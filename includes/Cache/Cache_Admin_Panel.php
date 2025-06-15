<?php
/**
 * Panel de Administración de Caché HTTP
 *
 * Implementa una interfaz de usuario para administrar y visualizar
 * el estado del sistema de caché HTTP del plugin.
 *
 * @package MiIntegracionApi\Cache
 * @since 1.0.0
 */

namespace MiIntegracionApi\Cache;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase que crea y gestiona el panel de administración de caché HTTP
 */
class Cache_Admin_Panel {

	/**
	 * Instancia de la clase
	 *
	 * @var Cache_Admin_Panel
	 */
	private static $instance = null;

	/**
	 * Slug de la página de administración
	 *
	 * @var string
	 */
	private $page_slug = 'mi-integracion-api-cache';

	/**
	 * Constructor
	 */
	private function __construct() {
		// Registrar menú y página de administración
		// add_action( 'admin_menu', array( $this, 'register_admin_page' ) ); // <--- DEBE PERMANECER COMENTADO PARA EVITAR DUPLICIDAD

		// Registrar acciones AJAX para gestionar caché
		add_action( 'wp_ajax_mi_api_flush_cache', array( $this, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_mi_api_toggle_cache', array( $this, 'ajax_toggle_cache_status' ) );
		add_action( 'wp_ajax_mi_api_update_cache_ttl', array( $this, 'ajax_update_cache_ttl' ) );
		add_action( 'wp_ajax_mi_api_invalidate_entity_cache', array( $this, 'ajax_invalidate_entity_cache' ) );
	}

	/**
	 * Obtiene la instancia única de la clase
	 *
	 * @return Cache_Admin_Panel
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Inicializa el panel de administración
	 */
	public static function init() {
		// No registrar menú aquí para evitar duplicidad
		self::get_instance();
	}
	
	/**
	 * Método de compatibilidad para cuando la clase es llamada estáticamente
	 * 
	 * @return void
	 */
	public static function render_page() {
		$instance = self::get_instance();
		$instance->render_admin_page();
	}

	/**
	 * Registra la página de administración en el menú de WordPress
	 */
	public function register_admin_page() {
		// add_submenu_page(
		//     'mi-integracion-api',
		//     __( 'Administración de Caché', 'mi-integracion-api' ),
		//     __( 'Caché HTTP', 'mi-integracion-api' ),
		//     'manage_options',
		//     $this->page_slug,
		//     array( $this, 'render_admin_page' )
		// );

		// Registrar assets
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
	}

	/**
	 * Carga los assets necesarios para la página de administración
	 *
	 * @param string $hook_suffix El hook de la página actual
	 */
	public function enqueue_admin_assets( $hook_suffix ) {
		if ( strpos( $hook_suffix, $this->page_slug ) === false ) {
			return;
		}

		// Estilos
		wp_enqueue_style(
			'mi-integracion-api-cache-admin',
			plugins_url( '/assets/css/cache-admin.css', MiIntegracionApi_PLUGIN_FILE ),
			array(),
			MiIntegracionApi_VERSION
		);

		// Scripts
		wp_enqueue_script(
			'mi-integracion-api-cache-admin',
			plugins_url( '/assets/js/cache-admin.js', MiIntegracionApi_PLUGIN_FILE ),
			array( 'jquery', 'wp-util' ),
			MiIntegracionApi_VERSION,
			true
		);

		// Localizar script
		wp_localize_script(
			'mi-integracion-api-cache-admin',
			'miApiCacheAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mi_api_cache_admin' ),
				'i18n'    => array(
					'confirmFlush'       => __( '¿Estás seguro de que deseas limpiar toda la caché? Esta acción no se puede deshacer.', 'mi-integracion-api' ),
					'confirmEntityFlush' => __( '¿Estás seguro de que deseas limpiar la caché de esta entidad? Esta acción no se puede deshacer.', 'mi-integracion-api' ),
					'success'            => __( 'Operación completada con éxito', 'mi-integracion-api' ),
					'error'              => __( 'Ha ocurrido un error al procesar la solicitud', 'mi-integracion-api' ),
				),
			)
		);
	}

	/**
	 * Renderiza la página de administración de caché
	 */
	public function render_admin_page() {
		// Obtener configuración de caché
		$cache_enabled = (bool) get_option( 'mi_integracion_api_enable_http_cache', true );
		$cache_ttl     = (int) get_option( 'mi_integracion_api_http_cache_ttl', 14400 );

		// Obtener estadísticas de caché
		$stats = \MiIntegracionApi\Cache\HTTP_Cache_Manager::get_cache_stats();

		// Incluir template
		include MiIntegracionApi_PLUGIN_DIR . 'templates/admin/cache-admin-panel.php';
	}

