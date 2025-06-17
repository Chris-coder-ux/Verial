<?php
/**
 * Clase para manejar la conexión con la API externa.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\CacheManager;
use MiIntegracionApi\SSL\CertificateCache;
use MiIntegracionApi\SSL\SSLTimeoutManager;
use MiIntegracionApi\SSL\SSLConfigManager;
use MiIntegracionApi\SSL\CertificateRotation;
use MiIntegracionApi\Core\Config_Manager;

// Incluir fallbacks para WordPress cuando no están disponibles
require_once __DIR__ . '/WP_Error.php';

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase para manejar todas las conexiones con la API externa.
 *
 * Esta clase proporciona los métodos necesarios para interactuar
 * con la API de Verial, incluyendo autenticación, manejo de errores,
 * y todas las operaciones HTTP necesarias.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */
class ApiConnector {
    // Usar sistemas SSL avanzados
    use SSLAdvancedSystemsTrait;

    /**
     * Número de sesión para Verial (sesionwcf)
     * @var int
     */
    private int $sesionwcf = 18;

    /**
     * Base URL para la API
     * @var string
     */
    private string $api_url = '';

    /**
     * Logger para registrar eventos
     * @var \MiIntegracionApi\Helpers\ILogger
     */
    private \MiIntegracionApi\Helpers\ILogger $logger;

    /**
     * Contador de reintentos
     * @var int
     */
    private int $retry_count = 0;

    /**
     * Path fijo del servicio Verial
     * @var string
     */
    private string $service_path = '/WcfServiceLibraryVerial/';

    /**
     * Última URL construida para la petición (para diagnóstico)
     * @var string|null
     */
    private ?string $last_request_url = null;

    /**
     * Opciones para las solicitudes HTTP.
     *
     * @since 1.0.0
     * @access   protected
     * @var      array    $request_options    Opciones para solicitudes HTTP.
     */
    protected array $request_options = [];
    
    /**
     * Gestor de caché de certificados
     * @var CertificateCache|null
     */
    private ?CertificateCache $cert_cache = null;
    
    /**
     * Gestor de timeouts SSL
     * @var SSLTimeoutManager|null
     */
    private ?SSLTimeoutManager $timeout_manager = null;
    
    /**
     * Gestor de configuración SSL
     * @var SSLConfigManager|null
     */
    private ?SSLConfigManager $ssl_config_manager = null;
    
    /**
     * Gestor de rotación de certificados
     * @var CertificateRotation|null
     */
    private ?CertificateRotation $cert_rotation = null;

    /**
     * Indica si se debe usar caché para las respuestas.
     *
     * @since 1.0.0
     * @access   protected
     * @var      boolean    $use_cache    Si se debe usar caché.
     */
    protected bool $use_cache = false;

    /**
     * Tiempo de vida predeterminado para caché en segundos.
     *
     * @since 1.0.0
     * @access   protected
     * @var      int    $default_cache_ttl    Tiempo de vida de caché.
     */
    protected int $default_cache_ttl = 3600;

    /**
     * Tiempo máximo de espera para solicitudes en segundos
     *
     * @var int
     */
    private int $timeout = 30;

    /**
     * Número máximo de reintentos para solicitudes fallidas
     *
     * @var int
     */
    private int $max_retries = 3;

    /**
     * Contador de reintentos de la última solicitud
     *
     * @var int
     */
    private int $last_retry_count = 0;

    /**
     * Configuración de retry personalizada
     *
     * @var array
     */
    private array $retry_config = [
        'max_retries' => 3,
        'base_delay' => 1000,
        'max_delay' => 60000,
        'backoff_multiplier' => 2,
        'jitter' => true,
        'strategy' => 'exponential'
    ];

    /**
     * Configuración SSL para conexiones HTTPS
     *
     * @var array
     */
    private array $ssl_config = [
        'verify_peer' => true,
        'verify_host' => true,
        'ca_bundle_path' => null,
        'cert_path' => null,
        'key_path' => null,
        'allow_self_signed' => false,
        'cipher_list' => null
    ];

    /**
     * Indica si se debe forzar SSL/TLS
     *
     * @var bool
     */
    private bool $force_ssl = true;

    /**
     * Rutas de certificados CA por defecto
     *
     * @var array
     */
    private array $default_ca_paths = [
        '/etc/ssl/certs/ca-certificates.crt',     // Debian/Ubuntu
        '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',  // RHEL/CentOS
        '/etc/ssl/ca-bundle.pem',                 // SUSE
        '/usr/local/share/certs/ca-root-nss.crt', // FreeBSD
        '/etc/ssl/cert.pem'                       // macOS/Alpine
    ];

    /**
     * Instancia del CacheManager
     *
     * @var \MiIntegracionApi\CacheManager|null
     */
    private ?\MiIntegracionApi\CacheManager $cache_manager = null;

    /**
     * Configuración de caché habilitada
     *
     * @var bool
     */
    private bool $cache_enabled = false;

    /**
     * Configuración de TTL específico por endpoint
     *
     * @var array
     */
    private array $cache_ttl_config = [
        'GetArticulosWS' => 7200,      // 2 horas para artículos
        'GetClientesWS' => 3600,       // 1 hora para clientes  
        'GetPedidosWS' => 1800,        // 30 minutos para pedidos
        'GetCategoriasWS' => 14400,    // 4 horas para categorías
        'GetEstadisticasWS' => 3600,   // 1 hora para estadísticas
        'test-connection' => 300,      // 5 minutos para pruebas
        'default' => 3600              // 1 hora por defecto
    ];

    /**
     * Estadísticas de caché
     *
     * @var array
     */
    private array $cache_stats = [
        'hits' => 0,
        'misses' => 0,
        'sets' => 0,
        'hit_ratio' => 0.0
    ];

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @param    array $config    Configuración opcional para el conector.
     */
    public function __construct(array $options = []) {
        // Inicializar logger
        $this->logger = new \MiIntegracionApi\Helpers\Logger('api_connector');

        // Cargar configuración
        $this->load_configuration($options);

        // Inicializar sistemas SSL mejorados
        $this->initSSLSystems();
        
        // Inicializar gestor de caché
        $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        
        // Verificar y configurar certificados SSL si es necesario
        if ($this->force_ssl) {
            $this->ensureSslConfiguration();
        }
    }

    /**
     * Variable para rastrear el origen de la configuración
     * @var string
     */
    private string $config_source = 'none';

    /**
     * Carga y valida la configuración ÚNICAMENTE desde opciones de WordPress
     * @param array $config Configuración pasada al constructor (solo para compatibilidad con logger)
     * @throws \Exception Si la configuración es inválida
     */
    private function load_configuration(array $config): void {
        // Verificar si estamos en modo de prueba
        if (isset($config['test_mode']) && $config['test_mode'] === true) {
            $this->load_test_configuration($config);
            return;
        }
        
        // Verificar que WordPress esté disponible para configuración real
        if (!function_exists('get_option')) {
            throw new \Exception('WordPress no está disponible para cargar la configuración');
        }

        // Usar el Config_Manager para obtener la configuración
        $config_manager = Config_Manager::get_instance();
        
        // Obtener configuración
        $api_url = $config_manager->get('mia_url_base');
        $sesionwcf = $config_manager->get('mia_numero_sesion');
        
        // Validar URL base - CRÍTICO para funcionamiento
        if (empty($api_url)) {
            throw new \Exception('URL base de Verial no configurada. Configure la URL en Mi Integración API > Configuración');
        }
        
        if (!filter_var($api_url, FILTER_VALIDATE_URL)) {
            throw new \Exception('URL base de Verial inválida: ' . $api_url);
        }
        
        // Configurar propiedades
        $this->api_url = rtrim($api_url, '/');
        $this->sesionwcf = $sesionwcf;
        $this->config_source = 'config_manager';
        
        // Registrar en log si está disponible
        if (class_exists('\\MiIntegracionApi\\Helpers\\Logger') && defined('WP_DEBUG') && WP_DEBUG) {
            $logger = new \MiIntegracionApi\Helpers\Logger('config');
            $logger->debug('Configuración cargada desde Config_Manager', [
                'api_url' => $this->api_url,
                'sesion' => $this->sesionwcf
            ]);
        }
    }

    /**
     * Obtiene el origen de la configuración actual
     * @return string
     */
    public function get_config_source(): string {
        return $this->config_source;
    }

    /**
     * Obtiene la URL base de la API configurada, sin duplicar paths.
     * @return string URL base de la API
     */
    public function get_api_base_url() {
        // Según la documentación y la colección de Postman, la estructura correcta es:
        // http://x.verial.org:8000/WcfServiceLibraryVerial
        
        // Si la URL ya contiene WcfServiceLibraryVerial, no añadirlo
        if (stripos($this->api_url, 'WcfServiceLibraryVerial') !== false) {
            return rtrim($this->api_url, '/');
        }
        return rtrim($this->api_url, '/') . '/WcfServiceLibraryVerial';
    }

    /**
     * Obtiene el número de sesión utilizado para la conexión
     * @return int|string Número de sesión
     */
    public function get_numero_sesion() {
        return $this->sesionwcf;
    }


    /**
     * Devuelve el número de sesión actual
     * Si no está configurado, intenta obtenerlo de las opciones guardadas
     * 
     * @return mixed El valor de sesionwcf o null si no está configurado
     */
    public function getSesionWcf() {
        if (empty($this->sesionwcf)) {
            $this->logger->warning('[CONFIG] Se intentó acceder a sesionwcf pero no está configurado');
            
            // Intentar obtener de las opciones como respaldo
            $options = get_option('mi_integracion_api_ajustes', []);
            if (!empty($options['mia_numero_sesion'])) {
                $this->sesionwcf = $options['mia_numero_sesion'];
                $this->logger->info('[CONFIG] Se recuperó sesionwcf desde ajustes guardados', [
                    'sesion' => $this->sesionwcf
                ]);
            }
        }
        
        return $this->sesionwcf;
    }

    /**
     * Inicializa el sistema de caché
     * 
     * @param array $config Configuración opcional
     */
    private function init_cache_system(array $config = []): void {
        try {
            // Obtener configuración de caché desde WordPress
            $cache_enabled = get_option('mi_integracion_api_ajustes_cache_enabled', true);
            $this->cache_enabled = (bool)($config['cache_enabled'] ?? $cache_enabled);
            
            if ($this->cache_enabled) {
                // Inicializar el CacheManager
                $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
                
                // Configurar TTL específicos si se proporcionan
                if (isset($config['cache_ttl_config'])) {
                    $this->cache_ttl_config = array_merge($this->cache_ttl_config, $config['cache_ttl_config']);
                }
                
                $this->logger->info('[CACHE] Sistema de caché inicializado', [
                    'enabled' => $this->cache_enabled,
                    'ttl_config' => $this->cache_ttl_config
                ]);
            } else {
                $this->logger->info('[CACHE] Sistema de caché deshabilitado');
            }
        } catch (\Exception $e) {
            $this->logger->error('[CACHE] Error inicializando sistema de caché: ' . $e->getMessage());
            $this->cache_enabled = false;
        }
    }

