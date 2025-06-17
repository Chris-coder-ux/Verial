<?php
/**
 * Gestor de actualizaciones automáticas para Mi Integración API
 *
 * @package MiIntegracionApi
 */

namespace MiIntegracionApi;



// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar las actualizaciones automáticas del plugin
 */
class Updater {

	/**
	 * URL del servidor de actualizaciones
	 *
	 * @var string
	 */
	private $update_server_url;

	/**
	 * Versión actual del plugin
	 *
	 * @var string
	 */
	private $current_version;

	/**
	 * Nombre del plugin
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Ruta del archivo principal del plugin
	 *
	 * @var string
	 */
	private $plugin_file;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->plugin_slug       = 'mi-integracion-api';
		$this->plugin_file       = 'mi-integracion-api/mi-integracion-api.php';
		$this->current_version   = MiIntegracionApi_VERSION;
		$this->update_server_url = 'https://tu-servidor-de-actualizaciones.com/api/v1/updates';

		// Hooks para el sistema de actualizaciones
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_updates' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_api_call' ), 10, 3 );

		// Hooks para notificar sobre actualizaciones de WP y WooCommerce
		add_action( 'upgrader_process_complete', array( $this, 'on_wp_update' ), 10, 2 );

		// Notificar compatibilidad con nuevas versiones
		add_action( 'admin_notices', array( $this, 'compatibility_notice' ) );
	}

	/**
	 * Inicializa los hooks del actualizador
	 */
	public static function init() {
		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * Comprueba si hay actualizaciones disponibles
	 *
	 * @param object $transient Objeto transient con la información de actualizaciones
	 * @return object Objeto transient modificado
	 */
	public function check_for_updates( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		// Evitamos solicitudes repetidas al servidor de actualizaciones
		$last_check = get_site_transient( 'mi_integracion_api_update_check' );
		if ( false !== $last_check && time() - $last_check < 6 * HOUR_IN_SECONDS ) {
			return $transient;
		}

		set_site_transient( 'mi_integracion_api_update_check', time(), 6 * HOUR_IN_SECONDS );

		// Consultar servidor de actualizaciones
		$response = wp_remote_post(
			$this->update_server_url,
			array(
				'body'    => array(
					'action'     => 'check_update',
					'plugin'     => $this->plugin_slug,
					'version'    => $this->current_version,
					'wp_version' => get_bloginfo( 'version' ),
					'wc_version' => $this->get_woocommerce_version(),
					'site_url'   => home_url(),
				),
				'timeout' => 10,
			)
		);

		// Verificar respuesta
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $transient;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		// Si hay una nueva versión disponible, añadirla al transient
		if ( $response_body && isset( $response_body['new_version'] ) && version_compare( $response_body['new_version'], $this->current_version, '>' ) ) {
			$plugin_data = (object) array(
				'id'           => 'mi-integracion-api/mi-integracion-api.php',
				'slug'         => $this->plugin_slug,
				'plugin'       => $this->plugin_file,
				'new_version'  => $response_body['new_version'],
				'url'          => isset( $response_body['url'] ) ? $response_body['url'] : '',
				'package'      => isset( $response_body['package'] ) ? $response_body['package'] : '',
				'icons'        => array(),
				'banners'      => array(),
				'banners_rtl'  => array(),
				'requires'     => isset( $response_body['requires'] ) ? $response_body['requires'] : '',
				'tested'       => isset( $response_body['tested'] ) ? $response_body['tested'] : '',
				'requires_php' => isset( $response_body['requires_php'] ) ? $response_body['requires_php'] : '',
			);

			$transient->response[ $this->plugin_file ] = $plugin_data;

			// Guardar información sobre compatibilidad
			update_option(
				'mi_integracion_api_compatibility',
				array(
					'wp_compatible' => isset( $response_body['wp_compatible'] ) ? $response_body['wp_compatible'] : true,
					'wc_compatible' => isset( $response_body['wc_compatible'] ) ? $response_body['wc_compatible'] : true,
					'message'       => isset( $response_body['message'] ) ? $response_body['message'] : '',
				)
			);
		}

		return $transient;
	}

	/**
	 * Proporciona información detallada sobre la actualización
	 *
	 * @param false|object|array $result Valor predeterminado por defecto (false)
	 * @param string             $action El tipo de información solicitada
	 * @param object             $args Argumentos adicionales
	 * @return false|object
	 */
	public function plugin_api_call( $result, $action, $args ) {
		// Verificar que se está solicitando información sobre nuestro plugin
		if ( 'plugin_information' !== $action || $this->plugin_slug !== $args->slug ) {
			return $result;
		}

		// Consultar servidor para obtener información detallada
		$response = wp_remote_post(
			$this->update_server_url,
			array(
				'body'    => array(
					'action'     => 'get_info',
					'plugin'     => $this->plugin_slug,
					'version'    => $this->current_version,
					'wp_version' => get_bloginfo( 'version' ),
					'wc_version' => $this->get_woocommerce_version(),
				),
				'timeout' => 10,
			)
		);

		// Verificar respuesta
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $result;
		}

		$response_body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! $response_body ) {
			return $result;
		}

		// Crear objeto de información
		$info                 = new stdClass();
		$info->name           = 'Mi Integración API';
		$info->slug           = $this->plugin_slug;
		$info->version        = isset( $response_body['version'] ) ? $response_body['version'] : '';
		$info->author         = isset( $response_body['author'] ) ? $response_body['author'] : '';
		$info->author_profile = isset( $response_body['author_profile'] ) ? $response_body['author_profile'] : '';
		$info->requires       = isset( $response_body['requires'] ) ? $response_body['requires'] : '';
		$info->tested         = isset( $response_body['tested'] ) ? $response_body['tested'] : '';
		$info->requires_php   = isset( $response_body['requires_php'] ) ? $response_body['requires_php'] : '';
		$info->last_updated   = isset( $response_body['last_updated'] ) ? $response_body['last_updated'] : '';
		$info->sections       = isset( $response_body['sections'] ) ? (array) $response_body['sections'] : array();

		if ( empty( $info->sections['description'] ) ) {
			$info->sections['description'] = 'Plugin de integración con Verial ERP';
		}

		if ( empty( $info->sections['changelog'] ) ) {
			$info->sections['changelog'] = 'Visita el sitio web para ver el changelog completo.';
		}

		$info->download_link = isset( $response_body['download_link'] ) ? $response_body['download_link'] : '';

		return $info;
	}

	/**
	 * Se ejecuta cuando WordPress o un plugin se actualiza
	 *
	 * @param WP_Upgrader $upgrader Objeto upgrader
	 * @param array       $options Opciones del proceso de actualización
	 */
	public function on_wp_update( $upgrader, $options ) {
		// Solo nos interesa cuando se trata de WordPress core o plugins
		if ( ! isset( $options['type'] ) || ! in_array( $options['type'], array( 'core', 'plugin' ), true ) ) {
			return;
		}

		// Si es una actualización de WordPress
		if ( $options['type'] === 'core' ) {
			$this->handle_wordpress_update();
		}

		// Si es una actualización de plugin, verificar si es WooCommerce
		if ( $options['type'] === 'plugin' && isset( $options['plugins'] ) ) {
			foreach ( $options['plugins'] as $plugin ) {
				if ( strpos( $plugin, 'woocommerce/woocommerce.php' ) !== false ) {
					$this->handle_woocommerce_update();
					break;
				}
			}
		}
	}

	/**
	 * Maneja la actualización de WordPress
	 */
	private function handle_wordpress_update() {
		// Forzar comprobación de compatibilidad
		delete_site_transient( 'mi_integracion_api_update_check' );

		// Notificar la actualización por correo electrónico al administrador del sitio
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$subject     = sprintf( '[%s] WordPress actualizado - Verifica la compatibilidad', $site_name );

		$message = sprintf(
			'Hola administrador, 

WordPress se ha actualizado en tu sitio %s.
Por favor, verifica que el plugin Mi Integración API sigue funcionando correctamente.
Si necesitas asistencia, contacta con el soporte técnico.

Saludos,
Plugin Mi Integración API',
			$site_name
		);

		wp_mail( $admin_email, $subject, $message );

		// Programar una comprobación de compatibilidad
		if ( ! wp_next_scheduled( 'mi_integracion_api_check_compatibility' ) ) {
			wp_schedule_single_event( time() + 300, 'mi_integracion_api_check_compatibility' );
		}
	}

	/**
	 * Maneja la actualización de WooCommerce
	 */
	private function handle_woocommerce_update() {
		// Forzar comprobación de compatibilidad
		delete_site_transient( 'mi_integracion_api_update_check' );

		// Notificar la actualización por correo electrónico
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );
		$subject     = sprintf( '[%s] WooCommerce actualizado - Verifica la compatibilidad', $site_name );

		$message = sprintf(
			'Hola administrador, 

WooCommerce se ha actualizado en tu sitio %s.
Por favor, verifica que el plugin Mi Integración API sigue funcionando correctamente con la nueva versión.
Si necesitas asistencia, contacta con el soporte técnico.

Saludos,
Plugin Mi Integración API',
			$site_name
		);

		wp_mail( $admin_email, $subject, $message );

		// Programar una comprobación de compatibilidad
		if ( ! wp_next_scheduled( 'mi_integracion_api_check_compatibility' ) ) {
			wp_schedule_single_event( time() + 300, 'mi_integracion_api_check_compatibility' );
		}
	}

	/**
	 * Muestra avisos de compatibilidad en el panel de administración
	 */
	public function compatibility_notice() {
		// Solo mostrar en páginas de administración
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->base, array( 'dashboard', 'plugins', 'update-core' ), true ) ) {
			return;
		}

		// Obtener información de compatibilidad
		$compatibility = get_option( 'mi_integracion_api_compatibility' );
		if ( ! $compatibility ) {
			return;
		}

		// Si hay problemas de compatibilidad, mostrar aviso
		if ( isset( $compatibility['wp_compatible'] ) && $compatibility['wp_compatible'] === false ) {
			$message = isset( $compatibility['message'] ) ? $compatibility['message'] : 'Mi Integración API puede no ser totalmente compatible con esta versión de WordPress.';
			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}

		if ( isset( $compatibility['wc_compatible'] ) && $compatibility['wc_compatible'] === false ) {
			$message = isset( $compatibility['message'] ) ? $compatibility['message'] : 'Mi Integración API puede no ser totalmente compatible con esta versión de WooCommerce.';
			echo '<div class="notice notice-warning"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Obtiene la versión de WooCommerce si está instalado
	 *
	 * @return string Versión de WooCommerce o cadena vacía si no está instalado
	 */
	private function get_woocommerce_version() {
		if ( defined( 'WC_VERSION' ) ) {
			return WC_VERSION;
		}

		return '';
	}
}
