<?php
/**
 * Clase unificada para validación y sanitización de entradas
 *
 * Esta clase centraliza toda la lógica de validación y sanitización
 * de entradas del plugin, combinando las funcionalidades que antes
 * estaban dispersas en diferentes clases.
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase unificada para validación y sanitización de entradas
 */
class InputValidation {

	/**
	 * Códigos de error de validación
	 */
	const ERROR_EMPTY     = 'empty';
	const ERROR_INVALID   = 'invalid';
	const ERROR_TOO_LONG  = 'too_long';
	const ERROR_TOO_SHORT = 'too_short';
	const ERROR_NUMERIC   = 'not_numeric';
	const ERROR_EMAIL     = 'invalid_email';
	const ERROR_URL       = 'invalid_url';
	const ERROR_DATE      = 'invalid_date';
	const ERROR_JSON      = 'invalid_json';
	const ERROR_REQUIRED  = 'required';
	const ERROR_FORMAT    = 'format';
	const ERROR_RANGE     = 'out_of_range';

	/**
	 * Define tipos de contenido específicos para endpoints de la API
	 */
	const TIPO_SESION      = 'sesion';
	const TIPO_ID_CLIENTE  = 'id_cliente';
	const TIPO_ID_ARTICULO = 'id_articulo';
	const TIPO_REFERENCIA  = 'referencia';
	const TIPO_EMAIL       = 'email';
	const TIPO_TELEFONO    = 'telefono';
	const TIPO_DIRECCION   = 'direccion';
	const TIPO_CP          = 'cp';
	const TIPO_NIF         = 'nif';
	const TIPO_FECHA       = 'fecha';
	const TIPO_PRECIO      = 'precio';
	const TIPO_STOCK       = 'stock';
	const TIPO_TEXTO_LARGO = 'texto_largo';

	/**
	 * Define longitudes máximas permitidas para diversos tipos de datos
	 */
	const MAX_LENGTH_NIF          = 20;
	const MAX_LENGTH_RAZON_SOCIAL = 50;
	const MAX_LENGTH_DIRECCION    = 75;
	const MAX_LENGTH_CP           = 10;
	const MAX_LENGTH_TELEFONO     = 20;
	const MAX_LENGTH_EMAIL        = 100;
	const MAX_LENGTH_TEXTO_LARGO  = 255;
	const MAX_LENGTH_REFERENCIA   = 40;

	/**
	 * Almacena los errores encontrados durante la validación
	 *
	 * @var array
	 */
	private static $errors = array();

	/**
	 * Sanitiza un valor según su tipo
	 *
	 * @param mixed  $value Valor a sanitizar
	 * @param string $type Tipo de sanitización
	 * @return mixed Valor sanitizado
	 */
	public static function sanitize( $value, $type = 'text' ) {
		switch ( $type ) {
			case 'email':
				return sanitize_email( $value );

			case 'url':
				return esc_url_raw( $value );

			case 'int':
			case 'integer':
				return intval( $value );

			case 'absint':
				return absint( $value );

			case 'float':
			case 'number':
				return floatval( $value );

			case 'boolean':
			case 'bool':
				return self::sanitize_boolean( $value );

			case 'html':
			case 'kses':
				return wp_kses_post( $value );

			case 'array':
				return self::sanitize_array( $value );

			case 'json':
				return self::sanitize_json( $value );

			case 'date':
				return self::sanitize_date( $value );

			case 'textarea':
				return sanitize_textarea_field( $value );

			case 'file_name':
				return sanitize_file_name( $value );

			case 'key':
			case 'option':
				return sanitize_key( $value );

			case 'slug':
				return sanitize_title( $value );

			// Tipos específicos de API
			case self::TIPO_NIF:
				return self::sanitize_nif( $value );

			case self::TIPO_TELEFONO:
				return self::sanitize_telefono( $value );

			case self::TIPO_DIRECCION:
				return self::sanitize_direccion( $value );

			case self::TIPO_CP:
				return self::sanitize_cp( $value );

			case self::TIPO_REFERENCIA:
				return self::sanitize_referencia( $value );

			case 'text':
			default:
				return sanitize_text_field( $value );
		}
	}

