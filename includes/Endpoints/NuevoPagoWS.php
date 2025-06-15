<?php
/**
 * Clase para el endpoint NuevoPagoWS de la API de Verial ERP.
 * Da de alta un nuevo pago para un documento de cliente según el manual v1.7.5.
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
 * Clase para gestionar el endpoint de nuevopagos
 */
class NuevoPagoWS extends Base {
	public const ENDPOINT_NAME               = 'NuevoPagoWS';
	public const CACHE_KEY_PREFIX            = 'mi_api_nuevo_pago_';
	public const CACHE_EXPIRATION            = 0; // No cachear, es escritura
	public const VERIAL_ERROR_SUCCESS        = 0;
/**
 * Implementación requerida por la clase abstracta Base.
 * El registro real de rutas ahora está centralizado en REST_API_Handler.php
 */
public function register_route(): void {
    // Esta implementación está vacía ya que el registro real
    // de rutas ahora se hace de forma centralizada en REST_API_Handler.php
}
	public const VERIAL_ERROR_DOC_NOT_FOUND  = 15;
	public const VERIAL_ERROR_CREATE_PAYMENT = 21;

	public const MAX_LENGTH_OBSERVACIONES = 255;

		// Eliminada la función register_route porque el registro de la ruta ahora es centralizado en REST_API_Handler.php

	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para registrar pagos.', 'mi-integracion-api' ),
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
			'ID_MetodoPago' => array(
				'description'       => __( 'ID del método de pago (numérico, de Verial).', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'Fecha'         => array(
				'description'       => __( 'Fecha del pago (YYYY-MM-DD).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => true,
				'validate_callback' => array( $this, 'validate_date_format' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'Importe'       => array(
				'description'       => __( 'Importe del pago (decimal, ej: 10.50).', 'mi-integracion-api' ),
				'type'              => 'number',
				'required'          => true,
				'sanitize_callback' => array( $this, 'sanitize_decimal' ),
				'validate_callback' => array( $this, 'validate_positive_numeric_strict' ),
			),
			'Observaciones' => array(
				'description'       => __( 'Observaciones adicionales para el pago.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'sanitize_callback' => 'sanitize_textarea_field',
				'maxLength'         => self::MAX_LENGTH_OBSERVACIONES,
			),
		);
	}

	public function validate_date_format( $value, $request, $key ) {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( $parts[1], $parts[2], $parts[0] ) ) {
				return true;
			}
		}
		return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' ), $key ), array( 'status' => 400 ) );
	}

	public function sanitize_decimal( $value, $request, $key ) {
		return ! empty( $value ) ? floatval( str_replace( ',', '.', $value ) ) : null;
	}

	public function validate_positive_numeric_strict( $value, $request, $key ) {
		if ( empty( $value ) && $value !== 0 && $value !== '0' ) {
			return true;
		}
		if ( is_numeric( $value ) && floatval( $value ) > 0 ) {
			return true;
		}
		return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s debe ser un valor numérico estrictamente positivo.', 'mi-integracion-api' ), $key ), array( 'status' => 400 ) );
	}

	public function execute_restful( \WP_REST_Request $request ) {
		// Rate limiting por IP/API key
		$api_key    = $request->get_header( 'X-Api-Key' ) ?: null;
		$rate_limit = \MiIntegracionApi\Helpers\AuthHelper::check_rate_limit( 'nuevo_pago', $api_key );
		if ( $rate_limit instanceof \WP_Error ) {
			return $rate_limit;
		}

		$params              = $request->get_params();
		$id_documento_verial = absint( $request['id_documento_verial'] );

		if ( empty( $id_documento_verial ) ) {
			return new \WP_Error(
				'rest_invalid_document_id',
				__( 'El ID del documento es requerido en la URL.', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		$verial_payload = array(
			'sesionwcf'     => $params['sesionwcf'],
			'ID_DocCli'     => $id_documento_verial,
			'ID_MetodoPago' => $params['ID_MetodoPago'],
			'Fecha'         => $params['Fecha'],
			'Importe'       => round( floatval( $params['Importe'] ), 2 ),
		);

		if ( isset( $params['Observaciones'] ) && ! empty( $params['Observaciones'] ) ) {
			$verial_payload['Observaciones'] = $params['Observaciones'];
		}

		$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );

		// Log de auditoría si hay un error de conexión
		if ( is_wp_error( $result ) ) {
			\MiIntegracionApi\Helpers\LoggerAuditoria::log(
				'Alta fallida de pago (conexión)',
				array(
					'payload'   => $verial_payload ?? null,
					'error'     => $result->get_error_message(),
					'respuesta' => null,
				)
			);
		}

		// La validación de la respuesta se maneja internamente en el conector
		// Solo registramos la auditoría si hay un error devuelto o es un éxito
		if ( ! is_wp_error( $result ) && isset( $result['InfoError'] ) && isset( $result['InfoError']['Codigo'] ) ) {
			$info_error        = $result['InfoError'];
			$error_code_verial = intval( $info_error['Codigo'] );

			if ( $error_code_verial !== self::VERIAL_ERROR_SUCCESS ) {
				$error_message = $info_error['Descripcion'] ?? __( 'Error desconocido de Verial al crear el pago.', 'mi-integracion-api' );

				// Registro de auditoría en caso de error en la respuesta
				\MiIntegracionApi\Helpers\LoggerAuditoria::log(
					'Alta fallida de pago',
					array(
						'payload'   => $verial_payload ?? null,
						'error'     => $error_message ?? 'Error desconocido',
						'respuesta' => $result ?? null,
					)
				);
			}
		}

		// Si no es un error, registramos el éxito en la auditoría
		if ( ! is_wp_error( $result ) ) {
			$id_pago_verial = isset( $result['Id'] ) ? intval( $result['Id'] ) : null;

			// Registro de auditoría para operación exitosa
			\MiIntegracionApi\Helpers\LoggerAuditoria::log(
				'Alta de pago',
				array(
					'id_documento_verial' => $id_documento_verial,
					'id_pago_verial'      => $id_pago_verial,
					'payload'             => $verial_payload,
					'respuesta'           => $result,
				)
			);

			// Formateamos la respuesta para el cliente manteniendo el código 201
			$formatted_result = $this->format_success_response(
				$result,
				[
					'message'             => __( 'Pago creado en Verial con éxito.', 'mi-integracion-api' ),
					'id_documento_verial' => $id_documento_verial,
					'id_pago_verial'      => $id_pago_verial,
				]
			);

			return rest_ensure_response( $formatted_result );
		}

		// Si llegamos aquí, devolvemos la respuesta directamente (que ya debería ser un WP_Error)
		return rest_ensure_response( $result );
	}
}
