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
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Cache\HTTP_Cache_Manager;
use MiIntegracionApi\Core\DataValidator;

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
     * @var Logger Instancia del logger
     */
    private Logger $logger;

    /**
     * @var RetryManager Instancia del gestor de reintentos
     */
    private RetryManager $retry_manager;

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
     * Timestamp de la última conexión exitosa
     * @var int|null
     */
    private ?int $last_connection_time = null;

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
     * @var int Timeout de las solicitudes en segundos
     */
    private int $timeout;

    /**
     * Constructor de la clase
     *
     * @param Logger $logger Instancia del logger
     * @param int $max_retries Número máximo de reintentos
     * @param int $retry_delay Tiempo de espera entre reintentos en segundos
     * @param int $timeout Timeout de las solicitudes en segundos
     */
    public function __construct(Logger $logger, int $max_retries = 3, int $retry_delay = 2, int $timeout = 30) {
        $this->logger = $logger;
        $this->timeout = $timeout;
        $this->retry_manager = new RetryManager();
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
        
        // Verificar que la URL base tenga un host válido
        if (empty($base) || !parse_url($base, PHP_URL_HOST)) {
            // Si no hay URL base o no tiene host, usar la URL por defecto de la colección Postman
            $default_url = 'http://x.verial.org:8000';
            $this->logger->warning('URL base inválida. Usando URL por defecto', [
                'base_original' => $base,
                'base_default' => $default_url
            ]);
            $base = $default_url . '/WcfServiceLibraryVerial';
        } elseif (parse_url($base, PHP_URL_PATH) === null || parse_url($base, PHP_URL_PATH) === '') {
            // Si la URL base no tiene path, añadir /WcfServiceLibraryVerial
            $base = rtrim($base, '/') . '/WcfServiceLibraryVerial';
        }
        
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
        $url = preg_replace('#(?<!:)//+#', '/', $url);
        
        // Validar que la URL resultante sea válida
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $this->logger->error('URL construida inválida', [
                'base' => $base,
                'endpoint' => $endpoint,
                'resultado' => $url
            ]);
            
            // Intentar reconstruir una URL completa y válida
            if (!parse_url($base, PHP_URL_SCHEME)) {
                $base = 'http://' . ltrim($base, '/');
            }
            
            // Asegurar que haya un host
            if (!parse_url($base, PHP_URL_HOST)) {
                $this->logger->error('No se pudo construir una URL válida - sin host');
                // Usar la URL por defecto como último recurso
                $base = 'http://x.verial.org:8000/WcfServiceLibraryVerial';
            }
            
            $url = rtrim($base, '/') . '/' . $endpoint;
            $this->logger->info('Se reconstruyó la URL como: ' . $url);
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
     * Realiza una solicitud GET a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function get(string $endpoint, array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $params, $options) {
            return $this->makeRequest('GET', $endpoint, [], $params, $options);
        }, 'GET_' . $endpoint);
    }

    /**
     * Realiza una solicitud POST a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function post(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('POST', $endpoint, $data, $params, $options);
        }, 'POST_' . $endpoint);
    }

    /**
     * Realiza una solicitud PUT a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function put(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('PUT', $endpoint, $data, $params, $options);
        }, 'PUT_' . $endpoint);
    }

    /**
     * Realiza una solicitud DELETE a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    public function delete(string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($endpoint, $data, $params, $options) {
            return $this->makeRequest('DELETE', $endpoint, $data, $params, $options);
        }, 'DELETE_' . $endpoint);
    }

    /**
     * Configura las opciones de retry para el ApiConnector
     *
     * @param array $retry_config Configuración de retry
     * @return self
     */
    public function setRetryConfig(array $retry_config): self {
        $this->retry_manager = new RetryManager();
        return $this;
    }

    /**
     * Obtiene la configuración actual de retry
     *
     * @return array Configuración de retry
     */
    public function getRetryConfig(): array {
        // Método getStats no existe en RetryManager, devolvemos array vacío por ahora
        return [];
    }
    
    /**
     * Realiza una solicitud HTTP a la API
     *
     * @param string $method Método HTTP (GET, POST, PUT, DELETE)
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar (cuerpo)
     * @param array $params Parámetros de consulta (URL)
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    private function makeRequest(string $method, string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        // Construir la URL
        $url = $this->build_api_url($endpoint);
        
        // Añadir parámetros a la URL
        if (!empty($params)) {
            $url = add_query_arg($params, $url);
        }
        
        // Añadir sesionwcf si no está incluido en los parámetros
        if (!isset($params['x']) && !isset($params['sesionwcf'])) {
            $url = add_query_arg('x', $this->sesionwcf, $url);
        }
        
        // Configurar opciones de la solicitud
        $args = [
            'method' => $method,
            'timeout' => $options['timeout'] ?? $this->getTimeoutForMethod($method),
            'redirection' => $options['redirection'] ?? 5,
            'httpversion' => $options['httpversion'] ?? '1.1',
            'blocking' => $options['blocking'] ?? true,
            'headers' => $options['headers'] ?? [],
            'sslverify' => $options['sslverify'] ?? false,
        ];
        
        // Añadir datos al cuerpo para métodos que lo soportan
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            // Guardar el JSON generado para depuración con opciones para manejo adecuado de UTF-8
            $json_body = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            
            // Verificar si hubo error en la codificación JSON
            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_error = json_last_error_msg();
                $this->logger->error('Error al codificar datos en JSON', [
                    'error' => $json_error,
                    'data_sample' => print_r(array_slice($data, 0, 3, true), true)
                ]);
            }
            
            $this->logger->debug('JSON generado para API Verial', [
                'endpoint' => $endpoint,
                'json' => $json_body
            ]);
            
            $args['body'] = $json_body;
            
            // Asegurar que estén presentes todos los encabezados esenciales
            if (!isset($args['headers']['Content-Type'])) {
                $args['headers']['Content-Type'] = 'application/json';
            }
            if (!isset($args['headers']['Accept'])) {
                $args['headers']['Accept'] = 'application/json';
            }
            if (!isset($args['headers']['User-Agent'])) {
                $args['headers']['User-Agent'] = 'MiIntegracionAPI/1.0';
            }
        }
        
        // Realizar la solicitud usando curl
        return $this->makeRequestWithCurl($url, $args);
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
        return $this->retry_manager->getStats();
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
        $this->retry_manager->resetStats();
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
     * Realiza una solicitud HTTP con reintentos
     *
     * @param string $method Método HTTP
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos para enviar
     * @param array $params Parámetros de consulta
     * @param array $options Opciones adicionales
     * @return mixed Resultado de la solicitud
     */
    private function makeRequestWithRetry(string $method, string $endpoint, array $data = [], array $params = [], array $options = []): mixed {
        return $this->retry_manager->executeWithRetry(function() use ($method, $endpoint, $data, $params, $options) {
            return $this->makeRequest($method, $endpoint, $data, $params, $options);
        }, $method . '_' . $endpoint);
    }

    /**
     * Realiza una solicitud HTTP usando cURL
     *
     * @param string $url URL de la solicitud
     * @param array $args Argumentos de la solicitud
     * @return mixed Resultado de la solicitud
     */
    private function makeRequestWithCurl(string $url, array $args): mixed {
        $ch = curl_init();
        
        // Configurar opciones de cURL
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $args['sslverify']);
        
        if (!empty($args['sslcertificates'])) {
            curl_setopt($ch, CURLOPT_CAINFO, $args['sslcertificates']);
        }
        
        // Configurar método HTTP
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $args['method']);
        
        // Configurar headers
        if (!empty($args['headers'])) {
            $headers = [];
            foreach ($args['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        // Configurar datos del cuerpo
        if (!empty($args['body'])) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $args['body']);
        }
        
        // Realizar la solicitud
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Verificar si hay error
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return new \WP_Error('curl_error', $error);
        }
        
        curl_close($ch);
        
        // Si es exitoso (2xx), devolver respuesta decodificada
        if ($http_code >= 200 && $http_code < 300) {
            $decoded_data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded_data;
            } else {
                // Registrar error de decodificación JSON para diagnóstico
                $json_error = json_last_error_msg();
                $this->logger->error('Error al decodificar JSON de respuesta', [
                    'error' => $json_error,
                    'response_sample' => substr($response, 0, 500) . (strlen($response) > 500 ? '...' : '')
                ]);
                
                return [
                    'status_code' => $http_code,
                    'body' => $response,
                    'json_error' => $json_error
                ];
            }
        }
        
        // Si es un error, devolver WP_Error
        return new \WP_Error(
            'http_error',
            "HTTP Error {$http_code}",
            [
                'status' => $http_code,
                'body' => $response
            ]
        );
    }

    /**
     * Wrapper: Obtiene artículos de Verial.
     * @param array $params Parámetros opcionales (inicio, fin, fecha, etc.)
     * @return array|WP_Error
     */
    public function get_articulos($params = array()) {
        // Soporta paginación: inicio, fin, fecha
        $endpoint = 'GetArticulosWS';
        
        // Validar parámetros
        if (!is_array($params)) {
            $params = array(); // Asegurar que params sea un array
            $this->logger->log('ATENCIÓN: get_articulos recibió parámetros no válidos. Tipo: ' . gettype($params), 'warning');
        }
        
        // Manejar diferentes formas de buscar por SKU
        if (isset($params['sku']) && !isset($params['referencia']) && !isset($params['referenciabarras'])) {
            // Si nos pasan 'sku', lo mapeamos a los parámetros que Verial entiende
            $params['referenciabarras'] = $params['sku'];
            // También intentamos buscar en Referencia por compatibilidad
            $params['referencia'] = $params['sku'];
            unset($params['sku']); // Ya no necesitamos este parámetro
            $this->logger->log("Convertido parámetro 'sku' a 'referenciabarras' y 'referencia' para compatibilidad con API Verial", 'debug');
        }
        
        // Registrar llamada a la API
        if (isset($this->logger)) {
            $this->logger->log("Llamando a get_articulos con endpoint={$endpoint} y parámetros: " . print_r($params, true), 'debug');
        }
        
        // Opciones avanzadas con encabezados HTTP esenciales
        $options = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'MiIntegracionAPI/1.0',
            ],
            'timeout' => 45, // Timeout extendido para catálogos grandes
        ];
        
        // CORRECCIÓN: El endpoint requiere POST con body JSON, no GET con parámetros en URL
        $response = $this->post($endpoint, $params, [], $options);
        
        // Registrar respuesta para depuración
        if (isset($this->logger)) {
            if (is_wp_error($response)) {
                $this->logger->log("Error en get_articulos: " . $response->get_error_message(), 'error');
            } else {
                $count = is_array($response) ? count($response) : 0;
                $this->logger->log("Respuesta de get_articulos recibida. Tipo: " . gettype($response) . ", Elementos: {$count}", 'debug');
            }
        }
        
        return $response;
    }

    /**
     * Wrapper: Obtiene artículos por rango con configuración optimizada para evitar timeouts
     * Método especializado para manejar rangos problemáticos
     *
     * @param array $params Parámetros para la solicitud, debe incluir 'inicio' y 'fin'
     * @return mixed Resultado de la solicitud
     */
    public function get_articulos_rango(array $params) {
        // Validar parámetros mínimos requeridos
        if (!isset($params['inicio']) || !isset($params['fin'])) {
            return new \WP_Error('invalid_parameters', 'Los parámetros inicio y fin son obligatorios.');
        }
        
        // Verificar y reiniciar la conexión si es necesario
        $this->check_and_restart_connection();
        
        // Actualizar timestamp de conexión
        $this->last_connection_time = time();
        
        // Registrar solicitud por rango
        if (method_exists($this, 'logger') && is_callable([$this->logger, 'info'])) {
            $this->logger->info('Solicitando rango de artículos', [
                'inicio' => $params['inicio'],
                'fin' => $params['fin'],
                'batch_size' => $params['fin'] - $params['inicio'] + 1,
                'has_filters' => !empty($params['fecha']),
                'memory_usage' => round(memory_get_usage() / 1048576, 2) . ' MB',
                'peak_memory' => round(memory_get_peak_usage() / 1048576, 2) . ' MB'
            ]);
        }
        
        // Aumentar tiempo de ejecución de PHP para esta solicitud
        if (!ini_get('safe_mode')) {
            @set_time_limit(120); // 2 minutos
        }
        
        // Opciones optimizadas para rangos problemáticos
        $options = [
            'timeout' => 90,                 // Timeout extendido para rangos problemáticos (90 segundos)
            'diagnostics' => true,           // Habilitar diagnósticos detallados
            'trace_request' => true,         // Rastreo completo de la solicitud
            'blocking' => true,              // Asegurarse que la solicitud sea bloqueante
            'sslverify' => apply_filters('mi_integracion_api_sslverify', true), // Permitir deshabilitar SSL en entornos problemáticos
            'retry_transient_errors' => true, // Reintentar errores transitorios
            'headers' => [                   // Encabezados HTTP esenciales
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'MiIntegracionAPI/1.0',
            ],
            'wp_args' => [
                'timeout' => 60,             // Mismo timeout en argumentos de WordPress
                'httpversion' => '1.1',      // HTTP 1.1 para mejor compatibilidad
                'blocking' => true           // Esperar a que se complete la solicitud
            ]
        ];
        
        // Usar método POST en lugar de GET para GetArticulosWS
        return $this->post('GetArticulosWS', $params, [], $options);
    }

    /**
     * Wrapper: Obtiene categorías de Verial.
     * @param array $params Parámetros opcionales
     * @return array|WP_Error
     */
    public function get_categorias($params = array()) {
        $endpoint = 'GetCategoriasWS';
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene clientes de Verial.
     * @param array $params Parámetros opcionales
     * @return array|WP_Error
     */
    public function get_clientes($params = array()) {
        $endpoint = 'GetClientesWS';
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene pedidos de Verial.
     * @param array $params Parámetros opcionales
     * @return array|WP_Error
     */
    public function get_pedidos($params = array()) {
        $endpoint = 'GetPedidosWS';
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene el stock de artículos de Verial.
     * @param array|int $params Puede ser array de filtros o id_articulo (int)
     * @return array|WP_Error
     */
    public function get_stock_articulos($params = array()) {
        $endpoint = 'GetStockArticulosWS';
        // Permite pasar id_articulo directamente
        if (is_int($params)) {
            $params = ['id_articulo' => $params];
        }
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene condiciones de tarifa de un artículo.
     * @param int $id_articulo
     * @param int $id_cliente
     * @param int|null $id_tarifa
     * @param string|null $fecha
     * @return array|WP_Error
     */
    public function get_condiciones_tarifa($id_articulo, $id_cliente = 0, $id_tarifa = null, $fecha = null) {
        $endpoint = 'GetCondicionesTarifaWS';
        $params = [
            'id_articulo' => $id_articulo,
            'id_cliente' => $id_cliente,
        ];
        if (!is_null($id_tarifa)) {
            $params['id_tarifa'] = $id_tarifa;
        }
        if (!is_null($fecha)) {
            $params['fecha'] = $fecha;
        }
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene imágenes de artículos.
     * @param array $params Parámetros opcionales (id_articulo, numpixelsladomenor, etc.)
     * @return array|WP_Error
     */
    public function get_imagenes_articulos($params = array()) {
        $endpoint = 'GetImagenesArticulosWS';
        return $this->get($endpoint, $params);
    }

    /**
     * Wrapper: Obtiene fabricantes de Verial.
     * @param array $params Parámetros opcionales
     * @return array|WP_Error
     */
    public function get_fabricantes($params = array()) {
        $endpoint = 'GetFabricantesWS';
        return $this->get($endpoint, $params);
    }

    /**
     * Prueba la conectividad con la API de Verial usando un método simple (GetPaisesWS).
     * @return true|string Mensaje de error si falla, true si conecta correctamente.
     */
    public function test_connectivity() {
        try {
            $this->logger->info('[TEST] Iniciando prueba de conectividad completa...', [
                'api_url' => $this->api_url,
                'sesionwcf' => $this->sesionwcf
            ]);
            
            // PASO 1: Verificar que la URL base es accesible
            $this->logger->info('[TEST] Verificando accesibilidad de URL base...');
            $response = $this->doRequestWithRetry('GET', $this->api_url, [
                'timeout' => 10, // Un timeout inicial más bajo para la accesibilidad
                'sslverify' => false, // No es necesario verificar SSL en este punto
                'headers' => [
                    'User-Agent' => 'Mi-Integracion-API/1.0'
                ],
                'retries' => 0, // No reintentar esta solicitud, solo verificar accesibilidad
            ]);
            
            if (is_wp_error($response)) {
                $error_msg = 'No se puede acceder a la URL base: ' . $response->get_error_message();
                $this->logger->error('[TEST] Error de accesibilidad: ' . $error_msg);
                return $error_msg;
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code < 200 || $response_code >= 400) {
                $error_msg = 'URL base devuelve código de error HTTP: ' . $response_code;
                $this->logger->error('[TEST] Error HTTP: ' . $error_msg);
                return $error_msg;
            }
            
            $this->logger->info('[TEST] ✓ URL base accesible (HTTP ' . $response_code . ')');
            
            // PASO 2: Verificar que el número de sesión es válido con la API
            $this->logger->info('[TEST] Verificando número de sesión con GetPaisesWS...');
            $test_url = $this->build_api_url('GetPaisesWS') . '?x=' . urlencode($this->sesionwcf);
            $this->logger->info('[TEST] URL de prueba: ' . $test_url);
            
            $api_response = $this->get('GetPaisesWS');
            
            // Log de respuesta para diagnóstico
            $this->logger->info('[TEST] Respuesta de GetPaisesWS', [
                'type' => gettype($api_response),
                'is_wp_error' => is_wp_error($api_response),
                'response' => $api_response
            ]);
            
            if (is_wp_error($api_response)) {
                $error_msg = 'Error en la conexión de prueba: ' . $api_response->get_error_message();
                $this->logger->error('[TEST] Error WP_Error: ' . $error_msg);
                return $error_msg;
            }
            
            // PASO 3: Validar respuesta de la API
            if (isset($api_response['InfoError'])) {
                $info_error = $api_response['InfoError'];
                
                if (isset($info_error['Codigo']) && $info_error['Codigo'] == 0) {
                    $this->logger->info('[TEST] ✓ Conectividad exitosa con Verial');
                    return true;
                } else {
                    $error_msg = 'Error de API: ' . ($info_error['Descripcion'] ?? 'Error desconocido');
                    $this->logger->error('[TEST] Error InfoError: ' . $error_msg, $info_error);
                    
                    // Proporcionar contexto adicional para errores comunes
                    if (isset($info_error['Codigo'])) {
                        switch ($info_error['Codigo']) {
                            case -1:
                                $error_msg .= ' (Posible número de sesión inválido)';
                                break;
                            case -2:
                                $error_msg .= ' (Posible error de autenticación)';
                                break;
                            case -3:
                                $error_msg .= ' (Posible servicio no disponible)';
                                break;
                        }
                    }
                    
                    return $error_msg;
                }
            } else {
                $error_msg = 'Respuesta inesperada de la API de Verial (sin InfoError)';
                $this->logger->error('[TEST] ' . $error_msg, $api_response);
                return $error_msg;
            }
            
        } catch (\Throwable $e) {
            $error_msg = 'Excepción durante prueba de conectividad: ' . $e->getMessage();
            $this->logger->error('[TEST] ' . $error_msg, [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $error_msg;
        }
    }

    /**
     * Lee la configuración desde las opciones de WordPress, compatible con ambos plugins.
     */
    private function load_settings() {
        $ajustes = get_option('mi_integracion_api_ajustes', array());
        $this->api_url = $ajustes['mia_url_base'] ?? '';
        $this->sesionwcf = $ajustes['mia_numero_sesion'] ?? '';

        // Si la URL base o el número de sesión están vacíos, lanzar una excepción
        if (empty($this->api_url) || empty($this->sesionwcf)) {
            throw new \Exception('La URL base o el número de sesión no están configurados correctamente. Por favor, revisa la configuración del plugin.');
        }
    }

    /**
     * Valida la configuración actual del conector
     * @return array{is_valid: bool, errors: string[], config: array}
     */
    public function validate_configuration(): array {
        $errors = [];
        $config = [
            'api_url' => $this->api_url,
            'sesionwcf' => $this->sesionwcf,
            'source' => $this->config_source
        ];
        
        // Validar URL base
        if (empty($this->api_url)) {
            $errors[] = 'URL base de Verial no configurada';
        } elseif (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            $errors[] = 'URL base de Verial tiene formato inválido: ' . $this->api_url;
        }
        
        // Validar número de sesión usando método dedicado
        $session_validation = self::validate_session_number($this->sesionwcf);
        if (!$session_validation['is_valid']) {
            $errors[] = $session_validation['error'];
        }
        
        // Validar que la fuente de configuración no sea de error
        if ($this->config_source === 'error_fallback') {
            $errors[] = 'Configuración cargada desde fallback de error';
        }
        
        return [
            'is_valid' => empty($errors),
            'errors' => $errors,
            'config' => $config
        ];
    }

    /**
     * Valida si un número de sesión es válido para Verial
     * 
     * @param mixed $sesionwcf Número de sesión a validar
     * @return array Array con 'is_valid' (bool) y 'error' (string)
     */
    public static function validate_session_number($sesionwcf): array {
        // Verificar que no esté vacío
        if ($sesionwcf === null || $sesionwcf === '') {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión no puede estar vacío'
            ];
        }
        
        // Verificar que sea numérico
        if (!is_numeric($sesionwcf)) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser numérico, recibido: ' . gettype($sesionwcf)
            ];
        }
        
        // Convertir a entero para validaciones adicionales
        $sesion_int = (int)$sesionwcf;
        
        // Verificar rango válido
        if ($sesion_int <= 0) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser mayor que 0, recibido: ' . $sesion_int
            ];
        }
        
        if ($sesion_int > 9999) {
            return [
                'is_valid' => false,
                'error' => 'El número de sesión debe ser menor que 10000, recibido: ' . $sesion_int
            ];
        }
        
        // Si llegamos aquí, es válido
        return [
            'is_valid' => true,
            'error' => ''
        ];
    }

    /**
     * Obtiene el número de sesión actual
     * 
     * @return int
     */
    public function get_session_number(): int {
        return $this->sesionwcf;
    }

    /**
     * Establece un nuevo número de sesión (con validación)
     * 
     * @param mixed $sesionwcf Nuevo número de sesión
     * @throws \Exception Si el número de sesión es inválido
     */
    public function set_session_number($sesionwcf): void {
        $validation = self::validate_session_number($sesionwcf);
        
        if (!$validation['is_valid']) {
            throw new \Exception('Número de sesión inválido: ' . $validation['error']);
        }
        
        $this->sesionwcf = (int)$sesionwcf;
        $this->logger->info('Número de sesión actualizado', ['new_session' => $this->sesionwcf]);
    }

    /**
     * Establece la URL base de la API
     * 
     * @param string $url URL base de la API
     * @return void
     */
    public function set_api_url(string $url): void {
        if (!empty($url)) {
            $this->api_url = $url;
            $this->logger->debug("URL de API configurada: " . $url);
        }
    }

    /**
     * Establece el número de sesión para Verial
     * 
     * @param string|int $sesion Número de sesión
     * @return void
     */
    public function set_sesion_wcf($sesion): void {
        if (!empty($sesion)) {
            $this->sesionwcf = intval($sesion);
            $this->logger->debug("Número de sesión configurado: " . $this->sesionwcf);
        }
    }

    /**
     * Recarga la configuración desde WordPress
     * @throws \Exception Si la configuración es inválida
     */
    public function reload_configuration(): void {
        $this->load_configuration([]);
    }

    /**
     * Método de prueba para demostrar el nuevo sistema de retry
     * 
     * @param string $test_endpoint Endpoint para probar (default: 'test-connection')
     * @return array Resultados de la prueba incluyendo estadísticas de retry
     */
    public function testRetrySystem(string $test_endpoint = 'test-connection'): array {
        $this->logger->info('[TEST] Iniciando prueba del sistema de retry robusto');
        
        $test_results = [];
        
        // Prueba 1: Solicitud GET con retry robusto
        $this->logger->info('[TEST] Prueba 1: GET con retry robusto');
        $start_time = microtime(true);
        $get_result = $this->makeRequestWithRetry('GET', $test_endpoint, [], ['test' => 'retry_system']);
        $get_time = microtime(true) - $start_time;
        
        $test_results['get_test'] = [
            'success' => !is_wp_error($get_result),
            'execution_time' => round($get_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => is_wp_error($get_result) ? $get_result->get_error_message() : 'Success'
        ];
        
        // Prueba 2: Solicitud POST con retry robusto
        $this->logger->info('[TEST] Prueba 2: POST con retry robusto');
        $start_time = microtime(true);
        $post_result = $this->makeRequestWithRetry('POST', $test_endpoint, ['test_data' => 'retry_system_post']);
        $post_time = microtime(true) - $start_time;
        
        $test_results['post_test'] = [
            'success' => !is_wp_error($post_result),
            'execution_time' => round($post_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => is_wp_error($post_result) ? $post_result->get_error_message() : 'Success'
        ];
        
        // Prueba 3: Comparación con método legacy
        $this->logger->info('[TEST] Prueba 3: Comparación con método legacy');
        $start_time = microtime(true);
        $legacy_result = $this->makeRequest('GET', $test_endpoint);
        $legacy_time = microtime(true) - $start_time;
        
        $test_results['legacy_comparison'] = [
            'success' => !is_wp_error($legacy_result),
            'execution_time' => round($legacy_time, 3),
            'retry_count' => $this->getLastRetryCount(),
            'result' => is_wp_error($legacy_result) ? $legacy_result->get_error_message() : 'Success'
        ];
        
        // Resumen de la prueba
        $test_results['summary'] = [
            'total_tests' => 3,
            'total_time' => round($get_time + $post_time + $legacy_time, 3),
            'retry_config' => $this->getRetryConfig(),
            'api_url' => $this->getApiUrl(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        $this->logger->info('[TEST] Prueba del sistema de retry completada', $test_results['summary']);
        
        return $test_results;
    }

    /**
     * Realiza un diagnóstico detallado de la conexión a la API
     * 
     * @return array|WP_Error Resultado del diagnóstico
     */
    public function diagnosticarConexion() {
        $resultado = [
            'estado' => 'error',
            'mensaje' => '',
            'detalles' => [],
            'sugerencias' => []
        ];
        
        $url_base = $this->get_api_base_url();
        $sesion = $this->getSesionWcf();
        
        if (empty($url_base)) {
            $resultado['mensaje'] = 'No se ha configurado la URL base de la API de Verial';
            $resultado['sugerencias'][] = 'Configure la URL base en Ajustes > Mi Integración API';
            return $resultado;
        }
        
        if (empty($sesion)) {
            $resultado['mensaje'] = 'No se ha configurado el número de sesión (sesionwcf)';
            $resultado['sugerencias'][] = 'Configure el número de sesión en Ajustes > Mi Integración API';
            return $resultado;
        }
        
        $resultado['detalles']['url_base'] = $url_base;
        $resultado['detalles']['sesion'] = $sesion;
        
        // 2. Intentar conexión básica a la URL base
        $test_url = rtrim($url_base, '/') . '/testConexion?x=' . $sesion;
        
        try {
            $response = wp_remote_get($test_url, [
                'timeout' => 15,
                'sslverify' => false
            ]);
            
            if (is_wp_error($response)) {
                $resultado['mensaje'] = 'Error al conectar con la API: ' . $response->get_error_message();
                $resultado['sugerencias'][] = 'Verifique que la URL base sea correcta';
                $resultado['sugerencias'][] = 'Compruebe que su servidor puede conectarse a Internet';
                $resultado['detalles']['error'] = $response->get_error_message();
                return $resultado;
            }
            
            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            $resultado['detalles']['status_code'] = $status_code;
            $resultado['detalles']['response_size'] = strlen($body);
            
            if ($status_code === 404) {
                $resultado['mensaje'] = 'La URL de la API no existe (404)';
                $resultado['sugerencias'][] = 'Verifique que la URL base sea correcta';
                $resultado['sugerencias'][] = 'Confirme que la API de Verial está accesible desde su servidor';
                return $resultado;
            }
            
            if ($status_code >= 400) {
                $resultado['mensaje'] = "Error HTTP $status_code al conectar con la API";
                $resultado['sugerencias'][] = 'Verifique que la URL y sesión sean correctas';
                $resultado['detalles']['response'] = substr($body, 0, 500); // Primeros 500 caracteres
                return $resultado;
            }
            
            // 3. Intentar la conexión parecida a los endpoints normales
            $endpoint = 'test';
            $response_api = $this->get($endpoint);
            
            if (is_wp_error($response_api)) {
                $resultado['mensaje'] = 'Error al probar un endpoint de API: ' . $response_api->get_error_message();
                $resultado['detalles']['endpoint_error'] = $response_api->get_error_message();
                $resultado['sugerencias'][] = 'Verifique que el número de sesión sea correcto';
                return $resultado;
            }
            
            // Si llegamos aquí, la conexión parece funcionar
            $resultado['estado'] = 'success';
            $resultado['mensaje'] = 'Conexión establecida correctamente';
            
            return $resultado;
        } catch (\Exception $e) {
            $resultado['mensaje'] = 'Error inesperado al diagnosticar conexión: ' . $e->getMessage();
            $resultado['detalles']['exception'] = $e->getMessage();
            $resultado['detalles']['trace'] = $e->getTraceAsString();
            return $resultado;
        }
    }

    /**
     * Devuelve información de la última solicitud para diagnósticos
     * 
     * @return array Información de la última solicitud
     */
    public function get_last_request_info() {
        $info = [
            'api_url' => $this->api_url ?? 'no configurada',
            'sesionwcf' => $this->sesionwcf ?? 'no configurada',
            'config_source' => $this->config_source ?? 'desconocido',
            'cache_enabled' => $this->cache_enabled ?? false,
            'cache_stats' => $this->cache_stats ?? [],
            'ssl_settings' => [
                'ssl_enabled' => isset($this->ssl_verify) ? ($this->ssl_verify ? 'habilitado' : 'deshabilitado') : 'no configurado',
                'cert_path' => $this->cert_path ?? 'no configurado',
                'ca_path' => $this->ca_path ?? 'no configurado',
            ],
            'last_error' => $this->last_error ?? 'ninguno'
        ];
        
        return $info;
    }

    /**
     * Inicializa la configuración de la API
     *
     * @param array $config Configuración de la API
     * @return bool
     */
    public function init_config($config) {
        if (!DataValidator::validate_api_config($config)) {
            Logger::error('Configuración de API inválida');
            return false;
        }

        $this->api_key = $config['api_key'];
        $this->api_url = $config['api_url'];
        return true;
    }

    /**
     * Realiza una petición a la API
     *
     * @param string $endpoint Endpoint de la API
     * @param array $data Datos a enviar
     * @param string $method Método HTTP
     * @return array|false
     */
    public function make_request($endpoint, $data = [], $method = 'GET') {
        if (!DataValidator::validate_api_config(['api_key' => $this->api_key, 'api_url' => $this->api_url])) {
            Logger::error('Configuración de API inválida antes de hacer la petición');
            return false;
        }

        // ... existing code ...
    }

    /**
     * Sincroniza un producto
     *
     * @param array $product_data Datos del producto
     * @return bool
     */
    public function sync_product($product_data) {
        if (!DataValidator::validate_product_data($product_data)) {
            Logger::error('Datos de producto inválidos');
            return false;
        }

        // ... existing code ...
    }

    /**
     * Sincroniza un pedido
     *
     * @param array $order_data Datos del pedido
     * @return bool
     */
    public function sync_order($order_data) {
        if (!DataValidator::validate_order_data($order_data)) {
            Logger::error('Datos de pedido inválidos');
            return false;
        }

        // ... existing code ...
    }

    /**
     * Sincroniza un cliente
     *
     * @param array $customer_data Datos del cliente
     * @return bool
     */
    public function sync_customer($customer_data) {
        if (!DataValidator::validate_customer_data($customer_data)) {
            Logger::error('Datos de cliente inválidos');
            return false;
        }

        // ... existing code ...
    }

    /**
     * Sincroniza una categoría
     *
     * @param array $category_data Datos de la categoría
     * @return bool
     */
    public function sync_category($category_data) {
        if (!DataValidator::validate_category_data($category_data)) {
            Logger::error('Datos de categoría inválidos');
            return false;
        }

        // ... existing code ...
    }

    /**
     * Verifica si la conexión con la API está activa y responde correctamente
     * Si la conexión está inactiva, intenta reiniciarla
     *
     * @return bool True si la conexión está activa o se reinició correctamente
     */
    public function check_and_restart_connection() {
        if (!$this->is_connected()) {
            if ($this->logger) {
                $this->logger->info('La conexión con la API no está activa, intentando reiniciarla');
            }
            
            // Forzar reconexión
            $this->session_number = null;
            $this->login_response = null;
            
            // Intentar conectar nuevamente
            $result = $this->init_api_connection();
            
            if ($result) {
                // Actualizar timestamp de última conexión exitosa
                $this->last_connection_time = time();
                
                if ($this->logger) {
                    $this->logger->info('Conexión reiniciada con éxito', [
                        'timestamp' => date('Y-m-d H:i:s', $this->last_connection_time),
                        'session' => $this->session_number ?? 'No disponible'
                    ]);
                }
            }
            
            return $result;
        }
        
        // La conexión está activa, actualizar timestamp
        $this->last_connection_time = time();
        return true;
    }
    
    /**
     * Inicializa la conexión con la API
     * 
     * @return bool True si la conexión se estableció correctamente
     */
    private function init_api_connection() {
        // Por ahora solo actualizamos el timestamp
        // En el futuro podríamos implementar una verdadera autenticación
        $this->last_connection_time = time();
        
        if ($this->sesionwcf <= 0) {
            $this->sesionwcf = 18; // Valor predeterminado si no hay sesión
        }
        
        return true;
    }
    
    /**
     * Determina si la conexión está activa según los datos de sesión
     *
     * @return bool
     */
    public function is_connected() {
        // Si no hay número de sesión, definitivamente no está conectado
        if (empty($this->session_number)) {
            return false;
        }
        
        // Si la última conexión fue hace más de 20 minutos, considerarla caducada
        if (!empty($this->last_connection_time) && 
            time() - $this->last_connection_time > 1200) {
            return false;
        }
        
        return true;
    }
}
