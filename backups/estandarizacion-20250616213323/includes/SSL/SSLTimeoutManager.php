<?php
/**
 * Clase para gestionar timeouts y reintentos en conexiones SSL
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
 * Gestiona timeouts y reintentos en conexiones SSL
 *
 * Esta clase proporciona funcionalidades para manejar timeouts,
 * reintentos y backoff exponencial en conexiones SSL.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/SSL
 */
class SSLTimeoutManager {
    /**
     * Instancia del logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * Configuración de timeouts
     *
     * @var array
     */
    private $config = [
        'default_timeout' => 30,         // Timeout por defecto en segundos
        'connect_timeout' => 10,         // Timeout de conexión
        'ssl_handshake_timeout' => 15,   // Timeout específico SSL handshake
        'max_retries' => 3,              // Número máximo de reintentos
        'backoff_factor' => 1.5,         // Factor para backoff exponencial
        'jitter' => 0.2,                 // Factor de variación para evitar tormentas de conexiones
        'timeout_hosts' => [],           // Hosts con tiempos de espera específicos
        'method_timeouts' => [           // Timeouts específicos por método HTTP
            'GET' => 30,
            'POST' => 45,
            'PUT' => 45,
            'DELETE' => 30,
            'HEAD' => 10,
            'OPTIONS' => 10,
        ],
        'error_policies' => [            // Políticas de reintentos por tipo de error
            'connection_timeout' => ['max_retries' => 5, 'backoff_factor' => 2.0],
            'ssl_error' => ['max_retries' => 4, 'backoff_factor' => 1.8],
            'server_error' => ['max_retries' => 3, 'backoff_factor' => 1.5],
            'client_error' => ['max_retries' => 1, 'backoff_factor' => 1.0],
        ],
        'latency_monitoring' => true,    // Activar monitorización de latencia
        'latency_thresholds' => [        // Umbrales de latencia para alertas (segundos)
            'warning' => 5,
            'critical' => 15,
        ],
    ];

    /**
     * Historial de latencias para monitorización de rendimiento
     *
     * @var array
     */
    private $latency_history = [];

    /**
     * Constructor
     *
     * @param Logger|null $logger Instancia del logger
     * @param array $config Configuración personalizada
     */
    public function __construct($logger = null, array $config = []) {
        $this->logger = $logger ?? new \MiIntegracionApi\Helpers\Logger('ssl_timeout');
        
        // Fusionar configuración personalizada con valores predeterminados
        if (!empty($config)) {
            $this->config = array_merge($this->config, $config);
        }
        
        // Cargar configuración desde opciones de WordPress
        $saved_config = get_option('miapi_ssl_timeout_config');
        if (is_array($saved_config)) {
            $this->config = array_merge($this->config, $saved_config);
        }
        
        // Cargar historial de latencias
        $this->loadLatencyHistory();
    }

    /**
     * Establece un timeout específico para un host
     *
     * @param string $host Nombre del host
     * @param array $timeouts Array con tiempos de espera específicos
     * @return self
     */
    public function setHostTimeout(string $host, array $timeouts): self {
        $this->config['timeout_hosts'][$host] = $timeouts;
        return $this;
    }

    /**
     * Establece un timeout específico para un método HTTP
     *
     * @param string $method Método HTTP (GET, POST, etc.)
     * @param int $timeout Tiempo de espera en segundos
     * @return self
     */
    public function setMethodTimeout(string $method, int $timeout): self {
        $method = strtoupper($method);
        $this->config['method_timeouts'][$method] = $timeout;
        return $this;
    }

    /**
     * Establece una política de reintentos para un tipo de error
     *
     * @param string $error_type Tipo de error (connection_timeout, ssl_error, etc.)
     * @param array $policy Política de reintentos
     * @return self
     */
    public function setErrorPolicy(string $error_type, array $policy): self {
        $this->config['error_policies'][$error_type] = $policy;
        return $this;
    }

