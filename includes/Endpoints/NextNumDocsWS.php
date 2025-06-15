<?php
/**
 * Clase para el endpoint GetNextNumDocsWS de la API de Verial ERP.
 * Devuelve el siguiente número de documento por tipo, según el manual v1.7.5.
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
 * Clase para gestionar el endpoint de nextnumdocse
 */
class NextNumDocsWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME    = 'GetNextNumDocsWS';
	public const CACHE_KEY_PREFIX = 'mi_api_next_num_docs_';
	public const CACHE_EXPIRATION = 15 * MINUTE_IN_SECONDS;

	// Tipos de Documento de Verial (según manual sección 28 y 25)
	public const TIPO_DOC_FACTURA              = 1;
	public const TIPO_DOC_ALBARAN_VENTA        = 3;
	public const TIPO_DOC_FACTURA_SIMPLIFICADA = 4;
	public const TIPO_DOC_PEDIDO               = 5;
	public const TIPO_DOC_PRESUPUESTO          = 6;

	// Códigos de error de Verial
	public const VERIAL_ERROR_SUCCESS         = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/nextnumdocsws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}
	public const VERIAL_ERROR_INVALID_SESSION = 1;

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger(); // Logger base, no específico de productos
	}

	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
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

	private function get_tipo_documento_descripcion( ?int $tipo_doc_code ): ?string {
		if ( $tipo_doc_code === null ) {
			return null;
		}
		switch ( $tipo_doc_code ) {
			case self::TIPO_DOC_FACTURA:
				return __( 'Factura', 'mi-integracion-api' );
			case self::TIPO_DOC_ALBARAN_VENTA:
				return __( 'Albarán de venta', 'mi-integracion-api' );
			case self::TIPO_DOC_FACTURA_SIMPLIFICADA:
				return __( 'Factura simplificada', 'mi-integracion-api' );
			case self::TIPO_DOC_PEDIDO:
				return __( 'Pedido', 'mi-integracion-api' );
			case self::TIPO_DOC_PRESUPUESTO:
				return __( 'Presupuesto', 'mi-integracion-api' );
			default:
				return __( 'Tipo de documento desconocido', 'mi-integracion-api' ) . ' (' . $tipo_doc_code . ')';
		}
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Numeros'] ) || ! is_array( $verial_response['Numeros'] ) ) {
			$this->logger->errorProducto(
				'[MI Integracion API] La respuesta de Verial no contiene la clave "Numeros" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_next_num_data',
				__( 'Los datos de siguientes números de documento recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$siguientes_numeros = array();
		foreach ( $verial_response['Numeros'] as $item_verial ) {
			$tipo_code            = isset( $item_verial['Tipo'] ) ? intval( $item_verial['Tipo'] ) : null;
			$siguientes_numeros[] = array(
				'tipo_codigo'      => $tipo_code,
				'tipo_descripcion' => $this->get_tipo_documento_descripcion( $tipo_code ),
				'siguiente_numero' => isset( $item_verial['Numero'] ) ? intval( $item_verial['Numero'] ) : null,
			);
		}
		return $siguientes_numeros;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_next_num_docs', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return $rate_limit;
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
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array( 'x' => $sesionwcf );
		$response_verial   = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
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
			'Acceso a GetNextNumDocsWS',
			array(
				'params'    => $verial_api_params,
				'usuario'   => get_current_user_id(),
				'resultado' => 'OK',
			)
		);
		return rest_ensure_response( $formatted );
	}
}
