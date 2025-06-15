<?php
/**
 * Clase para gestionar los activos (CSS y JavaScript) del plugin.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 */

namespace MiIntegracionApi;



// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar los activos del plugin.
 *
 * Esta clase se encarga de registrar y cargar todos los estilos y scripts
 * necesarios para el funcionamiento del plugin, tanto en el área de administración
 * como en el frontend público del sitio.
 *
 * @since 1.0.0
 */
class Assets {

	/**
	 * El ID único de este plugin.
	 *
	 * @since 1.0.0
	 * @access   private
	 * @var      string    $plugin_name    El nombre que identifica este plugin.
	 */
	private $plugin_name;

	/**
	 * La versión actual del plugin.
	 *
	 * @since 1.0.0
	 * @access   private
	 * @var      string    $version    La versión actual del plugin.
	 */
	private $version;

	/**
	 * Ruta URL al directorio de activos.
	 *
	 * @since 1.0.0
	 * @access   private
	 * @var      string    $assets_url    URL al directorio de activos.
	 */
	private $assets_url;

	/**
	 * Inicializa la clase y establece sus propiedades.
	 *
	 * @since 1.0.0
	 * @param    string $plugin_name    El nombre del plugin.
	 * @param    string $version        La versión del plugin.
	 */
	public function __construct( $plugin_name, $version ) {
		$this->plugin_name = $plugin_name;
		$this->version     = $version;
		$this->assets_url  = MiIntegracionApi_PLUGIN_URL . 'assets/';
	}

