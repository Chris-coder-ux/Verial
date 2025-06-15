<?php
/**
 * Clase para generar y manejar tokens JWT
 *
 * @package MiIntegracionApi
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar la autenticación JWT
 */
class JWT_Manager {
	/**
	 * Clave secreta para firmar los tokens
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Tiempo de expiración en segundos (por defecto 12 horas)
	 *
	 * @var int
	 */
	private $expiration_time = 43200; // 12 horas

	/**
	 * Nombre del emisor del token (iss)
	 *
	 * @var string
	 */
	private $issuer = 'mi-integracion-api';

	/**
	 * Nombre de la audiencia del token (aud)
	 *
	 * @var string
	 */
	private $audience = 'mi-integracion-api-client';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->initialize();
	}

	/**
	 * Inicializa la clase con la configuración necesaria
	 */
	private function initialize() {
		// Intentar obtener la clave secreta de constantes definidas
		if ( defined( 'MiIntegracionApi_JWT_SECRET' ) ) {
			$this->secret_key = MiIntegracionApi_JWT_SECRET;
		} else {
			// Generar o recuperar una clave secreta única para este sitio
			$this->secret_key = $this->get_or_generate_secret_key();
		}

		// Configuración personalizable mediante constantes
		if ( defined( 'MiIntegracionApi_JWT_EXPIRATION' ) ) {
			$this->expiration_time = (int) MiIntegracionApi_JWT_EXPIRATION;
		}

		if ( defined( 'MiIntegracionApi_JWT_ISSUER' ) ) {
			$this->issuer = MiIntegracionApi_JWT_ISSUER;
		}

		if ( defined( 'MiIntegracionApi_JWT_AUDIENCE' ) ) {
			$this->audience = MiIntegracionApi_JWT_AUDIENCE;
		}
	}

	/**
	 * Obtiene o genera una clave secreta única para el sitio
	 *
	 * @return string
	 */
	private function get_or_generate_secret_key() {
		$secret_key = get_option( 'mi_integracion_api_jwt_secret' );

		if ( ! $secret_key ) {
			// Generar una clave segura única
			if ( function_exists( 'random_bytes' ) ) {
				$secret_key = bin2hex( random_bytes( 32 ) );
			} elseif ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
				$secret_key = bin2hex( openssl_random_pseudo_bytes( 32 ) );
			} else {
				// Fallback menos seguro pero funcional
				$secret_key = md5( uniqid( mt_rand(), true ) ) . md5( uniqid( mt_rand(), true ) );
			}

			// Guardar la clave en opciones de WordPress
			update_option( 'mi_integracion_api_jwt_secret', $secret_key, false );
		}

		return $secret_key;
	}

	/**
	 * Genera un nuevo token JWT
	 *
	 * @param int   $user_id ID del usuario de WordPress
	 * @param array $extra_data Datos adicionales para incluir en el token
	 * @return string Token JWT generado
	 */
	public function generate_token( $user_id, $extra_data = array() ) {
		$issued_at  = time();
		$expiration = $issued_at + $this->expiration_time;

		// Datos del token
		$payload = array(
			'iss'  => $this->issuer,                      // Emisor
			'aud'  => $this->audience,                    // Audiencia
			'iat'  => $issued_at,                         // Tiempo de emisión
			'exp'  => $expiration,                        // Tiempo de expiración
			'uid'  => (int) $user_id,                     // ID de usuario
			'data' => array_merge(
				array(
					'user_id' => (int) $user_id,
				),
				$extra_data
			),
		);

		try {
			// Generar el token
			$token = JWT::encode( $payload, $this->secret_key, 'HS256' );
			return $token;
		} catch ( \Exception $e ) {
			Logger::error( sprintf( __( 'Error al generar token JWT: %s', 'mi-integracion-api' ), $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Valida un token JWT
	 *
	 * @param string $token Token JWT a validar
	 * @return object|false Payload decodificado o false si es inválido
	 */
	public function validate_token( $token ) {
		try {
			// Decodificar el token
			$decoded = JWT::decode( $token, new Key( $this->secret_key, 'HS256' ) );

			// Verificar la audiencia
			if ( $decoded->aud !== $this->audience ) {
				return false;
			}

			return $decoded;
		} catch ( \Exception $e ) {
			Logger::error( sprintf( __( 'Error al validar token JWT: %s', 'mi-integracion-api' ), $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Renueva un token JWT existente
	 *
	 * @param string $token Token JWT a renovar
	 * @return string|false Nuevo token o false si hay error
	 */
	public function renew_token( $token ) {
		try {
			// Decodificar el token actual
			$decoded = $this->validate_token( $token );

			if ( ! $decoded ) {
				return false;
			}

			// Generar un nuevo token con los mismos datos pero nueva fecha de expiración
			return $this->generate_token( $decoded->data->user_id, (array) $decoded->data );
		} catch ( \Exception $e ) {
			Logger::error( sprintf( __( 'Error al renovar token JWT: %s', 'mi-integracion-api' ), $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Revoca un token añadiéndolo a una lista negra
	 *
	 * @param string $token Token a revocar
	 * @return bool True si se revocó correctamente
	 */
	public function revoke_token( $token ) {
		try {
			$decoded = $this->validate_token( $token );

			if ( ! $decoded ) {
				return false;
			}

			// Obtener lista de tokens revocados
			$revoked_tokens = get_option( 'mi_integracion_api_revoked_tokens', array() );

			// Añadir a lista de tokens revocados con tiempo de expiración
			$revoked_tokens[ md5( $token ) ] = $decoded->exp;

			// Limpiar tokens expirados de la lista
			$this->clean_expired_tokens( $revoked_tokens );

			// Guardar la lista actualizada
			update_option( 'mi_integracion_api_revoked_tokens', $revoked_tokens );

			return true;
		} catch ( \Exception $e ) {
			Logger::error( sprintf( __( 'Error al revocar token JWT: %s', 'mi-integracion-api' ), $e->getMessage() ) );
			return false;
		}
	}

	/**
	 * Verifica si un token está revocado
	 *
	 * @param string $token Token a verificar
	 * @return bool True si está revocado
	 */
	public function is_token_revoked( $token ) {
		$revoked_tokens = get_option( 'mi_integracion_api_revoked_tokens', array() );
		return isset( $revoked_tokens[ md5( $token ) ] );
	}

	/**
	 * Limpia tokens expirados de la lista de revocados
	 *
	 * @param array $revoked_tokens Lista de tokens revocados
	 * @return array Lista limpia
	 */
	private function clean_expired_tokens( &$revoked_tokens ) {
		$current_time = time();

		foreach ( $revoked_tokens as $token_hash => $expiration ) {
			if ( $expiration < $current_time ) {
				unset( $revoked_tokens[ $token_hash ] );
			}
		}

		return $revoked_tokens;
	}
}
