<?php
/**
 * Registro de rutas de la API REST de WordPress
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para gestionar los callbacks y permisos de las rutas de la API REST
 * Nota: El registro de rutas se ha centralizado en REST_API_Handler.php
 */
class REST_Controller {

	/**
	 * Namespace para las rutas de la API
	 *
	 * @var string
	 */
	const API_NAMESPACE = 'mi-integracion-api/v1';

	/**
	 * Inicializa el controlador REST
	 * Nota: Ya no registra rutas directamente, esto lo hace REST_API_Handler
	 */
	public static function init() {
		// El registro de rutas ahora está centralizado en REST_API_Handler
	}

	/**
	 * Este método ya no registra rutas directamente
	 * Se mantiene por compatibilidad, pero no realiza ninguna acción
	 *
	 * @deprecated Las rutas ahora se registran en REST_API_Handler
	 */
	public static function register_routes() {
		// Las rutas ahora se registran en REST_API_Handler
		// Este método se mantiene por compatibilidad
		return;
	}

	/**
	 * Verifica los permisos de administración
	 *
	 * @return bool Verdadero si el usuario tiene permisos
	 */
	public static function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Verifica los permisos de autenticación (usuario y contraseña)
	 *
	 * @return bool True si el usuario tiene permisos
	 */
	public static function check_auth_permissions() {
		// Esta función permite el acceso al endpoint de autenticación
		// El endpoint validará las credenciales internamente
		return true;
	}

	/**
	 * Verifica los permisos mediante token JWT o permisos de administrador
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return bool True si el usuario tiene permisos
	 */
	public static function check_auth_or_admin_permissions( $request ) {
		// Verificar si es administrador
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Verificar token JWT
		$auth_header = $request->get_header( 'Authorization' );
		if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
			$jwt         = $matches[1];
			$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
			$decoded     = $jwt_manager->validate_token( $jwt );
			return $decoded !== false;
		}

