<?php
/**
 * Clase para el endpoint UpdateDocClienteWS de la API de Verial ERP.
 * Modifica ciertos datos de un documento de cliente existente según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas y corrección de constantes)
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
use MiIntegracionApi\Helpers\RestHelpers;

/**
 * Clase para gestionar el endpoint de updatedocclientes
 */
class UpdateDocClienteWS extends Base {
	use EndpointLogger;

	// Nombre del endpoint en la API de Verial
	public const ENDPOINT_NAME = 'UpdateDocClienteWS';

	// Prefijo para claves de caché (si se usan en el futuro)
	public const CACHE_KEY_PREFIX = 'mi_api_update_doc_cliente_';

	// Expiración de caché (0 para no cachear, ya que es una operación de escritura)
	public const CACHE_EXPIRATION = 0;

	// Constantes de error de Verial (Sección 2 del manual y específicas)
	public const VERIAL_ERROR_SUCCESS         = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/updatedocclientews',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
	public const VERIAL_ERROR_INVALID_SESSION = 1;

	// Códigos de error específicos que podrían ser relevantes para este endpoint
	public const VERIAL_ERROR_MODIFICATION_NOT_ALLOWED = 13; // "Modificación no permitida"
	public const VERIAL_ERROR_DOC_NOT_FOUND            = 15;          // "El documento que se quiere modificar no se ha encontrado"

	// Longitudes máximas (igual que en NuevoDocClienteWS)
	public const MAX_LENGTH_REFERENCIA    = 40;
	public const MAX_LENGTH_AUX           = 50;
	public const MAX_LENGTH_OBSERVACIONES = 255; // Nueva constante para observaciones

	/**
	 * Constructor.
	 *
	 * @param ApiConnector $connector Instancia del conector de la API.
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		$this->connector = $connector;
		$this->init_logger( 'pedidos' );
	}

	/**
	 * Método estático para instanciar la clase.
	 *
	 * @param ApiConnector $connector Instancia del conector.
	 * @return static
	 */
	public static function make( \MiIntegracionApi\Core\ApiConnector $connector ): static {
		return new static( $connector );
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 *
	 * @param WP_REST_Request $request Datos de la solicitud.
	 * @return bool|WP_Error True si tiene permiso, WP_Error si no.
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'edit_shop_orders' ) ) { // O una capacidad más específica
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para modificar documentos de cliente.', 'mi-integracion-api' ),
				array( 'status' => RestHelpers::rest_authorization_required_code() )
			);
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
			'sesionwcf'     => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Referencia'    => array(
				'description'       => __( 'Referencia del pedido (si se desea modificar por referencia en lugar de ID y es un pedido).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_REFERENCIA;
				},
			),
			'Aux1'          => array(
				'description'       => __( 'Campo auxiliar 1.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'Aux2'          => array(
				'description'       => __( 'Campo auxiliar 2.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'Aux3'          => array(
				'description'       => __( 'Campo auxiliar 3.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'Aux4'          => array(
				'description'       => __( 'Campo auxiliar 4.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'Aux5'          => array(
				'description'       => __( 'Campo auxiliar 5.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'Aux6'          => array(
				'description'       => __( 'Campo auxiliar 6.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return strlen( $param ) <= self::MAX_LENGTH_AUX;
				},
			),
			'observaciones' => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= static::MAX_LENGTH_OBSERVACIONES;
				},
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
	 * Ejecuta la lógica del endpoint para actualizar un documento de cliente.
	 *
	 * @param WP_REST_Request $request Datos de la solicitud.
	 * @return WP_REST_Response|WP_Error
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'update_doc', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return rest_ensure_response( $rate_limit );
		}

		$params                  = $request->get_params();
		$id_documento_verial_url = absint( $request['id_documento_verial'] );

		if ( empty( $id_documento_verial_url ) ) {
			return rest_ensure_response(
				new \WP_Error(
					'rest_invalid_document_id',
					__( 'El ID del documento es requerido en la URL.', 'mi-integracion-api' ),
					array( 'status' => 400 )
				)
			);
		}

		// Validar existencia del documento en WooCommerce (si aplica)
		$order_id = wc_get_order_id_by_order_key( $id_documento_verial_url );
		$order    = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return rest_ensure_response(
				new \WP_Error(
					'rest_document_not_found',
					__( 'El documento (pedido) no existe en WooCommerce.', 'mi-integracion-api' ),
					array( 'status' => 404 )
				)
			);
		}
		// Validar pertenencia del pedido al usuario actual (si aplica)
		if ( ! current_user_can( 'manage_woocommerce' ) && $order->get_user_id() !== get_current_user_id() ) {
			return rest_ensure_response(
				new \WP_Error(
					'rest_forbidden',
					__( 'No tienes permiso para modificar este documento.', 'mi-integracion-api' ),
					array( 'status' => 403 )
				)
			);
		}

		$verial_payload = array(
			'sesionwcf' => $params['sesionwcf'],
			'Id'        => $id_documento_verial_url, // ID del documento a modificar
		);

		$updatable_fields = array( 'Referencia', 'Aux1', 'Aux2', 'Aux3', 'Aux4', 'Aux5', 'Aux6', 'observaciones' );
		foreach ( $updatable_fields as $field_key ) {
			if ( isset( $params[ $field_key ] ) ) {
				$verial_payload[ $field_key ] = $params[ $field_key ];
			}
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );
		return rest_ensure_response( $result );
	}
}
