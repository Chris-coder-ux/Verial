<?php

namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetHistorialPedidosWS de la API de Verial ERP.
 * Obtiene el historial de pedidos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetHistorialPedidosWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetHistorialPedidosWS';
	const CACHE_KEY_PREFIX     = 'mi_api_historial_pedidos_';
	const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS;
	const VERIAL_ERROR_SUCCESS = 0;

	public function __construct() {
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
				array( 'status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code() )
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
		// ...puedes añadir más campos según sea necesario...
		return $doc;
	}

    /**
     * Implementación requerida por la clase abstracta Base.
     * El registro real de rutas ahora está centralizado en REST_API_Handler.php
     */
    public function register_route(): void {
        // Esta implementación está vacía ya que el registro real
        // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
    }

} // cierre de la clase
