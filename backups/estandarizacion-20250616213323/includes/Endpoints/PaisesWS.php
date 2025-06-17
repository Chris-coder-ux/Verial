<?php
/**
 * Clase para el endpoint GetPaisesWS de la API de Verial ERP.
 * Obtiene el listado de países, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas y revisión)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\RestHelpers;
use MiIntegracionApi\Helpers\EndpointArgs;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Clase para gestionar el endpoint de paises
 */
class PaisesWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME          = 'GetPaisesWS';
	public const CACHE_KEY_PREFIX       = 'mi_api_paises_';
	public const CACHE_EXPIRATION       = 12 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS   = 0;
	public const MAX_LENGTH_NOMBRE_PAIS = 255; // Longitud máxima para el nombre del país.

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger(); // Logger base, no específico de productos
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 *
	 * Cambio: A partir del 4 de junio de 2025, se permite acceso a cualquier usuario autenticado ('read') ya que el listado de países no es información sensible.
	 *
	 * @param WP_REST_Request $request La solicitud REST
	 * @return WP_Error|bool True si tiene permisos, WP_Error si no
	 */
	public function permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'read' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'Debes iniciar sesión para ver esta información.', 'mi-integracion-api' ),
				array( 'status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Define los argumentos esperados por el endpoint (parámetros query).
	 *
	 * @return array
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'activo'  => array(
				'required'    => false,
				'type'        => 'boolean',
				'description' => 'Filtrar por países activos/inactivos',
				'default'     => true
			),
			'context' => EndpointArgs::context(),
		);
	}

	/**
	 * Formatea la respuesta de la API de Verial.
	 *
	 * @param array $verial_response La respuesta decodificada de Verial.
	 * @return array|WP_Error Los datos formateados o un WP_Error si el formato es incorrecto.
	 */
	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Paises'] ) || ! is_array( $verial_response['Paises'] ) ) {
			$this->logger->errorProducto(
				'[GetPaisesWS] La respuesta de Verial no contiene la clave "Paises" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_paises_data',
				__( 'Los datos de países recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array(
					'status'          => 500,
					'verial_response' => $verial_response,
				)
			);
		}

		$paises_formateados = array();
		foreach ( $verial_response['Paises'] as $pais_verial ) {
			$paises_formateados[] = array(
				'id'     => isset( $pais_verial['Id'] ) ? absint( $pais_verial['Id'] ) : null,
				'nombre' => isset( $pais_verial['Nombre'] ) ? sanitize_text_field( $pais_verial['Nombre'] ) : null,
				'iso2'   => isset( $pais_verial['ISO2'] ) ? sanitize_text_field( $pais_verial['ISO2'] ) : null,
				'iso3'   => isset( $pais_verial['ISO3'] ) ? sanitize_text_field( $pais_verial['ISO3'] ) : null,
			);
		}
		return $paises_formateados;
	}

	/**
	 * Registra la ruta del endpoint en WordPress.
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/paises',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'executeRestful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'               => $this->get_endpoint_args(),
			)
		);
	}

	/**
	 * Ejecuta la lógica del endpoint.
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			return new \WP_REST_Response($this->format_success_response(), 200);
		} catch (\Exception $e) {
			return new \WP_Error(
				'rest_error',
				$e->getMessage(),
				['status' => 500]
			);
		}
	}
}
