<?php
/**
 * Herramienta de diagnóstico de conexión API Verial
 * 
 * Esta herramienta ayuda a verificar la conexión con la API de Verial
 * y muestra información detallada para solucionar problemas de conexión.
 * 
 * @package Mi_Integracion_Api
 */

// Seguridad
if (!defined('ABSPATH')) {
    exit;
}

// Cargar dependencias
require_once dirname(__FILE__, 2) . '/includes/Core/ApiConnector.php';

// Verificar permisos
if (!current_user_can('manage_options')) {
    wp_die(__('No tienes permisos para acceder a esta página.', 'mi-integracion-api'));
}

// Obtener el conector de API
$api = MiIntegracionApi\Helpers\ApiHelpers::get_api_connector();

// Función para probar una URL y mostrar el resultado
function test_api_url($url, $params = []) {
    $response = wp_remote_get(
        add_query_arg($params, $url), 
        [
            'timeout' => 30,
            'sslverify' => false
        ]
    );
    
    $result = [
        'url' => $url,
        'params' => $params,
        'status' => is_wp_error($response) ? 'error' : wp_remote_retrieve_response_code($response),
        'body' => is_wp_error($response) ? $response->get_error_message() : wp_remote_retrieve_body($response),
        'time' => time(),
    ];
    
    return $result;
}

// Realizar pruebas cuando se solicita
$test_results = [];
$show_results = false;

if (isset($_POST['test_api_connection']) && check_admin_referer('mi_integracion_api_test_connection')) {
    $show_results = true;
    
    // Obtener datos de configuración
    $api_url = $api->get_api_base_url();
    $session = $api->getSesionWcf();
    
    // Test 1: URL base sin parámetros
    $test_results[] = test_api_url($api_url);
    
    // Test 2: Endpoint GetVersionWS (según documentación de Postman)
    $test_results[] = test_api_url($api_url . '/GetVersionWS', ['x' => $session]);
    
    // Test 3: Endpoint GetArticulosWS con parámetros completos
    $test_results[] = test_api_url($api_url . '/GetArticulosWS', [
        'x' => $session,
        'pagina' => 1,
        'cantidad' => 5
    ]);
}

// Interfaz de usuario
?>
<div class="wrap">
    <h1><?php echo esc_html(__('Diagnóstico de Conexión API Verial', 'mi-integracion-api')); ?></h1>
    
    <div class="notice notice-info">
        <p>
            <?php _e('Esta herramienta te ayudará a diagnosticar problemas de conexión con la API de Verial.', 'mi-integracion-api'); ?>
            <?php _e('Usa esta página cuando tengas errores 404 u otros problemas de conexión.', 'mi-integracion-api'); ?>
        </p>
    </div>
    
    <h2><?php _e('Configuración Actual', 'mi-integracion-api'); ?></h2>
    <table class="widefat fixed" cellspacing="0">
        <tbody>
            <tr>
                <th scope="row"><?php _e('URL Base de API', 'mi-integracion-api'); ?></th>
                <td><?php echo esc_html($api->get_api_base_url()); ?></td>
            </tr>
            <tr>
                <th scope="row"><?php _e('Sesión WCF', 'mi-integracion-api'); ?></th>
                <td><?php echo esc_html($api->getSesionWcf() ?: __('No configurado', 'mi-integracion-api')); ?></td>
            </tr>
        </tbody>
    </table>
    
    <form method="post" action="">
        <?php wp_nonce_field('mi_integracion_api_test_connection'); ?>
        <p class="submit">
            <input type="submit" name="test_api_connection" class="button button-primary" value="<?php _e('Probar Conexión', 'mi-integracion-api'); ?>">
        </p>
    </form>
    
    <?php if ($show_results): ?>
    <h2><?php _e('Resultados de Prueba', 'mi-integracion-api'); ?></h2>
    
    <?php foreach ($test_results as $index => $result): ?>
    <div class="card">
        <h3><?php printf(__('Prueba %d: %s', 'mi-integracion-api'), $index + 1, esc_html($result['url'])); ?></h3>
        <table class="widefat striped" cellspacing="0">
            <tbody>
                <tr>
                    <th><?php _e('URL', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($result['url']); ?></td>
                </tr>
                <?php if (!empty($result['params'])): ?>
                <tr>
                    <th><?php _e('Parámetros', 'mi-integracion-api'); ?></th>
                    <td><pre><?php echo esc_html(json_encode($result['params'], JSON_PRETTY_PRINT)); ?></pre></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php _e('Estado', 'mi-integracion-api'); ?></th>
                    <td>
                        <span class="<?php echo $result['status'] == 200 ? 'status-ok' : 'status-error'; ?>">
                            <?php echo esc_html($result['status']); ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th><?php _e('Respuesta', 'mi-integracion-api'); ?></th>
                    <td>
                        <div style="max-height: 300px; overflow: auto;">
                            <pre><?php 
                                // Intentar formatear JSON si es posible
                                $json = json_decode($result['body']);
                                if (json_last_error() === JSON_ERROR_NONE) {
                                    echo esc_html(json_encode($json, JSON_PRETTY_PRINT));
                                } else {
                                    echo esc_html(substr($result['body'], 0, 1000) . (strlen($result['body']) > 1000 ? '...' : ''));
                                }
                            ?></pre>
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    
    <style>
        .status-ok { color: green; font-weight: bold; }
        .status-error { color: red; font-weight: bold; }
        .card { background: white; padding: 15px; margin: 15px 0; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); }
        pre { white-space: pre-wrap; word-wrap: break-word; }
    </style>
    <?php endif; ?>
</div>
