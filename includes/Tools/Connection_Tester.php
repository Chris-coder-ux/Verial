<?php
/**
 * Clase para integrar las pruebas de conexión con el plugin principal
 * 
 * Esta clase maneja la ejecución de pruebas desde el panel de administración de WordPress
 * y expone los resultados para ser mostrados en el panel.
 * 
 * @package     MiIntegracionApi
 * @subpackage  Tools
 */

namespace MiIntegracionApi\Tools;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class Connection_Tester {
    
    /**
     * Directorio base del plugin
     * 
     * @var string
     */
    private $plugin_dir;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->plugin_dir = dirname(dirname(dirname(__FILE__)));
        
        // Inicializar hooks
        add_action('admin_init', array($this, 'handle_test_actions'));
    }
    
    /**
     * Manejar acciones de prueba desde el panel de administración
     */
    public function handle_test_actions() {
        if (!isset($_GET['page']) || $_GET['page'] !== 'mi-integracion-api-test') {
            return;
        }
        
        if (isset($_POST['run_test']) && check_admin_referer('run_verial_test')) {
            $test_type = sanitize_text_field($_POST['test_type']);
            $this->run_test($test_type);
        }
    }
    
    /**
     * Ejecutar prueba según el tipo seleccionado
     * 
     * @param string $test_type El tipo de prueba a ejecutar
     * @return bool|string True si la prueba se ejecutó correctamente, mensaje de error si falló
     */
    public function run_test($test_type) {
        // Verificar permisos
        if (!current_user_can('manage_woocommerce')) {
            return new \WP_Error('permission', __('No tienes permisos para ejecutar pruebas.', 'mi-integracion-api'));
        }
        
        switch ($test_type) {
            case 'connection':
                return $this->run_connection_test();
                
            case 'product_sync':
                return $this->run_product_sync_test();
                
            case 'all':
                $conn_result = $this->run_connection_test();
                $prod_result = $this->run_product_sync_test();
                
                if ($conn_result === true && $prod_result === true) {
                    return true;
                } else {
                    return sprintf(
                        __('Errores en las pruebas: %s', 'mi-integracion-api'),
                        ($conn_result !== true ? $conn_result : '') . 
                        ($prod_result !== true ? ' ' . $prod_result : '')
                    );
                }
                
            default:
                return new \WP_Error('invalid_test', __('Tipo de prueba no válido.', 'mi-integracion-api'));
        }
    }
    
    /**
     * Ejecuta la prueba de conexión
     * 
     * @return bool|string True si la prueba se ejecutó correctamente, mensaje de error si falló
     */
    public function run_connection_test() {
        try {
            $test_file = $this->plugin_dir . '/connection-test-advanced.php';
            $report_file = $this->plugin_dir . '/generate-report.php';
            
            if (!file_exists($test_file) || !file_exists($report_file)) {
                return __('No se encontraron los scripts de prueba necesarios.', 'mi-integracion-api');
            }
            
            // Configurar entorno para PHP CLI
            $env = [];
            
            // Preparar el entorno para PHP para que pueda acceder a WordPress si es necesario
            if (defined('ABSPATH')) {
                $env['WP_PATH'] = ABSPATH;
            }
            
            // Ejecutar la prueba de conexión
            $output = $this->execute_php_script($test_file, $env);
            
            if ($output === false) {
                return __('Error al ejecutar la prueba de conexión.', 'mi-integracion-api');
            }
            
            // Generar el informe HTML
            $report_output = $this->execute_php_script($report_file, $env);
            
            if ($report_output === false) {
                return __('Error al generar el informe de conexión.', 'mi-integracion-api');
            }
            
            // Guardar una copia en el directorio de resultados
            $results_dir = $this->plugin_dir . '/test-results';
            if (!is_dir($results_dir)) {
                wp_mkdir_p($results_dir);
            }
            
            $date_suffix = date('Y-m-d-H-i-s');
            $result_file = $this->plugin_dir . '/connection-test-results.txt';
            $report_html = $this->plugin_dir . '/connection-test-report.html';
            
            if (file_exists($result_file)) {
                copy($result_file, $results_dir . '/connection-test-' . $date_suffix . '.txt');
            }
            
            if (file_exists($report_html)) {
                copy($report_html, $results_dir . '/connection-test-' . $date_suffix . '.html');
            }
            
            return true;
            
        } catch (\Exception $e) {
            return sprintf(
                __('Error al ejecutar la prueba de conexión: %s', 'mi-integracion-api'),
                $e->getMessage()
            );
        }
    }
    
    /**
     * Ejecuta la prueba de sincronización de productos
     * 
     * @return bool|string True si la prueba se ejecutó correctamente, mensaje de error si falló
     */
    public function run_product_sync_test() {
        try {
            $test_file = $this->plugin_dir . '/product-sync-test.php';
            
            if (!file_exists($test_file)) {
                return __('No se encontró el script de prueba de sincronización de productos.', 'mi-integracion-api');
            }
            
            // Configurar entorno para PHP CLI
            $env = [];
            
            // Preparar el entorno para PHP para que pueda acceder a WordPress
            if (defined('ABSPATH')) {
                $env['WP_PATH'] = ABSPATH;
            }
            
            // Ejecutar la prueba de sincronización de productos
            $output = $this->execute_php_script($test_file, $env);
            
            if ($output === false) {
                return __('Error al ejecutar la prueba de sincronización de productos.', 'mi-integracion-api');
            }
            
            // Guardar una copia en el directorio de resultados
            $results_dir = $this->plugin_dir . '/test-results';
            if (!is_dir($results_dir)) {
                wp_mkdir_p($results_dir);
            }
            
            $date_suffix = date('Y-m-d-H-i-s');
            $result_file = $this->plugin_dir . '/product-sync-test-results.txt';
            $result_json = $this->plugin_dir . '/product-sync-results.json';
            
            if (file_exists($result_file)) {
                copy($result_file, $results_dir . '/product-sync-' . $date_suffix . '.txt');
            }
            
            if (file_exists($result_json)) {
                copy($result_json, $results_dir . '/product-sync-' . $date_suffix . '.json');
            }
            
            return true;
            
        } catch (\Exception $e) {
            return sprintf(
                __('Error al ejecutar la prueba de sincronización de productos: %s', 'mi-integracion-api'),
                $e->getMessage()
            );
        }
    }
    
    /**
     * Ejecuta un script PHP
     * 
     * @param string $script_path Ruta al script PHP
     * @param array $env Variables de entorno adicionales
     * @return string|bool Salida del script o false si hay un error
     */
    private function execute_php_script($script_path, $env = []) {
        // Verificar si exec está disponible
        if (!function_exists('exec')) {
            // Si exec no está disponible, intentar con include
            ob_start();
            include_once $script_path;
            $output = ob_get_clean();
            return $output;
        }
        
        // Preparar el comando
        $cmd = 'php ' . escapeshellarg($script_path) . ' 2>&1';
        
        // Preparar variables de entorno
        $env_str = '';
        foreach ($env as $key => $value) {
            $env_str .= sprintf('%s=%s ', escapeshellarg($key), escapeshellarg($value));
        }
        
        // Ejecutar el comando
        $output = null;
        $return_var = null;
        exec($env_str . $cmd, $output, $return_var);
        
        if ($return_var !== 0) {
            error_log('Error al ejecutar el script: ' . $script_path . '. Código: ' . $return_var);
            error_log('Salida: ' . implode("\n", $output));
            return false;
        }
        
        return implode("\n", $output);
    }
    
    /**
     * Obtiene el último informe de conexión generado
     * 
     * @return array|bool Datos del informe o false si no hay informes
     */
    public function get_last_connection_report() {
        $report_html = $this->plugin_dir . '/connection-test-report.html';
        $result_file = $this->plugin_dir . '/connection-test-results.txt';
        
        $data = [
            'timestamp' => null,
            'html_path' => null,
            'text_path' => null,
            'has_report' => false,
            'connection_success' => false,
            'wc_success' => false,
            'integration_success' => false
        ];
        
        if (file_exists($report_html)) {
            $data['html_path'] = $report_html;
            $data['timestamp'] = filemtime($report_html);
            $data['has_report'] = true;
        }
        
        if (file_exists($result_file)) {
            $data['text_path'] = $result_file;
            
            // Analizar el contenido para extraer el estado
            $content = file_get_contents($result_file);
            $data['connection_success'] = strpos($content, 'Conexión con Verial: EXITOSA') !== false;
            $data['wc_success'] = strpos($content, 'WooCommerce: DISPONIBLE') !== false;
            $data['integration_success'] = strpos($content, 'Integración: VERIFICADA') !== false;
            
            if (!$data['timestamp']) {
                $data['timestamp'] = filemtime($result_file);
                $data['has_report'] = true;
            }
        }
        
        return $data['has_report'] ? $data : false;
    }
    
    /**
     * Obtiene el último informe de sincronización de productos generado
     * 
     * @return array|bool Datos del informe o false si no hay informes
     */
    public function get_last_product_sync_report() {
        $result_json = $this->plugin_dir . '/product-sync-results.json';
        $result_file = $this->plugin_dir . '/product-sync-test-results.txt';
        
        $data = [
            'timestamp' => null,
            'json_path' => null,
            'text_path' => null,
            'has_report' => false,
            'json_data' => null,
            'products_count' => 0,
            'mapping_success' => false
        ];
        
        if (file_exists($result_json)) {
            $data['json_path'] = $result_json;
            $data['timestamp'] = filemtime($result_json);
            $data['has_report'] = true;
            
            // Cargar datos JSON
            $json_content = file_get_contents($result_json);
            $json_data = json_decode($json_content, true);
            
            if ($json_data) {
                $data['json_data'] = $json_data;
                $data['products_count'] = $json_data['verial_products_count'] ?? 0;
                $data['mapping_success'] = $json_data['mapping_success'] ?? false;
            }
        }
        
        if (file_exists($result_file)) {
            $data['text_path'] = $result_file;
            
            if (!$data['timestamp']) {
                $data['timestamp'] = filemtime($result_file);
                $data['has_report'] = true;
            }
        }
        
        return $data['has_report'] ? $data : false;
    }
}
