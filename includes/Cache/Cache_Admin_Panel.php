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

use MiIntegracionApi\Core\CacheConfig;

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
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );

		// Registrar acciones AJAX para gestionar caché
		add_action( 'wp_ajax_mi_api_flush_cache', array( $this, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_mi_api_toggle_cache', array( $this, 'ajax_toggle_cache' ) );
		add_action( 'wp_ajax_mi_api_update_cache_ttl', array( $this, 'ajax_update_cache_ttl' ) );
		add_action( 'wp_ajax_mi_api_invalidate_entity_cache', array( $this, 'ajax_invalidate_entity_cache' ) );
		add_action( 'wp_ajax_mi_api_update_storage_method', array( $this, 'ajax_update_storage_method' ) );
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
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_mi_api_update_cache_ttl', array( $this, 'ajax_update_cache_ttl' ) );
		add_action( 'wp_ajax_mi_api_flush_cache', array( $this, 'ajax_flush_cache' ) );
		add_action( 'wp_ajax_mi_api_toggle_cache', array( $this, 'ajax_toggle_cache' ) );
		add_action( 'wp_ajax_mi_api_update_storage_method', array( $this, 'ajax_update_storage_method' ) );
	}

	/**
	 * Añade la página de administración de caché al menú
	 */
	public function add_menu_page() {
		add_submenu_page(
			'mi-integracion-api',
			__('Administración de Caché', 'mi-integracion-api'),
			__('Caché', 'mi-integracion-api'),
			'manage_options',
			'mi-api-cache',
			array($this, 'render_page')
		);
	}

	/**
	 * Carga los scripts y estilos necesarios
	 */
	public function enqueue_scripts($hook) {
		if ($hook !== 'mi-integracion-api_page_mi-api-cache') {
			return;
		}

		wp_enqueue_style(
			'mi-api-cache-admin',
			plugin_dir_url(dirname(__FILE__)) . 'assets/css/cache-admin.css',
			array(),
			MI_INTEGRACION_API_VERSION
		);

		wp_enqueue_script(
			'mi-api-cache-admin',
			plugin_dir_url(dirname(__FILE__)) . 'assets/js/cache-admin.js',
			array('jquery'),
			MI_INTEGRACION_API_VERSION,
			true
		);

		wp_localize_script(
			'mi-api-cache-admin',
			'miApiCacheAdmin',
			array(
				'ajaxUrl' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('mi_api_cache_admin'),
				'i18n' => array(
					'confirmFlush' => __('¿Está seguro de que desea limpiar toda la caché?', 'mi-integracion-api'),
					'confirmToggle' => __('¿Está seguro de que desea cambiar el estado de la caché?', 'mi-integracion-api'),
				),
			)
		);
	}

	/**
	 * Renderiza la página de administración de caché
	 */
	public function render_page() {
		if (!current_user_can('manage_options')) {
			wp_die(__('No tiene permisos para acceder a esta página.', 'mi-integracion-api'));
		}

		$cache_enabled = CacheConfig::is_enabled();
		$cache_ttl = CacheConfig::get_default_ttl();
		$storage_method = CacheConfig::get_storage_method();
		$stats = HTTP_Cache_Manager::get_extended_stats();

		include plugin_dir_path(dirname(__FILE__)) . 'templates/admin/cache-admin-panel.php';
	}

	/**
	 * Maneja la solicitud AJAX para actualizar el TTL de caché
	 */
	public function ajax_update_cache_ttl() {
		// Verificar nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_api_cache_admin')) {
			wp_send_json_error(__('Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api'));
		}

		// Verificar permisos
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('No tiene permisos para realizar esta acción.', 'mi-integracion-api'));
		}

		// Verificar datos
		if (!isset($_POST['ttl']) || !is_numeric($_POST['ttl']) || intval($_POST['ttl']) < 60) {
			wp_send_json_error(__('El tiempo de vida debe ser un número entero mayor o igual a 60 segundos.', 'mi-integracion-api'));
		}

		// Actualizar TTL
		$ttl = intval($_POST['ttl']);
		CacheConfig::set_default_ttl($ttl);

		wp_send_json_success(array(
			'message' => sprintf(
				__('Tiempo de vida de caché actualizado a %d segundos.', 'mi-integracion-api'),
				$ttl
			),
			'ttl' => $ttl,
		));
	}

	/**
	 * Maneja la solicitud AJAX para limpiar la caché
	 */
	public function ajax_flush_cache() {
		// Verificar nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_api_cache_admin')) {
			wp_send_json_error(__('Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api'));
		}

		// Verificar permisos
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('No tiene permisos para realizar esta acción.', 'mi-integracion-api'));
		}

		// Limpiar caché
		$count = HTTP_Cache_Manager::flush_all();

		wp_send_json_success(array(
			'message' => sprintf(
				__('Se han eliminado %d entradas de caché.', 'mi-integracion-api'),
				$count
			),
			'count' => $count,
		));
	}

	/**
	 * Maneja la solicitud AJAX para activar/desactivar la caché
	 */
	public function ajax_toggle_cache() {
		// Verificar nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_api_cache_admin')) {
			wp_send_json_error(__('Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api'));
		}

		// Verificar permisos
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('No tiene permisos para realizar esta acción.', 'mi-integracion-api'));
		}

		// Verificar datos
		if (!isset($_POST['enabled'])) {
			wp_send_json_error(__('Estado de caché no especificado.', 'mi-integracion-api'));
		}

		// Actualizar estado
		$enabled = filter_var($_POST['enabled'], FILTER_VALIDATE_BOOLEAN);
		CacheConfig::set_enabled($enabled);

		wp_send_json_success(array(
			'message' => $enabled
				? __('Caché activada correctamente.', 'mi-integracion-api')
				: __('Caché desactivada correctamente.', 'mi-integracion-api'),
			'enabled' => $enabled,
		));
	}

	/**
	 * Maneja la solicitud AJAX para actualizar el método de almacenamiento
	 */
	public function ajax_update_storage_method() {
		// Verificar nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'mi_api_cache_admin')) {
			wp_send_json_error(__('Error de seguridad. Recargue la página e intente de nuevo.', 'mi-integracion-api'));
		}

		// Verificar permisos
		if (!current_user_can('manage_options')) {
			wp_send_json_error(__('No tiene permisos para realizar esta acción.', 'mi-integracion-api'));
		}

		// Verificar datos
		if (!isset($_POST['method'])) {
			wp_send_json_error(__('Método de almacenamiento no especificado.', 'mi-integracion-api'));
		}

		// Actualizar método
		$method = sanitize_text_field($_POST['method']);
		if (!CacheConfig::set_storage_method($method)) {
			wp_send_json_error(__('Método de almacenamiento no válido.', 'mi-integracion-api'));
		}

		wp_send_json_success(array(
			'message' => sprintf(
				__('Método de almacenamiento actualizado a %s.', 'mi-integracion-api'),
				$method
			),
			'method' => $method,
		));
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
