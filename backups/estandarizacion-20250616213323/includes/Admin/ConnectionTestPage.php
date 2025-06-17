<?php
/**
 * Página de prueba de conexión para Verial y WooCommerce
 *
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Tests\ConnectionTest;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar la página de prueba de conexión
 */
class ConnectionTestPage {

    /**
     * Inicializar la página
     */
    public static function init() {
        // add_action('admin_menu', [__CLASS__, 'register_submenu']); // Eliminado para evitar menús duplicados
        add_action('wp_ajax_mi_run_connection_test', [__CLASS__, 'ajax_run_connection_test']);
    }

    /**
     * Registrar en el submenu
     */
    public static function register_submenu() {
        // add_submenu_page(
        //     'mi-integracion-api-dashboard',
        //     __('Prueba de Conexión', 'mi-integracion-api'),
        //     __('Prueba de Conexión', 'mi-integracion-api'),
        //     'manage_options',
        //     'mi-integracion-api-connection-test',
        //     [__CLASS__, 'render_page']
        // );
    }

    /**
     * Renderizar la página
     */
    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('No tienes permisos suficientes para acceder a esta página.', 'mi-integracion-api'));
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="card">
                <h2><?php esc_html_e('Prueba de conexión de Mi Integración API', 'mi-integracion-api'); ?></h2>
                <p><?php esc_html_e('Esta herramienta ejecutará una prueba completa de la conectividad entre WordPress, Verial y WooCommerce para verificar que todas las integraciones estén funcionando correctamente.', 'mi-integracion-api'); ?></p>
                
                <div id="mi-connection-test-loading" style="display:none;">
                    <p><span class="spinner is-active"></span> <?php esc_html_e('Ejecutando prueba de conexión. Por favor espere...', 'mi-integracion-api'); ?></p>
                </div>
                
                <div id="mi-connection-test-controls">
                    <button id="mi-run-connection-test" class="button button-primary">
                        <?php esc_html_e('Ejecutar prueba de conexión', 'mi-integracion-api'); ?>
                    </button>
                </div>
                
                <div id="mi-connection-test-results" style="display:none;"></div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#mi-run-connection-test').on('click', function() {
                $('#mi-connection-test-controls').hide();
                $('#mi-connection-test-loading').show();
                $('#mi-connection-test-results').hide().empty();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mi_run_connection_test',
                        security: '<?php echo wp_create_nonce('mi_connection_test_nonce'); ?>'
                    },
                    success: function(response) {
                        $('#mi-connection-test-loading').hide();
                        $('#mi-connection-test-controls').show();
                        
                        if (response.success) {
                            $('#mi-connection-test-results').html(response.data).show();
                        } else {
                            $('#mi-connection-test-results').html(
                                '<div class="notice notice-error"><p>' + response.data + '</p></div>'
                            ).show();
                        }
                    },
                    error: function() {
                        $('#mi-connection-test-loading').hide();
                        $('#mi-connection-test-controls').show();
                        $('#mi-connection-test-results').html(
                            '<div class="notice notice-error"><p><?php esc_html_e('Error al procesar la solicitud. Por favor, inténtalo de nuevo.', 'mi-integracion-api'); ?></p></div>'
                        ).show();
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Procesar la solicitud AJAX para ejecutar la prueba de conexión
     */
    public static function ajax_run_connection_test() {
        // Verificar nonce
        check_ajax_referer('mi_connection_test_nonce', 'security');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api'));
        }

        // Cargar la clase de prueba de conexión
        if (!class_exists('\MiIntegracionApi\Tests\ConnectionTest')) {
            require_once ABSPATH . 'wp-content/plugins/mi-integracion-api/tests/VerialWooCommerceConnectionTest.php';
        }

        try {
            // Inicializar el probador de conexión
            $tester = new \MiIntegracionApi\Tests\ConnectionTest();
            
            // Ejecutar la prueba
            $results = $tester->runAllTests();
            
            // Generar informe HTML
            $report = $tester->generateHtmlReport();
            
            // Guardar el informe en un archivo temporal
            $upload_dir = wp_upload_dir();
            $report_file = $upload_dir['basedir'] . '/mi-integracion-api-reports/connection-test-' . date('Y-m-d-H-i-s') . '.html';
            
            // Asegurarse de que el directorio existe
            wp_mkdir_p(dirname($report_file));
            
            // Escribir el archivo
            file_put_contents($report_file, $report);
            
            // Obtener URL del archivo
            $report_url = $upload_dir['baseurl'] . '/mi-integracion-api-reports/' . basename($report_file);
            
            // Añadir enlace al informe
            $html = $report;
            $html .= '<p class="mi-test-download-link">';
            $html .= '<a href="' . esc_url($report_url) . '" target="_blank" class="button">';
            $html .= __('Descargar informe completo', 'mi-integracion-api');
            $html .= '</a>';
            $html .= '</p>';
            
            wp_send_json_success($html);
        } catch (\Exception $e) {
            wp_send_json_error(__('Error al ejecutar la prueba de conexión: ', 'mi-integracion-api') . $e->getMessage());
        }
    }
}

// Inicializar la página
Connection_Test_Page::init();
