<?php


namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetAsignaturasWS de la API de Verial ERP.
 * Obtiene el listado de asignaturas, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\AuthHelper;

class GetAsignaturasWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetAsignaturasWS';
	const CACHE_KEY_PREFIX     = 'mi_api_asignaturas_';
	const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS;
	const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}

	public function __construct() {
		$this->init_logger(); // Logger base, no específico de productos
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'force_refresh' => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ) {
		$data_key = '';
		if ( isset( $verial_response['Valores'] ) && is_array( $verial_response['Valores'] ) ) {
			$data_key = 'Valores';
		} elseif ( isset( $verial_response['Asignaturas'] ) && is_array( $verial_response['Asignaturas'] ) ) {
			$this->logger->errorProducto(
				'[MI Integracion API] GetAsignaturasWS: Verial devolvió "Asignaturas" en lugar de "Valores" como indica el manual.',
				array( 'response' => $verial_response )
			);
			$data_key = 'Asignaturas';
		} else {
			$this->logger->errorProducto(
				'[MI Integracion API] Respuesta malformada de Verial para GetAsignaturasWS: ' . wp_json_encode( $verial_response ),
				array()
			);
			return new \WP_Error(
				'verial_api_malformed_asignaturas_data',
				__( 'Los datos de asignaturas recibidos de Verial no tienen el formato esperado (se esperaba la clave "Valores").', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$asignaturas = array();
		foreach ( $verial_response[ $data_key ] as $asignatura_verial ) {
			$asignaturas[] = array(
				'id'     => isset( $asignatura_verial['Id'] ) ? intval( $asignatura_verial['Id'] ) : null,
				'nombre' => isset( $asignatura_verial['Valor'] ) ? sanitize_text_field( $asignatura_verial['Valor'] ) : null,
			);
		}
		return $asignaturas;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_asignaturas', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$cache_params_for_key = array(
			'sesionwcf' => $sesionwcf,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$response_verial   = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( is_wp_error( $response_verial ) ) {
			return rest_ensure_response( $response_verial );
		}
		$formatted = $this->format_verial_response( $response_verial );
		if ( is_wp_error( $formatted ) ) {
			return rest_ensure_response( $formatted );
		}

		$this->set_cached_data( $cache_params_for_key, $formatted );
		require_once dirname( __DIR__ ) . '/../helpers/LoggerAuditoria.php';
		\LoggerAuditoria::log(
			'Acceso a GetAsignaturasWS',
			array(
				'params'    => $verial_api_params,
				'usuario'   => get_current_user_id(),
				'resultado' => 'OK',
			)
		);
		return rest_ensure_response( $formatted );
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 */
// add_action('rest_api_init', function () {
// $api_connector = new ApiConnector();
// $asignaturas_endpoint = new MI_Endpoint_GetAsignaturasWS($api_connector);
// $asignaturas_endpoint->register_route();
// });
