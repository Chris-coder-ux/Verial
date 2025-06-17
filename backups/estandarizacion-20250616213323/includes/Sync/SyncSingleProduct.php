<?php
/**
 * Archivo de compatibilidad para la sincronización individual de productos
 * 
 * Este archivo existe para mantener compatibilidad con código existente que llama a
 * \MiIntegracionApi\Sync\SyncSingleProduct en lugar de \MiIntegracionApi\Sync\Sync_Single_Product
 *
 * @package MiIntegracionApi\Sync
 */

namespace MiIntegracionApi\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase de compatibilidad para sincronización individual de productos
 */
class SyncSingleProduct {
    /**
     * Proxy para llamar a Sync_Single_Product::sync
     *
     * @param \MiIntegracionApi\Core\ApiConnector $api_connector El conector de API
     * @param string                              $sku SKU del producto a sincronizar
     * @param string                              $nombre Nombre del producto a sincronizar
     * @param string                              $categoria ID de la categoría del producto
     * @param string                              $fabricante ID del fabricante del producto
     * @return array<string, mixed> Resultado de la sincronización con claves 'success' y 'message'
     */
    public static function sync($api_connector, $sku = '', $nombre = '', $categoria = '', $fabricante = '') {
        // Asegurar que la clase Sync_Single_Product esté cargada
        if (!class_exists('\MiIntegracionApi\Sync\Sync_Single_Product')) {
            require_once __DIR__ . '/Sync_Single_Product.php';
        }
        
        // Crear una instancia y llamar al método de sincronización
        $sync_product = new Sync_Single_Product();
        return $sync_product->sync($api_connector, $sku, $nombre, $categoria, $fabricante);
    }
}
