<?php
namespace MiIntegracionApi\Sync;

/**
 * Clase para la sincronización de productos/artículos entre WooCommerce y Verial.
 */

// NOTA: Preferencia de desarrollo
// Si hace falta crear un archivo nuevo para lógica de sync, helpers, etc., se debe crear, nunca mezclar código en archivos que no corresponden. Esto asegura mantenibilidad profesional.

class SyncProductos {
	const RETRY_OPTION = 'mia_sync_productos_retry';
	const MAX_RETRIES  = 3;
	const RETRY_DELAY  = 300; // segundos entre reintentos (5 min)

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
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'woocommerce_missing', __( 'WooCommerce no está activo.', 'mi-integracion-api' ) );
		}
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return new \WP_Error( 'sync_locked', __( 'Ya hay una sincronización en curso o falta MI_Sync_Lock.', 'mi-integracion-api' ) );
		}
		if ( ! class_exists( '\\MiIntegracionApi\\Helpers\\MapProduct' ) ) {
			return array(
				'error'   => true,
				'message' => __( 'Clase MapProduct no disponible.', 'mi-integracion-api' ),
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
		$logger = new \MiIntegracionApi\Helpers\Logger('sync-batch');
		$logger->info('Iniciando sincronización por lotes', [
			'fecha_desde' => $fecha_desde,
			'batch_size' => $batch_size,
			'filtros' => $filtros_adicionales
		]);
		
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
				$logger->debug('Fecha con hora detectada, usando filtros separados', [
					'fecha' => $params['fecha'], 
					'hora' => $params['hora'] ?? 'no_disponible'
				]);
			}
		}
		
		// Aplicar filtros adicionales proporcionados como parámetro
		if (is_array($filtros_adicionales) && !empty($filtros_adicionales)) {
			$params = array_merge($params, $filtros_adicionales);
			$logger->debug('Aplicando filtros adicionales', ['filtros' => $filtros_adicionales]);
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
		
		// MEJORA: Procesar los productos con callback avanzado y manejo de errores robusto
		$result = $batcher->process($productos, $batch_size, function($producto) use ($api_connector, $logger) {
			// VALIDACIÓN: Verificar que el producto tenga un SKU válido (ReferenciaBarras)
			if (empty($producto['ReferenciaBarras'])) {
				$identificador = !empty($producto['Codigo']) ? $producto['Codigo'] : __('Producto sin código', 'mi-integracion-api');
				$logger->warning('Producto sin ReferenciaBarras/SKU detectado', [
					'codigo' => $identificador,
					'product_data' => array_intersect_key($producto, array_flip(['Codigo', 'Nombre', 'Descripcion']))
				]);
				return new \WP_Error(
					'missing_sku',
					sprintf(__('Error: Producto %s sin SKU (ReferenciaBarras) - Omitido', 'mi-integracion-api'), $identificador)
				);
			}
			
			try {
				// Procesamiento con validación avanzada
				$sku = sanitize_text_field($producto['ReferenciaBarras']);
				
				// Usar el nuevo método de procesamiento individual mejorado
				$result = self::process_single_product($api_connector, $producto, $sku);
				
				if (is_wp_error($result)) {
					// Registrar el error detallado
					$logger->error(sprintf('Error al procesar producto %s: %s', $sku, $result->get_error_message()), [
						'error_code' => $result->get_error_code(),
						'sku' => $sku
					]);
					
					// Añadir a la cola de reintentos si el error es recuperable
					if (!in_array($result->get_error_code(), ['invalid_product', 'missing_sku'])) {
						self::add_to_retry_queue($sku, $result->get_error_message());
					}
					
					return $result;
				}
				
				// Eliminar de la cola de reintentos si existe y fue exitoso
				self::remove_from_retry_queue($sku);
				$logger->debug(sprintf('Producto %s procesado exitosamente', $sku));
				
				return true;
			} catch (\Exception $e) {
				// Manejo de excepciones no esperadas
				$logger->error(sprintf('Excepción al procesar producto: %s', $e->getMessage()), [
					'trace' => $e->getTraceAsString(),
					'producto' => $producto['ReferenciaBarras'] ?? 'desconocido'
				]);
				
				return new \WP_Error(
					'batch_processing_exception',
					sprintf(__('Excepción: %s', 'mi-integracion-api'), $e->getMessage())
				);
			}
		}, $force_restart);
		
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
	 * Obtiene productos desde Verial de manera optimizada con manejo de errores mejorado
	 * 
	 * @param object $api_connector Instancia del conector API
	 * @param array $params Parámetros para la API
	 * @return array|WP_Error Productos obtenidos o error
	 */
	private static function get_productos_optimizado($api_connector, $params) {
		// Implementar estrategia de reintentos para la API
		$max_retries = 3;
		$retry_count = 0;
		$delay = 2; // segundos iniciales
		
		while ($retry_count < $max_retries) {
			try {
				\MiIntegracionApi\Helpers\Logger::debug(
					'Obteniendo productos desde Verial', 
					array('category' => 'sync-productos-api', 'params' => $params, 'intento' => $retry_count + 1)
				);
				
				// MEJORA: Usar API de búsqueda avanzada con fallbacks
				$productos = null;
				
				// 1. Primero intentar con los parámetros proporcionados
				$productos = $api_connector->get_articulos($params);
				
				// 2. Si falla, intentar búsqueda escalonada por niveles
				if (is_wp_error($productos) || empty($productos)) {
					\MiIntegracionApi\Helpers\Logger::info(
						'Primera búsqueda falló, intentando con estrategia escalonada', 
						array('category' => 'sync-productos-api', 'error' => is_wp_error($productos) ? $productos->get_error_message() : 'Sin resultados')
					);
					
					// Crear copias de los parámetros para diferentes estrategias
					$simplified_params = array_diff_key($params, array_flip(['hora', 'inicio', 'fin']));
					
					// Mantener solo los filtros más importantes
					$minimal_params = [];
					if (!empty($params['fecha'])) {
						$minimal_params['fecha'] = $params['fecha'];
					}
					
					// Intentar con parámetros simplificados
					$productos = $api_connector->get_articulos($simplified_params);
					
					// Si sigue fallando, intentar con parámetros mínimos
					if (is_wp_error($productos) || empty($productos)) {
						$productos = $api_connector->get_articulos($minimal_params);
					}
					
					// Último recurso: intentar búsqueda sin filtros pero con límite
					if (is_wp_error($productos) || empty($productos)) {
						$productos = $api_connector->get_articulos(['inicio' => 1, 'fin' => 200]);
					}
				}
				
				// Si sigue siendo un error, incrementar contador y reintentar
				if (is_wp_error($productos)) {
					$retry_count++;
					\MiIntegracionApi\Helpers\Logger::warning(
						sprintf('Error en la API (intento %d/%d): %s', $retry_count, $max_retries, $productos->get_error_message()),
						array('category' => 'sync-productos-api', 'error_code' => $productos->get_error_code())
					);
					
					if ($retry_count >= $max_retries) {
						return $productos; // Devolver el error después de agotar reintentos
					}
					
					sleep($delay);
					$delay *= 2; // Backoff exponencial
					continue;
				}
				
				if (!is_array($productos)) {
					$productos = [];
				}
				
				return $productos;
			} catch (\Exception $e) {
				$retry_count++;
				\MiIntegracionApi\Helpers\Logger::error(
					sprintf('Excepción al obtener productos (intento %d/%d): %s', $retry_count, $max_retries, $e->getMessage()),
					array('category' => 'sync-productos-api', 'error' => $e->getMessage())
				);
				
				if ($retry_count >= $max_retries) {
					return new \WP_Error(
						'api_exception',
						sprintf(__('Error al obtener productos: %s', 'mi-integracion-api'), $e->getMessage())
					);
				}
				
				sleep($delay);
				$delay *= 2; // Backoff exponencial
			}
		}
		
		// No debería llegar aquí, pero por seguridad
		return new \WP_Error(
			'api_error',
			__('Error desconocido al obtener productos después de reintentos', 'mi-integracion-api')
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
			
			// CRÍTICO: Establecer estado y visibilidad para que aparezca en la tienda
			$new_product->set_status( 'publish' );
			$new_product->set_catalog_visibility( 'visible' );

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
			
			// Log detallado del proceso de guardado
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos');
				if (!$new_product_id) {
					$logger->error('Error al guardar producto en SyncProductos', [
						'sku' => $wc_data['sku'],
						'errors' => $new_product->get_error_data()
					]);
				} else {
					$logger->info('Producto guardado exitosamente en SyncProductos', [
						'product_id' => $new_product_id,
						'sku' => $wc_data['sku'],
						'status' => $new_product->get_status(),
						'visibility' => $new_product->get_catalog_visibility()
					]);
				}
			}
			
			update_post_meta( $new_product_id, '_verial_sync_hash', $hash_actual );
			update_post_meta( $new_product_id, '_verial_sync_last', current_time( 'mysql' ) );

			// Verificación final: confirmar que el producto existe y está publicado
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-productos-verify');
				$saved_product = wc_get_product($new_product_id);
				if ($saved_product) {
					$logger->info('Verificación post-guardado exitosa', [
						'product_id' => $new_product_id,
						'sku' => $saved_product->get_sku(),
						'name' => $saved_product->get_name(),
						'status' => $saved_product->get_status(),
						'visibility' => $saved_product->get_catalog_visibility(),
						'url' => $saved_product->get_permalink()
					]);
				} else {
					$logger->error('Producto no encontrado después del guardado', [
						'product_id' => $new_product_id,
						'sku' => $wc_data['sku']
					]);
				}
			}

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
	 * Procesa un producto individual durante la sincronización
	 * 
	 * @param object $api_connector Instancia del conector API
	 * @param array $producto Datos del producto
	 * @param string $sku SKU del producto
	 * @return bool|WP_Error True si se procesa correctamente, WP_Error en caso de error
	 */
	private static function process_single_product($api_connector, $producto, $sku) {
		// VALIDACIÓN INICIAL: Verificar SKU válido
		if (empty($sku)) {
			return new \WP_Error(
				'missing_sku',
				__('El producto no tiene SKU (ReferenciaBarras)', 'mi-integracion-api')
			);
		}
		
		// Paso 1: Verificar si ya existe un producto con este SKU
		$product_id = wc_get_product_id_by_sku($sku);
		$product = $product_id ? wc_get_product($product_id) : null;
		
		// Si no existe, verificar si debemos crearlo
		if (!$product) {
			// Verificar si la configuración permite la creación automática
			$create_new = apply_filters('mi_integracion_api_allow_product_creation', true);
			
			if (!$create_new) {
				return new \WP_Error(
					'product_not_found',
					sprintf(__('No existe un producto con SKU "%s" y la creación automática está desactivada', 'mi-integracion-api'), $sku)
				);
			}
			
			// Crear nuevo producto utilizando la función de mapeo
			try {
				$wc_data = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc($producto);
				
				// Crear producto nuevo según el tipo detectado
				if (!empty($wc_data['type']) && $wc_data['type'] === 'variable') {
					$product = new \WC_Product_Variable();
				} else {
					$product = new \WC_Product();
				}
				
				// Asignar SKU
				$product->set_sku($sku);
				
				// Registrar la creación
				\MiIntegracionApi\Helpers\Logger::info(
					sprintf('Creando nuevo producto con SKU "%s"', $sku),
					array('category' => 'sync-productos-create')
				);
			} catch (\Exception $e) {
				return new \WP_Error(
					'product_creation_error',
					sprintf(__('Error al crear el producto: %s', 'mi-integracion-api'), $e->getMessage())
				);
			}
		}
		
		// PASO 2: Actualizar datos del producto
		try {
			// Mapear datos Verial a WooCommerce usando la clase de mapeo
			$wc_data = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc($producto);
			
			// Aplicar los datos al producto
			if (!empty($wc_data['name'])) {
				$product->set_name($wc_data['name']);
			}
			
			if (!empty($wc_data['description'])) {
				$product->set_description($wc_data['description']);
			}
			
			if (!empty($wc_data['short_description'])) {
				$product->set_short_description($wc_data['short_description']);
			}
			
			if (isset($wc_data['price'])) {
				$product->set_price($wc_data['price']);
				$product->set_regular_price($wc_data['price']);
			}
			
			if (isset($wc_data['sale_price'])) {
				$product->set_sale_price($wc_data['sale_price']);
			}
			
			if (isset($wc_data['stock'])) {
				$product->set_stock_quantity($wc_data['stock']);
				$product->set_stock_status($wc_data['stock'] > 0 ? 'instock' : 'outofstock');
			}
			
			if (isset($wc_data['manage_stock'])) {
				$product->set_manage_stock($wc_data['manage_stock']);
			}
			
			// Aplicar categorías si están definidas
			if (!empty($wc_data['categories'])) {
				$product->set_category_ids($wc_data['categories']);
			}
			
			// Guardar atributos del producto
			if (!empty($wc_data['attributes'])) {
				$product->set_attributes($wc_data['attributes']);
			}
			
			// Guardar metadatos adicionales
			if (!empty($wc_data['meta_data'])) {
				foreach ($wc_data['meta_data'] as $key => $value) {
					$product->update_meta_data($key, $value);
				}
			}
			
			// Guardar el producto
			$product_id = $product->save();
			
			if (!$product_id) {
				throw new \Exception(__('Error al guardar el producto en la base de datos', 'mi-integracion-api'));
			}
			
			return true;
		} catch (\Exception $e) {
			return new \WP_Error(
				'product_update_error',
				sprintf(__('Error al actualizar el producto %s: %s', 'mi-integracion-api'), $sku, $e->getMessage())
			);
		}
	}

	public function sync_producto( $producto ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// ...lógica...
	}
}
