<?php
/**
 * Página de prueba para compatibilidad con HPOS
 *
 * @package MiIntegracionApi\WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para la página de prueba de compatibilidad con HPOS
 */
class HposTestPage {
	/**
	 * Inicializa la página de prueba
	 */
	public static function init() {
		// No registrar menú aquí para evitar duplicidad
		// add_action( 'admin_menu', array( self::class, 'register_menu_page' ) );
		// Solo instanciar para AJAX y assets
		self::get_instance();
	}

	/**
	 * Registra la página en el menú
	 */
	public static function register_menu_page() {
		// add_submenu_page(
		//     'mi-integracion-api',
		//     __( 'Prueba de compatibilidad HPOS', 'mi-integracion-api' ),
		//     __( 'Prueba HPOS', 'mi-integracion-api' ),
		//     'manage_options',
		//     'mi-integracion-api-hpos-test',
		//     array( self::class, 'render_page' )
		// );
	}

	/**
	 * Registra scripts y estilos
	 */
	public static function enqueue_assets( $hook ) {
		if ( $hook !== 'mi-integracion-api_page_mi-integracion-api-hpos-test' ) {
			return;
		}

		// Registrar estilos
		wp_enqueue_style(
			'mi-integracion-api-hpos-test',
			plugins_url( '/assets/css/hpos-test.css', MiIntegracionApi_PLUGIN_FILE ),
			array(),
			MiIntegracionApi_VERSION
		);

		// Registrar scripts
		wp_enqueue_script(
			'mi-integracion-api-hpos-test',
			plugins_url( '/assets/js/hpos-test.js', MiIntegracionApi_PLUGIN_FILE ),
			array( 'jquery' ),
			MiIntegracionApi_VERSION,
			true
		);

		// Localizar script
		wp_localize_script(
			'mi-integracion-api-hpos-test',
			'mi_hpos_test',
			array(
				'ajax_url'       => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( 'mi-hpos-test' ),
				'is_hpos_active' => class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
									method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) &&
									\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
				'i18n'           => array(
					'validating' => __( 'Validando...', 'mi-integracion-api' ),
					'migrating'  => __( 'Migrando...', 'mi-integracion-api' ),
					'success'    => __( 'Éxito', 'mi-integracion-api' ),
					'error'      => __( 'Error', 'mi-integracion-api' ),
					'warning'    => __( 'Advertencia', 'mi-integracion-api' ),
				),
			)
		);
	}

	/**
	 * Renderiza la página
	 */
	public static function render_page() {
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permisos para acceder a esta página.', 'mi-integracion-api' ) );
		}

		// Verificar si WooCommerce está activo
		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<div class="wrap">
				<h1><?php _e( 'Prueba de compatibilidad HPOS', 'mi-integracion-api' ); ?></h1>
				<div class="notice notice-error">
					<p><?php _e( 'WooCommerce no está activado. Esta página requiere WooCommerce.', 'mi-integracion-api' ); ?></p>
				</div>
			</div>
			<?php
			return;
		}

		// Verificar si HPOS está habilitado
		$is_hpos_active = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) &&
							method_exists( '\Automattic\WooCommerce\Utilities\OrderUtil', 'custom_orders_table_usage_is_enabled' ) &&
							\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		// Cargar la clase de validación si es necesario
		if (!class_exists('\MiIntegracionApi\WooCommerce\HposValidation')) {
			require_once MiIntegracionApi_PLUGIN_DIR . 'includes/WooCommerce/HposValidation.php';
		}

		?>
		<div class="wrap">
			<h1><?php _e( 'Prueba de compatibilidad HPOS', 'mi-integracion-api' ); ?></h1>
			
			<div class="notice notice-info">
				<p>
					<?php _e( 'Esta página te permite validar la compatibilidad del plugin con el Almacenamiento de Pedidos de Alto Rendimiento (HPOS) de WooCommerce.', 'mi-integracion-api' ); ?>
				</p>
			</div>
			
			<div class="card">
				<h2><?php _e( 'Estado de HPOS', 'mi-integracion-api' ); ?></h2>
				<p>
					<?php if ( $is_hpos_active ) : ?>
						<span class="mi-hpos-active"><?php _e( 'HPOS está activado', 'mi-integracion-api' ); ?></span>
					<?php else : ?>
						<span class="mi-hpos-inactive"><?php _e( 'HPOS está desactivado', 'mi-integracion-api' ); ?></span>
					<?php endif; ?>
				</p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ); ?>" class="button">
						<?php _e( 'Configurar HPOS en WooCommerce', 'mi-integracion-api' ); ?>
					</a>
				</p>
			</div>
			
			<div class="card">
				<h2><?php _e( 'Validación de compatibilidad', 'mi-integracion-api' ); ?></h2>
				<p><?php _e( 'Ejecuta una validación completa para verificar que el plugin es compatible con HPOS.', 'mi-integracion-api' ); ?></p>
				<button id="mi-run-validation" class="button button-primary">
					<?php _e( 'Ejecutar validación', 'mi-integracion-api' ); ?>
				</button>
				
				<div id="mi-validation-results" class="mi-results" style="display: none;"></div>
			</div>
			
			<?php if ( $is_hpos_active ) : ?>
			<div class="card">
				<h2><?php _e( 'Migración de metadatos', 'mi-integracion-api' ); ?></h2>
				<p><?php _e( 'Si acabas de activar HPOS, es posible que necesites migrar los metadatos de pedidos antiguos.', 'mi-integracion-api' ); ?></p>
				<div class="mi-button-group">
					<button id="mi-check-meta" class="button">
						<?php _e( 'Comprobar metadatos', 'mi-integracion-api' ); ?>
					</button>
					<button id="mi-migrate-meta" class="button button-secondary" disabled>
						<?php _e( 'Migrar metadatos', 'mi-integracion-api' ); ?>
					</button>
				</div>
				
				<div id="mi-migration-results" class="mi-results" style="display: none;"></div>
			</div>
			<?php endif; ?>
			
			<div class="card">
				<h2><?php _e( 'Documentación', 'mi-integracion-api' ); ?></h2>
				<p><?php _e( 'El plugin utiliza las siguientes clases y métodos para garantizar la compatibilidad con HPOS:', 'mi-integracion-api' ); ?></p>
				<ul>
					<li><code>MiIntegracionApi\WooCommerce\HposCompatibility</code> - Clase principal de compatibilidad</li>
					<li><code>manage_order_meta()</code> - Método para gestionar metadatos de pedidos</li>
					<li><code>get_order_meta()</code> - Método para obtener metadatos de pedidos</li>
					<li><code>update_order_meta()</code> - Método para actualizar metadatos de pedidos</li>
					<li><code>delete_order_meta()</code> - Método para eliminar metadatos de pedidos</li>
				</ul>
				<p><?php _e( 'Además, se proporcionan funciones helper en el namespace MiIntegracionApi\Helpers:', 'mi-integracion-api' ); ?></p>
				<ul>
					<li><code>get_meta_safe()</code> - Función para obtener metadatos de forma segura</li>
					<li><code>update_meta_safe()</code> - Función para actualizar metadatos de forma segura</li>
					<li><code>delete_meta_safe()</code> - Función para eliminar metadatos de forma segura</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Handler AJAX para ejecutar validaciones
	 */
	public static function ajax_run_validation() {
		// Verificar nonce
		check_ajax_referer( 'mi-hpos-test', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ),
				)
			);
			return;
		}

		// Cargar la clase de validación
		if (!class_exists('\MiIntegracionApi\WooCommerce\HposValidation')) {
			require_once MiIntegracionApi_PLUGIN_DIR . 'includes/WooCommerce/HposValidation.php';
		}

		// Ejecutar validaciones
		$results = HposValidation::run_all_validations();

		// Devolver resultados
		wp_send_json_success( $results );
	}

	/**
	 * Handler AJAX para migrar metadatos
	 */
	public static function ajax_migrate_meta() {
		// Verificar nonce
		check_ajax_referer( 'mi-hpos-test', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ),
				)
			);
			return;
		}

		// Cargar la clase de validación
		if (!class_exists('\MiIntegracionApi\WooCommerce\HposValidation')) {
			require_once MiIntegracionApi_PLUGIN_DIR . 'includes/WooCommerce/HposValidation.php';
		}

		// Determinar si es una ejecución real o simulada
		$dry_run = isset( $_POST['dry_run'] ) ? (bool) $_POST['dry_run'] : true;

		// Ejecutar migración
		$results = HposValidation::migrate_order_meta( $dry_run );

		// Devolver resultados
		wp_send_json_success( $results );
	}

	/**
	 * Método para renderizar la página desde AdminMenu
	 */
	public function render() {
		self::render_page();
	}
}

// Inicializar la página si estamos en el admin
if ( is_admin() ) {
	HposTestPage::init();
}
