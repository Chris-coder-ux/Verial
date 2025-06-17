<?php
/**
 * Clase para el endpoint NuevoClienteWS de la API de Verial ERP.
 * Versión mejorada con validación y sanitización robusta.
 * Basado en el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class NuevoClienteWS extends Base {

	use EndpointLogger;

	const ENDPOINT_NAME        = 'NuevoClienteWS';
	const CACHE_KEY_PREFIX     = 'mi_api_nuevo_cliente_';
	const CACHE_EXPIRATION     = 0; // No cachear, es escritura
	const VERIAL_ERROR_SUCCESS = 0;
/**
 * Implementación requerida por la clase abstracta Base.
 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
 */
public function register_route(): void {
    // Esta implementación está vacía ya que el registro real
    // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
}

	// Longitudes máximas (según manual Verial v1.7.5)
	const MAX_LENGTH_NOMBRE           = 50;
	const MAX_LENGTH_APELLIDO         = 50;
	const MAX_LENGTH_NIF              = 20;
	const MAX_LENGTH_RAZON_SOCIAL     = 50;
	const MAX_LENGTH_DIRECCION        = 75;
	const MAX_LENGTH_CP               = 10;
	const MAX_LENGTH_TELEFONO         = 20;
	const MAX_LENGTH_EMAIL            = 100;
	const MAX_LENGTH_WEB_USER         = 100;
	const MAX_LENGTH_WEB_PASS         = 50;
	const MAX_LENGTH_LOCALIDAD_AUX    = 50;
	const MAX_LENGTH_PROVINCIA_MANUAL = 50;
	const MAX_LENGTH_LOCALIDAD_MANUAL = 100;

	public function __construct() {
		$this->init_logger( 'clientes' );
	}

	// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para realizar esta acción.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		$args = array(
			'sesionwcf'        => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Id'               => array(
				'description'       => __( 'ID del cliente en Verial para modificaciones.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => $is_update,
				'sanitize_callback' => 'absint',
			),
			'Tipo'             => array(
				'description'       => __( 'Tipo de cliente: 1 (Particular) o 2 (Empresa).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'enum'              => array( 1, 2 ),
				'sanitize_callback' => 'absint',
			),
			'NIF'              => array(
				'description'       => __( 'NIF/CIF del cliente.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => ! $is_update,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_NIF;
				},
			),
			'Nombre'           => array(
				'description'       => __( 'Nombre del cliente o razón social si es empresa y no se proporciona RazonSocial.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_NOMBRE;
				},
			),
			'Apellido1'        => array(
				'description'       => __( 'Primer apellido (para particulares).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_APELLIDO;
				},
			),
			'Apellido2'        => array(
				'description'       => __( 'Segundo apellido (para particulares).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_APELLIDO;
				},
			),
			'RazonSocial'      => array(
				'description'       => __( 'Razón social (para empresas).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_RAZON_SOCIAL;
				},
			),
			'RegFiscal'        => array(
				'description'       => __( 'Régimen fiscal (1-8 según manual).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'enum'              => array( 1, 2, 3, 4, 5, 6, 7, 8 ),
				'sanitize_callback' => 'absint',
			),
			'ID_Pais'          => array(
				'description'       => __( 'ID del país (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'ID_Provincia'     => array(
				'description'       => __( 'ID de la provincia (numérico, de Verial). Opcional si se envía Provincia.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Provincia'        => array(
				'description'       => __( 'Nombre de la provincia (si no se usa ID_Provincia).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_PROVINCIA_MANUAL;
				},
			),
			'ID_Localidad'     => array(
				'description'       => __( 'ID de la localidad (numérico, de Verial). Opcional si se envía Localidad.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'Localidad'        => array(
				'description'       => __( 'Nombre de la localidad (si no se usa ID_Localidad).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_LOCALIDAD_MANUAL;
				},
			),
			'LocalidadAux'     => array(
				'description'       => __( 'Información adicional de la localidad.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_LOCALIDAD_AUX;
				},
			),
			'CPostal'          => array(
				'description'       => __( 'Código postal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_CP;
				},
			),
			'Direccion'        => array(
				'description'       => __( 'Dirección completa.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_DIRECCION;
				},
			),
			'Telefono'         => array(
				'description'       => __( 'Número de teléfono principal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_TELEFONO;
				},
			),
			'Telefono1'        => array(
				'description'       => __( 'Número de teléfono 1.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_TELEFONO;
				},
			),
			'Telefono2'        => array(
				'description'       => __( 'Número de teléfono 2.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_TELEFONO;
				},
			),
			'Movil'            => array(
				'description'       => __( 'Número de teléfono móvil.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_TELEFONO;
				},
			),
			'Email'            => array(
				'description'       => __( 'Dirección de correo electrónico principal.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'format'            => 'email',
				'sanitize_callback' => 'sanitize_email',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_EMAIL;
				},
			),
			'Sexo'             => array(
				'description'       => __( 'Sexo: 0 (No aplicable), 1 (Masculino), 2 (Femenino).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'enum'              => array( 0, 1, 2 ),
				'sanitize_callback' => 'absint',
			),
			'ID_Agente1'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Agente2'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_Agente3'       => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'ID_MetodoPago'    => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'WebUserOld'       => array(
				'description'       => __( 'Nombre de usuario web antiguo (si se cambia).', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_WEB_USER;
				},
			),
			'WebUser'          => array(
				'description'       => __( 'Nombre de usuario para la web.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $param ) {
					return is_string( $param ) && mb_strlen( $param ) <= self::MAX_LENGTH_WEB_USER;
				},
			),
			'WebPassword'      => array(
				'description' => __( 'Contraseña para la web.', 'mi-integracion-api' ),
				'type'        => 'string',
				'required'    => false,
				'maxLength'   => self::MAX_LENGTH_WEB_PASS,
			),
			'EnviarAnuncios'   => array(
				'description'       => __( 'Indica si el cliente acepta recibir anuncios.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'DireccionesEnvio' => array(
				'description' => __( 'Array de direcciones de envío.', 'mi-integracion-api' ),
				'type'        => 'array',
				'required'    => false,
			),
			'Aux1'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'Aux2'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'Aux3'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'Aux4'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'Aux5'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'Aux6'             => array(
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_text_field',
				'maxLength'         => 50,
			),
			'ID_Grupo'         => array(
				'type'              => 'integer',
				'required'          => false,
				'sanitize_callback' => 'absint',
			),
			'context'          => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
		return $args;
	}

	/**
	 * Devuelve la definición de los argumentos de cliente (sin sesionwcf, Id, etc.)
	 * para ser reutilizada en otros endpoints.
	 *
	 * @return array
	 */
	public static function get_cliente_properties_args(): array {
		$args = ( new self() )->get_endpoint_args( false );
		// Eliminar campos que no son propios del cliente (ej: sesionwcf, Id)
		unset( $args['sesionwcf'], $args['Id'] );
		return $args;
	}

	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nuevo_cliente', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return rest_ensure_response( $rate_limit );
		}

		$params = $request->get_params();

		if ( isset( $request['id_cliente_verial'] ) ) {
			$params['Id'] = absint( $request['id_cliente_verial'] );
		}

		$verial_payload = array();
		$verial_fields  = array(
			'sesionwcf',
			'Id',
			'Tipo',
			'NIF',
			'Nombre',
			'Apellido1',
			'Apellido2',
			'RazonSocial',
			'RegFiscal',
			'ID_Pais',
			'ID_Provincia',
			'Provincia',
			'ID_Localidad',
			'Localidad',
			'LocalidadAux',
			'CPostal',
			'Direccion',
			'Telefono',
			'Telefono1',
			'Telefono2',
			'Movil',
			'Email',
			'Sexo',
			'ID_Agente1',
			'ID_Agente2',
			'ID_Agente3',
			'ID_MetodoPago',
			'WebUserOld',
			'WebUser',
			'WebPassword',
			'EnviarAnuncios',
			'DireccionesEnvio',
			'Aux1',
			'Aux2',
			'Aux3',
			'Aux4',
			'Aux5',
			'Aux6',
			'ID_Grupo',
		);

		// Provincia
		if ( ! empty( $params['ID_Provincia'] ) ) {
			$verial_payload['ID_Provincia'] = intval( $params['ID_Provincia'] );
		} elseif ( ! empty( $params['Provincia'] ) ) {
			$verial_payload['Provincia'] = $params['Provincia'];
		}
		// Localidad
		if ( ! empty( $params['ID_Localidad'] ) ) {
			$verial_payload['ID_Localidad'] = intval( $params['ID_Localidad'] );
		} elseif ( ! empty( $params['Localidad'] ) ) {
			$verial_payload['Localidad'] = $params['Localidad'];
		}
		// Resto de campos
		foreach ( $verial_fields as $field_key ) {
			if ( in_array( $field_key, array( 'ID_Provincia', 'Provincia', 'ID_Localidad', 'Localidad' ) ) ) {
				continue;
			}
			if ( array_key_exists( $field_key, $params ) ) {
				$value = $params[ $field_key ];
				if ( $value === '' && ! in_array( $field_key, array( 'Email' ) ) ) {
					$verial_payload[ $field_key ] = null;
				} else {
					$verial_payload[ $field_key ] = $value;
				}
			}
		}

		// Lógica para manejar Telefono vs Telefono1, Telefono2, Movil
		if ( ! empty( $verial_payload['Telefono'] ) ) {
			unset( $verial_payload['Telefono1'] );
			unset( $verial_payload['Telefono2'] );
			unset( $verial_payload['Movil'] );
		}

		// Envío a Verial
		$verial_response = $this->send_to_verial( $verial_payload, $params );

		// Respuesta de Verial
		if ( is_wp_error( $verial_response ) ) {
			return rest_ensure_response( $verial_response );
		}

		// Respuesta estándar de éxito
		return rest_ensure_response( $this->format_success_response( $verial_response ) );
	}

	/**
	 * Envía los datos a Verial y maneja la respuesta.
	 *
	 * @param array $data Datos a enviar.
	 * @param array $request_params Parámetros originales de la solicitud.
	 * @return array|\WP_Error Respuesta de Verial o error.
	 */
	protected function send_to_verial( array $data, array $request_params ): array|\WP_Error {
		// Preparar datos para Verial
		$verial_data = $this->prepare_data_for_verial( $data );

		// Envío a Verial (simulado aquí como un éxito inmediato)
		$response = $this->simulate_verial_response( $verial_data );

		return $response;
	}

	/**
	 * Prepara los datos para el formato esperado por Verial.
	 *
	 * @param array $data Datos originales.
	 * @return array Datos preparados.
	 */
	protected function prepare_data_for_verial( array $data ): array {
		// Ejemplo de preparación: renombrar campos, formatear valores, etc.
		$prepared = array(
			'sesionwcf'        => $data['sesionwcf'],
			'Id'               => $data['Id'] ?? null,
			'Tipo'             => $data['Tipo'],
			'NIF'              => $data['NIF'],
			'Nombre'           => $data['Nombre'],
			'Apellido1'        => $data['Apellido1'] ?? null,
			'Apellido2'        => $data['Apellido2'] ?? null,
			'RazonSocial'      => $data['RazonSocial'] ?? null,
			'RegFiscal'        => $data['RegFiscal'],
			'ID_Pais'          => $data['ID_Pais'],
			'ID_Provincia'     => $data['ID_Provincia'] ?? null,
			'Provincia'        => $data['Provincia'] ?? null,
			'ID_Localidad'     => $data['ID_Localidad'] ?? null,
			'Localidad'        => $data['Localidad'] ?? null,
			'LocalidadAux'     => $data['LocalidadAux'] ?? null,
			'CPostal'          => $data['CPostal'] ?? null,
			'Direccion'        => $data['Direccion'] ?? null,
			'Telefono'         => $data['Telefono'] ?? null,
			'Movil'            => $data['Movil'] ?? null,
			'Email'            => $data['Email'] ?? null,
			'Sexo'             => $data['Sexo'] ?? null,
			'ID_Agente1'       => $data['ID_Agente1'] ?? null,
			'ID_Agente2'       => $data['ID_Agente2'] ?? null,
			'ID_Agente3'       => $data['ID_Agente3'] ?? null,
			'ID_MetodoPago'    => $data['ID_MetodoPago'] ?? null,
			'WebUserOld'       => $data['WebUserOld'] ?? null,
			'WebUser'          => $data['WebUser'] ?? null,
			'WebPassword'      => $data['WebPassword'] ?? null,
			'EnviarAnuncios'   => $data['EnviarAnuncios'] ?? null,
			'DireccionesEnvio' => $data['DireccionesEnvio'] ?? null,
			'Aux1'             => $data['Aux1'] ?? null,
			'Aux2'             => $data['Aux2'] ?? null,
			'Aux3'             => $data['Aux3'] ?? null,
			'Aux4'             => $data['Aux4'] ?? null,
			'Aux5'             => $data['Aux5'] ?? null,
			'Aux6'             => $data['Aux6'] ?? null,
			'ID_Grupo'         => $data['ID_Grupo'] ?? null,
		);

		return $prepared;
	}

	/**
	 * Simula una respuesta de Verial para propósitos de prueba.
	 *
	 * @param array $data Datos enviados.
	 * @return array Respuesta simulada.
	 */
	protected function simulate_verial_response( array $data ): array {
		// Simulación de respuesta exitosa
		return array_merge(
			$data,
			array(
				'response_code'    => self::VERIAL_ERROR_SUCCESS,
				'response_message' => __( 'Éxito', 'mi-integracion-api' ),
			)
		);
	}
}
