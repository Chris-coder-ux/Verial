<?php

namespace MiIntegracionApi\Traits;

/**
 * Trait para proporcionar métodos de gestión de errores unificados.
 * 
 * @package MiIntegracionApi\Traits
 * @since 1.0.0
 */
trait ErrorHandler {

	/**
	 * Crea un objeto WP_Error con los parámetros adecuados.
	 * Esta función está anotada para evitar advertencias de PHPStan.
	 *
	 * @param string               $code Código de error
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_wp_error( string $code, string $message, array $data = array() ): \WP_Error {
		// @phpstan-ignore-next-line
		return new \WP_Error( $code, $message, $data );
	}

	/**
	 * Crea un error de respuesta REST.
	 *
	 * @param string               $code Código de error
	 * @param string               $message Mensaje de error
	 * @param int                  $status_code Código de estado HTTP
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_rest_error( string $code, string $message, int $status_code = 400, array $additional_data = array() ): \WP_Error {
		$data = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( $code, $message, $data );
	}

	/**
	 * Crea un error de validación para parámetros REST.
	 *
	 * @param string $param_name Nombre del parámetro inválido
	 * @param string $message Mensaje de error
	 * @param mixed  $param_value Valor recibido del parámetro
	 * @return \WP_Error
	 */
	protected function create_validation_error( string $param_name, string $message, $param_value = null ): \WP_Error {
		$data = array(
			'status'     => 400,
			'param'      => $param_name,
			'value'      => $param_value,
		);
		return $this->create_wp_error( 'rest_invalid_param', $message, $data );
	}

	/**
	 * Crea un error de autenticación REST.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_auth_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 401;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_forbidden', $message, $data );
	}

	/**
	 * Crea un error de recurso no encontrado.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_not_found_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 404;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_not_found', $message, $data );
	}

	/**
	 * Crea un error de servidor interno.
	 *
	 * @param string               $message Mensaje de error
	 * @param array<string, mixed> $additional_data Datos adicionales para el error
	 * @return \WP_Error
	 */
	protected function create_server_error( string $message, array $additional_data = array() ): \WP_Error {
		$status_code = 500;
		$data        = array_merge( array( 'status' => $status_code ), $additional_data );
		return $this->create_wp_error( 'rest_server_error', $message, $data );
	}
}
