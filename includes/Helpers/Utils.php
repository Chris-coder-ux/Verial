<?php
/**
 * Funciones de utilidad y validación consolidadas
 *
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase con utilidades y funciones de validación
 * Esta clase consolida funcionalidad de Utils y Validation
 */
class Utils {
	/**
	 * Elimina etiquetas HTML y recorta espacios en blanco de una cadena.
	 *
	 * @param string $str Cadena a sanitizar
	 * @return string Cadena sanitizada
	 */
	public static function sanitize_string( $str ) {
		return trim( strip_tags( $str ) );
	}

	/**
	 * Sanitiza recursivamente todos los valores string de un array.
	 *
	 * @param array $arr Array a sanitizar
	 * @return array Array con valores sanitizados
	 */
	public static function sanitize_array_strings( array $arr ): array {
		foreach ( $arr as $key => $value ) {
			if ( is_array( $value ) ) {
				$arr[ $key ] = self::sanitize_array_strings( $value );
			} elseif ( is_string( $value ) ) {
				$arr[ $key ] = self::sanitize_string( $value );
			}
		}
		return $arr;
	}

	/**
	 * Valida si una cadena es un email válido.
	 *
	 * @param string $email Email a validar
	 * @return bool True si es válido
	 */
	public static function is_valid_email( $email ) {
		return self::is_email( $email );
	}

	/**
	 * Valida si un valor no está vacío.
	 *
	 * @param mixed $value El valor a comprobar.
	 * @return bool True si no está vacío, false en caso contrario.
	 */
	public static function not_empty( $value ): bool {
		return ! empty( $value );
	}

