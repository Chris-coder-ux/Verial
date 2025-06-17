<?php
namespace MiIntegracionApi\Sync;

use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Core\Logger;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Helpers\DataSanitizer;
use MiIntegracionApi\Core\SyncError;
use MiIntegracionApi\Core\Validation\ProductValidator;
use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\TransactionManager;
use MiIntegracionApi\Core\ConfigManager;

/**
 * Clase para la sincronización de productos/artículos entre WooCommerce y Verial.
 */

// NOTA: Preferencia de desarrollo
// Si hace falta crear un archivo nuevo para lógica de sync, helpers, etc., se debe crear, nunca mezclar código en archivos que no corresponden. Esto asegura mantenibilidad profesional.

class SyncProductos extends BatchProcessor {
	const RETRY_OPTION = 'mia_sync_productos_retry';
	const MAX_RETRIES  = 3;
	const RETRY_DELAY  = 300; // segundos entre reintentos (5 min)

	private static $instance;
	private $logger;
	private $sanitizer;
	private $retry_manager;
	private $api_connector;
	private ProductValidator $validator;
	private MemoryManager $memory;
	private TransactionManager $transaction;

	public function __construct() {
		parent::__construct();
		self::$instance = $this;
		$this->logger = new Logger();
		$this->sanitizer = new DataSanitizer();
		$this->retry_manager = new RetryManager();
		$this->api_connector = new \MiIntegracionApi\Core\API_Connector();
		$this->validator = new ProductValidator();
		$this->memory = new MemoryManager();
		$this->transaction = new TransactionManager();
	}

