<?php
/**
 * Cargador de compatibilidad para la clase de validación unificada.
 *
 * Este archivo proporciona compatibilidad hacia atrás para código que
 * todavía utiliza las clases antiguas de validación y sanitización.
 * Todas las llamadas se redirigen a la nueva clase centralizada InputValidation.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Redirigir MI_Input_Validator a la nueva clase InputValidation
if ( ! class_exists( 'MI_Input_Validator' ) ) {
	class MI_Input_Validator {
		/**
		 * Sanitiza un valor según su tipo
		 *
		 * @param mixed  $value Valor a sanitizar
		 * @param string $type Tipo de sanitización
		 * @return mixed Valor sanitizado
		 */
		public static function sanitize( $value, $type = 'text' ) {
			return MiIntegracionApi\Core\InputValidation::sanitize( $value, $type );
		}

		/**
		 * Sanitiza un array
		 *
		 * @param array  $array Array a sanitizar
		 * @param string $default_type Tipo de sanitización por defecto
		 * @return array Array sanitizado
		 */
		public static function sanitize_array( $array, $default_type = 'text' ) {
			return MiIntegracionApi\Core\InputValidation::sanitize_array( $array, $default_type );
		}

		/**
		 * Sanitiza un JSON
		 *
		 * @param mixed $json JSON a sanitizar
		 * @return string JSON sanitizado
		 */
		public static function sanitize_json( $json ) {
			return MiIntegracionApi\Core\InputValidation::sanitize_json( $json );
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
			return MiIntegracionApi\Core\InputValidation::get_post_var( $key, $type, $default );
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
			return MiIntegracionApi\Core\InputValidation::get_get_var( $key, $type, $default );
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
			return MiIntegracionApi\Core\InputValidation::get_request_var( $key, $type, $default );
		}
	}
}

// Redirigir ApiInputValidator a la nueva clase InputValidation
if ( ! class_exists( 'MiIntegracionApi\\Core\\ApiInputValidator' ) ) {
	class ApiInputValidator {
		// Constantes redirigidas
		const TIPO_SESION      = InputValidation::TIPO_SESION;
		const TIPO_ID_CLIENTE  = InputValidation::TIPO_ID_CLIENTE;
		const TIPO_ID_ARTICULO = InputValidation::TIPO_ID_ARTICULO;
		const TIPO_REFERENCIA  = InputValidation::TIPO_REFERENCIA;
		const TIPO_EMAIL       = InputValidation::TIPO_EMAIL;
		const TIPO_TELEFONO    = InputValidation::TIPO_TELEFONO;
		const TIPO_DIRECCION   = InputValidation::TIPO_DIRECCION;
		const TIPO_CP          = InputValidation::TIPO_CP;
		const TIPO_NIF         = InputValidation::TIPO_NIF;
		const TIPO_FECHA       = InputValidation::TIPO_FECHA;
		const TIPO_PRECIO      = InputValidation::TIPO_PRECIO;
		const TIPO_STOCK       = InputValidation::TIPO_STOCK;
		const TIPO_TEXTO_LARGO = InputValidation::TIPO_TEXTO_LARGO;

		// Constantes de longitud máxima
		const MAX_LENGTH_NIF          = InputValidation::MAX_LENGTH_NIF;
		const MAX_LENGTH_RAZON_SOCIAL = InputValidation::MAX_LENGTH_RAZON_SOCIAL;
		const MAX_LENGTH_DIRECCION    = InputValidation::MAX_LENGTH_DIRECCION;
		const MAX_LENGTH_CP           = InputValidation::MAX_LENGTH_CP;
		const MAX_LENGTH_TELEFONO     = InputValidation::MAX_LENGTH_TELEFONO;
		const MAX_LENGTH_EMAIL        = InputValidation::MAX_LENGTH_EMAIL;
		const MAX_LENGTH_TEXTO_LARGO  = InputValidation::MAX_LENGTH_TEXTO_LARGO;
		const MAX_LENGTH_REFERENCIA   = InputValidation::MAX_LENGTH_REFERENCIA;
	}
}

// Añadir métodos de sanitización a MI_Security si es necesario
if ( class_exists( 'MI_Security' ) ) {
	if ( ! method_exists( 'MI_Security', 'sanitize_input' ) ) {
		function sanitize_input( $value, $type = 'text' ) {
			return MiIntegracionApi\Core\InputValidation::sanitize( $value, $type );
		}

		// Añadir el método a MI_Security
		add_filter(
			'mi_integracion_api_register_methods',
			function ( $methods ) {
				$methods['sanitize_input'] = function ( $value, $type = 'text' ) {
					return MiIntegracionApi\Core\InputValidation::sanitize( $value, $type );
				};
				return $methods;
			}
		);
	}
}