	/**
	 * Valida si un array contiene solo enteros.
	 *
	 * @param array $array El array a validar.
	 * @return bool True si todos los elementos son enteros, false en caso contrario.
	 */
	public static function array_of_int( $array ): bool {
		if ( ! is_array( $array ) ) {
			return false;
		}
		foreach ( $array as $item ) {
			if ( ! is_int( $item ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Valida si un string es un email válido.
	 *
	 * @param string $email El email a validar.
	 * @return bool True si es un email válido, false en caso contrario.
	 */
	public static function is_email( $email ): bool {
		if ( ! is_string( $email ) ) {
			return false;
		}
		return (bool) filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Valida si un array es asociativo.
	 * Un array es asociativo si sus claves no son una secuencia numérica de 0 a n-1.
	 *
	 * @param array $array El array a comprobar.
	 * @return bool True si es asociativo, false en caso contrario.
	 */
	public static function is_associative_array( array $array ): bool {
		return array_keys( $array ) !== range( 0, count( $array ) - 1 );
	}

	/**
	 * Alias de is_associative_array para mantener compatibilidad con la clase Validation.
	 *
	 * @param mixed $array El array a comprobar.
	 * @return bool True si es asociativo, false en caso contrario.
	 */
	public static function is_assoc( $array ): bool {
		if ( ! is_array( $array ) ) {
			return false;
		}
		return self::is_associative_array( $array );
	}

	/**
	 * Valida si un valor es una URL válida.
	 *
	 * @param string $url URL a validar.
	 * @return bool True si es una URL válida, false en caso contrario.
	 */
	public static function is_url( $url ): bool {
		if ( ! is_string( $url ) ) {
			return false;
		}
		return (bool) filter_var( $url, FILTER_VALIDATE_URL );
	}

	/**
	 * Valida si un string tiene la longitud especificada.
	 *
	 * @param string $string String a validar.
	 * @param int    $min Longitud mínima.
	 * @param int    $max Longitud máxima.
	 * @return bool True si cumple la longitud, false en caso contrario.
	 */
	public static function validate_length( $string, $min = 0, $max = PHP_INT_MAX ): bool {
		if ( ! is_string( $string ) ) {
			return false;
		}
		$length = mb_strlen( $string );
		return ( $length >= $min && $length <= $max );
	}

	/**
	 * Valida si un número está dentro del rango especificado.
	 *
	 * @param int|float $number Número a validar.
	 * @param int|float $min Mínimo permitido.
	 * @param int|float $max Máximo permitido.
	 * @return bool True si está en el rango, false en caso contrario.
	 */
	public static function validate_number_range( $number, $min = PHP_INT_MIN, $max = PHP_INT_MAX ): bool {
		if ( ! is_numeric( $number ) ) {
			return false;
		}
		return ( $number >= $min && $number <= $max );
	}

	/**
	 * Convierte una fecha al formato especificado.
	 *
	 * @param string $date Fecha a convertir.
	 * @param string $format Formato de salida.
	 * @return string Fecha formateada o vacío si es inválida.
	 */
	public static function format_date( $date, $format = 'Y-m-d' ): string {
		if ( empty( $date ) ) {
			return '';
		}

		$timestamp = strtotime( $date );
		if ( $timestamp === false ) {
			return '';
		}

		return date( $format, $timestamp );
	}

	/**
	 * Valida si una fecha es válida según el formato especificado.
	 *
	 * @param string $date Fecha a validar.
	 * @param string $format Formato esperado.
	 * @return bool True si es válida, false en caso contrario.
	 */
	public static function is_valid_date( $date, $format = 'Y-m-d' ): bool {
		if ( ! is_string( $date ) ) {
			return false;
		}

		$d = \DateTime::createFromFormat( $format, $date );
		return $d && $d->format( $format ) === $date;
	}

	/**
	 * Valida si una fecha está en formato YYYY-MM-DD y es válida.
	 *
	 * @param string $date_string Fecha a validar.
	 * @return bool True si es una fecha válida en formato YYYY-MM-DD.
	 */
	public static function is_valid_date_format( string $date_string ): bool {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_string ) ) {
			$parts = explode( '-', $date_string );
			// checkdate(month, day, year)
			return count($parts) === 3 && checkdate((int)$parts[1], (int)$parts[2], (int)$parts[0]);
		}
		return false;
	}

	/**
	 * Valida si una hora está en formato HH:MM o HH:MM:SS y es válida.
	 *
	 * @param string $time_string Hora a validar.
	 * @return bool True si es una hora válida.
	 */
	public static function is_valid_time_format( string $time_string ): bool {
		// Permite HH:MM o HH:MM:SS
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $time_string ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Valida una fecha opcional (vacía o válida)
	 *
	 * @param string $date_string Fecha a validar
	 * @return bool True si es vacía o válida
	 */
	public static function is_valid_date_format_optional( string $date_string ): bool {
		return $date_string === '' || self::is_valid_date_format($date_string);
	}

	/**
	 * Valida una hora opcional (vacía o válida)
	 *
	 * @param string $time_string Hora a validar
	 * @return bool True si es vacía o válida
	 */
	public static function is_valid_time_format_optional( string $time_string ): bool {
		return $time_string === '' || self::is_valid_time_format($time_string);
	}

	/**
	 * Genera un ID único para usar en caché, etc.
	 *
	 * @param mixed $data Datos para generar el hash.
	 * @return string Hash único.
	 */
	public static function generate_hash( $data ): string {
		if ( is_array( $data ) || is_object( $data ) ) {
			$data = json_encode( $data );
		}
		return md5( (string) $data );
	}

	/**
	 * Convierte un objeto a array de forma recursiva.
	 *
	 * @param mixed $obj Objeto a convertir.
	 * @return array Array resultante.
	 */
	public static function object_to_array( $obj ) {
		if ( is_object( $obj ) ) {
			$obj = (array) $obj;
		}

		if ( is_array( $obj ) ) {
			$new = array();
			foreach ( $obj as $key => $val ) {
				$new[ $key ] = self::object_to_array( $val );
			}
		} else {
			$new = $obj;
		}

		return $new;
	}

	/**
	 * Filtra un array para eliminar elementos vacíos.
	 *
	 * @param array $array Array a filtrar.
	 * @return array Array sin elementos vacíos.
	 */
	public static function filter_empty( array $array ): array {
		return array_filter(
			$array,
			function ( $value ) {
				return ! empty( $value ) || $value === 0 || $value === '0' || $value === false;
			}
		);
	}

	/**
	 * Convierte un string a snake_case.
	 *
	 * @param string $string String a convertir.
	 * @return string String en snake_case.
	 */
	public static function to_snake_case( $string ): string {
		if ( ! is_string( $string ) ) {
			return '';
		}

		$string = preg_replace( '/\s+/', '_', $string );
		$string = strtolower( $string );
		return $string;
	}

	/**
	 * Convierte un string a camelCase.
	 *
	 * @param string $string String a convertir.
	 * @param bool   $capitalizeFirst Si se debe capitalizar la primera letra.
	 * @return string String en camelCase.
	 */
	public static function to_camel_case( $string, $capitalizeFirst = false ): string {
		if ( ! is_string( $string ) ) {
			return '';
		}

		$string = str_replace( array( '-', '_' ), ' ', $string );
		$string = ucwords( $string );
		$string = str_replace( ' ', '', $string );

		if ( ! $capitalizeFirst ) {
			$string = lcfirst( $string );
		}

		return $string;
	}
}

/**
 * Valida y limpia los filtros según el tipo especificado.
 *
 * @param mixed  $raw_filters Los filtros a validar y limpiar.
 * @param string $type El tipo de filtro a aplicar.
 * @return array Array de filtros limpios.
 */
function mi_integracion_api_validate_filters( $raw_filters, string $type ): array {
	$clean_filters = array();
	if ( ! is_array( $raw_filters ) ) {
		// Loguear el error si la clase Logger está disponible
		if ( class_exists( 'MiIntegracionApi\helpers\Logger' ) ) {
			\MiIntegracionApi\helpers\Logger::error( 'Se esperaba un array para los filtros, se recibió: ' . gettype( $raw_filters ), 'mia-validation' );
		}
		return $clean_filters; // Devolver array vacío si la entrada no es un array
	}

	switch ( $type ) {
		case 'productos_wc': // Filtros para productos de WooCommerce
			if ( isset( $raw_filters['min_price'] ) ) {
				$clean_filters['min_price'] = floatval( $raw_filters['min_price'] );
			}
			if ( isset( $raw_filters['max_price'] ) ) {
				$clean_filters['max_price'] = floatval( $raw_filters['max_price'] );
			}
			if ( isset( $raw_filters['min_stock'] ) ) {
				$clean_filters['min_stock'] = intval( $raw_filters['min_stock'] );
			}
			if ( isset( $raw_filters['max_stock'] ) ) {
				$clean_filters['max_stock'] = intval( $raw_filters['max_stock'] );
			}
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			if ( isset( $raw_filters['category'] ) ) {
				// Puede ser un ID único o un array de IDs/slugs
				$clean_filters['category'] = is_array( $raw_filters['category'] )
					? array_map( 'sanitize_text_field', $raw_filters['category'] )
					: sanitize_text_field( $raw_filters['category'] );
			}
			if ( isset( $raw_filters['status'] ) ) {
				$clean_filters['status'] = is_array( $raw_filters['status'] )
					? array_map( 'sanitize_key', $raw_filters['status'] )
					: sanitize_key( $raw_filters['status'] );
			}
			break;

		case 'productos_verial': // Filtros para productos de Verial (usados por BatchProcessor)
			// Las claves aquí deben coincidir con las que envía el JS desde el formulario del panel de admin
			// y se mapearán a las claves que espera `filter_verial_products_locally` en BatchProcessor.
			if ( ! empty( $raw_filters['nombre_producto_verial'] ) ) {
				$clean_filters['Nombre'] = sanitize_text_field( wp_unslash( $raw_filters['nombre_producto_verial'] ) );
			}
			if ( isset( $raw_filters['id_categoria_verial'] ) && is_numeric( $raw_filters['id_categoria_verial'] ) ) {
				// La clave real en los datos de Verial podría ser 'ID_Categoria' o 'ID_CategoriaWeb1', etc.
				// BatchProcessor debería saber cómo usar esto. Aquí solo se sanea el valor.
				$clean_filters['id_categoria_verial_filter'] = absint( $raw_filters['id_categoria_verial'] );
			}
			if ( isset( $raw_filters['id_fabricante_verial'] ) && is_numeric( $raw_filters['id_fabricante_verial'] ) ) {
				$clean_filters['id_fabricante_verial_filter'] = absint( $raw_filters['id_fabricante_verial'] );
			}

			// Para precios, se asume que el JS envía 'precio_min_verial' y 'precio_max_verial'.
			// BatchProcessor usará estos para filtrar contra el campo de precio real de Verial.
			if ( isset( $raw_filters['precio_min_verial'] ) && is_numeric( $raw_filters['precio_min_verial'] ) ) {
				$clean_filters['precio_min_verial_filter'] = floatval( $raw_filters['precio_min_verial'] );
			}
			if ( isset( $raw_filters['precio_max_verial'] ) && is_numeric( $raw_filters['precio_max_verial'] ) ) {
				$clean_filters['precio_max_verial_filter'] = floatval( $raw_filters['precio_max_verial'] );
			}
			break;

		case 'clientes': // Filtros para clientes/usuarios de WordPress
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			if ( isset( $raw_filters['email'] ) ) {
				$clean_filters['email'] = sanitize_email( wp_unslash( $raw_filters['email'] ) ); // wp_unslash por si acaso
			}
			if ( isset( $raw_filters['registered_after'] ) ) {
				$clean_filters['registered_after'] = Utils::is_valid_date_format( $raw_filters['registered_after'] )
					? $raw_filters['registered_after']
					: '';
			}
			if ( isset( $raw_filters['registered_before'] ) ) {
				$clean_filters['registered_before'] = Utils::is_valid_date_format( $raw_filters['registered_before'] )
					? $raw_filters['registered_before']
					: '';
			}
			if ( isset( $raw_filters['role'] ) ) {
				$clean_filters['role'] = is_array( $raw_filters['role'] )
					? array_map( 'sanitize_key', $raw_filters['role'] )
					: sanitize_key( $raw_filters['role'] );
			}
			break;

		case 'pedidos': // Filtros para pedidos de WooCommerce
			if ( isset( $raw_filters['customer_id'] ) ) {
				$clean_filters['customer_id'] = absint( $raw_filters['customer_id'] );
			}
			if ( isset( $raw_filters['status'] ) ) {
				$clean_filters['status'] = is_array( $raw_filters['status'] )
					? array_map( 'sanitize_key', $raw_filters['status'] )
					: sanitize_key( $raw_filters['status'] );
			}
			if ( isset( $raw_filters['min_total'] ) ) {
				$clean_filters['min_total'] = floatval( $raw_filters['min_total'] );
			}
			if ( isset( $raw_filters['max_total'] ) ) {
				$clean_filters['max_total'] = floatval( $raw_filters['max_total'] );
			}
			if ( isset( $raw_filters['date_after'] ) ) {
				$clean_filters['date_after'] = Utils::is_valid_date_format( $raw_filters['date_after'] )
					? $raw_filters['date_after']
					: '';
			}
			if ( isset( $raw_filters['date_before'] ) ) {
				$clean_filters['date_before'] = Utils::is_valid_date_format( $raw_filters['date_before'] )
					? $raw_filters['date_before']
					: '';
			}
			if ( isset( $raw_filters['search'] ) ) {
				$clean_filters['search'] = sanitize_text_field( wp_unslash( $raw_filters['search'] ) );
			}
			break;

		default:
			// Loguear error si el tipo de filtro no es reconocido
			if ( class_exists( 'MiIntegracionApi\helpers\Logger' ) ) {
				\MiIntegracionApi\helpers\Logger::error( 'Tipo de filtro no reconocido en mi_integracion_api_validate_filters: ' . esc_html( $type ), 'mia-validation' );
			}
	}

	return $clean_filters;
}
