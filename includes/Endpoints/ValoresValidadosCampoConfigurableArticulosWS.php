<?php
/**
 * Clase para el endpoint GetValoresValidadosCampoConfigurableArticulosWS de la API de Verial ERP.
 * Obtiene los valores validados de un campo configurable de artículos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de campos configurables
 */
class ValoresValidadosCampoConfigurableArticulosWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetValoresValidadosCampoConfigurableArticulosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_val_val_campo_conf_';
	public const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/valoresvalidadoscampoconfigurablearticulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

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
		$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'                     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'id_campo'                      => array(
				'description'       => __( 'ID del campo configurable.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'id_familiacamposconfigurables' => array(
				'description'       => __( 'ID de la familia de campos configurables (0 si es un campo compartido).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'force_refresh'                 => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'context'                       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ) {
		$data_key = '';
		if ( isset( $verial_response['ValoresValidados'] ) && is_array( $verial_response['ValoresValidados'] ) ) {
			$data_key = 'ValoresValidados';
			$this->logger->errorProducto(
				'[MI Integracion API] GetValoresValidadosCampoConfigurableArticulosWS: Verial devolvió "ValoresValidados" en lugar de "Valores" como indica el manual.',
				array( 'response' => $verial_response )
			);
		} elseif ( isset( $verial_response['Valores'] ) && is_array( $verial_response['Valores'] ) ) {
			$data_key = 'Valores';
		} else {
			return new \WP_Error(
				'verial_api_malformed_valores_validados_data',
				__( 'Los datos de valores validados recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$valores_validados = array();
		foreach ( $verial_response[ $data_key ] as $valor_verial ) {
			if ( is_string( $valor_verial ) || is_numeric( $valor_verial ) ) {
				$valores_validados[] = sanitize_text_field( strval( $valor_verial ) );
			}
		}
		return $valores_validados;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_valores_validados_campo_configurable_articulos', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return rest_ensure_response( $rate_limit );
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$id_campo      = $params['id_campo'];
		$id_familia    = $params['id_familiacamposconfigurables'];
		$force_refresh = $params['force_refresh'] ?? false;

		$cache_params_for_key = array(
			'sesionwcf'                     => $sesionwcf,
			'id_campo'                      => $id_campo,
			'id_familiacamposconfigurables' => $id_familia,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return rest_ensure_response( $cached_data );
			}
		}

		$verial_api_params = array(
			'x'                             => $sesionwcf,
			'id_campo'                      => $id_campo,
			'id_familiacamposconfigurables' => $id_familia,
		);

		$result = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		return rest_ensure_response( $result );
	}
}
