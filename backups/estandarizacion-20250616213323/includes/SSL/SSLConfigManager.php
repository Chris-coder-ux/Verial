<?php
/**
 * Clase para administrar configuraciones SSL avanzadas
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */

namespace MiIntegracionApi\SSL;

use MiIntegracionApi\Helpers\Logger;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Administrador de configuraciones SSL avanzadas
 *
 * Esta clase proporciona opciones avanzadas de configuración SSL
 * y métodos para aplicarlas a conexiones CURL y WordPress.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */
class SSLConfigManager {
    /**
     * Instancia del logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * Opciones de configuración SSL
     *
     * @var array
     */
    private $ssl_options = [
        // Opciones básicas
        'verify_peer' => true,               // Verificar certificado del servidor
        'verify_peer_name' => true,          // Verificar que el certificado coincida con el host
        'allow_self_signed' => false,        // Permitir certificados autofirmados
        
        // Opciones avanzadas
        'verify_depth' => 5,                 // Profundidad de verificación de certificados
        'cipher_list' => '',                 // Lista personalizada de cifrados SSL/TLS
        'ssl_version' => '',                 // Versión SSL/TLS (TLSv1.2, etc.)
        'revocation_check' => true,          // Verificar revocación de certificados
        
        // Rutas a certificados
        'ca_bundle_path' => '',              // Ruta al bundle CA
        'client_cert_path' => '',            // Ruta al certificado cliente
        'client_key_path' => '',             // Ruta a la clave privada cliente
        
        // Opciones de desarrollo/entorno
        'disable_ssl_local' => true,         // Deshabilitar verificación SSL en entornos locales
        'debug_ssl' => false,                // Activar debug SSL detallado
        'proxy' => '',                       // Proxy para conexiones SSL
    ];

    /**
     * Constructor
     *
     * @param Logger|null $logger Instancia del logger
     * @param array $options Opciones SSL personalizadas
     */
    public function __construct($logger = null, array $options = []) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_config');
        
        // Cargar configuración guardada
        $saved_options = get_option('miapi_ssl_config_options', []);
        if (is_array($saved_options) && !empty($saved_options)) {
            $this->ssl_options = array_merge($this->ssl_options, $saved_options);
        }
        
        // Fusionar con opciones proporcionadas
        if (!empty($options)) {
            $this->ssl_options = array_merge($this->ssl_options, $options);
        }
        
