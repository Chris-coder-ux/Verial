<?php
/**
 * Helper para mapear datos de pedidos entre WooCommerce y Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para mapear datos de pedidos entre WooCommerce y Verial
 * 
 * @since 1.0.0
 */
class MapOrder {
    /**
     * Mapea un pedido de WooCommerce a formato Verial
     *
     * @param \WC_Order $order El pedido de WooCommerce
     * @param array $additional_data Datos adicionales opcionales
     * @return array Los datos del pedido en formato Verial
     */
    public static function wc_to_verial(\WC_Order $order, array $additional_data = []): array {
        // Aquí iría el código de mapeo
        // ...
        
        return [];
    }
    
    /**
     * Función de compatibilidad para el código antiguo
     * 
     * @deprecated Use MapOrder::wc_to_verial() en su lugar
     */
    public static function map_order_to_verial($order, $additional_data = []) {
        return self::wc_to_verial($order, $additional_data);
    }
}
