<?php
/**
 * Implementa los métodos para la inicialización y gestión de los sistemas SSL avanzados
 * 
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */

namespace MiIntegracionApi\Core;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Trait para la gestión de sistemas SSL avanzados
 *
 * Esta trait implementa los métodos necesarios para manejar los nuevos 
 * sistemas de certificados SSL, timeouts, configuración y rotación.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */
trait SSLAdvancedSystemsTrait {

    /**
     * Inicializa los sistemas SSL mejorados
     */
    private function initSSLSystems(): void {
        // Inicializar caché de certificados
        $this->cert_cache = new \MiIntegracionApi\SSL\CertificateCache($this->logger);
        
        // Inicializar gestor de timeouts SSL
        $this->timeout_manager = new \MiIntegracionApi\SSL\SSLTimeoutManager($this->logger);
        
        // Inicializar gestor de configuración SSL
        $this->ssl_config_manager = new \MiIntegracionApi\SSL\SSLConfigManager($this->logger);
        
        // Inicializar sistema de rotación de certificados
        $this->cert_rotation = new \MiIntegracionApi\SSL\CertificateRotation($this->logger);
        
        // Registrar acciones de WordPress para el sistema de rotación
        add_action('miapi_ssl_certificate_rotation', [$this->cert_rotation, 'scheduledRotation']);
        
        // Registrar acción para guardar estadísticas de latencia periódicamente
        add_action('miapi_ssl_save_latency_stats', [$this, 'saveLatencyStats']);
        
        // Programar guardado periódico de estadísticas de latencia
        if (!wp_next_scheduled('miapi_ssl_save_latency_stats')) {
            wp_schedule_event(time(), 'daily', 'miapi_ssl_save_latency_stats');
        }
    }
    
    /**
     * Guarda las estadísticas de latencia para análisis a largo plazo
     */
    public function saveLatencyStats(): void {
        if (!$this->timeout_manager) {
            return;
        }
        
        $stats = $this->timeout_manager->getLatencyStats();
        if (!empty($stats)) {
            $stats['date'] = date('Y-m-d');
            
            // Almacenar un historial limitado de estadísticas diarias
            $history = get_option('miapi_ssl_latency_stats_history', []);
            $history[] = $stats;
            
            // Mantener solo los últimos 30 días
            if (count($history) > 30) {
                $history = array_slice($history, -30);
            }
            
            update_option('miapi_ssl_latency_stats_history', $history);
            $this->logger->info("[SSL Stats] Estadísticas de latencia guardadas: " . $stats['total_requests'] . " solicitudes");
        }
    }
    
    /**
     * Verifica si es necesaria una rotación de certificados y la realiza si aplica
     * 
     * @param bool $force_rotation Forzar la rotación aunque no sea necesaria
     * @return bool Resultado de la operación o true si no se requiere rotación
     */
    public function checkCertificateRotation(bool $force_rotation = false): bool {
        if (!$this->cert_rotation) {
            return false;
        }
        
        if ($force_rotation || $this->cert_rotation->needsRotation()) {
            return $this->cert_rotation->rotateCertificates($force_rotation);
        }
        
        return true;
    }
    
    /**
     * Obtiene un certificado utilizando el sistema de caché
     * 
     * @param string $cert_url URL o ruta del certificado
     * @param bool $force_refresh Forzar recarga ignorando caché
     * @return string|false Contenido del certificado o false si error
     */
    public function getCachedCertificate(string $cert_url, bool $force_refresh = false) {
        if (!$this->cert_cache) {
            return file_exists($cert_url) ? file_get_contents($cert_url) : false;
        }
        
        return $this->cert_cache->getCertificate($cert_url, $force_refresh);
    }
    
    /**
     * Prepara una petición con los sistemas SSL mejorados
     * 
     * @param array $args Argumentos de la petición
     * @param string $url URL de destino
     * @param string $method Método HTTP de la petición (opcional)
     * @return array Argumentos modificados
     */
    private function prepareRequestWithSSLSystems(array $args, string $url, string $method = ''): array {
        $is_local = $this->isLocalDevelopment();
        
        // Aplicar configuración SSL avanzada
        if ($this->ssl_config_manager) {
            $args = $this->ssl_config_manager->applyWpRequestArgs($args, $is_local);
        }
        
        // Aplicar configuración de timeouts
        if ($this->timeout_manager) {
            $args = $this->timeout_manager->applyWpRequestArgs($args, $url, 0, $method);
        }
        
        // Asegurar que tenemos un CA bundle válido
        if ($this->ssl_config_manager && $this->ssl_config_manager->getOption('verify_peer', true)) {
            $ca_bundle_path = $this->ssl_config_manager->getOption('ca_bundle_path', '');
            
            if (empty($ca_bundle_path)) {
                $ca_bundle_path = $this->findCaBundlePath();
            }
            
            if ($ca_bundle_path && file_exists($ca_bundle_path)) {
                $args['sslcertificates'] = $ca_bundle_path;
            }
        }
        
        return $args;
    }
    