	/**
	 * Registra los estilos para el área de administración.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_admin_styles( $hook ) {
		// Verificar si estamos en una página del plugin
		if ( ! $this->is_plugin_admin_page( $hook ) ) {
			return;
		}

		// Cargar CSS de compatibilidad primero para asegurar que los estilos específicos lo sobrescriban
		wp_enqueue_style(
			$this->plugin_name . '-compatibility',
			$this->assets_url . 'css/class-compatibility.css',
			array(),
			$this->version,
			'all'
		);

		// Estilos comunes de administración
		wp_enqueue_style(
			$this->plugin_name . '-admin',
			$this->assets_url . 'css/admin.css',
			array($this->plugin_name . '-compatibility'),
			$this->version,
			'all'
		);

		// Dashboard específico
		if ( strpos( $hook, 'mi-integracion-dashboard' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-dashboard',
				$this->assets_url . 'css/dashboard.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}

		// Página de configuración
		if ( strpos( $hook, 'mi-integracion-settings' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-settings',
				$this->assets_url . 'css/settings.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}

		// Página de logs (usando el nuevo visor seguro)
		if ( strpos( $hook, 'mi-integracion-logs' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-logs-viewer-secure',
				$this->assets_url . 'css/logs-viewer-secure.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}

		// Página de diagnóstico API
		if ( strpos( $hook, 'mi-integracion-api-diagnostic' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-diagnostic',
				$this->assets_url . 'css/api-diagnostic.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}

		// Página de compatibilidad
		if ( strpos( $hook, 'mi-integracion-compatibility' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-compatibility',
				$this->assets_url . 'css/compatibility-report.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}

		// Estilos para HPOS (High-Performance Order Storage)
		if ( strpos( $hook, 'mi-integracion-hpos-test' ) !== false ) {
			wp_enqueue_style(
				$this->plugin_name . '-hpos',
				$this->assets_url . 'css/hpos-test.css',
				array( $this->plugin_name . '-admin' ),
				$this->version,
				'all'
			);
		}
	}

	/**
	 * Registra los scripts para el área de administración.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_admin_scripts( $hook ) {
		// Verificar si estamos en una página del plugin
		if ( ! $this->is_plugin_admin_page( $hook ) ) {
			return;
		}

		// --- Nuevo: Script de utilidades común para todos los scripts admin---
		wp_enqueue_script(
			$this->plugin_name . '-utils',
			$this->assets_url . 'js/utils.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Scripts comunes de administración, ahora usando admin-main.js
		wp_enqueue_script(
			$this->plugin_name . '-admin-main',
			$this->assets_url . 'js/admin-main.js',
			array( 'jquery', $this->plugin_name . '-utils' ), // Depende de utils.js
			$this->version,
			true
		);

		// Localizar script de administración común (ahora para admin-main.js)
		wp_localize_script(
			$this->plugin_name . '-admin-main',
			'miIntegracionApi',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'restUrl'   => esc_url_raw( rest_url( MiIntegracionApi_TEXT_DOMAIN . '/v1/' ) ),
				'nonce'     => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'admin' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'      => array(
					'loading' => __( 'Cargando...', MiIntegracionApi_TEXT_DOMAIN ),
					'error'   => __( 'Error: ', MiIntegracionApi_TEXT_DOMAIN ),
					'success' => __( 'Éxito: ', MiIntegracionApi_TEXT_DOMAIN ),
					'confirm' => __( '¿Estás seguro?', MiIntegracionApi_TEXT_DOMAIN ),
					'cancel'  => __( 'Cancelar', MiIntegracionApi_TEXT_DOMAIN ),
					'ok'      => __( 'Aceptar', MiIntegracionApi_TEXT_DOMAIN ),
					'confirmClearLogs' => __( '¿Estás seguro de que deseas borrar todos los registros? Esta acción no se puede deshacer.', MiIntegracionApi_TEXT_DOMAIN ),
					'noLogsFound' => __( 'No hay logs disponibles.', MiIntegracionApi_TEXT_DOMAIN ),
					'genericError' => __( 'Ha ocurrido un error inesperado.', MiIntegracionApi_TEXT_DOMAIN ),
					'clearLogsError' => __( 'Error al limpiar registros.', MiIntegracionApi_TEXT_DOMAIN ),
					'exportNotImplemented' => __( 'La función de exportación aún no está implementada.', MiIntegracionApi_TEXT_DOMAIN ),
				),
				'debug'     => (bool) get_option( MiIntegracionApi_OPTION_PREFIX . 'debug_mode', false ),
				'version'   => $this->version,
			)
		);

		// Dashboard y páginas relacionadas: siempre cargar el objeto de localización para evitar errores JS
		// Cargamos el script solo si existe el archivo, pero localizamos SIEMPRE el objeto en todas las páginas del plugin
		$should_enqueue_dashboard = (
			strpos( $hook, 'mi-integracion-dashboard' ) !== false
		);
		if ( $should_enqueue_dashboard ) {
			wp_enqueue_script(
				$this->plugin_name . '-dashboard',
				$this->assets_url . 'js/dashboard.js',
				array( 'jquery', $this->plugin_name . '-admin-main', 'wp-util' ),
				$this->version,
				true
			);
		}
		// Localizar SIEMPRE el objeto miIntegracionApiDashboard en todas las páginas del plugin
		wp_localize_script(
			$this->plugin_name . '-dashboard',
			'miIntegracionApiDashboard',
			array(
				'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'dashboard' ),
				'actions' => array(
					'refreshStats' => MiIntegracionApi_OPTION_PREFIX . 'refresh_dashboard_stats',
					'syncNow'      => MiIntegracionApi_OPTION_PREFIX . 'sync_now',
				),
				'i18n'    => array(
					'syncStarted'  => __( 'Sincronización iniciada. Por favor espere...', MiIntegracionApi_TEXT_DOMAIN ),
					'syncFinished' => __( 'Sincronización completada.', MiIntegracionApi_TEXT_DOMAIN ),
					'syncError'    => __( 'Error durante la sincronización: ', MiIntegracionApi_TEXT_DOMAIN ),
					'confirmSync'  => __( '¿Estás seguro de que deseas iniciar una sincronización manual ahora?', MiIntegracionApi_TEXT_DOMAIN ),
				),
			)
		);

		// Página de logs - Ahora usa logs-viewer-main.js
		if ( strpos( $hook, 'mi-integracion-logs' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-logs-viewer-main',
				$this->assets_url . 'js/logs-viewer-main.js',
				array( 'jquery', $this->plugin_name . '-admin-main', $this->plugin_name . '-utils' ), // Depende de admin-main y utils
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-logs-viewer-main',
				'miIntegracionApiLogs',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'logs' ),
					'restUrl' => esc_url_raw( rest_url( MiIntegracionApi_TEXT_DOMAIN . '/v1/' ) ),
					'restNonce' => wp_create_nonce( 'wp_rest' ),
					'i18n'    => array(
						'noLogsFound'    => __( 'No hay logs disponibles.', MiIntegracionApi_TEXT_DOMAIN ),
						'confirmClearLogs' => __( '¿Estás seguro de que deseas borrar todos los registros? Esta acción no se puede deshacer.', MiIntegracionApi_TEXT_DOMAIN ),
						'genericError'   => __( 'Ha ocurrido un error inesperado.', MiIntegracionApi_TEXT_DOMAIN ),
						'clearLogsError' => __( 'Error al limpiar registros.', MiIntegracionApi_TEXT_DOMAIN ),
						'exportNotImplemented' => __( 'La función de exportación aún no está implementada.', MiIntegracionApi_TEXT_DOMAIN ),
						'logId'          => __( 'ID', MiIntegracionApi_TEXT_DOMAIN ),
						'logType'        => __( 'Tipo', MiIntegracionApi_TEXT_DOMAIN ),
						'logDate'        => __( 'Fecha', MiIntegracionApi_TEXT_DOMAIN ),
						'logMessage'     => __( 'Mensaje', MiIntegracionApi_TEXT_DOMAIN ),
						'logContext'     => __( 'Contexto', MiIntegracionApi_TEXT_DOMAIN ),
					),
					'actions' => array(
						'getLogs' => MiIntegracionApi_OPTION_PREFIX . 'get_logs',
						'clearLogs' => MiIntegracionApi_OPTION_PREFIX . 'clear_logs',
						'getLogDetails' => MiIntegracionApi_OPTION_PREFIX . 'get_log_details',
					),
				)
			);
		}

		// Página de configuración
		if ( strpos( $hook, 'mi-integracion-settings' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-settings',
				$this->assets_url . 'js/settings.js',
				array( 'jquery', $this->plugin_name . '-admin-main', 'jquery-ui-tabs' ), // Depende de admin-main
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-settings',
				'miIntegracionApiSettings',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'settings' ),
					'actions' => array(
						'testConnection' => MiIntegracionApi_OPTION_PREFIX . 'test_connection',
						'saveSettings'   => MiIntegracionApi_OPTION_PREFIX . 'save_settings',
					),
					'i18n'    => array(
						'connectionSuccess' => __( 'Conexión exitosa a la API de Verial.', MiIntegracionApi_TEXT_DOMAIN ),
						'connectionError'   => __( 'Error de conexión: ', MiIntegracionApi_TEXT_DOMAIN ),
						'settingsSaved'     => __( 'Configuraciones guardadas con éxito.', MiIntegracionApi_TEXT_DOMAIN ),
						'savingError'       => __( 'Error al guardar configuraciones: ', MiIntegracionApi_TEXT_DOMAIN ),
					),
				)
			);
		}

		// Página de diagnóstico API
		if ( strpos( $hook, 'mi-integracion-api-diagnostic' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-diagnostic',
				$this->assets_url . 'js/api-diagnostic.js',
				array( 'jquery', $this->plugin_name . '-admin-main' ), // Depende de admin-main
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-diagnostic',
				'miIntegracionApiDiagnostic',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'api_diagnostic' ),
					'actions' => array(
						'testEndpoint' => MiIntegracionApi_OPTION_PREFIX . 'test_endpoint',
						'checkAuth'    => MiIntegracionApi_OPTION_PREFIX . 'check_auth',
					),
					'i18n'    => array(
						'testingEndpoint' => __( 'Probando endpoint...', MiIntegracionApi_TEXT_DOMAIN ),
						'endpointSuccess' => __( 'Endpoint respondió correctamente:', MiIntegracionApi_TEXT_DOMAIN ),
						'endpointError'   => __( 'Error en endpoint: ', MiIntegracionApi_TEXT_DOMAIN ),
						'checkingAuth'    => __( 'Verificando autenticación...', MiIntegracionApi_TEXT_DOMAIN ),
						'authSuccess'     => __( 'Autenticación exitosa.', MiIntegracionApi_TEXT_DOMAIN ),
						'authError'       => __( 'Error de autenticación: ', MiIntegracionApi_TEXT_DOMAIN ),
					),
				)
			);
		}

		// Página de compatibilidad
		if ( strpos( $hook, 'mi-integracion-compatibility' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-compatibility',
				$this->assets_url . 'js/compatibility-report.js',
				array( 'jquery', $this->plugin_name . '-admin-main' ), // Depende de admin-main
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-compatibility',
				'miIntegracionApiCompatibility',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'compatibility' ),
					'actions' => array(
						'runTests' => MiIntegracionApi_OPTION_PREFIX . 'run_compatibility_tests',
					),
					'i18n'    => array(
						'runningTests'  => __( 'Ejecutando pruebas de compatibilidad...', MiIntegracionApi_TEXT_DOMAIN ),
						'testsComplete' => __( 'Pruebas completadas.', MiIntegracionApi_TEXT_DOMAIN ),
						'testsError'    => __( 'Error al ejecutar pruebas: ', MiIntegracionApi_TEXT_DOMAIN ),
					),
				)
			);
		}

		// Página de test HPOS
		if ( strpos( $hook, 'mi-integracion-hpos-test' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-hpos',
				$this->assets_url . 'js/hpos-test.js',
				array( 'jquery', $this->plugin_name . '-admin-main' ), // Depende de admin-main
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-hpos',
				'miIntegracionApiHpos',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'hpos_test' ),
					'actions' => array(
						'testHpos' => MiIntegracionApi_OPTION_PREFIX . 'test_hpos_compatibility',
					),
					'i18n'    => array(
						'runningTests'  => __( 'Ejecutando pruebas de HPOS...', MiIntegracionApi_TEXT_DOMAIN ),
						'testsComplete' => __( 'Pruebas completadas.', MiIntegracionApi_TEXT_DOMAIN ),
						'testsError'    => __( 'Error al ejecutar pruebas HPOS: ', MiIntegracionApi_TEXT_DOMAIN ),
					),
				)
			);
		}

		// Página de caché
		if ( strpos( $hook, 'mi-integracion-cache' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-cache-admin',
				$this->assets_url . 'js/cache-admin.js',
				array( 'jquery', $this->plugin_name . '-admin-main' ), // Depende de admin-main
				$this->version,
				true
			);

			wp_localize_script(
				$this->plugin_name . '-cache-admin',
				'miIntegracionApiCache',
				array(
					'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'cache_admin' ),
					'actions' => array(
						'purgeCache'    => MiIntegracionApi_OPTION_PREFIX . 'purge_cache',
						'getCacheStats' => MiIntegracionApi_OPTION_PREFIX . 'get_cache_stats',
					),
					'i18n'    => array(
						'purgingCache' => __( 'Purgando caché...', MiIntegracionApi_TEXT_DOMAIN ),
						'cacheCleared' => __( 'Caché eliminada con éxito.', MiIntegracionApi_TEXT_DOMAIN ),
						'purgeError'   => __( 'Error al purgar caché: ', MiIntegracionApi_TEXT_DOMAIN ),
						'loadingStats' => __( 'Cargando estadísticas de caché...', MiIntegracionApi_TEXT_DOMAIN ),
					),
				)
			);
		}

		// Página de prueba de conexión
		if ( strpos( $hook, 'mi-integracion-connection-check' ) !== false ) {
			wp_enqueue_script(
				$this->plugin_name . '-connection-check',
				$this->assets_url . 'js/connection-check-page.js',
				array( 'jquery', $this->plugin_name . '-admin-main' ), // Depende de admin-main
				$this->version,
				true
			);

			// Localizar variables para el JS de prueba de conexión
			wp_localize_script(
				$this->plugin_name . '-connection-check',
				'mia_admin_ajax',
				array(
					'rest_url_verial' => esc_url_raw( rest_url( 'mi-integracion-api/v1/verial/check' ) ),
					'rest_url_wc'     => esc_url_raw( rest_url( 'mi-integracion-api/v1/woocommerce/check' ) ),
					'nonce'          => wp_create_nonce( 'wp_rest' ),
					'i18n'           => array(
						'checking' => __( 'Comprobando...', 'mi-integracion-api' ),
						'error'    => __( 'Error:', 'mi-integracion-api' ),
						'success'  => __( 'Conexión exitosa.', 'mi-integracion-api' ),
					),
				)
			);
		}
	}

	/**
	 * Registra los estilos para el área pública.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_public_styles() {
		// Solo cargamos estilos en el frontend si son necesarios
		$load_public_assets = apply_filters(
			'mi_integracion_api_load_public_assets',
			false
		);

		// Si el modo depuración está activo, siempre cargamos los assets
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$load_public_assets = true;
		}

		if ( ! $load_public_assets ) {
			return;
		}

		wp_enqueue_style(
			$this->plugin_name . '-frontend',
			$this->assets_url . 'css/frontend.css',
			array(),
			$this->version,
			'all'
		);
	}

	/**
	 * Registra los scripts para el área pública.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_public_scripts() {
		// Solo cargamos scripts en el frontend si son necesarios
		$load_public_assets = apply_filters(
			'mi_integracion_api_load_public_assets',
			false
		);

		// Siempre cargar el script WooCommerce si estamos en una página de producto o carrito
		if ( function_exists( 'is_product' ) && ( is_product() || is_cart() || is_checkout() ) ) {
			$load_public_assets = true;
		}

		if ( ! $load_public_assets ) {
			return;
		}

		// --- Nuevo: Script de utilidades común para todos los scripts públicos ---
		wp_enqueue_script(
			$this->plugin_name . '-utils',
			$this->assets_url . 'js/utils.js',
			array( 'jquery' ),
			$this->version,
			true
		);

		// Scripts comunes del frontend, ahora usando frontend-main.js
		wp_enqueue_script(
			$this->plugin_name . '-frontend-main',
			$this->assets_url . 'js/frontend-main.js',
			array( 'jquery', $this->plugin_name . '-utils' ), // Depende de utils.js
			$this->version,
			true
		);

		// Localizar variables para el JS del frontend (ahora para frontend-main.js)
		wp_localize_script(
			$this->plugin_name . '-frontend-main',
			'miIntegracionApi',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'frontend' ),
				'i18n'    => array(
					'confirmAction' => __( '¿Estás seguro de que deseas realizar esta acción?', MiIntegracionApi_TEXT_DOMAIN ),
					'requiredField' => __( 'Este campo es obligatorio.', MiIntegracionApi_TEXT_DOMAIN ),
					'invalidEmail'  => __( 'Por favor, introduce una dirección de correo electrónico válida.', MiIntegracionApi_TEXT_DOMAIN ),
					'genericError'  => __( 'Ha ocurrido un error inesperado al procesar la solicitud.', MiIntegracionApi_TEXT_DOMAIN ),
					'operationSuccess' => __( 'Operación realizada con éxito.', MiIntegracionApi_TEXT_DOMAIN ),
				),
			)
		);
	}

	/**
	 * Comprueba si la página actual es una página de administración del plugin.
	 *
	 * @since 1.0.0
	 * @access   private
	 * @param    string $hook    El hook de la página de administración.
	 * @return   bool            Verdadero si es una página del plugin, falso en caso contrario.
	 */
	private function is_plugin_admin_page( $hook ) {
		$plugin_pages = array(
			'toplevel_page_mi-integracion-api',
			'mi-integracion-api_page_mi-integracion-dashboard',
			'mi-integracion-api_page_mi-integracion-settings',
			'mi-integracion-api_page_mi-integracion-logs',
			'mi-integracion-api_page_mi-integracion-api-diagnostic',
			'mi-integracion-api_page_mi-integracion-compatibility',
			'mi-integracion-api_page_mi-integracion-hpos-test',
			'mi-integracion-api_page_mi-integracion-cache',
			'mi-integracion-api_page_mi-integracion-connection-check',
		);

		return in_array( $hook, $plugin_pages );
	}
}
