<?php
/**
 * Manejador de credenciales y autenticación con Verial ERP
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para gestionar la autenticación y credenciales con Verial ERP
 */
class Auth_Manager {
	/**
	 * Clave única para el almacenamiento de credenciales en la BD
	 */
	const OPTION_KEY = 'mi_integracion_api_verial_credentials';

	/**
	 * Almacena una instancia de la clase
	 *
	 * @var Auth_Manager
	 */
	private static $instance = null;

	/**
	 * Credenciales en caché
	 *
	 * @var array
	 */
	private $credentials = null;

	/**
	 * Constructor
	 */
	private function __construct() {
		// Privado para implementar singleton
	}

	/**
	 * Obtiene la instancia única de la clase
	 *
	 * @return Auth_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Guarda las credenciales de Verial
	 *
	 * @param array $credentials Arreglo con las credenciales
	 * @return bool Verdadero si se guardaron correctamente
	 */
	public function save_credentials( $credentials ) {
		// Validación de datos
		if ( ! is_array( $credentials ) ) {
			return false;
		}

		// Asegurar que existen los campos necesarios (solo URL y numero de sesión son obligatorios)
		$required_fields = array( 'api_url', 'session_id' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $credentials[ $field ] ) || empty( $credentials[ $field ] ) ) {
				return false;
			}
		}

		// Cifrar la contraseña utilizando la API de WordPress
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = $this->encrypt_password( $credentials['password'] );
		}

		// Guardar las credenciales
		$result = update_option( self::OPTION_KEY, $credentials, true );

		// Actualizar caché si se guardó correctamente
		if ( $result ) {
			$this->credentials = $credentials;
		}

		return $result;
	}

	/**
	 * Obtiene las credenciales de Verial
	 *
	 * @return array|false Arreglo con las credenciales o falso si no hay credenciales guardadas
	 */
	public function get_credentials() {
		// Usar caché si está disponible
		if ( $this->credentials !== null ) {
			return $this->credentials;
		}

		// Obtener las credenciales de la base de datos
		$credentials = get_option( self::OPTION_KEY, false );

		if ( ! $credentials ) {
			return false;
		}

		// Si hay contraseña cifrada, descifrarla
		if ( isset( $credentials['password'] ) ) {
			$credentials['password'] = $this->decrypt_password( $credentials['password'] );
		}

		// Guardar en caché
		$this->credentials = $credentials;

		return $credentials;
	}

	/**
	 * Verifica si hay credenciales guardadas
	 *
	 * @return bool Verdadero si hay credenciales guardadas
	 */
	public function has_credentials() {
		$credentials = $this->get_credentials();

		if ( ! $credentials ) {
			return false;
		}

		// Verificar que existan los campos necesarios
		$required_fields = array( 'api_url', 'username', 'password' );
		foreach ( $required_fields as $field ) {
			if ( ! isset( $credentials[ $field ] ) || empty( $credentials[ $field ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Prueba la conexión con Verial ERP
	 *
	 * @return array Resultados de la prueba con estado y mensaje
	 */
	public function test_connection() {
		if ( ! $this->has_credentials() ) {
			return array(
				'success' => false,
				'message' => __( 'No hay credenciales guardadas.', 'mi-integracion-api' ),
			);
		}

		$credentials = $this->get_credentials();

		// Intentar una llamada simple a la API (GetPaisesWS)
		$api_connector = function_exists( 'mi_integracion_api_get_connector' )
			? \MiIntegracionApi\Helpers\ApiHelpers::get_connector()
			: new \MiIntegracionApi\Core\ApiConnector();

		// Configurar credenciales temporales para la prueba
		$api_connector->set_credentials( $credentials );

		$response = $api_connector->get( 'GetPaisesWS' );

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => $response->get_error_message(),
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code !== 200 ) {
			return array(
				'success' => false,
				'message' => sprintf(
					__( 'Error de conexión. Código de estado: %s', 'mi-integracion-api' ),
					$status_code
				),
			);
		}

		$body = wp_remote_retrieve_body( $response );

		if ( empty( $body ) ) {
			return array(
				'success' => false,
				'message' => __( 'Respuesta vacía del servidor.', 'mi-integracion-api' ),
			);
		}

		// Intentar decodificar el JSON
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'message' => __( 'Respuesta incorrecta del servidor. No es un formato JSON válido.', 'mi-integracion-api' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'Conexión exitosa con Verial ERP.', 'mi-integracion-api' ),
			'data'    => $data,
		);
	}

	/**
	 * Cifra una contraseña de manera segura
	 *
	 * @param string $password Contraseña en texto plano
	 * @return string Contraseña cifrada
	 */
	private function encrypt_password( $password ) {
		// Usar el servicio de criptografía centralizado
		if ( function_exists( 'mi_integracion_api_get_crypto' ) ) {
			$crypto_service = \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
			if ( $crypto_service ) {
				return $crypto_service->encrypt( $password );
			}
		}

		// Método antiguo como fallback (por compatibilidad con versiones anteriores)
		$key = wp_salt( 'auth' );

		// Usar OpenSSL si está disponible (más seguro)
		if ( function_exists( 'openssl_encrypt' ) ) {
			$ivlen     = openssl_cipher_iv_length( $cipher = 'AES-256-CBC' );
			$iv        = openssl_random_pseudo_bytes( $ivlen );
			$encrypted = openssl_encrypt( $password, $cipher, $key, 0, $iv );
			if ( $encrypted !== false ) {
				return base64_encode( $iv . $encrypted );
			}
		}

		// Fallback a criptografía simple si OpenSSL no está disponible
		return base64_encode( $password );
	}

	/**
	 * Descifra una contraseña cifrada
	 *
	 * @param string $encrypted_password Contraseña cifrada
	 * @return string Contraseña en texto plano
	 */
	private function decrypt_password( $encrypted_password ) {
		// Usar el servicio de criptografía centralizado
		if ( function_exists( 'mi_integracion_api_get_crypto' ) ) {
			$crypto_service = \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
			if ( $crypto_service ) {
				$decrypted = $crypto_service->decrypt( $encrypted_password );
				if ( $decrypted !== false ) {
					return $decrypted;
				}
			}
		}

		// Método antiguo como fallback (por compatibilidad con versiones anteriores)
		$key = wp_salt( 'auth' );

		// Usar OpenSSL si está disponible
		if ( function_exists( 'openssl_decrypt' ) ) {
			$mix   = base64_decode( $encrypted_password );
			$ivlen = openssl_cipher_iv_length( $cipher = 'AES-256-CBC' );

			// Verificar que tengamos suficientes datos
			if ( strlen( $mix ) > $ivlen ) {
				$iv        = substr( $mix, 0, $ivlen );
				$encrypted = substr( $mix, $ivlen );
				$decrypted = openssl_decrypt( $encrypted, $cipher, $key, 0, $iv );
				if ( $decrypted !== false ) {
					return $decrypted;
				}
			}
		}

		// Fallback a decodificación simple
		return base64_decode( $encrypted_password );
	}

	/**
	 * Elimina las credenciales guardadas
	 *
	 * @return bool Verdadero si se eliminaron correctamente
	 */
	public function delete_credentials() {
		$this->credentials = null;
		return delete_option( self::OPTION_KEY );
	}
}
