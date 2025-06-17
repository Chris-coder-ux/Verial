<?php
/**
 * Clase para el endpoint GetFormasEnvioWS de la API de Verial ERP.
 * Plugin Name: Mi Integración API
 * Description: Integración con la API de Verial ERP.
 * Version: 1.2.0
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
 * Clase para gestionar el endpoint de formasenvios
 */
class FormasEnvioWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetFormasEnvioWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_formas_envio_';
	public const CACHE_EXPIRATION     = 12 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;    
	
	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger(); // Logger base, no específico de productos
	}

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
		}                $auth_result = AuthHelper::validate_rest_auth( $request );
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

	/**
	 * Helper para sanitizar valores que pueden ser decimales con coma.
	 */
	private function sanitize_decimal_text( $value ): ?string {
		if ( is_null( $value ) || $value === '' ) {
			return null;
		}
		return str_replace( ',', '.', strval( $value ) );
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['FormasEnvio'] ) || ! is_array( $verial_response['FormasEnvio'] ) ) {
			$this->logger->errorProducto(
				'[GetFormasEnvioWS] La respuesta de Verial no contiene la clave "FormasEnvio" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_formas_envio_data',
				__( 'Los datos de formas de envío recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$formas_envio = array();
		foreach ( $verial_response['FormasEnvio'] as $forma_envio_verial ) {
			$destinos_formateados = array();
			if ( isset( $forma_envio_verial['Destinos'] ) && is_array( $forma_envio_verial['Destinos'] ) ) {
				foreach ( $forma_envio_verial['Destinos'] as $destino_verial ) {
					$destinos_formateados[] = array(
						'id'                   => isset( $destino_verial['Id'] ) ? intval( $destino_verial['Id'] ) : null,
						'nombre'               => isset( $destino_verial['Nombre'] ) ? sanitize_text_field( $destino_verial['Nombre'] ) : null,
						'fijo'                 => isset( $destino_verial['Fijo'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['Fijo'] ) ) : null,
						'por_unidad'           => isset( $destino_verial['PorUnidad'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['PorUnidad'] ) ) : null,
						'por_peso'             => isset( $destino_verial['PorPeso'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['PorPeso'] ) ) : null,
						'minimo'               => isset( $destino_verial['Minimo'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['Minimo'] ) ) : null,
						'peso_maximo_kg'       => isset( $destino_verial['PesoMaximo'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['PesoMaximo'] ) ) : null,
						'gratis_desde_importe' => isset( $destino_verial['Gratis'] ) ? floatval( $this->sanitize_decimal_text( $destino_verial['Gratis'] ) ) : null,
						'paises_ids_csv'       => isset( $destino_verial['Paises'] ) ? sanitize_text_field( $destino_verial['Paises'] ) : null,
						'provincias_ids_csv'   => isset( $destino_verial['Provincias'] ) ? sanitize_text_field( $destino_verial['Provincias'] ) : null,
						'localidades_ids_csv'  => isset( $destino_verial['Localidades'] ) ? sanitize_text_field( $destino_verial['Localidades'] ) : null,
					);
				}
			}

			$formas_envio[] = array(
				'id'            => isset( $forma_envio_verial['Id'] ) ? intval( $forma_envio_verial['Id'] ) : null,
				'nombre'        => isset( $forma_envio_verial['Nombre'] ) ? sanitize_text_field( $forma_envio_verial['Nombre'] ) : null,
				'transportista' => isset( $forma_envio_verial['Transportista'] ) ? sanitize_text_field( $forma_envio_verial['Transportista'] ) : null,
				'destinos'      => $destinos_formateados,
			);
		}
		return $formas_envio;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
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

		if ( ! is_wp_error( $formatted_response ) && $this->use_cache() ) {
			$this->set_cached_data( $cache_params_for_key, $formatted_response );
		}

		return rest_ensure_response( $formatted_response );
	}
}
