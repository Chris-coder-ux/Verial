<?php

namespace MiIntegracionApi\Admin;


/**
 * Endpoints AJAX para la página de Endpoints
 *
 * @package MiIntegracionApi\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxEndpointsPage {
	public static function register_ajax_handler() {
		add_action('wp_ajax_mi_test_endpoint', [self::class, 'handle_ajax']);
	}

	public static function handle_ajax() {
		// Verificar nonce y permisos en una sola llamada usando nuestra clase centralizada
		\MI_Nonce_Manager::verify_ajax_request( 'test_endpoint', 'manage_woocommerce' );
		// Usar nuestra nueva clase centralizada de validación
		$endpoint      = \MiIntegracionApi\Core\InputValidation::get_post_var( 'endpoint', 'key', '' );
		$param         = \MiIntegracionApi\Core\InputValidation::get_post_var( 'param', 'text', '' );
		$api_connector = isset( $GLOBALS['mi_api_connector'] ) ? $GLOBALS['mi_api_connector'] : null;
		if ( ! $api_connector || ! $endpoint ) {
			wp_send_json_error( array( 'message' => __( 'Endpoint o conector no válido.', 'mi-integracion-api' ) ) );
		}
		$cache_key = 'mia_ep_' . md5( $endpoint . '|' . $param );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			wp_send_json_success( $cached );
		}
		try {
			$result = null;
			switch ( $endpoint ) {
				case 'get_articulos':
					$result = $api_connector->get_articulos();
					break;
				case 'get_clientes':
					$result = $api_connector->get_clientes();
					break;
				case 'get_pedidos':
					$result = $api_connector->get_pedidos();
					break;
				case 'get_stock':
					$result = $api_connector->get_stock_articulos( $param ? array( $param ) : array() );
					break;
				case 'get_condiciones_tarifa':
					$result = $api_connector->get_condiciones_tarifa( $param, 0, null, date( 'Y-m-d' ) );
					break;
				default:
					wp_send_json_error( array( 'message' => __( 'Endpoint no soportado.', 'mi-integracion-api' ) ) );
			}

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			if ( is_array( $result ) ) {
				// Si es una lista, devolver los primeros 10 para evitar scroll
				if ( count( $result ) > 10 ) {
					$result = array_slice( $result, 0, 10 );
				}
			}
			set_transient( $cache_key, $result, 5 * MINUTE_IN_SECONDS );
			wp_send_json_success( $result );
		} catch ( \Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}
}

// Registrar el handler al cargar el archivo
AjaxEndpointsPage::register_ajax_handler();