	/**
	 * Sanitiza un array recursivamente
	 *
	 * @param mixed  $array Array a sanitizar
	 * @param string $default_type Tipo de sanitización por defecto para los valores
	 * @return array Array sanitizado
	 */
	public static function sanitize_array( $array, $default_type = 'text' ) {
		if ( ! is_array( $array ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $array as $key => $value ) {
			if ( is_array( $value ) ) {
				$sanitized[ sanitize_key( $key ) ] = self::sanitize_array( $value, $default_type );
			} else {
				$sanitized[ sanitize_key( $key ) ] = self::sanitize( $value, $default_type );
			}
		}

		return $sanitized;
	}

	/**
	 * Sanitiza un JSON
	 *
	 * @param mixed $json JSON a sanitizar
	 * @return string JSON sanitizado
	 */
	public static function sanitize_json( $json ) {
		if ( is_array( $json ) || is_object( $json ) ) {
			return wp_json_encode( $json );
		}

		if ( ! is_string( $json ) ) {
			return '';
		}

		$decoded = json_decode( $json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return '';
		}

		if ( is_array( $decoded ) ) {
			$sanitized = self::sanitize_array( $decoded );
			return wp_json_encode( $sanitized );
		}

		return '';
	}

	/**
	 * Sanitiza un valor booleano
	 *
	 * @param mixed $value Valor a sanitizar
	 * @return bool Valor booleano
	 */
	public static function sanitize_boolean( $value ) {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) ) {
			$value = strtolower( trim( $value ) );
			return in_array( $value, array( 'true', '1', 'yes', 'si', 'on' ), true );
		}

