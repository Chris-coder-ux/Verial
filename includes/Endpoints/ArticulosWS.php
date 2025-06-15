<?php
/**
 * Clase para el endpoint GetArticulosWS de la API de Verial ERP.
 * Obtiene el listado de artículos, según el manual v1.7.5.
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
use MiIntegracionApi\Traits\CacheableTrait;
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de artículos
 */
class ArticulosWS extends Base {

	use EndpointLogger;
	use CacheableTrait;

	/** @var string El nombre del endpoint en Verial */
	public const ENDPOINT_NAME = 'GetArticulosWS';

	/** @var string El prefijo para las claves de caché */
	public const CACHE_KEY_PREFIX = 'mi_api_articulos_';

	/** @var int Tiempo de expiración de la caché en segundos (6 horas) */
	public const CACHE_EXPIRATION = 21600;

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
			'fecha_desde'   => array(
				'description'       => __( 'Fecha desde la que obtener artículos (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
			),
			'hora_desde'    => array(
				'description'       => __( 'Hora desde la que obtener artículos (HH:mm:ss).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_time_format_optional' ),
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
	 * @param array<int, array<string, mixed>> $verial_data_success
	 * @return array<int, array<string, mixed>>
	 */
	protected function format_specific_data( array $verial_data_success ): array {
		$formatted_articulos = array();
		foreach ( $verial_data_success as $articulo_verial ) {
			if ( is_array( $articulo_verial ) ) {
				$formatted_articulos[] = array(
					'id_verial' => $articulo_verial['Id'] ?? null,
					'nombre'    => $articulo_verial['Nombre'] ?? null,
				);
			}
		}
		return $formatted_articulos;
	}

	public function execute_restful( WP_REST_Request $request ): \WP_REST_Response {
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = AuthHelper::check_rate_limit( 'get_articulos', $api_key );
		if ( is_wp_error( $rate_limit ) ) {
			return rest_ensure_response( $rate_limit );
		}
		$params_mixed = $request->get_params();
		if ( ! is_array( $params_mixed ) ) {
			return rest_ensure_response( new WP_Error( 'invalid_params', __( 'Los parámetros de la petición no son válidos.', 'mi-integracion-api' ), array( 'status' => 400 ) ) );
		}
		$params               = $params_mixed;
		$force_refresh        = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;
		$sesionwcf            = isset( $params['sesionwcf'] ) && ( is_int( $params['sesionwcf'] ) || ctype_digit( (string) $params['sesionwcf'] ) ) ? (int) $params['sesionwcf'] : 0;
		$fecha_desde          = isset( $params['fecha_desde'] ) ? (string) $params['fecha_desde'] : null;
		$hora_desde           = isset( $params['hora_desde'] ) ? (string) $params['hora_desde'] : null;
		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'fecha_desde' => $fecha_desde,
			'hora_desde'  => $hora_desde,
		);
		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return rest_ensure_response( $cached_data );
			}
		}
		$verial_api_params = array( 'x' => $sesionwcf );
		if ( $fecha_desde !== null ) {
			$verial_api_params['fecha'] = $fecha_desde;
		}
		if ( $hora_desde !== null && $fecha_desde !== null ) {
			$verial_api_params['hora'] = $hora_desde;
		}
		$result = $this->connector->get( static::ENDPOINT_NAME, $verial_api_params );

		// Si la respuesta es correcta (no es WP_Error) y contiene los datos esperados
		if ( ! is_wp_error( $result ) && is_array( $result ) && isset( $result['Articulos'] ) && is_array( $result['Articulos'] ) ) {
			$articulos      = $result['Articulos'];
			$formatted_data = $this->format_specific_data( $articulos );

			// Solo guardamos en caché si fue una respuesta exitosa
			$this->set_cached_data( $cache_params_for_key, $formatted_data );

			// Devolvemos los datos formateados
			return rest_ensure_response( $formatted_data );
		}

		// En caso de error o datos faltantes, devolvemos la respuesta tal cual
		// El conector ya se encarga de devolver WP_Error con el formato adecuado
		return rest_ensure_response( $result );
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
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}
	
	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/articulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
}
