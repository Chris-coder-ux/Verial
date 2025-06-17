<?php
/**
 * Maneja las solicitudes AJAX para la sincronización individual de productos
 *
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 * @since      1.0.0
 */

namespace MiIntegracionApi\Admin;

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use MiIntegracionApi\Core\Sync_Single_Product;
use MiIntegracionApi\Helpers\Logger;

/**
 * Clase que maneja las solicitudes AJAX para la sincronización individual de productos
 */
class AjaxSingleSync {
    /**
     * Logger para esta clase
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new Logger('ajax-single-sync');
        $this->register_hooks();
    }

    /**
     * Registra los hooks necesarios
     */
    private function register_hooks() {
        add_action('wp_ajax_mi_sync_single_product', array($this, 'handle_sync_single_product'));
    }

    /**
     * Maneja la solicitud AJAX para sincronizar un producto individual
     */
    public function handle_sync_single_product() {
        // Verificar nonce
        check_ajax_referer('mi_sync_single_product', 'nonce');
        
        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error([
                'message' => __('No tienes permisos para realizar esta acción.', 'mi-integracion-api'),
                'code' => 'permission_denied'
            ]);
        }
        
        $sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
        $nombre = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
        $categoria = isset($_POST['categoria']) ? sanitize_text_field($_POST['categoria']) : '';
        $fabricante = isset($_POST['fabricante']) ? sanitize_text_field($_POST['fabricante']) : '';
        
        // Validar que al menos se haya proporcionado un SKU o nombre
        if (empty($sku) && empty($nombre)) {
            wp_send_json_error([
                'message' => __('Debes proporcionar un SKU o nombre del producto.', 'mi-integracion-api'),
                'code' => 'missing_required_fields'
            ]);
        }
        
        try {
            $this->logger->info('Iniciando sincronización individual de producto', [
                'sku' => $sku,
                'nombre' => $nombre,
                'categoria' => $categoria,
                'fabricante' => $fabricante
            ]);
            
            // Crear instancia de la clase de sincronización
            $sync = new Sync_Single_Product();
            
            // Preparar datos para sincronizar
            $product_data = [
                'sku' => $sku,
                'nombre' => $nombre,
                'categoria' => $categoria,
                'fabricante' => $fabricante
            ];
            
            // Ejecutar sincronización
            $result = $sync->sync($product_data);
            
            if ($result) {
                $this->logger->info('Producto sincronizado correctamente', [
                    'sku' => $sku,
                    'nombre' => $nombre
                ]);
                
                wp_send_json_success([
                    'message' => __('Producto sincronizado correctamente.', 'mi-integracion-api'),
                    'productData' => $product_data
                ]);
            } else {
                $this->logger->error('Error durante la sincronización del producto', [
                    'sku' => $sku,
                    'nombre' => $nombre
                ]);
                
                wp_send_json_error([
                    'message' => __('No se pudo sincronizar el producto. Verifica el log para más detalles.', 'mi-integracion-api'),
                    'code' => 'sync_failed'
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Excepción durante la sincronización individual', [
                'message' => $e->getMessage(),
                'sku' => $sku,
                'nombre' => $nombre
            ]);
            
            wp_send_json_error([
                'message' => $e->getMessage(),
                'code' => 'exception'
            ]);
        }
    }
}

// Inicializar la clase
new AjaxSingleSync();