    /**
     * Guarda la configuración actual como opción de WordPress
     */
    public function saveConfig(): void {
        update_option('miapi_ssl_timeout_config', $this->config, false);
    }

    /**
     * Obtiene la configuración de timeout para un host específico
     *
     * @param string $url URL completa o nombre de host
     * @param string $method Método HTTP (opcional)
     * @return array Configuración de timeout
     */
    public function getTimeoutConfig(string $url, string $method = ''): array {
        // Extraer el hostname de la URL
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        
        // Configuración base
        $config = [
            'timeout' => $this->config['default_timeout'],
            'connect_timeout' => $this->config['connect_timeout'],
            'ssl_handshake_timeout' => $this->config['ssl_handshake_timeout'],
            'max_retries' => $this->config['max_retries'],
        ];
        
        // Si hay un método HTTP especificado, ajustar el timeout
        if (!empty($method)) {
            $method = strtoupper($method);
            if (isset($this->config['method_timeouts'][$method])) {
                $config['timeout'] = $this->config['method_timeouts'][$method];
            }
        }
        
        // Si hay configuración específica para este host, usarla
        if (isset($this->config['timeout_hosts'][$host])) {
            $config = array_merge($config, $this->config['timeout_hosts'][$host]);
        }
        
        return $config;
    }

    /**
     * Obtiene la política de reintento para un tipo específico de error
     *
     * @param string $error_type Tipo de error
     * @return array Política de reintentos
     */
    public function getErrorPolicy(string $error_type): array {
        if (isset($this->config['error_policies'][$error_type])) {
            return $this->config['error_policies'][$error_type];
        }
        
        // Política por defecto
        return [
            'max_retries' => $this->config['max_retries'],
            'backoff_factor' => $this->config['backoff_factor']
        ];
    }

    /**
     * Calcula el tiempo de espera para un reintento usando backoff exponencial con jitter
     *
     * @param int $retry_number Número de reintento (empezando por 0)
     * @param string $error_type Tipo de error para aplicar política específica
     * @return float Tiempo de espera en segundos
     */
    public function calculateBackoff(int $retry_number, string $error_type = ''): float {
        if ($retry_number <= 0) {
            return 0;
        }
        
        // Obtener política según tipo de error
        $policy = !empty($error_type) ? $this->getErrorPolicy($error_type) : [];
        $backoff_factor = $policy['backoff_factor'] ?? $this->config['backoff_factor'];
        
        // Backoff exponencial base
        $base_backoff = pow($backoff_factor, $retry_number);
        
        // Añadir jitter (variación aleatoria) para evitar tormentas de conexiones
        $jitter = $this->config['jitter'] * $base_backoff * (mt_rand(0, 100) / 100);
        
        return $base_backoff + $jitter;
    }

    /**
     * Aplica la configuración de timeouts a una solicitud CURL
     *
     * @param resource $curl_handle Manejador CURL
     * @param string $url URL de la solicitud
     * @param int $retry_number Número de reintento actual
     * @param string $method Método HTTP (opcional)
     * @return resource Manejador CURL actualizado
     */
    public function applyCurlTimeouts($curl_handle, string $url, int $retry_number = 0, string $method = '') {
        $timeout_config = $this->getTimeoutConfig($url, $method);
        
        // Timeout general
        curl_setopt($curl_handle, CURLOPT_TIMEOUT, $timeout_config['timeout']);
        
        // Timeout de conexión
        curl_setopt($curl_handle, CURLOPT_CONNECTTIMEOUT, $timeout_config['connect_timeout']);
        
        // Timeout específico para SSL/TLS
        if (defined('CURLOPT_SSL_HANDSHAKE_TIMEOUT')) {
            // Esta opción está disponible en versiones recientes de cURL
            curl_setopt($curl_handle, CURLOPT_SSL_HANDSHAKE_TIMEOUT, $timeout_config['ssl_handshake_timeout']);
        }
        
        // Si este es un reintento, registrarlo
        if ($retry_number > 0) {
            $this->logger->info("[SSL Timeout] Reintento #$retry_number para $url");
        }
        
        return $curl_handle;
    }

