<?php
/**
 * Helper para manejo y formateo de errores de la API Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para estandarizar los errores de la API
 */
class ApiError {
	/**
	 * Devuelve un array de error estándar para la API Verial.
	 *
	 * @param int    $code
	 * @param string $description
	 * @return array
	 */
	public static function error( $code, $description ) {
		return array(
			'OK'      => false,
			'Error'   => array(
				'CodigoError' => $code,
				'MensajeError' => $description,
			),
		);
	}

	/**
	 * Formatea un error WP_Error para la respuesta de la API.
	 *
	 * @param \WP_Error $wp_error
	 * @return array
	 */
	public static function from_wp_error( $wp_error ) {
		$code    = $wp_error->get_error_code();
		$message = $wp_error->get_error_message();
		
		return self::error( $code, $message );
	}
}
