<?php
/**
 * Helper para autenticación JWT en endpoints REST
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase auxiliar para autenticar endpoints de API mediante JWT
 */
class JwtAuthHelper {
	/**
	 * Verifica si una solicitud tiene un JWT válido
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud
	 * @return bool|WP_Error True si es válido, WP_Error si no
	 */
	public static function verify_jwt_auth( $request ) {
		// Obtener token del encabezado Authorization
		$auth_header = $request->get_header( 'Authorization' );

		if ( ! $auth_header || ! preg_match( '/Bearer\s+(.*)$/i', $auth_header, $matches ) ) {
			return new \WP_Error(
				'jwt_auth_no_token',
				__( 'No se proporcionó un token de autenticación', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		}

		$token = $matches[1];

		// Validar el token
		try {
			$jwt_manager = new \MiIntegracionApi\Core\JWT_Manager();
			$decoded     = $jwt_manager->validate_token( $token );

			if ( ! $decoded ) {
				return new \WP_Error(
					'jwt_auth_invalid_token',
					__( 'Token de autenticación inválido', 'mi-integracion-api' ),
					array( 'status' => 401 )
				);
			}

			// Verificar si el token está revocado
			if ( $jwt_manager->is_token_revoked( $token ) ) {
				return new \WP_Error(
					'jwt_auth_revoked_token',
					__( 'Token de autenticación revocado', 'mi-integracion-api' ),
					array( 'status' => 401 )
				);
			}

			// Obtener el usuario asociado al token
			$user_id = $decoded->data->user_id;
			$user    = get_user_by( 'id', $user_id );

			if ( ! $user ) {
				return new \WP_Error(
					'jwt_auth_user_not_found',
					__( 'El usuario asociado al token no existe', 'mi-integracion-api' ),
					array( 'status' => 401 )
				);
			}

			// Almacenar el usuario y los datos del token para usarlos posteriormente
			$request->set_param( 'jwt_user', $user );
			$request->set_param( 'jwt_decoded', $decoded );

			// JWT válido
			return true;

		} catch ( ExpiredException $e ) {
			return new \WP_Error(
				'jwt_auth_token_expired',
				__( 'Token de autenticación expirado', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		} catch ( SignatureInvalidException $e ) {
			return new \WP_Error(
				'jwt_auth_invalid_signature',
				__( 'Firma del token inválida', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		} catch ( \Exception $e ) {
			return new \WP_Error(
				'jwt_auth_unknown_error',
				__( 'Error de autenticación: ', 'mi-integracion-api' ) . $e->getMessage(),
				array( 'status' => 401 )
			);
		}
	}

	/**
	 * Valida que el usuario tenga un rol o capacidad específica
	 *
	 * @param \WP_User     $user Usuario a validar
	 * @param string|array $roles Roles o capacidades a verificar
	 * @return bool True si el usuario tiene al menos un rol o capacidad
	 */
	public static function has_role_or_capability( $user, $roles_or_caps ) {
		if ( ! $user || ! ( $user instanceof \WP_User ) ) {
			return false;
		}

		if ( is_string( $roles_or_caps ) ) {
			$roles_or_caps = array( $roles_or_caps );
		}

		foreach ( $roles_or_caps as $role_or_cap ) {
			if ( $user->has_cap( $role_or_cap ) || in_array( $role_or_cap, $user->roles ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Obtiene una función de callback para verificar JWT en endpoints REST
	 *
	 * @param array $required_capabilities Capacidades requeridas (opcional)
	 * @return callable Función de verificación
	 */
	public static function get_jwt_auth_callback( $required_capabilities = array() ) {
		return function ( $request ) use ( $required_capabilities ) {
			// Verificar JWT
			$jwt_result = self::verify_jwt_auth( $request );

			if ( is_wp_error( $jwt_result ) ) {
				return $jwt_result;
			}

			// Si se requieren capacidades específicas, verificarlas
			if ( ! empty( $required_capabilities ) ) {
				$user = $request->get_param( 'jwt_user' );

				if ( ! self::has_role_or_capability( $user, $required_capabilities ) ) {
					return new \WP_Error(
						'jwt_auth_insufficient_permissions',
						__( 'El usuario no tiene los permisos necesarios', 'mi-integracion-api' ),
						array( 'status' => 403 )
					);
				}
			}

			return true;
		};
	}
}
