<?php
/**
 * Herramienta para diagnóstico y monitoreo del sistema SSL avanzado
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Tools
 */

namespace MiIntegracionApi\Tools;

use MiIntegracionApi\Core\ApiConnector;
use \MiIntegracionApi\Helpers\Logger;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Implementa herramientas para diagnóstico y monitoreo del sistema SSL
 *
 * Esta clase proporciona métodos para verificar y mostrar estadísticas
 * del sistema avanzado de SSL, ayudando a identificar y resolver problemas.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Tools
 */
class SSLDiagnosticsTool {

    /**
     * Instancia del conector API
     * 
     * @var ApiConnector
     */
    private $api_connector;

    /**
     * Logger para registrar eventos
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     * 
     * @param ApiConnector $api_connector Instancia del conector API
     */
    public function __construct(ApiConnector $api_connector) {
        $this->api_connector = $api_connector;
        $this->logger = new \MiIntegracionApi\Helpers\Logger('ssl_diagnostics');
    }

    /**
     * Realiza una verificación completa del sistema SSL
     * 
     * @return array Resultados de la verificación
     */
    public function runDiagnostics(): array {
        $results = [
            'timestamp' => time(),
            'date' => date('Y-m-d H:i:s'),
            'tests' => [],
            'summary' => [
                'passed' => 0,
                'warnings' => 0,
                'failed' => 0,
                'total' => 0
            ]
        ];
        
        // 1. Verificar certificados CA disponibles
        $results['tests']['ca_bundle'] = $this->checkCaBundle();
        
        // 2. Verificar configuración SSL
        $results['tests']['ssl_config'] = $this->checkSSLConfig();
        
        // 3. Verificar caché de certificados
        $results['tests']['certificate_cache'] = $this->checkCertificateCache();
        
        // 4. Verificar sistema de timeouts
        $results['tests']['timeout_system'] = $this->checkTimeoutSystem();
        
        // 5. Verificar sistema de rotación
        $results['tests']['certificate_rotation'] = $this->checkCertificateRotation();
        
        // 6. Verificar conectividad con servidor de prueba
        $results['tests']['test_connection'] = $this->testSSLConnection();
        
        // 7. Verificar historial de latencias
        $results['tests']['latency_history'] = $this->checkLatencyHistory();
        
        // Calcular resumen
        foreach ($results['tests'] as $test) {
            $results['summary']['total']++;
            if ($test['status'] === 'passed') {
                $results['summary']['passed']++;
            } elseif ($test['status'] === 'warning') {
                $results['summary']['warnings']++;
            } elseif ($test['status'] === 'failed') {
                $results['summary']['failed']++;
            }
        }
        
        // Calcular porcentaje de éxito
        $results['summary']['success_rate'] = $results['summary']['total'] > 0 
            ? round(($results['summary']['passed'] / $results['summary']['total']) * 100, 1)
            : 0;
            
        return $results;
    }
    