    /**
     * Configura los parámetros para una solicitud de WordPress
     *
     * @param array $args Argumentos de solicitud wp_remote_*
     * @param string $url URL de la solicitud
     * @param int $retry_number Número de reintento actual
     * @param string $method Método HTTP (opcional)
     * @return array Argumentos actualizados
     */
    public function applyWpRequestArgs(array $args, string $url, int $retry_number = 0, string $method = ''): array {
        $timeout_config = $this->getTimeoutConfig($url, $method);
        
        // Configurar timeouts
        $args['timeout'] = $timeout_config['timeout'];
        $args['connect_timeout'] = $timeout_config['connect_timeout'];
        
        // Añadir info de reintento en el user agent si es un reintento
        if ($retry_number > 0) {
            $user_agent = $args['user-agent'] ?? '';
            $args['user-agent'] = $user_agent . " (retry=$retry_number)";
            
            $this->logger->info("[SSL Timeout] Reintento #$retry_number para $url");
        }
        
        return $args;
    }

    /**
     * Ejecuta una solicitud HTTP con manejo de reintentos y backoff
     *
     * @param callable $request_fn Función que ejecuta la solicitud
     * @param string $url URL de la solicitud
     * @param array $args Argumentos de la solicitud
     * @return mixed Resultado de la solicitud o null si todos los intentos fallaron
     */
    public function executeWithRetry(callable $request_fn, string $url, array $args = []) {
        // Extraer método HTTP si está disponible
        $method = $args['method'] ?? '';
        
        $timeout_config = $this->getTimeoutConfig($url, $method);
        $max_retries = $timeout_config['max_retries'];
        
        $attempt = 0;
        $last_error = null;
        $error_type = '';
        $start_time = microtime(true);
        
        do {
            try {
                // Si es un reintento, esperar según la estrategia de backoff
                if ($attempt > 0) {
                    $backoff_time = $this->calculateBackoff($attempt, $error_type);
                    $this->logger->info("[SSL Timeout] Esperando $backoff_time segundos antes del reintento #$attempt (tipo de error: $error_type)");
                    sleep(ceil($backoff_time)); // Convertir a entero para sleep()
                }
                
                // Aplicar configuración de timeout según el intento
                if (strpos(get_class($request_fn), 'Closure') !== false) {
                    // Para funciones de cierre personalizadas
                    $args['_retry_number'] = $attempt;
                    $args['_timeout_config'] = $timeout_config;
                }
                
                $retry_start_time = microtime(true);
                
                // Ejecutar la solicitud
                $result = $request_fn($url, $args, $attempt);
                
                $latency = microtime(true) - $retry_start_time;
                
                // Monitorear la latencia de la respuesta
                $this->recordLatency($url, $method, $latency);
                
                // Si hay un resultado válido, retornarlo
                if ($this->isValidResult($result)) {
                    if ($attempt > 0) {
                        $this->logger->info("[SSL Timeout] Solicitud exitosa después de $attempt reintento(s): $url (latencia: " . round($latency, 2) . "s)");
                    }
                    
                    // Registro de latencia para análisis
                    if ($this->config['latency_monitoring']) {
                        $total_time = microtime(true) - $start_time;
                        $this->checkLatencyThresholds($url, $latency, $total_time);
                    }
                    
                    return $result;
                }
                
                // Analizar el error para determinar la política de reintentos
                $error_message = $this->getErrorMessage($result);
                $error_type = $this->determineErrorType($result);
                
                $this->logger->warning("[SSL Timeout] Intento $attempt falló: $error_message (tipo: $error_type)");
                $last_error = $result;
                
                // Actualizar el número máximo de reintentos según la política
                $error_policy = $this->getErrorPolicy($error_type);
                $max_retries = $error_policy['max_retries'] ?? $max_retries;
                
            } catch (\Exception $e) {
                $this->logger->warning("[SSL Timeout] Excepción en intento $attempt: " . $e->getMessage());
                $last_error = $e;
                $error_type = 'exception';
            }
            
            $attempt++;
            
        } while ($attempt <= $max_retries);
        
        $this->logger->error("[SSL Timeout] Todos los intentos fallaron para $url después de $max_retries reintentos (último error: $error_type)");
        return $last_error;
    }
    