	/**
	 * Maneja la solicitud AJAX para limpiar la caché
	 */
	public function ajax_flush_cache() {
		// Verificar nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mi_api_cache_admin' ) ) {
			wp_send_json_error( __( 'Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api' ) );
		}

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No tiene permisos para realizar esta acción.', 'mi-integracion-api' ) );
		}

		// Limpiar caché
		$result = HTTP_Cache_Manager::flush_all_cache();

		if ( $result ) {
			wp_send_json_success( __( 'Caché limpiada con éxito.', 'mi-integracion-api' ) );
		} else {
			wp_send_json_error( __( 'Error al limpiar la caché. Intente de nuevo.', 'mi-integracion-api' ) );
		}
	}

	/**
	 * Maneja la solicitud AJAX para cambiar el estado de la caché
	 */
	public function ajax_toggle_cache_status() {
		// Verificar nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mi_api_cache_admin' ) ) {
			wp_send_json_error( __( 'Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api' ) );
		}

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No tiene permisos para realizar esta acción.', 'mi-integracion-api' ) );
		}

		// Verificar datos
		if ( ! isset( $_POST['enabled'] ) || ! in_array( $_POST['enabled'], array( 'true', 'false' ) ) ) {
			wp_send_json_error( __( 'Datos no válidos.', 'mi-integracion-api' ) );
		}

		// Cambiar estado
		$enabled = $_POST['enabled'] === 'true';
		update_option( 'mi_integracion_api_enable_http_cache', $enabled );

		wp_send_json_success(
			array(
				'message' => $enabled
					? __( 'Caché HTTP habilitada.', 'mi-integracion-api' )
					: __( 'Caché HTTP deshabilitada.', 'mi-integracion-api' ),
				'enabled' => $enabled,
			)
		);
	}

	/**
	 * Maneja la solicitud AJAX para actualizar el TTL de la caché
	 */
	public function ajax_update_cache_ttl() {
		// Verificar nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mi_api_cache_admin' ) ) {
			wp_send_json_error( __( 'Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api' ) );
		}

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No tiene permisos para realizar esta acción.', 'mi-integracion-api' ) );
		}

		// Verificar datos
		if ( ! isset( $_POST['ttl'] ) || ! is_numeric( $_POST['ttl'] ) || intval( $_POST['ttl'] ) < 60 ) {
			wp_send_json_error( __( 'El tiempo de vida debe ser un número entero mayor o igual a 60 segundos.', 'mi-integracion-api' ) );
		}

		// Actualizar TTL
		$ttl = intval( $_POST['ttl'] );
		update_option( 'mi_integracion_api_http_cache_ttl', $ttl );

		wp_send_json_success(
			array(
				'message' => sprintf(
					__( 'Tiempo de vida de caché actualizado a %d segundos.', 'mi-integracion-api' ),
					$ttl
				),
				'ttl'     => $ttl,
			)
		);
	}

	/**
	 * Maneja la solicitud AJAX para invalidar la caché de una entidad específica
	 */
	public function ajax_invalidate_entity_cache() {
		// Verificar nonce
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( $_POST['nonce'], 'mi_api_cache_admin' ) ) {
			wp_send_json_error( __( 'Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api' ) );
		}

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'No tiene permisos para realizar esta acción.', 'mi-integracion-api' ) );
		}

		// Verificar datos
		if ( ! isset( $_POST['entity'] ) || ! in_array( $_POST['entity'], array( 'product', 'order', 'config', 'global' ) ) ) {
			wp_send_json_error( __( 'Entidad no válida.', 'mi-integracion-api' ) );
		}

		// Invalidar caché
		$entity = $_POST['entity'];
		$result = false;

		switch ( $entity ) {
			case 'product':
				$result = HTTP_Cache_Manager::flush_product_cache();
				break;
			case 'order':
				$result = HTTP_Cache_Manager::flush_order_cache();
				break;
			case 'config':
				$result = HTTP_Cache_Manager::flush_config_cache();
				break;
			case 'global':
				$result = HTTP_Cache_Manager::flush_global_cache();
				break;
		}

		if ( $result ) {
			wp_send_json_success(
				array(
					'message' => sprintf(
						__( 'Caché de %s limpiada con éxito.', 'mi-integracion-api' ),
						$this->get_entity_name( $entity )
					),
				)
			);
		} else {
			wp_send_json_error( __( 'Error al limpiar la caché. Intente de nuevo.', 'mi-integracion-api' ) );
		}
	}

	/**
	 * Obtiene el nombre localizado de una entidad
	 *
	 * @param string $entity Clave de la entidad
	 * @return string Nombre localizado
	 */
	private function get_entity_name( $entity ) {
		$names = array(
			'product' => __( 'productos', 'mi-integracion-api' ),
			'order'   => __( 'órdenes', 'mi-integracion-api' ),
			'config'  => __( 'configuración', 'mi-integracion-api' ),
			'global'  => __( 'datos globales', 'mi-integracion-api' ),
		);

		return isset( $names[ $entity ] ) ? $names[ $entity ] : $entity;
	}
}
