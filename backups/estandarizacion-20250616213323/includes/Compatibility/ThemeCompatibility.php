<?php
/**
 * Compatibilidad con temas populares
 *
 * Esta clase proporciona soluciones para problemas comunes con temas populares.
 *
 * @package MiIntegracionApi\Compatibility
 * @since 1.0.0
 */

namespace MiIntegracionApi\Compatibility;



// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar la compatibilidad con temas populares
 */
class ThemeCompatibility {

	/**
	 * Inicializa los hooks para la compatibilidad con temas
	 */
	public static function init() {
		// Detectar el tema actual y aplicar parches específicos
		add_action( 'after_setup_theme', array( __CLASS__, 'detect_and_apply_theme_patches' ) );

		// Hooks para soluciones genéricas a problemas comunes de temas
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_compatibility_styles' ), 999 );
	}

	/**
	 * Detecta el tema actual y aplica parches específicos
	 */
	public static function detect_and_apply_theme_patches() {
		$current_theme = wp_get_theme();
		$theme_name    = $current_theme->get_template();

		// Aplicar parches específicos según el tema
		switch ( $theme_name ) {
			case 'twentytwentyfive':
				self::apply_twenty_twenty_five_patches();
				break;

			case 'astra':
				self::apply_astra_patches();
				break;

			case 'divi':
				self::apply_divi_patches();
				break;

			case 'flatsome':
				self::apply_flatsome_patches();
				break;

			case 'avada':
				self::apply_avada_patches();
				break;

			case 'storefront':
				self::apply_storefront_patches();
				break;
		}
	}

	/**
	 * Encola estilos CSS para compatibilidad con temas
	 */
	public static function enqueue_compatibility_styles() {
		$current_theme = wp_get_theme();
		$theme_name    = $current_theme->get_template();

		// Solo encolar en páginas de Admin de nuestro plugin
		if ( ! is_admin() || ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'mi-integracion-api' ) === false ) {
			return;
		}

		// Comprobar si existe un archivo CSS específico para este tema
		$theme_css_path      = 'assets/css/theme-compatibility/' . $theme_name . '.css';
		$theme_css_full_path = MiIntegracionApi_PLUGIN_DIR . $theme_css_path;

		if ( file_exists( $theme_css_full_path ) ) {
			wp_enqueue_style(
				'mi-integracion-api-' . $theme_name . '-compatibility',
				MiIntegracionApi_PLUGIN_URL . $theme_css_path,
				array( 'mi-integracion-api-admin' ),
				MiIntegracionApi_VERSION
			);
		}