    /**
     * Configura el sistema de caché
     * 
     * @param bool $enabled Si habilitar/deshabilitar caché
     * @param array $ttl_config Configuración de TTL por endpoint
     * @return self Para method chaining
     */
    public function setCacheConfig(bool $enabled, array $ttl_config = []): self {
        $this->cache_enabled = $enabled;
        
        if (!empty($ttl_config)) {
            $this->cache_ttl_config = array_merge($this->cache_ttl_config, $ttl_config);
        }
        
        if ($enabled && !$this->cache_manager) {
            $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        }
        
        $this->logger->info('[CACHE] Configuración de caché actualizada', [
            'enabled' => $this->cache_enabled,
            'ttl_config' => $this->cache_ttl_config
        ]);
        
        return $this;
    }

    /**
     * Obtiene el TTL configurado para un endpoint específico
     * 
     * @param string $endpoint Nombre del endpoint
     * @return int TTL en segundos
     */
    private function getCacheTtlForEndpoint(string $endpoint): int {
        return $this->cache_ttl_config[$endpoint] ?? $this->cache_ttl_config['default'];
    }

    /**
     * Genera una clave de caché única para una solicitud
     * 
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint
     * @param array $data Datos de la solicitud
     * @param array $params Parámetros GET
     * @return string Clave de caché
     */
    private function generateCacheKey(string $method, string $endpoint, array $data = [], array $params = []): string {
        $key_parts = [
            'api_connector',
            strtolower($method),
            $endpoint,
            $this->sesionwcf
        ];
        
        if (!empty($params)) {
            $key_parts[] = md5(serialize($params));
        }
        
        if (!empty($data)) {
            $key_parts[] = md5(serialize($data));
        }
        
        return implode('_', $key_parts);
    }

    /**
     * Verifica si una respuesta debe ser cacheada
     * 
     * @param mixed $response Respuesta de la API
     * @param int $status_code Código de estado HTTP
     * @return bool True si debe ser cacheada
     */
    private function shouldCacheResponse($response, int $status_code): bool {
        // Solo cachear respuestas exitosas
        if ($status_code < 200 || $status_code >= 400) {
            return false;
        }
        
        // No cachear errores de WordPress
        if (is_wp_error($response)) {
            return false;
        }
        
        // No cachear respuestas vacías
        if (empty($response)) {
            return false;
        }
        
        return true;
    }

    /**
     * Obtiene las estadísticas de caché
     * 
     * @return array Estadísticas de caché
     */
    public function getCacheStats(): array {
        if (!empty($this->cache_stats['hits']) || !empty($this->cache_stats['misses'])) {
            $total_requests = $this->cache_stats['hits'] + $this->cache_stats['misses'];
            $this->cache_stats['hit_ratio'] = $total_requests > 0 
                ? round(($this->cache_stats['hits'] / $total_requests) * 100, 2) 
                : 0.0;
        }
        
        return $this->cache_stats;
    }

    /**
     * Reinicia las estadísticas de caché
     */
    public function resetCacheStats(): void {
        $this->cache_stats = [
            'hits' => 0,
            'misses' => 0,
            'sets' => 0,
            'hit_ratio' => 0.0
        ];
    }

    /**
     * Construye la URL completa para la API de Verial con manejo mejorado de URLs
     * @param string $endpoint
     * @return string
     */
    private function build_api_url(string $endpoint): string {
        $base = $this->get_api_base_url();
        
        // Limpiar el endpoint de espacios y barras iniciales/finales
        $endpoint = trim($endpoint);
        $endpoint = ltrim($endpoint, '/');
        
        // Si el endpoint está vacío, devolver solo la URL base
        if (empty($endpoint)) {
            $this->last_request_url = $base;
            return $base;
        }
        
        // Construir la URL de forma más robusta
        $url = rtrim($base, '/') . '/' . $endpoint;
        
        // Eliminar dobles barras pero preservar el protocolo (http:// o https://)
        // Esta expresión regular es más específica y no afecta el protocolo
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        
        // Validar que la URL resultante sea válida
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('URL construida inválida', [
                'base' => $base,
                'endpoint' => $endpoint,
                'resultado' => $url
            ]);
            
            // Intentar construcción alternativa como fallback
            $url = $base . (substr($base, -1) !== '/' ? '/' : '') . $endpoint;
        }
        