	private static function add_to_retry_queue( $sku, $error_msg ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( ! isset( $queue[ $sku ] ) ) {
			$queue[ $sku ] = array(
				'attempts'     => 1,
				'last_attempt' => time(),
				'error'        => $error_msg,
			);
		} else {
			++$queue[ $sku ]['attempts'];
			$queue[ $sku ]['last_attempt'] = time();
			$queue[ $sku ]['error']        = $error_msg;
		}
		update_option( self::RETRY_OPTION, $queue, false );
	}
	private static function remove_from_retry_queue( $sku ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( isset( $queue[ $sku ] ) ) {
			unset( $queue[ $sku ] );
			update_option( self::RETRY_OPTION, $queue, false );
		}
	}
	private static function get_alert_email() {
		$custom = get_option( 'mia_alert_email' );
		if ( $custom && is_email( $custom ) ) {
			return $custom;
		}
		return get_option( 'admin_email' );
	}
	public static function process_retry_queue( $api_connector ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $sku => $info ) {
			if ( $info['attempts'] >= self::MAX_RETRIES ) {
				/* translators: %1$s: SKU del producto, %2$d: número de reintentos, %3$s: mensaje de error */
				$msg = sprintf( __( 'Producto SKU %1$s falló tras %2$d reintentos: %3$s', 'mi-integracion-api' ), $sku, $info['attempts'], $info['error'] );
				\MiIntegracionApi\helpers\Logger::critical( $msg, array( 'context' => 'sync-productos-retry' ) );
				$alert_email = self::get_alert_email();
				wp_mail( $alert_email, __( 'Producto no sincronizado tras reintentos', 'mi-integracion-api' ), $msg );
				// (Opcional) Registrar en tabla de incidencias si se implementa
				self::remove_from_retry_queue( $sku );
				continue;
			}
			if ( time() - $info['last_attempt'] < self::RETRY_DELAY ) {
				continue;
			}
			$wc_product_id = wc_get_product_id_by_sku( $sku );
			if ( ! $wc_product_id ) {
				self::remove_from_retry_queue( $sku );
				continue;
			}
			$wc_product = wc_get_product( $wc_product_id );
			if ( ! $wc_product ) {
				self::remove_from_retry_queue( $sku );
				continue;
			}
			$verial_data = $api_connector->get_articulos( array( 'sku' => $sku ) );
			if ( is_wp_error( $verial_data ) || ! is_array( $verial_data ) || empty( $verial_data[0] ) ) {
				self::add_to_retry_queue( $sku, is_wp_error( $verial_data ) ? $verial_data->get_error_message() : 'No data' );
				continue;
			}
			$producto = $verial_data[0];
			$wc_data  = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc( $producto );
			$wc_product->set_name( $wc_data['name'] );
			$wc_product->set_price( $wc_data['price'] );
			if ( isset( $wc_data['stock'] ) ) {
				$wc_product->set_stock_quantity( $wc_data['stock'] );
			}
			$wc_product->save();
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Producto SKU %1$s sincronizado tras reintento.', 'mi-integracion-api' ), $sku );
			\MiIntegracionApi\helpers\Logger::info( $msg, array( 'context' => 'sync-productos-retry' ) );
			self::remove_from_retry_queue( $sku );
		}
	}
	/**
	 * Sincroniza productos desde Verial a WooCommerce, solo los modificados desde una fecha si se indica.
	 * Versión optimizada para aprovechar filtrado en la API según documentación oficial de Verial.
	 *
	 * @param object      $api_connector Instancia del conector API.
	 * @param string|null $fecha_desde Fecha desde la que sincronizar (YYYY-MM-DD) o null para todos.
	 * @param int         $batch_size Tamaño del lote para procesar productos (0 = sin procesar por lotes)
	 * @param array       $filtros_adicionales Filtros adicionales para la API
	 * @return array Resultado de la sincronización.
	 */
	public static function sync( $api_connector, $fecha_desde = null, $batch_size = 0, $filtros_adicionales = array() ) {
		if (!class_exists('WooCommerce')) {
			throw new \MiIntegracionApi\Core\SyncError(
				'WooCommerce no está activo.',
				400,
				['entity' => 'productos']
			);
		}

		if (!\MiIntegracionApi\Core\SyncLock::acquire('productos')) {
			throw new \MiIntegracionApi\Core\SyncError(
				'Ya hay una sincronización en curso.',
				409,
				['entity' => 'productos']
			);
		}

		if (!class_exists('\\MiIntegracionApi\\Helpers\\MapProduct')) {
			throw new \MiIntegracionApi\Core\SyncError(
				'Clase MapProduct no disponible.',
				500,
				['entity' => 'productos']
			);
		}

		// Determinar si usar procesamiento por lotes
		$use_batch_processing = $batch_size > 0;

		if ( $use_batch_processing ) {
			return self::sync_batch( $api_connector, $fecha_desde, $batch_size, $filtros_adicionales );
		}

		$processed = 0;
		$errors    = 0;
		$log       = array();
		$start_time = microtime(true);
		$params = array();

		try {
			// --- Configuración optimizada de filtros para API según documentación ---
			
			// Establecer filtros primarios: fecha de modificación
			if ( $fecha_desde ) {
				// Según documentación de Verial 1.6.2, el parámetro 'fecha' filtra según 
				// fecha de modificación de productos
				$params['fecha'] = $fecha_desde;
				
				// También aprovechar el campo fecha_hora si disponemos de timestamp completo
				if (strpos($fecha_desde, ' ') !== false) {
					$parts = explode(' ', $fecha_desde);
					if (count($parts) == 2) {
						$params['fecha'] = $parts[0];
						$params['hora'] = $parts[1];
					}
				}
				
				\MiIntegracionApi\Helpers\Logger::info(
					'Iniciando sincronización de productos con filtro de fecha', 
					array(
						'category' => 'sync-productos',
						'fecha_desde' => $fecha_desde,
						'filtros_api' => $params
					)
				);
			} else {
				\MiIntegracionApi\Helpers\Logger::info(
					'Iniciando sincronización completa de productos sin filtro de fecha', 
					array('category' => 'sync-productos')
				);
			}
			
			// Configuración de paginación para prevenir problemas de memoria
			// En Verial 1.8+, usar los parámetros 'inicio' y 'fin' para paginación
			$default_limit = apply_filters('mi_integracion_api_max_articulos_por_request', 500);
			$params['inicio'] = 1;
			$params['fin'] = $default_limit;
			
			// Aplicar filtros adicionales proporcionados directamente como parámetro
			if (is_array($filtros_adicionales) && !empty($filtros_adicionales)) {
				$params = array_merge($params, $filtros_adicionales);
				\MiIntegracionApi\Helpers\Logger::info(
					'Aplicando filtros adicionales proporcionados directamente', 
					array(
						'category' => 'sync-productos',
						'filtros_adicionales' => $filtros_adicionales
					)
				);
			}
			
			// Obtener configuraciones adicionales de filtrado por hook
			$config_filtros = apply_filters('mi_integracion_api_sync_productos_filtros', array());
			if (is_array($config_filtros) && !empty($config_filtros)) {
				$params = array_merge($params, $config_filtros);
				\MiIntegracionApi\Helpers\Logger::info(
					'Aplicando filtros adicionales configurados por hook', 
					array(
						'category' => 'sync-productos',
						'filtros_adicionales' => $config_filtros
					)
				);
			}

			// --- Optimización para mejorar el logging y diagnóstico ---
			$memory_before = memory_get_usage();
			
			// --- Obtención de productos con parámetros optimizados ---
			$params = self::prepare_api_params($params);
			$productos = self::get_productos_optimizado( $api_connector, $params );

			$memory_after = memory_get_usage();
			$memory_usage = round(($memory_after - $memory_before) / 1024 / 1024, 2);

			if ( is_wp_error( $productos ) ) {
				\MiIntegracionApi\Helpers\Logger::error( 
					'Error al obtener productos de Verial: ' . $productos->get_error_message(), 
					array( 
						'category' => 'sync-productos',
						'params' => $params,
						'error_code' => $productos->get_error_code(),
						'memory_mb' => $memory_usage
					)
				);
				return array(
					'success'   => false,
					'message'   => $productos->get_error_message(),
					'processed' => 0,
					'errors'    => 1,
					'filters'   => $params
				);
			}
			
			if ( ! is_array( $productos ) || empty( $productos ) ) {
				$duration = round(microtime(true) - $start_time, 2);
				\MiIntegracionApi\Helpers\Logger::info( 
					'No se obtuvieron productos de Verial con los filtros aplicados.', 
					array( 
						'category' => 'sync-productos',
						'params' => $params,
						'duration' => $duration,
						'memory_mb' => $memory_usage
					)
				);
				return array(
					'success'   => true, // Es éxito pero sin productos
					'message'   => __( 'No se obtuvieron productos de Verial con los filtros aplicados.', 'mi-integracion-api' ),
					'processed' => 0,
					'errors'    => 0,
					'filters'   => $params,
					'duration'  => $duration
				);
			}

			$product_count = count($productos);
			\MiIntegracionApi\Helpers\Logger::info(
				sprintf('Se han obtenido %d productos de Verial aplicando filtros en API', $product_count),
				array(
					'category' => 'sync-productos',
					'total_productos' => $product_count,
					'filtros_aplicados' => $params,
					'memory_mb' => $memory_usage
				)
			);

			// --- Optimización: Procesar productos ---
			$results   = self::process_products( $productos );
			$processed = $results['processed'];
			$errors    = $results['errors'];
			$log       = $results['log'];

			// Procesar la cola de reintentos al final de la sincronización
			self::process_retry_queue( $api_connector );

			$duration = round(microtime(true) - $start_time, 2);
			$memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
			
			\MiIntegracionApi\Helpers\Logger::sync_operation('productos', [
				'total' => $product_count,
				'procesados' => $processed, 
				'errores' => $errors,
				'filtros' => $params,
				'duration' => $duration,
				'memory_peak_mb' => $memory_peak
			], $errors > 0 ? 'partial' : 'success');

			return array(
				'success'   => $errors === 0,
				'message'   => sprintf(
					__( 'Sincronización de productos completada en %s segundos. Procesados: %d de %d, Errores: %d', 'mi-integracion-api' ),
					$duration, 
					$processed, 
					$product_count,
					$errors
				),
				'processed' => $processed,
				'total'     => $product_count,
				'errors'    => $errors,
				'log'       => $log,
				'duration'  => $duration,
				'memory_mb' => $memory_peak,
				'filters'   => $params
			);
		} catch ( \Exception $e ) {
			$duration = round(microtime(true) - $start_time, 2);
			$memory_peak = round(memory_get_peak_usage() / 1024 / 1024, 2);
			
			\MiIntegracionApi\Helpers\Logger::exception( 
				$e,
				'Excepción en sincronización de productos',
				array( 
					'category' => 'sync-productos',
					'duration' => $duration,
					'params' => $params,
					'memory_peak_mb' => $memory_peak
				)
			);
			return array(
				'success'   => false,
				'message'   => $e->getMessage(),
				'processed' => $processed,
				'errors'    => $errors + 1,
				'log'       => $log,
				'duration'  => $duration,
				'memory_mb' => $memory_peak,
				'filters'   => $params
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	/**
	 * Sincronización de productos usando procesamiento por lotes.
	 * Utiliza los mismos filtros optimizados que el método sync principal.
	 *
	 * @param object      $api_connector Instancia del conector API.
	 * @param string|null $fecha_desde Fecha desde la que sincronizar (YYYY-MM-DD) o null para todos.
	 * @param int         $batch_size Tamaño del lote.
	 * @param array       $filtros_adicionales Filtros adicionales para la API
	 * @return array Resultado de la sincronización.
	 */
	private static function sync_batch( $api_connector, $fecha_desde = null, $batch_size = 100, $filtros_adicionales = array() ) {
		if ( ! class_exists( 'MiIntegracionApi\\Sync\\BatchProcessor' ) ) {
			require_once __DIR__ . '/BatchProcessor.php';
		}
		
		$start_time = microtime(true);
		
		// Configurar los parámetros iniciales
		$params = array();
		
		// Establecer filtros primarios: fecha de modificación
		if ( $fecha_desde ) {
			// Según documentación de Verial 1.6.2, el parámetro 'fecha' filtra según 
			// fecha de modificación de productos
			$params['fecha'] = $fecha_desde;
			
			// También aprovechar el campo fecha_hora si disponemos de timestamp completo
			if (strpos($fecha_desde, ' ') !== false) {
				$parts = explode(' ', $fecha_desde);
				if (count($parts) == 2) {
					$params['fecha'] = $parts[0];
					$params['hora'] = $parts[1];
				}
			}
		}
		
		// Aplicar filtros adicionales proporcionados como parámetro
		if (is_array($filtros_adicionales) && !empty($filtros_adicionales)) {
			$params = array_merge($params, $filtros_adicionales);
		}
		
		// Aplicar filtros adicionales configurados por hook
		$config_filtros = apply_filters('mi_integracion_api_sync_productos_filtros', array());
		if (is_array($config_filtros) && !empty($config_filtros)) {
			$params = array_merge($params, $config_filtros);
		}
		
		// Optimizar los parámetros para la API según documentación
		$params = self::prepare_api_params($params);
		
		\MiIntegracionApi\Helpers\Logger::info(
			'Iniciando sincronización por lotes de productos', 
			array(
				'category' => 'sync-productos-batch',
				'batch_size' => $batch_size,
				'filtros' => $params
			)
		);
		
		// Obtener los productos usando los filtros optimizados
		$memory_before = memory_get_usage();
		$productos = self::get_productos_optimizado( $api_connector, $params );
		$memory_usage = round((memory_get_usage() - $memory_before) / 1024 / 1024, 2);
		
		if ( is_wp_error( $productos ) ) {
			\MiIntegracionApi\Helpers\Logger::error(
				'Error al obtener productos de Verial (batch): ' . $productos->get_error_message(), 
				array( 
					'category' => 'sync-productos-batch',
					'filtros' => $params,
					'memory_mb' => $memory_usage,
					'error_code' => $productos->get_error_code()
				)
			);
			return array(
				'success'   => false,
				'message'   => $productos->get_error_message(),
				'processed' => 0,
				'errors'    => 1,
				'filters'   => $params,
			);
		}
		
		if ( ! is_array( $productos ) || empty( $productos ) ) {
			$duration = round(microtime(true) - $start_time, 2);
			\MiIntegracionApi\Helpers\Logger::info(
				'No se obtuvieron productos de Verial con los filtros aplicados (batch).', 
				array( 
					'category' => 'sync-productos-batch',
					'filtros' => $params,
					'duration' => $duration,
					'memory_mb' => $memory_usage
				)
			);
			return array(
				'success'   => true, // Es éxito pero sin productos
				'message'   => __( 'No se obtuvieron productos de Verial con los filtros aplicados.', 'mi-integracion-api' ),
				'processed' => 0,
				'errors'    => 0,
				'filters'   => $params,
				'duration'  => $duration,
			);
		}
		
		$product_count = count($productos);
		\MiIntegracionApi\Helpers\Logger::info(
			sprintf('Se procesarán %d productos en lotes de %d', $product_count, $batch_size),
			array(
				'category' => 'sync-productos-batch',
				'total_productos' => $product_count,
				'batch_size' => $batch_size,
				'filtros' => $params,
				'memory_mb' => $memory_usage
			)
		);
		
		// Crear el procesador de lotes y procesar los productos
		$batcher = new \MiIntegracionApi\Sync\BatchProcessor( $api_connector );
		
		// Configurar el sistema de recuperación
		$batcher->set_entity_name('productos')
		       ->set_filters($params);
		
		// Verificar si existe un punto de recuperación
		$force_restart = isset($filtros_adicionales['force_restart']) && $filtros_adicionales['force_restart'];
		$recovery_message = '';
		
		if ($batcher->check_recovery_point() && !$force_restart) {
			$recovery_message = $batcher->get_recovery_message();
			\MiIntegracionApi\Helpers\Logger::info(
				'Punto de recuperación detectado para sincronización de productos', 
				array(
					'category' => 'sync-productos-recovery',
					'message' => $recovery_message
				)
			);
		}
		
		// Procesar los productos (con posibilidad de recuperación)
		$result = $batcher->process($productos, $batch_size, null, $force_restart);
		
		// Si hubo un punto de recuperación, añadir el mensaje al resultado
		if (!empty($recovery_message)) {
			$result['recovery_message'] = $recovery_message;
			$result['resumed'] = true;
		}
		
		// Añadir información adicional al resultado
		$result['filters'] = $params;
		$result['memory_mb'] = round(memory_get_peak_usage() / 1024 / 1024, 2);
		$result['duration'] = round(microtime(true) - $start_time, 2);
		
		// Procesar la cola de reintentos al final de la sincronización
		self::process_retry_queue( $api_connector );
		
		\MiIntegracionApi\Helpers\Logger::sync_operation('productos_batch', [
			'total' => $product_count,
			'procesados' => $result['processed'], 
			'errores' => $result['errors'],
			'filtros' => $params,
			'duration' => $result['duration'],
			'memory_peak_mb' => $result['memory_mb'],
			'batch_size' => $batch_size
		], $result['errors'] > 0 ? 'partial' : 'success');
		
		return $result;
	}

	/**
	 * Obtiene los productos de Verial de manera optimizada.
	 * Versión mejorada para aprovechar todos los filtros disponibles en la API
	 * según documentación oficial.
	 *
	 * @param object $api_connector Instancia del conector API.
	 * @param array  $params Parámetros de filtrado.
	 * @return array|\WP_Error Productos o error.
	 */
	private static function get_productos_optimizado( $api_connector, $params = array() ) {
		// Los parámetros ya deben venir optimizados
		$optimized_params = $params;
		
		// Guardar métricas para el log
		$start_time = microtime(true);
		$strategy_used = '';
		$use_cache = apply_filters('mi_integracion_api_use_cache_productos', true);
		$cache_ttl = apply_filters('mi_integracion_api_cache_ttl_productos', 300); // 5 minutos por defecto
		
		// Generar una clave de caché única basada en los parámetros
		$cache_key = 'mia_productos_' . md5(json_encode($optimized_params));
		
		// Verificar si tenemos datos en caché
		if ($use_cache) {
			$cached_data = get_transient($cache_key);
			if ($cached_data !== false) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
				$logger->debug(
					'Productos obtenidos desde caché',
					array(
						'category' => 'sync-productos',
						'count' => count($cached_data),
						'params' => $optimized_params,
						'cache_key' => $cache_key
					)
				);
				return $cached_data;
			}
		}
		
		// Verificar si tenemos la clase ProductApiService para uso eficiente de la API
		if ( class_exists( '\\MiIntegracionApi\\Helpers\\ProductApiService' ) ) {
			$product_service = new \MiIntegracionApi\Helpers\ProductApiService( $api_connector );

			// Primera estrategia: intentar obtener productos usando ProductApiService
			try {
				$productos = $product_service->get_articulos( $optimized_params );
				if ( ! empty( $productos ) ) {
					$strategy_used = 'ProductApiService';
					$execution_time = round((microtime(true) - $start_time) * 1000, 2);
					
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
					$logger->debug(
						'Productos obtenidos con ProductApiService',
						array(
							'category' => 'sync-productos',
							'count' => count($productos),
							'params' => $optimized_params,
							'tiempo_ms' => $execution_time,
							'strategy' => $strategy_used
						)
					);
					
					// Guardar en caché si está habilitado
					if ($use_cache && !empty($productos)) {
						set_transient($cache_key, $productos, $cache_ttl);
					}
					
					return $productos;
				}
			} catch (\Exception $e) {
				\MiIntegracionApi\Helpers\Logger::warning(
					'Error al obtener productos con ProductApiService: ' . $e->getMessage(),
					array(
						'category' => 'sync-productos',
						'params' => $optimized_params,
						'error' => $e->getMessage()
					)
				);
				// Continuar con la siguiente estrategia
			}
		}

		// Segunda estrategia (fallback): usar el método get_articulos directamente
		if ( method_exists( $api_connector, 'get_articulos' ) ) {
			try {
				$productos = $api_connector->get_articulos( $optimized_params );
				if ( ! is_wp_error( $productos ) && ! empty( $productos ) ) {
					$strategy_used = 'ApiConnector::get_articulos';
					$execution_time = round((microtime(true) - $start_time) * 1000, 2);
					
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
					$logger->debug(
						'Productos obtenidos con ApiConnector::get_articulos',
						array(
							'category' => 'sync-productos',
							'count' => count($productos),
							'params' => $optimized_params,
							'tiempo_ms' => $execution_time,
							'strategy' => $strategy_used
						)
					);
					
					// Guardar en caché si está habilitado
					if ($use_cache && !empty($productos)) {
						set_transient($cache_key, $productos, $cache_ttl);
					}
					
					return $productos;
				}
				if ( is_wp_error( $productos ) ) {
					\MiIntegracionApi\Helpers\Logger::warning(
						'Error al obtener productos con ApiConnector::get_articulos: ' . $productos->get_error_message(),
						array(
							'category' => 'sync-productos',
							'error_code' => $productos->get_error_code(),
							'params' => $optimized_params
						)
					);
					// No retornar el error aquí, seguir con la siguiente estrategia
				}
			} catch (\Exception $e) {
				\MiIntegracionApi\Helpers\Logger::warning(
					'Excepción al obtener productos con ApiConnector::get_articulos: ' . $e->getMessage(),
					array(
						'category' => 'sync-productos',
						'params' => $optimized_params,
						'error' => $e->getMessage()
					)
				);
				// Continuar con la siguiente estrategia
			}
		}

		// Tercera estrategia: uso directo del endpoint GetArticulosWS usando GET
		try {
			$response = $api_connector->get( 'GetArticulosWS', $optimized_params );
			if ( is_wp_error( $response ) ) {
				\MiIntegracionApi\Helpers\Logger::warning(
					'Error al obtener productos con endpoint GetArticulosWS: ' . $response->get_error_message(),
					array(
						'category' => 'sync-productos',
						'error_code' => $response->get_error_code(),
						'params' => $optimized_params
					)
				);
				return $response;
			}

			// Extraer productos del formato de respuesta
			if ( isset( $response['Articulos'] ) && is_array( $response['Articulos'] ) ) {
				$strategy_used = 'ApiConnector::get + Articulos';
				$execution_time = round((microtime(true) - $start_time) * 1000, 2);
				
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
				$logger->debug(
					'Productos obtenidos con ApiConnector::get usando formato Articulos',
					array(
						'category' => 'sync-productos',
						'count' => count($response['Articulos']),
						'params' => $optimized_params,
						'tiempo_ms' => $execution_time,
						'strategy' => $strategy_used
					)
				);
				
				// Guardar en caché si está habilitado
				if ($use_cache && !empty($response['Articulos'])) {
					set_transient($cache_key, $response['Articulos'], $cache_ttl);
				}
				
				return $response['Articulos'];
			} elseif ( is_array( $response ) ) {
				$strategy_used = 'ApiConnector::get + array';
				$execution_time = round((microtime(true) - $start_time) * 1000, 2);
				
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
				$logger->debug(
					'Productos obtenidos con ApiConnector::get usando formato array',
					array(
						'category' => 'sync-productos',
						'count' => count($response),
						'params' => $optimized_params,
						'tiempo_ms' => $execution_time,
						'strategy' => $strategy_used
					)
				);
				
				// Guardar en caché si está habilitado
				if ($use_cache && !empty($response)) {
					set_transient($cache_key, $response, $cache_ttl);
				}
				
				return $response;
			}
		} catch (\Exception $e) {
			\MiIntegracionApi\Helpers\Logger::warning(
				'Excepción al obtener productos con endpoint GetArticulosWS: ' . $e->getMessage(),
				array(
					'category' => 'sync-productos',
					'params' => $optimized_params,
					'error' => $e->getMessage()
				)
			);
			// Continuar con el manejo de errores
		}
		
		// Cuarta estrategia: intentar usar un método alternativo de la API GetNumArticulosWS + GetArticulosWS con paginación
		try {
			// Primero verificar cuántos artículos hay con los filtros actuales (sin paginación)
			$count_params = array_diff_key($optimized_params, array_flip(['inicio', 'fin']));
			$count_response = $api_connector->get('GetNumArticulosWS', $count_params);
			
			if (!is_wp_error($count_response) && isset($count_response['NumArticulos'])) {
				$num_articulos = (int)$count_response['NumArticulos'];
				
				if ($num_articulos > 0) {
					$max_per_page = apply_filters('mi_integracion_api_max_articulos_por_page', 100);
					$all_productos = [];
					
					// Usar paginación para obtener todos los productos en lotes
					for ($offset = 0; $offset < $num_articulos; $offset += $max_per_page) {
						$page_params = $optimized_params;
						$page_params['inicio'] = $offset + 1;
						$page_params['fin'] = min($offset + $max_per_page, $num_articulos);
						
						$page_response = $api_connector->get('GetArticulosWS', $page_params);
						
						if (!is_wp_error($page_response)) {
							$page_productos = isset($page_response['Articulos']) && is_array($page_response['Articulos']) 
								? $page_response['Articulos'] 
								: (is_array($page_response) ? $page_response : []);
								
							if (!empty($page_productos)) {
								$all_productos = array_merge($all_productos, $page_productos);
							}
						}
					}
					
					if (!empty($all_productos)) {
						$strategy_used = 'ApiConnector::get_paginado';
						$execution_time = round((microtime(true) - $start_time) * 1000, 2);
						
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
						$logger->debug(
							'Productos obtenidos con paginación',
							array(
								'category' => 'sync-productos',
								'count' => count($all_productos),
								'params' => $optimized_params,
								'tiempo_ms' => $execution_time,
								'strategy' => $strategy_used,
								'total_articulos' => $num_articulos
							)
						);
						
						// Guardar en caché si está habilitado
						if ($use_cache && !empty($all_productos)) {
							set_transient($cache_key, $all_productos, $cache_ttl);
						}
						
						return $all_productos;
					}
				} else {
					// No hay productos según el contador, es un resultado válido (array vacío)
					\MiIntegracionApi\Helpers\Logger::info(
						'La API informa que no hay productos con los filtros aplicados (GetNumArticulosWS)',
						array(
							'category' => 'sync-productos',
							'params' => $optimized_params
						)
					);
					return [];
				}
			}
		} catch (\Exception $e) {
			\MiIntegracionApi\Helpers\Logger::warning(
				'Excepción al intentar estrategia de paginación: ' . $e->getMessage(),
				array(
					'category' => 'sync-productos',
					'params' => $optimized_params,
					'error' => $e->getMessage()
				)
			);
		}

		// Ninguna estrategia funcionó
		$tiempo_total = round((microtime(true) - $start_time) * 1000, 2);
		\MiIntegracionApi\Helpers\Logger::error(
			'No se pudieron obtener productos del API utilizando ningún método disponible',
			array(
				'category' => 'sync-productos',
				'params' => $optimized_params,
				'tiempo_ms' => $tiempo_total,
				'estrategias_fallidas' => [
					'ProductApiService', 
					'ApiConnector::get_articulos', 
					'ApiConnector::get',
					'ApiConnector::get_paginado'
				]
			)
		);
		
		return new \WP_Error(
			'no_products_found',
			__( 'No se pudieron obtener productos del API utilizando ningún método disponible. Revise la configuración y los filtros aplicados.', 'mi-integracion-api' )
		);
	}
	
	/**
	 * Prepara los parámetros optimizados para la API de Verial.
	 * 
	 * @param array $params Parámetros originales.
	 * @return array Parámetros optimizados según documentación oficial de Verial.
	 */
	private static function prepare_api_params($params) {
		$optimized = $params;
		
		// Formatear fecha si está presente para asegurar compatibilidad con API de Verial
		// Según documentación, formato requerido: YYYY-MM-DD
		if (isset($optimized['fecha']) && !empty($optimized['fecha'])) {
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $optimized['fecha'])) {
				$date = date_create($optimized['fecha']);
				if ($date) {
					$optimized['fecha'] = date_format($date, 'Y-m-d');
				}
			}
			
			// Si también tenemos una hora específica, aprovechar el parámetro 'hora'
			// que acepta la API desde versión 1.7.1 (documentado)
			if (isset($optimized['hora']) && !empty($optimized['hora'])) {
				// Asegurarse que la hora está en formato correcto (HH:MM:SS)
				if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $optimized['hora'])) {
					// Intentar convertir al formato correcto
					$time = strtotime($optimized['hora']);
					if ($time !== false) {
						$optimized['hora'] = date('H:i:s', $time);
					}
				}
			} elseif (isset($optimized['fecha_hora']) && !empty($optimized['fecha_hora'])) {
				// Si tenemos fecha_hora como campo combinado, extraer la parte de hora
				$datetime = date_create($optimized['fecha_hora']);
				if ($datetime) {
					$optimized['hora'] = date_format($datetime, 'H:i:s');
					// Ya tenemos fecha separada, no necesitamos fecha_hora
					unset($optimized['fecha_hora']);
				}
			}
		}
		
		// Parámetros de paginación documentados: 'inicio' y 'fin'
		// Añadidos en versión 1.8 según documentación
		if (isset($optimized['page']) && isset($optimized['per_page'])) {
			$page = (int)$optimized['page'];
			$per_page = (int)$optimized['per_page'];
			
			if ($page > 0 && $per_page > 0) {
				$optimized['inicio'] = (($page - 1) * $per_page) + 1;
				$optimized['fin'] = $page * $per_page;
				
				// Eliminar parámetros no estándar para Verial
				unset($optimized['page']);
				unset($optimized['per_page']);
			}
		}
		
		// Límite de resultados global si no se ha establecido paginación
		if (!isset($optimized['inicio']) && !isset($optimized['limit'])) {
			// Valor predeterminado configurable por filtro
			$max_items = apply_filters('mi_integracion_api_max_articulos_por_request', 500);
			
			// Según documentación, usar inicio/fin para limitar resultados
			$optimized['inicio'] = 1;
			$optimized['fin'] = $max_items;
		}
		
		// Parámetro de estado: para productos activos/inactivos
		// Manejar según documentación específica de Verial
		if (!isset($optimized['activo']) && !isset($optimized['inactivo'])) {
			$optimized['activo'] = true; // Por defecto traemos solo productos activos
		}
		
		// Filtro por fecha de modificación
		if (!empty($optimized['fecha_modificacion'])) {
			// Asegurar formato correcto para fecha_modificacion
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $optimized['fecha_modificacion'])) {
				$date = date_create($optimized['fecha_modificacion']);
				if ($date) {
					$optimized['fecha_modificacion'] = date_format($date, 'Y-m-d');
				}
			}
		}
		
		// Filtro por categoría según formato Verial
		if (isset($optimized['categoria_id']) && is_numeric($optimized['categoria_id'])) {
			$optimized['categoriaId'] = (int)$optimized['categoria_id'];
			unset($optimized['categoria_id']); // Usar el nombre correcto del parámetro
		}
		
		// Filtro por SKU o código de producto
		if (!empty($optimized['sku'])) {
			// Si es un array de SKUs, convertirlo a formato aceptado por la API
			if (is_array($optimized['sku'])) {
				$optimized['skus'] = implode(',', $optimized['sku']);
				unset($optimized['sku']);
			}
		}
		
		// Aplicar cualquier configuración global que pueda existir en opciones
		$config = get_option('mi_integracion_api_config_sync', []);
		if (isset($config['filtros_productos']) && is_array($config['filtros_productos'])) {
			foreach ($config['filtros_productos'] as $key => $value) {
				// Solo añadir si no está ya especificado en los parámetros
				if (!isset($optimized[$key])) {
					$optimized[$key] = $value;
				}
			}
		}
		
		// Permitir que desarrolladores modifiquen/añadan parámetros personalizados
		return apply_filters('mi_integracion_api_prepare_articulos_params', $optimized);
	}

	/**
	 * Procesa una lista de productos para actualización o creación.
	 *
	 * @param array $productos Lista de productos a procesar.
	 * @return array Resultado del procesamiento.
	 */
	private static function process_products( $productos ) {
		$processed = 0;
		$errors    = 0;
		$log       = array();

		foreach ( $productos as $producto ) {
			$wc_data = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc( $producto );
			if ( empty( $wc_data['sku'] ) ) {
				++$errors;
				$log[] = __( 'Producto sin SKU, omitido.', 'mi-integracion-api' );
				continue;
			}

			$wc_product_id = wc_get_product_id_by_sku( $wc_data['sku'] );

			// --- Control de duplicados y sincronización incremental reforzado ---
			$verial_fecha_mod = isset( $producto['FechaModificacion'] ) ? $producto['FechaModificacion'] : null;

			// Comprobar duplicados por nombre
			$existing_by_name = get_posts(
				array(
					'post_type'      => 'product',
					'title'          => $wc_data['name'],
					'fields'         => 'ids',
					'posts_per_page' => 2,
					'post_status'    => 'any',
					'meta_query'     => array(
						array(
							'key'     => '_sku',
							'value'   => $wc_data['sku'],
							'compare' => '!=',
						),
					),
				)
			);

			if ( ! empty( $existing_by_name ) ) {
				/* translators: %1$s: nombre del producto, %2$s: IDs existentes */
				$msg   = sprintf(
					__( 'Posible duplicado: producto con nombre "%1$s" pero distinto SKU ya existe (ID: %2$s).', 'mi-integracion-api' ),
					$wc_data['name'],
					implode( ',', $existing_by_name )
				);
				$log[] = $msg;
				\MiIntegracionApi\helpers\Logger::warning( $msg, array( 'context' => 'sync-productos-duplicados' ) );

				// Enviar alerta proactiva si es crítico
				if ( count( $existing_by_name ) > 1 ) {
					wp_mail( get_option( 'admin_email' ), '[Verial/WC] Duplicado crítico de producto', $msg );
				}
			}

			// --- Hash de sincronización con campos clave ---
			$hash_fields = array(
				$wc_data['name'],
				$wc_data['price'],
				isset( $wc_data['stock'] ) ? $wc_data['stock'] : null,
				isset( $wc_data['category_ids'] ) ? implode( ',', $wc_data['category_ids'] ) : '',
				isset( $wc_data['attributes'] ) ? wp_json_encode( $wc_data['attributes'] ) : '',
				isset( $wc_data['meta_data'] ) ? wp_json_encode( $wc_data['meta_data'] ) : '',
				isset( $wc_data['complex_attributes'] ) ? wp_json_encode( $wc_data['complex_attributes'] ) : '',
				isset( $wc_data['bundle'] ) ? wp_json_encode( $wc_data['bundle'] ) : '',
			);

			$hash_actual   = md5( json_encode( $hash_fields ) );
			$hash_guardado = $wc_product_id ? get_post_meta( $wc_product_id, '_verial_sync_hash', true ) : '';

			// Procesar producto existente
			if ( $wc_product_id ) {
				$result = self::update_existing_product( $wc_product_id, $wc_data, $verial_fecha_mod, $hash_actual, $hash_guardado );
				if ( $result['processed'] ) {
					++$processed;
				}
				if ( $result['error'] ) {
					++$errors;
				}
				if ( ! empty( $result['msg'] ) ) {
					$log[] = $result['msg'];
				}
			}
			// Crear nuevo producto
			else {
				$result = self::create_new_product( $wc_data, $hash_actual, $producto );
				if ( $result['processed'] ) {
					++$processed;
				}
				if ( $result['error'] ) {
					++$errors;
				}
				if ( ! empty( $result['msg'] ) ) {
					$log[] = $result['msg'];
				}
			}
		}

		return array(
			'processed' => $processed,
			'errors'    => $errors,
			'log'       => $log,
		);
	}

	/**
	 * Actualiza un producto existente en WooCommerce.
	 *
	 * @param int         $wc_product_id ID del producto en WooCommerce.
	 * @param array       $wc_data Datos del producto mapeados para WooCommerce.
	 * @param string|null $verial_fecha_mod Fecha de modificación en Verial.
	 * @param string      $hash_actual Hash actual calculado.
	 * @param string      $hash_guardado Hash guardado anteriormente.
	 * @return array Resultado de la operación.
	 */
	private static function update_existing_product( $wc_product_id, $wc_data, $verial_fecha_mod, $hash_actual, $hash_guardado ) {
		$processed = false;
		$error     = false;
		$msg       = '';

		$wc_product = wc_get_product( $wc_product_id );
		if ( ! $wc_product ) {
			$error = true;
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Error al cargar producto WooCommerce: %1$s', 'mi-integracion-api' ), $wc_data['sku'] );
			return compact( 'processed', 'error', 'msg' );
		}

		$wc_fecha_mod = $wc_product->get_date_modified() ? $wc_product->get_date_modified()->date( 'Y-m-d H:i:s' ) : null;

		// Verificar si el producto ha cambiado desde la última sincronización
		if ( $verial_fecha_mod && $wc_fecha_mod && strtotime( $verial_fecha_mod ) <= strtotime( $wc_fecha_mod ) ) {
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Producto %1$s omitido (sin cambios desde última sync).', 'mi-integracion-api' ), $wc_data['sku'] );
			return compact( 'processed', 'error', 'msg' );
		}

		// Verificar hash para evitar actualizaciones innecesarias
		if ( $hash_guardado && $hash_actual === $hash_guardado ) {
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Producto %1$s omitido (hash sin cambios).', 'mi-integracion-api' ), $wc_data['sku'] );
			return compact( 'processed', 'error', 'msg' );
		}

		// Actualizar datos básicos del producto
		$wc_product->set_name( $wc_data['name'] );
		$wc_product->set_price( $wc_data['price'] );
		if ( isset( $wc_data['regular_price'] ) ) {
			$wc_product->set_regular_price( $wc_data['regular_price'] );
		}
		if ( isset( $wc_data['sale_price'] ) ) {
			$wc_product->set_sale_price( $wc_data['sale_price'] );
		}
		if ( isset( $wc_data['stock'] ) ) {
			$wc_product->set_stock_quantity( $wc_data['stock'] );
			// Actualizar estado de inventario basado en stock
			$wc_product->set_stock_status( $wc_data['stock'] > 0 ? 'instock' : 'outofstock' );
		}
		if ( isset( $wc_data['description'] ) ) {
			$wc_product->set_description( $wc_data['description'] );
		}
		if ( isset( $wc_data['short_description'] ) ) {
			$wc_product->set_short_description( $wc_data['short_description'] );
		}
		if ( isset( $wc_data['category_ids'] ) ) {
			$wc_product->set_category_ids( $wc_data['category_ids'] );
		}

		// Actualizar metadatos personalizados si existen
		if ( isset( $wc_data['meta_data'] ) && is_array( $wc_data['meta_data'] ) ) {
			foreach ( $wc_data['meta_data'] as $meta_key => $meta_value ) {
				$wc_product->update_meta_data( $meta_key, $meta_value );
			}
		}

		try {
			$wc_product->save();
			update_post_meta( $wc_product_id, '_verial_sync_hash', $hash_actual );
			update_post_meta( $wc_product_id, '_verial_sync_last', current_time( 'mysql' ) );
			$processed = true;
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Producto actualizado: %1$s', 'mi-integracion-api' ), $wc_data['sku'] );
		} catch ( \Exception $e ) {
			self::add_to_retry_queue( $wc_data['sku'], $e->getMessage() );
			$error = true;
			/* translators: %1$s: SKU del producto, %2$s: mensaje de error */
			$msg = sprintf( __( 'Error al guardar producto SKU %1$s: %2$s', 'mi-integracion-api' ), $wc_data['sku'], $e->getMessage() );
		}

		return compact( 'processed', 'error', 'msg' );
	}

	/**
	 * Crea un nuevo producto en WooCommerce.
	 *
	 * @param array  $wc_data Datos del producto mapeados para WooCommerce.
	 * @param string $hash_actual Hash actual calculado.
	 * @param array  $producto_original Datos originales del producto de Verial.
	 * @return array Resultado de la operación.
	 */
	private static function create_new_product( $wc_data, $hash_actual, $producto_original = array() ) {
		$processed = false;
		$error     = false;
		$msg       = '';

		// Primero verificar si podemos evitar una creación duplicada
		if ( method_exists( $api_connector, 'get_articulo_por_sku' ) && ! empty( $producto_original ) ) {
			$articulo_verial = $api_connector->get_articulo_por_sku( $wc_data['sku'] );
			if ( $articulo_verial ) {
				\MiIntegracionApi\helpers\Logger::warning( 'Intento de crear producto ya existente en Verial. Se omitirá. SKU: ' . $wc_data['sku'], array( 'context' => 'sync-productos' ) );
				/* translators: %1$s: SKU del producto */
				$msg = sprintf( __( 'Producto %1$s ya existe en Verial. Se omite creación y se recomienda actualizar.', 'mi-integracion-api' ), $wc_data['sku'] );
				return compact( 'processed', 'error', 'msg' );
			}
		}

		// Verificar que la clase de producto existe
		if ( ! class_exists( '\\WC_Product_Simple' ) ) {
			$error = true;
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Clase WC_Product_Simple no encontrada para SKU: %1$s', 'mi-integracion-api' ), $wc_data['sku'] );
			return compact( 'processed', 'error', 'msg' );
		}

		// Crear el producto
		try {
			$new_product = new \WC_Product_Simple();
			$new_product->set_sku( $wc_data['sku'] );
			$new_product->set_name( $wc_data['name'] );
			$new_product->set_price( $wc_data['price'] );

			if ( isset( $wc_data['regular_price'] ) ) {
				$new_product->set_regular_price( $wc_data['regular_price'] );
			}
			if ( isset( $wc_data['sale_price'] ) ) {
				$new_product->set_sale_price( $wc_data['sale_price'] );
			}
			if ( isset( $wc_data['stock'] ) ) {
				$new_product->set_stock_quantity( $wc_data['stock'] );
				// Configurar estado del stock basado en la cantidad
				$new_product->set_stock_status( $wc_data['stock'] > 0 ? 'instock' : 'outofstock' );
			}
			if ( isset( $wc_data['description'] ) ) {
				$new_product->set_description( $wc_data['description'] );
			}
			if ( isset( $wc_data['short_description'] ) ) {
				$new_product->set_short_description( $wc_data['short_description'] );
			}
			if ( isset( $wc_data['category_ids'] ) ) {
				$new_product->set_category_ids( $wc_data['category_ids'] );
			}

			// Configurar metadatos personalizados si existen
			if ( isset( $wc_data['meta_data'] ) && is_array( $wc_data['meta_data'] ) ) {
				foreach ( $wc_data['meta_data'] as $meta_key => $meta_value ) {
					$new_product->update_meta_data( $meta_key, $meta_value );
				}
			}

			// Guardar el producto
			$new_product_id = $new_product->save();
			update_post_meta( $new_product_id, '_verial_sync_hash', $hash_actual );
			update_post_meta( $new_product_id, '_verial_sync_last', current_time( 'mysql' ) );

			$processed = true;
			/* translators: %1$s: SKU del producto */
			$msg = sprintf( __( 'Producto creado: %1$s', 'mi-integracion-api' ), $wc_data['sku'] );
		} catch ( \Exception $e ) {
			self::add_to_retry_queue( $wc_data['sku'], $e->getMessage() );
			$error = true;
			/* translators: %1$s: SKU del producto, %2$s: mensaje de error */
			$msg = sprintf( __( 'Error al crear producto SKU %1$s: %2$s', 'mi-integracion-api' ), $wc_data['sku'], $e->getMessage() );
		}

		return compact( 'processed', 'error', 'msg' );
	}

	/**
	 * Sincroniza un producto con la API externa
	 * 
	 * @param array $producto Datos del producto a sincronizar
	 * @return array Resultado de la operación
	 */
	public function sync_producto($producto) {
		$operation_id = uniqid('product_sync_');
		$this->metrics->startOperation($operation_id, 'productos', 'push');
		
		try {
			if (empty($producto['sku'])) {
				throw new SyncError('SKU del producto no proporcionado', 400);
			}

			// Verificar memoria antes de procesar
			if (!$this->metrics->checkMemoryUsage($operation_id)) {
				throw new SyncError('Umbral de memoria alcanzado', 500);
			}

			// Ejecutar la sincronización dentro de una transacción
			$result = TransactionManager::getInstance()->executeInTransaction(
				function() use ($producto, $operation_id) {
					return $this->retryOperation(
						function() use ($producto) {
							return $this->sincronizarProducto($producto);
						},
						[
							'operation_id' => $operation_id,
							'sku' => $producto['sku'],
							'product_id' => $producto['id'] ?? null
						]
					);
				},
				'productos',
				$operation_id
			);

			$this->metrics->recordItemProcessed($operation_id, true);
			return [
				'success' => true,
				'message' => 'Producto sincronizado correctamente',
				'data' => $result
			];

		} catch (SyncError $e) {
			$this->metrics->recordError(
				$operation_id,
				'sync_error',
				$e->getMessage(),
				['sku' => $producto['sku'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error sincronizando producto", [
				'sku' => $producto['sku'] ?? 'unknown',
				'error' => $e->getMessage()
			]);
			
			return [
				'success' => false,
				'error' => $e->getMessage(),
				'error_code' => $e->getCode()
			];
		} catch (\Exception $e) {
			$this->metrics->recordError(
				$operation_id,
				'unexpected_error',
				$e->getMessage(),
				['sku' => $producto['sku'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error inesperado sincronizando producto", [
				'sku' => $producto['sku'] ?? 'unknown',
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			return [
				'success' => false,
				'error' => 'Error inesperado: ' . $e->getMessage(),
				'error_code' => $e->getCode()
			];
		}
	}

	/**
	 * Ejecuta una operación con reintentos automáticos
	 * 
	 * @param callable $operation Operación a ejecutar
	 * @param array $context Contexto de la operación
	 * @param int $maxRetries Número máximo de reintentos
	 * @return mixed Resultado de la operación
	 * @throws \Exception Si la operación falla después de todos los reintentos
	 */
	private function retryOperation(callable $operation, array $context = [], int $maxRetries = 3) {
		$attempts = 0;
		$lastError = null;
		
		while ($attempts < $maxRetries) {
			try {
				$result = $operation();
				
				if (is_array($result) && isset($result['success']) && !$result['success']) {
					throw new SyncError(
						$result['error'] ?? 'Error desconocido',
						$result['error_code'] ?? 0,
						$context
					);
				}
				
				return $result;
				
			} catch (SyncError $e) {
				$lastError = $e;
				$attempts++;
				
				$this->metrics->recordError(
					$context['operation_id'] ?? 'unknown',
					'retry_attempt',
					$e->getMessage(),
					array_merge($context, [
						'attempt' => $attempts,
						'max_retries' => $maxRetries,
						'error_code' => $e->getCode()
					]),
					$e->getCode()
				);
				
				$this->logger->warning("Reintentando operación", [
					'attempt' => $attempts,
					'max_retries' => $maxRetries,
					'error' => $e->getMessage(),
					'context' => $context
				]);
				
				if ($attempts >= $maxRetries) {
					break;
				}
				
				$delay = pow(2, $attempts) + rand(0, 1000) / 1000;
				usleep($delay * 1000000);
			}
		}
		
		$this->metrics->recordError(
			$context['operation_id'] ?? 'unknown',
			'max_retries_exceeded',
			$lastError ? $lastError->getMessage() : 'Error desconocido',
			array_merge($context, [
				'attempts' => $attempts,
				'max_retries' => $maxRetries
			]),
			$lastError ? $lastError->getCode() : 0
		);
		
		throw $lastError ?? new SyncError(
			'Error después de ' . $maxRetries . ' reintentos',
			0,
			$context
		);
	}

	public function sincronizar(array $productos): array {
		$resultados = [
			'exitosos' => [],
			'fallidos' => [],
			'omitidos' => []
		];

		foreach ($productos as $producto) {
			try {
				// Sanitizar datos del producto
				$producto = $this->sanitizer->sanitize($producto, 'text');

				// Mapear a DTO
				$producto_dto = MapProduct::verial_to_wc($producto);
				if (!$producto_dto) {
					$this->logger->error('Error al mapear producto', [
						'producto' => $producto
					]);
					$resultados['fallidos'][] = [
						'id' => $producto['id'] ?? 'unknown',
						'error' => 'Datos de producto inválidos'
					];
					continue;
				}

				// Sincronizar con WooCommerce
				$resultado = $this->api_connector->sync_product($producto_dto);
				if ($resultado) {
					$this->logger->info('Producto sincronizado exitosamente', [
						'producto_id' => $producto_dto->id
					]);
					$resultados['exitosos'][] = $producto_dto->id;
				} else {
					$this->logger->error('Error al sincronizar producto', [
						'producto_id' => $producto_dto->id
					]);
					$resultados['fallidos'][] = [
						'id' => $producto_dto->id,
						'error' => 'Error en sincronización'
					];
				}

			} catch (\Exception $e) {
				$this->logger->error('Error al sincronizar producto', [
					'error' => $e->getMessage(),
					'producto' => $producto
				]);
				$resultados['fallidos'][] = [
					'id' => $producto['id'] ?? 'unknown',
					'error' => $e->getMessage()
				];
			}
		}

		return $resultados;
	}

	public function sincronizarProducto(array $producto): bool {
		try {
			// Sanitizar datos del producto
			$producto = $this->sanitizer->sanitize($producto, 'text');

			// Mapear a DTO
			$producto_dto = MapProduct::verial_to_wc($producto);
			if (!$producto_dto) {
				$this->logger->error('Error al mapear producto', [
					'producto' => $producto
				]);
				return false;
			}

			// Sincronizar con WooCommerce
			$resultado = $this->api_connector->sync_product($producto_dto);
			if ($resultado) {
				$this->logger->info('Producto sincronizado exitosamente', [
					'producto_id' => $producto_dto->id
				]);
				return true;
			}

			$this->logger->error('Error al sincronizar producto', [
				'producto_id' => $producto_dto->id
			]);
			return false;

		} catch (\Exception $e) {
			$this->logger->error('Error al sincronizar producto', [
				'error' => $e->getMessage(),
				'producto' => $producto
			]);
			return false;
		}
	}

	public function verificarEstado(int $producto_id): ?string {
		try {
			// Sanitizar ID del producto
			$producto_id = $this->sanitizer->sanitize($producto_id, 'int');

			// Verificar estado de sincronización
			$estado = $this->api_connector->get_product_sync_status($producto_id);
			if ($estado) {
				return $this->sanitizer->sanitize($estado, 'text');
			}

			return null;

		} catch (\Exception $e) {
			$this->logger->error('Error al verificar estado de sincronización', [
				'error' => $e->getMessage(),
				'producto_id' => $producto_id
			]);
			return null;
		}
	}

	public function reintentarSincronizacion(int $producto_id): bool {
		try {
			// Sanitizar ID del producto
			$producto_id = $this->sanitizer->sanitize($producto_id, 'int');

			// Verificar si se puede reintentar
			if (!$this->retry_manager->can_retry('product', $producto_id)) {
				$this->logger->warning('No se puede reintentar la sincronización', [
					'producto_id' => $producto_id
				]);
				return false;
			}

			// Obtener datos del producto
			$producto_data = $this->api_connector->get_product($producto_id);
			if (!$producto_data) {
				$this->logger->error('No se pudieron obtener los datos del producto', [
					'producto_id' => $producto_id
				]);
				return false;
			}

			// Intentar sincronización
			$resultado = $this->sincronizarProducto($producto_data);
			if ($resultado) {
				$this->retry_manager->mark_success('product', $producto_id);
				return true;
			}

			$this->retry_manager->mark_failure('product', $producto_id);
			return false;

		} catch (\Exception $e) {
			$this->logger->error('Error al reintentar sincronización', [
				'error' => $e->getMessage(),
				'producto_id' => $producto_id
			]);
			return false;
		}
	}
}
