<?php
/**
 * Herramienta de diagnóstico para el conector API de Verial
 * 
 * Este archivo registra la herramienta de diagnóstico en el panel de administración
 * de WordPress para facilitar la verificación de la conexión con la API de Verial.
 * 
 * @package     mi-integracion-api
 * @subpackage  tools
 * @since 1.0.0
 */

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Cargar clase de diagnóstico si no existe
if (!class_exists('MiIntegracionApi\\Tools\\ApiConnectionChecker')) {
    // Intentar cargar primero la versión Legacy
    $legacy_path = dirname(dirname(__FILE__)) . '/includes/Legacy/Tools/ApiConnectionChecker.php';
    if (file_exists($legacy_path)) {
        require_once $legacy_path;
    } else {
        // Si no existe el adaptador, intentar con la ubicación original
        $api_checker_path = dirname(dirname(__FILE__)) . '/includes/Tools/ApiConnectionChecker.php';
        if (file_exists($api_checker_path)) {
            require_once $api_checker_path;
        } else {
            // Reportar error pero seguir funcionando
            error_log('Mi Integración API: No se pudo cargar ApiConnectionChecker.php');
        }
    }
}

/**
 * Función de inicialización para las herramientas de diagnóstico de la API
 * Comprobamos si la función ya está declarada para evitar errores
 * cuando hay múltiples instancias del plugin
 */
if (!function_exists('mi_integracion_api_init_diagnostic_tools')) {
    function mi_integracion_api_init_diagnostic_tools() {
        // Verificar que estamos en el panel de administración
        if (!is_admin()) {
            return;
        }
        
        // Inicializar el comprobador de conexión
        $checker = new MiIntegracionApi\Tools\ApiConnectionChecker();
        $checker->register_hooks();
    }
}
add_action('init', 'mi_integracion_api_init_diagnostic_tools');

/**
 * Renderiza la página de diagnóstico en el panel de administración
 * Comprobamos si la función ya está declarada para evitar errores
 * cuando hay múltiples instancias del plugin
 */
