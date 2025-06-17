<?php
/**
 * Clase para el endpoint BorrarMascotaWS de la API de Verial ERP.
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Helpers\EndpointArgs;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

if ( ! class_exists( 'MiIntegracionApi\\Endpoints\\BorrarMascotaWS' ) && class_exists( 'MiIntegracionApi\\Endpoints\\Base' ) ) {
	class BorrarMascotaWS extends Base {

		const ENDPOINT_NAME        = 'BorrarMascotaWS';
		const CACHE_KEY_PREFIX     = 'mia_borrar_mascota_';
		const CACHE_EXPIRATION     = HOUR_IN_SECONDS;
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
			// Ejemplo: Solo usuarios que pueden editar clientes/pedidos. Ajustar según necesidad.
			if ( ! current_user_can( 'edit_shop_orders' ) ) {
				$error_message = esc_html__( 'No tienes permiso para borrar mascotas.', 'mi-integracion-api' );
				$error_message = is_string( $error_message ) ? $error_message : 'No tienes permiso para borrar mascotas.';

				return new \WP_Error(
					'rest_forbidden',
					$error_message,
					array( 'status' => rest_authorization_required_code() )
				);
			}
			return true;
		}

		public function get_endpoint_args( bool $is_update = false ): array {
			return array(
				'id_cliente_verial' => array(
					'description' => __( 'ID del cliente en Verial.', 'mi-integracion-api' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'id_mascota_verial' => array(
					'description' => __( 'ID de la mascota a borrar.', 'mi-integracion-api' ),
					'type'        => 'integer',
					'required'    => true,
				),
				'sesionwcf'         => EndpointArgs::sesionwcf(),
				'context'           => EndpointArgs::context(),
			);
		}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints.
	 *
	 * @param mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 */
	protected function format_success_response($data, array $extra = []): array {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}

		public function execute_restful(\WP_REST_Request $request): \WP_REST_Response|\WP_Error {
			// Los parámetros de la URL son la fuente principal para los IDs.
			$id_cliente_verial = absint( $request['id_cliente_verial'] );
			$id_mascota_verial = absint( $request['id_mascota_verial'] );

			// El parámetro 'sesionwcf' viene del cuerpo JSON y ya está validado/sanitizado por 'args'.
			$sesionwcf = $request->get_param( 'sesionwcf' );

			if ( empty( $id_cliente_verial ) || empty( $id_mascota_verial ) ) {
				\LoggerAuditoria::log(
					'Error: IDs vacíos en URL para BorrarMascotaWS',
					array(
						'request' => $request->get_params(),
						'usuario' => get_current_user_id(),
					)
				);
				return new \WP_Error(
					'rest_invalid_ids',
					__( 'Los IDs del cliente y de la mascota son requeridos en la URL.', 'mi-integracion-api' ),
					array( 'status' => 400 )
				);
			}
			if ( is_null( $sesionwcf ) ) {
				\LoggerAuditoria::log(
					'Error: sesionwcf vacío en BorrarMascotaWS',
					array(
						'request' => $request->get_params(),
						'usuario' => get_current_user_id(),
					)
				);
				return new \WP_Error(
					'rest_missing_sesionwcf',
					__( 'El parámetro sesionwcf es requerido en el cuerpo de la solicitud.', 'mi-integracion-api' ),
					array( 'status' => 400 )
				);
			}

			$verial_payload = array(
				'sesionwcf'  => $sesionwcf,
				'ID_Cliente' => $id_cliente_verial,
				'Id'         => $id_mascota_verial, // 'Id' es el identificador de la mascota en Verial
			);

			// Llamada POST a Verial aunque la ruta sea DELETE en la API REST local
			$result = $this->connector->post( self::ENDPOINT_NAME, $verial_payload );

			// Log de auditoría si hay un error de conexión
			if ( is_wp_error( $result ) ) {
				\LoggerAuditoria::log(
					'Baja fallida de mascota (conexión)',
					array(
						'id_cliente_verial'  => isset( $id_cliente_verial ) ? $id_cliente_verial : null,
						'id_mascota_borrada' => isset( $id_mascota_verial ) ? $id_mascota_verial : null,
						'payload'            => $verial_payload,
						'error'              => $result->get_error_message(),
						'respuesta'          => null,
					)
				);
			}

			// La validación de la respuesta se maneja en el conector API
			// Solo registramos la auditoría en caso de error o éxito
			if ( ! is_wp_error( $result ) && isset( $result['InfoError'] ) && isset( $result['InfoError']['Codigo'] ) ) {
				$info_error        = $result['InfoError'];
				$error_code_verial = intval( $info_error['Codigo'] );

				if ( $error_code_verial !== self::VERIAL_ERROR_SUCCESS ) {
					$error_message = isset( $info_error['Descripcion'] ) ? $info_error['Descripcion'] : __( 'Error desconocido de Verial al borrar mascota.', 'mi-integracion-api' );

					// Registro de auditoría en caso de error en la respuesta
					\LoggerAuditoria::log(
						'Baja fallida de mascota',
						array(
							'id_cliente_verial'  => isset( $id_cliente_verial ) ? $id_cliente_verial : null,
							'id_mascota_borrada' => isset( $id_mascota_verial ) ? $id_mascota_verial : null,
							'payload'            => $verial_payload,
							'error'              => $error_message,
							'respuesta'          => $result,
						)
					);
				}
			}

			// Si no es un error, registramos el éxito en la auditoría
			if ( ! is_wp_error( $result ) ) {
				// Registro de auditoría para operación exitosa
				\LoggerAuditoria::log(
					'Baja de mascota',
					array(
						'id_cliente_verial'  => $id_cliente_verial,
						'id_mascota_borrada' => $id_mascota_verial,
						'payload'            => $verial_payload,
						'respuesta'          => $result,
					)
				);

				// Formatear la respuesta para el cliente
				$formatted_result = $this->format_success_response(null, [
					'message'            => __( 'Mascota borrada en Verial con éxito.', 'mi-integracion-api' ),
					'id_cliente_verial'  => $id_cliente_verial,
					'id_mascota_borrada' => $id_mascota_verial,
				]);

				return rest_ensure_response( $formatted_result );
			}

			// Si llegamos aquí, devolvemos la respuesta (que debería ser un WP_Error)
			return rest_ensure_response( $result );
		}
	}
}

// add_action('rest_api_init', function () {
// });
