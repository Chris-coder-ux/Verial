<?php
/**
 * Servicio centralizado de criptografía para Mi Integración API
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para manejar todas las operaciones criptográficas del plugin
 *
 * Provee métodos unificados para cifrar y descifrar datos sensibles
 * como credenciales, API keys y otros datos confidenciales.
 */
class CryptoService {
	/**
	 * Instancia única de la clase (patrón Singleton)
	 *
	 * @var CryptoService
	 */
	private static $instance = null;

	/**
	 * Algoritmo de cifrado
	 *
	 * @var string
	 */
	private $cipher = 'AES-256-CBC';

	/**
	 * Constructor privado para implementar Singleton
	 */
	private function __construct() {
		// Verificar que el cifrado seleccionado esté disponible
		if ( ! in_array( $this->cipher, openssl_get_cipher_methods(), true ) ) {
			$this->cipher = 'aes-256-cbc'; // Alternativa en minúsculas

			if ( ! in_array( $this->cipher, openssl_get_cipher_methods(), true ) ) {
				// Fallback a un método más común si el primero no está disponible
				$this->cipher = 'AES-128-CBC';

				if ( ! in_array( $this->cipher, openssl_get_cipher_methods(), true ) ) {
					error_log(
						// Translators: Error message logged when no secure cipher methods are available
						__( 'Mi Integración API: No hay métodos de cifrado seguros disponibles.', 'mi-integracion-api' )
					);
				}
			}
		}
	}

	/**
	 * Obtener la instancia única de la clase
	 *
	 * @return CryptoService
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Obtiene la clave de cifrado segura para operaciones criptográficas
	 *
	 * @return string Clave de cifrado
	 */
	private function get_encryption_key() {
		// Verificar si la constante está definida en wp-config.php
		if ( defined( 'VERIAL_ENCRYPTION_KEY' ) && ! empty( VERIAL_ENCRYPTION_KEY ) && VERIAL_ENCRYPTION_KEY !== 'clave-segura-defecto' ) {
			// Usar la clave definida en wp-config.php
			return VERIAL_ENCRYPTION_KEY;
		}

		// Conservar compatibilidad con versiones antiguas si no hay clave definida
		// Usar AUTH_KEY de WordPress como una alternativa aceptable
		if ( defined( 'AUTH_KEY' ) && ! empty( AUTH_KEY ) ) {
			// Derivar una clave de 32 bytes (256 bits) a partir de AUTH_KEY
			return substr( AUTH_KEY, 0, 32 );
		}

		// Si no hay ninguna clave disponible, usar una derivación de wp_salt
		// Esto es menos seguro pero mejor que un valor fijo
		return hash( 'sha256', wp_salt( 'auth' ), true );
	}

	/**
	 * Cifra un texto plano
	 *
	 * @param string      $plaintext El texto a cifrar
	 * @param string|null $custom_key Opcional: clave personalizada
	 * @return string Texto cifrado en base64
	 */
	public function encrypt( $plaintext, $custom_key = null ) {
		if ( empty( $plaintext ) ) {
			return '';
		}

		$key = $custom_key ?: $this->get_encryption_key();

		// Verificar si OpenSSL está disponible
		if ( function_exists( 'openssl_encrypt' ) ) {
			$ivlen = openssl_cipher_iv_length( $this->cipher );
			$iv    = openssl_random_pseudo_bytes( $ivlen );

			// Cifrar el texto
			$ciphertext_raw = openssl_encrypt( $plaintext, $this->cipher, $key, OPENSSL_RAW_DATA, $iv );

			// Añadir un HMAC para verificación de integridad
			$hmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );

			// Combinar IV + HMAC + texto cifrado y codificarlo en base64
			return base64_encode( $iv . $hmac . $ciphertext_raw );
		}