if (!function_exists('mi_integracion_api_render_diagnostic_page')) {
    function mi_integracion_api_render_diagnostic_page() {
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_die(__('Acceso denegado', 'mi-integracion-api'));
        }
        // No necesitamos verificar nonce de seguridad en la carga inicial de la página
        // Las acciones de diagnóstico específicas deben usar su propio nonce
        // Cargar estilos y scripts necesarios usando la ruta correcta
        if (defined('MiIntegracionApi_PLUGIN_URL')) {
            wp_enqueue_style('mi-integracion-api-admin', MiIntegracionApi_PLUGIN_URL . 'assets/css/admin.css', [], MiIntegracionApi_VERSION);
            wp_enqueue_script('mi-integracion-api-admin-script', MiIntegracionApi_PLUGIN_URL . 'assets/js/admin.js', ['jquery'], MiIntegracionApi_VERSION, true);
        } else {
            // Fallback usando la ruta relativa corregida
            wp_enqueue_style('mi-integracion-api-admin', plugins_url('../assets/css/admin.css', __FILE__));
            wp_enqueue_script('mi-integracion-api-admin-script', plugins_url('../assets/js/admin.js', __FILE__), ['jquery'], null, true);
        }
        // Cargar el conector API
        if (!class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
            require_once dirname(__FILE__) . '/../../includes/Core/ApiConnector.php';
        }
        // Cargar la clase de herramientas de diagnóstico
        if (!class_exists('MiIntegracionApi\\Tools\\ApiConnectionChecker')) {
            require_once dirname(__FILE__) . '/../../includes/Tools/ApiConnectionChecker.php';
        }
    }
}
        
        // Inicializar el conector API para diagnóstico
    $api = new MiIntegracionApi\Core\ApiConnector();
    $checker = new MiIntegracionApi\Tools\ApiConnectionChecker();
    ?>
    <div class="wrap mi-integracion-api-admin">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        
        <div class="api-diagnostic-container">
            <div class="api-diagnostic-section">
                <div class="api-diagnostic-header">
                    <h2 class="api-diagnostic-title">
                        <span class="dashicons dashicons-rest-api"></span>
                        <?php _e('Configuración API', 'mi-integracion-api'); ?>
                    </h2>
                    <?php 
                    $config_check = $api->check_api_config();
                    $status_class = $config_check['is_valid'] ? 'connected' : 'disconnected';
                    ?>
                    <div class="api-diagnostic-status <?php echo esc_attr($status_class); ?>">
                        <span class="dashicons dashicons-<?php echo $config_check['is_valid'] ? 'yes' : 'no'; ?>"></span>
                        <?php echo $config_check['is_valid'] ? __('Conectado', 'mi-integracion-api') : __('Desconectado', 'mi-integracion-api'); ?>
                    </div>
                </div>
                
                <div class="api-diagnostic-grid">
                    <div class="api-diagnostic-item">
                        <div class="api-diagnostic-label"><?php _e('URL Base', 'mi-integracion-api'); ?></div>
                        <div class="api-diagnostic-value"><?php echo esc_html($api->get_api_base_url()); ?></div>
                    </div>
                    
                    <div class="api-diagnostic-item">
                        <div class="api-diagnostic-label"><?php _e('Número de Sesión', 'mi-integracion-api'); ?></div>
                        <div class="api-diagnostic-value">
                            <?php 
                            $numero_sesion = $api->get_numero_sesion(); 
                            if (empty($numero_sesion)) {
                                echo '<span class="status-badge status-error">' . __('No configurado', 'mi-integracion-api') . '</span>';
                            } else {
                                echo esc_html($numero_sesion);
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="api-diagnostic-item">
                        <div class="api-diagnostic-label"><?php _e('CORS', 'mi-integracion-api'); ?></div>
                        <div class="api-diagnostic-value">
                            <?php 
                            if (defined('MIA_ENABLE_CORS') && MIA_ENABLE_CORS) {
                                echo '<span class="status-badge status-success">' . __('Habilitado', 'mi-integracion-api') . '</span>';
                            } else {
                                echo '<span class="status-badge status-warning">' . __('Deshabilitado', 'mi-integracion-api') . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="api-diagnostic-item">
                        <div class="api-diagnostic-label"><?php _e('Autenticación API Key', 'mi-integracion-api'); ?></div>
                        <div class="api-diagnostic-value">
                            <?php 
                            if (defined('MIA_USE_API_CREDENTIALS') && MIA_USE_API_CREDENTIALS) {
                                $api_key = $api->get_api_key();
                                $api_secret = $api->get_api_secret();
                                
                                if (!empty($api_key) && !empty($api_secret)) {
                                    echo '<span class="status-badge status-success">' . __('Configurada', 'mi-integracion-api') . '</span>';
                                } else {
                                    echo '<span class="status-badge status-error">' . __('Incompleta', 'mi-integracion-api') . '</span>';
                                }
                            } else {
                                echo '<span class="status-badge status-warning">' . __('No habilitada', 'mi-integracion-api') . '</span>';
                            }
                            ?>
                        </div>
                    </div>
                </div>
                
                <?php if (!$config_check['is_valid']): ?>
                <div class="api-diagnostic-log">
                    <div class="error">
                        <?php foreach ($config_check['errors'] as $error): ?>
                            <div>✗ <?php echo is_array($error) ? esc_html($error['message']) : esc_html($error); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="api-diagnostic-actions">
                    <button type="button" id="mi-check-connection" class="api-diagnostic-button primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php _e('Probar Conexión', 'mi-integracion-api'); ?>
                    </button>
                    
                    <?php if (defined('MIA_USE_API_CREDENTIALS') && MIA_USE_API_CREDENTIALS): ?>
                    <button type="button" id="mi-check-api-auth" class="api-diagnostic-button secondary">
                        <span class="dashicons dashicons-lock"></span>
                        <?php _e('Verificar Autenticación', 'mi-integracion-api'); ?>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
    jQuery(document).ready(function($) {
        // Verificar versión
        $('#mi-check-version').on('click', function() {
            var $button = $(this);
            var $result = $('#mi-version-result');
            
            $button.prop('disabled', true);
            $result.html('<div class="notice notice-info"><p><?php echo esc_js(__('Verificando versión...', 'mi-integracion-api')); ?></p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mi_check_api_connection',
                    security: '<?php echo wp_create_nonce('mi_api_connection_check'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + 
                            response.data.message + ' ' +
                            '<?php echo esc_js(__('Versión:', 'mi-integracion-api')); ?> ' + response.data.version + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + 
                            response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p>' + 
                        'Error al procesar la solicitud. Por favor, inténtalo de nuevo.' +
                        '</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
        
        // Verificar autenticación API Key
        if ($('#mi-check-api-auth').length) {
            $('#mi-check-api-auth').on('click', function() {
                var $button = $(this);
                var $result = $('#mi-api-auth-result');
                
                $button.prop('disabled', true);
                $result.html('<div class="notice notice-info"><p><?php echo esc_js(__('Verificando autenticación API Key...', 'mi-integracion-api')); ?></p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mi_check_api_auth',
                        security: '<?php echo wp_create_nonce('mi_api_auth_check'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + 
                                response.data.message + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error"><p>' + 
                                response.data.message + '</p></div>');
                        }
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error"><p>' + 
                            'Error al procesar la solicitud. Por favor, inténtalo de nuevo.' +
                            '</p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false);
                    }
                });
            });
        }
        
        // Limpiar caché
        $('#mi-clear-cache').on('click', function() {
            var $button = $(this);
            var $result = $('#mi-cache-result');
            
            $button.prop('disabled', true);
            $result.html('<div class="notice notice-info"><p><?php echo esc_js(__('Limpiando caché...', 'mi-integracion-api')); ?></p></div>');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'mi_clear_api_cache',
                    security: '<?php echo wp_create_nonce('mi_api_cache_clear'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<div class="notice notice-success"><p>' + 
                            response.data.message + '</p></div>');
                    } else {
                        $result.html('<div class="notice notice-error"><p>' + 
                            response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    $result.html('<div class="notice notice-error"><p>' + 
                        'Error al procesar la solicitud. Por favor, inténtalo de nuevo.' +
                        '</p></div>');
                },
                complete: function() {
                    $button.prop('disabled', false);
                }
            });
        });
    });
    </script>
    <?php


