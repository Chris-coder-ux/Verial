<?php
/**
 * Pantalla de informe de compatibilidad
 *
 * Esta clase proporciona una interfaz de administración para verificar
 * y gestionar la compatibilidad con temas y plugins.
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
 * Clase para la pantalla de informe de compatibilidad
 */
class CompatibilityReport {

	/**
	 * Inicializa los hooks para la pantalla de compatibilidad
	 */
	public static function init() {
		// Añadir página de menú para el informe de compatibilidad
		add_action( 'admin_menu', array( __CLASS__, 'add_compatibility_page' ), 30 );

		// Añadir AJAX handlers para pruebas de compatibilidad
		add_action( 'wp_ajax_mi_integracion_test_theme_compatibility', array( __CLASS__, 'ajax_test_theme_compatibility' ) );
		add_action( 'wp_ajax_mi_integracion_test_plugin_compatibility', array( __CLASS__, 'ajax_test_plugin_compatibility' ) );

		// Scripts y estilos para la página de compatibilidad
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_compatibility_assets' ) );
	}

	/**
	 * Añade la página de informe de compatibilidad al menú
	 */
	public static function add_compatibility_page() {
		// add_submenu_page(
		//     'mi-integracion-api',
		//     __( 'Informe de Compatibilidad', 'mi-integracion-api' ),
		//     __( 'Informe de Compatibilidad', 'mi-integracion-api' ),
		//     'manage_options',
		//     'mi-integracion-api-compatibility-report',
		//     array( self::class, 'render_page' )
		// );
	}

	/**
	 * Encola scripts y estilos para la página de compatibilidad
	 *
	 * @param string $hook Página actual
	 */
	public static function enqueue_compatibility_assets( $hook ) {
		if ( $hook !== 'mi-integracion-api_page_mi-integracion-api-compatibility' ) {
			return;
		}

		// Registrar y encolar script para la página de compatibilidad
		wp_register_script(
			'mi-integracion-api-compatibility',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/compatibility-report.js',
			array( 'jquery', 'wp-util' ),
			MiIntegracionApi_VERSION,
			true
		);

		// Localizar script
		wp_localize_script(
			'mi-integracion-api-compatibility',
			'miApiCompat',
			array(
				'nonce'   => wp_create_nonce( 'mi-integracion-api-compatibility' ),
				'testing' => __( 'Probando...', 'mi-integracion-api' ),
				'success' => __( 'Compatible', 'mi-integracion-api' ),
				'warning' => __( 'Parcialmente compatible', 'mi-integracion-api' ),
				'error'   => __( 'Problema detectado', 'mi-integracion-api' ),
				'unknown' => __( 'No probado', 'mi-integracion-api' ),
			)
		);

		wp_enqueue_script( 'mi-integracion-api-compatibility' );

		// Estilos para la página de compatibilidad
		wp_register_style(
			'mi-integracion-api-compatibility-style',
			MiIntegracionApi_PLUGIN_URL . 'assets/css/compatibility-report.css',
			array( 'mi-integracion-api-admin' ),
			MiIntegracionApi_VERSION
		);

		wp_enqueue_style( 'mi-integracion-api-compatibility-style' );
	}