    /**
     * Verifica si un resultado es válido
     *
     * @param mixed $result Resultado a verificar
     * @return bool True si es válido
     */
    private function isValidResult($result) {
        // Verificar errores de WordPress
        if (is_wp_error($result)) {
            return false;
        }
        
        // Verificar respuestas HTTP
        if (is_array($result) && isset($result['response']['code'])) {
            $code = $result['response']['code'];
            // Códigos 2xx son éxito
            return $code >= 200 && $code < 300;
        }
        
        // Verificar si es null o false (error)
        if ($result === null || $result === false) {
            return false;
        }
        
        // Por defecto, asumir que es válido
        return true;
    }
    
    /**
     * Obtiene un mensaje de error de un resultado fallido
     *
     * @param mixed $result Resultado fallido
     * @return string Mensaje de error
     */
    private function getErrorMessage($result) {
        if (is_wp_error($result)) {
            return $result->get_error_message();
        }
        
        if (is_array($result) && isset($result['response']['code'])) {
            return "HTTP code: " . $result['response']['code'];
        }
        
        if ($result instanceof \Exception) {
            return $result->getMessage();
        }
        
        return "Error desconocido";
    }
    
    /**
     * Determina el tipo de error para aplicar política de reintentos
     *
     * @param mixed $result Resultado fallido
     * @return string Tipo de error
     */
    private function determineErrorType($result) {
        // Error de WordPress
        if (is_wp_error($result)) {
            $error_code = $result->get_error_code();
            
            if (strpos($error_code, 'connect') !== false || strpos($error_code, 'timeout') !== false) {
                return 'connection_timeout';
            }
            
            if (strpos($error_code, 'ssl') !== false || strpos($error_code, 'cert') !== false) {
                return 'ssl_error';
            }
        }
        
        // Respuesta HTTP
        if (is_array($result) && isset($result['response']['code'])) {
            $code = $result['response']['code'];
            
            // Errores de servidor (5xx)
            if ($code >= 500) {
                return 'server_error';
            }
            
            // Errores de cliente (4xx)
            if ($code >= 400) {
                return 'client_error';
            }
        }
        
        // Error de conexión genérico
        return 'connection_error';
    }
    
    /**
     * Registra la latencia de una solicitud para monitoreo de rendimiento
     *
     * @param string $url URL de la solicitud
     * @param string $method Método HTTP
     * @param float $latency Tiempo de latencia en segundos
     */
    private function recordLatency(string $url, string $method, float $latency) {
        if (!$this->config['latency_monitoring']) {
            return;
        }
        
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        $method = strtoupper($method) ?: 'UNKNOWN';
        $timestamp = time();
        
        // Limitar el tamaño del historial por host
        if (!isset($this->latency_history[$host])) {
            $this->latency_history[$host] = [];
        }
        
        $this->latency_history[$host][] = [
            'latency' => $latency,
            'method' => $method,
            'timestamp' => $timestamp,
        ];
        
        // Mantener solo las últimas 100 entradas por host
        if (count($this->latency_history[$host]) > 100) {
            array_shift($this->latency_history[$host]);
        }
        
        // Guardar historial periódicamente
        if (mt_rand(1, 10) === 1) { // ~10% de las veces
            $this->saveLatencyHistory();
        }
    }
    
