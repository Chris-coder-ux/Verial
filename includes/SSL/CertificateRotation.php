<?php
/**
 * Clase para gestionar la rotación de certificados SSL
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
 * Sistema de rotación de certificados SSL
 *
 * Esta clase gestiona la rotación periódica de certificados SSL,
 * garantizando que siempre estén actualizados y válidos.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */
class CertificateRotation {
    /**
     * Instancia del logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * Directorio para certificados
     *
     * @var string
     */
    private $cert_dir;

    /**
     * Configuración de rotación
     *
     * @var array
     */
    private $config = [
        'rotation_interval' => 30,           // Días entre rotaciones automáticas
        'expiration_threshold' => 30,        // Días antes de la expiración para renovar
        'retention_count' => 3,              // Número de versiones anteriores a mantener
        'sources' => [],                     // Fuentes de certificados
        'last_rotation' => 0,                // Timestamp de última rotación
        'backup_enabled' => true,            // Habilitar backup antes de la rotación
        'rotation_schedule' => 'daily',      // Frecuencia de comprobación
    ];

    /**
     * Constructor
     *
     * @param Logger|null $logger Instancia del logger
     * @param array $config Configuración personalizada
     */
    public function __construct($logger = null, array $config = []) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_rotation');
        $this->cert_dir = plugin_dir_path(dirname(__FILE__)) . '../certs';
        
        // Asegurar que el directorio exista
        if (!file_exists($this->cert_dir)) {
            wp_mkdir_p($this->cert_dir);
            $this->logger->info("[SSL Rotation] Directorio de certificados creado: {$this->cert_dir}");
        }
        
        // Cargar configuración guardada
        $saved_config = get_option('miapi_ssl_rotation_config', []);
        if (is_array($saved_config)) {
            $this->config = array_merge($this->config, $saved_config);
        }
        
        // Fusionar con configuración proporcionada
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        
        // Configurar fuentes de certificados predeterminadas si no hay ninguna
        if (empty($this->config['sources'])) {
            $this->config['sources'] = $this->getDefaultSources();
        }
        