		return false;
	}

	/**
	 * Verifica los permisos mediante una función de callback personalizada
	 *
	 * @param callable $callback Función de verificación
	 * @return callable Función que verifica permisos
	 */
	public static function check_with_callback( $callback ) {
		return function ( $request ) use ( $callback ) {
			return call_user_func( $callback, $request );
		};
	}

	/**
	 * Obtiene las credenciales de Verial (protegidas)
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function get_credentials( \WP_REST_Request $request ) {
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
		$credentials  = $auth_manager->get_credentials();

		if ( ! $credentials ) {
			return API_Response_Handler::error(
				'no_credentials',
				__('No hay credenciales guardadas.', 'mi-integracion-api'),
				[],
				404
			);
		}

		// Nunca devolver la contraseña por seguridad
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = '';
		}

		return API_Response_Handler::success($credentials);
	}

	/**
	 * Guarda las credenciales de Verial
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function save_credentials( \WP_REST_Request $request ) {
		$params = $request->get_params();

		// Validar y sanitizar credenciales
		$validation_result = Credentials_Validator::validate_and_sanitize($params);
		
		if (!empty($validation_result['errors'])) {
			return API_Response_Handler::validation_error($validation_result['errors']);
		}

		// Obtener credenciales actuales para conservar la contraseña si no se proporciona una nueva
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
		$current_credentials = $auth_manager->get_credentials();

		$new_credentials = $validation_result['credentials'];

		// Si no se proporciona una nueva contraseña, mantener la actual
		if (!isset($new_credentials['password']) || empty($new_credentials['password'])) {
			$new_credentials['password'] = $current_credentials['password'] ?? '';
		}

		// Guardar credenciales
		$result = $auth_manager->save_credentials($new_credentials);

		if (!$result) {
			return API_Response_Handler::error(
				'save_failed',
				__('No se pudieron guardar las credenciales.', 'mi-integracion-api'),
				[],
				500
			);
		}

		return API_Response_Handler::success(null, [
			'message' => __('Credenciales guardadas correctamente.', 'mi-integracion-api')
		]);
	}

	/**
	 * Prueba la conexión con Verial ERP
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function test_connection( \WP_REST_Request $request ) {
		$params = $request->get_params();

		try {
			$api_connector = function_exists('mi_integracion_api_get_connector')
				? \MiIntegracionApi\Helpers\ApiHelpers::get_connector()
				: new \MiIntegracionApi\Core\ApiConnector();

			// Si se proporcionan credenciales temporales, usarlas para la prueba
			if (isset($params['api_url']) && isset($params['username'])) {
				$temp_credentials = [
					'api_url' => sanitize_text_field($params['api_url']),
					'username' => sanitize_text_field($params['username']),
					'password' => $params['password'] ?? '',
				];
				$api_connector->set_credentials($temp_credentials);
			}

			// Verificar que hay credenciales configuradas
			if (!$api_connector->has_valid_credentials()) {
				return API_Response_Handler::error(
					'no_credentials',
					__('No hay credenciales válidas configuradas.', 'mi-integracion-api'),
					[],
					400
				);
			}

			// Realizar la prueba de conexión
			$result = $api_connector->test_connection();

			if ($result['success']) {
				return API_Response_Handler::success($result['data']);
			} else {
				return API_Response_Handler::error(
					'connection_failed',
					$result['message'] ?? __('Error al conectar con Verial ERP', 'mi-integracion-api'),
					$result['data'] ?? [],
					500
				);
			}
		} catch (\Exception $e) {
			return API_Response_Handler::error(
				'connection_error',
				$e->getMessage(),
				[],
				500
			);
		}
	}

	/**
	 * Obtiene el estado actual de la conexión
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function get_connection_status( \WP_REST_Request $request ) {
		$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();

		return new \WP_REST_Response(
			array(
				'connected' => $auth_manager->has_credentials(),
			)
		);
	}

	/**
	 * Inicia un proceso de sincronización
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function start_sync( \WP_REST_Request $request ) {
		$params = $request->get_params();

		// Validar parámetros requeridos
		if ( ! isset( $params['entity'] ) || ! in_array( $params['entity'], array( 'products', 'orders' ) ) ) {
			return new \WP_Error(
				'invalid_entity',
				__( 'Entidad inválida para sincronización.', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $params['direction'] ) || ! in_array( $params['direction'], array( 'wc_to_verial', 'verial_to_wc' ) ) ) {
			return new \WP_Error(
				'invalid_direction',
				__( 'Dirección de sincronización inválida.', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();

		// Iniciar sincronización
		$result = $sync_manager->start_sync(
			$params['entity'],
			$params['direction'],
			isset( $params['filters'] ) ? $params['filters'] : array()
		);

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'sync_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Sincronización iniciada correctamente.', 'mi-integracion-api' ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Procesa el siguiente lote de sincronización
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function process_next_batch( \WP_REST_Request $request ) {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();

		$result = $sync_manager->process_next_batch();

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'batch_error',
				$result->get_error_message(),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
			)
		);
	}

	/**
	 * Cancela la sincronización actual
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function cancel_sync( \WP_REST_Request $request ) {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();

		$result = $sync_manager->cancel_sync();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Sincronización cancelada.', 'mi-integracion-api' ),
				'data'    => $result,
			)
		);
	}

	/**
	 * Obtiene el estado actual de sincronización
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function get_sync_status( \WP_REST_Request $request ): \WP_REST_Response {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$status = $sync_manager->get_sync_status();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'status'  => $status,
			)
		);
	}

	/**
	 * Obtiene el historial de sincronizaciones
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function get_sync_history( \WP_REST_Request $request ): \WP_REST_Response {
		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$history = $sync_manager->get_sync_history();

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $history,
			)
		);
	}

	/**
	 * Inicia un reintento de sincronización para un conjunto de errores.
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST.
	 * @return \WP_REST_Response|\WP_Error Respuesta o error.
	 */
	public static function retry_sync_errors( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$params = $request->get_params();
		$error_ids = $params['error_ids'] ?? [];

		if ( empty( $error_ids ) || ! is_array( $error_ids ) ) {
			return new \WP_Error(
				'no_errors_specified',
				__( 'No se especificaron errores para reintentar.', 'mi-integracion-api' ),
				[ 'status' => 400 ]
			);
		}

		$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
		$result = $sync_manager->retry_sync_errors( array_map( 'intval', $error_ids ) );

		if ( is_wp_error( $result ) ) {
			return new \WP_Error(
				'retry_failed',
				$result->get_error_message(),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Reintento de sincronización iniciado.', 'mi-integracion-api' ),
				'data'    => $result,
			]
		);
	}

	/**
	 * Realiza una prueba de API con Verial
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function test_api( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$response = wp_remote_get('https://api.example.com/test');
			
			if (is_wp_error($response)) {
				return new \WP_Error(
					'api_error',
					$response->get_error_message(),
					array('status' => 500)
				);
			}

			$status_code = wp_remote_retrieve_response_code($response);
			if ($status_code !== 200) {
				return new \WP_Error(
					'api_error',
					__('Error al conectar con el servidor.', 'mi-integracion-api'),
					array('status' => $status_code)
				);
			}

			$body = wp_remote_retrieve_body($response);
			if (empty($body)) {
				return new \WP_Error(
					'empty_response',
					__('Respuesta vacía del servidor.', 'mi-integracion-api'),
					array('status' => 500)
				);
			}

			$data = json_decode($body, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				return new \WP_Error(
					'invalid_json',
					__('Respuesta incorrecta del servidor. No es un formato JSON válido.', 'mi-integracion-api'),
					array('status' => 500)
				);
			}

			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => __('Conexión exitosa con Verial ERP.', 'mi-integracion-api'),
					'data'    => $data,
				)
			);
		} catch (\Exception $e) {
			return new \WP_Error(
				'rest_error',
				$e->getMessage(),
				array('status' => 500)
			);
		}
	}

	/**
	 * Genera un token JWT para autenticación
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function generate_token( \WP_REST_Request $request ) {
		$params   = $request->get_params();
		$username = $params['username'] ?? '';
		$password = $params['password'] ?? '';

		// Autenticar con WordPress
		$user = wp_authenticate( $username, $password );

		if ( is_wp_error( $user ) ) {
			return new \WP_Error(
				'invalid_credentials',
				__( 'Credenciales inválidas', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		}

		// Verificar capacidades (mínimo debe ser autor)
		if ( ! user_can( $user->ID, 'edit_posts' ) && ! user_can( $user->ID, 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'insufficient_permissions',
				__( 'Este usuario no tiene permisos suficientes para usar la API', 'mi-integracion-api' ),
				array( 'status' => 403 )
			);
		}

		// Generar token JWT
		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();

		$token = $jwt_manager->generate_token(
			$user->ID,
			array(
				'username'     => $user->user_login,
				'email'        => $user->user_email,
				'display_name' => $user->display_name,
				'roles'        => $user->roles,
			)
		);

		if ( ! $token ) {
			return new \WP_Error(
				'token_generation_failed',
				__( 'Error al generar el token de autenticación', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success'           => true,
				'token'             => $token,
				'user_id'           => $user->ID,
				'user_display_name' => $user->display_name,
				'user_email'        => $user->user_email,
			)
		);
	}

	/**
	 * Valida un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function validate_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			return new \WP_Error(
				'missing_token',
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$decoded     = $jwt_manager->validate_token( $token );

		if ( ! $decoded ) {
			return new \WP_Error(
				'invalid_token',
				__( 'Token inválido o expirado', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'valid'   => true,
				'user_id' => $decoded->data->user_id,
				'expires' => $decoded->exp,
			)
		);
	}

	/**
	 * Renueva un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function refresh_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			return new \WP_Error(
				'missing_token',
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$new_token   = $jwt_manager->renew_token( $token );

		if ( ! $new_token ) {
			return new \WP_Error(
				'token_renewal_failed',
				__( 'Error al renovar el token', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'token'   => $new_token,
			)
		);
	}

	/**
	 * Revoca un token JWT
	 *
	 * @param \WP_REST_Request $request Solicitud REST
	 * @return \WP_REST_Response|\WP_Error Respuesta o error
	 */
	public static function revoke_token( \WP_REST_Request $request ) {
		$params = $request->get_params();
		$token  = $params['token'] ?? '';

		if ( empty( $token ) ) {
			// Intentar obtener el token del encabezado Authorization
			$auth_header = $request->get_header( 'Authorization' );
			if ( $auth_header && preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
				$token = $matches[1];
			}
		}

		if ( empty( $token ) ) {
			return new \WP_Error(
				'missing_token',
				__( 'Token no proporcionado', 'mi-integracion-api' ),
				array( 'status' => 400 )
			);
		}

		$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
		$success     = $jwt_manager->revoke_token( $token );

		if ( ! $success ) {
			return new \WP_Error(
				'token_revocation_failed',
				__( 'Error al revocar el token', 'mi_integracion_api' ),
				array( 'status' => 400 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Token revocado correctamente', 'mi_integracion_api' ),
			)
		);
	}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints REST estáticos.
	 *
	 * @param array|mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 */
	private static function format_success_response($data = null, array $extra = []) {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}
}
