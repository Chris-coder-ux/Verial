<?php
/**
 * Funciones de formateo y transformación de datos para la integración WooCommerce-Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// NOTA: Preferencia de desarrollo
// Si hace falta crear un archivo nuevo para helpers de formateo, etc., se debe crear, nunca mezclar código en archivos que no corresponden. Esto asegura mantenibilidad profesional.

class Formatting {
	/**
	 * Convierte un array en una cadena separada por comas (para la API).
	 *
	 * @param array<int, string|int|float> $array
	 * @return string
	 */
	public static function array_to_csv( array $array ): string {
		return implode( ',', $array );
	}

	/**
	 * Formatea un mensaje de error estándar para la API.
	 *
	 * @param int    $code
	 * @param string $description
	 * @return array<string, array<string, int|string>>
	 */
	public static function api_error( int $code, string $description ): array {
		return array(
			'InfoError' => array(
				'Codigo'      => $code,
				'Descripcion' => $description,
			),
		);
	}

	/**
	 * Devuelve un resumen legible de un array para logs/debug.
	 *
	 * @param array<mixed, mixed> $data
	 * @param int                 $max_length
	 * @return string
	 */
	public static function array_summary( array $data, int $max_length = 255 ): string {
		$str = json_encode( $data );
		if ( ! is_string( $str ) ) {
			$str = '';
		}
		return strlen( $str ) > $max_length ? substr( $str, 0, $max_length ) . '...' : $str;
	}
}
