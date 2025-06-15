<?php
/**
 * Gestor de caché HTTP basado en transients de WordPress, con registro de claves por grupo para limpieza eficiente.
 * Todos los métodos (get, set, delete, flush_group) usan el sistema de registro de claves por grupo.
 *
 * @package MiIntegracionApi\Cache
 * @since 1.0.0
 */

namespace MiIntegracionApi\Cache;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar la caché HTTP de las solicitudes a la API externa
 */
class HTTP_Cache_Manager {

	/**
	 * Prefijo para entradas de caché
	 */
	const CACHE_PREFIX = 'mi_api_http_cache_';

	/**
	 * Grupos de caché por tipo
	 */
	const CACHE_GROUPS = array(
		'product' => 'mi_api_products',
		'order'   => 'mi_api_orders',
		'config'  => 'mi_api_config',
		'global'  => 'mi_api_global',
	);

	/**
	 * Tiempo de vida predeterminado para la caché en segundos
	 * (4 horas por defecto)
	 */
	private static $default_ttl = 14400;

	/**
	 * Indicador de si la caché está habilitada
	 */
	private static $enabled = true;

	/**
	 * Estadísticas de caché para la solicitud actual
	 */
	private static $stats = array(
		'hit'     => 0,
		'miss'    => 0,
		'expired' => 0,
		'stored'  => 0,
	);