		return (bool) $value;
	}

	/**
	 * Sanitiza una fecha
	 *
	 * @param mixed  $date Fecha a sanitizar
	 * @param string $format Formato de la fecha (default: Y-m-d)
	 * @return string Fecha sanitizada
	 */
	public static function sanitize_date( $date, $format = 'Y-m-d' ) {
		if ( empty( $date ) ) {
			return '';
		}

		// Si es un objeto DateTime
		if ( $date instanceof \DateTime ) {
			return $date->format( $format );
		}

		// Si es un timestamp
		if ( is_numeric( $date ) ) {
			$datetime = new \DateTime();
			$datetime->setTimestamp( (int) $date );
			return $datetime->format( $format );
		}

		// Si es una cadena
		if ( is_string( $date ) ) {
			$timestamp = strtotime( $date );
			if ( $timestamp !== false ) {
				$datetime = new \DateTime();
				$datetime->setTimestamp( $timestamp );
				return $datetime->format( $format );
			}
		}

		return '';
	}

	/**
	 * Sanitiza un NIF
	 *
	 * @param string $nif NIF a sanitizar
	 * @return string NIF sanitizado
	 */
	public static function sanitize_nif( $nif ) {
		if ( ! is_string( $nif ) ) {
			return '';
		}

		// Eliminar espacios y convertir a mayúsculas
		$nif = strtoupper( trim( $nif ) );

		// Eliminar caracteres no alfanuméricos
		$nif = preg_replace( '/[^A-Z0-9]/', '', $nif );

		// Limitar longitud
		if ( strlen( $nif ) > self::MAX_LENGTH_NIF ) {
			$nif = substr( $nif, 0, self::MAX_LENGTH_NIF );
		}

		return $nif;
	}

	/**
	 * Sanitiza un teléfono
	 *
	 * @param string $telefono Teléfono a sanitizar
	 * @return string Teléfono sanitizado
	 */
	public static function sanitize_telefono( $telefono ) {
		if ( ! is_string( $telefono ) && ! is_numeric( $telefono ) ) {
			return '';
		}

		$telefono = trim( (string) $telefono );

		// Eliminar caracteres no numéricos ni '+'
		$telefono = preg_replace( '/[^0-9+]/', '', $telefono );

		// Limitar longitud
		if ( strlen( $telefono ) > self::MAX_LENGTH_TELEFONO ) {
			$telefono = substr( $telefono, 0, self::MAX_LENGTH_TELEFONO );
		}

		return $telefono;
	}

	/**
	 * Sanitiza una dirección
	 *
	 * @param string $direccion Dirección a sanitizar
	 * @return string Dirección sanitizada
	 */
	public static function sanitize_direccion( $direccion ) {
		if ( ! is_string( $direccion ) ) {
			return '';
		}

		$direccion = trim( $direccion );

		// Sanitizar como texto normal
		$direccion = sanitize_text_field( $direccion );

		// Limitar longitud
		if ( strlen( $direccion ) > self::MAX_LENGTH_DIRECCION ) {
			$direccion = substr( $direccion, 0, self::MAX_LENGTH_DIRECCION );
		}

		return $direccion;
	}

	/**
	 * Sanitiza un código postal
	 *
	 * @param string $cp Código postal a sanitizar
	 * @return string Código postal sanitizado
	 */
	public static function sanitize_cp( $cp ) {
		if ( ! is_string( $cp ) && ! is_numeric( $cp ) ) {
			return '';
		}

		$cp = trim( (string) $cp );

		// Eliminar espacios y caracteres no alfanuméricos
		$cp = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $cp ) );

		// Limitar longitud
		if ( strlen( $cp ) > self::MAX_LENGTH_CP ) {
			$cp = substr( $cp, 0, self::MAX_LENGTH_CP );
		}

		return $cp;
	}

	/**
	 * Sanitiza una referencia
	 *
	 * @param string $referencia Referencia a sanitizar
	 * @return string Referencia sanitizada
	 */
	public static function sanitize_referencia( $referencia ) {
		if ( ! is_string( $referencia ) ) {
			return '';
		}

		$referencia = trim( $referencia );

		// Sanitizar como texto normal
		$referencia = sanitize_text_field( $referencia );

		// Limitar longitud
		if ( strlen( $referencia ) > self::MAX_LENGTH_REFERENCIA ) {
			$referencia = substr( $referencia, 0, self::MAX_LENGTH_REFERENCIA );
		}

		return $referencia;
	}

	/**
	 * Valida un valor según su tipo y reglas
	 *
	 * @param mixed  $value Valor a validar
	 * @param string $type Tipo de validación
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido, False si no lo es
	 */
	public static function validate( $value, $type = 'text', $rules = array() ) {
		// Limpiar errores anteriores para este campo
		self::$errors = array();

		// Validación según el tipo
		switch ( $type ) {
			case 'email':
				return self::validate_email( $value, $rules );

			case 'url':
				return self::validate_url( $value, $rules );

			case 'int':
			case 'integer':
				return self::validate_integer( $value, $rules );

			case 'float':
			case 'number':
				return self::validate_number( $value, $rules );

			case 'boolean':
			case 'bool':
				return self::validate_boolean( $value );

			case 'date':
				return self::validate_date( $value, $rules );

			case 'array':
				return self::validate_array( $value, $rules );

			case 'json':
				return self::validate_json( $value );

			// Tipos específicos de API
			case self::TIPO_SESION:
				return self::validate_sesion( $value );

			case self::TIPO_ID_CLIENTE:
			case self::TIPO_ID_ARTICULO:
				return self::validate_id( $value );

			case self::TIPO_NIF:
				return self::validate_nif( $value, $rules );

			case self::TIPO_TELEFONO:
				return self::validate_telefono( $value, $rules );

			case self::TIPO_DIRECCION:
				return self::validate_direccion( $value, $rules );

			case self::TIPO_CP:
				return self::validate_cp( $value, $rules );

			case self::TIPO_REFERENCIA:
				return self::validate_referencia( $value, $rules );

			case self::TIPO_PRECIO:
				return self::validate_precio( $value, $rules );

			case self::TIPO_STOCK:
				return self::validate_stock( $value, $rules );

			case 'text':
			default:
				return self::validate_text( $value, $rules );
		}
	}

	/**
	 * Valida un texto
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_text( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'Este campo es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar longitud mínima
		if ( isset( $rules['min_length'] ) && strlen( $value ) < $rules['min_length'] ) {
			self::add_error(
				self::ERROR_TOO_SHORT,
				sprintf(
					/* translators: %d: mínima longitud requerida */
					__( 'Este campo debe tener al menos %d caracteres.', 'mi-integracion-api' ),
					$rules['min_length']
				)
			);
			return false;
		}

		// Validar longitud máxima
		if ( isset( $rules['max_length'] ) && strlen( $value ) > $rules['max_length'] ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'Este campo no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					$rules['max_length']
				)
			);
			return false;
		}

		// Validar patron regex
		if ( isset( $rules['pattern'] ) && ! preg_match( $rules['pattern'], $value ) ) {
			$message = isset( $rules['pattern_message'] )
				? $rules['pattern_message']
				: __( 'Este campo no tiene el formato correcto.', 'mi-integracion-api' );
			self::add_error( self::ERROR_FORMAT, $message );
			return false;
		}

		return true;
	}

	/**
	 * Valida un email
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_email( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El email es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar formato de email
		if ( ! is_email( $value ) ) {
			self::add_error( self::ERROR_EMAIL, __( 'El email no es válido.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_EMAIL ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'El email no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_EMAIL
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida una URL
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_url( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'La URL es obligatoria.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar formato de URL
		if ( false === filter_var( $value, FILTER_VALIDATE_URL ) ) {
			self::add_error( self::ERROR_URL, __( 'La URL no es válida.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Valida un entero
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_integer( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'Este campo es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			return true;
		}

		// Validar que sea un número entero
		if ( ! is_numeric( $value ) || intval( $value ) != $value ) {
			self::add_error( self::ERROR_NUMERIC, __( 'Este campo debe ser un número entero.', 'mi-integracion-api' ) );
			return false;
		}

		$value = intval( $value );

		// Validar mínimo
		if ( isset( $rules['min'] ) && $value < $rules['min'] ) {
			self::add_error(
				self::ERROR_RANGE,
				sprintf(
					/* translators: %d: valor mínimo permitido */
					__( 'Este campo debe ser mayor o igual a %d.', 'mi-integracion-api' ),
					$rules['min']
				)
			);
			return false;
		}

		// Validar máximo
		if ( isset( $rules['max'] ) && $value > $rules['max'] ) {
			self::add_error(
				self::ERROR_RANGE,
				sprintf(
					/* translators: %d: valor máximo permitido */
					__( 'Este campo debe ser menor o igual a %d.', 'mi-integracion-api' ),
					$rules['max']
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida un número (flotante)
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_number( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'Este campo es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			return true;
		}

		// Validar que sea un número
		if ( ! is_numeric( $value ) ) {
			self::add_error( self::ERROR_NUMERIC, __( 'Este campo debe ser un número.', 'mi-integracion-api' ) );
			return false;
		}

		$value = floatval( $value );

		// Validar mínimo
		if ( isset( $rules['min'] ) && $value < $rules['min'] ) {
			self::add_error(
				self::ERROR_RANGE,
				sprintf(
					/* translators: %f: valor mínimo permitido */
					__( 'Este campo debe ser mayor o igual a %f.', 'mi-integracion-api' ),
					$rules['min']
				)
			);
			return false;
		}

		// Validar máximo
		if ( isset( $rules['max'] ) && $value > $rules['max'] ) {
			self::add_error(
				self::ERROR_RANGE,
				sprintf(
					/* translators: %f: valor máximo permitido */
					__( 'Este campo debe ser menor o igual a %f.', 'mi-integracion-api' ),
					$rules['max']
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida un booleano
	 *
	 * @param mixed $value Valor a validar
	 * @return bool True si es válido
	 */
	public static function validate_boolean( $value ) {
		// Los booleanos siempre son válidos
		return true;
	}

	/**
	 * Valida una fecha
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_date( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'La fecha es obligatoria.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Si es un objeto DateTime, es válido
		if ( $value instanceof \DateTime ) {
			return true;
		}

		// Si es un timestamp, validarlo
		if ( is_numeric( $value ) ) {
			return true;
		}

		// Si es una cadena, validarla como fecha
		if ( is_string( $value ) ) {
			$timestamp = strtotime( $value );
			if ( $timestamp === false ) {
				self::add_error( self::ERROR_DATE, __( 'La fecha no es válida.', 'mi-integracion-api' ) );
				return false;
			}
			return true;
		}

		// Si no es ninguno de los tipos anteriores, no es válido
		self::add_error( self::ERROR_DATE, __( 'La fecha no es válida.', 'mi-integracion-api' ) );
		return false;
	}

	/**
	 * Valida un array
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_array( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'Este campo es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar que sea un array
		if ( ! is_array( $value ) ) {
			self::add_error( self::ERROR_INVALID, __( 'Este campo debe ser un array.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar mínimo de elementos
		if ( isset( $rules['min_items'] ) && count( $value ) < $rules['min_items'] ) {
			self::add_error(
				self::ERROR_TOO_SHORT,
				sprintf(
					/* translators: %d: mínimo de elementos requeridos */
					__( 'Este campo debe tener al menos %d elementos.', 'mi-integracion-api' ),
					$rules['min_items']
				)
			);
			return false;
		}

		// Validar máximo de elementos
		if ( isset( $rules['max_items'] ) && count( $value ) > $rules['max_items'] ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máximo de elementos permitidos */
					__( 'Este campo no puede tener más de %d elementos.', 'mi-integracion-api' ),
					$rules['max_items']
				)
			);
			return false;
		}

		// Validar elementos del array si se especifica un tipo
		if ( isset( $rules['items_type'] ) ) {
			$valid = true;
			foreach ( $value as $item ) {
				if ( ! self::validate( $item, $rules['items_type'], isset( $rules['items_rules'] ) ? $rules['items_rules'] : array() ) ) {
					$valid = false;
					// No break para validar todos los elementos y recoger todos los errores
				}
			}
			return $valid;
		}

		return true;
	}

	/**
	 * Valida un JSON
	 *
	 * @param mixed $value Valor a validar
	 * @return bool True si es válido
	 */
	public static function validate_json( $value ) {
		// Si ya es un array u objeto, es válido
		if ( is_array( $value ) || is_object( $value ) ) {
			return true;
		}

		// Si no es una cadena, no es válido
		if ( ! is_string( $value ) ) {
			self::add_error( self::ERROR_JSON, __( 'Este campo debe ser un JSON válido.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar que sea un JSON válido
		json_decode( $value );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			self::add_error( self::ERROR_JSON, __( 'Este campo debe ser un JSON válido.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Valida una sesión
	 *
	 * @param mixed $value Valor a validar
	 * @return bool True si es válido
	 */
	public static function validate_sesion( $value ) {
		// Validar que no esté vacío
		if ( empty( $value ) && $value !== '0' && $value !== 0 ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El número de sesión es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar que sea un número
		if ( ! is_numeric( $value ) ) {
			self::add_error( self::ERROR_NUMERIC, __( 'El número de sesión debe ser un número.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Valida un ID
	 *
	 * @param mixed $value Valor a validar
	 * @return bool True si es válido
	 */
	public static function validate_id( $value ) {
		// Si es 0 o está vacío, es válido (puede ser un nuevo registro)
		if ( empty( $value ) ) {
			return true;
		}

		// Validar que sea un número positivo
		if ( ! is_numeric( $value ) || intval( $value ) <= 0 ) {
			self::add_error( self::ERROR_INVALID, __( 'El ID debe ser un número positivo.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Valida un NIF
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_nif( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El NIF/CIF es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_NIF ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'El NIF/CIF no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_NIF
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida un teléfono
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_telefono( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El teléfono es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar que solo contenga números y '+'
		if ( ! preg_match( '/^[0-9+]+$/', $value ) ) {
			self::add_error( self::ERROR_INVALID, __( 'El teléfono solo puede contener números y el símbolo +.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_TELEFONO ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'El teléfono no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_TELEFONO
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida una dirección
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_direccion( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'La dirección es obligatoria.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_DIRECCION ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'La dirección no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_DIRECCION
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida un código postal
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_cp( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El código postal es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_CP ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'El código postal no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_CP
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida una referencia
	 *
	 * @param string $value Valor a validar
	 * @param array  $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_referencia( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && empty( $value ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'La referencia es obligatoria.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && empty( $value ) ) {
			return true;
		}

		// Validar longitud máxima
		if ( strlen( $value ) > self::MAX_LENGTH_REFERENCIA ) {
			self::add_error(
				self::ERROR_TOO_LONG,
				sprintf(
					/* translators: %d: máxima longitud permitida */
					__( 'La referencia no puede tener más de %d caracteres.', 'mi-integracion-api' ),
					self::MAX_LENGTH_REFERENCIA
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Valida un precio
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_precio( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El precio es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			return true;
		}

		// Validar que sea un número
		if ( ! is_numeric( $value ) ) {
			self::add_error( self::ERROR_NUMERIC, __( 'El precio debe ser un número.', 'mi-integracion-api' ) );
			return false;
		}

		// Validar que sea positivo o cero
		if ( floatval( $value ) < 0 ) {
			self::add_error( self::ERROR_INVALID, __( 'El precio no puede ser negativo.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Valida un stock
	 *
	 * @param mixed $value Valor a validar
	 * @param array $rules Reglas adicionales
	 * @return bool True si es válido
	 */
	public static function validate_stock( $value, $rules = array() ) {
		// Validar que no esté vacío si es requerido
		if ( ! empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			self::add_error( self::ERROR_REQUIRED, __( 'El stock es obligatorio.', 'mi-integracion-api' ) );
			return false;
		}

		// Si no es requerido y está vacío, es válido
		if ( empty( $rules['required'] ) && ( $value === null || $value === '' ) ) {
			return true;
		}

		// Validar que sea un número
		if ( ! is_numeric( $value ) ) {
			self::add_error( self::ERROR_NUMERIC, __( 'El stock debe ser un número.', 'mi-integracion-api' ) );
			return false;
		}

		return true;
	}

	/**
	 * Añade un error a la lista de errores
	 *
	 * @param string $code Código del error
	 * @param string $message Mensaje del error
	 */
	private static function add_error( $code, $message ) {
		self::$errors[] = array(
			'code'    => $code,
			'message' => $message,
		);
	}

	/**
	 * Obtiene los errores de validación
	 *
	 * @return array Array de errores
	 */
	public static function get_errors() {
		return self::$errors;
	}

	/**
	 * Obtiene el primer error de validación
	 *
	 * @return array|null Primer error o null si no hay errores
	 */
	public static function get_first_error() {
		return ! empty( self::$errors ) ? self::$errors[0] : null;
	}

	/**
	 * Limpia los errores de validación
	 */
	public static function clear_errors() {
		self::$errors = array();
	}

	/**
	 * Función de ayuda para validar un conjunto de datos
	 *
	 * @param array $data Array de datos a validar
	 * @param array $rules Array de reglas de validación
	 * @return array Array con los resultados de validación
	 */
	public static function validate_data( $data, $rules ) {
		$result = array(
			'valid'     => true,
			'errors'    => array(),
			'sanitized' => array(),
		);

		foreach ( $rules as $field => $field_rules ) {
			$type  = isset( $field_rules['type'] ) ? $field_rules['type'] : 'text';
			$value = isset( $data[ $field ] ) ? $data[ $field ] : null;

			// Sanitizar el valor
			$sanitized                     = self::sanitize( $value, $type );
			$result['sanitized'][ $field ] = $sanitized;

			// Validar el valor
			$validation_rules = isset( $field_rules['rules'] ) ? $field_rules['rules'] : array();
			$is_valid         = self::validate( $sanitized, $type, $validation_rules );

			if ( ! $is_valid ) {
				$result['valid']            = false;
				$result['errors'][ $field ] = self::get_errors();
				self::clear_errors();
			}
		}

		return $result;
	}

	/**
	 * Obtiene un valor del array POST y lo sanitiza
	 *
	 * @param string $key Clave del valor
	 * @param string $type Tipo de sanitización
	 * @param mixed  $default Valor por defecto
	 * @return mixed Valor sanitizado
	 */
	public static function get_post_var( $key, $type = 'text', $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( isset( $_POST[ $key ] ) ) {
			$value = wp_unslash( $_POST[ $key ] );
			return self::sanitize( $value, $type );
		}
        // phpcs:enable

		return $default;
	}

	/**
	 * Obtiene un valor del array GET y lo sanitiza
	 *
	 * @param string $key Clave del valor
	 * @param string $type Tipo de sanitización
	 * @param mixed  $default Valor por defecto
	 * @return mixed Valor sanitizado
	 */
	public static function get_get_var( $key, $type = 'text', $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET[ $key ] ) ) {
			$value = wp_unslash( $_GET[ $key ] );
			return self::sanitize( $value, $type );
		}
        // phpcs:enable

		return $default;
	}

	/**
	 * Obtiene un valor del array REQUEST y lo sanitiza
	 *
	 * @param string $key Clave del valor
	 * @param string $type Tipo de sanitización
	 * @param mixed  $default Valor por defecto
	 * @return mixed Valor sanitizado
	 */
	public static function get_request_var( $key, $type = 'text', $default = '' ) {
        // phpcs:disable WordPress.Security.NonceVerification
		if ( isset( $_REQUEST[ $key ] ) ) {
			$value = wp_unslash( $_REQUEST[ $key ] );
			return self::sanitize( $value, $type );
		}
        // phpcs:enable

		return $default;
	}
}
