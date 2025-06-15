<?php
/**
 * Ejemplo de integración del sistema de recuperación en la interfaz administrativa
 * 
 * Este archivo ilustra cómo se puede integrar el sistema de recuperación
 * en la interfaz administrativa de WordPress.
 * 
 * @package MiIntegracionApi\Admin\Examples
 */

namespace MiIntegracionApi\Admin;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase de ejemplo para la interfaz administrativa de recuperación
 */
class Recovery_Interface {
    /**
     * Constructor
     */
    public function __construct() {
        // Añadir mensaje de recuperación en el panel principal
        add_action('mi_integracion_api_admin_before_sync', array($this, 'mostrar_mensaje_recuperacion'));
        
        // Añadir opciones de recuperación en el panel de herramientas
        add_action('mi_integracion_api_admin_tools', array($this, 'mostrar_opciones_recuperacion'));
        
        // Procesar los formularios
        add_action('admin_init', array($this, 'procesar_formularios'));
    }
    
    /**
     * Muestra mensaje de recuperación si hay una sincronización pendiente
     */
    public function mostrar_mensaje_recuperacion() {
        // Verificar si hay una sincronización pendiente
        $sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
        $pending_sync = $sync_manager->check_pending_sync();
        
        if (!$pending_sync) {
            return; // No hay sincronización pendiente
        }
        
        $entity_labels = array(
            'productos' => __('productos', 'mi-integracion-api'),
            'clientes' => __('clientes', 'mi-integracion-api'),
            'pedidos' => __('pedidos', 'mi-integracion-api'),
        );
        
        $entity_label = isset($entity_labels[$pending_sync['entity']]) ? 
                        $entity_labels[$pending_sync['entity']] : 
                        $pending_sync['entity'];
        
        ?>
        <div class="notice notice-info">
            <p><strong><?php _e('Sincronización pendiente de reanudar', 'mi-integracion-api'); ?></strong></p>
            <p><?php echo esc_html($pending_sync['message']); ?></p>
            
            <form method="post" style="margin-top: 15px;">
                <?php wp_nonce_field('mi_integracion_api_resume_sync', 'resume_sync_nonce'); ?>
                <input type="hidden" name="action" value="mi_integracion_api_resume_sync">
                <input type="hidden" name="entity" value="<?php echo esc_attr($pending_sync['entity']); ?>">
                
                <button type="submit" name="resume" class="button button-primary">
                    <?php printf(__('Reanudar sincronización de %s', 'mi-integracion-api'), $entity_label); ?>
                </button>
                
                <button type="submit" name="restart" class="button">
                    <?php printf(__('Iniciar nueva sincronización de %s', 'mi-integracion-api'), $entity_label); ?>
                </button>
            </form>
        </div>
        <?php
    }
    
    /**
     * Muestra opciones de recuperación en el panel de herramientas
     */
    public function mostrar_opciones_recuperacion() {
        ?>
        <div class="mi-integracion-api-admin-section">
            <h3><?php _e('Gestionar Puntos de Recuperación', 'mi-integracion-api'); ?></h3>
            
            <p><?php _e('Los puntos de recuperación permiten reanudar sincronizaciones interrumpidas. Utilice estas opciones con precaución.', 'mi-integracion-api'); ?></p>
            
            <?php
            // Verificar si hay alguna sincronización pendiente
            $sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
            $pending_sync = $sync_manager->check_pending_sync();
            
            if ($pending_sync) {
                $progress = $pending_sync['progress'];
                ?>
                <div class="mi-integracion-api-recovery-info">
                    <h4><?php printf(__('Sincronización de %s pendiente', 'mi-integracion-api'), esc_html($pending_sync['entity'])); ?></h4>
                    
                    <ul>
                        <li><?php printf(__('Progreso: %s%%', 'mi-integracion-api'), esc_html($progress['percentage'])); ?></li>
                        <li><?php printf(__('Procesados: %s de %s', 'mi-integracion-api'), esc_html($progress['processed']), esc_html($progress['total'])); ?></li>
                        <li><?php printf(__('Último lote: #%s', 'mi-integracion-api'), esc_html($progress['last_batch'])); ?></li>
                        <li><?php printf(__('Fecha: %s', 'mi-integracion-api'), esc_html($progress['date'])); ?></li>
                    </ul>
                </div>
                <?php
            } else {
                ?>
                <div class="mi-integracion-api-recovery-info">
                    <p><?php _e('No hay puntos de recuperación activos.', 'mi-integracion-api'); ?></p>
                </div>
                <?php
            }
            ?>
            
            <form method="post">
                <?php wp_nonce_field('mi_integracion_api_clear_recovery', 'clear_recovery_nonce'); ?>
                <input type="hidden" name="action" value="mi_integracion_api_clear_recovery">
                
                <button type="submit" class="button" onclick="return confirm('<?php esc_attr_e('¿Está seguro de que desea eliminar todos los puntos de recuperación?', 'mi-integracion-api'); ?>')">
                    <?php _e('Eliminar todos los puntos de recuperación', 'mi-integracion-api'); ?>
                </button>
            </form>
        </div>
        <?php
    }
    
    /**
     * Procesa los formularios de recuperación
     */
    public function procesar_formularios() {
        // Procesar formulario de reanudación
        if (isset($_POST['action']) && $_POST['action'] === 'mi_integracion_api_resume_sync') {
            if (!isset($_POST['resume_sync_nonce']) || !wp_verify_nonce($_POST['resume_sync_nonce'], 'mi_integracion_api_resume_sync')) {
                wp_die(__('Verificación de seguridad fallida', 'mi-integracion-api'));
            }
            
            if (!isset($_POST['entity'])) {
                return;
            }
            
            $entity = sanitize_text_field($_POST['entity']);
            $force_restart = isset($_POST['restart']);
            
            $sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
            $result = $sync_manager->resume_sync($entity, $force_restart);
            
            // Configurar mensaje para mostrar en la siguiente carga de página
            if ($result['success']) {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($result['message']) . '</p></div>';
                });
            }
            
            return;
        }
        
        // Procesar formulario de limpieza de puntos de recuperación
        if (isset($_POST['action']) && $_POST['action'] === 'mi_integracion_api_clear_recovery') {
            if (!isset($_POST['clear_recovery_nonce']) || !wp_verify_nonce($_POST['clear_recovery_nonce'], 'mi_integracion_api_clear_recovery')) {
                wp_die(__('Verificación de seguridad fallida', 'mi-integracion-api'));
            }
            
            $sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
            $result = $sync_manager->clear_all_recovery_states();
            
            // Configurar mensaje para mostrar en la siguiente carga de página
            if ($result) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success is-dismissible"><p>' . 
                         __('Todos los puntos de recuperación han sido eliminados correctamente.', 'mi-integracion-api') . 
                         '</p></div>';
                });
            } else {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error is-dismissible"><p>' . 
                         __('Error al eliminar los puntos de recuperación.', 'mi-integracion-api') . 
                         '</p></div>';
                });
            }
            
            return;
        }
    }
}