        // Detectar ruta al bundle CA por defecto si no está establecido
        if (empty($this->ssl_options['ca_bundle_path'])) {
            $this->ssl_options['ca_bundle_path'] = $this->detectDefaultCaBundle();
        }
    }

    /**
     * Guarda la configuración SSL como opción de WordPress
     * 
     * @return bool Éxito de la operación
     */
    public function saveConfig(): bool {
        return update_option('miapi_ssl_config_options', $this->ssl_options, false);
    }

    /**
     * Detecta la ruta al bundle CA por defecto
     * 
     * @return string Ruta al bundle CA o cadena vacía si no se encuentra
     */
    private function detectDefaultCaBundle(): string {
        $possible_paths = [
            plugin_dir_path(dirname(__FILE__)) . '../certs/ca-bundle.pem',
            plugin_dir_path(dirname(__FILE__)) . '../../certs/ca-bundle.pem',
            ABSPATH . 'wp-content/plugins/mi-integracion-api/certs/ca-bundle.pem',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        // Buscar bundle del sistema (Linux/Unix)
        $system_paths = [
            '/etc/ssl/certs/ca-certificates.crt',  // Debian/Ubuntu
            '/etc/pki/tls/cert.pem',               // Red Hat/Fedora/CentOS
            '/etc/ssl/ca-bundle.pem',              // OpenSuse
            '/etc/pki/tls/cacert.pem',             // CentOS/Oracle Linux
            '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
            '/usr/local/etc/openssl/cert.pem',     // macOS Homebrew
            '/usr/local/etc/openssl@1.1/cert.pem', // macOS Homebrew alternative
        ];
        
        foreach ($system_paths as $path) {
            if (file_exists($path) && is_readable($path)) {
                return $path;
            }
        }
        
        return '';
    }

    /**
     * Establece una opción de configuración SSL
     *
     * @param string $option Nombre de la opción
     * @param mixed $value Valor de la opción
     * @return self
     */
    public function setOption(string $option, $value): self {
        if (array_key_exists($option, $this->ssl_options)) {
            $this->ssl_options[$option] = $value;
        }
        return $this;
    }

    /**
     * Obtiene una opción de configuración SSL
     *
     * @param string $option Nombre de la opción
     * @param mixed $default Valor por defecto si la opción no existe
     * @return mixed Valor de la opción
     */
    public function getOption(string $option, $default = null) {
        return array_key_exists($option, $this->ssl_options) ? $this->ssl_options[$option] : $default;
    }

    /**
     * Obtiene todas las opciones de configuración SSL
     *
     * @return array Opciones de configuración SSL
     */
    public function getAllOptions(): array {
        return $this->ssl_options;
    }

    /**
     * Valida si una ruta de CA bundle es válida y accesible.
     *
     * @param string $path La ruta al archivo CA bundle.
     * @return bool True si es válido y legible, false en caso contrario.
     */
    public function validateCaBundle(string $path): bool {
        if (empty($path)) {
            return false;
        }
        if (!file_exists($path)) {
            $this->logger->warning("[SSL Config] CA bundle no encontrado: " . $path);
            return false;
        }
        if (!is_readable($path)) {
            $this->logger->warning("[SSL Config] CA bundle no legible: " . $path);
            return false;
        }
        $this->logger->debug("[SSL Config] CA bundle validado: " . $path);
        return true;
    }

    /**
     * Aplica la configuración SSL a un manejador CURL
     *
     * @param resource $curl_handle Manejador CURL
     * @param bool $is_local Indicador de entorno local
     * @return resource Manejador CURL configurado
     */
    public function applyCurlOptions($curl_handle, bool $is_local = false) {
        // Verificar si debemos deshabilitar SSL en entorno local
        $verify_peer = $this->ssl_options['verify_peer'];
        if ($is_local && $this->ssl_options['disable_ssl_local']) {
            $verify_peer = false;
            $this->logger->debug('[SSL Config] Verificación SSL deshabilitada en entorno local');
        }
        
        // Configuración básica SSL
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYPEER, $verify_peer);
        curl_setopt($curl_handle, CURLOPT_SSL_VERIFYHOST, $this->ssl_options['verify_peer_name'] ? 2 : 0);
        
        // Configurar bundle CA
        if ($verify_peer && !empty($this->ssl_options['ca_bundle_path'])) {
            curl_setopt($curl_handle, CURLOPT_CAINFO, $this->ssl_options['ca_bundle_path']);
        }
        
        // Configuración avanzada
        if (!empty($this->ssl_options['ssl_version'])) {
            $ssl_version = $this->mapSSLVersion($this->ssl_options['ssl_version']);
            if ($ssl_version !== null) {
                curl_setopt($curl_handle, CURLOPT_SSLVERSION, $ssl_version);
            }
        }
        
        // Configurar certificado cliente para autenticación mutua
        if (!empty($this->ssl_options['client_cert_path'])) {
            curl_setopt($curl_handle, CURLOPT_SSLCERT, $this->ssl_options['client_cert_path']);
            
            if (!empty($this->ssl_options['client_key_path'])) {
                curl_setopt($curl_handle, CURLOPT_SSLKEY, $this->ssl_options['client_key_path']);
            }
        }
        
        // Configurar lista de cifrados
        if (!empty($this->ssl_options['cipher_list'])) {
            curl_setopt($curl_handle, CURLOPT_SSL_CIPHER_LIST, $this->ssl_options['cipher_list']);
        }
        
        // Verificación de revocación
        if ($this->ssl_options['revocation_check']) {
            if (defined('CURLOPT_SSL_VERIFYSTATUS')) {
                curl_setopt($curl_handle, CURLOPT_SSL_VERIFYSTATUS, true);
            }
        }
        
        // Proxy
        if (!empty($this->ssl_options['proxy'])) {
            curl_setopt($curl_handle, CURLOPT_PROXY, $this->ssl_options['proxy']);
        }
        
        // Debug SSL
        if ($this->ssl_options['debug_ssl']) {
            curl_setopt($curl_handle, CURLOPT_VERBOSE, true);
            $verbose_log = fopen('php://temp', 'w+');
            curl_setopt($curl_handle, CURLOPT_STDERR, $verbose_log);
            
            // Guardar en propiedad para acceso posterior
            $this->verbose_log = $verbose_log;
        }
        
        return $curl_handle;
    }
    
    /**
     * Aplica la configuración SSL a argumentos de WordPress
     *
     * @param array $args Argumentos de solicitud wp_remote_*
     * @param bool $is_local Indicador de entorno local
     * @return array Argumentos actualizados
     */
    public function applyWpRequestArgs(array $args, bool $is_local = false): array {
        // Verificar si debemos deshabilitar SSL en entorno local
        $verify_ssl = $this->ssl_options['verify_peer'];
        if ($is_local && $this->ssl_options['disable_ssl_local']) {
            $verify_ssl = false;
            $this->logger->debug('[SSL Config] Verificación SSL deshabilitada en entorno local');
        }
        
        // Configuración básica
        $args['sslverify'] = $verify_ssl;
        
        // Configurar bundle CA
        if ($verify_ssl && !empty($this->ssl_options['ca_bundle_path'])) {
            $args['sslcertificates'] = $this->ssl_options['ca_bundle_path'];
        }
        
        // Proxy
        if (!empty($this->ssl_options['proxy'])) {
            $args['proxy'] = $this->ssl_options['proxy'];
        }
        
        return $args;
    }

    /**
     * Obtiene logs de depuración SSL si están habilitados
     *
     * @return string|null Logs de depuración o null si no están disponibles
     */
    public function getSSLDebugLog() {
        if (!$this->ssl_options['debug_ssl'] || !isset($this->verbose_log)) {
            return null;
        }
        
        rewind($this->verbose_log);
        $log_contents = stream_get_contents($this->verbose_log);
        fclose($this->verbose_log);
        
        return $log_contents;
    }
    
    /**
     * Mapea una versión SSL de cadena a constante CURL
     *
     * @param string $version_str Versión SSL en formato cadena
     * @return int|null Constante CURL o null si no se reconoce
     */
    private function mapSSLVersion(string $version_str) {
        $map = [
            'SSLv3' => CURL_SSLVERSION_SSLv3,
            'TLSv1' => CURL_SSLVERSION_TLSv1,
            'TLSv1.0' => CURL_SSLVERSION_TLSv1_0,
            'TLSv1.1' => CURL_SSLVERSION_TLSv1_1,
            'TLSv1.2' => CURL_SSLVERSION_TLSv1_2,
        ];
        
        // Añadir TLSv1.3 si está disponible (PHP 7.3+ con cURL 7.52.0+)
        if (defined('CURL_SSLVERSION_TLSv1_3')) {
            $map['TLSv1.3'] = CURL_SSLVERSION_TLSv1_3;
        }
        
        return isset($map[$version_str]) ? $map[$version_str] : null;
    }

    /**
     * Devuelve la configuración SSL actual (compatibilidad)
     *
     * @return array
     */
    public function getConfiguration(): array {
        if ($this->logger) {
            $this->logger->debug('[SSLConfigManager] getConfiguration() llamado');
        }
        return $this->ssl_options;
    }
}