    /**
     * Verifica si la latencia supera los umbrales configurados
     *
     * @param string $url URL de la solicitud
     * @param float $latency Tiempo de latencia
     * @param float $total_time Tiempo total incluyendo reintentos
     */
    private function checkLatencyThresholds(string $url, float $latency, float $total_time) {
        $host = parse_url($url, PHP_URL_HOST) ?? $url;
        
        // Verificar umbral crítico
        if ($latency >= $this->config['latency_thresholds']['critical']) {
            $this->logger->error("[SSL Latencia] Crítica: $host - " . round($latency, 2) . "s (total: " . round($total_time, 2) . "s)");
        }
        // Verificar umbral de advertencia
        else if ($latency >= $this->config['latency_thresholds']['warning']) {
            $this->logger->warning("[SSL Latencia] Alta: $host - " . round($latency, 2) . "s (total: " . round($total_time, 2) . "s)");
        }
    }
    
    /**
     * Obtiene estadísticas de latencia para un host específico o para todos los hosts
     *
     * @param string|null $host Host específico (opcional)
     * @return array Estadísticas de latencia
     */
    public function getLatencyStats(?string $host = null): array {
        if (!$this->config['latency_monitoring']) {
            return ['enabled' => false];
        }
        
        $stats = ['enabled' => true];
        
        if ($host !== null) {
            // Estadísticas para un host específico
            $stats['host'] = $host;
            
            if (!isset($this->latency_history[$host])) {
                $stats['requests'] = 0;
                return $stats;
            }
            
            $latencies = array_column($this->latency_history[$host], 'latency');
            $stats['requests'] = count($latencies);
            
            if (!empty($latencies)) {
                $stats['average_latency'] = array_sum($latencies) / count($latencies);
                $stats['min_latency'] = min($latencies);
                $stats['max_latency'] = max($latencies);
                $stats['last_request'] = end($this->latency_history[$host])['timestamp'];
            }
            
        } else {
            // Estadísticas globales
            $stats['hosts'] = [];
            $total_requests = 0;
            $all_latencies = [];
            
            foreach ($this->latency_history as $hostname => $entries) {
                $host_latencies = array_column($entries, 'latency');
                $total_requests += count($host_latencies);
                $all_latencies = array_merge($all_latencies, $host_latencies);
                
                $stats['hosts'][$hostname] = [
                    'requests' => count($host_latencies),
                ];
                
                if (!empty($host_latencies)) {
                    $stats['hosts'][$hostname]['average_latency'] = array_sum($host_latencies) / count($host_latencies);
                    $stats['hosts'][$hostname]['max_latency'] = max($host_latencies);
                }
            }
            
            $stats['total_requests'] = $total_requests;
            
            if (!empty($all_latencies)) {
                $stats['global_average_latency'] = array_sum($all_latencies) / count($all_latencies);
                $stats['global_max_latency'] = max($all_latencies);
            }
        }
        
        return $stats;
    }
    
    /**
     * Guarda el historial de latencias en las opciones de WordPress
     */
    private function saveLatencyHistory(): void {
        if (!$this->config['latency_monitoring']) {
            return;
        }
        
        update_option('miapi_ssl_latency_history', $this->latency_history, false);
    }
    
    /**
     * Carga el historial de latencias desde las opciones de WordPress
     */
    private function loadLatencyHistory(): void {
        if (!$this->config['latency_monitoring']) {
            $this->latency_history = [];
            return;
        }
        
        $saved_history = get_option('miapi_ssl_latency_history');
        $this->latency_history = is_array($saved_history) ? $saved_history : [];
    }
    
    /**
     * Limpia el historial de latencias
     *
     * @param string|null $host Host específico o null para limpiar todo
     * @return bool Resultado de la operación
     */
    public function clearLatencyHistory(?string $host = null): bool {
        if ($host === null) {
            $this->latency_history = [];
        } else if (isset($this->latency_history[$host])) {
            $this->latency_history[$host] = [];
        }
        
        $this->saveLatencyHistory();
        return true;
    }

    /**
     * Devuelve la configuración actual de timeouts y reintentos
     *
     * @return array
     */
    public function getConfiguration(): array {
        return $this->config;
    }
}
