<?php
/**
 * Clase para el endpoint GetMascotasWS de la API de Verial ERP.
 * Obtiene el listado de mascotas, según el manual v1.7.5.
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
use MiIntegracionApi\Helpers\AuthHelper;
use MiIntegracionApi\Helpers\Logger;
use WP_REST_Request;
use WP_Error;

/**
 * Clase para gestionar el endpoint de mascotas
 */
class MascotasWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetMascotasWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_mascotas_';
	public const CACHE_EXPIRATION     = 6 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion/v1',
			'/mascotas',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Registro para compatibilidad con versiones antiguas
		register_rest_route(
			'mi-integracion-api/v1',
			'/mascotas',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_request' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);
	}

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger( 'clientes' );
	}

	/**
	 * Comprueba los permisos para acceder al endpoint.
	 * Requiere manage_woocommerce y autenticación adicional (API key/JWT).
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_Error|bool
	 */
	public function permissions_check( \WP_REST_Request $request ): \WP_Error|bool {
		// Usar JWT para autenticación primero
		if ( class_exists( 'MiIntegracionApi\Helpers\JwtAuthHelper' ) ) {
			// Verificar JWT con los permisos necesarios (manage_woocommerce)
			$jwt_callback = \MiIntegracionApi\Helpers\JwtAuthHelper::get_jwt_auth_callback( array( 'manage_woocommerce' ) );
			$jwt_result   = $jwt_callback( $request );
			if ( $jwt_result === true ) {
				return true;
			}
		}

		// Fallback a la autenticación tradicional
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver esta información.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		// Verificar API key como último recurso
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}

		return true;
	}

	public function permissions_check_cliente_mascotas( \WP_REST_Request $request ): \WP_Error|bool {
		// Reforzar: exigir manage_woocommerce + autenticación adicional (API key/JWT)
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden_cliente_mascotas',
				esc_html__( 'No tienes permiso para ver las mascotas de este cliente.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		$auth_result = AuthHelper::validate_rest_auth( $request );
		if ( $auth_result !== true ) {
			return $auth_result;
		}
		return true;
	}

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
			'fecha_desde'   => array(
				'description'       => __( 'Fecha (YYYY-MM-DD) para filtrar mascotas creadas/modificadas desde esta fecha.', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context'       => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);

		if ( $is_update ) {
			$args['id_cliente_param'] = array(
				'description'       => __( 'ID del cliente en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			);
		} else {
			$args['id_cliente'] = array(
				'description'       => __( 'ID del cliente en Verial para filtrar (0 para todas las mascotas de todos los clientes).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			);
		}
		return $args;
	}

	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( count( $parts ) === 3 && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		$error_template = esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una fecha válida en formato YYYY-MM-DD.';
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_template, $key ), array( 'status' => 400 ) );
	}

	protected function format_verial_response( array $verial_response ) {
		if ( ! isset( $verial_response['Mascotas'] ) || ! is_array( $verial_response['Mascotas'] ) ) {
			$this->logger->errorCliente(
				'[MI Integracion API] La respuesta de Verial no contiene la clave "Mascotas" esperada o no es un array.',
				array( 'verial_response' => $verial_response )
			);
			return new \WP_Error(
				'verial_api_malformed_mascotas_data',
				__( 'Los datos de mascotas recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$mascotas = array();
		foreach ( $verial_response['Mascotas'] as $mascota_verial ) {
			$mascotas[] = array(
				'id_verial'                      => isset( $mascota_verial['Id'] ) ? intval( $mascota_verial['Id'] ) : null,
				'id_cliente_verial'              => isset( $mascota_verial['ID_Cliente'] ) ? intval( $mascota_verial['ID_Cliente'] ) : null,
				'nombre'                         => isset( $mascota_verial['Nombre'] ) ? sanitize_text_field( $mascota_verial['Nombre'] ) : null,
				'tipo_animal'                    => isset( $mascota_verial['TipoAnimal'] ) ? sanitize_text_field( $mascota_verial['TipoAnimal'] ) : null,
				'raza'                           => isset( $mascota_verial['Raza'] ) ? sanitize_text_field( $mascota_verial['Raza'] ) : null,
				'fecha_nacimiento'               => isset( $mascota_verial['FechaNacimiento'] ) ? sanitize_text_field( $mascota_verial['FechaNacimiento'] ) : null,
				'peso_kg'                        => isset( $mascota_verial['Peso'] ) ? floatval( $mascota_verial['Peso'] ) : null,
				'situacion_peso'                 => isset( $mascota_verial['SituacionPeso'] ) ? intval( $mascota_verial['SituacionPeso'] ) : null,
				'actividad'                      => isset( $mascota_verial['Actividad'] ) ? intval( $mascota_verial['Actividad'] ) : null,
				'tiene_patologias'               => isset( $mascota_verial['HayPatologias'] ) ? rest_sanitize_boolean( $mascota_verial['HayPatologias'] ) : null,
				'patologias_descripcion'         => isset( $mascota_verial['Patologias'] ) ? sanitize_textarea_field( $mascota_verial['Patologias'] ) : null,
				'alimentacion_tipo'              => isset( $mascota_verial['Alimentacion'] ) ? intval( $mascota_verial['Alimentacion'] ) : null,
				'alimentacion_otros_descripcion' => isset( $mascota_verial['AlimentacionOtros'] ) ? sanitize_textarea_field( $mascota_verial['AlimentacionOtros'] ) : null,
			);
		}
		return $mascotas;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'get_mascotas', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return $rate_limit;
		}

		$params        = $request->get_params();
		$sesionwcf     = $params['sesionwcf'];
		$force_refresh = $params['force_refresh'] ?? false;

		$id_cliente_filter = 0;
		if ( isset( $request['id_cliente_param'] ) ) {
			$id_cliente_filter = absint( $request['id_cliente_param'] );
		} elseif ( isset( $params['id_cliente'] ) ) {
			$id_cliente_filter = absint( $params['id_cliente'] );
		}

		$fecha_desde_filter = $params['fecha_desde'] ?? null;

		$cache_params_for_key = array(
			'sesionwcf'   => $sesionwcf,
			'id_cliente'  => $id_cliente_filter,
			'fecha_desde' => $fecha_desde_filter,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array(
			'x'          => $sesionwcf,
			'id_cliente' => $id_cliente_filter,
		);
		if ( $fecha_desde_filter !== null ) {
			$verial_api_params['fecha'] = $fecha_desde_filter;
		}

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( is_wp_error( $response_verial ) ) {
			return rest_ensure_response( $response_verial );
		}
		$formatted = $this->format_verial_response( $response_verial );
		if ( is_wp_error( $formatted ) ) {
			return rest_ensure_response( $formatted );
		}

		$this->set_cached_data( $cache_params_for_key, $formatted );
		
		// Log de auditoría si llegamos hasta aquí con éxito
		if ( class_exists( 'MiIntegracionApi\\Helpers\\Logger' ) ) {
			Logger::log(
				'info',
				'Acceso a MascotasWS',
				array(
					'params'  => $verial_api_params,
					'usuario' => get_current_user_id(),
				),
				'auditoria'
			);
		}
		return rest_ensure_response( $formatted );
	}
}
