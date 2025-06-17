<?php
/**
 * Clase para el endpoint GetArbolCamposConfigurablesArticulosWS de la API de Verial ERP.
 * Obtiene el árbol de campos configurables de artículos, según el manual v1.7.5.
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
use MiIntegracionApi\Core\ApiConnector;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de campos configurables
 */
class ArbolCamposConfigurablesArticulosWS extends Base {

	use EndpointLogger;
	use CacheableTrait;

	public const ENDPOINT_NAME        = 'GetArbolCamposConfigurablesArticulosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_arbol_campo_conf_';
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
			'/arbolcamposconfigurablesarticulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

		/** @var ApiConnector */
		protected ApiConnector $connector;

		/**
		 * Constructor de la clase
		 *
		 * @param ApiConnector $connector Conector de API
		 */
	public function __construct( ApiConnector $connector ) {
		$this->connector = $connector;
		$this->init_logger(); // Logger base, no específico de productos
	}

		/**
		 * Factory method para crear una nueva instancia
		 *
		 * @param ApiConnector $connector Conector de API
		 * @return static Nueva instancia
		 */
	public static function make( ApiConnector $connector ): static {
		return new static( $connector );
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

		/**
		 * Devuelve los argumentos del endpoint
		 *
		 * @param bool $is_update Si es una actualización
		 * @return array<string, array<string, mixed>> Argumentos
		 */
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

		/**
		 * Formatea la respuesta de Verial
		 *
		 * @param array<string, mixed> $verial_response Respuesta de Verial
		 * @return array<int, array<string, mixed>>|\WP_Error Datos formateados o error
		 */
	protected function format_verial_response( array $verial_response ): array|\WP_Error {
		$data_key = '';
		if ( isset( $verial_response['ArbolCamposConfigurables'] ) && is_array( $verial_response['ArbolCamposConfigurables'] ) ) {
			$data_key = 'ArbolCamposConfigurables';
			$this->logger->errorProducto(
				'[MI Integracion API] GetArbolCamposConfigurablesArticulosWS: Verial devolvió "ArbolCamposConfigurables" en lugar de "RamasArbol" como indica el manual.',
				array( 'response' => $verial_response )
			);
		} elseif ( isset( $verial_response['RamasArbol'] ) && is_array( $verial_response['RamasArbol'] ) ) {
			$data_key = 'RamasArbol';
		} else {
			$this->logger->errorProducto(
				'[MI Integracion API] Respuesta malformada de Verial para GetArbolCamposConfigurablesArticulosWS: ' . wp_json_encode( $verial_response ),
				array()
			);
			return new \WP_Error(
				'verial_api_malformed_arbol_data',
				__( 'Los datos del árbol de campo configurable recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$ramas_arbol = array();
		foreach ( $verial_response[ $data_key ] as $rama_verial ) {
			$ramas_arbol[] = array(
				'id'               => isset( $rama_verial['Id'] ) ? intval( $rama_verial['Id'] ) : null,
				'id_padre'         => isset( $rama_verial['ID_Padre'] ) ? intval( $rama_verial['ID_Padre'] ) : null,
				'nombre'           => isset( $rama_verial['Nombre'] ) ? sanitize_text_field( $rama_verial['Nombre'] ) : null,
				'clave_ordenacion' => isset( $rama_verial['Clave'] ) ? sanitize_text_field( $rama_verial['Clave'] ) : null,
			);
		}
		return $ramas_arbol;
	}

		/**
		 * Ejecuta la petición REST
		 *
		 * @param \WP_REST_Request $request Petición REST
		 * @return \WP_REST_Response|\WP_Error Respuesta o error
		 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$id_campo      = $params['id_campo'];
		$id_familia    = $params['id_familiacamposconfigurables'];
		$force_refresh = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;

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
