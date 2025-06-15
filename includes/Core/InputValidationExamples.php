<?php
/**
 * Ejemplo de uso de la clase InputValidation
 *
 * Este archivo muestra ejemplos de cómo utilizar la nueva clase unificada
 * de validación y sanitización de entradas.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Core\InputValidation;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ejemplos de uso de la clase InputValidation
 */
class InputValidationExamples {

	/**
	 * Ejecutar ejemplos de validación
	 *
	 * @return array Resultados de los ejemplos
	 */
	public static function run_examples() {
		$results = array();

		// Ejemplo 1: Validación y sanitización de un email
		$results['email'] = self::example_validate_email();

		// Ejemplo 2: Validación de un objeto completo (cliente)
		$results['cliente'] = self::example_validate_cliente();

		// Ejemplo 3: Validación de un pedido
		$results['pedido'] = self::example_validate_pedido();

		return $results;
	}

	/**
	 * Ejemplo de validación y sanitización de email
	 *
	 * @return array Resultado del ejemplo
	 */
	private static function example_validate_email() {
		// Valores a validar
		$emails = array(
			'correcto'   => 'usuario@ejemplo.com',
			'incorrecto' => 'esto-no-es-un-email',
			'largo'      => str_repeat( 'a', 90 ) . '@ejemplo.com',
			'vacio'      => '',
		);

		$results = array();

		foreach ( $emails as $key => $email ) {
			// Sanitizar
			$sanitized = InputValidation::sanitize( $email, 'email' );

			// Validar
			$is_valid = InputValidation::validate( $sanitized, 'email', array( 'required' => true ) );

			$results[ $key ] = array(
				'original'  => $email,
				'sanitized' => $sanitized,
				'valid'     => $is_valid,
				'errors'    => $is_valid ? array() : InputValidation::get_errors(),
			);

			// Limpiar errores para la próxima validación
			InputValidation::clear_errors();
		}

		return $results;
	}

	/**
	 * Ejemplo de validación de un cliente
	 *
	 * @return array Resultado del ejemplo
	 */
	private static function example_validate_cliente() {
		// Datos del cliente
		$cliente = array(
			'Nombre'    => 'Juan',
			'Apellido1' => 'Pérez',
			'Apellido2' => 'García',
			'NIF'       => '12345678Z',
			'Email'     => 'juan.perez@ejemplo.com',
			'Telefono'  => '912345678',
			'Direccion' => 'Calle Ejemplo 123',
			'CPostal'   => '28001',
			'Poblacion' => 'Madrid',
		);

		// Reglas de validación
		$rules = array(
			'Nombre'    => array(
				'type'  => 'text',
				'rules' => array(
					'required'   => true,
					'max_length' => 50,
				),
			),
			'Apellido1' => array(
				'type'  => 'text',
				'rules' => array(
					'required'   => true,
					'max_length' => 50,
				),
			),
			'Apellido2' => array(
				'type'  => 'text',
				'rules' => array(
					'required'   => false,
					'max_length' => 50,
				),
			),
			'NIF'       => array(
				'type'  => InputValidation::TIPO_NIF,
				'rules' => array(
					'required' => true,
				),
			),
			'Email'     => array(
				'type'  => 'email',
				'rules' => array(
					'required' => true,
				),
			),
			'Telefono'  => array(
				'type'  => InputValidation::TIPO_TELEFONO,
				'rules' => array(
					'required' => false,
				),
			),
			'Direccion' => array(
				'type'  => InputValidation::TIPO_DIRECCION,
				'rules' => array(
					'required' => true,
				),
			),
			'CPostal'   => array(
				'type'  => InputValidation::TIPO_CP,
				'rules' => array(
					'required' => true,
				),
			),
			'Poblacion' => array(
				'type'  => 'text',
				'rules' => array(
					'required'   => true,
					'max_length' => 50,
				),
			),
		);

		// Validar datos
		$result = InputValidation::validate_data( $cliente, $rules );

		return $result;
	}

	/**
	 * Ejemplo de validación de un pedido
	 *
	 * @return array Resultado del ejemplo
	 */
	private static function example_validate_pedido() {
		// Datos del pedido
		$pedido = array(
			'id_cliente' => 123,
			'total'      => 150.95,
			'lineas'     => array(
				array(
					'producto_id' => 456,
					'cantidad'    => 2,
					'precio'      => 49.95,
				),
				array(
					'producto_id' => 789,
					'cantidad'    => 1,
					'precio'      => 51.05,
				),
			),
			'fecha'      => '2023-05-30',
		);

		// Reglas de validación
		$rules = array(
			'id_cliente' => array(
				'type'  => InputValidation::TIPO_ID_CLIENTE,
				'rules' => array(
					'required' => true,
				),
			),
			'total'      => array(
				'type'  => InputValidation::TIPO_PRECIO,
				'rules' => array(
					'required' => true,
				),
			),
			'lineas'     => array(
				'type'  => 'array',
				'rules' => array(
					'required'  => true,
					'min_items' => 1,
				),
			),
			'fecha'      => array(
				'type'  => 'date',
				'rules' => array(
					'required' => true,
				),
			),
		);

		// Validar datos
		$result = InputValidation::validate_data( $pedido, $rules );

		return $result;
	}
}
