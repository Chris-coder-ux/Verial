<?php
/**
 * Clase para el endpoint GetClientesWS de la API de Verial ERP.
 * Obtiene el listado de clientes, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\Logger;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Clase para gestionar el endpoint de clienteses
 */
class ClientesWS extends Base {
	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetClientesWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_clientes_';
	public const CACHE_EXPIRATION     = 6 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;
	public const MAX_LENGTH_NIF       = 20;

	/**
	 * Verifica los permisos para acceder al endpoint de clientes.
	 *
	 * Justificación: Se requiere 'manage_woocommerce' porque el listado de clientes es información sensible y solo debe estar disponible para usuarios con privilegios elevados en la tienda.
	 * Si en el futuro se requiere exponer datos menos sensibles o filtrados por usuario autenticado, se podrá reducir el nivel de privilegio.
	 */
	public function permissions_check( WP_REST_Request $request ): bool|WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver la información de los clientes.', 'mi-integracion-api' ),
				array( 'status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code() )
			);
		}
		if ( class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
			if ( $auth_result !== true ) {
				return $auth_result;
			}
		}
		return true;
	}

	/**
	 * Define los argumentos del endpoint REST.
	 *
	 * @param bool $is_update Si es una actualización o no
	 * @return array<string, mixed> Argumentos del endpoint
	 */
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
			'id_cliente'  => array(
				'description'       => __( 'ID del cliente en Verial para filtrar (0 para todos).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'nif'         => array(
				'description'       => __( 'NIF/CIF del cliente para filtrar.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ): bool {
					return is_string( $param ) && mb_strlen( $param ) <= static::MAX_LENGTH_NIF;
				},
			),
			'fecha_desde' => array(
				'description'       => __( 'Filtrar clientes creados/modificados desde esta fecha (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hora'        => array(
				'description'       => __( 'Filtrar clientes desde una hora específica (HH:MM:SS).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context' => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
	}

	/**
	 * @param array<string, mixed> $verial_response
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	protected function format_verial_response( array $verial_response ): array|WP_Error {
		if ( ! isset( $verial_response['Clientes'] ) || ! is_array( $verial_response['Clientes'] ) ) {
			Logger::error( __( '[GetClientesWS] La respuesta de Verial no contiene la clave "Clientes" esperada o no es un array.', 'mi-integracion-api' ), array( 'verial_response' => $verial_response ) );
			return new WP_Error(
				'verial_api_malformed_clientes_data',
				__( 'Los datos de clientes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array(
					'status'          => 500,
					'verial_response' => $verial_response,
				)
			);
		}
		$clientes_formateados = array();
		foreach ( $verial_response['Clientes'] as $cliente ) {
			if ( ! is_array( $cliente ) ) {
				continue;
			}
			$id = null;
			if ( isset( $cliente['Id'] ) ) {
				$tmp = $cliente['Id'];
				/** @var int|string $tmp */
				if ( is_int( $tmp ) ) {
					$id = $tmp;
				} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
					$id = (int) $tmp;
				}
			}
			$nombre = isset( $cliente['Nombre'] ) && is_string( $cliente['Nombre'] ) ? sanitize_text_field( $cliente['Nombre'] ) : null;
			$nif    = isset( $cliente['NIF'] ) && is_string( $cliente['NIF'] ) ? sanitize_text_field( $cliente['NIF'] ) : null;
			$email  = isset( $cliente['Email1'] ) && is_string( $cliente['Email1'] ) ? sanitize_email( $cliente['Email1'] ) : null;
			$tipo   = null;
			if ( isset( $cliente['Tipo'] ) ) {
				$tmp = $cliente['Tipo'];
				/** @var int|string $tmp */
				if ( is_int( $tmp ) ) {
					$tipo = $tmp;
				} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
					$tipo = (int) $tmp;
				}
			}
			$clientes_formateados[] = array(
				'id'     => $id,
				'nombre' => $nombre,
				'nif'    => $nif,
				'email'  => $email,
				'tipo'   => $tipo,
			);
		}
		return $clientes_formateados;
	}

	/**
	 * NOTA: PHPStan puede reportar un falso positivo aquí indicando que el método retorna array, pero la lógica garantiza que solo se retorna WP_Error o WP_REST_Response.
	 * El tipado y los chequeos aseguran la robustez y cumplimiento del contrato de retorno.
	 * Si se requiere un análisis 100% limpio, revisar la configuración de stubs y el flujo de tipado estricto.
	 */
	public function execute_restful( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$params_mixed = $request->get_params();
		if ( ! is_array( $params_mixed ) ) {
			return new WP_Error( 'invalid_params', __( 'Los parámetros de la petición no son válidos.', 'mi-integracion-api' ), array( 'status' => 400 ) );
		}
		$params    = $params_mixed;
		$sesionwcf = null;
		if ( isset( $params['sesionwcf'] ) ) {
			$tmp = $params['sesionwcf'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$sesionwcf = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$sesionwcf = (int) $tmp;
			}
		}
		$force_refresh = isset( $params['force_refresh'] ) ? (bool) $params['force_refresh'] : false;
		$id_cliente    = null;
		if ( isset( $params['id_cliente'] ) ) {
			$tmp = $params['id_cliente'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$id_cliente = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$id_cliente = (int) $tmp;
			}
		}
		$nif               = isset( $params['nif'] ) && is_string( $params['nif'] ) ? $params['nif'] : null;
		$fecha_desde       = isset( $params['fecha_desde'] ) && is_string( $params['fecha_desde'] ) ? $params['fecha_desde'] : null;
		$hora              = isset( $params['hora'] ) && is_string( $params['hora'] ) ? $params['hora'] : null;
		$id_cliente_verial = null;
		if ( isset( $params['id_cliente_verial'] ) ) {
			$tmp = $params['id_cliente_verial'];
			/** @var int|string $tmp */
			if ( is_int( $tmp ) ) {
				$id_cliente_verial = $tmp;
			} elseif ( is_string( $tmp ) && ctype_digit( $tmp ) ) {
				$id_cliente_verial = (int) $tmp;
			}
		}
		$cache_key = 'sesion_' . $sesionwcf;
		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_key );
			if ( null !== $cached_data ) {
				return new WP_REST_Response( $cached_data, 200 );
			}
		}
		$verial_api_params = array( 'x' => $sesionwcf );
		if ( $id_cliente !== null ) {
			$verial_api_params['id_cliente'] = $id_cliente;
		}
		if ( $nif !== null ) {
			$verial_api_params['nif'] = sanitize_text_field( $nif );
		}
		if ( $fecha_desde !== null ) {
			$verial_api_params['fecha'] = sanitize_text_field( $fecha_desde );
		}
		if ( $hora !== null ) {
			$verial_api_params['hora'] = sanitize_text_field( $hora );
		}
		if ( $id_cliente_verial !== null ) {
			$verial_api_params['id_cliente'] = $id_cliente_verial;
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
		return rest_ensure_response( $formatted );
	}

	/**
	 * Registra la ruta del endpoint.
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/clientes',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
				'args'               => $this->get_endpoint_args(),
			)
		);
	}
}
