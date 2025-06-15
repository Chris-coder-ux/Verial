<?php
/**
 * Clase para el endpoint GetCategoriasWS de la API de Verial ERP.
 * Obtiene el listado de categorías de artículos, según el manual v1.7.5.
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
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de categorias
 */
class CategoriasWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetCategoriasWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_categorias_';
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
			'/categoriasws',
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
		$auth_result = AuthHelper::validate_rest_auth( $request );
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
		if ( ! isset( $verial_response['Categorias'] ) || ! is_array( $verial_response['Categorias'] ) ) {
			return new \WP_Error(
				'verial_api_malformed_categorias_data',
				__( 'Los datos de categorías recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$categorias = array();
		foreach ( $verial_response['Categorias'] as $categoria_verial ) {
			$categorias[] = array(
				'id'                              => isset( $categoria_verial['Id'] ) ? intval( $categoria_verial['Id'] ) : null,
				'id_padre'                        => isset( $categoria_verial['ID_Padre'] ) ? intval( $categoria_verial['ID_Padre'] ) : null,
				'nombre'                          => isset( $categoria_verial['Nombre'] ) ? sanitize_text_field( $categoria_verial['Nombre'] ) : null,
				'clave_ordenacion'                => isset( $categoria_verial['Clave'] ) ? sanitize_text_field( $categoria_verial['Clave'] ) : null,
				'id_familia_campos_configurables' => isset( $categoria_verial['ID_FamiliaCamposConfigurables'] ) ? intval( $categoria_verial['ID_FamiliaCamposConfigurables'] ) : null,
			);
		}
		return $categorias;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_categorias', $api_key );
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
		\LoggerAuditoria::log(
			'Acceso a GetCategoriasWS',
			array(
				'params'    => $verial_api_params,
				'usuario'   => get_current_user_id(),
				'resultado' => 'OK',
			)
		);
		return rest_ensure_response( $formatted_response );
	}
}
