<?php
/**
 * Clase para la gestión de nonces y seguridad AJAX
 *
 * @package MiIntegracionApi
 */

namespace MiIntegracionApi;



// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase de verificación de nonces y seguridad para llamadas AJAX
 */
class NonceManager {
	/**
	 * Prefijo para los nombres de nonce
	 *
	 * @var string
	 */
	private static $nonce_prefix = 'mi_integracion_api_';

	/**
	 * Genera un nonce para una acción específica
	 *
	 * @param string $action Nombre de la acción
	 * @return string Nonce generado
	 */
	public static function create_nonce( $action ) {
		return wp_create_nonce( self::$nonce_prefix . $action );
	}

	/**
	 * Verifica si un nonce es válido
	 *
	 * @param string $nonce Nonce a verificar
	 * @param string $action Nombre de la acción
	 * @return boolean True si el nonce es válido, false en caso contrario
	 */
	public static function verify_nonce( $nonce, $action ) {
		return wp_verify_nonce( $nonce, self::$nonce_prefix . $action ) !== false;
	}

	/**
	 * Verifica un nonce de una petición AJAX y termina la ejecución si no es válido
	 *
	 * @param string $action Nombre de la acción
	 * @param string $nonce_param Nombre del parámetro que contiene el nonce (por defecto 'nonce')
	 * @return void
	 */
	public static function verify_ajax_nonce( $action, $nonce_param = 'nonce' ) {
		// Verificar que se haya enviado un nonce
		if ( ! isset( $_REQUEST[ $nonce_param ] ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Error de seguridad: falta el token de verificación.', 'mi-integracion-api' ),
					'code'    => 'missing_nonce',
				),
				403
			);
			exit;
		}

		$nonce = sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_param ] ) );

		// Verificar que el nonce sea válido
		if ( ! self::verify_nonce( $nonce, $action ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Error de seguridad: token de verificación inválido o caducado. Por favor, recarga la página e intenta de nuevo.', 'mi-integracion-api' ),
					'code'    => 'invalid_nonce',
				),
				403
			);
			exit;
		}
	}

	/**
	 * Verifica que el usuario tenga permisos para ejecutar una acción AJAX
	 *
	 * @param string $capability Capacidad requerida (por defecto 'manage_options')
	 * @return void
	 */
	public static function verify_ajax_capability( $capability = 'manage_options' ) {
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ),
					'code'    => 'insufficient_permissions',
				),
				403
			);
			exit;
		}
	}

	/**
	 * Método de conveniencia que verifica tanto el nonce como los permisos del usuario
	 *
	 * @param string $action Nombre de la acción
	 * @param string $capability Capacidad requerida
	 * @param string $nonce_param Nombre del parámetro que contiene el nonce
	 * @return void
	 */
	public static function verify_ajax_request( $action, $capability = 'manage_options', $nonce_param = 'nonce' ) {
		self::verify_ajax_nonce( $action, $nonce_param );
		self::verify_ajax_capability( $capability );
	}

	/**
	 * Crea un campo de nonce para un formulario
	 *
	 * @param string  $action Nombre de la acción
	 * @param string  $name Nombre del campo (por defecto 'nonce')
	 * @param boolean $referer Si se debe incluir el referer (por defecto true)
	 * @return void Imprime el campo nonce
	 */
	public static function nonce_field( $action, $name = 'nonce', $referer = true ) {
		wp_nonce_field( self::$nonce_prefix . $action, $name, $referer );
	}

	/**
	 * Crea un campo de nonce para una URL
	 *
	 * @param string $action Nombre de la acción
	 * @param string $name Nombre del parámetro (por defecto 'nonce')
	 * @return string URL con el nonce añadido
	 */
	public static function nonce_url( $url, $action, $name = 'nonce' ) {
		return add_query_arg( $name, self::create_nonce( $action ), $url );
	}

	/**
	 * Genera los datos necesarios para localizar un script con seguridad AJAX
	 *
	 * @param string $action Nombre de la acción
	 * @param array  $extra_data Datos adicionales a incluir
	 * @return array Datos para wp_localize_script
	 */
	public static function get_ajax_security_data( $action, $extra_data = array() ) {
		return array_merge(
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => self::create_nonce( $action ),
				'action'  => $action,
			),
			$extra_data
		);
	}
}
