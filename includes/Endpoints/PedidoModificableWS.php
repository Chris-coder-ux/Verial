<?php
/**
 * Clase para el endpoint PedidoModificableWS de la API de Verial ERP.
 * Consulta si un pedido es modificable, según el manual v1.7.5.
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
 * Clase para gestionar el endpoint de pedidomodificables
 */
class PedidoModificableWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME                        = 'PedidoModificableWS';
	public const CACHE_KEY_PREFIX                     = 'mi_api_pedido_modificable_';
	public const CACHE_EXPIRATION                     = 0; // No cachear, es consulta puntual
	public const VERIAL_ERROR_SUCCESS                 = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/pedidomodificablews',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
	public const VERIAL_ERROR_INVALID_SESSION         = 1;
	public const VERIAL_ERROR_CHECK_MODIFIABLE_FAILED = 14;

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger( 'pedidos' );
	}

		/**
		 * Comprueba los permisos para acceder al endpoint.
		 *
		 * @param WP_REST_Request $request Datos de la solicitud.
		 * @return bool|WP_Error True si tiene permiso, WP_Error si no.
		 */
	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		// Ejemplo: Usuarios que pueden editar pedidos.
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // O una capacidad más específica
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para consultar esta información del pedido.', 'mi-integracion-api' ),
				array( 'status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code() )
			);
		}
		return true;
	}

		/**
		 * Define los argumentos esperados por el endpoint (parámetros de la URL y query).
		 *
		 * @return array
		 */
	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'id_pedido_verial' => array( // Parámetro de la ruta
				'description'       => __( 'ID del pedido en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			),
			'sesionwcf'        => array( // Parámetro de consulta (query param)
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
		);
	}

		/**
		 * Ejecuta la lógica del endpoint.
		 *
		 * @param WP_REST_Request $request Datos de la solicitud.
		 * @return WP_REST_Response|WP_Error
		 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$id_pedido_verial = $request['id_pedido_verial'];
		$sesionwcf        = $request['sesionwcf'];

		$verial_params = array(
			'x'         => $sesionwcf,
			'id_pedido' => $id_pedido_verial,
		);

		$result = $this->connector->get( self::ENDPOINT_NAME, $verial_params );
		return rest_ensure_response( $result );
	}
}