        // Registrar cron para rotación automática
        $this->setupCronJob();
    }

    /**
     * Configura el trabajo cron para rotación automática
     */
    private function setupCronJob() {
        if (!wp_next_scheduled('miapi_ssl_certificate_rotation')) {
            wp_schedule_event(time(), $this->config['rotation_schedule'], 'miapi_ssl_certificate_rotation');
        }
    }

    /**
     * Obtiene fuentes de certificados predeterminadas
     *
     * @return array Fuentes de certificados
     */
    private function getDefaultSources(): array {
        return [
            'mozilla' => [
                'url' => 'https://curl.se/ca/cacert.pem',
                'name' => 'Mozilla CA Bundle',
                'priority' => 10,
            ],
            'amazon' => [
                'url' => 'https://www.amazontrust.com/repository/AmazonRootCA-bundle.pem',
                'name' => 'Amazon Trust Services CA Bundle',
                'priority' => 20,
            ],
            'digicert' => [
                'url' => 'https://www.digicert.com/CACerts/DigiCertGlobalRootCA.crt.pem',
                'name' => 'DigiCert Global Root CA',
                'priority' => 30,
            ],
            'wordpress' => [
                'url' => 'https://api.wordpress.org/core/browse-happy/1.1/ca-bundle.crt',
                'name' => 'WordPress CA Bundle',
                'priority' => 40,
            ],
            'certifi' => [
                'url' => 'https://raw.githubusercontent.com/certifi/python-certifi/master/certifi/cacert.pem',
                'name' => 'Certifi CA Bundle',
                'priority' => 50,
            ],
        ];
    }

    /**
     * Guarda la configuración actual
     *
     * @return bool Éxito de la operación
     */
    public function saveConfig(): bool {
        return update_option('miapi_ssl_rotation_config', $this->config, false);
    }

    /**
     * Añade una nueva fuente de certificados
     *
     * @param string $id ID único de la fuente
     * @param string $url URL de la fuente
     * @param string $name Nombre descriptivo
     * @param int $priority Prioridad (menor número = mayor prioridad)
     * @return self
     */
    public function addSource(string $id, string $url, string $name, int $priority = 100): self {
        $this->config['sources'][$id] = [
            'url' => $url,
            'name' => $name,
            'priority' => $priority,
        ];
        
        $this->saveConfig();
        return $this;
    }

    /**
     * Elimina una fuente de certificados
     *
     * @param string $id ID de la fuente a eliminar
     * @return self
     */
    public function removeSource(string $id): self {
        if (isset($this->config['sources'][$id])) {
            unset($this->config['sources'][$id]);
            $this->saveConfig();
        }
        
        return $this;
    }

    /**
     * Obtiene todas las fuentes ordenadas por prioridad
     *
     * @return array Fuentes ordenadas
     */
    public function getSources(): array {
        $sources = $this->config['sources'];
        
        // Ordenar por prioridad
        uasort($sources, function($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        
        return $sources;
    }

    /**
     * Verifica si es necesaria una rotación de certificados
     *
     * @return bool True si se requiere rotación
     */
    public function needsRotation(): bool {
        // Si nunca se ha hecho rotación, hacerla
        if (empty($this->config['last_rotation'])) {
            return true;
        }
        
        // Verificar intervalo de rotación
        $days_since_last_rotation = (time() - $this->config['last_rotation']) / DAY_IN_SECONDS;
        if ($days_since_last_rotation >= $this->config['rotation_interval']) {
            return true;
        }
        
        // Verificar si el certificado actual está próximo a expirar
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        if (!file_exists($ca_bundle_path)) {
            return true;
        }
        
        // Verificar próxima expiración de algún certificado en el bundle
        if ($this->hasNearExpiringCertificates($ca_bundle_path)) {
            return true;
        }
        
        return false;
    }

    /**
     * Verifica si algún certificado en el bundle está próximo a expirar
     *
     * @param string $bundle_path Ruta al bundle de certificados
     * @return bool True si hay certificados próximos a expirar
     */
    private function hasNearExpiringCertificates(string $bundle_path): bool {
        if (!file_exists($bundle_path) || !is_readable($bundle_path)) {
            return true;  // Si no podemos leer, mejor rotar
        }
        
        $content = file_get_contents($bundle_path);
        if ($content === false) {
            return true;
        }
        
        // Extraer certificados individuales
        preg_match_all('/-----BEGIN CERTIFICATE-----\s*([^-]+)\s*-----END CERTIFICATE-----/', $content, $matches);
        
        if (empty($matches[1])) {
            return true;  // No se encontraron certificados, mejor rotar
        }
        
        $threshold_time = time() + ($this->config['expiration_threshold'] * DAY_IN_SECONDS);
        
        foreach ($matches[1] as $cert_data) {
            $cert_data = trim($cert_data);
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert_data, 64, "\n") . "-----END CERTIFICATE-----";
            
            $cert_resource = openssl_x509_read($cert);
            if ($cert_resource) {
                $cert_info = openssl_x509_parse($cert_resource);
                
                // Si algún certificado importante expira pronto, necesitamos rotar
                if ($cert_info && isset($cert_info['validTo_time_t'])) {
                    if ($cert_info['validTo_time_t'] <= $threshold_time) {
                        // Verificar si es un certificado importante (CA raíz)
                        if (isset($cert_info['subject']['CN']) && isset($cert_info['issuer']['CN'])) {
                            // Los certificados raíz tienen el mismo CN en subject e issuer
                            if ($cert_info['subject']['CN'] === $cert_info['issuer']['CN']) {
                                $expiry_date = date('Y-m-d', $cert_info['validTo_time_t']);
                                $this->logger->warning("[SSL Rotation] Certificado raíz próximo a expirar: {$cert_info['subject']['CN']} (expira: $expiry_date)");
                                return true;
                            }
                        }
                    }
                }
            }
        }
        
        return false;
    }

    /**
     * Realiza una rotación de certificados
     *
     * @param bool $force Forzar rotación aunque no sea necesaria
     * @return bool Éxito de la operación
     */
    public function rotateCertificates(bool $force = false): bool {
        if (!$force && !$this->needsRotation()) {
            $this->logger->info("[SSL Rotation] No se necesita rotación de certificados en este momento");
            return true;  // No es necesario rotar
        }
        
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        
        // Crear backup del bundle actual si existe
        if ($this->config['backup_enabled'] && file_exists($ca_bundle_path)) {
            $backup_suffix = date('Y-m-d-His');
            $backup_path = $ca_bundle_path . '.' . $backup_suffix . '.backup';
            
            if (copy($ca_bundle_path, $backup_path)) {
                $this->logger->info("[SSL Rotation] Backup creado: $backup_path");
            } else {
                $this->logger->warning("[SSL Rotation] No se pudo crear backup antes de la rotación");
            }
        }
        
        // Obtener y validar certificados de las fuentes
        $new_bundle = $this->fetchCertificatesFromSources();
        if ($new_bundle === false) {
            $this->logger->error("[SSL Rotation] No se pudo obtener certificados válidos de ninguna fuente");
            return false;
        }
        
        // Guardar nuevo bundle
        $result = file_put_contents($ca_bundle_path, $new_bundle);
        if ($result === false) {
            $this->logger->error("[SSL Rotation] Error al guardar el nuevo bundle de certificados");
            return false;
        }
        
        // Establecer permisos seguros
        $this->setSecureCertificatePermissions($ca_bundle_path);
        
        // Actualizar timestamp de última rotación
        $this->config['last_rotation'] = time();
        $this->saveConfig();
        
        // Limpiar backups antiguos
        $this->cleanupOldBackups();
        
        $cert_count = substr_count($new_bundle, "-----BEGIN CERTIFICATE-----");
        $this->logger->info("[SSL Rotation] Rotación de certificados completada exitosamente", [
            'certificados' => $cert_count,
            'tamaño' => strlen($new_bundle),
        ]);
        
        return true;
    }

    /**
     * Establece permisos seguros en un archivo de certificado
     *
     * @param string $cert_path Ruta al certificado
     * @return bool Éxito de la operación
     */
    private function setSecureCertificatePermissions(string $cert_path): bool {
        if (!file_exists($cert_path)) {
            return false;
        }
        
        // Intentar métodos disponibles
        $success = false;
        
        // Método 1: PHP chmod directo
        if (@chmod($cert_path, 0644)) {
            $success = true;
        } else {
            // Método 2: Comando chmod del sistema
            @exec("chmod 644 " . escapeshellarg($cert_path), $output, $return_var);
            if ($return_var === 0) {
                $success = true;
            } else {
                // Método 3: Comando chmod con sudo (para sistemas Unix)
                if (stripos(PHP_OS, 'win') === false) {
                    @exec("sudo chmod 644 " . escapeshellarg($cert_path), $output, $return_var);
                    if ($return_var === 0) {
                        $success = true;
                    }
                }
            }
        }
        
        // Verificar el resultado
        if ($success) {
            clearstatcache(true, $cert_path);
            $perms = fileperms($cert_path) & 0777;
            
            if ($perms === 0644 || $perms === 0444) {
                $this->logger->debug("[SSL Rotation] Permisos seguros establecidos para: $cert_path");
                return true;
            }
        }
        
        $this->logger->warning("[SSL Rotation] No se pudieron establecer permisos seguros para: $cert_path");
        return false;
    }

    /**
     * Descarga y valida certificados de fuentes configuradas
     *
     * @return string|false Contenido del bundle o false si hay error
     */
    private function fetchCertificatesFromSources() {
        $sources = $this->getSources();
        
        foreach ($sources as $source_id => $source) {
            $this->logger->info("[SSL Rotation] Descargando certificados desde: {$source['name']} ({$source['url']})");
            
            $response = wp_remote_get($source['url'], [
                'timeout' => 30,
                'sslverify' => true,
                'user-agent' => 'Mi-Integracion-API/' . (defined('MIAPI_VERSION') ? MIAPI_VERSION : '2.0.0'),
            ]);
            
            if (is_wp_error($response)) {
                $this->logger->warning("[SSL Rotation] Error al descargar de {$source_id}: " . $response->get_error_message());
                continue;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            if ($http_code !== 200) {
                $this->logger->warning("[SSL Rotation] Error HTTP $http_code al descargar de {$source_id}");
                continue;
            }
            
            $content = wp_remote_retrieve_body($response);
            if (empty($content)) {
                $this->logger->warning("[SSL Rotation] Contenido vacío descargado de {$source_id}");
                continue;
            }
            
            // Validar que el contenido tenga certificados PEM
            $cert_count = substr_count($content, "-----BEGIN CERTIFICATE-----");
            if ($cert_count < 50) {  // La mayoría de bundles tienen más de 100 certificados
                $this->logger->warning("[SSL Rotation] Bundle de {$source_id} tiene pocos certificados: $cert_count");
                continue;
            }
            
            // Formateo básico - Asegurar formato PEM correcto
            $content = $this->formatCertificateBundle($content);
            
            // Añadir encabezado
            $timestamp = date('Y-m-d H:i:s');
            $header = "# CA Bundle generado automáticamente por Mi Integración API\n";
            $header .= "# Fuente: {$source['name']} ({$source['url']})\n";
            $header .= "# Fecha: $timestamp\n";
            $header .= "# Certificados: $cert_count\n\n";
            
            $content = $header . $content;
            
            $this->logger->info("[SSL Rotation] Bundle válido obtenido de {$source_id} con $cert_count certificados");
            return $content;
        }
        
        return false;
    }

    /**
     * Formatea un bundle de certificados para asegurar formato PEM correcto
     *
     * @param string $content Contenido del bundle
     * @return string Contenido formateado
     */
    private function formatCertificateBundle(string $content): string {
        // Normalizar saltos de línea
        $content = str_replace("\r\n", "\n", $content);
        
        // Extraer certificados individuales
        preg_match_all('/(-----BEGIN CERTIFICATE-----\s*.*?\s*-----END CERTIFICATE-----)/s', $content, $matches);
        
        if (empty($matches[1])) {
            return $content; // No se encontraron certificados, devolver original
        }
        
        $formatted_bundle = "";
        
        foreach ($matches[1] as $cert) {
            // Limpiar espacios o líneas vacías adicionales
            $cert = trim($cert);
            
            // Asegurar que hay una línea vacía entre certificados
            $formatted_bundle .= $cert . "\n\n";
        }
        
        return $formatted_bundle;
    }

    /**
     * Limpia backups antiguos según la configuración de retención
     */
    private function cleanupOldBackups(): void {
        $retention_count = (int) $this->config['retention_count'];
        
        if ($retention_count <= 0) {
            return;  // Conservar todos los backups
        }
        
        // Buscar archivos de backup
        $backups = glob($this->cert_dir . '/ca-bundle.pem.*.backup');
        
        if (count($backups) <= $retention_count) {
            return;  // No hay suficientes backups para limpiar
        }
        
        // Ordenar por fecha (más antiguos primero)
        usort($backups, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });
        
        // Eliminar backups antiguos manteniendo los más recientes
        $to_delete = array_slice($backups, 0, count($backups) - $retention_count);
        
        foreach ($to_delete as $backup_file) {
            if (unlink($backup_file)) {
                $this->logger->debug("[SSL Rotation] Backup antiguo eliminado: $backup_file");
            } else {
                $this->logger->warning("[SSL Rotation] No se pudo eliminar backup antiguo: $backup_file");
            }
        }
    }

    /**
     * Realiza una rotación programada
     * 
     * Esta función es llamada automáticamente por el cron de WordPress
     */
    public function scheduledRotation(): void {
        if ($this->needsRotation()) {
            $this->logger->info("[SSL Rotation] Iniciando rotación programada de certificados");
            $this->rotateCertificates();
        }
    }

    /**
     * Obtiene un resumen del estado actual
     *
     * @return array Información sobre el estado
     */
    public function getStatus(): array {
        $ca_bundle_path = $this->cert_dir . '/ca-bundle.pem';
        $status = [
            'certificado_principal' => [
                'path' => $ca_bundle_path,
                'existe' => file_exists($ca_bundle_path),
                'tamaño' => file_exists($ca_bundle_path) ? filesize($ca_bundle_path) : 0,
                'fecha_modificacion' => file_exists($ca_bundle_path) ? date('Y-m-d H:i:s', filemtime($ca_bundle_path)) : null,
            ],
            'ultima_rotacion' => $this->config['last_rotation'] ? date('Y-m-d H:i:s', $this->config['last_rotation']) : 'Nunca',
            'proxima_rotacion' => $this->config['last_rotation'] ? date('Y-m-d H:i:s', $this->config['last_rotation'] + ($this->config['rotation_interval'] * DAY_IN_SECONDS)) : 'Programada para próximo chequeo',
            'necesita_rotacion' => $this->needsRotation(),
            'fuentes_disponibles' => count($this->config['sources']),
            'backups' => [],
        ];
        
        // Información de backups
        $backups = glob($this->cert_dir . '/ca-bundle.pem.*.backup');
        foreach ($backups as $backup) {
            $status['backups'][] = [
                'archivo' => basename($backup),
                'tamaño' => filesize($backup),
                'fecha' => date('Y-m-d H:i:s', filemtime($backup)),
            ];
        }
        
        return $status;
    }
}
