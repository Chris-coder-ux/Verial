<?php
/**
 * Clase para el endpoint NuevaLocalidadWS de la API de Verial ERP.
 * Da de alta una nueva localidad según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de nuevalocalidads
 */
class NuevaLocalidadWS extends Base {
	public const ENDPOINT_NAME        = 'NuevaLocalidadWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_nueva_localidad_';
	public const CACHE_EXPIRATION     = 0; // No cachear, es escritura
	public const VERIAL_ERROR_SUCCESS = 0;

		// Longitudes máximas (según manual Verial v1.7.5, sección 8)
	public const MAX_LENGTH_NOMBRE               = 50;
	public const MAX_LENGTH_CODIGO_POSTAL        = 10;
	public const MAX_LENGTH_CODIGO_NUTS          = 5;
	public const MAX_LENGTH_CODIGO_MUNICIPIO_INE = 5;

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
				esc_html__( 'No tienes permiso para crear localidades.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'          => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Nombre'             => array(
				'description'       => __( 'Nombre de la localidad.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => self::MAX_LENGTH_NOMBRE,
			),
			'ID_Pais'            => array(
				'description'       => __( 'ID del país al que pertenece la localidad (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'ID_Provincia'       => array(
				'description'       => __( 'ID de la provincia a la que pertenece la localidad (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'CodigoPostal'       => array(
				'description'       => __( 'Código postal de la localidad.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => self::MAX_LENGTH_CODIGO_POSTAL,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || preg_match( '/^[0-9A-Za-z\s-]{3,' . self::MAX_LENGTH_CODIGO_POSTAL . '}$/', $param );
				},
			),
			'CodigoNUTS'         => array(
				'description'       => __( 'Código NUTS.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => self::MAX_LENGTH_CODIGO_NUTS,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || ( is_string( $param ) && strlen( $param ) <= self::MAX_LENGTH_CODIGO_NUTS );
				},
			),
			'CodigoMunicipioINE' => array(
				'description'       => __( 'Código de municipio INE.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => self::MAX_LENGTH_CODIGO_MUNICIPIO_INE,
				'validate_callback' => function ( $param ) {
					return empty( $param ) || ( is_string( $param ) && strlen( $param ) <= self::MAX_LENGTH_CODIGO_MUNICIPIO_INE );
				},
			),
			'context'            => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nueva_localidad', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return rest_ensure_response( $rate_limit );
		}

		$params = $request->get_params();

		$verial_payload = array();
		$verial_fields  = array( 'sesionwcf', 'Nombre', 'ID_Pais', 'ID_Provincia', 'CodigoNUTS', 'CodigoMunicipioINE' );

		foreach ( $verial_fields as $field_key ) {
			if ( isset( $params[ $field_key ] ) ) {
				if ( in_array( $field_key, array( 'sesionwcf', 'ID_Pais', 'ID_Provincia' ) ) ) {
					$verial_payload[ $field_key ] = intval( $params[ $field_key ] );
				} else {
					$verial_payload[ $field_key ] = $params[ $field_key ];
				}
			} elseif ( in_array( $field_key, array( 'CodigoNUTS', 'CodigoMunicipioINE', 'CodigoPostal' ) ) ) {
				$verial_payload[ $field_key ] = '';
			}
		}
		if ( isset( $params['CodigoPostal'] ) ) {
			$verial_payload['CodigoPostal'] = $params['CodigoPostal'];
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );
		return rest_ensure_response( $result );
	}
}
