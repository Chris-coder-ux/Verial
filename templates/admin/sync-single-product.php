<?php
/**
 * Plantilla para la página de sincronización individual de productos
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 * @since      1.0.0
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Renderizar cabecera
$this->render_header();
?>

<div class="wrap mi-integracion-api-sync-single-product">
    <h1><?php echo esc_html__( 'Sincronización Individual de Productos', 'mi-integracion-api' ); ?></h1>
    
    <div class="card">
        <h2><?php echo esc_html__( 'Sincronizar un producto', 'mi-integracion-api' ); ?></h2>
        <p><?php echo esc_html__( 'Utilice este formulario para sincronizar un producto individual desde el API.', 'mi-integracion-api' ); ?></p>
        
        <form id="mi-sync-single-product-form" method="post">
            <?php wp_nonce_field( 'mi_sync_single_product', 'nonce' ); ?>
            
            <table class="form-table">
                <tbody>
                    <tr>
                        <th scope="row">
                            <label for="sku"><?php echo esc_html__( 'SKU del producto', 'mi-integracion-api' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="sku" name="sku" class="regular-text" placeholder="<?php echo esc_attr__( 'SKU o código del producto', 'mi-integracion-api' ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Introduzca el SKU o código del producto que desea sincronizar', 'mi-integracion-api' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="nombre"><?php echo esc_html__( 'Nombre del producto', 'mi-integracion-api' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="nombre" name="nombre" class="regular-text" placeholder="<?php echo esc_attr__( 'Nombre del producto (opcional)', 'mi-integracion-api' ); ?>" />
                            <p class="description"><?php echo esc_html__( 'Si no conoce el SKU, puede buscar por nombre', 'mi-integracion-api' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="categoria"><?php echo esc_html__( 'Categoría', 'mi-integracion-api' ); ?></label>
                        </th>
                        <td>
                            <select id="categoria" name="categoria">
                                <option value=""><?php echo esc_html__( '-- Todas las categorías --', 'mi-integracion-api' ); ?></option>
                                <!-- Las categorías se cargarán vía AJAX -->
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fabricante"><?php echo esc_html__( 'Fabricante', 'mi-integracion-api' ); ?></label>
                        </th>
                        <td>
                            <select id="fabricante" name="fabricante">
                                <option value=""><?php echo esc_html__( '-- Todos los fabricantes --', 'mi-integracion-api' ); ?></option>
                                <!-- Los fabricantes se cargarán vía AJAX -->
                            </select>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div class="submit-container">
                <button type="submit" id="sync-button" class="button button-primary">
                    <?php echo esc_html__( 'Sincronizar Producto', 'mi-integracion-api' ); ?>
                </button>
                <span class="spinner"></span>
            </div>
        </form>
    </div>
    
    <div id="sync-result" class="card hidden">
        <h3><?php echo esc_html__( 'Resultado de la sincronización', 'mi-integracion-api' ); ?></h3>
        <div class="sync-result-content"></div>
    </div>
</div>

<script type="text/javascript">
    // Este script es solo para asegurar que la funcionalidad básica esté disponible
    // El archivo sync-single-product.js contiene la implementación completa
    jQuery(document).ready(function($) {
        // Verificar que todo esté cargado correctamente
        if (typeof miSyncSingleProduct === 'undefined') {
            console.error('Error: El objeto miSyncSingleProduct no está definido. Asegúrese de que el archivo sync-single-product.js se ha cargado correctamente.');
            $('#mi-sync-single-product-form').before('<div class="notice notice-error"><p><?php echo esc_js(__('Error: No se han podido cargar los scripts necesarios. Por favor, recargue la página.', 'mi-integracion-api')); ?></p></div>');
        }
    });
</script>

<?php
// Renderizar pie de página
$this->render_footer();