    /**
     * Verifica la disponibilidad y validez del bundle de CA
     * 
     * @return array Resultado de la verificación
     */
    private function checkCaBundle(): array {
        $result = [
            'name' => 'Certificados CA',
            'description' => 'Verificación del bundle de certificados CA',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si el método existe
        if (!method_exists($this->api_connector, 'findCaBundlePath')) {
            $result['status'] = 'failed';
            $result['details'][] = 'El método findCaBundlePath no existe en el conector API';
            return $result;
        }
        
        // Obtener ruta del CA bundle
        $ca_path = $this->api_connector->findCaBundlePath();
        
        if (empty($ca_path)) {
            $result['status'] = 'failed';
            $result['details'][] = 'No se encontró ningún bundle de CA válido';
            return $result;
        }
        
        $result['details'][] = "Bundle CA ubicado en: $ca_path";
        
        // Verificar si el archivo existe
        if (!file_exists($ca_path)) {
            $result['status'] = 'failed';
            $result['details'][] = "El archivo de certificados CA no existe: $ca_path";
            return $result;
        }
        
        // Verificar permisos
        if (!is_readable($ca_path)) {
            $result['status'] = 'warning';
            $result['details'][] = "El archivo de certificados CA no es legible: $ca_path";
            return $result;
        }
        
        // Verificar contenido básico
        $ca_content = file_get_contents($ca_path);
        if (empty($ca_content)) {
            $result['status'] = 'failed';
            $result['details'][] = "El archivo de certificados CA está vacío";
            return $result;
        }
        
        // Verificar si contiene certificados
        if (strpos($ca_content, '-----BEGIN CERTIFICATE-----') === false) {
            $result['status'] = 'warning';
            $result['details'][] = "El archivo no parece contener certificados en formato PEM";
            return $result;
        }
        
        // Contar certificados aproximadamente
        $cert_count = substr_count($ca_content, '-----BEGIN CERTIFICATE-----');
        $result['details'][] = "El bundle contiene aproximadamente $cert_count certificados CA";
        
        // Verificar tamaño del archivo
        $file_size = filesize($ca_path);
        $result['details'][] = "Tamaño del archivo: " . round($file_size / 1024, 2) . " KB";
        
        if ($file_size < 10000) { // Menos de 10KB es sospechoso para un bundle
            $result['status'] = 'warning';
            $result['details'][] = "El tamaño del archivo es inusualmente pequeño para un bundle CA";
            return $result;
        }
        
        $result['status'] = 'passed';
        return $result;
    }
    
    /**
     * Verifica la configuración SSL actual
     * 
     * @return array Resultado de la verificación
     */
    private function checkSSLConfig(): array {
        $result = [
            'name' => 'Configuración SSL',
            'description' => 'Verificación de la configuración SSL',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si tenemos acceso a la configuración
        if (!method_exists($this->api_connector, 'getSSLConfiguration')) {
            $result['status'] = 'failed';
            $result['details'][] = 'No se puede acceder a la configuración SSL';
            return $result;
        }
        
        $ssl_config = $this->api_connector->getSSLConfiguration();
        
        // Verificar opciones críticas
        $critical_options = [
            'verify_peer' => 'Verificación del peer',
            'verify_peer_name' => 'Verificación del nombre del peer',
            'allow_self_signed' => 'Permitir certificados autofirmados',
            'verify_depth' => 'Profundidad de verificación',
        ];
        
        $warnings = 0;
        foreach ($critical_options as $option => $description) {
            if (isset($ssl_config[$option])) {
                $value = $ssl_config[$option];
                $value_text = is_bool($value) ? ($value ? 'activado' : 'desactivado') : $value;
                $result['details'][] = "$description: $value_text";
                
                // Verificar configuraciones peligrosas
                if (($option === 'verify_peer' && $value === false) || 
                    ($option === 'verify_peer_name' && $value === false) ||
                    ($option === 'allow_self_signed' && $value === true)) {
                    $warnings++;
                    $result['details'][] = "⚠️ Advertencia: La configuración de '$option' puede comprometer la seguridad";
                }
            } else {
                $result['details'][] = "$description: no configurado";
                $warnings++;
            }
        }
        
        // Verificar opciones adicionales relevantes
        if (isset($ssl_config['cipher_list']) && !empty($ssl_config['cipher_list'])) {
            $result['details'][] = "Lista de cifrados personalizada configurada";
        }
        
        if (isset($ssl_config['ssl_version']) && !empty($ssl_config['ssl_version'])) {
            $result['details'][] = "Versión SSL/TLS específica configurada: " . $ssl_config['ssl_version'];
        }
        
        if ($warnings > 0) {
            $result['status'] = 'warning';
        } else {
            $result['status'] = 'passed';
        }
        
        return $result;
    }
    
    /**
     * Verifica el sistema de caché de certificados
     * 
     * @return array Resultado de la verificación
     */
    private function checkCertificateCache(): array {
        $result = [
            'name' => 'Caché de Certificados',
            'description' => 'Verificación del sistema de caché de certificados',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si el método existe
        if (!method_exists($this->api_connector, 'getCertificateCacheStats')) {
            $result['status'] = 'warning';
            $result['details'][] = 'No se puede acceder a las estadísticas de caché de certificados';
            return $result;
        }
        
        $cache_stats = $this->api_connector->getCertificateCacheStats();
        
        if (!isset($cache_stats['enabled']) || $cache_stats['enabled'] === false) {
            $result['status'] = 'warning';
            $result['details'][] = 'El sistema de caché de certificados está deshabilitado';
            return $result;
        }
        
        // Verificar estadísticas básicas
        if (isset($cache_stats['cache_hits'])) {
            $result['details'][] = "Aciertos de caché: " . $cache_stats['cache_hits'];
        }
        
        if (isset($cache_stats['cache_misses'])) {
            $result['details'][] = "Fallos de caché: " . $cache_stats['cache_misses'];
        }
        
        if (isset($cache_stats['cached_items'])) {
            $result['details'][] = "Elementos en caché: " . $cache_stats['cached_items'];
        }
        
        if (isset($cache_stats['cache_size'])) {
            $result['details'][] = "Tamaño de caché: " . round($cache_stats['cache_size'] / 1024, 2) . " KB";
        }
        
        // Verificar ratio de aciertos
        if (isset($cache_stats['cache_hits']) && isset($cache_stats['cache_misses']) && 
            ($cache_stats['cache_hits'] + $cache_stats['cache_misses']) > 0) {
            $hit_ratio = $cache_stats['cache_hits'] / ($cache_stats['cache_hits'] + $cache_stats['cache_misses']) * 100;
            $result['details'][] = "Ratio de aciertos: " . round($hit_ratio, 2) . "%";
            
            if ($hit_ratio < 50 && ($cache_stats['cache_hits'] + $cache_stats['cache_misses']) > 10) {
                $result['status'] = 'warning';
                $result['details'][] = "⚠️ Advertencia: El ratio de aciertos de caché es bajo";
            } else {
                $result['status'] = 'passed';
            }
        } else {
            $result['status'] = 'passed';
            $result['details'][] = "No hay suficientes datos para evaluar el rendimiento de la caché";
        }
        
        return $result;
    }
    
    /**
     * Verifica el sistema de timeouts
     * 
     * @return array Resultado de la verificación
     */
    private function checkTimeoutSystem(): array {
        $result = [
            'name' => 'Sistema de Timeouts',
            'description' => 'Verificación del sistema de manejo de timeouts',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si el método existe
        if (!method_exists($this->api_connector, 'getTimeoutStats')) {
            $result['status'] = 'warning';
            $result['details'][] = 'No se puede acceder a las estadísticas del sistema de timeouts';
            return $result;
        }
        
        $timeout_stats = $this->api_connector->getTimeoutStats();
        
        if (!isset($timeout_stats['enabled']) || $timeout_stats['enabled'] === false) {
            $result['status'] = 'warning';
            $result['details'][] = 'El sistema avanzado de timeouts está deshabilitado';
            return $result;
        }
        
        // Verificar configuración de métodos
        if (isset($timeout_stats['configuration']['method_timeouts'])) {
            $result['details'][] = "Timeout general: " . $timeout_stats['configuration']['method_timeouts']['timeout'] . "s";
            $result['details'][] = "Timeout de conexión: " . $timeout_stats['configuration']['method_timeouts']['connect_timeout'] . "s";
        }
        
        // Verificar políticas de error
        if (isset($timeout_stats['configuration']['error_policies'])) {
            foreach ($timeout_stats['configuration']['error_policies'] as $error_type => $policy) {
                $result['details'][] = "Política para '$error_type': " . $policy['max_retries'] . " reintentos (factor: " . $policy['backoff_factor'] . ")";
            }
        }
        
        // Verificar estadísticas de latencia
        if (isset($timeout_stats['latency']) && isset($timeout_stats['latency']['global_average_latency'])) {
            $avg_latency = $timeout_stats['latency']['global_average_latency'];
            $max_latency = $timeout_stats['latency']['global_max_latency'] ?? 0;
            
            $result['details'][] = "Latencia promedio global: " . round($avg_latency, 2) . "s";
            $result['details'][] = "Latencia máxima registrada: " . round($max_latency, 2) . "s";
            
            // Evaluar latencia
            if ($avg_latency > 5) {
                $result['status'] = 'warning';
                $result['details'][] = "⚠️ Advertencia: La latencia promedio es alta";
            } else {
                $result['status'] = 'passed';
            }
        } else {
            $result['status'] = 'passed';
            $result['details'][] = "No hay suficientes datos de latencia para evaluar";
        }
        
        return $result;
    }
    
    /**
     * Verifica el sistema de rotación de certificados
     * 
     * @return array Resultado de la verificación
     */
    private function checkCertificateRotation(): array {
        $result = [
            'name' => 'Rotación de Certificados',
            'description' => 'Verificación del sistema de rotación de certificados',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si el método existe
        if (!method_exists($this->api_connector, 'getCertificateRotationStatus')) {
            $result['status'] = 'warning';
            $result['details'][] = 'No se puede acceder al sistema de rotación de certificados';
            return $result;
        }
        
        $rotation_status = $this->api_connector->getCertificateRotationStatus();
        
        if (!isset($rotation_status['enabled']) || $rotation_status['enabled'] === false) {
            $result['status'] = 'warning';
            $result['details'][] = 'El sistema de rotación de certificados está deshabilitado';
            return $result;
        }
        
        // Verificar última rotación
        if (isset($rotation_status['last_rotation'])) {
            $last_rotation = $rotation_status['last_rotation'];
            $days_ago = round((time() - $last_rotation) / 86400, 1);
            
            $result['details'][] = "Última rotación: hace " . $days_ago . " días";
            
            if ($days_ago > 30) {
                $result['status'] = 'warning';
                $result['details'][] = "⚠️ Advertencia: Han pasado más de 30 días desde la última rotación";
            }
        }
        
        // Verificar próxima rotación
        if (isset($rotation_status['next_rotation'])) {
            $next_rotation = $rotation_status['next_rotation'];
            $days_until = round(($next_rotation - time()) / 86400, 1);
            
            if ($days_until > 0) {
                $result['details'][] = "Próxima rotación: en " . $days_until . " días";
            } else {
                $result['details'][] = "Próxima rotación: pendiente (debería ocurrir pronto)";
            }
        }
        
        // Verificar fuentes de certificados
        if (isset($rotation_status['sources']) && is_array($rotation_status['sources'])) {
            $result['details'][] = "Fuentes de certificados configuradas: " . count($rotation_status['sources']);
        }
        
        // Verificar copias de seguridad
        if (isset($rotation_status['backups']) && is_array($rotation_status['backups'])) {
            $result['details'][] = "Copias de seguridad disponibles: " . count($rotation_status['backups']);
        }
        
        if (!isset($result['status'])) {
            $result['status'] = 'passed';
        }
        
        return $result;
    }
    
    /**
     * Realiza una prueba de conexión SSL
     * 
     * @return array Resultado de la prueba
     */
    private function testSSLConnection(): array {
        $result = [
            'name' => 'Prueba de Conexión SSL',
            'description' => 'Prueba de conexión SSL a un servidor seguro',
            'status' => 'pending',
            'details' => []
        ];
        
        // URL de prueba segura (endpoint que devuelve información TLS)
        $test_url = 'https://www.howsmyssl.com/a/check';
        
        $start_time = microtime(true);
        $response = $this->api_connector->makeRequest('GET', $test_url);
        $total_time = microtime(true) - $start_time;
        
        $result['details'][] = "Tiempo total de conexión: " . round($total_time, 2) . "s";
        
        if (is_wp_error($response)) {
            $result['status'] = 'failed';
            $result['details'][] = "Error: " . $response->get_error_message();
            return $result;
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $result['details'][] = "Código de respuesta HTTP: $http_code";
        
        if ($http_code < 200 || $http_code >= 300) {
            $result['status'] = 'failed';
            $result['details'][] = "La conexión no fue exitosa";
            return $result;
        }
        
        // Analizar la respuesta JSON con información TLS
        $body = wp_remote_retrieve_body($response);
        $tls_info = json_decode($body, true);
        
        if (is_array($tls_info)) {
            if (isset($tls_info['tls_version'])) {
                $result['details'][] = "Versión TLS: " . $tls_info['tls_version'];
            }
            
            if (isset($tls_info['cipher_suite'])) {
                $result['details'][] = "Suite de cifrado: " . $tls_info['cipher_suite'];
            }
            
            if (isset($tls_info['rating'])) {
                $result['details'][] = "Calificación de seguridad: " . $tls_info['rating'];
                
                if ($tls_info['rating'] === 'Bad') {
                    $result['status'] = 'failed';
                    $result['details'][] = "⚠️ La calificación de seguridad SSL/TLS es baja";
                } elseif ($tls_info['rating'] === 'Improvable') {
                    $result['status'] = 'warning';
                    $result['details'][] = "⚠️ La calificación de seguridad SSL/TLS puede mejorarse";
                } else {
                    $result['status'] = 'passed';
                }
            } else {
                $result['status'] = 'passed';
            }
        } else {
            $result['status'] = 'warning';
            $result['details'][] = "No se pudo obtener información detallada de la conexión SSL";
        }
        
        return $result;
    }
    
    /**
     * Verifica el historial de latencias
     * 
     * @return array Resultado de la verificación
     */
    private function checkLatencyHistory(): array {
        $result = [
            'name' => 'Historial de Latencias',
            'description' => 'Análisis del historial de latencias registradas',
            'status' => 'pending',
            'details' => []
        ];
        
        // Verificar si el método existe
        if (!method_exists($this->api_connector, 'getTimeoutStats')) {
            $result['status'] = 'warning';
            $result['details'][] = 'No se puede acceder a las estadísticas del sistema de timeouts';
            return $result;
        }
        
        $timeout_stats = $this->api_connector->getTimeoutStats();
        
        if (!isset($timeout_stats['history']) || empty($timeout_stats['history'])) {
            $result['status'] = 'warning';
            $result['details'][] = 'No hay historial de latencias disponible';
            return $result;
        }
        
        $history = $timeout_stats['history'];
        $result['details'][] = "Días registrados: " . $history['days'];
        $result['details'][] = "Total de solicitudes: " . $history['summary']['total_requests'];
        
        if (isset($history['last_recorded'])) {
            $result['details'][] = "Última fecha registrada: " . $history['last_recorded'];
        }
        
        // Analizar tendencia si hay suficientes datos
        if ($history['days'] >= 7) {
            $result['details'][] = "Hay suficientes datos para analizar tendencias";
            $result['status'] = 'passed';
        } else {
            $result['details'][] = "No hay suficientes datos para analizar tendencias (menos de 7 días)";
            $result['status'] = 'warning';
        }
        
        return $result;
    }
    
    /**
     * Genera un informe HTML con los resultados diagnósticos
     * 
     * @param array $results Resultados del diagnóstico
     * @return string HTML del informe
     */
    public function generateHTMLReport(array $results): string {
        ob_start();
        ?>
        <div class="miapi-ssl-report">
            <h2>Informe de Diagnóstico SSL</h2>
            <p><strong>Fecha:</strong> <?php echo esc_html($results['date']); ?></p>
            
            <div class="miapi-ssl-summary">
                <h3>Resumen</h3>
                <div class="miapi-ssl-summary-stats">
                    <div class="miapi-ssl-stat passed">
                        <span class="miapi-ssl-stat-value"><?php echo esc_html($results['summary']['passed']); ?></span>
                        <span class="miapi-ssl-stat-label">Correctos</span>
                    </div>
                    <div class="miapi-ssl-stat warning">
                        <span class="miapi-ssl-stat-value"><?php echo esc_html($results['summary']['warnings']); ?></span>
                        <span class="miapi-ssl-stat-label">Advertencias</span>
                    </div>
                    <div class="miapi-ssl-stat failed">
                        <span class="miapi-ssl-stat-value"><?php echo esc_html($results['summary']['failed']); ?></span>
                        <span class="miapi-ssl-stat-label">Fallidos</span>
                    </div>
                    <div class="miapi-ssl-stat total">
                        <span class="miapi-ssl-stat-value"><?php echo esc_html($results['summary']['success_rate']); ?>%</span>
                        <span class="miapi-ssl-stat-label">Tasa de éxito</span>
                    </div>
                </div>
            </div>
            
            <div class="miapi-ssl-tests">
                <?php foreach ($results['tests'] as $test_id => $test): ?>
                <div class="miapi-ssl-test miapi-ssl-test-<?php echo esc_attr($test['status']); ?>">
                    <h4>
                        <span class="miapi-ssl-test-icon">
                            <?php if ($test['status'] === 'passed'): ?>✅<?php 
                                  elseif ($test['status'] === 'warning'): ?>⚠️<?php 
                                  elseif ($test['status'] === 'failed'): ?>❌<?php 
                                  else: ?>❓<?php endif; ?>
                        </span>
                        <?php echo esc_html($test['name']); ?>
                    </h4>
                    <div class="miapi-ssl-test-description"><?php echo esc_html($test['description']); ?></div>
                    <div class="miapi-ssl-test-details">
                        <ul>
                            <?php foreach ($test['details'] as $detail): ?>
                                <li><?php echo esc_html($detail); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <div class="miapi-ssl-actions">
                <h3>Acciones recomendadas</h3>
                <ul>
                    <?php if ($results['summary']['failed'] > 0): ?>
                        <li>❌ <strong>Corregir los errores críticos</strong> mostrados en rojo antes de continuar.</li>
                    <?php endif; ?>
                    
                    <?php if ($results['summary']['warnings'] > 0): ?>
                        <li>⚠️ <strong>Revisar las advertencias</strong> mostradas en amarillo para mejorar el sistema.</li>
                    <?php endif; ?>
                    
                    <?php if (isset($results['tests']['ca_bundle']) && $results['tests']['ca_bundle']['status'] !== 'passed'): ?>
                        <li>Verificar que el bundle de certificados CA esté correctamente instalado y actualizado.</li>
                    <?php endif; ?>
                    
                    <?php if (isset($results['tests']['ssl_config']) && $results['tests']['ssl_config']['status'] !== 'passed'): ?>
                        <li>Revisar y corregir la configuración SSL para garantizar conexiones seguras.</li>
                    <?php endif; ?>
                    
                    <?php if (isset($results['tests']['certificate_rotation']) && $results['tests']['certificate_rotation']['status'] !== 'passed'): ?>
                        <li>Verificar el sistema de rotación de certificados y programar una rotación si es necesario.</li>
                    <?php endif; ?>
                    
                    <?php if ($results['summary']['success_rate'] === 100): ?>
                        <li>✅ <strong>¡Felicidades!</strong> El sistema SSL está correctamente configurado.</li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        <style>
            .miapi-ssl-report {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                max-width: 900px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            }
            .miapi-ssl-summary-stats {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
                margin: 20px 0;
            }
            .miapi-ssl-stat {
                background: #f7f7f7;
                border-radius: 4px;
                padding: 15px;
                text-align: center;
                min-width: 100px;
                flex: 1;
            }
            .miapi-ssl-stat-value {
                font-size: 24px;
                font-weight: bold;
                display: block;
            }
            .miapi-ssl-stat.passed { background: #e7f5ea; }
            .miapi-ssl-stat.warning { background: #fff8e5; }
            .miapi-ssl-stat.failed { background: #fbeaea; }
            .miapi-ssl-stat.total { background: #e8f0fe; }
            
            .miapi-ssl-test {
                margin-bottom: 20px;
                padding: 15px;
                border-left: 5px solid #ddd;
                background: #f9f9f9;
            }
            .miapi-ssl-test h4 {
                margin-top: 0;
                display: flex;
                align-items: center;
            }
            .miapi-ssl-test-icon {
                margin-right: 10px;
                font-size: 1.2em;
            }
            .miapi-ssl-test-passed { border-left-color: #46b450; }
            .miapi-ssl-test-warning { border-left-color: #ffb900; background: #fff8e5; }
            .miapi-ssl-test-failed { border-left-color: #dc3232; background: #fbeaea; }
            
            .miapi-ssl-test-details {
                margin-top: 10px;
                font-size: 0.9em;
            }
            .miapi-ssl-test-details ul {
                margin: 0;
                padding-left: 20px;
            }
            .miapi-ssl-actions {
                margin-top: 30px;
                background: #e8f0fe;
                padding: 15px;
                border-radius: 4px;
            }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Ejecuta un diagnóstico y genera un informe HTML
     * 
     * @return string HTML del informe
     */
    public function runDiagnosticReport(): string {
        $results = $this->runDiagnostics();
        return $this->generateHTMLReport($results);
    }
    
    /**
     * Exporta los resultados del diagnóstico a JSON
     * 
     * @return string JSON con los resultados
     */
    public function exportDiagnosticsToJSON(): string {
        $results = $this->runDiagnostics();
        return json_encode($results, JSON_PRETTY_PRINT);
    }
}
