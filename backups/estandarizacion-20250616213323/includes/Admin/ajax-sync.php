<?php
/**
 * Funciones AJAX para sincronización
 *
 * @package MiIntegracionApi
 * @subpackage Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente
}

/**
 * Crea la tabla de historial de sincronización si no existe
 * 
 * @return void
 */
function mia_crear_tabla_historial() {
    global $wpdb;
    
    $tabla = $wpdb->prefix . 'mi_integracion_api_logs';
    
    // Verificar si la tabla ya existe
    if($wpdb->get_var("SHOW TABLES LIKE '$tabla'") != $tabla) {
        // Crear la tabla de logs
        $sql = "CREATE TABLE $tabla (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            tipo varchar(50) NOT NULL,
            message text NOT NULL,
            details longtext,
            status varchar(20) NOT NULL DEFAULT 'complete',
            elapsed_time float DEFAULT NULL,
            items_processed int DEFAULT 0,
            items_success int DEFAULT 0,
            items_error int DEFAULT 0,
            PRIMARY KEY  (id)
        ) CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

/**
 * Muestra los detalles de una sincronización específica
 * 
 * @param int $sync_id ID de la sincronización
 * @return void
 */
function mia_mostrar_detalles_sincronizacion($sync_id) {
    global $wpdb;
    
    $tabla = $wpdb->prefix . 'mi_integracion_api_logs';
    $registro = $wpdb->get_row($wpdb->prepare("SELECT * FROM $tabla WHERE id = %d", $sync_id));
    
    if (!$registro) {
        echo '<div class="wrap"><div class="notice notice-error"><p>';
        echo esc_html__('No se encontró el registro solicitado.', 'mi-integracion-api');
        echo '</p></div></div>';
        return;
    }
    
    $detalles = json_decode($registro->details, true);
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Detalles de la Sincronización', 'mi-integracion-api'); ?> #<?php echo esc_html($sync_id); ?></h1>
        
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mi-integracion-api-sync-history')); ?>" class="button">
                <?php esc_html_e('← Volver al historial', 'mi-integracion-api'); ?>
            </a>
        </p>
        
        <div class="mi-integracion-api-sync-details">
            <table class="widefat">
                <tr>
                    <th><?php esc_html_e('Fecha/Hora', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->timestamp); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tipo', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->tipo); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Mensaje', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->message); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                    <td>
                        <?php 
                        switch($registro->status) {
                            case 'complete':
                                echo '<span class="sync-status sync-complete">' . esc_html__('Completado', 'mi-integracion-api') . '</span>'; 
                                break;
                            case 'error':
                                echo '<span class="sync-status sync-error">' . esc_html__('Error', 'mi-integracion-api') . '</span>'; 
                                break;
                            case 'partial':
                                echo '<span class="sync-status sync-partial">' . esc_html__('Parcial', 'mi-integracion-api') . '</span>'; 
                                break;
                            default:
                                echo esc_html($registro->status);
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tiempo transcurrido', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html(number_format($registro->elapsed_time, 2)); ?> <?php esc_html_e('segundos', 'mi-integracion-api'); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items procesados', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_processed); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items correctos', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_success); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Items con error', 'mi-integracion-api'); ?></th>
                    <td><?php echo esc_html($registro->items_error); ?></td>
                </tr>
            </table>
            
            <?php if (!empty($detalles) && is_array($detalles)): ?>
                <h2><?php esc_html_e('Detalles', 'mi-integracion-api'); ?></h2>
                
                <?php if (isset($detalles['items']) && is_array($detalles['items'])): ?>
                    <h3><?php esc_html_e('Items procesados', 'mi-integracion-api'); ?></h3>
                    <table class="widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('ID', 'mi-integracion-api'); ?></th>
                                <th><?php esc_html_e('Estado', 'mi-integracion-api'); ?></th>
                                <th><?php esc_html_e('Mensaje', 'mi-integracion-api'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($detalles['items'] as $item): ?>
                                <tr>
                                    <td><?php echo esc_html($item['id'] ?? ''); ?></td>
                                    <td>
                                        <?php 
                                        $status = $item['status'] ?? '';
                                        switch($status) {
                                            case 'success':
                                                echo '<span class="sync-status sync-complete">' . esc_html__('Correcto', 'mi-integracion-api') . '</span>'; 
                                                break;
                                            case 'error':
                                                echo '<span class="sync-status sync-error">' . esc_html__('Error', 'mi-integracion-api') . '</span>'; 
                                                break;
                                            default:
                                                echo esc_html($status);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo esc_html($item['message'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <?php if (isset($detalles['errors']) && is_array($detalles['errors'])): ?>
                    <h3><?php esc_html_e('Errores', 'mi-integracion-api'); ?></h3>
                    <div class="mi-integracion-api-error-list">
                        <ul>
                            <?php foreach ($detalles['errors'] as $error): ?>
                                <li><?php echo esc_html($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (isset($detalles['raw']) && !empty($detalles['raw'])): ?>
                    <h3><?php esc_html_e('Datos crudos', 'mi-integracion-api'); ?></h3>
                    <div class="mi-integracion-api-raw-data">
                        <pre><?php echo esc_html(print_r($detalles['raw'], true)); ?></pre>
                    </div>
                <?php endif; ?>
                
            <?php endif; ?>
        </div>
    </div>
    <?php
}
