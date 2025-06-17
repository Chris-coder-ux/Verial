<?php


namespace MiIntegracionApi\Endpoints;
/**
 * Clase para el endpoint GetMetodosPagoWS de la API de Verial ERP.
 * Obtiene el listado de métodos de pago, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;

class GetMetodosPagoWS extends \MiIntegracionApi\Endpoints\Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'GetMetodosPagoWS';
	const CACHE_KEY_PREFIX     = 'mi_api_metodos_pago_';
	const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS;
	const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Implementación requerida por la clase abstracta Base.
	 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
	 */
	public function register_route(): void {
		// Esta implementación está vacía ya que el registro real
		// de rutas ahora se hace de forma centralizada en REST_API_Handler.php
	}

	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
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
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['MetodosPago'] ) || ! is_array( $verial_response['MetodosPago'] ) ) {
			$this->logger->errorProducto(
				'[GetMetodosPagoWS] La respuesta de Verial no contiene la clave "MetodosPago" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_metodos_pago_data',
				__( 'Los datos de métodos de pago recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$metodos_pago = array();
		foreach ( $verial_response['MetodosPago'] as $metodo_verial ) {
			$metodos_pago[] = array(
				'id'     => isset( $metodo_verial['Id'] ) ? intval( $metodo_verial['Id'] ) : null,
				'nombre' => isset( $metodo_verial['Nombre'] ) ? sanitize_text_field( $metodo_verial['Nombre'] ) : null,
			);
		}
		return $metodos_pago;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'] ?? null;
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
		$result            = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );

		// Si hay resultado exitoso y existe método para formato, aplicarlo
		if ( ! is_wp_error( $result ) && method_exists( $this, 'format_verial_response' ) ) {
			$formatted_result = $this->format_verial_response( $result );

			if ( ! is_wp_error( $formatted_result ) && $this->use_cache() ) {
				$this->set_cached_data( $cache_params_for_key, $formatted_result );

				// Log de auditoría si llegamos hasta aquí con éxito
				if ( class_exists( 'LoggerAuditoria' ) ) {
					\LoggerAuditoria::log(
						'Acceso a GetMetodosPagoWS',
						array(
							'params'  => $verial_api_params,
							'usuario' => get_current_user_id(),
						)
					);
				}
			}

			return rest_ensure_response( $formatted_result );
		}

		return rest_ensure_response( $result );
	}
}
// Fin de la clase