		// Fallback si OpenSSL no está disponible (menos seguro)
		return base64_encode( $plaintext );
	}

	/**
	 * Descifra un texto cifrado previamente con encrypt()
	 *
	 * @param string      $ciphertext El texto cifrado en base64
	 * @param string|null $custom_key Opcional: clave personalizada
	 * @return string|false El texto descifrado o false si hubo un error
	 */
	public function decrypt( $ciphertext, $custom_key = null ) {
		if ( empty( $ciphertext ) ) {
			return false;
		}

		$key = $custom_key ?: $this->get_encryption_key();

		// Verificar si OpenSSL está disponible
		if ( function_exists( 'openssl_decrypt' ) ) {
			$c     = base64_decode( $ciphertext );
			$ivlen = openssl_cipher_iv_length( $this->cipher );

			// Verificar que tenemos suficientes datos
			if ( strlen( $c ) <= $ivlen + 32 ) {
				return false;
			}

			// Extraer IV, HMAC y texto cifrado
			$iv             = substr( $c, 0, $ivlen );
			$hmac           = substr( $c, $ivlen, 32 );
			$ciphertext_raw = substr( $c, $ivlen + 32 );

			// Descifrar
			$original_plaintext = openssl_decrypt( $ciphertext_raw, $this->cipher, $key, OPENSSL_RAW_DATA, $iv );

			// Verificar integridad con HMAC
			$calcmac = hash_hmac( 'sha256', $ciphertext_raw, $key, true );

			if ( function_exists( 'hash_equals' ) ) {
				if ( hash_equals( $hmac, $calcmac ) ) {
					return $original_plaintext;
				}
			} else {
				// Comparación constante en tiempo para versiones antiguas de PHP
				$diff = strlen( $hmac ) ^ strlen( $calcmac );
				for ( $i = 0; $i < strlen( $hmac ) && $i < strlen( $calcmac ); $i++ ) {
					$diff |= ord( $hmac[ $i ] ) ^ ord( $calcmac[ $i ] );
				}

				if ( $diff === 0 ) {
					return $original_plaintext;
				}
			}

			return false; // Integridad comprometida
		}

		// Fallback si OpenSSL no está disponible
		return base64_decode( $ciphertext );
	}

	/**
	 * Genera una clave segura aleatoria
	 *
	 * @param int $length Longitud de la clave en bytes
	 * @return string Clave generada en formato hexadecimal
	 */
	public function generate_random_key( $length = 16 ) {
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( $length ) );
		} elseif ( function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return bin2hex( openssl_random_pseudo_bytes( $length ) );
		} else {
			// Fallback menos seguro si no hay métodos criptográficos disponibles
			return bin2hex( substr( hash( 'sha256', uniqid( mt_rand(), true ), true ), 0, $length ) );
		}
	}

	/**
	 * Cifra credenciales API completas
	 *
	 * @param array $credentials Array de credenciales
	 * @return array Credenciales cifradas
	 */
	public function encrypt_credentials( $credentials ) {
		if ( ! is_array( $credentials ) ) {
			return $credentials;
		}

		$encrypted = $credentials;

		// Cifrar solo los campos sensibles
		$sensitive_fields = array( 'password', 'api_key', 'api_secret', 'token', 'secret' );

		foreach ( $sensitive_fields as $field ) {
			if ( isset( $credentials[ $field ] ) && ! empty( $credentials[ $field ] ) ) {
				$encrypted[ $field ] = $this->encrypt( $credentials[ $field ] );
			}
		}

		return $encrypted;
	}

	/**
	 * Descifra credenciales API completas
	 *
	 * @param array $encrypted_credentials Array de credenciales cifradas
	 * @return array Credenciales descifradas
	 */
	public function decrypt_credentials( $encrypted_credentials ) {
		if ( ! is_array( $encrypted_credentials ) ) {
			return $encrypted_credentials;
		}

		$decrypted = $encrypted_credentials;

		// Descifrar solo los campos sensibles
		$sensitive_fields = array( 'password', 'api_key', 'api_secret', 'token', 'secret' );

		foreach ( $sensitive_fields as $field ) {
			if ( isset( $encrypted_credentials[ $field ] ) && ! empty( $encrypted_credentials[ $field ] ) ) {
				$decrypted[ $field ] = $this->decrypt( $encrypted_credentials[ $field ] );
			}
		}

		return $decrypted;
	}
}

// No se detecta uso de Logger::log, solo error_log estándar.
