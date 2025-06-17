<?php
/**
 * Clase para gestionar la compatibilidad con temas y plugins populares
 *
 * Esta clase maneja las pruebas de compatibilidad y adapta el comportamiento
 * del plugin según el tema o plugin activo en el sitio.
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
 * Clase principal para gestionar la compatibilidad del plugin
 */
class ThemePluginCompatibility {

	/**
	 * Lista de temas probados y su estado de compatibilidad
	 *
	 * @var array
	 */
	private static $compatible_themes;

	/**
	 * Lista de plugins de WooCommerce probados y su estado de compatibilidad
	 *
	 * @var array
	 */
	private static $compatible_wc_plugins;

	/**
	 * Conflictos conocidos y sus soluciones
	 *
	 * @var array
	 */
	private static $known_conflicts = array(
		'some-plugin' => array(
			'description'      => 'Descripción del conflicto',
			'solution'         => 'Solución recomendada',
			'version_affected' => '1.0 - 2.0',
		),
	);

	/**
	 * Inicializa los hooks de compatibilidad
	 */
	public static function init() {
		// Cargar datos de compatibilidad
		self::load_compatibility_data();

		// Verificar compatibilidad al activar plugin
		add_action( 'activated_plugin', array( __CLASS__, 'check_activated_plugin_compatibility' ), 10, 2 );

		// Verificar compatibilidad al cambiar de tema
		add_action( 'after_switch_theme', array( __CLASS__, 'check_theme_compatibility' ) );

		// Añadir comprobaciones en admin
		add_action( 'admin_init', array( __CLASS__, 'check_current_theme_plugin_compatibility' ) );

		// Aplicar parches de compatibilidad según sea necesario
		self::apply_compatibility_patches();
	}

	/**
	 * Carga los datos de compatibilidad de temas y plugins
	 */
	private static function load_compatibility_data() {
		// Cargar datos de compatibilidad con temas
		$theme_compat_file = __DIR__ . '/theme-compatibility-tests.php';
		if ( file_exists( $theme_compat_file ) ) {
			self::$compatible_themes = include $theme_compat_file;
		} else {
			self::$compatible_themes = array();
		}

		// Cargar datos de compatibilidad con plugins WooCommerce
		$woo_compat_file = __DIR__ . '/woo-plugin-compatibility-tests.php';
		if ( file_exists( $woo_compat_file ) ) {
			self::$compatible_wc_plugins = include $woo_compat_file;
		} else {
			self::$compatible_wc_plugins = array();
		}
	}

	/**
	 * Verifica la compatibilidad con un plugin recién activado
	 *
	 * @param string $plugin Ruta del plugin activado
	 * @param bool   $network_wide Si se activó en toda la red
	 */
	public static function check_activated_plugin_compatibility( $plugin, $network_wide ) {
		// Extraer el nombre del plugin de la ruta
		$plugin_name = plugin_basename( $plugin );
		$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );

		// Si es un plugin relacionado con WooCommerce, verificar compatibilidad
		if ( strpos( $plugin_data['Name'], 'WooCommerce' ) !== false ||
			strpos( $plugin_name, 'woocommerce' ) !== false ) {

			self::verify_wc_plugin_compatibility( $plugin_name );
		}
	}

	/**
	 * Verifica la compatibilidad con el tema actual
	 */
	public static function check_theme_compatibility() {
		$current_theme = wp_get_theme();
		$theme_name    = $current_theme->get_template();

		// Verificar si el tema está en nuestra lista de compatibilidad
		if ( isset( self::$compatible_themes[ $theme_name ] ) ) {
			$status = self::$compatible_themes[ $theme_name ];

			if ( $status === false ) {
				// Tema conocido como incompatible
				self::add_admin_notice(
					sprintf(
						__( 'El tema %s tiene problemas de compatibilidad conocidos con Mi Integración API. Por favor revise la documentación para más información.', 'mi-integracion-api' ),
						'<strong>' . $current_theme->get( 'Name' ) . '</strong>'
					),
					'warning'
				);
			} elseif ( $status === null ) {
				// Tema no probado completamente
				self::add_admin_notice(
					sprintf(
						__( 'El tema %s no ha sido probado completamente con Mi Integración API. Por favor reporte cualquier problema que encuentre.', 'mi-integracion-api' ),
						'<strong>' . $current_theme->get( 'Name' ) . '</strong>'
					),
					'info'
				);
			}
		}
	}

	/**
	 * Verifica la compatibilidad con el tema y plugins actuales
	 */
	public static function check_current_theme_plugin_compatibility() {
		// Evitar ejecuciones repetidas
		static $checked = false;
		if ( $checked ) {
			return;
		}
		$checked = true;

		// Solo ejecutar en páginas de nuestro plugin
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( $screen->id, 'mi-integracion-api' ) === false ) {
			return;
		}

		// Verificar tema actual
		self::check_theme_compatibility();

		// Verificar plugins de WooCommerce activos
		$active_plugins = get_option( 'active_plugins', array() );
		foreach ( $active_plugins as $plugin ) {
			$plugin_name = plugin_basename( $plugin );

			// Verificar si es un plugin relacionado con WooCommerce
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin );
			if ( strpos( $plugin_data['Name'], 'WooCommerce' ) !== false ||
				strpos( $plugin_name, 'woocommerce' ) !== false ) {

				self::verify_wc_plugin_compatibility( $plugin_name );
			}
		}
	}

	/**
	 * Verifica la compatibilidad con un plugin específico de WooCommerce
	 *
	 * @param string $plugin_name Nombre del plugin
	 */
	private static function verify_wc_plugin_compatibility( $plugin_name ) {
		// Extraer el slug del plugin
		$plugin_slug = explode( '/', $plugin_name )[0];

		// Verificar si el plugin está en nuestra lista
		if ( isset( self::$compatible_wc_plugins[ $plugin_slug ] ) ) {
			$status = self::$compatible_wc_plugins[ $plugin_slug ];

			if ( $status === false ) {
				// Plugin conocido como incompatible
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_name );
				self::add_admin_notice(
					sprintf(
						__( 'El plugin %s tiene problemas de compatibilidad conocidos con Mi Integración API. Por favor revise la documentación para más información.', 'mi-integracion-api' ),
						'<strong>' . $plugin_data['Name'] . '</strong>'
					),
					'warning'
				);
			}
		}

		// Verificar si hay conflictos conocidos
		if ( isset( self::$known_conflicts[ $plugin_slug ] ) ) {
			$conflict    = self::$known_conflicts[ $plugin_slug ];
			$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_name );

			self::add_admin_notice(
				sprintf(
					__( 'Conflicto conocido con %1$s: %2$s. Solución: %3$s', 'mi-integracion-api' ),
					'<strong>' . $plugin_data['Name'] . '</strong>',
					$conflict['description'],
					$conflict['solution']
				),
				'error'
			);
		}
	}

	/**
	 * Aplica parches de compatibilidad según el tema y plugins activos
	 */
	private static function apply_compatibility_patches() {
		$theme      = wp_get_theme();
		$theme_name = $theme->get_template();

		// Aplicar parches específicos para temas
		switch ( $theme_name ) {
			case 'astra':
				self::apply_astra_compatibility();
				break;
			case 'divi':
				self::apply_divi_compatibility();
				break;
			// Añadir otros casos según sea necesario
		}

		// Aplicar parches específicos para plugins
		if ( is_plugin_active( 'woocommerce-subscriptions/woocommerce-subscriptions.php' ) ) {
			self::apply_wc_subscriptions_compatibility();
		}

		if ( is_plugin_active( 'woo-product-feed-pro/woocommerce-product-feed-pro.php' ) ) {
			self::apply_wc_product_feed_compatibility();
		}
	}

	/**
	 * Añade un aviso en el panel de administración
	 *
	 * @param string $message Mensaje del aviso
	 * @param string $type Tipo de aviso (info, warning, error, success)
	 */
	private static function add_admin_notice( $message, $type = 'info' ) {
		add_action(
			'admin_notices',
			function () use ( $message, $type ) {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
					esc_attr( $type ),
					$message
				);
			}
		);
	}

	/**
	 * Agrega compatibilidad específica con el tema Astra
	 */
	private static function apply_astra_compatibility() {
		// Implementación específica para Astra
	}

	/**
	 * Agrega compatibilidad específica con el tema Divi
	 */
	private static function apply_divi_compatibility() {
		// Implementación específica para Divi
	}

	/**
	 * Agrega compatibilidad con WooCommerce Subscriptions
	 */
	private static function apply_wc_subscriptions_compatibility() {
		// Implementación específica para WooCommerce Subscriptions
	}

	/**
	 * Agrega compatibilidad con WooCommerce Product Feed
	 */
	private static function apply_wc_product_feed_compatibility() {
		// Implementación específica para WooCommerce Product Feed
	}

	/**
	 * Verifica la compatibilidad del plugin con un sistema y devuelve un informe
	 *
	 * @return array Informe de compatibilidad
	 */
	public static function generate_compatibility_report() {
		$report = array(
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => phpversion(),
			'theme'             => array(
				'name'                 => wp_get_theme()->get( 'Name' ),
				'version'              => wp_get_theme()->get( 'Version' ),
				'is_child'             => is_child_theme(),
				'compatibility_status' => self::get_theme_compatibility_status( wp_get_theme()->get_template() ),
			),
			'woocommerce'       => array(
				'active'  => is_plugin_active( 'woocommerce/woocommerce.php' ),
				'version' => is_plugin_active( 'woocommerce/woocommerce.php' ) ? get_plugin_data( WP_PLUGIN_DIR . '/woocommerce/woocommerce.php' )['Version'] : null,
				'plugins' => self::get_active_wc_plugins(),
			),
			'conflicts'         => self::detect_active_conflicts(),
		);

		return $report;
	}

	/**
	 * Obtiene el estado de compatibilidad de un tema
	 *
	 * @param string $theme_slug Slug del tema
	 * @return string Estado de compatibilidad (compatible, untested, incompatible)
	 */
	private static function get_theme_compatibility_status( $theme_slug ) {
		if ( ! isset( self::$compatible_themes[ $theme_slug ] ) ) {
			return 'untested';
		}

		$status = self::$compatible_themes[ $theme_slug ];
		if ( $status === true ) {
			return 'compatible';
		} elseif ( $status === false ) {
			return 'incompatible';
		} else {
			return 'untested';
		}
	}

	/**
	 * Obtiene la lista de plugins de WooCommerce activos
	 *
	 * @return array Lista de plugins con su estado de compatibilidad
	 */
	private static function get_active_wc_plugins() {
		$wc_plugins  = array();
		$all_plugins = get_plugins();

		foreach ( $all_plugins as $plugin_path => $plugin_data ) {
			// Verificar si es un plugin de WooCommerce y está activo
			if ( ( strpos( $plugin_data['Name'], 'WooCommerce' ) !== false ||
				strpos( $plugin_path, 'woocommerce' ) !== false ) &&
				is_plugin_active( $plugin_path ) ) {

				// Extraer el slug del plugin
				$plugin_slug = explode( '/', $plugin_path )[0];

				// Determinar estado de compatibilidad
				$compatibility = 'untested';
				if ( isset( self::$compatible_wc_plugins[ $plugin_slug ] ) ) {
					$status = self::$compatible_wc_plugins[ $plugin_slug ];
					if ( $status === true ) {
						$compatibility = 'compatible';
					} elseif ( $status === false ) {
						$compatibility = 'incompatible';
					} else {
						$compatibility = 'untested';
					}
				}

				$wc_plugins[] = array(
					'name'          => $plugin_data['Name'],
					'version'       => $plugin_data['Version'],
					'slug'          => $plugin_slug,
					'compatibility' => $compatibility,
				);
			}
		}

		return $wc_plugins;
	}

	/**
	 * Detecta conflictos activos en el sistema actual
	 *
	 * @return array Lista de conflictos detectados
	 */
	private static function detect_active_conflicts() {
		$conflicts      = array();
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $active_plugins as $plugin_path ) {
			$plugin_slug = explode( '/', $plugin_path )[0];

			if ( isset( self::$known_conflicts[ $plugin_slug ] ) ) {
				$plugin_data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_path );
				$conflict    = self::$known_conflicts[ $plugin_slug ];

				$conflicts[] = array(
					'plugin_name'      => $plugin_data['Name'],
					'plugin_version'   => $plugin_data['Version'],
					'description'      => $conflict['description'],
					'solution'         => $conflict['solution'],
					'version_affected' => $conflict['version_affected'],
				);
			}
		}

		return $conflicts;
	}
}

// Inicializar la clase
add_action( 'plugins_loaded', array( 'MI_Theme_Plugin_Compatibility', 'init' ) );
