<?php
/**
 * Clase para el endpoint GetFabricantesWS de la API de Verial ERP.
 * Obtiene el listado de fabricantes y editores, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas y corrección para Qodana)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Traits\EndpointLogger;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de fabricantes
 */
class FabricantesWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetFabricantesWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_fabricantes_';
	public const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS; // 12 horas de caché
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/fabricantesws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

		// Tipos de Fabricante (según manual sección 16)
	public const TIPO_GENERICO                = 1;
	public const TIPO_EDITOR_LIBROS           = 2;
	public const MAX_LENGTH_NOMBRE_FABRICANTE = 255;

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
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
		if ( class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
			if ( $auth_result !== true ) {
				return $auth_result;
			}
		}
		return true;
	}

		/**
		 * Define los argumentos esperados por el endpoint.
		 *
		 * @return array
		 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'         => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'force_refresh'     => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'nombre_fabricante' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= static::MAX_LENGTH_NOMBRE_FABRICANTE;
				},
			),
			'context'           => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

		/**
		 * Genera una clave de caché única basada en los parámetros.
		 *
		 * @param array $params Parámetros de la solicitud.
		 * @return string Clave de caché.
		 */
	protected function generate_cache_key( array $params ): string {
		// La respuesta solo depende de la sesión.
		return self::CACHE_KEY_PREFIX . md5( serialize( array( 'sesion' => $params['sesionwcf'] ?? '' ) ) );
	}

		/**
		 * Obtiene la descripción textual de un tipo de fabricante.
		 *
		 * @param int|null $tipo_code Código numérico del tipo.
		 * @return string|null Descripción del tipo.
		 */
	private function get_tipo_fabricante_descripcion( ?int $tipo_code ): ?string {
		if ( $tipo_code === null ) {
			return null;
		}
		switch ( $tipo_code ) {
			case self::TIPO_GENERICO:
				return __( 'Genérico', 'mi-integracion-api' );
			case self::TIPO_EDITOR_LIBROS:
				return __( 'Editor de libros', 'mi-integracion-api' );
			default:
				return __( 'Tipo desconocido', 'mi-integracion-api' );
		}
	}

		/**
		 * Formatea la respuesta de la API de Verial.
		 *
		 * @param array $verial_response La respuesta decodificada de Verial.
		 * @return array|WP_Error Los datos formateados o un WP_Error.
		 */
	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Fabricantes'] ) || ! is_array( $verial_response['Fabricantes'] ) ) {
			$this->logger->errorProducto(
				'[GetFabricantesWS] La respuesta de Verial no contiene la clave "Fabricantes" esperada o no es un array. Respuesta: ' . wp_json_encode( $verial_response ),
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_fabricantes_data',
				__( 'Los datos de fabricantes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array(
					'status'          => 500,
					'verial_response' => $verial_response,
				)
			);
		}

		$fabricantes = array();
		foreach ( $verial_response['Fabricantes'] as $fabricante_verial ) {
			$tipo_code     = isset( $fabricante_verial['Tipo'] ) ? intval( $fabricante_verial['Tipo'] ) : null;
			$fabricantes[] = array(
				'id'               => isset( $fabricante_verial['Id'] ) ? intval( $fabricante_verial['Id'] ) : null,
				'nombre'           => isset( $fabricante_verial['Nombre'] ) ? sanitize_text_field( $fabricante_verial['Nombre'] ) : null,
				'tipo_codigo'      => $tipo_code,
				'tipo_descripcion' => $this->get_tipo_fabricante_descripcion( $tipo_code ),
			);
		}
		return $fabricantes;
	}

		/**
		 * Ejecuta la lógica del endpoint.
		 *
		 * @param WP_REST_Request $request Datos de la solicitud.
		 * @return WP_REST_Response|WP_Error
		 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_fabricantes', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
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
			if ( false !== $cached_data && ! is_wp_error( $cached_data ) ) {
				return rest_ensure_response( $cached_data );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$result            = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		if ( ! is_wp_error( $result ) && $this->use_cache() ) {
			$this->set_cached_data( $cache_params_for_key, $result );
		}

		return rest_ensure_response( $result );
	}
}
