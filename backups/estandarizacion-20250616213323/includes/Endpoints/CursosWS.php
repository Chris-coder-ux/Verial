<?php
/**
 * Clase para el endpoint GetCursosWS de la API de Verial ERP.
 * Obtiene el listado de cursos, según el manual v1.7.5.
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
use WP_REST_Response;

/**
 * Clase para gestionar el endpoint de cursos
 */
class CursosWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetCursosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_cursos_';
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
			'/cursosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
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
		);
	}

	protected function format_verial_response( array $verial_response ) {
		$data_key = '';
		if ( isset( $verial_response['Valores'] ) && is_array( $verial_response['Valores'] ) ) {
			$data_key = 'Valores';
		} elseif ( isset( $verial_response['Cursos'] ) && is_array( $verial_response['Cursos'] ) ) {
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				\MiIntegracionApi\Helpers\Logger::error( __( '[MI Integracion API] GetCursosWS: Verial devolvió "Cursos" en lugar de "Valores" como indica el manual.', 'mi-integracion-api' ), array( 'response' => $verial_response ), 'endpoint-getcursos' );
			}
			$data_key = 'Cursos';
		} else {
			\MiIntegracionApi\Helpers\Logger::error(
				__( '[MI Integracion API] Respuesta inesperada de Verial para GetCursosWS', 'mi-integracion-api' ),
				array( 'response' => $verial_response ),
				'endpoint-getcursos'
			);
			return new \WP_Error(
				'verial_api_malformed_cursos_data',
				__( 'Los datos de cursos recibidos de Verial no tienen el formato esperado (se esperaba la clave "Valores").', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$cursos = array();
		foreach ( $verial_response[ $data_key ] as $curso_verial ) {
			$cursos[] = array(
				'id'     => isset( $curso_verial['Id'] ) ? intval( $curso_verial['Id'] ) : null,
				'nombre' => isset( $curso_verial['Valor'] ) ? sanitize_text_field( $curso_verial['Valor'] ) : null,
			);
		}
		return $cursos;
	}

	public function execute_restful( \WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'];

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
		$result            = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		if ( ! is_wp_error( $result ) && $this->use_cache() ) {
			$this->set_cached_data( $cache_params_for_key, $result );
		}

		return rest_ensure_response( $result );
	}
}
