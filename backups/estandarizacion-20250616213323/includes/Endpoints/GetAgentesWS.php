<?php


namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetAgentesWS de la API de Verial ERP.
 * Devuelve la lista de agentes comisionistas, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Traits\CacheableTrait;
use MiIntegracionApi\Traits\ErrorHandlerTrait;

use MiIntegracionApi\Core\ApiConnector;

class GetAgentesWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;
	use CacheableTrait;
	use ErrorHandlerTrait;

	const ENDPOINT_NAME        = 'GetAgentesWS';
	const CACHE_KEY_PREFIX     = 'mi_api_agentes_';
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

	// La propiedad $connector ya está declarada en la clase base

	/**
	 * Constructor para el endpoint GetAgentesWS
	 *
	 * @param ApiConnector|null $connector Inyección del conector API opcional
	 */
	public function __construct( ?ApiConnector $connector = null ) {
		parent::__construct( $connector );
		$this->init_logger( 'productos' ); // Logger específico de productos
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return bool|\WP_Error
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $this->create_permission_error(
				esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' )
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
		);
	}

	/**
	 * Formatea la respuesta de la API de Verial a un formato adecuado para REST
	 *
	 * @param array<string, mixed> $verial_response Respuesta de Verial
	 * @return array<int, array<string, mixed>>|\WP_Error Array de agentes o error
	 */
	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Agentes'] ) || ! is_array( $verial_response['Agentes'] ) ) {
			// @phpstan-ignore-next-line
			$this->logger->errorProducto(
				'[MI Integracion API] La respuesta de Verial no contiene la clave "Agentes" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return $this->create_rest_error(
				'verial_api_malformed_agentes_data',
				__( 'Los datos de agentes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				500
			);
		}

		$agentes = array();
		foreach ( $verial_response['Agentes'] as $agente_verial ) {
			$agentes[] = array(
				'id'     => isset( $agente_verial['Id'] ) ? intval( $agente_verial['Id'] ) : null,
				'nombre' => isset( $agente_verial['Nombre'] ) ? sanitize_text_field( $agente_verial['Nombre'] ) : null,
			);
		}
		return $agentes;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_agentes', $api_key );
		if ( is_wp_error( $rate_limit ) ) {
			return rest_ensure_response( $rate_limit );
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
				return rest_ensure_response( $cached_data );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$response_verial   = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		if ( is_wp_error( $response_verial ) ) {
			return rest_ensure_response( $response_verial );
		}

		$formatted_response = $this->format_verial_response( $response_verial );
		if ( is_wp_error( $formatted_response ) ) {
			return rest_ensure_response( $formatted_response );
		}

		$this->set_cached_data( $cache_params_for_key, $formatted_response );
		\MiIntegracionApi\Helpers\LoggerAuditoria::log(
			'Acceso a GetAgentesWS',
			array(
				'params'    => $verial_api_params,
				'usuario'   => get_current_user_id(),
				'resultado' => 'OK',
			)
		);
		return rest_ensure_response( $formatted_response );
	}
}

/**
 * Función para registrar las rutas (ejemplo).
 * Debería estar en tu archivo principal del plugin, dentro de la acción 'rest_api_init'.
 */
// add_action('rest_api_init', function () {
// Asumimos que $api_connector es una instancia de ApiConnector
// if (class_exists('ApiConnector') && class_exists('MI_Endpoint_GetAgentesWS')) {
// $api_connector = new ApiConnector(); // O cómo obtengas tu instancia global/singleton
// $agentes_endpoint = new MI_Endpoint_GetAgentesWS($api_connector);
// $agentes_endpoint->register_route();
// }
// });