	/**
	 * Renderiza la página de informe de compatibilidad
	 */
	public static function render_compatibility_page() {
		// Comprobar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Obtener datos de compatibilidad documentados
		$theme_tests  = array();
		$plugin_tests = array();

		if ( class_exists( 'MI_Compatibility_Documentation' ) ) {
			$theme_tests  = MI_Compatibility_Documentation::get_theme_compatibility_tests();
			$plugin_tests = MI_Compatibility_Documentation::get_woo_plugin_compatibility_tests();
		}

		// Obtener tema actual
		$current_theme          = wp_get_theme();
		$current_theme_name     = $current_theme->get( 'Name' );
		$current_theme_version  = $current_theme->get( 'Version' );
		$current_theme_template = $current_theme->get_template();

		// Obtener plugins de WooCommerce activos
		$wc_plugins = self::get_active_woocommerce_plugins();

		?>
		<div class="wrap mi-integration-api-admin-panel">
			<h1><?php _e( 'Informe de Compatibilidad', 'mi-integracion-api' ); ?></h1>
			
			<div class="mi-compatibility-notice notice notice-info inline">
				<p><?php _e( 'Esta página muestra la compatibilidad de Mi Integración API con temas y plugins populares de WooCommerce.', 'mi-integracion-api' ); ?></p>
				<p><?php _e( 'Puedes probar la compatibilidad con tu configuración actual y ver posibles problemas y soluciones.', 'mi-integracion-api' ); ?></p>
			</div>
			
			<div class="mi-compatibility-current">
				<h2><?php _e( 'Tu Configuración Actual', 'mi-integracion-api' ); ?></h2>
				
				<div class="mi-compatibility-section">
					<h3><?php _e( 'Tema Activo', 'mi-integracion-api' ); ?></h3>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php _e( 'Tema', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Versión', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Estado', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Acciones', 'mi-integracion-api' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr>
								<td><?php echo esc_html( $current_theme_name ); ?></td>
								<td><?php echo esc_html( $current_theme_version ); ?></td>
								<td class="mi-compatibility-status">
									<?php
									$theme_status = self::get_theme_compatibility_status( $current_theme_template, $theme_tests );
									self::render_compatibility_status_badge( $theme_status );
									?>
								</td>
								<td>
									<button type="button" class="button test-compatibility" data-type="theme" data-slug="<?php echo esc_attr( $current_theme_template ); ?>">
										<?php _e( 'Probar Compatibilidad', 'mi-integracion-api' ); ?>
									</button>
								</td>
							</tr>
						</tbody>
					</table>
				</div>
				
				<div class="mi-compatibility-section">
					<h3><?php _e( 'Plugins de WooCommerce Activos', 'mi-integracion-api' ); ?></h3>
					
					<?php if ( ! empty( $wc_plugins ) ) : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php _e( 'Plugin', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Versión', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Estado', 'mi-integracion-api' ); ?></th>
								<th><?php _e( 'Acciones', 'mi-integracion-api' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $wc_plugins as $slug => $plugin ) : ?>
							<tr>
								<td><?php echo esc_html( $plugin['name'] ); ?></td>
								<td><?php echo esc_html( $plugin['version'] ); ?></td>
								<td class="mi-compatibility-status">
									<?php
									$plugin_status = self::get_plugin_compatibility_status( $slug, $plugin_tests );
									self::render_compatibility_status_badge( $plugin_status );
									?>
								</td>
								<td>
									<button type="button" class="button test-compatibility" data-type="plugin" data-slug="<?php echo esc_attr( $slug ); ?>">
										<?php _e( 'Probar Compatibilidad', 'mi-integracion-api' ); ?>
									</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<?php else : ?>
					<p><?php _e( 'No se han detectado plugins de WooCommerce activos.', 'mi-integracion-api' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
			
			<div class="mi-compatibility-all-themes">
				<h2><?php _e( 'Compatibilidad con Temas Populares', 'mi-integracion-api' ); ?></h2>
				
				<table class="widefat striped compatibility-table">
					<thead>
						<tr>
							<th><?php _e( 'Tema', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Versión Probada', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Estado', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Notas', 'mi-integracion-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $theme_tests as $slug => $theme ) : ?>
						<tr>
							<td><?php echo esc_html( $theme['name'] ); ?></td>
							<td><?php echo esc_html( $theme['version_tested'] ); ?></td>
							<td class="mi-compatibility-status">
								<?php self::render_compatibility_status_badge( $theme['status'] ); ?>
							</td>
							<td>
								<?php echo esc_html( $theme['notes'] ); ?>
								
								<?php if ( $theme['status'] == 'partial' && ! empty( $theme['issues'] ) ) : ?>
								<div class="mi-compatibility-details">
									<p><strong><?php _e( 'Problemas Detectados:', 'mi-integracion-api' ); ?></strong></p>
									<ul>
										<?php foreach ( $theme['issues'] as $issue ) : ?>
										<li><?php echo esc_html( $issue ); ?></li>
										<?php endforeach; ?>
									</ul>
									
									<?php if ( ! empty( $theme['solutions'] ) ) : ?>
									<p><strong><?php _e( 'Soluciones Implementadas:', 'mi-integracion-api' ); ?></strong></p>
									<ul>
										<?php foreach ( $theme['solutions'] as $solution ) : ?>
										<li><?php echo esc_html( $solution ); ?></li>
										<?php endforeach; ?>
									</ul>
									<?php endif; ?>
								</div>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="mi-compatibility-all-plugins">
				<h2><?php _e( 'Compatibilidad con Plugins de WooCommerce Populares', 'mi-integracion-api' ); ?></h2>
				
				<table class="widefat striped compatibility-table">
					<thead>
						<tr>
							<th><?php _e( 'Plugin', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Versión Probada', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Estado', 'mi-integracion-api' ); ?></th>
							<th><?php _e( 'Notas', 'mi-integracion-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $plugin_tests as $slug => $plugin ) : ?>
						<tr>
							<td><?php echo esc_html( $plugin['name'] ); ?></td>
							<td><?php echo esc_html( $plugin['version_tested'] ); ?></td>
							<td class="mi-compatibility-status">
								<?php self::render_compatibility_status_badge( $plugin['status'] ); ?>
							</td>
							<td>
								<?php echo esc_html( $plugin['notes'] ); ?>
								
								<?php if ( $plugin['status'] == 'partial' && ! empty( $plugin['issues'] ) ) : ?>
								<div class="mi-compatibility-details">
									<p><strong><?php _e( 'Problemas Detectados:', 'mi-integracion-api' ); ?></strong></p>
									<ul>
										<?php foreach ( $plugin['issues'] as $issue ) : ?>
										<li><?php echo esc_html( $issue ); ?></li>
										<?php endforeach; ?>
									</ul>
									
									<?php if ( ! empty( $plugin['solutions'] ) ) : ?>
									<p><strong><?php _e( 'Soluciones Implementadas:', 'mi-integracion-api' ); ?></strong></p>
									<ul>
										<?php foreach ( $plugin['solutions'] as $solution ) : ?>
										<li><?php echo esc_html( $solution ); ?></li>
										<?php endforeach; ?>
									</ul>
									<?php endif; ?>
								</div>
								<?php endif; ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			
			<div class="mi-compatibility-help">
				<h2><?php _e( '¿Encontraste un problema de compatibilidad?', 'mi-integracion-api' ); ?></h2>
				<p>
					<?php _e( 'Si encuentras problemas de compatibilidad entre Mi Integración API y tu tema o plugins, por favor contáctanos para ayudarte a resolverlos.', 'mi-integracion-api' ); ?>
				</p>
				<p>
					<a href="https://miplugin.com/soporte" class="button button-primary" target="_blank">
						<?php _e( 'Obtener Soporte', 'mi-integracion-api' ); ?>
					</a>
				</p>
			</div>
			
			<div id="mi-compatibility-test-results" class="mi-compatibility-test-results hidden">
				<h3><?php _e( 'Resultados de la Prueba', 'mi-integracion-api' ); ?></h3>
				<div class="results-content"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Obtiene el estado de compatibilidad para un tema
	 *
	 * @param string $theme_slug Slug del tema
	 * @param array  $theme_tests Pruebas de compatibilidad con temas
	 * @return string Estado de compatibilidad
	 */
	private static function get_theme_compatibility_status( $theme_slug, $theme_tests ) {
		if ( isset( $theme_tests[ $theme_slug ] ) ) {
			return $theme_tests[ $theme_slug ]['status'];
		}

		return 'unknown';
	}

	/**
	 * Obtiene el estado de compatibilidad para un plugin
	 *
	 * @param string $plugin_slug Slug del plugin
	 * @param array  $plugin_tests Pruebas de compatibilidad con plugins
	 * @return string Estado de compatibilidad
	 */
	private static function get_plugin_compatibility_status( $plugin_slug, $plugin_tests ) {
		if ( isset( $plugin_tests[ $plugin_slug ] ) ) {
			return $plugin_tests[ $plugin_slug ]['status'];
		}

		return 'unknown';
	}

	/**
	 * Renderiza una insignia de estado de compatibilidad
	 *
	 * @param string $status Estado de compatibilidad
	 */
	private static function render_compatibility_status_badge( $status ) {
		$classes = '';
		$text    = '';

		switch ( $status ) {
			case 'compatible':
				$classes = 'mi-status-success';
				$text    = __( 'Compatible', 'mi-integracion-api' );
				break;

			case 'partial':
				$classes = 'mi-status-warning';
				$text    = __( 'Parcialmente Compatible', 'mi-integracion-api' );
				break;

			case 'incompatible':
				$classes = 'mi-status-error';
				$text    = __( 'Incompatible', 'mi-integracion-api' );
				break;

			default:
				$classes = 'mi-status-unknown';
				$text    = __( 'No Probado', 'mi-integracion-api' );
				break;
		}

		echo '<span class="mi-status-badge ' . esc_attr( $classes ) . '">' . esc_html( $text ) . '</span>';
	}

	/**
	 * Obtiene los plugins de WooCommerce activos
	 *
	 * @return array Plugins de WooCommerce activos
	 */
	private static function get_active_woocommerce_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$wc_plugins     = array();

		foreach ( $active_plugins as $plugin_path ) {
			if ( ! isset( $all_plugins[ $plugin_path ] ) ) {
				continue;
			}

			$plugin_data = $all_plugins[ $plugin_path ];
			$plugin_slug = dirname( plugin_basename( $plugin_path ) );

			// Si es un plugin del directorio principal (sin directorio), usar el filename
			if ( $plugin_slug === '.' ) {
				$plugin_slug = basename( $plugin_path, '.php' );
			}

			// Verificar si es un plugin relacionado con WooCommerce
			if ( strpos( $plugin_data['Name'], 'WooCommerce' ) !== false ||
				strpos( $plugin_slug, 'woocommerce' ) !== false ||
				strpos( $plugin_path, 'woocommerce' ) !== false ||
				isset( $plugin_data['WC tested up to'] ) ||
				isset( $plugin_data['WC requires at least'] ) ) {

				$wc_plugins[ $plugin_slug ] = array(
					'name'    => $plugin_data['Name'],
					'version' => $plugin_data['Version'],
					'path'    => $plugin_path,
				);
			}
		}

		return $wc_plugins;
	}

	/**
	 * Manejador AJAX para prueba de compatibilidad de tema
	 */
	public static function ajax_test_theme_compatibility() {
		// Verificar seguridad
		check_ajax_referer( 'mi-integracion-api-compatibility', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ) ) );
		}

		$theme_slug = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : '';

		if ( empty( $theme_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No se especificó un tema para probar.', 'mi-integracion-api' ) ) );
		}

		// Obtener datos de compatibilidad documentados
		$theme_tests = array();
		if ( class_exists( 'MI_Compatibility_Documentation' ) ) {
			$theme_tests = MI_Compatibility_Documentation::get_theme_compatibility_tests();
		}

		// Verificar si tenemos información sobre este tema
		if ( isset( $theme_tests[ $theme_slug ] ) ) {
			$theme_test = $theme_tests[ $theme_slug ];

			$response = array(
				'status'         => $theme_test['status'],
				'name'           => $theme_test['name'],
				'version_tested' => $theme_test['version_tested'],
				'notes'          => $theme_test['notes'],
				'test_date'      => $theme_test['test_date'],
				'tested_by'      => $theme_test['tested_by'],
			);

			if ( isset( $theme_test['issues'] ) ) {
				$response['issues'] = $theme_test['issues'];
			}

			if ( isset( $theme_test['solutions'] ) ) {
				$response['solutions'] = $theme_test['solutions'];
			}

			wp_send_json_success( $response );
		} else {
			// Realizar prueba dinámica básica
			$current_theme = wp_get_theme( $theme_slug );
			$theme_name    = $current_theme->get( 'Name' );

			// Realizar algunas comprobaciones básicas
			$issues = self::detect_theme_compatibility_issues( $theme_slug );

			if ( empty( $issues ) ) {
				wp_send_json_success(
					array(
						'status' => 'compatible',
						'name'   => $theme_name,
						'notes'  => __( 'No se detectaron problemas de compatibilidad con este tema.', 'mi-integracion-api' ),
					)
				);
			} else {
				wp_send_json_success(
					array(
						'status' => 'partial',
						'name'   => $theme_name,
						'notes'  => __( 'Se detectaron algunos posibles problemas de compatibilidad.', 'mi-integracion-api' ),
						'issues' => $issues,
					)
				);
			}
		}
	}

	/**
	 * Manejador AJAX para prueba de compatibilidad de plugin
	 */
	public static function ajax_test_plugin_compatibility() {
		// Verificar seguridad
		check_ajax_referer( 'mi-integracion-api-compatibility', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ) ) );
		}

		$plugin_slug = isset( $_POST['slug'] ) ? sanitize_text_field( $_POST['slug'] ) : '';

		if ( empty( $plugin_slug ) ) {
			wp_send_json_error( array( 'message' => __( 'No se especificó un plugin para probar.', 'mi-integracion-api' ) ) );
		}

		// Obtener datos de compatibilidad documentados
		$plugin_tests = array();
		if ( class_exists( 'MI_Compatibility_Documentation' ) ) {
			$plugin_tests = MI_Compatibility_Documentation::get_woo_plugin_compatibility_tests();
		}

		// Verificar si tenemos información sobre este plugin
		if ( isset( $plugin_tests[ $plugin_slug ] ) ) {
			$plugin_test = $plugin_tests[ $plugin_slug ];

			$response = array(
				'status'         => $plugin_test['status'],
				'name'           => $plugin_test['name'],
				'version_tested' => $plugin_test['version_tested'],
				'notes'          => $plugin_test['notes'],
				'test_date'      => $plugin_test['test_date'],
				'tested_by'      => $plugin_test['tested_by'],
			);

			if ( isset( $plugin_test['issues'] ) ) {
				$response['issues'] = $plugin_test['issues'];
			}

			if ( isset( $plugin_test['solutions'] ) ) {
				$response['solutions'] = $plugin_test['solutions'];
			}

			wp_send_json_success( $response );
		} else {
			// Realizar prueba dinámica básica
			$plugin_data = self::get_plugin_data_by_slug( $plugin_slug );
			$plugin_name = isset( $plugin_data['name'] ) ? $plugin_data['name'] : $plugin_slug;

			// Realizar algunas comprobaciones básicas
			$issues = self::detect_plugin_compatibility_issues( $plugin_slug );

			if ( empty( $issues ) ) {
				wp_send_json_success(
					array(
						'status' => 'compatible',
						'name'   => $plugin_name,
						'notes'  => __( 'No se detectaron problemas de compatibilidad con este plugin.', 'mi_integracion_api' ),
					)
				);
			} else {
				wp_send_json_success(
					array(
						'status' => 'partial',
						'name'   => $plugin_name,
						'notes'  => __( 'Se detectaron algunos posibles problemas de compatibilidad.', 'mi_integracion_api' ),
						'issues' => $issues,
					)
				);
			}
		}
	}

	/**
	 * Detecta problemas de compatibilidad con un tema
	 *
	 * @param string $theme_slug Slug del tema
	 * @return array Problemas detectados
	 */
	private static function detect_theme_compatibility_issues( $theme_slug ) {
		$issues = array();
		// Test dinámico: versión mínima de WordPress
		global $wp_version;
		if ( version_compare( $wp_version, '6.0', '<' ) ) {
			$issues[] = __( 'La versión de WordPress es inferior a 6.0. Algunas funciones pueden no estar soportadas.', 'mi-integracion-api' );
		}
		// Test dinámico: versión mínima de PHP
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$issues[] = __( 'La versión de PHP es inferior a 7.4. El plugin puede no funcionar correctamente.', 'mi-integracion-api' );
		}
		// Test dinámico: hooks críticos del tema
		$critical_hooks = array( 'wp_head', 'wp_footer' );
		foreach ( $critical_hooks as $hook ) {
			if ( ! has_action( $hook ) ) {
				$issues[] = sprintf( __( 'El tema no ejecuta el hook crítico %s. Algunas funcionalidades pueden no mostrarse.', 'mi-integracion-api' ), $hook );
			}
		}
		// Test dinámico: conflicto de clases comunes
		if ( class_exists( 'ReduxFramework' ) ) {
			$issues[] = __( 'Se detectó ReduxFramework activo. Puede causar conflictos de estilos en la administración.', 'mi-integracion-api' );
		}
		return $issues;
	}
	/**
	 * Detecta problemas de compatibilidad con un plugin
	 *
	 * @param string $plugin_slug Slug del plugin
	 * @return array Problemas detectados
	 */
	private static function detect_plugin_compatibility_issues( $plugin_slug ) {
		$issues = array();
		// Test dinámico: versión mínima de PHP
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			$issues[] = __( 'La versión de PHP es inferior a 7.4. El plugin puede no funcionar correctamente.', 'mi-integracion-api' );
		}
		// Test dinámico: si WooCommerce está activo y el plugin depende de él
		if ( strpos( $plugin_slug, 'woocommerce' ) !== false && ! class_exists( 'WooCommerce' ) ) {
			$issues[] = __( 'El plugin parece requerir WooCommerce, pero WooCommerce no está activo.', 'mi-integracion-api' );
		}
		// Test dinámico: conflicto de clases comunes
		if ( class_exists( 'ReduxFramework' ) ) {
			$issues[] = __( 'Se detectó ReduxFramework activo. Puede causar conflictos de estilos en la administración.', 'mi-integracion-api' );
		}
		// Test dinámico: hooks críticos del plugin
		$critical_hooks = array( 'init', 'plugins_loaded' );
		foreach ( $critical_hooks as $hook ) {
			if ( ! has_action( $hook ) ) {
				$issues[] = sprintf( __( 'El plugin no ejecuta el hook crítico %s. Algunas funcionalidades pueden no estar disponibles.', 'mi-integracion-api' ), $hook );
			}
		}
		return $issues;
	}
	/**
	 * Obtiene datos de un plugin por su slug
	 *
	 * @param string $plugin_slug Slug del plugin
	 * @return array Datos del plugin
	 */
	private static function get_plugin_data_by_slug( $plugin_slug ) {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );

		foreach ( $active_plugins as $plugin_path ) {
			if ( ! isset( $all_plugins[ $plugin_path ] ) ) {
				continue;
			}

			$path_slug = dirname( plugin_basename( $plugin_path ) );

			// Si es un plugin del directorio principal (sin directorio), usar el filename
			if ( $path_slug === '.' ) {
				$path_slug = basename( $plugin_path, '.php' );
			}

			if ( $path_slug === $plugin_slug ) {
				return array(
					'name'    => $all_plugins[ $plugin_path ]['Name'],
					'version' => $all_plugins[ $plugin_path ]['Version'],
					'path'    => $plugin_path,
				);
			}
		}

		return array();
	}
}

// Inicializar la clase
add_action( 'plugins_loaded', array( 'MI_Compatibility_Report', 'init' ) );
