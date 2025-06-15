<?php
declare(strict_types=1);

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\AuthHelper;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Clase para el endpoint GetStockArticulosWS de la API de Verial ERP.
 * Obtiene el stock de artículos, según el manual v1.7.5 y el JSON de Postman.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

/**
 * Endpoint REST para obtener el stock de artículos desde Verial.
 */
class GetStockArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetStockArticulosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_stock_art_';
	public const CACHE_EXPIRATION     = 3600; // 1 hora
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/getstockarticulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Constructor. Inicializa el logger y el conector.
	 *
	 * @param ApiConnector $connector
	 */
	public function __construct( ApiConnector $connector ) {
		parent::__construct( $connector );
		$this->init_logger();
	}

	/**
	 * Comprueba permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param WP_REST_Request $request
	 * @return bool|WP_Error
	 */
	public function permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver esta información de stock.', 'mi-integracion-api' ),
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
	 * Define los argumentos del endpoint.
	 *
	 * @param bool $is_update Si es endpoint de un solo artículo.
	 * @return array<string, array<string, mixed>>
	 */
	public function get_endpoint_args( bool $is_update = false ): array {
		$args = array(
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
		if ( $is_update ) {
			$args['id_articulo_verial'] = array(
				'description'       => __( 'ID del artículo en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			);
		}
		$args['context'] = array(
			'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
			'type'        => 'string',
			'enum'        => array( 'view', 'embed', 'edit' ),
			'default'     => 'view',
		);
		return $args;
	}

	/**
	 * Formatea la respuesta de Verial según el manual y el JSON de Postman.
	 *
	 * @param array<string, mixed> $verial_response
	 * @return array<int, array<string, int|float|null>>|WP_Error
	 */
	protected function format_verial_response( array $verial_response ): array|WP_Error {
		$data_key = '';
		if ( isset( $verial_response['StockArticulos'] ) && is_array( $verial_response['StockArticulos'] ) ) {
			$data_key = 'StockArticulos';
			if ( method_exists( $this->logger, 'error' ) ) {
				$this->logger->error(
					'[MI Integracion API] GetStockArticulosWS: Verial devolvió "StockArticulos" en lugar de "Stock" como indica el manual.',
					array( 'response' => $verial_response )
				);
			}
		} elseif ( isset( $verial_response['Stock'] ) && is_array( $verial_response['Stock'] ) ) {
			$data_key = 'Stock';
		} else {
			return new WP_Error(
				'verial_api_malformed_stock_data',
				__( 'Los datos de stock recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$stock_articulos = array();
		if ( isset( $verial_response[ $data_key ] ) && is_iterable( $verial_response[ $data_key ] ) ) {
			foreach ( $verial_response[ $data_key ] as $stock_item_verial ) {
				if ( ! is_array( $stock_item_verial ) ) {
					continue;
				}
				$id_articulo               = isset( $stock_item_verial['ID_Articulo'] ) && ( is_int( $stock_item_verial['ID_Articulo'] ) || is_string( $stock_item_verial['ID_Articulo'] ) ) ? intval( $stock_item_verial['ID_Articulo'] ) : null;
				$stock_unidades            = isset( $stock_item_verial['Stock'] ) && ( is_int( $stock_item_verial['Stock'] ) || is_float( $stock_item_verial['Stock'] ) || is_string( $stock_item_verial['Stock'] ) ) ? floatval( $stock_item_verial['Stock'] ) : null;
				$stock_unidades_auxiliares = isset( $stock_item_verial['StockAux'] ) && ( is_int( $stock_item_verial['StockAux'] ) || is_float( $stock_item_verial['StockAux'] ) || is_string( $stock_item_verial['StockAux'] ) ) ? floatval( $stock_item_verial['StockAux'] ) : null;

				$stock_articulos[] = array(
					'id_articulo'               => $id_articulo,
					'stock_unidades'            => $stock_unidades,
					'stock_unidades_auxiliares' => $stock_unidades_auxiliares,
				);
			}
		}
		return $stock_articulos;
	}

	/**
	 * Ejecuta la lógica principal del endpoint REST.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_restful( WP_REST_Request $request ): WP_REST_Response {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = AuthHelper::check_rate_limit( 'get_stock_articulos', $api_key );
		if ( $rate_limit instanceof WP_Error ) {
			return $rate_limit;
		}

		$params = $request->get_params();
		if ( ! is_array( $params ) ) {
			return new WP_Error(
				'invalid_request_params',
				__( 'Los parámetros de la petición no son válidos.', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}
		if ( ! isset( $params['sesionwcf'] ) || ! is_numeric( $params['sesionwcf'] ) ) {
			return new WP_Error(
				'missing_sesionwcf',
				__( 'El parámetro sesionwcf es obligatorio y debe ser numérico.', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}
		$sesionwcf         = (int) $params['sesionwcf'];
		$force_refresh     = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;
		$id_articulo_param = 0;
		if ( isset( $params['id_articulo_verial'] ) && is_numeric( $params['id_articulo_verial'] ) ) {
			$id_articulo_param = absint( $params['id_articulo_verial'] );
		}

		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'id_articulo' => $id_articulo_param,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array(
			'x'           => $sesionwcf,
			'id_articulo' => $id_articulo_param,
		);

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
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
			'Acceso a GetStockArticulosWS',
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
// $api_connector = new \MiIntegracionApi\Core\ApiConnector(/* ...configuración... */);
// $stock_articulos_endpoint = new \MiIntegracionApi\Endpoints\GetStockArticulosWS($api_connector);
// $stock_articulos_endpoint->register_route();
// });
