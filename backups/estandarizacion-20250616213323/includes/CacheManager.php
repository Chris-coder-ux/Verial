<?php
/**
 * Clase para gestionar el sistema de caché del plugin.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 */

namespace MiIntegracionApi;



// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar el sistema de caché del plugin.
 *
 * Esta clase proporciona métodos para almacenar, recuperar, y gestionar
 * datos en caché, mejorando el rendimiento al reducir llamadas a la API
 * y consultas repetitivas a la base de datos.
 *
 * @since 1.0.0
 */
class CacheManager {

	/**
	 * Instancia única de esta clase (patrón Singleton).
	 *
	 * @since 1.0.0
	 * @access   private
	 * @var      MI_Cache_Manager    $instance    La única instancia de esta clase.
	 */
	private static $instance = null;

	/**
	 * Prefijo para las claves de caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      string    $cache_prefix    Prefijo para las claves de caché.
	 */
	protected $cache_prefix = 'mia_cache_';

	/**
	 * Tiempo de vida predeterminado para la caché en segundos.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      int    $default_ttl    Tiempo de vida predeterminado.
	 */
	protected $default_ttl;

	/**
	 * Instancia del logger.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      MiIntegracionApi\Helpers\Logger    $logger    Instancia del logger.
	 */
	protected $logger;

	/**
	 * Indica si la caché está habilitada.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      boolean    $enabled    Si la caché está habilitada.
	 */
	protected $enabled;

	/**
	 * Constructor privado para implementar el patrón Singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Verificar si la caché está habilitada
		$this->enabled = (bool) get_option( MiIntegracionApi_OPTION_PREFIX . 'enable_cache', true );

		// Obtener TTL predeterminado
		$this->default_ttl = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_ttl', 3600 );

		// Inicializar logger si existe la clase
		if ( class_exists( 'MiIntegracionApi\\Helpers\\Logger' ) ) {
			$this->logger = new \MiIntegracionApi\Helpers\Logger( 'cache_manager' );
		}

		// Agregar acciones para limpieza de caché
		$this->init_cache_hooks();
	}

	/**
	 * Obtiene la instancia única de esta clase.
	 *
	 * @since 1.0.0
	 * @return   MI_Cache_Manager    La única instancia de esta clase.
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Inicializa hooks relacionados con la caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 */
	protected function init_cache_hooks() {
		// Limpieza periódica de caché expirada
		if ( ! wp_next_scheduled( 'mi_integracion_api_clean_expired_cache' ) ) {
			wp_schedule_event( time(), 'daily', 'mi_integracion_api_clean_expired_cache' );
		}

		add_action( 'mi_integracion_api_clean_expired_cache', array( $this, 'clean_expired_cache' ) );

		// Limpiar caché cuando se actualiza un producto
		add_action( 'save_post_product', array( $this, 'clear_product_cache' ), 10, 3 );

		// Limpiar caché cuando se actualiza una categoría
		add_action( 'edit_term', array( $this, 'clear_term_cache' ), 10, 3 );

		// Limpiar caché cuando se actualiza una opción del plugin
		add_action( 'update_option', array( $this, 'maybe_clear_cache_on_option_update' ), 10, 3 );

		// Limpiar caché al activar/desactivar el plugin
		register_activation_hook( MiIntegracionApi_PLUGIN_FILE, array( $this, 'clear_all_cache' ) );
	}