// Añadir AJAX endpoint para limpiar el caché
if (!function_exists('mi_integracion_api_clear_cache_ajax')) {
    function mi_integracion_api_clear_cache_ajax() {
        // Verificar seguridad
        check_ajax_referer('mi_api_cache_clear', 'security');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api')
            ]);
        }
    
        // Intentar limpiar la caché
        $result = [];
        if (class_exists('MI_Cache_Manager')) {
            // Limpiar todo el grupo de caché de API
            $cleared = MI_Cache_Manager::clear_group('api_');
            $result = [
                'success' => $cleared,
                'message' => $cleared 
                    ? __('Caché de API limpiada correctamente.', 'mi-integracion-api')
                    : __('Error al limpiar la caché de API.', 'mi-integracion-api')
            ];
        } else {
            // Si no existe el gestor de caché mejorado, intentar limpiar con transients
            global $wpdb;
            $like = $wpdb->esc_like('_transient_api_') . '%';
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->options WHERE option_name LIKE %s",
                $like
            ));
            
            $result = [
                'success' => ($deleted !== false),
                'message' => ($deleted !== false)
                    ? sprintf(__('Se han limpiado %d elementos de caché.', 'mi-integracion-api'), $deleted)
                    : __('Error al limpiar la caché.', 'mi-integracion-api')
            ];
        }
    
        wp_send_json_success($result);
    }
}
add_action('wp_ajax_mi_clear_api_cache', 'mi_integracion_api_clear_cache_ajax');

// Añadir AJAX endpoint para verificar autenticación API Key
if (!function_exists('mi_integracion_api_check_auth_ajax')) {
    function mi_integracion_api_check_auth_ajax() {
        // Verificar seguridad
        check_ajax_referer('mi_api_auth_check', 'security');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api')
            ]);
        }
    
        // Verificar si la autenticación API Key está habilitada
        if (!defined('MIA_USE_API_CREDENTIALS') || !MIA_USE_API_CREDENTIALS) {
            wp_send_json_error([
                'message' => __('La autenticación API Key no está habilitada. Activa MIA_USE_API_CREDENTIALS en el archivo principal.', 'mi-integracion-api')
            ]);
        }
        
        // Inicializar el conector API
        $api = new MiIntegracionApi\Core\ApiConnector();
        
        // Verificar credenciales
        $api_key = $api->get_api_key();
        $api_secret = $api->get_api_secret();
    
        if (empty($api_key) || empty($api_secret)) {
            wp_send_json_error([
                'message' => __('Credenciales de API incompletas. Verifica API Key y API Secret.', 'mi-integracion-api')
            ]);
        }
        
        // Intentar hacer una solicitud con autenticación API Key
        $response = $api->get_version();
        
        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => __('Error al verificar autenticación: ', 'mi-integracion-api') . $response->get_error_message()
            ]);
        }
    
        // Verificar si la respuesta es válida
        if (!MiIntegracionApi\Core\ApiConnector::is_valid_verial_response($response)) {
            wp_send_json_error([
                'message' => __('La respuesta de la API no tiene el formato esperado.', 'mi-integracion-api')
            ]);
        }
        
        // Comprobar código de resultado
        if (isset($response['Codigo']) && $response['Codigo'] === 0) {
            wp_send_json_success([
                'message' => __('Autenticación API Key exitosa. Credenciales válidas.', 'mi-integracion-api'),
                'response' => $response
            ]);
        } else {
            $error_message = isset($response['Descripcion']) ? $response['Descripcion'] : __('Error desconocido', 'mi-integracion-api');
            wp_send_json_error([
                'message' => __('La API rechazó las credenciales: ', 'mi-integracion-api') . $error_message
            ]);
        }
    }
}
add_action('wp_ajax_mi_check_api_auth', 'mi_integracion_api_check_auth_ajax');
