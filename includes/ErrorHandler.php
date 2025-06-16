<?php
/**
 * Clase para manejo de errores - Compatibilidad
 *
 * @package MiIntegracionApi
 * @deprecated 2.0.0 Use MiIntegracionApi\Helpers\Logger instead
 */

namespace MiIntegracionApi;

use MiIntegracionApi\Helpers\Logger;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase ErrorHandler para compatibilidad
 * 
 * @deprecated 2.0.0 Use MiIntegracionApi\Helpers\Logger instead
 */
class ErrorHandler {
	/**
	 * Registra un error
	 *
	 * @param string $message Mensaje de error
	 * @param string $context Contexto del error
	 * @return void
	 */
	public static function log_error($message, $context = '') {
		trigger_error(
			__( 'ErrorHandler::log_error() está obsoleto. Use MiIntegracionApi\Helpers\Logger::error() en su lugar.', 'mi-integracion-api' ),
			E_USER_DEPRECATED
		);
		$logger = new Logger('deprecated');
		$logger->error($message, ['context' => $context]);
	}
	
	/**
	 * Registra información
	 *
	 * @param string $message Mensaje informativo
	 * @param string $context Contexto del mensaje
	 * @return void
	 */
	public static function log_info($message, $context = '') {
		trigger_error(
			__( 'ErrorHandler::log_info() está obsoleto. Use MiIntegracionApi\Helpers\Logger::info() en su lugar.', 'mi-integracion-api' ),
			E_USER_DEPRECATED
		);
		$logger = new Logger('deprecated');
		$logger->info($message, ['context' => $context]);
	}
}
