<?php
/**
 * Helper para mapear datos de clientes entre WooCommerce y Verial.
 * Prepara el payload para el endpoint NuevoClienteWS de Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Helpers\Logger; // Corregido el namespace

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente al archivo
}

/**
 * Clase para mapear datos de clientes entre WooCommerce y Verial
 * 
 * @since 1.0.0
 */
class MapCustomer {
    
    /**
     * Mapea un cliente de WooCommerce a formato Verial
     *
     * @param \WC_Customer $customer El cliente de WooCommerce
     * @param array $additional_data Datos adicionales opcionales
     * @return array Los datos del cliente en formato Verial
     */
    public static function wc_to_verial(\WC_Customer $customer, array $additional_data = []): array {
        // Aquí iría el código de mapeo
        // ...
        
        return [];
    }
    
    /**
     * Función de compatibilidad para el código antiguo
     * 
     * @deprecated Use MapCustomer::wc_to_verial() en su lugar
     */
    public static function map_customer_to_verial($customer, $additional_data = []) {
        return self::wc_to_verial($customer, $additional_data);
    }
}
