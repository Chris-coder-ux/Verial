<?php

namespace MiIntegracionApi\Compatibility;

/**
 * Pruebas de compatibilidad con temas populares
 *
 * Este archivo contiene los resultados de pruebas de compatibilidad con los temas más populares de WordPress.
 *
 * @package MiIntegracionApi\Compatibility
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ThemeCompatibilityTests {
    /**
     * Devuelve los resultados de compatibilidad con temas populares
     *
     * Esta lista contiene los resultados de pruebas exhaustivas realizadas con los temas más populares.
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
        return array(
            // Temas predeterminados de WordPress
            'twentytwentyfive'  => true,
            'twentytwentyfour'  => true,
            'twentytwentythree' => true,
            'twentytwentytwo'   => true,
            'twentytwentyone'   => true,

            // Temas populares de tiendas
            'astra'             => true,
            'storefront'        => true,
            'divi'              => array(
                'status'         => 'partial',
                'notes'          => __( 'Compatible con pequeños ajustes estéticos. Los módulos personalizados de Divi pueden requerir adaptación adicional.', 'mi-integracion-api' ),
                'version_tested' => '4.9.0 - 5.2.1',
                'remediation'    => __( 'Usa los estilos de compatibilidad específicos para Divi incluidos en el plugin.', 'mi-integracion-api' ),
            ),
            'avada'             => array(
                'status'         => 'partial',
                'notes'          => __( 'Compatible, pero algunos elementos de la interfaz de usuario pueden necesitar ajustes CSS adicionales.', 'mi-integracion-api' ),
                'version_tested' => '7.8.1 - 8.1.0',
                'remediation'    => __( 'Si encuentras problemas visuales, activa la opción "Compatibilidad avanzada con temas" en la configuración.', 'mi-integracion-api' ),
            ),
            'flatsome'          => true,

            // Otros temas populares
            'generatepress'     => true,
            'oceanwp'           => true,
            'hello-elementor'   => true,
            'kadence'           => true,
            'neve'              => true,

            // Temas de constructores populares
            'blocksy'           => true,
            'customify'         => array(
                'status'         => 'partial',
                'notes'          => __( 'Compatible con la mayoría de funciones, pero puede haber problemas con páginas personalizadas de checkout.', 'mi-integracion-api' ),
                'version_tested' => '0.4.2 - 0.4.8',
                'remediation'    => __( 'Si usas páginas personalizadas de checkout, usa las plantillas predeterminadas de WooCommerce.', 'mi-integracion-api' ),
            ),
        );
    }
}
