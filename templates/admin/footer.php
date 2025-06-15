<?php
/**
 * Template para el footer de las páginas de administración del plugin
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
    </div><!-- .mi-integracion-api-content -->

    <div class="mi-integracion-api-footer">
        <p class="mi-integracion-version">
            <?php echo sprintf(__('Mi Integración API versión %s', 'mi-integracion-api'), MiIntegracionApi_VERSION); ?>
        </p>
    </div>
</div><!-- .wrap -->