	/**
	 * Inicializa el administrador de caché HTTP
	 */
	public static function init() {
		// Comprobar si la caché está habilitada en la configuración
		self::$enabled = (bool) get_option( 'mi_integracion_api_enable_http_cache', true );

		// Cargar el tiempo de vida personalizado si está configurado
		$custom_ttl = get_option( 'mi_integracion_api_http_cache_ttl', false );
		if ( $custom_ttl !== false && is_numeric( $custom_ttl ) ) {
			self::$default_ttl = (int) $custom_ttl;
		}

		// Añadir hooks para limpiar caché en eventos relevantes
		add_action( 'mi_api_product_updated', array( __CLASS__, 'invalidate_product_cache' ) );
		add_action( 'mi_api_order_updated', array( __CLASS__, 'invalidate_order_cache' ) );
		add_action( 'mi_api_config_updated', array( __CLASS__, 'invalidate_config_cache' ) );

		// Añadir endpoint REST API para administrar la caché
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/**
	 * Registra rutas REST API para administrar la caché
	 */
	public static function register_rest_routes() {
		register_rest_route(
			'mi-integracion-api/v1',
			'/cache/flush',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'rest_flush_cache' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			'mi-integracion-api/v1',
			'/cache/stats',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_get_stats' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Endpoint REST para limpiar la caché
	 *
	 * @param \WP_REST_Request $request Objeto de solicitud REST
	 * @return \WP_REST_Response
	 */
	public static function rest_flush_cache( $request ) {
		$group = $request->get_param( 'group' );

		if ( $group && array_key_exists( $group, self::CACHE_GROUPS ) ) {
			$count = self::flush_group( $group );
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf( __( 'Se han eliminado %1$d entradas de caché del grupo %2$s', 'mi-integracion-api' ), $count, $group ),
					'count'   => $count,
				)
			);
		} else {
			$count = self::flush_all();
			return new \WP_REST_Response(
				array(
					'success' => true,
					'message' => sprintf( __( 'Se han eliminado %d entradas de caché', 'mi-integracion-api' ), $count ),
					'count'   => $count,
				)
			);
		}
	}

	/**
	 * Endpoint REST para obtener estadísticas de caché
	 *
	 * @return \WP_REST_Response
	 */
	public static function rest_get_stats() {
		$stats = self::get_extended_stats();
		return new \WP_REST_Response( $stats );
	}

	/**
	 * Guarda una respuesta HTTP en la caché
	 *
	 * @param string $url URL de la solicitud
	 * @param array  $args Argumentos de la solicitud
	 * @param mixed  $response Respuesta a guardar
	 * @param string $group Grupo de caché
	 * @param int    $ttl Tiempo de vida en segundos (opcional)
	 * @return bool
	 */
	public static function set( $url, $args, $response, $group = 'global', $ttl = null ) {
		if ( ! self::$enabled ) {
			return false;
		}

		// Generar una clave única basada en la URL y los argumentos
		$key = self::generate_cache_key( $url, $args );

		// Si no se especifica TTL, usar el predeterminado
		if ( $ttl === null ) {
			$ttl = self::$default_ttl;
		}

		// Preparar datos a guardar
		$cache_data = array(
			'response' => $response,
			'created'  => time(),
			'expires'  => time() + $ttl,
		);

		// Determinar el grupo de caché real
		$cache_group = isset( self::CACHE_GROUPS[ $group ] ) ? self::CACHE_GROUPS[ $group ] : self::CACHE_GROUPS['global'];

		// Almacenar en caché transient
		$result = set_transient( self::CACHE_PREFIX . $key, $cache_data, $ttl );

		// Registrar la clave en la lista de transients del grupo
		$option_key = self::CACHE_PREFIX . 'group_keys_' . $group;
		$group_keys = get_option( $option_key, array() );
		if ( ! in_array( self::CACHE_PREFIX . $key, $group_keys, true ) ) {
			$group_keys[] = self::CACHE_PREFIX . $key;
			update_option( $option_key, $group_keys, false );
		}

		if ( $result ) {
			++self::$stats['stored'];
		}

		return $result;
	}

	/**
	 * Obtiene un valor de caché HTTP usando transients y el sistema de grupos.
	 *
	 * @param string $key
	 * @param string $group
	 * @return mixed|false
	 */
	public static function get( $key, $group = 'global' ) {
		$transient_key = self::CACHE_PREFIX . $group . '_' . $key;
		return get_transient( $transient_key );
	}

	/**
	 * Genera una clave de caché única para una URL y argumentos
	 *
	 * @param string $url URL de la solicitud
	 * @param array  $args Argumentos de la solicitud
	 * @return string Clave de caché
	 */
	private static function generate_cache_key( $url, $args ) {
		// Extraer solo los argumentos relevantes para la firma de la caché
		$cache_args = array();
		if ( isset( $args['method'] ) ) {
			$cache_args['method'] = $args['method'];
		}
		if ( isset( $args['body'] ) ) {
			$cache_args['body'] = $args['body'];
		}
		if ( isset( $args['headers'] ) ) {
			// Filtrar headers sensibles que no deben afectar la caché
			$filtered_headers = array();
			foreach ( $args['headers'] as $header => $value ) {
				if ( ! in_array( strtolower( $header ), array( 'authorization', 'cookie' ) ) ) {
					$filtered_headers[ $header ] = $value;
				}
			}
			if ( ! empty( $filtered_headers ) ) {
				$cache_args['headers'] = $filtered_headers;
			}
		}

		// Crear firma
		return md5( $url . serialize( $cache_args ) );
	}

	/**
	 * Invalida la caché para un producto específico
	 *
	 * @param int $product_id ID del producto
	 */
	public static function invalidate_product_cache( $product_id ) {
		// Implementación de invalidación específica
		// Esta es una simplificación, se podría mejorar con un registro de claves por ID
		self::flush_group( 'product' );
	}

	/**
	 * Invalida la caché para un pedido específico
	 *
	 * @param int $order_id ID del pedido
	 */
	public static function invalidate_order_cache( $order_id ) {
		// Implementación de invalidación específica
		self::flush_group( 'order' );
	}

	/**
	 * Invalida la caché de configuración
	 */
	public static function invalidate_config_cache() {
		self::flush_group( 'config' );
	}

	/**
	 * Limpia todas las entradas de caché para un grupo específico usando el registro de claves.
	 *
	 * @param string $group Grupo de caché a limpiar
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_group( $group ) {
		$option_key = self::CACHE_PREFIX . 'group_keys_' . $group;
		$group_keys = get_option( $option_key, array() );
		$count      = 0;
		if ( ! empty( $group_keys ) && is_array( $group_keys ) ) {
			foreach ( $group_keys as $transient_key ) {
				if ( delete_transient( $transient_key ) ) {
					++$count;
				}
			}
		}
		// Limpiar el registro del grupo
		delete_option( $option_key );
		return $count;
	}

	/**
	 * Limpia todas las entradas de caché HTTP
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_all() {
		global $wpdb;

		$prefix = self::CACHE_PREFIX;
		$like   = $prefix . '%';

		// Obtener todas las claves de transient que coincidan con nuestro prefijo
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		$count = 0;
		if ( $transients ) {
			foreach ( $transients as $transient ) {
				$key = str_replace( '_transient_', '', $transient->option_name );
				if ( delete_transient( substr( $key, strlen( $prefix ) ) ) ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Obtiene estadísticas extendidas de la caché
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_extended_stats() {
		global $wpdb;

		$prefix = self::CACHE_PREFIX;
		$like   = $prefix . '%';

		// Contar entradas de caché existentes
		$total_entries = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		// Contar entradas por grupo
		$group_stats = array();
		foreach ( self::CACHE_GROUPS as $key => $group ) {
			$count               = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM $wpdb->options 
                    WHERE option_name LIKE %s 
                    AND option_name LIKE %s",
					'_transient_' . $like,
					'%' . $key . '%'
				)
			);
			$group_stats[ $key ] = (int) $count;
		}

		// Calcular tamaño aproximado
		$size_query = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(LENGTH(option_value)) as total_size 
                FROM $wpdb->options 
                WHERE option_name LIKE %s",
				'_transient_' . $like
			)
		);

		$total_size = $size_query ? $size_query->total_size : 0;

		// Formatear tamaño en unidades legibles
		$size_formatted = self::format_bytes( $total_size );

		return array(
			'enabled'         => self::$enabled,
			'ttl_default'     => self::$default_ttl,
			'current_request' => self::$stats,
			'total_entries'   => (int) $total_entries,
			'by_group'        => $group_stats,
			'size_bytes'      => (int) $total_size,
			'size_formatted'  => $size_formatted,
		);
	}

	/**
	 * Formatea bytes en unidades legibles
	 *
	 * @param int $bytes Tamaño en bytes
	 * @param int $precision Precisión decimal
	 * @return string Tamaño formateado
	 */
	private static function format_bytes( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Determina si la caché HTTP está habilitada
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return self::$enabled;
	}

	/**
	 * Activa o desactiva la caché HTTP
	 *
	 * @param bool $enabled Estado de activación
	 */
	public static function set_enabled( $enabled ) {
		$enabled       = (bool) $enabled;
		self::$enabled = $enabled;
		update_option( 'mi_integracion_api_enable_http_cache', $enabled );
	}

	/**
	 * Establece el tiempo de vida predeterminado para la caché
	 *
	 * @param int $ttl Tiempo de vida en segundos
	 */
	public static function set_default_ttl( $ttl ) {
		$ttl = absint( $ttl );
		if ( $ttl > 0 ) {
			self::$default_ttl = $ttl;
			update_option( 'mi_integracion_api_http_cache_ttl', $ttl );
		}
	}

	/**
	 * Alias para get_extended_stats() para mantener compatibilidad
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_cache_statistics() {
		return self::get_extended_stats();
	}

	/**
	 * Alias para get_extended_stats() para mantener compatibilidad
	 *
	 * @return array Estadísticas de caché
	 */
	public static function get_cache_stats() {
		return self::get_extended_stats();
	}

	/**
	 * Obtiene el tamaño formateado de la caché
	 *
	 * @return string Tamaño formateado
	 */
	public static function get_cache_size() {
		$stats = self::get_extended_stats();
		return $stats['size_formatted'];
	}

	/**
	 * Obtiene el número de entradas en caché para una entidad específica
	 *
	 * @param string $entity_key Clave de la entidad
	 * @return int Número de entradas
	 */
	public static function get_entity_cache_count( $entity_key ) {
		$stats = self::get_extended_stats();
		return isset( $stats['by_group'][ $entity_key ] ) ? $stats['by_group'][ $entity_key ] : 0;
	}

	/**
	 * Alias para flush_group('product')
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_product_cache() {
		return self::flush_group( 'product' );
	}

	/**
	 * Alias para flush_group('order')
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_order_cache() {
		return self::flush_group( 'order' );
	}

	/**
	 * Alias para flush_group('config')
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_config_cache() {
		return self::flush_group( 'config' );
	}

	/**
	 * Alias para flush_group('global')
	 *
	 * @return int Número de entradas eliminadas
	 */
	public static function flush_global_cache() {
		return self::flush_group( 'global' );
	}

	/**
	 * Elimina todas las entradas de caché de todos los grupos.
	 * 
	 * @return int El número de entradas eliminadas.
	 */
	public static function flush_all_cache() {
		return self::flush_all();
	}
}