    /**
     * Realiza una solicitud HTTP con reintentos y manejo de errores mejorado
     * 
     * @param string $method Método HTTP (GET, POST, etc.)
     * @param string $url URL de destino
     * @param array $args Argumentos de la petición
     * @return array|WP_Error Respuesta o error
     */
    private function doRequestWithRetry(string $method, string $url, array $args = []) {
        if (!$this->timeout_manager) {
            // Si no hay gestor de timeouts, usar el método estándar
            return $this->doRequest($method, $url, $args);
        }
        
        // Preparar la solicitud con sistemas SSL mejorados
        $args = $this->prepareRequestWithSSLSystems($args, $url, $method);
        
        // Definir la función que realizará la solicitud
        $request_fn = function($url, $args, $retry_number) use ($method) {
            // Actualizar argumentos para este intento específico
            $args = $this->timeout_manager->applyWpRequestArgs($args, $url, $retry_number, $method);
            
            // Realizar la solicitud según el método
            switch (strtoupper($method)) {
                case 'GET':
                    return wp_remote_get($url, $args);
                case 'POST':
                    return wp_remote_post($url, $args);
                case 'HEAD':
                    return wp_remote_head($url, $args);
                default:
                    $args['method'] = strtoupper($method);
                    return wp_remote_request($url, $args);
            }
        };
        
        // Ejecutar con sistema de reintentos
        return $this->timeout_manager->executeWithRetry($request_fn, $url, $args);
    }
    
    /**
     * Mejora una conexión CURL con los sistemas SSL avanzados
     * 
     * @param resource $ch Handle de CURL
     * @param string $url URL de destino
     * @param string $method Método HTTP (opcional)
     * @return resource Handle de CURL configurado
     */
    private function enhanceCurlWithSSLSystems($ch, string $url, string $method = '') {
        $is_local = $this->isLocalDevelopment();
        
        // Aplicar configuración SSL avanzada
        if ($this->ssl_config_manager) {
            $ch = $this->ssl_config_manager->applyCurlOptions($ch, $is_local);
        }
        
        // Aplicar configuración de timeouts
        if ($this->timeout_manager) {
            $ch = $this->timeout_manager->applyCurlTimeouts($ch, $url, 0, $method);
        }
        
        return $ch;
    }
    
    /**
     * Obtiene estadísticas del sistema de caché de certificados
     * 
     * @return array Estadísticas de caché
     */
    public function getCertificateCacheStats(): array {
        if (!$this->cert_cache) {
            return ['enabled' => false];
        }
        
        $stats = $this->cert_cache->getCacheStats();
        $stats['enabled'] = true;
        
        return $stats;
    }
    
    /**
     * Obtiene estadísticas del sistema de timeouts y latencias
     * 
     * @param string|null $host Host específico o null para todas las estadísticas
     * @return array Estadísticas de timeouts y latencias
     */
    public function getTimeoutStats(?string $host = null): array {
        if (!$this->timeout_manager) {
            return ['enabled' => false];
        }
        
        $latency_stats = $this->timeout_manager->getLatencyStats($host);
        
        $stats = [
            'enabled' => true,
            'latency' => $latency_stats,
            'configuration' => [
                'method_timeouts' => $this->timeout_manager->getTimeoutConfig('example.com'),
                'error_policies' => [
                    'connection_timeout' => $this->timeout_manager->getErrorPolicy('connection_timeout'),
                    'ssl_error' => $this->timeout_manager->getErrorPolicy('ssl_error'),
                    'server_error' => $this->timeout_manager->getErrorPolicy('server_error'),
                ],
            ],
        ];
        
        // Añadir historial de estadísticas si existe
        $stats_history = get_option('miapi_ssl_latency_stats_history', []);
        if (!empty($stats_history)) {
            $stats['history'] = [
                'days' => count($stats_history),
                'last_recorded' => end($stats_history)['date'],
                'summary' => [
                    'total_requests' => array_sum(array_column($stats_history, 'total_requests')),
                ],
            ];
        }
        
        return $stats;
    }
    
    /**
     * Limpia la caché de certificados
     * 
     * @param string|null $cert_url Certificado específico a limpiar o null para limpiar todo
     * @return bool Resultado de la operación
     */
    public function clearCertificateCache(?string $cert_url = null): bool {
        if (!$this->cert_cache) {
            return false;
        }
        
        return $this->cert_cache->clearCache($cert_url);
    }
    