        $this->last_request_url = $url;
        return $url;
    }

    /**
     * Devuelve la última URL usada en una petición
     * @return string|null
     */
    public function get_last_request_url(): ?string {
        return $this->last_request_url;
    }

    /**
     * Realiza una solicitud GET a la API de Verial, añadiendo el parámetro x=sesionwcf.
     * @param string $endpoint
     * @param array $params
     * @param bool $use_robust_retry Si usar el sistema de retry robusto con caché en lugar de CURL directo
     * @return array|WP_Error
     */
    public function get($endpoint, $params = array(), $options_or_retry = true, array $options = []) {
        // Mantener compatibilidad hacia atrás - comprobar si el tercer parámetro es un booleano o un array
        $use_robust_retry = true;
        if (is_bool($options_or_retry)) {
            $use_robust_retry = $options_or_retry;
        } elseif (is_array($options_or_retry)) {
            $options = $options_or_retry;
        }
        // Asegurarse de que tenemos sesionwcf
        $sesion = $this->getSesionWcf();
        if (!$sesion) {
            $this->logger->warning('[HTTP][GET] No se ha configurado sesionwcf');
        }
        
        // COMPATIBILIDAD: Verificar si $params es una cadena (formato antiguo) y convertirlo a array
        if (is_string($params) && !empty($params)) {
            $this->logger->debug('[HTTP][GET] Params es una cadena, convirtiendo a array para compatibilidad');
            
            // Crear array de parámetros a partir de la cadena de consulta
            $query_parts = [];
            parse_str($params, $query_parts);
            $params = $query_parts;
            
            $this->logger->debug('[HTTP][GET] Params convertidos a:', ['params' => $params]);
        }
        
        // COMPATIBILIDAD: Verificar si hay parámetros concatenados al endpoint (formato antiguo)
        if (is_string($endpoint) && strpos($endpoint, '&') !== false) {
            $this->logger->debug('[HTTP][GET] Endpoint contiene parámetros, extrayendo');
            
            // Separar el endpoint y los parámetros
            $parts = explode('&', $endpoint, 2);
            $clean_endpoint = $parts[0];
            
            // Extraer parámetros adicionales
            if (isset($parts[1])) {
                $query_string = $parts[1];
                $query_parts = [];
                parse_str($query_string, $query_parts);
                // Fusionar con parámetros existentes
                $params = array_merge($query_parts, is_array($params) ? $params : []);
                $endpoint = $clean_endpoint;
                
                $this->logger->debug('[HTTP][GET] Endpoint y params separados:', [
                    'endpoint' => $endpoint,
                    'params' => $params
                ]);
            }
        }
        
        // Añadir información de depuración detallada
        $this->logger->debug('[HTTP][GET] Información detallada', [
            'endpoint_final' => $endpoint,
            'params_final' => $params,
            'params_type' => is_array($params) ? 'array' : gettype($params)
        ]);
        
        if ($use_robust_retry) {
            // Registrar opciones avanzadas si se proporcionan
            if (!empty($options)) {
                $this->logger->info('[HTTP][GET] Usando sistema de retry robusto con opciones avanzadas', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'cache_enabled' => $this->cache_enabled,
                    'sesionwcf_presente' => !empty($sesion) ? 'sí' : 'no',
                    'advanced_options' => array_keys($options),
                    'timeout' => $options['timeout'] ?? 'default',
                    'diagnostics' => isset($options['diagnostics']) ? 'enabled' : 'disabled',
                    'trace_request' => isset($options['trace_request']) ? 'enabled' : 'disabled'
                ]);
            } else {
                $this->logger->info('[HTTP][GET] Usando sistema de retry robusto con caché', [
                    'endpoint' => $endpoint,
                    'params' => $params,
                    'cache_enabled' => $this->cache_enabled,
                    'sesionwcf_presente' => !empty($sesion) ? 'sí' : 'no'
                ]);
            }
            
            // IMPORTANTE: NO añadimos aquí el parámetro 'x' porque lo hace makeRequestWithRetry
            // Esto evita parámetros duplicados
            
            try {
                // Registrar detalles de la solicitud para depuración
                if (isset($this->logger)) {
                    $this->logger->log("ApiConnector::get - Realizando solicitud: endpoint={$endpoint}, params=" . json_encode($params), 'debug');
                }
                
                $result = $this->makeRequestWithRetry('GET', $endpoint, [], $params, $options);
                
                // Registrar información sobre la respuesta
                if (isset($this->logger)) {
                    $result_info = is_array($result) ? 'Array con ' . count($result) . ' elementos' : gettype($result);
                    $this->logger->log("ApiConnector::get - Respuesta recibida: {$result_info}", 'debug');
                }
                
                return $result;
            } catch (\Throwable $api_ex) {
                // Guardar el error para diagnóstico
                $this->last_error = $api_ex->getMessage();
                
                if (isset($this->logger)) {
                    $this->logger->log("Error en ApiConnector::get: " . $api_ex->getMessage(), 'error');
                    $this->logger->log("Traza: " . $api_ex->getTraceAsString(), 'debug');
                }
                
                // Re-lanzar para ser manejada por el llamador
                throw $api_ex;
            }
        }
        
        // Fallback a método legacy CURL
        // Solo añadimos el parámetro 'x' si no está presente en $params
        if ($sesion && !isset($params['x'])) {
            $params['x'] = $sesion;
        }
        
        $this->logger->info('[CURL][GET] Endpoint (método legacy)', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        // Añadir información de depuración detallada
        $this->logger->debug('[HTTP][GET] Información detallada', [
            'endpoint_original' => $endpoint,
            'params_original' => $params,
            'params_type' => is_array($params) ? 'array' : gettype($params)
        ]);
        
        // COMPATIBILIDAD: Verificar si $params es una cadena (formato antiguo) y convertirlo a array
        if (is_string($params) && !empty($params)) {
            $this->logger->debug('[HTTP][GET] Params es una cadena, convirtiendo a array para compatibilidad');
            
            // Crear array de parámetros a partir de la cadena de consulta
            $query_parts = [];
            parse_str($params, $query_parts);
            $params = $query_parts;
            
            $this->logger->debug('[HTTP][GET] Params convertidos a:', ['params' => $params]);
        }
        
        // COMPATIBILIDAD: Verificar si hay parámetros concatenados al endpoint (formato antiguo)
        if (is_string($endpoint) && strpos($endpoint, '&') !== false) {
            $this->logger->debug('[HTTP][GET] Endpoint contiene parámetros, extrayendo');
            
            // Separar el endpoint y los parámetros
            $parts = explode('&', $endpoint, 2);
            $clean_endpoint = $parts[0];
            
            // Extraer parámetros adicionales
            if (isset($parts[1])) {
                $query_string = $parts[1];
                $query_parts = [];
                parse_str($query_string, $query_parts);
                // Fusionar con parámetros existentes
                $params = array_merge($query_parts, is_array($params) ? $params : []);
                $endpoint = $clean_endpoint;
                
                $this->logger->debug('[HTTP][GET] Endpoint y params separados:', [
                    'endpoint' => $endpoint,
                    'params' => $params
                ]);
            }
        }
        
        return $this->curl_request($endpoint, $params);
    }

    /**
     * Realiza una solicitud POST a la API de Verial, añadiendo sesionwcf al JSON de entrada.
     * @param string $endpoint
     * @param array $data
     * @param bool $use_robust_retry Si usar el sistema de retry robusto en lugar de CURL directo
     * @return array|WP_Error
     */
    public function post($endpoint, $data = array(), bool $use_robust_retry = true) {
        // Asegurarse de que tenemos sesionwcf
        $sesion = $this->getSesionWcf();
        if (!$sesion) {
            $this->logger->warning('[HTTP][POST] No se ha configurado sesionwcf');
        }
        
        if ($use_robust_retry) {
            // Asegurarnos que la sesión está incluida en el cuerpo
            if ($sesion && !isset($data['sesionwcf'])) {
                $data['sesionwcf'] = $sesion;
            }
            
            $this->logger->info('[HTTP][POST] Usando sistema de retry robusto', [
                'endpoint' => $endpoint,
                'data_keys' => array_keys($data),
                'sesionwcf_presente' => isset($data['sesionwcf']) ? 'sí' : 'no'
            ]);
            
            return $this->makeRequestWithRetry('POST', $endpoint, $data);
        }
        
        // Fallback a método legacy CURL
        if ($sesion) {
            $data['sesionwcf'] = $sesion;
        }
        
        $this->logger->info('[CURL][POST] Endpoint (método legacy)', [
            'endpoint' => $endpoint,
            'data_keys' => array_keys($data),
            'sesionwcf_presente' => isset($data['sesionwcf']) ? 'sí' : 'no'
        ]);
        
        return $this->curl_request($endpoint, [], $data);
    }

    /**
     * Realiza una solicitud PUT usando el sistema de retry robusto
     * 
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar en el cuerpo
     * @param array $options Opciones adicionales
     * @return array|WP_Error Respuesta de la API o error
     */
    public function put(string $endpoint, array $data = [], array $options = []) {
        $this->logger->info('[HTTP][PUT] Realizando solicitud PUT', [
            'endpoint' => $endpoint,
            'data_keys' => array_keys($data)
        ]);
        
        return $this->makeRequestWithRetry('PUT', $endpoint, $data, [], $options);
    }

    /**
     * Realiza una solicitud DELETE usando el sistema de retry robusto
     * 
     * @param string $endpoint Endpoint de la API
     * @param array $params Parámetros GET opcionales
     * @param array $options Opciones adicionales
     * @return array|WP_Error Respuesta de la API o error
     */
    public function delete(string $endpoint, array $params = [], array $options = []) {
        $this->logger->info('[HTTP][DELETE] Realizando solicitud DELETE', [
            'endpoint' => $endpoint,
            'params' => $params
        ]);
        
        return $this->makeRequestWithRetry('DELETE', $endpoint, [], $params, $options);
    }

    /**
     * Realiza una solicitud PATCH usando el sistema de retry robusto
     * 
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar en el cuerpo
     * @param array $options Opciones adicionales
     * @return array|WP_Error Respuesta de la API o error
     */
    public function patch(string $endpoint, array $data = [], array $options = []) {
        $this->logger->info('[HTTP][PATCH] Realizando solicitud PATCH', [
            'endpoint' => $endpoint,
            'data_keys' => array_keys($data)
        ]);
        
        return $this->makeRequestWithRetry('PATCH', $endpoint, $data, [], $options);
    }

    /**
     * Configura las opciones de retry para el ApiConnector
     * 
     * @param array $retry_config Configuración de retry
     *   - max_retries: Número máximo de reintentos (default: 3)
     *   - base_delay: Delay base en segundos (default: 1)
     *   - max_delay: Delay máximo en segundos (default: 60)
     *   - backoff_multiplier: Multiplicador para backoff exponencial (default: 2)
     *   - jitter: Agregar jitter aleatorio (default: true)
     * @return self Para method chaining
     */
    public function setRetryConfig(array $retry_config): self {
        $this->retry_config = array_merge([
            'max_retries' => 3,
            'base_delay' => 1,
            'max_delay' => 60,
            'backoff_multiplier' => 2,
            'jitter' => true
        ], $retry_config);
        
        // Actualizar max_retries para compatibilidad con métodos legacy
        if (isset($retry_config['max_retries'])) {
            $this->max_retries = $retry_config['max_retries'];
        }
        
        $this->logger->info('[CONFIG] Configuración de retry actualizada', $this->retry_config);
        
        return $this;
    }

    /**
     * Obtiene la configuración actual de retry
     * 
     * @return array Configuración de retry
     */
    public function getRetryConfig(): array {
        return $this->retry_config ?? [
            'max_retries' => $this->max_retries,
            'base_delay' => 1,
            'max_delay' => 60,
            'backoff_multiplier' => 2,
            'jitter' => true
        ];
    }

    /**
     * Configura timeouts específicos por método HTTP
     * 
     * @param array $timeouts Timeouts por método (ej: ['GET' => 30, 'POST' => 60])
     * @return self Para method chaining
     */
    public function setTimeoutConfig(array $timeouts): self {
        $this->timeout_config = array_merge($this->timeout_config, $timeouts);
        $this->logger->info('[CONFIG] Configuración de timeouts actualizada', $this->timeout_config);
        return $this;
    }

    /**
     * Obtiene el timeout configurado para un método HTTP específico
     * 
     * @param string $method Método HTTP
     * @return int Timeout en segundos
     */
    public function getTimeoutForMethod(string $method): int {
        return $this->timeout_config[strtoupper($method)] ?? $this->timeout;
    }

    /**
     * Configura una retry policy predefinida
     * 
     * @param string $policy_name Nombre de la policy ('critical', 'standard', 'background', 'realtime')
     * @return self Para method chaining
     * @throws \InvalidArgumentException Si la policy no existe
     */
    public function setRetryPolicy(string $policy_name): self {
        if (!isset($this->retry_policies[$policy_name])) {
            throw new \InvalidArgumentException("Retry policy '{$policy_name}' no existe. Políticas disponibles: " . implode(', ', array_keys($this->retry_policies)));
        }

        $this->setRetryConfig($this->retry_policies[$policy_name]);
        $this->logger->info("[CONFIG] Retry policy '{$policy_name}' aplicada", $this->retry_policies[$policy_name]);
        return $this;
    }

    /**
     * Configura el circuit breaker
     * 
     * @param array $config Configuración del circuit breaker
     * @return self Para method chaining
     */
    public function setCircuitBreakerConfig(array $config): self {
        $this->circuit_breaker = array_merge($this->circuit_breaker, $config);
        $this->logger->info('[CONFIG] Circuit breaker configurado', $this->circuit_breaker);
        return $this;
    }

    /**
     * Verifica el estado del circuit breaker antes de hacer una solicitud
     * 
     * @return bool True si la solicitud puede proceder, false si debe ser bloqueada
     */
    private function checkCircuitBreaker(): bool {
        if (!$this->circuit_breaker['enabled']) {
            return true;
        }

        $current_time = time();

        switch ($this->circuit_breaker['state']) {
            case 'closed':
                return true;

            case 'open':
                if ($current_time - $this->circuit_breaker['last_failure_time'] >= $this->circuit_breaker['recovery_timeout']) {
                    $this->circuit_breaker['state'] = 'half-open';
                    $this->circuit_breaker['current_failures'] = 0;
                    $this->logger->info('[CIRCUIT-BREAKER] Estado cambiado a half-open, permitiendo solicitudes de prueba');
                    return true;
                }
                return false;

            case 'half-open':
                return $this->circuit_breaker['current_failures'] < $this->circuit_breaker['half_open_max_calls'];

            default:
                return true;
        }
    }

    /**
     * Registra el resultado de una solicitud en el circuit breaker
     * 
     * @param bool $success Si la solicitud fue exitosa
     */
    private function recordCircuitBreakerResult(bool $success): void {
        if (!$this->circuit_breaker['enabled']) {
            return;
        }

        if ($success) {
            if ($this->circuit_breaker['state'] === 'half-open') {
                $this->circuit_breaker['state'] = 'closed';
                $this->circuit_breaker['current_failures'] = 0;
                $this->logger->info('[CIRCUIT-BREAKER] Recuperación exitosa, estado cambiado a closed');
            }
        } else {
            $this->circuit_breaker['current_failures']++;
            $this->circuit_breaker['last_failure_time'] = time();

            if ($this->circuit_breaker['current_failures'] >= $this->circuit_breaker['failure_threshold']) {
                $this->circuit_breaker['state'] = 'open';
                $this->logger->warning('[CIRCUIT-BREAKER] Límite de fallos alcanzado, estado cambiado a open', [
                    'failures' => $this->circuit_breaker['current_failures'],
                    'threshold' => $this->circuit_breaker['failure_threshold']
                ]);
            }
        }
    }

    /**
     * Calcula el delay usando la estrategia configurada
     * 
     * @param int $attempt Número del intento actual
     * @param array $retry_config Configuración de retry
     * @return float Delay en segundos
     */
    private function calculateRetryDelay(int $attempt, array $retry_config): float {
        $base_delay = $retry_config['base_delay'] ?? 1;
        $max_delay = $retry_config['max_delay'] ?? 60;
        $backoff_multiplier = $retry_config['backoff_multiplier'] ?? 2;
        $strategy = $retry_config['strategy'] ?? 'exponential';
        $jitter = $retry_config['jitter'] ?? true;

        switch ($strategy) {
            case 'linear':
                $delay = $base_delay + ($attempt * $base_delay);
                break;

            case 'exponential':
            default:
                $delay = $base_delay * pow($backoff_multiplier, $attempt);
                break;

            case 'fixed':
                $delay = $base_delay;
                break;

            case 'custom':
                // Para estrategias personalizadas, permitir callback
                if (isset($retry_config['custom_delay_function']) && is_callable($retry_config['custom_delay_function'])) {
                    $delay = call_user_func($retry_config['custom_delay_function'], $attempt, $retry_config);
                } else {
                    $delay = $base_delay * pow($backoff_multiplier, $attempt);
                }
                break;
        }

        // Aplicar límite máximo
        $delay = min($delay, $max_delay);

        // Agregar jitter si está habilitado
        if ($jitter) {
            $jitter_range = $delay * 0.1; // 10% de variación
            $jitter_amount = mt_rand(-$jitter_range * 1000, $jitter_range * 1000) / 1000;
            $delay = max(0.1, $delay + $jitter_amount);
        }

        return $delay;
    }

    /**
     * Actualiza las estadísticas de reintentos
     * 
     * @param int $retry_count Número de reintentos utilizados
     * @param bool $success Si la solicitud fue exitosa
     * @param int $status_code Código de estado HTTP
     */
    private function updateRetryStats(int $retry_count, bool $success, int $status_code = 0): void {
        // Inicializar el array si no existe
        if (!isset($this->retry_stats) || !is_array($this->retry_stats)) {
            $this->retry_stats = [
                'total_requests' => 0,
                'total_retries' => 0,
                'success_after_retry' => 0,
                'failed_after_retries' => 0,
                'avg_retry_count' => 0,
                'status_codes' => []
            ];
        }
        
        $this->retry_stats['total_requests']++;
        $this->retry_stats['total_retries'] += $retry_count;

        if ($success && $retry_count > 0) {
            $this->retry_stats['success_after_retry']++;
        } elseif (!$success) {
            $this->retry_stats['failed_after_retries']++;
        }

        // Actualizar promedio de reintentos
        $this->retry_stats['avg_retry_count'] = $this->retry_stats['total_retries'] / $this->retry_stats['total_requests'];

        // Estadísticas por código de estado
        if ($status_code > 0 && $retry_count > 0) {
            if (!isset($this->retry_stats['retry_by_status_code'][$status_code])) {
                $this->retry_stats['retry_by_status_code'][$status_code] = 0;
            }
            $this->retry_stats['retry_by_status_code'][$status_code]++;
        }
    }

    /**
     * Obtiene las estadísticas detalladas de reintentos
     * 
     * @return array Estadísticas de reintentos
     */
    public function getRetryStats(): array {
        return array_merge($this->retry_stats, [
            'circuit_breaker_state' => $this->circuit_breaker['state'] ?? 'disabled',
            'circuit_breaker_failures' => $this->circuit_breaker['current_failures'] ?? 0,
            'cache_stats' => $this->getCacheStats()
        ]);
    }

    /**
     * Obtiene estadísticas combinadas de retry y caché
     * 
     * @return array Estadísticas completas del sistema
     */
    public function getSystemStats(): array {
        $retry_stats = $this->getRetryStats();
        $cache_stats = $this->getCacheStats();
        
        return [
            'retry' => $retry_stats,
            'cache' => [
                'enabled' => $this->cache_enabled,
                'stats' => $cache_stats,
                'ttl_config' => $this->cache_ttl_config
            ],
            'performance' => [
                'total_requests' => $retry_stats['total_requests'],
                'cache_hit_ratio' => $cache_stats['hit_ratio'],
                'avg_retry_count' => $retry_stats['avg_retry_count'] ?? 0,
                'circuit_breaker_state' => $retry_stats['circuit_breaker_state']
            ]
        ];
    }

    /**
     * Reinicia las estadísticas de reintentos
     * 
     * @return self Para method chaining
     */
    public function resetRetryStats(): self {
        $this->retry_stats = [
            'total_requests' => 0,
            'total_retries' => 0,
            'success_after_retry' => 0,
            'failed_after_retries' => 0,
            'avg_retry_count' => 0,
            'retry_by_status_code' => []
        ];
        $this->logger->info('[STATS] Estadísticas de retry reiniciadas');
        return $this;
    }

    /**
     * Variable para rastrear el estado del circuito
     * @var array
     */
    private array $circuit_breaker = [
        'enabled' => false,
        'failure_threshold' => 5,
        'recovery_timeout' => 300, // 5 minutos
        'half_open_max_calls' => 3,
        'current_failures' => 0,
        'state' => 'closed', // closed, open, half-open
        'last_failure_time' => 0
    ];

    /**
     * Retry policies predefinidas por tipo de operación
     * @var array
     */
    private array $retry_policies = [
        'critical' => [
            'max_retries' => 5,
            'base_delay' => 2,
            'max_delay' => 120,
            'backoff_multiplier' => 2.5,
            'jitter' => true,
            'strategy' => 'exponential'
        ],
        'standard' => [
            'max_retries' => 3,
            'base_delay' => 1,
            'max_delay' => 60,
            'backoff_multiplier' => 2,
            'jitter' => true,
            'strategy' => 'exponential'
        ],
        'background' => [
            'max_retries' => 7,
            'base_delay' => 5,
            'max_delay' => 300,
            'backoff_multiplier' => 1.5,
            'jitter' => true,
            'strategy' => 'linear'
        ],
        'realtime' => [
            'max_retries' => 2,
            'base_delay' => 0.5,
            'max_delay' => 5,
            'backoff_multiplier' => 2,
            'jitter' => false,
            'strategy' => 'exponential'
        ]
    ];

    /**
     * Realiza una solicitud HTTP con sistema de retry robusto y backoff exponencial
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE, etc.)
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar en el cuerpo de la solicitud (para POST/PUT)
     * @param array $params Parámetros GET para agregar a la URL
     * @param array $options Opciones adicionales de la solicitud
     * @return array|WP_Error Respuesta de la API o error
     */
    public function makeRequestWithRetry(string $method, string $endpoint, array $data = [], array $params = [], array $options = []) {
        // 1. VERIFICACIÓN DE CACHÉ - Solo para métodos GET
        if ($this->cache_enabled && $this->cache_manager && strtoupper($method) === 'GET') {
            $cache_key = $this->generateCacheKey($method, $endpoint, $data, $params);
            $cached_response = $this->cache_manager->get($cache_key);
            
            if ($cached_response !== false) {
                $this->cache_stats['hits']++;
                $this->logger->info('[CACHE][HIT] Respuesta obtenida desde caché', [
                    'endpoint' => $endpoint,
                    'cache_key' => $cache_key,
                    'hit_ratio' => $this->getCacheStats()['hit_ratio'] . '%'
                ]);
                return $cached_response;
            } else {
                $this->cache_stats['misses']++;
                $this->logger->info('[CACHE][MISS] Respuesta no encontrada en caché', [
                    'endpoint' => $endpoint,
                    'cache_key' => $cache_key
                ]);
            }
        }

        // 2. VERIFICACIÓN CIRCUIT BREAKER
        if (!$this->checkCircuitBreaker()) {
            $error = new \WP_Error(
                'circuit_breaker_open',
                'Circuit breaker está abierto, solicitudes bloqueadas temporalmente',
                ['state' => $this->circuit_breaker['state'], 'failures' => $this->circuit_breaker['current_failures']]
            );
            $this->updateRetryStats(0, false, 0);
            return $error;
        }

        // Configuración del retry - usar retry_config si está disponible, sino options, sino defaults
        $retry_config = array_merge($this->getRetryConfig(), $options);
        $max_retries = $retry_config['max_retries'];
        $timeout = $options['timeout'] ?? $this->getTimeoutForMethod($method);
        
        // Códigos de estado HTTP que deben reintentarse
        $retryable_codes = [
            408, // Request Timeout
            429, // Too Many Requests
            500, // Internal Server Error
            502, // Bad Gateway
            503, // Service Unavailable
            504, // Gateway Timeout
            520, // Unknown Error (Cloudflare)
            521, // Web Server Is Down (Cloudflare)
            522, // Connection Timed Out (Cloudflare)
            523, // Origin Is Unreachable (Cloudflare)
            524, // A Timeout Occurred (Cloudflare)
        ];
        
        $this->last_retry_count = 0;
        $last_error = null;
        $request_start_time = microtime(true);
        
        // Preparar URL
        $endpoint = (string) $endpoint;
        
        // Verificar si el endpoint ya contiene la URL base de la API
        if (strpos($endpoint, 'http://') === 0 || strpos($endpoint, 'https://') === 0) {
            $url = $endpoint; // El endpoint ya es una URL completa
            $this->logger->info('[URL] Usando URL completa proporcionada en el endpoint', ['url' => $url]);
        } else {
            // Obtener URL base de la API
            $base = $this->get_api_base_url();
            if (empty($base)) {
                $this->logger->error('[URL] URL base de API no configurada');
                return new \WP_Error('api_url_missing', 'URL base de API no configurada');
            }
            
            // Verificar si el endpoint ya contiene el path base de la API o es solo un nombre de función
            if (strpos($endpoint, '.php') !== false || strpos($endpoint, '?') === 0) {
                $url = rtrim($base, '/') . '/' . ltrim($endpoint, '/');
            } else {
                // Según la documentación y colección de Postman, el formato correcto es /WcfServiceLibraryVerial/NombreFuncion
                // Separamos el endpoint por si contiene parámetros como "&pagina=1"
                $endpoint_parts = explode('&', $endpoint, 2);
                $function_name = $endpoint_parts[0];
                $params = isset($endpoint_parts[1]) ? $endpoint_parts[1] : '';
                
                // Construir la URL con el método como parte de la ruta
                $url = rtrim($base, '/') . '/' . $function_name;
                
                // No añadimos parámetros aquí, se manejarán más adelante con http_build_query
                // Los parámetros serán añadidos después con comprobaciones adicionales
                // para evitar duplicados
            }
            
            // Validar la URL resultante
            if (!filter_var($url, FILTER_VALIDATE_URL)) {
                $this->logger->warning('[URL] URL potencialmente inválida construida', [
                    'base' => $base, 
                    'endpoint' => $endpoint, 
                    'url' => $url
                ]);
            }
            
            $this->logger->info('[URL] URL construida para endpoint', ['endpoint' => $endpoint, 'url' => $url]);
        }
        
        // Agregar parámetros GET a la URL, asegurando que no haya duplicación del parámetro 'x'
        if (!empty($params)) {
            // Si la URL ya tiene el parámetro 'x' pero también viene en los parámetros, eliminarlo de los parámetros
            if (strpos($url, 'x=') !== false && isset($params['x'])) {
                $this->logger->debug('[URL] Eliminando parámetro x duplicado de los parámetros');
                unset($params['x']);
            }
            
            // Añadir parámetros GET restantes a la URL
            if (!empty($params)) {
                $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
            }
        }
        
        // Configurar argumentos base de la solicitud
        $ssl_config = $this->getSSLConfiguration();
        $args = array_merge([
            'method' => strtoupper($method),
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'Mi-Integracion-API/1.0'
            ],
            'sslverify' => $ssl_config['verify_peer'],
            'sslcertificates' => !empty($ssl_config['ca_bundle_path']) && file_exists($ssl_config['ca_bundle_path']) ? $ssl_config['ca_bundle_path'] : null,
            'redirection' => 5,
        ], $options['wp_args'] ?? []);
        
        // Configuración SSL adicional para wp_remote_*
        if (!empty($ssl_config['ca_bundle_path']) && file_exists($ssl_config['ca_bundle_path'])) {
            $args['sslcertificates'] = $ssl_config['ca_bundle_path'];
        }
        
        // Agregar datos al cuerpo para métodos POST/PUT/PATCH
        if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE']) && !empty($data)) {
            $args['body'] = json_encode($data);
        }
        
        // Agregar sesionwcf para compatibilidad con Verial
        $sesion = $this->getSesionWcf();
        if ($sesion) {
            $this->logger->info('[URL] Agregando sesionwcf a la solicitud', ['sesionwcf' => $sesion]);
            
            // Para todos los métodos HTTP, asegurarnos que la sesión esté incluida UNA SOLA VEZ
            if (in_array(strtoupper($method), ['GET', 'HEAD'])) {
                // Verificar si ya existe el parámetro x en la URL
                $url_has_x = preg_match('/[?&]x=/', $url);
                $params_has_x = isset($params['x']);
                
                // Si la URL ya tiene el parámetro x múltiples veces, limpiarla
                if ($url_has_x) {
                    // Extraer partes de la URL
                    $url_parts = parse_url($url);
                    $base = $url_parts['scheme'] . '://' . $url_parts['host'];
                    if (isset($url_parts['port'])) $base .= ':' . $url_parts['port'];
                    if (isset($url_parts['path'])) $base .= $url_parts['path'];
                    
                    // Analizar los parámetros de consulta
                    $query = [];
                    if (isset($url_parts['query'])) {
                        parse_str($url_parts['query'], $query);
                    }
                    
                    // Asegurar que solo haya un parámetro 'x'
                    $query['x'] = $sesion;
                    
                    // Reconstruir la URL sin duplicados
                    $url = $base . '?' . http_build_query($query);
                    $this->logger->debug('[URL] URL reconstruida para eliminar parámetros x duplicados', ['url' => $url]);
                } else if (!$params_has_x) {
                    // Si no tiene parámetro x, agregarlo
                    $separator = strpos($url, '?') === false ? '?' : '&';
                    $url .= $separator . 'x=' . $sesion;
                    $this->logger->debug('[URL] Parámetro x=sesionwcf añadido a la URL');
                } else {
                    $this->logger->debug('[URL] Parámetro x=sesionwcf ya presente en params, no se duplica');
                }
            } else {
                // Para POST/PUT/etc., siempre agregamos al cuerpo JSON
                if (empty($data)) {
                    $data = [];
                }
                if (!isset($data['sesionwcf'])) {
                    $data['sesionwcf'] = $sesion;
                }
                $args['body'] = json_encode($data);
            }
            
            // Verificar si la URL final es válida y tiene el parámetro de sesión
            $this->logger->debug('[URL] URL final para solicitud', [
                'url' => $url, 
                'method' => $method,
                'tiene_sesion' => strpos($url, 'x=') !== false || isset($data['sesionwcf']),
                'post_data_keys' => is_array($data) ? array_keys($data) : 'no_data'
            ]);
        } else {
            $this->logger->error('[URL] No se pudo obtener sesionwcf para la solicitud', [
                'method' => $method,
                'endpoint' => $endpoint,
                'url' => $url
            ]);
        }
        
        // Bucle de reintentos
        for ($attempt = 0; $attempt <= $max_retries; $attempt++) {
            $this->last_retry_count = $attempt;
            
            // Log del intento
            $this->logger->info('[RETRY] Intento de solicitud HTTP', [
                'attempt' => $attempt + 1,
                'max_attempts' => $max_retries + 1,
                'method' => $method,
                'url' => $url,
                'timeout' => $timeout,
                'strategy' => $retry_config['strategy'] ?? 'exponential'
            ]);
            
            // Verificar estado del circuit breaker
            if (!$this->checkCircuitBreaker()) {
                $this->logger->warning('[RETRY] Circuit breaker abierto, abortando solicitud', [
                    'state' => $this->circuit_breaker['state'],
                    'failures' => $this->circuit_breaker['current_failures']
                ]);
                $this->updateRetryStats($attempt, false, 0);
                return new \WP_Error('circuit_breaker_open', 'El circuito está abierto, la solicitud no puede ser procesada');
            }

            // Actualizar timeout en args con estrategia más agresiva
            $args['timeout'] = $timeout;
            
            // ESTRATEGIA ANTI-TIMEOUT: Configuraciones adicionales para rangos problemáticos
            if (strpos($url, 'GetArticulosWS') !== false) {
                // Extraer rangos para detectar si es problemático
                preg_match('/inicio=(\d+)/', $url, $inicio_matches);
                preg_match('/fin=(\d+)/', $url, $fin_matches);
                
                if (!empty($inicio_matches[1]) && !empty($fin_matches[1])) {
                    $inicio = (int)$inicio_matches[1];
                    $fin = (int)$fin_matches[1];
                    
                    // Aplicar configuraciones especiales para rangos problemáticos
                    $problematic_ranges = [
                        [3801, 4000], [3901, 4000], [2601, 2700], [2801, 2900], 
                        [3101, 3200], [3301, 3400], [3501, 3600], [3701, 3800]
                    ];
                    
                    $is_problematic = false;
                    foreach ($problematic_ranges as $range) {
                        if ($inicio >= $range[0] && $inicio <= $range[1]) {
                            $is_problematic = true;
                            break;
                        }
                    }
                    
                    if ($is_problematic) {
                        // Configuraciones especiales para rangos problemáticos
                        $args['timeout'] = max(120, $timeout * 2); // Timeout mucho más largo
                        $args['httpversion'] = '1.0'; // Usar HTTP 1.0 más simple
                        $args['stream'] = false; // Desactivar streaming
                        $args['compress'] = false; // Desactivar compresión
                        $args['decompress'] = false; // Desactivar descompresión
                        
                        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                            $logger = new \MiIntegracionApi\Helpers\Logger('api-connector-problematic');
                            $logger->info(
                                sprintf('Aplicando configuración especial para rango problemático %d-%d', $inicio, $fin),
                                [
                                    'timeout_original' => $timeout,
                                    'timeout_ajustado' => $args['timeout'],
                                    'configuraciones_especiales' => [
                                        'httpversion' => '1.0',
                                        'stream' => false,
                                        'compress' => false
                                    ]
                                ]
                            );
                        }
                    }
                }
            }
            
            // Realizar la solicitud y capturar información detallada
            $request_start_time = microtime(true);
            if (function_exists('wp_remote_request')) {
                $response = wp_remote_request($url, $args);
                $request_end_time = microtime(true);
                $request_duration = $request_end_time - $request_start_time;
                
                // Análisis detallado de la respuesta para diagnóstico
                $is_wp_error = is_wp_error($response);
                $response_code = $is_wp_error ? 'error' : wp_remote_retrieve_response_code($response);
                $response_message = $is_wp_error ? $response->get_error_message() : wp_remote_retrieve_response_message($response);
                $response_headers = $is_wp_error ? [] : wp_remote_retrieve_headers($response);
                $response_body = $is_wp_error ? '' : wp_remote_retrieve_body($response);
                $response_size = strlen($response_body);
                
                // Log detallado de la respuesta para rangos específicos o errores
                $should_log_detailed = $is_wp_error || $response_size == 0 || $response_code >= 400;
                
                // También log detallado si estamos en un rango problemático conocido
                if (!$should_log_detailed && strpos($url, 'GetArticulosWS') !== false) {
                    // Extraer parámetros inicio y fin de la URL para detectar rangos problemáticos
                    preg_match('/inicio=(\d+)/', $url, $inicio_matches);
                    preg_match('/fin=(\d+)/', $url, $fin_matches);
                    
                    if (!empty($inicio_matches[1]) && !empty($fin_matches[1])) {
                        $inicio = (int)$inicio_matches[1];
                        $fin = (int)$fin_matches[1];
                        
                        // Rangos problemáticos conocidos - expandidos basado en patrones detectados
                        $problematic_ranges = [
                            // Rangos conocidos con problemas confirmados
                            [2601, 2610], [2801, 2810], [3101, 3110],
                            
                            // Rangos expandidos basados en patrones detectados
                            [2501, 2510], [2701, 2710], [2901, 2910], [3001, 3010], [3201, 3210],
                            [3301, 3310], [3401, 3410], [3501, 3510], [3601, 3610], [3701, 3710],
                            
                            // Rangos adicionales en franjas críticas
                            [2401, 2410], [2481, 2490], [2581, 2590], [2681, 2690], [2781, 2790],
                            [2881, 2890], [2981, 2990], [3081, 3090], [3181, 3190], [3281, 3290],
                            
                            // Rangos de productos con IDs específicos que pueden causar problemas
                            [1, 10], [91, 100], [191, 200], [291, 300], [391, 400],
                            [991, 1000], [1991, 2000], [2991, 3000], [3991, 4000], [4991, 5000]
                        ];
                        
                        foreach ($problematic_ranges as $range) {
                            if (($inicio >= $range[0] && $inicio <= $range[1]) || 
                                ($fin >= $range[0] && $fin <= $range[1])) {
                                $should_log_detailed = true;
                                break;
                            }
                        }
                    }
                }
                
                if ($should_log_detailed) {
                    $this->logger->info('[HTTP_DETAILED] Respuesta detallada capturada', [
                        'url' => $url,
                        'method' => $method,
                        'request_duration' => round($request_duration, 4),
                        'response_code' => $response_code,
                        'response_message' => $response_message,
                        'response_size' => $response_size,
                        'response_headers' => array_slice((array)$response_headers, 0, 10), // Limitar headers
                        'response_first_200_chars' => substr($response_body, 0, 200),
                        'response_last_100_chars' => $response_size > 100 ? substr($response_body, -100) : '',
                        'is_json' => json_decode($response_body) !== null,
                        'json_error' => json_last_error_msg(),
                        'attempt' => $attempt + 1,
                        'is_wp_error' => $is_wp_error,
                        'memory_usage' => memory_get_usage(true),
                        'timestamp' => date('Y-m-d H:i:s')
                    ]);
                }
                
                $is_wp_error = is_wp_error($response);
            } else {
                // Fallback usando cURL directo cuando no hay WordPress
                $response = $this->makeRequestWithCurl($url, $args);
                $is_wp_error = isset($response['error']);
            }
            
            // Verificar si hay error de WordPress/HTTP o cURL
            if ($is_wp_error) {
                if (function_exists('wp_remote_request')) {
                    // WordPress error handling
                    $error_code = $response->get_error_code();
                    $error_message = $response->get_error_message();
                    $error_data = $response->get_error_data();
                    $last_error = new \WP_Error($error_code, $error_message, $error_data);
                } else {
                    // cURL error handling
                    $error_code = $response['error_code'] ?? 'curl_error';
                    $error_message = $response['error_message'] ?? 'Unknown cURL error';
                    $error_data = $response['error_data'] ?? [];
                    $last_error = new \WP_Error($error_code, $error_message, $error_data);
                }
                
                // Información de diagnóstico mejorada cuando se solicita
                $log_context = [
                    'attempt' => $attempt + 1,
                    'error_code' => $error_code,
                    'error_message' => $error_message,
                    'url' => $url
                ];
                
                // Agregar información de diagnóstico detallada si está habilitada
                if (!empty($options['diagnostics'])) {
                    $log_context['error_data'] = $error_data;
                    $log_context['request_args'] = $args;
                    $log_context['full_url'] = $url;
                    $log_context['sesion'] = $this->sesionwcf;
                    $log_context['php_version'] = PHP_VERSION;
                    $log_context['timeout'] = $args['timeout'];
                }
                
                // Rastrear la solicitud completa si está habilitado
                if (!empty($options['trace_request'])) {
                    $log_context['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
                    $log_context['server_info'] = $_SERVER;
                }
                
                $this->logger->error('[RETRY] Error HTTP/WordPress', $log_context);
                
                // Algunos errores no deben reintentarse
                $non_retryable_errors = ['http_request_failed', 'rest_forbidden', 'rest_authentication_error'];
                if (in_array($error_code, $non_retryable_errors) && $attempt < $max_retries) {
                    $this->logger->warning('[RETRY] Error no reintentable, abortando', [
                        'error_code' => $error_code
                    ]);
                    break;
                }
            } else {
                // Verificar código de estado HTTP
                if (function_exists('wp_remote_retrieve_response_code')) {
                    // WordPress response handling
                    $response_code = wp_remote_retrieve_response_code($response);
                    $response_body = wp_remote_retrieve_body($response);
                    $response_message = wp_remote_retrieve_response_message($response);
                    $response_headers = wp_remote_retrieve_headers($response);
                } else {
                    // cURL response handling
                    $response_code = $response['status_code'] ?? 0;
                    $response_body = $response['body'] ?? '';
                    $response_message = $response['status_message'] ?? '';
                    $response_headers = $response['headers'] ?? [];
                }
                
                $this->logger->info('[RETRY] Respuesta recibida', [
                    'attempt' => $attempt + 1,
                    'status_code' => $response_code,
                    'body_length' => strlen($response_body)
                ]);
                
                // Si es exitoso (2xx), devolver respuesta
                if ($response_code >= 200 && $response_code < 300) {
                    $log_context = [
                        'attempts_used' => $attempt + 1,
                        'status_code' => $response_code,
                        'total_time' => round(microtime(true) - $request_start_time, 3),
                        'body_size' => strlen($response_body)
                    ];
                    
                    // Agregar información detallada de diagnóstico cuando se solicita
                    if (!empty($options['diagnostics'])) {
                        $log_context['headers'] = $response_headers;
                        $log_context['request_args'] = $args;
                        $log_context['response_time'] = date('Y-m-d H:i:s');
                    }
                    
                    $this->logger->info('[RETRY] Solicitud exitosa', $log_context);
                    
                    // Registrar éxito en circuit breaker y estadísticas
                    $this->recordCircuitBreakerResult(true);
                    $this->updateRetryStats($attempt, true, $response_code);
                    
                    // Decodificar respuesta JSON
                    $decoded_data = json_decode($response_body, true);
                    $is_valid_json = json_last_error() === JSON_ERROR_NONE;
                    
                    // Preparar respuesta final
                    $final_response = null;
                    
                    if ($is_valid_json) {
                        $final_response = $decoded_data;
                        
                        // Verificar si la respuesta de Verial tiene errores lógicos
                        if (isset($decoded_data['InfoError']) &&
                            isset($decoded_data['InfoError']['Codigo']) &&
                            $decoded_data['InfoError']['Codigo'] != 0) {
                            
                            $error_message = $decoded_data['InfoError']['Descripcion'] ?? 'Error desconocido de Verial';
                            $error_code = $decoded_data['InfoError']['Codigo'];
                            
                            // Determinar si este error es transitorio
                            $is_transient_error = false;
                            if (!empty($options['retry_transient_errors'])) {
                                $transient_codes = [-100, -101, -200, -500]; // Códigos transitorios conocidos
                                $is_transient_error = in_array($error_code, $transient_codes) ||
                                                     strpos(strtolower($error_message), 'timeout') !== false ||
                                                     strpos(strtolower($error_message), 'sesión') !== false;
                            }
                            
                            if ($is_transient_error && $attempt < $max_retries) {
                                $this->logger->warning('[RETRY] Error transitorio detectado en respuesta', [
                                    'error_code' => $error_code,
                                    'error_message' => $error_message,
                                    'attempt' => $attempt + 1,
                                    'max_retries' => $max_retries
                                ]);
                                
                                // Crear un error para forzar un reintento
                                $last_error = new \WP_Error(
                                    'verial_transient_error',
                                    "Error transitorio de Verial: ({$error_code}) {$error_message}",
                                    ['response_body' => $response_body]
                                );
                                
                                // No devolver la respuesta aún, continuar con el bucle
                                // Saltamos a la siguiente iteración del loop
                                continue;
                            }
                        }
                    } else {
                        // Si no es JSON válido, devolver respuesta raw
                        $final_response = [
                            'status_code' => $response_code,
                            'body' => $response_body,
                            'headers' => $response_headers,
                            'json_error' => json_last_error_msg()
                        ];
                        
                        // Registrar cuando la respuesta no es JSON válida
                        $this->logger->warning('[RETRY] Respuesta no es JSON válido', [
                            'json_error' => json_last_error_msg(),
                            'body_preview' => substr($response_body, 0, 100)
                        ]);
                    }
                    
                    // 3. GUARDAR EN CACHÉ - Solo para métodos GET exitosos
                    if ($this->cache_enabled && $this->cache_manager && strtoupper($method) === 'GET' && $this->shouldCacheResponse($final_response, $response_code)) {
                        $cache_key = $this->generateCacheKey($method, $endpoint, $data, $params);
                        $ttl = $this->getCacheTtlForEndpoint($endpoint);
                        
                        $cache_saved = $this->cache_manager->set($cache_key, $final_response, $ttl);
                        if ($cache_saved) {
                            $this->cache_stats['sets']++;
                            $this->logger->info('[CACHE][SET] Respuesta guardada en caché', [
                                'endpoint' => $endpoint,
                                'cache_key' => $cache_key,
                                'ttl' => $ttl,
                                'size_bytes' => strlen(serialize($final_response))
                            ]);
                        } else {
                            $this->logger->warning('[CACHE][ERROR] Error guardando respuesta en caché', [
                                'endpoint' => $endpoint,
                                'cache_key' => $cache_key
                            ]);
                        }
                    }
                    
                    return $final_response;
                }
                
                // Si es un error retryable, continuar con reintentos
                if (in_array($response_code, $retryable_codes)) {
                    // Construir contexto detallado para el error
                    $error_context = [
                        'status' => $response_code,
                        'body' => substr($response_body, 0, 1000),
                        'url' => $url,
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'attempt' => $attempt + 1,
                        'session_id' => $this->sesionwcf
                    ];
                    
                    // Si las opciones de diagnóstico están habilitadas, añadir más información
                    if (!empty($options['diagnostics'])) {
                        // Añadir información de encabezados de respuesta para diagnóstico
                        $error_context['response_headers'] = $response_headers;
                        
                        // Intentar decodificar el cuerpo para verificar si hay errores de JSON
                        $json_decode_error = json_last_error_msg();
                        if ($json_decode_error !== 'No error') {
                            $error_context['json_error'] = $json_decode_error;
                        }
                        
                        // Añadir detalles sobre los parámetros de la solicitud
                        $error_context['request_params'] = $params;
                    }
                    
                    $last_error = new \WP_Error(
                        'http_error_retryable',
                        "HTTP Error {$response_code}: " . $response_message,
                        $error_context
                    );
                    
                    // Registro detallado para depuración
                    $log_context = [
                        'attempt' => $attempt + 1,
                        'status_code' => $response_code,
                        'response_message' => $response_message,
                        'url' => $url,
                        'body_preview' => substr($response_body, 0, 200)
                    ];
                    
                    // Añadir información de diagnóstico detallada para el registro
                    if (!empty($options['diagnostics'])) {
                        $log_context['endpoint'] = $endpoint;
                        $log_context['params'] = $params;
                        $log_context['timeout'] = $args['timeout'];
                        $log_context['next_retry_in'] = $this->calculateRetryDelay($attempt, $retry_config);
                    }
                    
                    $this->logger->warning('[RETRY] Error HTTP retryable', $log_context);
                } else {
                    // Error no retryable, devolver inmediatamente
                    $error_code = 'http_error_final';
                    
                    // Construir contexto detallado para el error
                    $error_context = [
                        'status' => $response_code,
                        'body_preview' => substr($response_body, 0, 1000),
                        'url' => $url,
                        'endpoint' => $endpoint,
                        'method' => $method,
                        'attempt' => $attempt + 1,
                        'session_id' => $this->sesionwcf
                    ];
                    
                    // Si las opciones de diagnóstico están habilitadas, añadir más información
                    if (!empty($options['diagnostics'])) {
                        // Añadir información de encabezados de respuesta para diagnóstico
                        $error_context['response_headers'] = $response_headers;
                        
                        // Añadir detalles del cuerpo JSON si es posible
                        $decoded_json = json_decode($response_body, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            if (isset($decoded_json['InfoError'])) {
                                $error_context['verial_error'] = $decoded_json['InfoError'];
                            }
                            // Añadir primeras claves de la respuesta para diagnóstico
                            $error_context['response_keys'] = array_keys($decoded_json);
                        } else {
                            $error_context['json_error'] = json_last_error_msg();
                        }
                        
                        // Añadir detalles sobre los parámetros de la solicitud
                        $error_context['request_params'] = $params;
                        $error_context['request_data'] = $data;
                    }
                    
                    // Mensaje específico para errores 404
                    if ($response_code === 404) {
                        $error_message = "HTTP Error 404: Recurso no encontrado. Verifique la URL y los parámetros.";
                        
                        $log_context = [
                            'status_code' => $response_code,
                            'url' => $url,
                            'sesionwcf_presente' => !empty($this->getSesionWcf()) ? 'sí' : 'no',
                            'endpoint' => $endpoint
                        ];
                        
                        // Añadir información de diagnóstico detallada
                        if (!empty($options['diagnostics'])) {
                            $log_context['params'] = $params;
                            $log_context['constructed_url'] = $this->build_api_url($endpoint);
                            $log_context['body_preview'] = substr($response_body, 0, 200);
                        }
                        
                        $this->logger->error('[RETRY] Error 404: URL no encontrada', $log_context);
                    } else {
                        $error_message = "HTTP Error {$response_code}: " . $response_message;
                        
                        $log_context = [
                            'status_code' => $response_code,
                            'response_message' => $response_message,
                            'url' => $url,
                            'endpoint' => $endpoint
                        ];
                        
                        // Añadir información de diagnóstico detallada
                        if (!empty($options['diagnostics'])) {
                            $log_context['body_preview'] = substr($response_body, 0, 500);
                            $log_context['params'] = $params;
                            $log_context['data'] = $data;
                            $log_context['headers'] = $response_headers;
                            
                            // Intentar extraer información de errores específicos de Verial
                            $decoded_json = json_decode($response_body, true);
                            if (json_last_error() === JSON_ERROR_NONE && isset($decoded_json['InfoError'])) {
                                $log_context['verial_error'] = $decoded_json['InfoError'];
                            }
                        }
                        
                        $this->logger->error('[RETRY] Error HTTP no retryable', $log_context);
                    }
                    
                    // Registrar fallo en circuit breaker y estadísticas
                    $this->recordCircuitBreakerResult(false);
                    $this->updateRetryStats($attempt, false, $response_code);
                    
                    // Si está habilitado el rastreo de solicitudes, añadir información adicional
                    if (!empty($options['trace_request'])) {
                        $trace_info = [
                            'url' => $url,
                            'args' => $args,
                            'response_code' => $response_code,
                            'response_body_preview' => substr($response_body, 0, 1000),
                            'session_id' => $this->sesionwcf,
                            'endpoint' => $endpoint,
                            'time' => date('Y-m-d H:i:s'),
                            'server_info' => $_SERVER
                        ];
                        
                        $this->logger->error('[API_CONNECTOR] Diagnóstico completo del error final', $trace_info);
                    } else {
                        // Log básico sin información detallada
                        $this->logger->error('[API_CONNECTOR] Error final de la solicitud', [
                            'url' => $url,
                            'status_code' => $response_code
                        ]);
                    }
               
                    return new \WP_Error($error_code, $error_message, $error_context);
                   }
                  }
                  
                  // Si llegamos aquí y es el último intento, no hacer delay
            if ($attempt >= $max_retries) {
                break;
            }
            
            // Calcular delay usando la nueva estrategia configurable
            $delay = $this->calculateRetryDelay($attempt, $retry_config);
            
            $this->logger->info('[RETRY] Esperando antes del siguiente intento', [
                'delay_seconds' => round($delay, 2),
                'next_attempt' => $attempt + 2,
                'strategy' => $retry_config['strategy'] ?? 'exponential'
            ]);
            
            // Esperar antes del siguiente intento
            usleep($delay * 1000000); // usleep acepta microsegundos
        }
        
        // Si llegamos aquí, todos los reintentos fallaron
        $this->logger->error('[RETRY] Todos los reintentos fallaron', [
            'total_attempts' => $this->last_retry_count + 1,
            'max_retries' => $max_retries,
            'total_time' => round(microtime(true) - $request_start_time, 3),
            'final_error' => $last_error ? $last_error->get_error_message() : 'Error desconocido'
        ]);
        
        // Registrar fallo final en circuit breaker y estadísticas
        $this->recordCircuitBreakerResult(false);
        $this->updateRetryStats($this->last_retry_count, false, 0);
        
        // Devolver el último error o crear uno genérico
        return $last_error ?: new \WP_Error(
            'max_retries_exceeded',
            "Se excedió el número máximo de reintentos ({$max_retries}) para la solicitud {$method} {$endpoint}",
            ['attempts' => $this->last_retry_count + 1]
        );
    }

    /**
     * Verifica el estado de salud de la API
     * 
     * @return bool|WP_Error True si la API responde correctamente, WP_Error en caso contrario
     */
    public function check_api_health() {
        try {
            // Intentamos hacer una solicitud ligera para verificar que la API está disponible
            // Usamos el endpoint de categorías o fabricantes que suele ser más rápido que productos
            $endpoint = $this->endpoint . '/GetCategoriasWS';
            $params = ['inicio' => 1, 'fin' => 1];  // Solo pedimos una categoría
            
            $response = $this->make_api_request($endpoint, $params);
            
            // Si la respuesta contiene un error específico de la API
            if (is_array($response) && isset($response['Error']) && $response['Error'] === true) {
                return new \WP_Error(
                    'api_error',
                    $response['Mensaje'] ?? 'Error devuelto por la API sin mensaje específico',
                    $response
                );
            }
            
            // Si la respuesta es un array simple o un objeto, probablemente esté bien
            if (is_array($response) || is_object($response)) {
                return true;
            }
            
            // Si no tenemos un formato reconocible, podría ser un problema
            return new \WP_Error(
                'api_unexpected_response',
                'La API devolvió una respuesta en un formato inesperado',
                $response
            );
            
        } catch (\Exception $e) {
            return new \WP_Error(
                'api_exception',
                'Error al verificar el estado de la API: ' . $e->getMessage(),
                ['exception' => $e]
            );
        }
    }

    /**
     * Wrapper: Obtiene artículos de Verial.
     * @param array $params Parámetros opcionales (inicio, fin, fecha, etc.)
     * @return array|WP_Error
     */
    public function get_articulos($params = array()) {
        $endpoint = 'GetArticulosWS';
        $result = $this->get($endpoint, $params);
        
        // Iniciar logger para diagnóstico
        $logger = new \MiIntegracionApi\Helpers\Logger('api-articulos');
        $logger->debug('Respuesta cruda API artículos', [
            'params' => $params,
            'tipo_resultado' => is_wp_error($result) ? 'WP_Error' : gettype($result)
        ]);
        
        if (is_wp_error($result)) {
            $logger->error('Error al obtener artículos', [
                'error_code' => $result->get_error_code(),
                'error_mensaje' => $result->get_error_message()
            ]);
            return $result;
        }
        
        // Normalizar la respuesta para tener un formato consistente
        if (isset($result['Articulos']) && is_array($result['Articulos'])) {
            $logger->info('Formato estándar con clave Articulos', [
                'cantidad' => count($result['Articulos'])
            ]);
            return $result;
        } elseif (is_array($result) && !isset($result['Articulos']) && !isset($result['InfoError'])) {
            // Si la respuesta es un array plano de productos (formato alternativo)
            $logger->info('Formato array plano de productos', [
                'cantidad' => count($result)
            ]);
            
            // Convertir a formato estándar
            return [
                'Articulos' => $result,
                'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
            ];
        }
        
        // Si hay información de error en la respuesta
        if (isset($result['InfoError'])) {
            if ($result['InfoError']['Codigo'] != 0) {
                $logger->error('Error desde API Verial', [
                    'codigo' => $result['InfoError']['Codigo'],
                    'descripcion' => $result['InfoError']['Descripcion'] ?? 'Sin descripción'
                ]);
                
                return new \WP_Error(
                    'verial_api_error',
                    $result['InfoError']['Descripcion'] ?? 'Error en API de Verial',
                    ['codigo' => $result['InfoError']['Codigo']]
                );
            }
            
            // Respuesta exitosa pero sin artículos
            if (!isset($result['Articulos'])) {
                $logger->info('Respuesta sin artículos', [
                    'result_keys' => array_keys($result)
                ]);
                return [
                    'Articulos' => [],
                    'InfoError' => $result['InfoError']
                ];
            }
        }
        
        return $result;
    }

    /**
     * Busca un producto específicamente por su ReferenciaBarras (SKU en WooCommerce).
     * Este método está optimizado para búsquedas rápidas por código de barras.
     *
     * @since 2.0.0
     * @param string $referencia_barras El código de barras o SKU a buscar
     * @param array $params_adicionales Parámetros adicionales opcionales
     * @return array|WP_Error Producto encontrado o error
     */
    public function searchProductByReferenciaBarras($referencia_barras, $params_adicionales = []) {
        if (empty($referencia_barras)) {
            return new \WP_Error(
                'param_invalid',
                'Se requiere un código de barras válido para la búsqueda',
                ['param' => 'referencia_barras']
            );
        }
        
        $logger = new \MiIntegracionApi\Helpers\Logger('search-product');
        $logger->info('Buscando producto por ReferenciaBarras', [
            'referencia' => $referencia_barras,
            'params_adicionales' => $params_adicionales
        ]);
        
        // Construir parámetros para la búsqueda específica
        $params = array_merge(['referenciaBarras' => $referencia_barras], $params_adicionales);
        
        // Realizar la búsqueda usando el método general
        $result = $this->get_articulos($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Procesar el resultado para extraer el producto
        if (isset($result['Articulos']) && is_array($result['Articulos']) && !empty($result['Articulos'])) {
            $logger->info('Producto encontrado por ReferenciaBarras', [
                'cantidad' => count($result['Articulos']),
                'primer_id' => isset($result['Articulos'][0]['Id']) ? $result['Articulos'][0]['Id'] : 'no_id'
            ]);
            
            // Filtrar para asegurar una coincidencia exacta
            $matches = array_filter($result['Articulos'], function($item) use ($referencia_barras) {
                return isset($item['ReferenciaBarras']) && 
                       strtolower((string)$item['ReferenciaBarras']) === strtolower((string)$referencia_barras);
            });
            
            if (!empty($matches)) {
                $logger->info('Coincidencia exacta encontrada', [
                    'cantidad_exacta' => count($matches)
                ]);
                
                $normalized = [
                    'Articulos' => array_values($matches),
                    'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
                ];
                return $normalized;
            }
            
            return $result; // Devolver todos los resultados si no hay coincidencia exacta
        }
        
        $logger->info('No se encontraron productos por ReferenciaBarras', [
            'referencia' => $referencia_barras
        ]);
        
        return [
            'Articulos' => [],
            'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
        ];
    }

    /**
     * Busca un producto específicamente por su código interno (Id en Verial).
     * Este método está optimizado para búsquedas rápidas por ID numérico.
     *
     * @since 2.0.0
     * @param int $codigo El ID interno del producto en Verial
     * @param array $params_adicionales Parámetros adicionales opcionales
     * @return array|WP_Error Producto encontrado o error
     */
    public function searchProductByCodigo($codigo, $params_adicionales = []) {
        if (empty($codigo)) {
            return new \WP_Error(
                'param_invalid',
                'Se requiere un código válido para la búsqueda',
                ['param' => 'codigo']
            );
        }
        
        $logger = new \MiIntegracionApi\Helpers\Logger('search-product');
        $logger->info('Buscando producto por Código/ID', [
            'codigo' => $codigo,
            'params_adicionales' => $params_adicionales
        ]);
        
        // Construir parámetros para la búsqueda específica por ID
        $params = array_merge(['id_articulo' => $codigo], $params_adicionales);
        
        // Realizar la búsqueda usando el método general
        $result = $this->get_articulos($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Procesar el resultado para extraer el producto
        if (isset($result['Articulos']) && is_array($result['Articulos']) && !empty($result['Articulos'])) {
            $logger->info('Producto encontrado por Código/ID', [
                'cantidad' => count($result['Articulos']),
                'primer_id' => isset($result['Articulos'][0]['Id']) ? $result['Articulos'][0]['Id'] : 'no_id'
            ]);
            
            // Filtrar para asegurar una coincidencia exacta por ID
            $matches = array_filter($result['Articulos'], function($item) use ($codigo) {
                return isset($item['Id']) && 
                       (string)$item['Id'] === (string)$codigo;
            });
            
            if (!empty($matches)) {
                $logger->info('Coincidencia exacta por ID encontrada', [
                    'cantidad_exacta' => count($matches)
                ]);
                
                $normalized = [
                    'Articulos' => array_values($matches),
                    'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
                ];
                return $normalized;
            }
            
            return $result; // Devolver todos los resultados si no hay coincidencia exacta
        }
        
        $logger->info('No se encontraron productos por Código/ID', [
            'codigo' => $codigo
        ]);
        
        return [
            'Articulos' => [],
            'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
        ];
    }

    /**
     * Busca productos por nombre o descripción.
     * Este método está optimizado para búsquedas por texto en campos de nombre.
     *
     * @since 2.0.0
     * @param string $nombre El nombre o descripción a buscar
     * @param array $params_adicionales Parámetros adicionales opcionales
     * @return array|WP_Error Productos encontrados o error
     */
    public function searchProductByName($nombre, $params_adicionales = []) {
        if (empty($nombre)) {
            return new \WP_Error(
                'param_invalid',
                'Se requiere un nombre válido para la búsqueda',
                ['param' => 'nombre']
            );
        }
        
        $logger = new \MiIntegracionApi\Helpers\Logger('search-product');
        $logger->info('Buscando producto por Nombre', [
            'nombre' => $nombre,
            'params_adicionales' => $params_adicionales
        ]);
        
        // Construir parámetros para la búsqueda por nombre
        $params = array_merge(['buscar' => $nombre], $params_adicionales);
        
        // Realizar la búsqueda usando el método general
        $result = $this->get_articulos($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Procesar el resultado
        if (isset($result['Articulos']) && is_array($result['Articulos']) && !empty($result['Articulos'])) {
            $logger->info('Productos encontrados por Nombre', [
                'cantidad' => count($result['Articulos'])
            ]);
            
            // Ordenar resultados por relevancia (coincidencia de nombre)
            $productos = $result['Articulos'];
            usort($productos, function($a, $b) use ($nombre) {
                // Verificar si tienen nombre
                $a_nombre = isset($a['Nombre']) ? strtolower($a['Nombre']) : '';
                $b_nombre = isset($b['Nombre']) ? strtolower($b['Nombre']) : '';
                $nombre_lower = strtolower($nombre);
                
                // Priorizar coincidencias exactas
                $a_exact = $a_nombre === $nombre_lower;
                $b_exact = $b_nombre === $nombre_lower;
                
                if ($a_exact && !$b_exact) return -1;
                if (!$a_exact && $b_exact) return 1;
                
                // Luego priorizar las que empiezan con el término
                $a_starts = strpos($a_nombre, $nombre_lower) === 0;
                $b_starts = strpos($b_nombre, $nombre_lower) === 0;
                
                if ($a_starts && !$b_starts) return -1;
                if (!$a_starts && $b_starts) return 1;
                
                // Finalmente por longitud (nombres más cortos primero)
                return strlen($a_nombre) - strlen($b_nombre);
            });
            
            $result['Articulos'] = $productos;
            return $result;
        }
        
        $logger->info('No se encontraron productos por Nombre', [
            'nombre' => $nombre
        ]);
        
        return [
            'Articulos' => [],
            'InfoError' => ['Codigo' => 0, 'Descripcion' => '']
        ];
    }

    /**
     * Busca productos utilizando múltiples filtros combinados.
     * Este método permite búsquedas avanzadas con varios criterios.
     *
     * @since 2.0.0
     * @param array $filters Arreglo asociativo de filtros (campo => valor)
     * @param bool $usar_operador_and Si es true, todos los filtros deben coincidir (AND lógico)
     * @return array|WP_Error Productos encontrados o error
     */
    public function searchProductByFilters($filters = [], $usar_operador_and = true) {
        if (empty($filters)) {
            return new \WP_Error(
                'param_invalid',
                'Se requiere al menos un filtro válido para la búsqueda',
                ['param' => 'filters']
            );
        }
        
        $logger = new \MiIntegracionApi\Helpers\Logger('search-product');
        $logger->info('Buscando productos con múltiples filtros', [
            'filtros' => $filters,
            'operador_and' => $usar_operador_and
        ]);
        
        // Construir los parámetros para la API a partir de los filtros
        $params = $filters;
        
        // Realizar la búsqueda usando el método general
        $result = $this->get_articulos($params);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Si la API no permite filtrado combinado nativo, implementamos el filtrado local
        if ($usar_operador_and && isset($result['Articulos']) && is_array($result['Articulos'])) {
            $productos = $result['Articulos'];
            $filtered = [];
            
            foreach ($productos as $producto) {
                $match = true;
                
                // Verificar que cumpla con todos los criterios
                foreach ($filters as $campo => $valor) {
                    // Mapeo de algunos campos especiales
                    $campo_api = $campo;
                    if ($campo == 'sku') $campo_api = 'ReferenciaBarras';
                    if ($campo == 'codigo' || $campo == 'id') $campo_api = 'Id';
                    
                    // Verificar coincidencia
                    $tiene_campo = isset($producto[$campo_api]);
                    $coincide = $tiene_campo && ((string)$producto[$campo_api] === (string)$valor);
                    
                    if (!$coincide) {
                        $match = false;
                        break;
                    }
                }
                
                if ($match) {
                    $filtered[] = $producto;
                }
            }
            
            $logger->info('Filtrado local AND aplicado', [
                'total_original' => count($productos),
                'total_filtered' => count($filtered)
            ]);
            
            $result['Articulos'] = $filtered;
        }
        
        return $result;
    }

    /**
     * Método estratégico que implementa una búsqueda escalonada por prioridad.
     * Intenta diferentes métodos de búsqueda en orden de especificidad hasta encontrar resultados.
     *
     * @since 2.0.0
     * @param string $sku SKU o código del producto (opcional)
     * @param string $nombre Nombre del producto (opcional)
     * @param array $filtros_adicionales Filtros adicionales como categoría o fabricante
     * @return array|WP_Error Productos encontrados o error
     */
    public function searchProductEscalonado($sku = '', $nombre = '', $filtros_adicionales = []) {
        $logger = new \MiIntegracionApi\Helpers\Logger('search-escalated');
        $logger->info('Iniciando búsqueda escalonada', [
            'sku' => $sku,
            'nombre' => $nombre,
            'filtros_adicionales' => $filtros_adicionales
        ]);
        
        $result = null;
        
        // Paso 1: Si tenemos SKU/código, intentamos búsqueda exacta por este valor (alta prioridad)
        if (!empty($sku)) {
            $logger->info('Estrategia 1: Búsqueda por ReferenciaBarras (SKU)');
            
            // Intentar primero como código de barras (no numérico o SKU en WooCommerce)
            if (!is_numeric($sku)) {
                $result = $this->searchProductByReferenciaBarras($sku, $filtros_adicionales);
                
                // Si encontramos resultados exactos, retornar
                if (!is_wp_error($result) && isset($result['Articulos']) && !empty($result['Articulos'])) {
                    $logger->info('Éxito en estrategia 1: Producto encontrado por ReferenciaBarras');
                    return $result;
                }
            }
            
            // Si no tuvo éxito o es numérico, intentar como ID interno
            $logger->info('Estrategia 2: Búsqueda por Código/ID');
            $result = $this->searchProductByCodigo($sku, $filtros_adicionales);
            
            // Si encontramos resultados exactos, retornar
            if (!is_wp_error($result) && isset($result['Articulos']) && !empty($result['Articulos'])) {
                $logger->info('Éxito en estrategia 2: Producto encontrado por Código/ID');
                return $result;
            }
            
            // Si aún no hay resultados, intentar búsqueda general con el SKU como término
            $logger->info('Estrategia 3: Búsqueda general con SKU como término');
            $params_busqueda = array_merge(['buscar' => $sku], $filtros_adicionales);
            $result = $this->get_articulos($params_busqueda);
            
            if (!is_wp_error($result) && isset($result['Articulos']) && !empty($result['Articulos'])) {
                $logger->info('Éxito en estrategia 3: Productos encontrados con búsqueda general por SKU');
                return $result;
            }
        }
        
        // Paso 2: Si tenemos nombre o no se encontró nada por SKU, buscar por nombre
        if (!empty($nombre)) {
            $logger->info('Estrategia 4: Búsqueda por Nombre');
            $result = $this->searchProductByName($nombre, $filtros_adicionales);
            
            if (!is_wp_error($result) && isset($result['Articulos']) && !empty($result['Articulos'])) {
                $logger->info('Éxito en estrategia 4: Productos encontrados por Nombre');
                return $result;
            }
        }
        
        // Paso 3: Si aún no hay resultados pero tenemos filtros adicionales, intentar solo con ellos
        if (!empty($filtros_adicionales)) {
            $logger->info('Estrategia 5: Búsqueda solo con filtros adicionales');
            $result = $this->get_articulos($filtros_adicionales);
            
            if (!is_wp_error($result) && isset($result['Articulos']) && !empty($result['Articulos'])) {
                $logger->info('Éxito en estrategia 5: Productos encontrados solo con filtros adicionales');
                return $result;
            }
        }
        
        // Si llegamos aquí es porque no se encontraron resultados en ninguna estrategia
        $logger->warning('No se encontraron productos con ninguna estrategia de búsqueda');
        
        // Si ya tenemos un resultado de algún intento anterior, devolverlo aunque esté vacío
        if ($result !== null) {
            return $result;
        }
        
        // Devolver estructura vacía estándar
        return [
            'Articulos' => [],
            'InfoError' => ['Codigo' => 0, 'Descripcion' => 'No se encontraron productos con los criterios proporcionados']
        ];
    }
}