	/**
	 * Guarda un valor en caché.
	 *
	 * @since 1.0.0
	 * @param    string $key      Clave para identificar los datos.
	 * @param    mixed  $value    Valor a almacenar en caché.
	 * @param    int    $ttl      Tiempo de vida en segundos. Usar 0 para no expirar.
	 * @return   boolean             True si se guardó correctamente, false en caso contrario.
	 */
	public function set( $key, $value, $ttl = null ) {
		if ( ! $this->enabled ) {
			return false;
		}

		// Usar TTL predeterminado si no se especifica
		if ( $ttl === null ) {
			$ttl = $this->default_ttl;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Guardar metadata para gestión de caché
		$this->set_cache_metadata( $cache_key, $ttl );

		// Almacenar en transient
		$result = set_transient( $cache_key, $value, $ttl );

		if ( $result && $this->logger ) {
			$this->logger->debug(
				'Caché establecida',
				array(
					'key'   => $key,
					'ttl'   => $ttl,
					'bytes' => $this->estimate_size( $value ),
				)
			);
		}

		return $result;
	}

	/**
	 * Recupera un valor de la caché.
	 *
	 * @since 1.0.0
	 * @param    string  $key           Clave para identificar los datos.
	 * @param    mixed   $default       Valor por defecto si no existe en caché.
	 * @param    boolean $refresh_ttl   Si debe refrescar el TTL al recuperar.
	 * @return   mixed                    Valor almacenado o valor por defecto.
	 */
	public function get( $key, $default = false, $refresh_ttl = false ) {
		if ( ! $this->enabled ) {
			return $default;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Obtener de transient
		$value = get_transient( $cache_key );

		if ( $value !== false ) {
			if ( $refresh_ttl ) {
				$metadata = $this->get_cache_metadata( $cache_key );
				if ( $metadata && isset( $metadata['ttl'] ) && $metadata['ttl'] > 0 ) {
					$this->set( $key, $value, $metadata['ttl'] );
				}
			}

			if ( $this->logger ) {
				$this->logger->debug(
					'Caché recuperada',
					array(
						'key'       => $key,
						'cache_hit' => true,
					)
				);
			}

			return $value;
		}

		if ( $this->logger ) {
			$this->logger->debug(
				'Caché no encontrada',
				array(
					'key'       => $key,
					'cache_hit' => false,
				)
			);
		}

		return $default;
	}

	/**
	 * Elimina un valor específico de la caché.
	 *
	 * @since 1.0.0
	 * @param    string $key    Clave para identificar los datos.
	 * @return   boolean           True si se eliminó, false en caso contrario.
	 */
	public function delete( $key ) {
		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Eliminar metadata
		$this->delete_cache_metadata( $cache_key );

		// Eliminar transient
		$result = delete_transient( $cache_key );

		if ( $result && $this->logger ) {
			$this->logger->debug(
				'Caché eliminada',
				array(
					'key' => $key,
				)
			);
		}

		return $result;
	}

	/**
	 * Comprueba si una clave existe en la caché.
	 *
	 * @since 1.0.0
	 * @param    string $key    Clave para identificar los datos.
	 * @return   boolean           True si existe, false en caso contrario.
	 */
	public function exists( $key ) {
		if ( ! $this->enabled ) {
			return false;
		}

		// Sanitizar y preparar la clave
		$cache_key = $this->prepare_key( $key );

		// Verificar existencia
		return get_transient( $cache_key ) !== false;
	}

	/**
	 * Limpia toda la caché del plugin.
	 *
	 * @since 1.0.0
	 * @return   int       Número de elementos eliminados.
	 */
	public function clear_all_cache() {
		global $wpdb;

		// Obtener todas las claves de transient con nuestro prefijo
		$sql = $wpdb->prepare(
			"SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE %s 
             OR option_name LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_' . $this->cache_prefix . '%'
		);

		$transients = $wpdb->get_col( $sql );
		$count      = 0;

		// Eliminar cada transient
		foreach ( $transients as $transient ) {
			$key = str_replace( array( '_transient_', '_transient_timeout_' ), '', $transient );

			// Eliminar metadata y transient
			$this->delete_cache_metadata( $key );
			delete_option( $transient );
			++$count;
		}

		// Limpiar metadata
		$this->clear_all_metadata();

		if ( $this->logger ) {
			$this->logger->info(
				'Caché completa eliminada',
				array(
					'count' => $count,
				)
			);
		}

		return $count / 2; // Dividir por 2 porque contamos transient y su timeout
	}

	/**
	 * Limpia la caché expirada.
	 *
	 * @since 1.0.0
	 * @return   int       Número de elementos expirados eliminados.
	 */
	public function clean_expired_cache() {
		// Los transients expirados son eliminados automáticamente por WordPress
		// Solo necesitamos limpiar la metadata
		$count = $this->clean_expired_metadata();

		if ( $this->logger ) {
			$this->logger->info(
				'Caché expirada eliminada',
				array(
					'count' => $count,
				)
			);
		}

		return $count;
	}

	/**
	 * Limpia la caché relacionada con un producto.
	 *
	 * @since 1.0.0
	 * @param    int     $post_id      ID del post.
	 * @param    WP_Post $post         Objeto post.
	 * @param    boolean $update       Si es una actualización.
	 * @return   void
	 */
	public function clear_product_cache( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Limpiar caché específica del producto
		$this->delete( 'product_' . $post_id );
		$this->delete( 'product_data_' . $post_id );

		// Limpiar caches relacionadas
		$this->delete( 'products_list' );
		$this->delete( 'recently_updated_products' );

		// Buscar y eliminar cualquier caché que contenga el ID del producto
		$this->delete_by_pattern( '*product*' . $post_id . '*' );

		if ( $this->logger ) {
			$this->logger->debug(
				'Caché de producto eliminada',
				array(
					'product_id' => $post_id,
				)
			);
		}
	}

	/**
	 * Limpia la caché relacionada con un término/categoría.
	 *
	 * @since 1.0.0
	 * @param    int    $term_id      ID del término.
	 * @param    int    $tt_id        ID de la taxonomía del término.
	 * @param    string $taxonomy     Taxonomía.
	 * @return   void
	 */
	public function clear_term_cache( $term_id, $tt_id, $taxonomy ) {
		// Solo procesar términos relevantes
		if ( ! in_array( $taxonomy, array( 'product_cat', 'product_tag', 'category' ) ) ) {
			return;
		}

		// Limpiar caché específica del término
		$this->delete( 'term_' . $term_id );
		$this->delete( 'taxonomy_' . $taxonomy );

		// Limpiar caches relacionadas
		$this->delete( 'categories_list' );
		$this->delete( 'categories_tree' );

		if ( $this->logger ) {
			$this->logger->debug(
				'Caché de término eliminada',
				array(
					'term_id'  => $term_id,
					'taxonomy' => $taxonomy,
				)
			);
		}
	}

	/**
	 * Verifica si debe limpiar la caché cuando se actualiza una opción.
	 *
	 * @since 1.0.0
	 * @param    string $option_name     Nombre de la opción.
	 * @param    mixed  $old_value       Valor anterior.
	 * @param    mixed  $new_value       Nuevo valor.
	 * @return   void
	 */
	public function maybe_clear_cache_on_option_update( $option_name, $old_value, $new_value ) {
		// Solo procesar opciones del plugin
		if ( strpos( $option_name, MiIntegracionApi_OPTION_PREFIX ) !== 0 ) {
			return;
		}

		// Opciones que afectan a la caché global
		$global_cache_options = array(
			MiIntegracionApi_OPTION_PREFIX . 'api_url',
			MiIntegracionApi_OPTION_PREFIX . 'api_key',
			MiIntegracionApi_OPTION_PREFIX . 'api_secret',
			MiIntegracionApi_OPTION_PREFIX . 'sync_settings',
		);

		// Opciones que afectan a la configuración de caché
		$cache_config_options = array(
			MiIntegracionApi_OPTION_PREFIX . 'enable_cache',
			MiIntegracionApi_OPTION_PREFIX . 'cache_ttl',
		);

		// Si cambia una opción que afecta a la caché global
		if ( in_array( $option_name, $global_cache_options ) ) {
			$this->clear_all_cache();
		}
		// Si cambia la configuración de caché
		elseif ( in_array( $option_name, $cache_config_options ) ) {
			// Actualizar variables internas
			if ( $option_name === MiIntegracionApi_OPTION_PREFIX . 'enable_cache' ) {
				$this->enabled = (bool) $new_value;

				// Si se desactiva la caché, limpiarla toda
				if ( ! $this->enabled ) {
					$this->clear_all_cache();
				}
			} elseif ( $option_name === MiIntegracionApi_OPTION_PREFIX . 'cache_ttl' ) {
				$this->default_ttl = (int) $new_value;
			}
		}
	}

	/**
	 * Elimina elementos de la caché por patrón.
	 *
	 * @since 1.0.0
	 * @param    string $pattern    Patrón para las claves (acepta * como comodín).
	 * @return   int                   Número de elementos eliminados.
	 */
	public function delete_by_pattern( $pattern ) {
		global $wpdb;

		// Convertir patrón con * a formato SQL LIKE
		$sql_pattern = str_replace( '*', '%', $pattern );
		$sql_pattern = $this->cache_prefix . $sql_pattern;

		// Buscar transients que coincidan
		$sql = $wpdb->prepare(
			"SELECT option_name FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name LIKE %s",
			'_transient_' . $sql_pattern,
			'_transient_%'
		);

		$transients = $wpdb->get_col( $sql );
		$count      = 0;

		// Eliminar cada transient
		foreach ( $transients as $transient ) {
			$key = str_replace( '_transient_', '', $transient );

			// Eliminar metadata y transient
			$this->delete_cache_metadata( $key );
			delete_option( $transient );
			delete_option( '_transient_timeout_' . $key );
			++$count;
		}

		if ( $this->logger && $count > 0 ) {
			$this->logger->debug(
				'Caché eliminada por patrón',
				array(
					'pattern' => $pattern,
					'count'   => $count,
				)
			);
		}

		return $count;
	}

	/**
	 * Obtiene estadísticas de uso de la caché.
	 *
	 * @since 1.0.0
	 * @return   array     Estadísticas de la caché.
	 */
	public function get_stats() {
		global $wpdb;

		// Contar elementos en caché
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_%'
		);

		$count = (int) $wpdb->get_var( $sql );

		// Calcular tamaño aproximado
		$sql = $wpdb->prepare(
			"SELECT SUM(LENGTH(option_value)) FROM $wpdb->options 
             WHERE option_name LIKE %s 
             AND option_name NOT LIKE %s",
			'_transient_' . $this->cache_prefix . '%',
			'_transient_timeout_%'
		);

		$size = (int) $wpdb->get_var( $sql );

		// Obtener estadísticas de hit/miss si están disponibles
		$hits   = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0 );
		$misses = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0 );

