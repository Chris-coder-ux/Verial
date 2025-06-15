<?php
/**
 * Plantilla para la página de configuración
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 * @since      1.0.0
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Obtener opciones guardadas
$options = get_option( 'mi_integracion_api_ajustes', array() );
$url_base = $options['mia_url_base'] ?? '';
$numero_sesion = $options['mia_numero_sesion'] ?? '';
$timeout = $options['mia_timeout'] ?? 30;
$enabled_modules = $options['mia_enabled_modules'] ?? array();

// Verificar si hay mensajes de error o éxito
$message = isset( $_GET['message'] ) ? sanitize_text_field( $_GET['message'] ) : '';
$error = isset( $_GET['error'] ) ? sanitize_text_field( $_GET['error'] ) : '';

// Renderizar cabecera
$this->render_header();
?>

<div class="wrap mi-integracion-api-settings">
    <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
    
    <?php if ( $message === 'saved' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Configuración guardada correctamente.', 'mi-integracion-api' ); ?></p>
        </div>
    <?php endif; ?>
    
    <?php if ( $error ) : ?>
        <div class="notice notice-error is-dismissible">
            <p><?php echo esc_html( $error ); ?></p>
        </div>
    <?php endif; ?>
    
    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'mi_integracion_api_save_settings', 'mi_integracion_api_settings_nonce' ); ?>
        <input type="hidden" name="action" value="mi_integracion_api_save_settings">
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mia_url_base"><?php esc_html_e( 'URL Base de Verial', 'mi-integracion-api' ); ?></label>
                    </th>
                    <td>
                        <input name="mia_url_base" type="url" id="mia_url_base" value="<?php echo esc_attr( $url_base ); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e( 'La URL base para conectarse a la API de Verial.', 'mi-integracion-api' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mia_numero_sesion"><?php esc_html_e( 'Número de Sesión', 'mi-integracion-api' ); ?></label>
                    </th>
                    <td>
                        <input name="mia_numero_sesion" type="text" id="mia_numero_sesion" value="<?php echo esc_attr( $numero_sesion ); ?>" class="regular-text" required>
                        <p class="description"><?php esc_html_e( 'El número de sesión para la API de Verial (sesionwcf).', 'mi-integracion-api' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mia_timeout"><?php esc_html_e( 'Timeout (segundos)', 'mi-integracion-api' ); ?></label>
                    </th>
                    <td>
                        <input name="mia_timeout" type="number" id="mia_timeout" value="<?php echo esc_attr( $timeout ); ?>" class="small-text" min="1" max="120">
                        <p class="description"><?php esc_html_e( 'Tiempo máximo de espera para las solicitudes a la API (en segundos).', 'mi-integracion-api' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Módulos Habilitados', 'mi-integracion-api' ); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e( 'Módulos Habilitados', 'mi-integracion-api' ); ?></legend>
                            
                            <?php 
                            $module_list = array(
                                'products' => array('name' => __('Sincronización de Productos', 'mi-integracion-api')),
                                'categories' => array('name' => __('Sincronización de Categorías', 'mi-integracion-api')),
                                'orders' => array('name' => __('Sincronización de Pedidos', 'mi-integracion-api')),
                                'customers' => array('name' => __('Sincronización de Clientes', 'mi-integracion-api')),
                                'stock' => array('name' => __('Sincronización de Stock', 'mi-integracion-api'))
                            );
                            
                            foreach ( $module_list as $module_id => $module_data ) : ?>
                                <label for="mia_enabled_modules_<?php echo esc_attr( $module_id ); ?>">
                                    <input name="mia_enabled_modules[]" type="checkbox" id="mia_enabled_modules_<?php echo esc_attr( $module_id ); ?>" value="<?php echo esc_attr( $module_id ); ?>" <?php checked( in_array( $module_id, $enabled_modules ) ); ?>>
                                    <?php echo esc_html( $module_data['name'] ?? $module_id ); ?>
                                </label>
                                <br>
                            <?php endforeach; ?>
                            
                            <p class="description"><?php esc_html_e( 'Selecciona los módulos que deseas habilitar.', 'mi-integracion-api' ); ?></p>
                        </fieldset>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <h2><?php esc_html_e( 'Configuración Avanzada', 'mi-integracion-api' ); ?></h2>
        
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row">
                        <label for="mia_ssl_verify"><?php esc_html_e( 'Verificación SSL', 'mi-integracion-api' ); ?></label>
                    </th>
                    <td>
                        <select name="mia_ssl_verify" id="mia_ssl_verify">
                            <option value="1" <?php selected( $options['mia_ssl_verify'] ?? '1', '1' ); ?>><?php esc_html_e( 'Habilitada (recomendado)', 'mi-integracion-api' ); ?></option>
                            <option value="0" <?php selected( $options['mia_ssl_verify'] ?? '1', '0' ); ?>><?php esc_html_e( 'Deshabilitada (solo para depuración)', 'mi-integracion-api' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Deshabilita solo si tienes problemas con certificados SSL. No recomendado para producción.', 'mi-integracion-api' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="mia_log_level"><?php esc_html_e( 'Nivel de Logs', 'mi-integracion-api' ); ?></label>
                    </th>
                    <td>
                        <select name="mia_log_level" id="mia_log_level">
                            <option value="error" <?php selected( $options['mia_log_level'] ?? 'info', 'error' ); ?>><?php esc_html_e( 'Solo errores', 'mi-integracion-api' ); ?></option>
                            <option value="warning" <?php selected( $options['mia_log_level'] ?? 'info', 'warning' ); ?>><?php esc_html_e( 'Advertencias y errores', 'mi-integracion-api' ); ?></option>
                            <option value="info" <?php selected( $options['mia_log_level'] ?? 'info', 'info' ); ?>><?php esc_html_e( 'Información general (por defecto)', 'mi-integracion-api' ); ?></option>
                            <option value="debug" <?php selected( $options['mia_log_level'] ?? 'info', 'debug' ); ?>><?php esc_html_e( 'Depuración detallada', 'mi-integracion-api' ); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e( 'Define cuánta información se guarda en los logs.', 'mi-integracion-api' ); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>
        
        <?php submit_button( __( 'Guardar Configuración', 'mi-integracion-api' ) ); ?>
    </form>
</div>

<?php $this->render_footer(); ?>
