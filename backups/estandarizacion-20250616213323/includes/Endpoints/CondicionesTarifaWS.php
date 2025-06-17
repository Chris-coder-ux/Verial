<?php
/**
 * Clase para el endpoint GetCondicionesTarifaWS de la API de Verial ERP.
 * Obtiene las condiciones de tarifa para la venta, según el manual v1.7.5.
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
 * Clase para gestionar el endpoint de condicionestarifas
 */
class CondicionesTarifaWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetCondicionesTarifaWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_cond_tarifa_';
	public const CACHE_EXPIRATION     = HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/condicionestarifaws',
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
			'id_articulo'   => array(
				'description'       => __( 'ID del artículo (0 para todos los artículos).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'id_cliente'    => array(
				'description'       => __( 'ID del cliente (0 para tarifa general web).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'id_tarifa'     => array(
				'description'       => __( 'ID de la tarifa (si no se especifica, usa general web o del cliente).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'fecha'         => array(
				'description'       => __( 'Fecha para calcular precios (si es distinta a la actual).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	/**
	 * Valida que un valor sea una fecha opcional en formato ISO
	 *
	 * @param mixed            $value Valor a validar
	 * @param \WP_REST_Request $request Petición
	 * @param string           $key Clave del parámetro
	 * @return bool|\WP_Error True si es válido, WP_Error si no
	 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		// @phpstan-ignore-next-line
		return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' ), $key ), array( 'status' => 400 ) );
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['CondicionesTarifa'] ) || ! is_array( $verial_response['CondicionesTarifa'] ) ) {
			$this->logger->errorProducto(
				'[GetCondicionesTarifaWS] La respuesta de Verial no contiene la clave "CondicionesTarifa" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_condiciones_data',
				__( 'Los datos de condiciones de tarifa recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$condiciones = array();
		foreach ( $verial_response['CondicionesTarifa'] as $condicion_verial ) {
			$condiciones[] = array(
				'id_articulo'              => isset( $condicion_verial['ID_Articulo'] ) ? intval( $condicion_verial['ID_Articulo'] ) : null,
				'precio_sin_impuestos'     => isset( $condicion_verial['Precio'] ) ? floatval( $condicion_verial['Precio'] ) : null,
				'descuento_porcentaje'     => isset( $condicion_verial['Dto'] ) ? floatval( $condicion_verial['Dto'] ) : null,
				'descuento_euros_x_unidad' => isset( $condicion_verial['DtoEurosXUd'] ) ? floatval( $condicion_verial['DtoEurosXUd'] ) : null,
				'unidades_minimas'         => isset( $condicion_verial['UdsMin'] ) ? floatval( $condicion_verial['UdsMin'] ) : null,
				'unidades_regalo'          => isset( $condicion_verial['UdsRegalo'] ) ? floatval( $condicion_verial['UdsRegalo'] ) : null,
			);
		}
		return $condiciones;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_condiciones_tarifa', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$verial_api_params = array( 'x' => $sesionwcf );
		if ( isset( $params['id_articulo'] ) ) {
			$verial_api_params['id_articulo'] = $params['id_articulo'];
		}
		if ( isset( $params['id_cliente'] ) ) {
			$verial_api_params['id_cliente'] = $params['id_cliente'];
		}
		if ( isset( $params['id_tarifa'] ) ) {
			$verial_api_params['id_tarifa'] = $params['id_tarifa'];
		}
		if ( isset( $params['fecha'] ) ) {
			$verial_api_params['fecha'] = $params['fecha'];
		}

		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'id_articulo' => $params['id_articulo'] ?? 0,
			'id_cliente'  => $params['id_cliente'] ?? 0,
			'id_tarifa'   => $params['id_tarifa'] ?? null,
			'fecha'       => $params['fecha'] ?? null,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( is_wp_error( $response_verial ) ) {
			return rest_ensure_response( $response_verial );
		}
		$formatted = $this->format_verial_response( $response_verial );
		if ( is_wp_error( $formatted ) ) {
			return rest_ensure_response( $formatted );
		}

		$this->set_cached_data( $cache_params_for_key, $formatted );
		return rest_ensure_response( $formatted );
	}
}