		// Calcular ratio de aciertos
		$total_requests = $hits + $misses;
		$hit_ratio      = $total_requests > 0 ? ( $hits / $total_requests ) * 100 : 0;

		return array(
			'enabled'        => $this->enabled,
			'count'          => $count,
			'size_bytes'     => $size,
			'size_formatted' => $this->format_size( $size ),
			'ttl'            => $this->default_ttl,
			'hits'           => $hits,
			'misses'         => $misses,
			'hit_ratio'      => round( $hit_ratio, 2 ),
			'last_cleared'   => get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_last_cleared', '' ),
			'last_check'     => current_time( 'mysql' ),
		);
	}

	/**
	 * Prepara una clave de caché, sanitizándola y añadiendo el prefijo.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $key    Clave original.
	 * @return   string            Clave preparada.
	 */
	protected function prepare_key( $key ) {
		// Sanitizar clave
		$key = sanitize_key( str_replace( array( ' ', '.' ), '_', $key ) );

		// Añadir prefijo si no lo tiene ya
		if ( strpos( $key, $this->cache_prefix ) !== 0 ) {
			$key = $this->cache_prefix . $key;
		}

		return $key;
	}

	/**
	 * Guarda metadata de un elemento en caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $key         Clave de caché.
	 * @param    int    $ttl         Tiempo de vida en segundos.
	 * @return   boolean                True si se guardó correctamente.
	 */
	protected function set_cache_metadata( $key, $ttl ) {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		$metadata[ $key ] = array(
			'created' => time(),
			'expires' => $ttl > 0 ? time() + $ttl : 0,
			'ttl'     => $ttl,
		);

		// Evitar que metadata crezca demasiado
		if ( count( $metadata ) > 1000 ) {
			// Eliminar entradas antiguas
			$metadata = array_slice( $metadata, -500, 500, true );
		}

		return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
	}

	/**
	 * Obtiene metadata de un elemento en caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $key         Clave de caché.
	 * @return   array|false            Metadata o false si no existe.
	 */
	protected function get_cache_metadata( $key ) {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		return isset( $metadata[ $key ] ) ? $metadata[ $key ] : false;
	}

	/**
	 * Elimina metadata de un elemento en caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $key         Clave de caché.
	 * @return   boolean                True si se eliminó correctamente.
	 */
	protected function delete_cache_metadata( $key ) {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );

		if ( isset( $metadata[ $key ] ) ) {
			unset( $metadata[ $key ] );
			return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
		}

		return false;
	}

	/**
	 * Limpia toda la metadata de caché.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @return   boolean                True si se limpió correctamente.
	 */
	protected function clear_all_metadata() {
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_last_cleared', current_time( 'mysql' ) );
		return update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array(), false );
	}

	/**
	 * Limpia metadata expirada.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @return   int                    Número de elementos eliminados.
	 */
	protected function clean_expired_metadata() {
		$metadata = get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', array() );
		$now      = time();
		$count    = 0;

		foreach ( $metadata as $key => $data ) {
			if ( ! empty( $data['expires'] ) && $data['expires'] < $now ) {
				unset( $metadata[ $key ] );
				++$count;
			}
		}

		if ( $count > 0 ) {
			update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_metadata', $metadata, false );
		}

		return $count;
	}

	/**
	 * Estima el tamaño en bytes de un valor.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    mixed $value    Valor a medir.
	 * @return   int                 Tamaño aproximado en bytes.
	 */
	protected function estimate_size( $value ) {
		$serialized = serialize( $value );
		return strlen( $serialized );
	}

	/**
	 * Formatea un tamaño en bytes a una unidad legible.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    int $bytes       Tamaño en bytes.
	 * @param    int $precision   Precisión decimal.
	 * @return   string                 Tamaño formateado.
	 */
	protected function format_size( $bytes, $precision = 2 ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );

		$bytes /= pow( 1024, $pow );

		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Incrementa el contador de aciertos de caché.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function increment_hit_count() {
		$hits = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0 );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', $hits + 1, false );
	}

	/**
	 * Incrementa el contador de fallos de caché.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function increment_miss_count() {
		$misses = (int) get_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0 );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', $misses + 1, false );
	}

	/**
	 * Resetea los contadores de estadísticas.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function reset_stats() {
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_hits', 0, false );
		update_option( MiIntegracionApi_OPTION_PREFIX . 'cache_misses', 0, false );
	}

	/**
	 * Destructor. Limpia recursos cuando se destruye la instancia.
	 *
	 * @since 1.0.0
	 */
	public function __destruct() {
		// Nada que hacer por ahora
	}
}
