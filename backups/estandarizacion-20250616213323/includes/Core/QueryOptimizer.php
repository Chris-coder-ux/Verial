<?php
/**
 * Optimizador de Consultas SQL para Mi Integración API
 *
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase que proporciona métodos optimizados para consultas comunes
 * y funciones de utilidad para mejorar el rendimiento de las consultas SQL
 */
class QueryOptimizer {

	/**
	 * Almacena en memoria caché los resultados de consultas frecuentes
	 *
	 * @var array
	 */
	private static $query_cache = array();

	/**
	 * Duración por defecto para la caché en memoria (en segundos)
	 *
	 * @var int
	 */
	private static $cache_ttl = 60;

	/**
	 * Total de consultas optimizadas en la solicitud actual
	 *
	 * @var int
	 */
	private static $optimized_queries_count = 0;

	/**
	 * Obtiene logs con consultas preparadas y optimizadas
	 *
	 * @param array $filtros Filtros a aplicar
	 * @param int   $pagina Número de página
	 * @param int   $por_pagina Elementos por página (-1 para todos)
	 * @param int   &$total Variable por referencia para devolver total de logs
	 * @return array Logs filtrados
	 */
	public static function get_filtered_logs( $filtros, $pagina = 1, $por_pagina = 20, &$total = 0 ) {
		global $wpdb;

		// Tabla de logs
		$tabla_logs = $wpdb->prefix . 'mi_integracion_api_logs';

		// Generar una clave de caché basada en los filtros
		$cache_key = 'logs_' . md5( serialize( $filtros ) . $pagina . $por_pagina );

		// Intentar recuperar de la caché en memoria primero
		if ( isset( self::$query_cache[ $cache_key ] ) && ( time() - self::$query_cache[ $cache_key ]['time'] < self::$cache_ttl ) ) {
			$total = self::$query_cache[ $cache_key ]['total'];
			return self::$query_cache[ $cache_key ]['data'];
		}

		// Intentar recuperar de la caché transient de WordPress
		$cached_result = \MI_Cache_Manager::get( $cache_key, null, 'api_queries' );
		if ( $cached_result !== null ) {
			$total = $cached_result['total'];
			return $cached_result['data'];
		}

		// Construir consulta base con JOIN optimizado si se requiere búsqueda en contexto
		$necesita_join_contexto = ! empty( $filtros['search'] );

		// Construir campos SELECT
		$select_fields = 'l.id, l.fecha, l.tipo, l.usuario, l.entidad, l.mensaje, l.contexto';

		// Construimos la consulta base
		$sql_select = "SELECT {$select_fields} FROM {$tabla_logs} AS l";
		$sql_count  = "SELECT COUNT(l.id) FROM {$tabla_logs} AS l";

		// Condiciones WHERE y parámetros
		$sql_where  = array();
		$sql_params = array();

		// Filtrar por tipo de log (usar índice)
		if ( ! empty( $filtros['level'] ) ) {
			$sql_where[]  = 'l.tipo = %s';
			$sql_params[] = $filtros['level'];
		}

		// Filtrar por fuente/entidad (usar índice)
		if ( ! empty( $filtros['source'] ) ) {
			$sql_where[]  = 'l.entidad = %s';
			$sql_params[] = $filtros['source'];
		}

		// Filtrar por fecha inicial (usar índice)
		if ( ! empty( $filtros['date_start'] ) ) {
			$sql_where[]  = 'l.fecha >= %s';
			$sql_params[] = $filtros['date_start'] . ' 00:00:00';
		}

		// Filtrar por fecha final (usar índice)
		if ( ! empty( $filtros['date_end'] ) ) {
			$sql_where[]  = 'l.fecha <= %s';
			$sql_params[] = $filtros['date_end'] . ' 23:59:59';
		}

		// Filtrar por búsqueda (en mensaje y contexto)
		if ( ! empty( $filtros['search'] ) ) {
			// Usar LIKE con operador OR puede ser menos eficiente, limitamos la búsqueda a campos indexados
			// cuando sea posible y usamos FULLTEXT si está disponible
			$busqueda = '%' . $wpdb->esc_like( $filtros['search'] ) . '%';

			// Comprobar si existe índice FULLTEXT
			$has_fulltext = self::check_fulltext_index( $tabla_logs, array( 'mensaje', 'contexto' ) );

			if ( $has_fulltext ) {
				// Usar MATCH AGAINST para búsqueda más eficiente
				$sql_where[]  = 'MATCH(l.mensaje, l.contexto) AGAINST (%s IN BOOLEAN MODE)';
				$sql_params[] = $filtros['search'] . '*'; // Búsqueda por prefijo
			} else {
				// Fallback a LIKE, centrándose primero en mensaje por ser más probable y menos pesado
				$sql_where[]  = '(l.mensaje LIKE %s OR l.contexto LIKE %s)';
				$sql_params[] = $busqueda;
				$sql_params[] = $busqueda;
			}
		}

		// Agregar cláusula WHERE a las consultas
		if ( ! empty( $sql_where ) ) {
			$sql_select .= ' WHERE ' . implode( ' AND ', $sql_where );
			$sql_count  .= ' WHERE ' . implode( ' AND ', $sql_where );
		}

		// Usar índice de fecha para ordenación
		$sql_select .= ' ORDER BY l.fecha DESC';

		// Limitar resultados para paginación (si por_pagina > 0)
		if ( $por_pagina > 0 ) {
			$offset      = ( $pagina - 1 ) * $por_pagina;
			$sql_select .= $wpdb->prepare( ' LIMIT %d, %d', $offset, $por_pagina );
		}

		// Preparar consultas
		$sql_select = $wpdb->prepare( $sql_select, $sql_params );
		$sql_count  = $wpdb->prepare( $sql_count, $sql_params );

		// Obtener total de logs primero (más ligero)
		$total = (int) $wpdb->get_var( $sql_count );

		// Si no hay resultados, devolver array vacío inmediatamente
		if ( $total === 0 ) {
			$logs = array();

			// Guardar en caché
			$cached_result = array(
				'data'  => $logs,
				'total' => 0,
				'time'  => time(),
			);

			self::$query_cache[ $cache_key ] = $cached_result;
			\MI_Cache_Manager::set( $cache_key, $cached_result, 300, 'api_queries' );

			return $logs;
		}

		// Obtener logs
		$logs_raw = $wpdb->get_results( $sql_select, ARRAY_A );
		$logs     = array();

		foreach ( $logs_raw as $log ) {
			// Decodificar el contexto JSON si existe y es necesario
			$contexto = ! empty( $log['contexto'] ) ? json_decode( $log['contexto'], true ) : null;

			// Formatear log para la vista
			$logs[] = array(
				'date'    => $log['fecha'],
				'level'   => $log['tipo'],
				'message' => $log['mensaje'],
				'source'  => $log['entidad'],
				'user'    => $log['usuario'] ?? 'sistema',
				'context' => $contexto,
			);
		}

		// Guardar en caché
		$cached_result = array(
			'data'  => $logs,
			'total' => $total,
			'time'  => time(),
		);

		self::$query_cache[ $cache_key ] = $cached_result;
		\MI_Cache_Manager::set( $cache_key, $cached_result, 300, 'api_queries' );

		// Incrementar contador de consultas optimizadas
		++self::$optimized_queries_count;

		return $logs;
	}

