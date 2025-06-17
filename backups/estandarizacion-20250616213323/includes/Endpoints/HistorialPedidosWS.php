<?php
/**
 * Clase para el endpoint GetHistorialPedidosWS de la API de Verial ERP.
 * Obtiene el historial de pedidos, según el manual v1.7.5.
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
 * Clase para gestionar el endpoint de historialpedidos
 */
class HistorialPedidosWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetHistorialPedidosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_hist_pedidos_';
	public const CACHE_EXPIRATION     = 2 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/historialpedidosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger( 'pedidos' );
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
				esc_html__( 'No tienes permiso para ver el historial de pedidos.', 'mi-integracion-api' ),
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
			'id_cliente'    => array(
				'description'       => __( 'ID del cliente para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'fechadesde'    => array(
				'description'       => __( 'Fecha desde (YYYY-MM-DD) para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'fechahasta'    => array(
				'description'       => __( 'Fecha hasta (YYYY-MM-DD) para filtrar el historial (opcional).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'allareasventa' => array(
				'description'       => __( 'Incluir pedidos de todas las áreas de venta (true/false, opcional).', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
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

	private function sanitize_decimal_text( $value ): ?string {
		return ! is_null( $value ) && $value !== '' ? str_replace( ',', '.', $value ) : null;
	}

	protected function format_documento_data( array $documento_verial ): array {
		$doc                          = array();
		$doc['id_verial']             = isset( $documento_verial['Id'] ) ? intval( $documento_verial['Id'] ) : null;
		$doc['tipo_documento_codigo'] = isset( $documento_verial['Tipo'] ) ? intval( $documento_verial['Tipo'] ) : null;
		$doc['referencia']            = isset( $documento_verial['Referencia'] ) ? sanitize_text_field( $documento_verial['Referencia'] ) : null;
		$doc['numero_documento']      = isset( $documento_verial['Numero'] ) ? intval( $documento_verial['Numero'] ) : null;
		$doc['fecha_documento']       = isset( $documento_verial['Fecha'] ) ? sanitize_text_field( $documento_verial['Fecha'] ) : null;
		$doc['id_cliente_verial']     = isset( $documento_verial['ID_Cliente'] ) ? intval( $documento_verial['ID_Cliente'] ) : null;

		if ( isset( $documento_verial['Cliente'] ) && is_array( $documento_verial['Cliente'] ) ) {
			$cliente_data        = $documento_verial['Cliente'];
			$doc['cliente_info'] = array(
				'id_verial' => isset( $cliente_data['Id'] ) ? intval( $cliente_data['Id'] ) : null,
				'nombre'    => isset( $cliente_data['Nombre'] ) ? sanitize_text_field( $cliente_data['Nombre'] ) : null,
				'nif'       => isset( $cliente_data['NIF'] ) ? sanitize_text_field( $cliente_data['NIF'] ) : null,
			);
		}
		$doc['etiqueta_cliente']   = isset( $documento_verial['EtiquetaCliente'] ) ? sanitize_textarea_field( $documento_verial['EtiquetaCliente'] ) : null;
		$doc['id_direccion_envio'] = isset( $documento_verial['ID_DireccionEnvio'] ) ? intval( $documento_verial['ID_DireccionEnvio'] ) : null;
		$doc['id_agente1']         = isset( $documento_verial['ID_Agente1'] ) ? intval( $documento_verial['ID_Agente1'] ) : null;
		$doc['id_metodo_pago']     = isset( $documento_verial['ID_MetodoPago'] ) ? intval( $documento_verial['ID_MetodoPago'] ) : null;
		$doc['id_forma_envio']     = isset( $documento_verial['ID_FormaEnvio'] ) ? intval( $documento_verial['ID_FormaEnvio'] ) : null;
		$doc['id_destino']         = isset( $documento_verial['ID_Destino'] ) ? intval( $documento_verial['ID_Destino'] ) : null;

		$doc['peso_kg']                     = isset( $documento_verial['Peso'] ) ? floatval( $this->sanitize_decimal_text( $documento_verial['Peso'] ) ) : null;
		$doc['bultos']                      = isset( $documento_verial['Bultos'] ) ? intval( $documento_verial['Bultos'] ) : null;
		$doc['tipo_portes']                 = isset( $documento_verial['TipoPortes'] ) ? intval( $documento_verial['TipoPortes'] ) : null;
		$doc['precios_impuestos_incluidos'] = isset( $documento_verial['PreciosImpIncluidos'] ) ? rest_sanitize_boolean( $documento_verial['PreciosImpIncluidos'] ) : null;
		$doc['base_imponible']              = isset( $documento_verial['BaseImponible'] ) ? floatval( $this->sanitize_decimal_text( $documento_verial['BaseImponible'] ) ) : null;
		$doc['total_importe']               = isset( $documento_verial['TotalImporte'] ) ? floatval( $this->sanitize_decimal_text( $documento_verial['TotalImporte'] ) ) : null;
		$doc['portes']                      = isset( $documento_verial['Portes'] ) ? floatval( $this->sanitize_decimal_text( $documento_verial['Portes'] ) ) : null;

		$doc['comentario_documento']  = isset( $documento_verial['Comentario'] ) ? sanitize_textarea_field( $documento_verial['Comentario'] ) : null;
		$doc['descripcion_documento'] = isset( $documento_verial['Descripcion'] ) ? sanitize_text_field( $documento_verial['Descripcion'] ) : null;
		$doc['aux1']                  = isset( $documento_verial['Aux1'] ) ? sanitize_text_field( $documento_verial['Aux1'] ) : null;

		$doc['lineas_contenido'] = array();
		if ( isset( $documento_verial['Contenido'] ) && is_array( $documento_verial['Contenido'] ) ) {
			foreach ( $documento_verial['Contenido'] as $linea_verial ) {
				$linea                     = array(
					'tipo_registro'               => isset( $linea_verial['TipoRegistro'] ) ? intval( $linea_verial['TipoRegistro'] ) : null,
					'id_articulo'                 => isset( $linea_verial['ID_Articulo'] ) ? intval( $linea_verial['ID_Articulo'] ) : null,
					'comentario_linea'            => isset( $linea_verial['Comentario'] ) ? sanitize_text_field( $linea_verial['Comentario'] ) : null,
					'unidades'                    => isset( $linea_verial['Uds'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['Uds'] ) ) : null,
					'precio_unitario'             => isset( $linea_verial['Precio'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['Precio'] ) ) : null,
					'descuento_porcentaje'        => isset( $linea_verial['Dto'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['Dto'] ) ) : null,
					'descuento_euros_x_unidad'    => isset( $linea_verial['DtoEurosXUd'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['DtoEurosXUd'] ) ) : null,
					'descuento_euros_total_linea' => isset( $linea_verial['DtoEuros'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['DtoEuros'] ) ) : null,
					'importe_linea'               => isset( $linea_verial['ImporteLinea'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['ImporteLinea'] ) ) : null,
					'lote'                        => isset( $linea_verial['Lote'] ) ? sanitize_text_field( $linea_verial['Lote'] ) : null,
					'caducidad'                   => isset( $linea_verial['Caducidad'] ) ? sanitize_text_field( $linea_verial['Caducidad'] ) : null,
					'id_partida'                  => isset( $linea_verial['ID_Partida'] ) ? intval( $linea_verial['ID_Partida'] ) : null,
					'porcentaje_iva'              => isset( $linea_verial['PorcentajeIVA'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['PorcentajeIVA'] ) ) : null,
					'porcentaje_re'               => isset( $linea_verial['PorcentajeRE'] ) ? floatval( $this->sanitize_decimal_text( $linea_verial['PorcentajeRE'] ) ) : null,
					'descripcion_amplia_linea'    => isset( $linea_verial['DescripcionAmplia'] ) ? sanitize_textarea_field( $linea_verial['DescripcionAmplia'] ) : null,
					'concepto_linea'              => isset( $linea_verial['Concepto'] ) ? sanitize_text_field( $linea_verial['Concepto'] ) : null,
				);
				$doc['lineas_contenido'][] = $linea;
			}
		}

		$doc['pagos_realizados'] = array();
		if ( isset( $documento_verial['Pagos'] ) && is_array( $documento_verial['Pagos'] ) ) {
			foreach ( $documento_verial['Pagos'] as $pago_verial ) {
				$pago                      = array(
					'id_metodo_pago' => isset( $pago_verial['ID_MetodoPago'] ) ? intval( $pago_verial['ID_MetodoPago'] ) : null,
					'fecha_pago'     => isset( $pago_verial['Fecha'] ) ? sanitize_text_field( $pago_verial['Fecha'] ) : null,
					'importe_pago'   => isset( $pago_verial['Importe'] ) ? floatval( $this->sanitize_decimal_text( $pago_verial['Importe'] ) ) : null,
				);
				$doc['pagos_realizados'][] = $pago;
			}
		}
		return $doc;
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Documentos'] ) || ! is_array( $verial_response['Documentos'] ) ) {
			return new \WP_Error(
				'verial_api_malformed_historial_data',
				__( 'Los datos de historial de pedidos recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$documentos = array();
		foreach ( $verial_response['Documentos'] as $documento_verial ) {
			if ( is_array( $documento_verial ) ) {
				$documentos[] = $this->format_documento_data( $documento_verial );
			}
		}
		return $documentos;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_historial_pedidos', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return rest_ensure_response( $rate_limit );
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$verial_api_params = array( 'x' => $sesionwcf );
		if ( isset( $params['id_cliente'] ) && $params['id_cliente'] > 0 ) {
			$verial_api_params['id_cliente'] = $params['id_cliente'];
		}
		if ( isset( $params['fechadesde'] ) ) {
			$verial_api_params['fechadesde'] = $params['fechadesde'];
		}
		if ( isset( $params['fechahasta'] ) ) {
			$verial_api_params['fechahasta'] = $params['fechahasta'];
		}
		if ( isset( $params['allareasventa'] ) ) {
			$verial_api_params['allareasventa'] = $params['allareasventa'] ? 'true' : 'false';
		}

		$cache_params_for_key = array(
			'sesionwcf'     => $sesionwcf,
			'id_cliente'    => $params['id_cliente'] ?? null,
			'fechadesde'    => $params['fechadesde'] ?? null,
			'fechahasta'    => $params['fechahasta'] ?? null,
			'allareasventa' => $params['allareasventa'] ?? null,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return rest_ensure_response( $cached_data );
			}
		}

		$result = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		if ( ! is_wp_error( $result ) && $this->use_cache() ) {
			$this->set_cached_data( $cache_params_for_key, $result );
		}

		return rest_ensure_response( $result );
	}
}