		// Estilos generales de compatibilidad para todos los temas
		wp_enqueue_style(
			'mi-integracion-api-theme-compatibility',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/theme-compatibility/general.css',
			array( 'mi-integracion-api-admin' ),
			MiIntegracionApi_VERSION
		);
	}

	/**
	 * Aplica parches específicos para el tema Twenty Twenty Five
	 */
	private static function apply_twenty_twenty_five_patches() {
		// Por defecto, Twenty Twenty Five es completamente compatible
		// Pero podemos añadir hooks específicos si se detectan problemas en el futuro
	}

	/**
	 * Aplica parches específicos para el tema Astra
	 */
	private static function apply_astra_patches() {
		// Compatibilidad con el panel de opciones de Astra
		add_action( 'astra_get_option_array', array( __CLASS__, 'fix_astra_options_panel' ), 20 );

		// Asegurar que nuestro CSS no sea sobrescrito por Astra
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'fix_astra_css_conflicts' ), 999 );
	}

	/**
	 * Aplica parches específicos para el tema Divi
	 */
	private static function apply_divi_patches() {
		// Solucionar problemas con el builder de Divi
		add_filter( 'et_builder_load_actions', array( __CLASS__, 'fix_divi_builder_conflicts' ) );

		// Compatibilidad con los modales de Divi
		add_action( 'admin_head', array( __CLASS__, 'add_divi_modal_compatibility' ) );

		// Asegurar que los meta boxes de productos funcionen correctamente con Divi
		add_filter( 'et_pb_all_fields_unprocessed_et_pb_shop', array( __CLASS__, 'fix_divi_product_fields' ), 10, 1 );
	}

	/**
	 * Aplica parches específicos para el tema Flatsome
	 */
	private static function apply_flatsome_patches() {
		// Compatibilidad con UX Builder
		if ( function_exists( 'flatsome_ux_builder_enabled' ) ) {
			add_filter( 'flatsome_ux_builder_template', array( __CLASS__, 'fix_flatsome_ux_builder' ) );
		}

		// Asegurar compatibilidad con hooks de Flatsome en WooCommerce
		add_action( 'init', array( __CLASS__, 'fix_flatsome_woocommerce_hooks' ), 20 );
	}

	/**
	 * Aplica parches específicos para el tema Avada
	 */
	private static function apply_avada_patches() {
		// Solucionar problemas con Fusion Builder
		if ( class_exists( 'FusionBuilder' ) ) {
			add_filter( 'fusion_builder_element_params', array( __CLASS__, 'fix_fusion_builder_elements' ), 10, 2 );
		}

		// Compatibilidad con Avada Container
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'fix_avada_container_conflicts' ), 999 );
	}

	/**
	 * Aplica parches específicos para el tema Storefront
	 */
	private static function apply_storefront_patches() {
		// Storefront es generalmente muy compatible con WooCommerce, pero podemos
		// mejorar algunos aspectos específicos de la integración
		add_filter( 'storefront_customizer_css', array( __CLASS__, 'fix_storefront_customizer_css' ) );
	}

	/**
	 * Corrige conflictos con el panel de opciones de Astra
	 *
	 * @param array $options Opciones de Astra
	 * @return array Opciones modificadas
	 */
	public static function fix_astra_options_panel( $options ) {
		// Implementación específica para Astra
		return $options;
	}

	/**
	 * Corrige conflictos CSS con el tema Astra
	 */
	public static function fix_astra_css_conflicts() {
		// Solo en páginas de admin de nuestro plugin
		if ( ! is_admin() || ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'mi-integracion-api' ) === false ) {
			return;
		}

		// Aquí implementamos la solución específica
		wp_add_inline_style(
			'mi-integracion-api-admin',
			'
            .ast-container .mi-integration-api-admin-panel {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                float: none !important;
            }
        '
		);
	}

	/**
	 * Corrige conflictos con Divi Builder
	 *
	 * @param array $actions Acciones del builder
	 * @return array Acciones modificadas
	 */
	public static function fix_divi_builder_conflicts( $actions ) {
		// Evitar que Divi procese nuestros shortcodes o elementos personalizados
		if ( isset( $actions['mi_integration_api'] ) ) {
			unset( $actions['mi_integration_api'] );
		}

		return $actions;
	}

	/**
	 * Añade compatibilidad con los modales de Divi
	 */
	public static function add_divi_modal_compatibility() {
		// Solo en páginas de admin de nuestro plugin
		$screen = get_current_screen();
		if ( ! $screen || strpos( $screen->id, 'mi-integracion-api' ) === false ) {
			return;
		}

		echo '<style>
            .et-core-modal-overlay {
                z-index: 9999 !important;
            }
            .mi-integration-api-modal {
                z-index: 10000 !important;
            }
        </style>';
	}

	/**
	 * Corrige campos de productos en Divi
	 *
	 * @param array $fields Campos del módulo
	 * @return array Campos modificados
	 */
	public static function fix_divi_product_fields( $fields ) {
		// Implementación específica para productos en Divi
		return $fields;
	}

	/**
	 * Corrige problemas con UX Builder de Flatsome
	 *
	 * @param array $template Plantilla de UX Builder
	 * @return array Plantilla modificada
	 */
	public static function fix_flatsome_ux_builder( $template ) {
		// Implementación específica para UX Builder
		return $template;
	}

	/**
	 * Corrige hooks de WooCommerce en Flatsome
	 */
	public static function fix_flatsome_woocommerce_hooks() {
		// Solo si WooCommerce está activo
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Implementar soluciones específicas para hooks de Flatsome
	}

	/**
	 * Corrige elementos del Fusion Builder de Avada
	 *
	 * @param array  $params Parámetros del elemento
	 * @param string $element Nombre del elemento
	 * @return array Parámetros modificados
	 */
	public static function fix_fusion_builder_elements( $params, $element ) {
		// Implementación específica para elementos de Fusion Builder
		return $params;
	}

	/**
	 * Corrige conflictos con Avada Container
	 */
	public static function fix_avada_container_conflicts() {
		// Solo en páginas de admin de nuestro plugin
		if ( ! is_admin() || ! isset( $_GET['page'] ) || strpos( $_GET['page'], 'mi-integracion-api' ) === false ) {
			return;
		}

		// Solución específica para Avada
		wp_add_inline_style(
			'mi-integracion-api-admin',
			'
            .mi-integration-api-admin-panel .fusion-builder-container,
            .mi-integration-api-admin-panel .fusion-layout-column {
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }
        '
		);
	}

	/**
	 * Corrige CSS del personalizador de Storefront
	 *
	 * @param string $css Estilos CSS
	 * @return string CSS modificado
	 */
	public static function fix_storefront_customizer_css( $css ) {
		// Implementación específica para Storefront
		return $css;
	}
}

// Inicializar la clase
add_action( 'plugins_loaded', array( 'MI_Theme_Compatibility', 'init' ) );