	/**
	 * Verifica si una tabla tiene índice FULLTEXT en las columnas especificadas
	 *
	 * @param string $table Nombre de la tabla
	 * @param array  $columns Columnas a verificar
	 * @return bool True si existe el índice
	 */
	public static function check_fulltext_index( $table, $columns ) {
		static $fulltext_cache = array();

		// Verificar caché primero
		$table_key = $table . '_' . implode( '_', $columns );
		if ( isset( $fulltext_cache[ $table_key ] ) ) {
			return $fulltext_cache[ $table_key ];
		}

		global $wpdb;

		// Verificar índices de la tabla
		$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

		if ( ! $indexes ) {
			$fulltext_cache[ $table_key ] = false;
			return false;
		}

		// Verificar si hay un índice FULLTEXT que incluya todas las columnas requeridas
		$found_columns = array();
		foreach ( $indexes as $index ) {
			if ( $index['Index_type'] === 'FULLTEXT' ) {
				$found_columns[] = $index['Column_name'];
			}
		}

		$has_fulltext                 = count( array_intersect( $columns, $found_columns ) ) === count( $columns );
		$fulltext_cache[ $table_key ] = $has_fulltext;

		return $has_fulltext;
	}

	/**
	 * Optimiza la consulta de limpieza de caché realizándola en lotes
	 *
	 * @param string $prefix Prefijo de las claves de caché a eliminar
	 * @param int    $batch_size Tamaño del lote
	 * @return int Número de entradas eliminadas
	 */
	public static function batch_delete_cache( $prefix, $batch_size = 1000 ) {
		global $wpdb;

		$total_deleted = 0;
		$continue      = true;

		while ( $continue ) {
			// Seleccionar un lote de opciones a eliminar
			$options = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} 
                    WHERE option_name LIKE %s OR option_name LIKE %s 
                    LIMIT %d",
					'_transient_' . $prefix . '%',
					'_transient_timeout_' . $prefix . '%',
					$batch_size
				),
				ARRAY_A
			);

			if ( empty( $options ) ) {
				$continue = false;
				break;
			}

			// Construir consulta IN para eliminar el lote completo
			$option_names = array_map(
				function ( $opt ) {
					return $opt['option_name'];
				},
				$options
			);

			$placeholders = implode( ', ', array_fill( 0, count( $option_names ), '%s' ) );

			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->options} WHERE option_name IN ({$placeholders})",
					$option_names
				)
			);

			$total_deleted += $deleted;

			// Si se eliminaron menos entradas que el tamaño del lote, hemos terminado
			if ( $deleted < $batch_size ) {
				$continue = false;
			}

			// Prevenir bucles infinitos por seguridad
			if ( $total_deleted > 100000 ) {
				break;
			}
		}

		return $total_deleted;
	}

	/**
	 * Ejecuta una consulta SQL con preparación de consulta optimizada
	 * y caché inteligente
	 *
	 * @param string $query Consulta SQL con placeholders
	 * @param array  $args Argumentos para la consulta
	 * @param string $cache_key Clave para almacenar el resultado en caché
	 * @param int    $cache_time Tiempo de caché en segundos (0 para no cachear)
	 * @param string $output Tipo de salida (OBJECT, ARRAY_A, ARRAY_N)
	 * @return mixed Resultado de la consulta
	 */
	public static function get_results( $query, $args = array(), $cache_key = '', $cache_time = 0, $output = OBJECT ) {
		global $wpdb;

		// Si hay una clave de caché, intentar recuperar resultado
		if ( ! empty( $cache_key ) && $cache_time > 0 ) {
			// Intentar caché en memoria primero (más rápida)
			if ( isset( self::$query_cache[ $cache_key ] ) && ( time() - self::$query_cache[ $cache_key ]['time'] < self::$cache_ttl ) ) {
				return self::$query_cache[ $cache_key ]['data'];
			}

			// Intentar caché de WordPress
			$cached = \MI_Cache_Manager::get( $cache_key, null, 'db_queries' );
			if ( $cached !== null ) {
				return $cached;
			}
		}

		// Si hay argumentos, preparamos la consulta
		if ( ! empty( $args ) ) {
			$query = $wpdb->prepare( $query, $args );
		}

		// Ejecutar consulta
		$results = $wpdb->get_results( $query, $output );

		// Guardar en caché si es necesario
		if ( ! empty( $cache_key ) && $cache_time > 0 ) {
			self::$query_cache[ $cache_key ] = array(
				'data' => $results,
				'time' => time(),
			);

			\MI_Cache_Manager::set( $cache_key, $results, $cache_time, 'db_queries' );
		}

		++self::$optimized_queries_count;
		return $results;
	}

	/**
	 * Obtiene un único valor de la base de datos con optimización de caché
	 *
	 * @param string $query Consulta SQL con placeholders
	 * @param array  $args Argumentos para la consulta
	 * @param string $cache_key Clave para almacenar el resultado en caché
	 * @param int    $cache_time Tiempo de caché en segundos (0 para no cachear)
	 * @return mixed Resultado de la consulta
	 */
	public static function get_var( $query, $args = array(), $cache_key = '', $cache_time = 0 ) {
		global $wpdb;

		// Si hay una clave de caché, intentar recuperar resultado
		if ( ! empty( $cache_key ) && $cache_time > 0 ) {
			// Intentar caché en memoria primero
			if ( isset( self::$query_cache[ $cache_key ] ) && ( time() - self::$query_cache[ $cache_key ]['time'] < self::$cache_ttl ) ) {
				return self::$query_cache[ $cache_key ]['data'];
			}

			// Intentar caché de WordPress
			$cached = \MI_Cache_Manager::get( $cache_key, null, 'db_queries' );
			if ( $cached !== null ) {
				return $cached;
			}
		}

		// Si hay argumentos, preparamos la consulta
		if ( ! empty( $args ) ) {
			$query = $wpdb->prepare( $query, $args );
		}

		// Ejecutar consulta
		$result = $wpdb->get_var( $query );

		// Guardar en caché si es necesario
		if ( ! empty( $cache_key ) && $cache_time > 0 ) {
			self::$query_cache[ $cache_key ] = array(
				'data' => $result,
				'time' => time(),
			);

			\MI_Cache_Manager::set( $cache_key, $result, $cache_time, 'db_queries' );
		}

		++self::$optimized_queries_count;
		return $result;
	}

	/**
	 * Obtiene un valor del contador de consultas optimizadas
	 *
	 * @return int Número de consultas optimizadas
	 */
	public static function get_optimized_queries_count() {
		return self::$optimized_queries_count;
	}

	/**
	 * Establece la duración TTL para la caché en memoria
	 *
	 * @param int $seconds Duración en segundos
	 */
	public static function set_cache_ttl( $seconds ) {
		self::$cache_ttl = max( 1, (int) $seconds );
	}

	/**
	 * Ejecuta una inserción o actualización dependiendo de si existe el registro
	 * (UPSERT pattern)
	 *
	 * @param string $table Nombre de la tabla
	 * @param array  $data Datos a insertar/actualizar
	 * @param array  $where Condiciones para buscar registro existente
	 * @return int|false ID insertado/actualizado o false si falla
	 */
	public static function upsert( $table, $data, $where ) {
		global $wpdb;

		// Tabla con prefijo de WordPress
		$table = $wpdb->prefix . $table;

		// Construir condiciones WHERE para buscar registro existente
		$where_sql    = array();
		$where_values = array();

		foreach ( $where as $field => $value ) {
			$where_sql[]    = "`{$field}` = %s";
			$where_values[] = $value;
		}

		$where_clause = implode( ' AND ', $where_sql );

		// Si no hay condiciones WHERE, realizar una inserción directa
		if ( empty( $where_sql ) ) {
			$result = $wpdb->insert( $table, $data );
			return $result ? $wpdb->insert_id : false;
		}

		// Comprobar si existe el registro
		$exists_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where_clause}",
			$where_values
		);

		$exists = (int) $wpdb->get_var( $exists_query ) > 0;

		// Actualizar o insertar según corresponda
		if ( $exists ) {
			// Actualizar registro existente
			$result = $wpdb->update( $table, $data, $where );

			if ( $result !== false ) {
				// Obtener ID del registro actualizado
				$id_query = $wpdb->prepare(
					"SELECT id FROM {$table} WHERE {$where_clause}",
					$where_values
				);
				return $wpdb->get_var( $id_query );
			}
			return false;
		} else {
			// Insertar nuevo registro
			$insert_data = array_merge( $data, $where );
			$result      = $wpdb->insert( $table, $insert_data );

			if ( $result ) {
				return $wpdb->insert_id;
			}
			return false;
		}
	}

	/**
	 * Crea un índice FULLTEXT en una tabla si no existe
	 *
	 * @param string $table Nombre de la tabla
	 * @param array  $columns Columnas para el índice
	 * @param string $index_name Nombre del índice
	 * @return bool True si se creó o ya existía
	 */
	public static function create_fulltext_index( $table, $columns, $index_name = '' ) {
		global $wpdb;

		// Verificar si ya existe el índice
		if ( self::check_fulltext_index( $table, $columns ) ) {
			return true;
		}

		// Si no se proporciona un nombre para el índice, crear uno basado en las columnas
		if ( empty( $index_name ) ) {
			$index_name = 'ft_' . implode( '_', $columns );
		}

		// Sanitizar el nombre del índice
		$index_name = preg_replace( '/[^a-zA-Z0-9_]/', '', $index_name );

		// Lista de columnas separadas por comas
		$columns_str = '`' . implode( '`, `', $columns ) . '`';

		// Crear el índice
		$result = $wpdb->query(
			"
            ALTER TABLE {$table} 
            ADD FULLTEXT INDEX {$index_name} ({$columns_str})
        "
		);

		return $result !== false;
	}
}
