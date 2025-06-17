<?php
namespace MiIntegracionApi\Traits;

trait Cacheable {

	/**
	 * Genera una clave única para la caché basada en la operación y parámetros.
	 *
	 * @param array<string, mixed> $params Parámetros que influyen en la respuesta.
	 * @return string Clave única para la caché.
	 */
	protected function generate_cache_key( array $params ): string {
		$params_string = '';
		if ( ! empty( $params ) ) {
			ksort( $params ); // Ordenar para consistencia
			$params_string = md5( wp_json_encode( $params ) );
		}

		/** @var class-string $class */
		$class         = static::class;
		$endpoint_name = defined( static::class . '::ENDPOINT_NAME' ) ? static::ENDPOINT_NAME : basename( str_replace( '\\', '/', $class ) );
		$prefix        = defined( static::class . '::CACHE_KEY_PREFIX' ) ? static::CACHE_KEY_PREFIX : 'mi_api_endpoint_';

		return $prefix . $endpoint_name . '_' . $params_string;
	}

	/**
	 * Obtiene datos almacenados en caché.
	 *
	 * @param string $key Clave de caché.
	 * @return mixed Datos en caché o false si no existen o han expirado.
	 */
	public function get_cached_data( string $key ) {
		$cached_data = get_transient( $key );

		if ( false !== $cached_data ) {
			// Registrar hit de caché si hay un logger disponible
			if ( isset( $this->logger ) ) {
				$this->logger->debug( 'Datos obtenidos de caché', array(
					'cache_key' => $key,
				) );
			}
			return $cached_data;
		}

		return false;
	}

	/**
	 * Almacena datos en caché.
	 *
	 * @param array<string, mixed> $params Parámetros para generar la clave de caché.
	 * @param mixed $data Datos a almacenar.
	 * @param int $expiration Tiempo de expiración en segundos.
	 * @return bool True si se guardaron los datos, false en caso contrario.
	 */
	protected function cache_data( array $params, $data, int $expiration = 3600 ): bool {
		$cache_key = $this->generate_cache_key( $params );
		$result    = set_transient( $cache_key, $data, $expiration );

		// Registrar resultado si hay un logger disponible
		if ( isset( $this->logger ) ) {
			$this->logger->debug( 'Datos almacenados en caché', array(
				'cache_key'   => $cache_key,
				'expiration'  => $expiration,
				'success'     => $result,
			) );
		}

		return $result;
	}

	/**
	 * Elimina datos de la caché.
	 *
	 * @param array<string, mixed> $params Parámetros para generar la clave de caché.
	 * @return bool True si se eliminaron los datos, false en caso contrario.
	 */
	protected function clear_cached_data( array $params ): bool {
		$cache_key = $this->generate_cache_key( $params );
		$result    = delete_transient( $cache_key );

		// Registrar resultado si hay un logger disponible
		if ( isset( $this->logger ) ) {
			$this->logger->debug( 'Datos eliminados de caché', array(
				'cache_key' => $cache_key,
				'success'   => $result,
			) );
		}

		return $result;
	}
}
