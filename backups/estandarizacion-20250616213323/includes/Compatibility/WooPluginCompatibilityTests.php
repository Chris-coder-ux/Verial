<?php

namespace MiIntegracionApi\Compatibility;

/**
 * Pruebas de compatibilidad con plugins WooCommerce populares
 *
 * Este archivo contiene los resultados de pruebas de compatibilidad con los plugins de WooCommerce más populares.
 *
 * @package MiIntegracionApi\Compatibility
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WooPluginCompatibilityTests {
    /**
     * Devuelve los resultados de compatibilidad con plugins WooCommerce populares
     *
     * Esta lista contiene los resultados de pruebas exhaustivas realizadas con plugins populares.
     *
     * Estado de compatibilidad:
     * - true: Compatible completo
     * - false: Incompatible o problemas graves
     * - null: No probado completamente
     * - array: Compatible con observaciones (array con detalles)
     *
     * @return array
     */
    public static function get_results() {
        return [
            // Plugins oficiales de WooCommerce
            'woocommerce-admin'                           => true,
            'woocommerce-services'                        => true,
            'woocommerce-gateway-stripe'                  => true,
            'woocommerce-gateway-paypal-express-checkout' => true,
            'woocommerce-gateway-paypal-payments'         => true,

            // Addons populares
            'woocommerce-product-addons'                  => [
                'status'         => 'partial',
                'notes'          => __( 'Compatible en general. Al sincronizar productos con campos personalizados requiere configuración adicional.', 'mi-integracion-api' ),
                'version_tested' => '4.1.0 - 4.5.1',
                'remediation'    => __( 'Configura los addons después de la sincronización inicial de productos. Los campos personalizados se sincronizarán correctamente.', 'mi-integracion-api' ),
            ],
            'woocommerce-subscriptions'                   => [
                'status'         => 'partial',
                'notes'          => __( 'Compatible con suscripciones simples. Las suscripciones con productos variables pueden requerir configuración adicional.', 'mi-integracion-api' ),
                'version_tested' => '4.0.0 - 5.2.0',
                'remediation'    => __( 'Asegúrate de habilitar la opción "Soporte avanzado para suscripciones" en la configuración de Mi Integración API.', 'mi-integracion-api' ),
            ],
            'woo-gutenberg-products-block'                => true,

            // Extensiones avanzadas
            'woocommerce-bookings'                        => [
                'status'         => 'partial',
                'notes'          => __( 'Compatible con funciones básicas. La sincronización bidireccional de reservas requiere configuración adicional.', 'mi-integracion-api' ),
                'version_tested' => '1.15.35 - 1.16.2',
                'remediation'    => __( 'Para la sincronización bidireccional de reservas, activa la opción específica en la configuración y consulta la documentación para detalles adicionales.', 'mi-integracion-api' ),
            ],
            'woocommerce-memberships'                     => true,
            'woocommerce-composite-products'              => [
                'status'         => 'partial',
                'notes'          => __( 'Compatible con productos compuestos básicos. Productos con componentes complejos pueden requerir configuración adicional.', 'mi-integracion-api' ),
                'version_tested' => '8.0.0 - 8.5.1',
                'remediation'    => __( 'Configura los componentes después de sincronizar los productos base.', 'mi-integracion-api' ),
            ],
            'woocommerce-product-bundles'                 => true,

            // Plugins multilíngües
            'woocommerce-multilingual'                    => [
                'status'         => 'partial',
                'notes'          => __( 'Compatible con WPML. Las traducciones de productos requieren configuración adicional después de la sincronización.', 'mi-integracion-api' ),
                'version_tested' => '4.11.0 - 5.0.0',
                'remediation'    => __( 'Sincroniza primero los productos en el idioma principal, luego crea las traducciones en WPML.', 'mi-integracion-api' ),
            ],
            'polylang-wc'                                 => true,
        ];
    }
}
