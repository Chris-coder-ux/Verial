<?php
/**
 * Plantilla para el panel de administración de caché HTTP
 *
 * @package Mi_Integracion_API
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Obtener estadísticas de caché
$cache_enabled = get_option('mi_integracion_api_enable_http_cache', true);
$cache_ttl = get_option('mi_integracion_api_http_cache_ttl', 14400);
$cache_stats = \MiIntegracionApi\Cache\HTTP_Cache_Manager::get_cache_stats();
$cache_size = \MiIntegracionApi\Cache\HTTP_Cache_Manager::get_cache_size();
?>

<div class="wrap mi-api-cache-admin">
    <h1><?php _e('Panel de Administración de Caché HTTP', 'mi-integracion-api'); ?></h1>
    
    <div class="notice notice-info">
        <p><?php _e('Esta página le permite gestionar la caché HTTP utilizada para almacenar respuestas de la API externa y mejorar el rendimiento.', 'mi-integracion-api'); ?></p>
    </div>
    
    <!-- Panel de Estado -->
    <div class="mi-api-admin-card">
        <h2><?php _e('Estado de la Caché', 'mi-integracion-api'); ?></h2>
        
        <div class="mi-api-status-grid">
            <div class="mi-api-status-item <?php echo $cache_enabled ? 'status-enabled' : 'status-disabled'; ?>">
                <span class="dashicons <?php echo $cache_enabled ? 'dashicons-yes-alt' : 'dashicons-no-alt'; ?>"></span>
                <span class="status-label">
                    <?php echo $cache_enabled 
                        ? __('Caché Activa', 'mi-integracion-api') 
                        : __('Caché Desactivada', 'mi-integracion-api'); 
                    ?>
                </span>
                <button class="button" id="mi-api-toggle-cache" 
                    data-status="<?php echo $cache_enabled ? 'enabled' : 'disabled'; ?>">
                    <?php echo $cache_enabled 
                        ? __('Desactivar', 'mi-integracion-api') 
                        : __('Activar', 'mi-integracion-api'); 
                    ?>
                </button>
            </div>
            
            <div class="mi-api-status-item">
                <span class="dashicons dashicons-clock"></span>
                <span class="status-label"><?php _e('Tiempo de vida', 'mi-integracion-api'); ?></span>
                <div class="mi-api-ttl-control">
                    <input type="number" id="mi-api-cache-ttl" value="<?php echo esc_attr($cache_ttl); ?>" min="60" step="60"> 
                    <span><?php _e('segundos', 'mi-integracion-api'); ?></span>
                    <button class="button" id="mi-api-update-ttl"><?php _e('Actualizar', 'mi-integracion-api'); ?></button>
                </div>
            </div>
            
            <div class="mi-api-status-item">
                <span class="dashicons dashicons-database"></span>
                <span class="status-label"><?php _e('Tamaño de caché', 'mi-integracion-api'); ?></span>
                <span class="status-value"><?php echo $cache_size; ?></span>
            </div>
            
            <div class="mi-api-status-item">
                <span class="dashicons dashicons-chart-bar"></span>
                <span class="status-label"><?php _e('Rendimiento', 'mi-integracion-api'); ?></span>
                <?php if (!empty($cache_stats['total'])): ?>
                    <span class="status-value">
                        <?php echo sprintf(
                            __('Tasa de aciertos: %s%%', 'mi-integracion-api'), 
                            round(($cache_stats['hit'] / $cache_stats['total']) * 100, 1)
                        ); ?>
                    </span>
                <?php else: ?>
                    <span class="status-value"><?php _e('No hay datos disponibles', 'mi-integracion-api'); ?></span>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mi-api-button-row">
            <button class="button button-primary" id="mi-api-flush-all-cache">
                <span class="dashicons dashicons-trash"></span>
                <?php _e('Limpiar toda la caché', 'mi-integracion-api'); ?>
            </button>
            <button class="button" id="mi-api-refresh-stats">
                <span class="dashicons dashicons-update"></span>
                <?php _e('Actualizar estadísticas', 'mi-integracion-api'); ?>
            </button>
        </div>
    </div>
    
    <!-- Panel de Estadísticas -->
    <div class="mi-api-admin-card">
        <h2><?php _e('Estadísticas de Caché', 'mi-integracion-api'); ?></h2>
        
        <div class="mi-api-stats-container">
            <table class="widefat mi-api-stats-table">
                <thead>
                    <tr>
                        <th><?php _e('Métrica', 'mi-integracion-api'); ?></th>
                        <th><?php _e('Valor', 'mi-integracion-api'); ?></th>
                        <th><?php _e('Porcentaje', 'mi-integracion-api'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php _e('Aciertos', 'mi-integracion-api'); ?></td>
                        <td><?php echo isset($cache_stats['hit']) ? esc_html($cache_stats['hit']) : '0'; ?></td>
                        <td>
                            <?php 
                            if (!empty($cache_stats['total'])) {
                                echo round(($cache_stats['hit'] / $cache_stats['total']) * 100, 1) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Fallos', 'mi-integracion-api'); ?></td>
                        <td><?php echo isset($cache_stats['miss']) ? esc_html($cache_stats['miss']) : '0'; ?></td>
                        <td>
                            <?php 
                            if (!empty($cache_stats['total'])) {
                                echo round(($cache_stats['miss'] / $cache_stats['total']) * 100, 1) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Caducados', 'mi-integracion-api'); ?></td>
                        <td><?php echo isset($cache_stats['expired']) ? esc_html($cache_stats['expired']) : '0'; ?></td>
                        <td>
                            <?php 
                            if (!empty($cache_stats['total'])) {
                                echo round(($cache_stats['expired'] / $cache_stats['total']) * 100, 1) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Almacenados', 'mi-integracion-api'); ?></td>
                        <td><?php echo isset($cache_stats['stored']) ? esc_html($cache_stats['stored']) : '0'; ?></td>
                        <td>-</td>
                    </tr>
                    <tr>
                        <td><strong><?php _e('Total de solicitudes', 'mi-integracion-api'); ?></strong></td>
                        <td><strong><?php echo isset($cache_stats['total']) ? esc_html($cache_stats['total']) : '0'; ?></strong></td>
                        <td>100%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Panel de Gestión por Entidad -->
    <div class="mi-api-admin-card">
        <h2><?php _e('Gestión por Tipo de Entidad', 'mi-integracion-api'); ?></h2>
        
        <div class="mi-api-entity-grid">
            <?php 
            $entities = [
                'product' => __('Productos', 'mi-integracion-api'),
                'order' => __('Pedidos', 'mi-integracion-api'),
                'config' => __('Configuración', 'mi-integracion-api'),
                'global' => __('Global', 'mi-integracion-api')
            ];
            
            foreach ($entities as $entity_key => $entity_name): 
                $entity_count = \MiIntegracionApi\Cache\HTTP_Cache_Manager::get_entity_cache_count($entity_key);
            ?>
                <div class="mi-api-entity-item">
                    <h3><?php echo $entity_name; ?></h3>
                    <div class="entity-stat">
                        <span class="dashicons dashicons-database"></span>
                        <span><?php echo sprintf(__('%d entradas en caché', 'mi-integracion-api'), $entity_count); ?></span>
                    </div>
                    <button class="button mi-api-flush-entity" data-entity="<?php echo $entity_key; ?>">
                        <?php _e('Limpiar caché', 'mi-integracion-api'); ?>
                    </button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Panel de Configuración Avanzada -->
    <div class="mi-api-admin-card">
        <h2><?php _e('Configuración Avanzada', 'mi-integracion-api'); ?></h2>
        
        <form method="post" action="options.php" id="mi-api-cache-advanced-settings">
            <?php settings_fields('mi_integracion_api_cache_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <?php _e('Invalidación automática', 'mi-integracion-api'); ?>
                    </th>
                    <td>
                        <label for="mi_auto_invalidate">
                            <input type="checkbox" name="mi_integracion_api_auto_invalidate_cache" 
                                id="mi_auto_invalidate" value="1" 
                                <?php checked(get_option('mi_integracion_api_auto_invalidate_cache', true)); ?>>
                            <?php _e('Invalidar automáticamente la caché cuando se modifican datos', 'mi-integracion-api'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Esta opción limpiará automáticamente la caché relevante cuando se actualizan productos, pedidos o configuración.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('Método de almacenamiento', 'mi-integracion-api'); ?>
                    </th>
                    <td>
                        <select name="mi_integracion_api_cache_storage_method" id="mi_cache_storage_method">
                            <option value="transient" <?php selected(get_option('mi_integracion_api_cache_storage_method', 'transient'), 'transient'); ?>>
                                <?php _e('Transients (base de datos)', 'mi-integracion-api'); ?>
                            </option>
                            <option value="file" <?php selected(get_option('mi_integracion_api_cache_storage_method', 'transient'), 'file'); ?>>
                                <?php _e('Sistema de archivos', 'mi-integracion-api'); ?>
                            </option>
                            <?php if (function_exists('apcu_store')): ?>
                            <option value="apcu" <?php selected(get_option('mi_integracion_api_cache_storage_method', 'transient'), 'apcu'); ?>>
                                <?php _e('APCu (memoria)', 'mi-integracion-api'); ?>
                            </option>
                            <?php endif; ?>
                        </select>
                        <p class="description">
                            <?php _e('Seleccione dónde se almacenarán los datos de caché. La opción APCu solo estará disponible si está instalada en su servidor.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php _e('Compresión de caché', 'mi-integracion-api'); ?>
                    </th>
                    <td>
                        <label for="mi_cache_compression">
                            <input type="checkbox" name="mi_integracion_api_cache_compression" 
                                id="mi_cache_compression" value="1" 
                                <?php checked(get_option('mi_integracion_api_cache_compression', false)); ?>>
                            <?php _e('Comprimir datos de caché', 'mi-integracion-api'); ?>
                        </label>
                        <p class="description">
                            <?php _e('La compresión reduce el tamaño de almacenamiento pero puede añadir pequeña sobrecarga de CPU.', 'mi-integracion-api'); ?>
                        </p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('Guardar configuración avanzada', 'mi-integracion-api')); ?>
        </form>
    </div>

    <div id="mi-api-cache-debug-info" class="mi-api-admin-card" style="display: none;">
        <h2><?php _e('Información de depuración', 'mi-integracion-api'); ?></h2>
        <pre id="mi-api-cache-debug-content"></pre>
    </div>
</div>

<!-- Modal de confirmación -->
<div id="mi-api-confirm-modal" class="mi-api-modal">
    <div class="mi-api-modal-content">
        <span class="mi-api-modal-close">&times;</span>
        <h3 id="mi-api-modal-title"><?php _e('Confirmar acción', 'mi-integracion-api'); ?></h3>
        <p id="mi-api-modal-message"><?php _e('¿Está seguro que desea continuar con esta acción?', 'mi-integracion-api'); ?></p>
        <div class="mi-api-modal-actions">
            <button class="button" id="mi-api-modal-cancel"><?php _e('Cancelar', 'mi-integracion-api'); ?></button>
            <button class="button button-primary" id="mi-api-modal-confirm"><?php _e('Confirmar', 'mi-integracion-api'); ?></button>
        </div>
    </div>
</div>