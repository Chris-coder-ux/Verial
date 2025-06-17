<?php
/**
 * Clase de compatibilidad con la antigua Validation
 * Redirige a la clase Utils consolidada
 *
 * @package MiIntegracionApi\Helpers
 * @deprecated Usar MiIntegracionApi\Helpers\Utils en su lugar
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase de compatibilidad que extiende a Utils.
 *
 * @deprecated Usar MiIntegracionApi\Helpers\Utils en su lugar
 */
class Validation extends Utils {
	/**
	 * Constructor que emite un aviso de deprecación
	 */
	public function __construct() {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			trigger_error( __( 'La clase Validation está obsoleta. Usar MiIntegracionApi\Helpers\Utils en su lugar.', 'mi-integracion-api' ), E_USER_DEPRECATED );
		}
	}

	/**
	 * Redirige llamadas a métodos no definidos a la clase padre (Utils)
	 * Proporciona una capa adicional de compatibilidad para métodos que puedan
	 * existir en código antiguo pero no estén definidos en esta clase.
	 *
	 * @param string $name Nombre del método
	 * @param array  $arguments Argumentos del método
	 * @return mixed Resultado del método de Utils
	 * @throws \BadMethodCallException Si el método no existe en Utils
	 */
	public static function __callStatic( $name, $arguments ) {
		if ( method_exists( Utils::class, $name ) ) {
			return call_user_func_array( array( Utils::class, $name ), $arguments );
		}
		throw new \BadMethodCallException( "El método $name no existe en Validation ni en Utils" );
	}
}