    /**
     * Limpia el historial de latencias
     * 
     * @param string|null $host Host específico a limpiar o null para limpiar todo
     * @return bool Resultado de la operación
     */
    public function clearLatencyHistory(?string $host = null): bool {
        if (!$this->timeout_manager) {
            return false;
        }
        
        return $this->timeout_manager->clearLatencyHistory($host);
    }
    
    /**
     * Obtiene el estado del sistema de rotación de certificados
     * 
     * @return array Estado del sistema
     */
    public function getCertificateRotationStatus(): array {
        if (!$this->cert_rotation) {
            return ['enabled' => false];
        }
        
        $status = $this->cert_rotation->getStatus();
        $status['enabled'] = true;
        
        return $status;
    }
    
    /**
     * Actualiza la configuración SSL avanzada
     * 
     * @param array $options Nuevas opciones de configuración
     * @return bool Resultado de la operación
     */
    public function updateSSLConfiguration(array $options): bool {
        if (!$this->ssl_config_manager) {
            return false;
        }
        
        foreach ($options as $key => $value) {
            $this->ssl_config_manager->setOption($key, $value);
        }
        
        return $this->ssl_config_manager->saveConfig();
    }
    
    /**
     * Actualiza la configuración del gestor de timeouts
     * 
     * @param array $timeout_config Nueva configuración de timeouts
     * @return bool Resultado de la operación
     */
    public function updateTimeoutConfiguration(array $timeout_config): bool {
        if (!$this->timeout_manager) {
            return false;
        }
        
        // Actualizar configuración por método HTTP si está presente
        if (isset($timeout_config['method_timeouts']) && is_array($timeout_config['method_timeouts'])) {
            foreach ($timeout_config['method_timeouts'] as $method => $timeout) {
                $this->timeout_manager->setMethodTimeout($method, $timeout);
            }
        }
        
        // Actualizar configuración por host si está presente
        if (isset($timeout_config['timeout_hosts']) && is_array($timeout_config['timeout_hosts'])) {
            foreach ($timeout_config['timeout_hosts'] as $host => $host_config) {
                $this->timeout_manager->setHostTimeout($host, $host_config);
            }
        }
        
        // Actualizar políticas de error si están presentes
        if (isset($timeout_config['error_policies']) && is_array($timeout_config['error_policies'])) {
            foreach ($timeout_config['error_policies'] as $error_type => $policy) {
                $this->timeout_manager->setErrorPolicy($error_type, $policy);
            }
        }
        
        // Guardar la configuración
        $this->timeout_manager->saveConfig();
        
        return true;
    }
    
    /**
     * Obtiene la configuración SSL avanzada actual
     * 
     * @return array Configuración SSL
     */
    public function getSSLConfiguration(): array {
        if (!$this->ssl_config_manager) {
            return $this->ssl_config;
        }
        
        return $this->ssl_config_manager->getAllOptions();
    }
    
    /**
     * Asegura que la configuración SSL esté correctamente inicializada
     */
    private function ensureSslConfiguration(): void {
        if (!$this->ssl_config_manager) {
            $this->logger->error('[SSL] El gestor de configuración SSL no está inicializado');
            return;
        }

        // Verificar y actualizar la configuración SSL
        $config = $this->ssl_config_manager->getAllOptions();
        
        // Verificar que tenemos un CA bundle válido
        if ($config['verify_peer']) {
            $ca_bundle_path = $config['ca_bundle_path'] ?? '';
            
            if (empty($ca_bundle_path)) {
                $ca_bundle_path = $this->findCaBundlePath();
                if ($ca_bundle_path) {
                    $this->ssl_config_manager->setOption('ca_bundle_path', $ca_bundle_path);
                    $this->ssl_config_manager->saveConfig();
                }
            }
            
            if (empty($ca_bundle_path) || !file_exists($ca_bundle_path)) {
                $this->logger->warning('[SSL] No se encontró un CA bundle válido');
            }
        }
        
        // Verificar y actualizar timeouts si es necesario
        if ($this->timeout_manager) {
            $timeout_config = $this->timeout_manager->getConfiguration();
            if (empty($timeout_config)) {
                $this->timeout_manager->updateConfiguration([
                    'default_timeout' => 30,
                    'max_timeout' => 60,
                    'retry_count' => 3,
                    'retry_delay' => 1
                ]);
            }
        }
        
        // Verificar caché de certificados
        if ($this->cert_cache) {
            $this->cert_cache->validateCache();
        }
        
        $this->logger->info('[SSL] Configuración SSL verificada y actualizada');
    }
}
