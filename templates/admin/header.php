<?php
/**
 * Template para la cabecera de las páginas de administración del plugin
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/templates/admin
 */

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mi-integracion-api-admin">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
    
    <?php
    // Mostrar cualquier notificación de admin que pueda haber
    settings_errors();
    
    // Verificar si hay módulos activos
    if (!empty($modules)):
    ?>
    <div class="mi-integracion-api-modules">
        <p class="mi-integracion-modules-info"><?php _e('Módulos activos:', 'mi-integracion-api'); ?></p>
        <ul class="mi-integracion-modules-list">
            <?php foreach ($modules as $module_id => $module_info): ?>
                <li><?php echo esc_html($module_info['title'] ?? $module_id); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>
    
    <div class="mi-integracion-api-content">
        <!-- El contenido principal irá aquí -->
