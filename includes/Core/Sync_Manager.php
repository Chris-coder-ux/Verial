<?php
declare(strict_types=1);

/**
 * Gestor de sincronización bidireccional entre WooCommerce y Verial
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para gestionar la sincronización entre WooCommerce y Verial ERP
 */
class Sync_Manager {
	/**
	 * Clave de opción para almacenar el estado de sincronización
	 */
	const SYNC_STATUS_OPTION = 'mi_integracion_api_sync_status';

	/**
	 * Clave de opción para almacenar el historial de sincronización
	 */
	const SYNC_HISTORY_OPTION = 'mi_integracion_api_sync_history';

	/**
	 * Instancia del gestor de configuración.
	 *
	 * @var Config_Manager
	 */
	private $config_manager;

	/**
	 * Instancia única de la clase
	 *
	 * @var Sync_Manager
	 */
	private static $instance = null;

	/**
	 * Cliente de API de Verial
	 *
	 * @var \MiIntegracionApi\Core\ApiConnector
	 */
	private $api_connector;

	/**
	 * Estado actual de sincronización
	 *
	 * @var array
	 */
	private $sync_status = null;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->api_connector = function_exists( 'mi_integracion_api_get_connector' )
			? \MiIntegracionApi\Helpers\ApiHelpers::get_connector()
			: new \MiIntegracionApi\Core\ApiConnector();
		$this->config_manager = Config_Manager::get_instance();
		$this->load_sync_status();
	}

	/**
	 * Obtiene la instancia única de la clase
	 *
	 * @return Sync_Manager
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Carga el estado actual de sincronización
	 */
	private function load_sync_status() {
		if ( $this->sync_status === null ) {
			$this->sync_status = get_option(
				self::SYNC_STATUS_OPTION,
				array(
					'last_sync'    => array(
						'products' => array(
							'wc_to_verial' => 0,
							'verial_to_wc' => 0,
						),
						'orders'   => array(
							'wc_to_verial' => 0,
							'verial_to_wc' => 0,
						),
					),
					'current_sync' => array(
						'in_progress'   => false,
						'entity'        => '',
						'direction'     => '',
						'batch_size'    => (int) $this->config_manager->get('mia_sync_batch_size', 100),
						'current_batch' => 0,
						'total_batches' => 0,
						'items_synced'  => 0,
						'total_items'   => 0,
						'errors'        => 0,
						'start_time'    => 0,
						'last_update'   => 0,
					),
				)
			);
		}
	}

	/**
	 * Guarda el estado de sincronización actual
	 */
	private function save_sync_status() {
		update_option( self::SYNC_STATUS_OPTION, $this->sync_status, true );
	}

	/**
	 * Agrega un registro al historial de sincronización
	 *
	 * @param array $sync_data Datos de la sincronización
	 */
	private function add_to_history( $sync_data ) {
		$history = get_option( self::SYNC_HISTORY_OPTION, array() );

		// Limitar el tamaño del historial a 100 registros
		if ( count( $history ) >= 100 ) {
			array_shift( $history );
		}

		$history[] = $sync_data;

		update_option( self::SYNC_HISTORY_OPTION, $history, true );
	}

	/**
	 * Obtiene el estado actual de sincronización
	 *
	 * @return array Estado actual de sincronización
	 */
	public function get_sync_status() {
		$this->load_sync_status();
		return $this->sync_status;
	}

	/**
	 * Obtiene el historial de sincronizaciones
	 *
	 * @param int $limit Número máximo de registros a devolver
	 * @return array Historial de sincronizaciones
	 */
	public function get_sync_history( $limit = 100 ) {
		$history = get_option( self::SYNC_HISTORY_OPTION, array() );
		return array_slice( $history, -$limit );
	}

	/**
	 * Reintenta la sincronización para un conjunto de errores específicos.
	 *
	 * @param array $error_ids IDs de los errores a reintentar.
	 * @return array|WP_Error Resultado de la operación.
	 */
	public function retry_sync_errors( array $error_ids ): array|WP_Error {
		global $wpdb;
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		$ids_placeholder = implode( ',', array_fill( 0, count( $error_ids ), '%d' ) );
		$sql = $wpdb->prepare( "SELECT item_data FROM {$table_name} WHERE id IN ($ids_placeholder)", $error_ids );
		$results = $wpdb->get_col( $sql );

		if ( empty( $results ) ) {
			return new WP_Error( 'no_errors_found', __( 'No se encontraron los errores especificados para reintentar.', 'mi-integracion-api' ) );
		}

		$products_to_retry = array_map( 'json_decode', $results, array_fill(0, count($results), true) );

		// Aquí se podría iniciar un proceso de sincronización especial
		// Por simplicidad, de momento solo devolvemos los productos a reintentar.
		return [
			'status' => 'retry_initiated',
			'item_count' => count($products_to_retry),
			'items' => $products_to_retry,
		];
	}

	/**
	 * Inicia un proceso de sincronización
	 *
	 * @param string $entity Entidad a sincronizar ('products' o 'orders')
	 * @param string $direction Dirección de la sincronización ('wc_to_verial' o 'verial_to_wc')
	 * @param array  $filters Filtros adicionales para la sincronización
	 * @return array|WP_Error Resultado de la operación
	 */
	public function start_sync( $entity, $direction, $filters = array() ) {
		$this->load_sync_status();

		// Verificar si ya hay una sincronización en progreso
		if ( $this->sync_status['current_sync']['in_progress'] ) {
			return new \WP_Error(
				'sync_in_progress',
				__( 'Ya hay una sincronización en progreso.', 'mi-integracion-api' )
			);
		}

		// Validar la entidad
		if ( ! in_array( $entity, array( 'products', 'orders' ) ) ) {
			return new \WP_Error(
				'invalid_entity',
				__( 'Entidad inválida para sincronización.', 'mi-integracion-api' )
			);
		}

		// Validar la dirección
		if ( ! in_array( $direction, array( 'wc_to_verial', 'verial_to_wc' ) ) ) {
			return new \WP_Error(
				'invalid_direction',
				__( 'Dirección de sincronización inválida.', 'mi-integracion-api' )
			);
		}

		// Verificar conexión con Verial
		if ( ! $this->api_connector->has_valid_credentials() ) {
			return new \WP_Error(
				'no_credentials',
				__( 'No hay credenciales válidas para Verial.', 'mi-integracion-api' )
			);
		}

		// Obtener el total de items a sincronizar
		$total_items = $this->count_items_for_sync( $entity, $direction, $filters );

		if ( is_wp_error( $total_items ) ) {
			return $total_items;
		}

		// Calcular el total de lotes
		$batch_size = (int) $this->config_manager->get('mia_sync_batch_size', 100);
		
		// Limitar el tamaño de lote para GetArticulosWS
		if ($entity === 'products' && $direction === 'verial_to_wc') {
			// Obtener tamaño óptimo de lote desde opciones si está disponible
			$optimal_batch_size = get_option('mi_integracion_api_optimal_batch_size', 40);
			
			// Usar un batch size optimizado para la sincronización desde Verial
			// Usar el mínimo entre el configurado, el guardado como óptimo, y el máximo seguro
			$original_batch_size = $batch_size;
			$batch_size = min($batch_size, $optimal_batch_size);
			
			// Registrar ajuste automático del tamaño de lote
			if ($batch_size != $original_batch_size && class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-config');
				$logger->info(
					sprintf('Ajustando tamaño de lote de %d a %d basado en experiencia previa',
						$original_batch_size, $batch_size),
					[
						'entity' => $entity,
						'direction' => $direction,
						'optimal_size_from_options' => $optimal_batch_size
					]
				);
			}
		}
		
		$total_batches = $total_items > 0 ? (int) ceil( $total_items / $batch_size ) : 0;

		// Actualizar el estado de sincronización
		// Generar un ID único para esta ejecución de sincronización
		$sync_run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sync_', true);

		// Inicializar tracking de recuperación y lotes
		delete_transient('mia_sync_completed_batches');
		delete_transient('mia_sync_processed_skus');
		delete_transient('mia_sync_current_batch_offset');
		delete_transient('mia_sync_current_batch_limit');
		
		$this->sync_status['current_sync'] = array(
			'in_progress'   => true,
			'run_id'        => $sync_run_id,
			'entity'        => $entity,
			'direction'     => $direction,
			'batch_size'    => $batch_size,
			'current_batch' => 0,
			'total_batches' => $total_batches,
			'items_synced'  => 0,
			'total_items'   => $total_items,
			'errors'        => 0,
			'filters'       => $filters,
			'start_time'    => time(),
			'last_update'   => time(),
			'recovery_enabled' => true,
			'last_error'    => null,
		);

		$this->save_sync_status();

		// Iniciar el primer lote
		return $this->process_next_batch(false);
	}

	/**
	 * Procesa el siguiente lote de sincronización con soporte de recuperación
	 *
	 * @param bool $recovery_mode Si se está ejecutando en modo recuperación
	 * @return array|WP_Error Resultado de la operación
	 */
	public function process_next_batch($recovery_mode = false) {
		$this->load_sync_status();

		// Verificar si hay una sincronización en progreso
		if (!$this->sync_status['current_sync']['in_progress']) {
			return new \WP_Error(
				'no_sync_in_progress',
				__('No hay una sincronización en progreso.', 'mi-integracion-api')
			);
		}

		// Obtener los datos de la sincronización actual
		$entity = $this->sync_status['current_sync']['entity'];
		$direction = $this->sync_status['current_sync']['direction'];
		$current_batch = $this->sync_status['current_sync']['current_batch'];
		$batch_size = $this->sync_status['current_sync']['batch_size'];
		$filters = isset($this->sync_status['current_sync']['filters']) ? $this->sync_status['current_sync']['filters'] : array();

		// Intentar recuperar desde el último lote fallido si estamos en modo recuperación
		if ($recovery_mode && $entity === 'products' && $direction === 'verial_to_wc') {
			$last_batch_info = $this->get_last_failed_batch();
			if ($last_batch_info) {
				$current_batch = floor($last_batch_info['offset'] / $batch_size);
				
				// Registrar la recuperación
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-recovery');
					$logger->info(
						'Recuperando desde lote fallido',
						[
							'offset' => $last_batch_info['offset'],
							'batch_size' => $batch_size,
							'current_batch' => $current_batch
						]
					);
				}
			}
		}

		// Incrementar el número de lote actual
		++$current_batch;
		$this->sync_status['current_sync']['current_batch'] = $current_batch;
		$this->save_sync_status();

		// Calcular el offset para el lote actual
		$offset = ($current_batch - 1) * $batch_size;
		
		// Registrar inicio del procesamiento del lote
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-batch');
			$logger->info(
				sprintf('Iniciando procesamiento de lote #%d (offset %d, batch_size %d)',
					$current_batch, $offset, $batch_size),
				[
					'entity' => $entity,
					'direction' => $direction,
					'recovery_mode' => $recovery_mode
				]
			);
		}

		// Verificar si este lote ya se completó previamente (para casos de reinicio)
		if ($entity === 'products' && $direction === 'verial_to_wc') {
			$batch_key = $offset . '-' . ($offset + $batch_size);
			$completed_batches = get_transient('mia_sync_completed_batches');
			
			if (is_array($completed_batches) && isset($completed_batches[$batch_key])) {
				// Este lote ya se completó, podemos saltar
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-batch');
					$logger->info(
						sprintf('Lote #%d ya fue completado previamente, saltando', $current_batch),
						[
							'batch_key' => $batch_key,
							'completed_at' => date('Y-m-d H:i:s', $completed_batches[$batch_key])
						]
					);
				}
				
				// Actualizar el estado como si se hubiera procesado
				$this->sync_status['current_sync']['items_synced'] += $batch_size;
				$this->sync_status['current_sync']['last_update'] = time();
				$this->save_sync_status();
				
				// Verificar si se completó la sincronización
				if ($current_batch >= $this->sync_status['current_sync']['total_batches']) {
					return $this->finish_sync();
				}
				
				// Devolver el progreso actual como si se hubiera procesado
				return array(
					'status' => 'in_progress',
					'skipped_batch' => true,
					'progress' => array(
						'current_batch' => $current_batch,
						'total_batches' => $this->sync_status['current_sync']['total_batches'],
						'items_synced' => $this->sync_status['current_sync']['items_synced'],
						'total_items' => $this->sync_status['current_sync']['total_items'],
						'percentage' => floor(($this->sync_status['current_sync']['items_synced'] / $this->sync_status['current_sync']['total_items']) * 100),
					),
				);
			}
		}

		// Actualizar transient de actividad para monitoreo
		set_transient('mia_sync_last_activity', time(), HOUR_IN_SECONDS);

		// Procesar el lote según la entidad y dirección
		if ($entity === 'products') {
			if ($direction === 'wc_to_verial') {
				$result = $this->sync_products_to_verial($offset, $batch_size, $filters);
			} else {
				// Para productos desde Verial, registrar el tamaño original para ajuste
				$original_batch_size = $batch_size;
				
				// Implementación de seguridad adicional: forzar tamaño máximo de lote seguro
				if ($batch_size > 50) {
					if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-safe-batch');
						$logger->warning(
							sprintf('Forzando reducción de tamaño de lote de %d a 50 para prevenir timeouts',
								$batch_size),
							[
								'offset' => $offset,
								'batch' => $current_batch,
								'large_batch_size' => $batch_size
							]
						);
					}
					$batch_size = 50;
				}
				
				$result = $this->sync_products_from_verial($offset, $batch_size, $filters);
				
				// Añadir información sobre el ajuste de lote
				if ($original_batch_size != $batch_size) {
					$result['adjusted_batch_size'] = $batch_size;
					$result['original_batch_size'] = $original_batch_size;
				}
				
				// Primero verificar si el resultado es un error
				if (is_wp_error($result)) {
					// Detectar si es un error de timeout o tamaño de lote demasiado grande
					$error_code = $result->get_error_code();
					$error_data = $result->get_error_data();
					
					// Verificar si debemos intentar una subdivisión automática del lote
					if (($error_code === 'batch_too_large' || $error_code === 'empty_response') &&
						isset($error_data['subdivision_recommended']) && $error_data['subdivision_recommended']) {
						
						// Registrar intento de subdivisión
						if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
							$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-subdivision');
							$logger->warning(
								'Iniciando subdivisión automática del lote debido a timeout persistente',
								[
									'error_code' => $error_code,
									'offset' => $offset,
									'original_batch_size' => $batch_size,
									'suggested_size' => $error_data['suggested_size'] ?? floor($batch_size / 2)
								]
							);
						}
						
						// Calcular nuevo tamaño de lote reducido
						$new_batch_size = $error_data['suggested_size'] ?? floor($batch_size / 2);
						if ($new_batch_size < 10) $new_batch_size = 10; // Mínimo 10 elementos
						
						// Crear un nuevo resultado que indique subdivisión
						return [
							'status' => 'batch_subdivided',
							'message' => __('Lote subdividido automáticamente para evitar timeouts', 'mi-integracion-api'),
							'original_batch_size' => $batch_size,
							'new_batch_size' => $new_batch_size,
							'current_offset' => $offset,
							'suggested_next_steps' => [
								__('Reduzca el tamaño de lote en la configuración a', 'mi-integracion-api') . ' ' . $new_batch_size,
								__('O continúe con la sincronización, que usará el nuevo tamaño automáticamente', 'mi-integracion-api')
							]
						];
					}
					
					// Si no se puede subdividir, devolver el error original
					return $result; // Devolver el error para ser manejado por la función llamadora
				}
				
				// Verificar si el lote fue reducido durante el procesamiento
				if (!is_wp_error($result) && is_array($result) && isset($result['adjusted_batch_size']) && $result['adjusted_batch_size'] < $original_batch_size) {
					// Actualizar el progreso basado en el lote realmente procesado, no el previsto
					if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-batch-adjust');
						$logger->info(
							sprintf('Lote ajustado de %d a %d elementos para prevenir timeouts',
								$original_batch_size, $result['adjusted_batch_size']),
							['batch' => $current_batch]
						);
					}
					
					// Ajustar el número de batch actual para la próxima ejecución
					$batch_progress_ratio = $result['adjusted_batch_size'] / $original_batch_size;
					$current_batch -= (1 - $batch_progress_ratio);
					$this->sync_status['current_sync']['current_batch'] = $current_batch;
					
					// Actualizar tamaño óptimo de lote en opciones para futuras sincronizaciones
					if ($result['adjusted_batch_size'] < $original_batch_size && $result['adjusted_batch_size'] > 0) {
						// Guardar nuevo tamaño de lote óptimo con un pequeño margen de seguridad
						$optimal_batch_size = max(10, floor($result['adjusted_batch_size'] * 0.9));
						
						if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
							$logger = new \MiIntegracionApi\Helpers\Logger('sync-optimization');
							$logger->info(
								sprintf('Actualizando tamaño de lote óptimo para futuras sincronizaciones: %d', $optimal_batch_size),
								[
									'original_size' => $original_batch_size,
									'adjusted_size' => $result['adjusted_batch_size'],
									'optimal_size' => $optimal_batch_size
								]
							);
						}
						
						// Actualizar opción de tamaño de lote
						update_option('mi_integracion_api_optimal_batch_size', $optimal_batch_size);
						
						// Actualizar estado actual
						$this->sync_status['current_sync']['batch_size'] = $result['adjusted_batch_size'];
						$this->save_sync_status();
					}
				}
			}
		} elseif ($direction === 'wc_to_verial') { // orders
			$result = $this->sync_orders_to_verial($offset, $batch_size, $filters);
		} else {
			$result = $this->sync_orders_from_verial($offset, $batch_size, $filters);
		}

		// Verificar errores
		if (is_wp_error($result)) {
			// Guardar el último error para referencia
			$this->sync_status['current_sync']['last_error'] = [
				'code' => $result->get_error_code(),
				'message' => $result->get_error_message(),
				'batch' => $current_batch,
				'offset' => $offset,
				'time' => time()
			];
			$this->save_sync_status();
			
			// Registrar el error para diagnóstico
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-error');
				$logger->error(
					sprintf('Error en lote #%d: %s', $current_batch, $result->get_error_message()),
					[
						'code' => $result->get_error_code(),
						'data' => $result->get_error_data(),
						'offset' => $offset,
						'batch_size' => $batch_size
					]
				);
			}
			
			return $result;
		}

		// Actualizar el estado de la sincronización
		$items_processed = $result['count'] ?? 0;
		$this->sync_status['current_sync']['items_synced'] += $items_processed;
		$this->sync_status['current_sync']['last_update'] = time();
		$this->save_sync_status();
		
		// Registrar éxito del lote
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-batch');
			$logger->info(
				sprintf('Lote #%d completado exitosamente: %d elementos procesados',
					$current_batch, $items_processed),
				[
					'progress' => floor(($this->sync_status['current_sync']['items_synced'] / $this->sync_status['current_sync']['total_items']) * 100) . '%',
					'items_synced' => $this->sync_status['current_sync']['items_synced'],
					'total_items' => $this->sync_status['current_sync']['total_items']
				]
			);
		}

		// Verificar si se completó la sincronización
		if ($current_batch >= $this->sync_status['current_sync']['total_batches']) {
			return $this->finish_sync();
		}

		// Devolver el progreso actual
		return array(
			'status' => 'in_progress',
			'progress' => array(
				'current_batch' => $current_batch,
				'total_batches' => $this->sync_status['current_sync']['total_batches'],
				'items_synced' => $this->sync_status['current_sync']['items_synced'],
				'total_items' => $this->sync_status['current_sync']['total_items'],
				'percentage' => floor(($this->sync_status['current_sync']['items_synced'] / $this->sync_status['current_sync']['total_items']) * 100),
				'last_update' => time(),
			),
			'batch_result' => $result,
		);
	}
	
	/**
	 * Obtiene información del último lote que falló para recuperación
	 *
	 * @return array|false Información del último lote fallido o false si no hay información
	 */
	private function get_last_failed_batch() {
		// Verificar transient con offset del último lote en proceso
		$offset = get_transient('mia_sync_current_batch_offset');
		$limit = get_transient('mia_sync_current_batch_limit');
		$time = get_transient('mia_sync_current_batch_time');
		
		// Si no tenemos información de recuperación, usar el estado actual
		if (false === $offset || false === $limit) {
			return false;
		}
		
		// Verificar si la información no es demasiado antigua (< 24 horas)
		if ($time && (time() - $time) > 86400) {
			// Información demasiado antigua, no usarla
			return false;
		}
		
		return [
			'offset' => (int)$offset,
			'limit' => (int)$limit,
			'time' => $time
		];
	}

	/**
	 * Finaliza el proceso de sincronización actual
	 *
	 * @return array Resultado de la operación
	 */
	public function finish_sync() {
		$this->load_sync_status();

		// Verificar si hay una sincronización en progreso
		if ( ! $this->sync_status['current_sync']['in_progress'] ) {
			return array(
				'status'  => 'no_sync',
				'message' => __( 'No hay una sincronización en progreso.', 'mi-integracion-api' ),
			);
		}

		// Calcular la duración de la sincronización
		$duration = time() - $this->sync_status['current_sync']['start_time'];

		// Crear el registro para el historial
		$history_entry = array(
			'entity'       => $this->sync_status['current_sync']['entity'],
			'direction'    => $this->sync_status['current_sync']['direction'],
			'items_synced' => $this->sync_status['current_sync']['items_synced'],
			'total_items'  => $this->sync_status['current_sync']['total_items'],
			'errors'       => $this->sync_status['current_sync']['errors'],
			'start_time'   => $this->sync_status['current_sync']['start_time'],
			'end_time'     => time(),
			'duration'     => $duration,
		);

		// Actualizar la última sincronización
		$this->sync_status['last_sync'][ $this->sync_status['current_sync']['entity'] ][ $this->sync_status['current_sync']['direction'] ] = time();

		// Añadir estadísticas finales al registro de historial
		$history_entry['run_id'] = $this->sync_status['current_sync']['run_id'] ?? 'unknown';
		$history_entry['batch_size'] = $this->sync_status['current_sync']['batch_size'];
		$history_entry['status'] = 'completed';
		
		// Añadir métricas de rendimiento
		$metrics = $this->get_sync_performance_metrics($history_entry['run_id']);
		$history_entry['performance'] = [
			'items_per_second' => $metrics['items_per_second'] ?? 0,
			'items_per_minute' => $metrics['items_per_minute'] ?? 0,
			'error_rate' => $metrics['error_rate'] ?? 0
		];

		// Restablecer el estado de sincronización actual
		$this->sync_status['current_sync']['in_progress'] = false;

		// Guardar cambios
		$this->save_sync_status();

		// Agregar al historial
		$this->add_to_history( $history_entry );

		return array(
			'status'  => 'completed',
			'message' => __( 'Sincronización completada con éxito.', 'mi-integracion-api' ),
			'summary' => $history_entry,
		);
	}

	/**
	 * Cancela la sincronización actual
	 *
	 * @return array Resultado de la operación
	 */
	public function cancel_sync() {
		$this->load_sync_status();

		// Verificar si hay una sincronización en progreso
		if ( ! $this->sync_status['current_sync']['in_progress'] ) {
			return array(
				'status'  => 'no_sync',
				'message' => __( 'No hay una sincronización en progreso.', 'mi-integracion-api' ),
			);
		}

		// Crear el registro para el historial
		$history_entry = array(
			'entity'       => $this->sync_status['current_sync']['entity'],
			'direction'    => $this->sync_status['current_sync']['direction'],
			'items_synced' => $this->sync_status['current_sync']['items_synced'],
			'total_items'  => $this->sync_status['current_sync']['total_items'],
			'errors'       => $this->sync_status['current_sync']['errors'],
			'start_time'   => $this->sync_status['current_sync']['start_time'],
			'end_time'     => time(),
			'duration'     => time() - $this->sync_status['current_sync']['start_time'],
			'status'       => 'cancelled',
			'run_id'       => $this->sync_status['current_sync']['run_id'] ?? 'unknown',
			'batch_size'   => $this->sync_status['current_sync']['batch_size'],
		);

		// Restablecer el estado de sincronización actual
		$this->sync_status['current_sync']['in_progress'] = false;

		// Guardar cambios
		$this->save_sync_status();

		// Agregar al historial
		$this->add_to_history( $history_entry );

		return array(
			'status'  => 'cancelled',
			'message' => __( 'Sincronización cancelada.', 'mi-integracion-api' ),
			'summary' => $history_entry,
		);
	}

	/**
	 * Cuenta el número de elementos a sincronizar
	 *
	 * @param string $entity Entidad a sincronizar
	 * @param string $direction Dirección de la sincronización
	 * @param array  $filters Filtros adicionales
	 * @return int|WP_Error Número de elementos o error
	 */
	private function count_items_for_sync( $entity, $direction, $filters ) {
		if ( $entity === 'products' ) {
			if ( $direction === 'wc_to_verial' ) {
				return $this->count_woocommerce_products( $filters );
			} else {
				return $this->count_verial_products( $filters );
			}
		} elseif ( $direction === 'wc_to_verial' ) { // orders
				return $this->count_woocommerce_orders( $filters );
		} else {
			return $this->count_verial_orders( $filters );
		}
	}

	/**
	 * Cuenta el número de productos en WooCommerce
	 *
	 * @param array $filters Filtros adicionales
	 * @return int Número de productos
	 */
	private function count_woocommerce_products( $filters ) {
		$args = array(
			'status' => 'publish',
			'limit'  => 1,
			'return' => 'ids',
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['categories'] ) ) {
			$args['category'] = $filters['categories'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Contar productos
		$products = wc_get_products( $args );

		return $products->total;
	}

	/**
	 * Cuenta el número de productos en Verial
	 *
	 * @param array $filters Filtros adicionales
	 * @return int|WP_Error Número de productos o error
	 */
	/**
	 * Cuenta el número de productos en Verial usando GetNumArticulosWS
	 * Implementa soporte completo para fecha y hora según manual V1.7.1+
	 *
	 * @param array $filters Filtros adicionales
	 * @return int|WP_Error Número de productos o error
	 */
	private function count_verial_products( $filters ) {
		// Crear parámetros para la consulta con soporte para fecha y hora
		$params = array();

		// Soporte para filtros de fecha con formato adecuado
		if ( ! empty( $filters['modified_after'] ) ) {
			$params['fecha'] = date( 'Y-m-d', $filters['modified_after'] );
			
			// Si hay hora específica, añadirla (soporte para V1.7.1+)
			if (!empty($filters['modified_after_time'])) {
				$params['hora'] = $filters['modified_after_time'];
			} else {
				// Si tenemos timestamp completo, extraer la hora también
				$params['hora'] = date('H:i:s', $filters['modified_after']);
			}
		}

		// Registrar llamada para diagnóstico
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-count');
			$logger->info(
				'Contando productos Verial',
				[
					'params' => $params,
					'filters' => $filters
				]
			);
		}

		// Llamar al método GetNumArticulosWS para obtener el total
		$response = $this->api_connector->get( 'GetNumArticulosWS', $params );

		if ( is_wp_error( $response ) ) {
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-count-error');
				$logger->error(
					'Error al contar productos Verial',
					[
						'error' => $response->get_error_message(),
						'params' => $params
					]
				);
			}
			return $response;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		// Verificar errores específicos de API Verial antes de procesar
		if (isset($data['InfoError']) && isset($data['InfoError']['Codigo']) && $data['InfoError']['Codigo'] != 0) {
			$error_message = $data['InfoError']['Descripcion'] ?? __('Error desconocido desde la API de Verial', 'mi-integracion-api');
			
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					$error_message,
					[
						'codigo' => $data['InfoError']['Codigo'],
						'params' => $params
					]
				);
			}
			
			return new WP_Error(
				'verial_api_error_' . $data['InfoError']['Codigo'],
				$error_message,
				[ 'response_body' => $body ]
			);
		}

		if ( ! isset( $data['Numero'] ) ) {
			$error_message = __( 'Respuesta inválida del servidor al contar productos. La clave "Numero" no fue encontrada.', 'mi-integracion-api' );
			// Registrar el cuerpo de la respuesta para depuración
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					$error_message,
					[
						'context'  => 'count_verial_products',
						'response_body' => $body,
						'params' => $params
					]
				);
			}
			return new WP_Error( 'invalid_response', $error_message, [ 'response_body' => $body ] );
		}

		// Registrar resultado exitoso
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-count');
			$logger->info(
				'Conteo de productos Verial completado',
				[
					'total' => (int) $data['Numero'],
					'params' => $params
				]
			);
		}

		return (int) $data['Numero'];
	}

	/**
	 * Cuenta el número de pedidos en WooCommerce
	 *
	 * @param array $filters Filtros adicionales
	 * @return int Número de pedidos
	 */
	private function count_woocommerce_orders( $filters ) {
		$args = array(
			'limit'  => 1,
			'return' => 'ids',
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['status'] ) ) {
			$args['status'] = $filters['status'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Contar pedidos
		$query  = new \WC_Order_Query( $args );
		$orders = $query->get_orders();

		return $query->total;
	}

	/**
	 * Cuenta el número de pedidos en Verial
	 *
	 * @param array $filters Filtros adicionales
	 * @return int|WP_Error Número de pedidos o error
	 */
	private function count_verial_orders( $filters ) {
		// Esta función necesitaría implementarse según la API de Verial
		// Por ahora, devolvemos un valor estático para demostración
		return 100;
	}

	/**
	 * Sincroniza productos desde WooCommerce a Verial
	 *
	 * @param int   $offset Offset para la consulta
	 * @param int   $limit Límite de productos a procesar
	 * @param array $filters Filtros adicionales
	 * @return array|WP_Error Resultado de la operación
	 */
	private function sync_products_to_verial( $offset, $limit, $filters ) {
		// Obtener productos de WooCommerce
		$args = array(
			'status' => 'publish',
			'limit'  => $limit,
			'offset' => $offset,
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['categories'] ) ) {
			$args['category'] = $filters['categories'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Obtener productos
		$products = wc_get_products( $args );

		// Procesar cada producto
		$processed = 0;
		$errors    = array();

		foreach ( $products as $product ) {
			// Mapear producto de WooCommerce a formato de Verial
			$verial_product = $this->map_wc_product_to_verial( $product );

			// Enviar a Verial
			// TODO: Implementar la lógica específica según la API de Verial

			++$processed;
		}

		return array(
			'count'  => $processed,
			'errors' => $errors,
		);
	}

	/**
	 * Sincroniza productos desde Verial a WooCommerce
	 *
	 * @param int   $offset Offset para la consulta
	 * @param int   $limit Límite de productos a procesar
	 * @param array $filters Filtros adicionales
	 * @return array|WP_Error Resultado de la operación
	 */
	/**
	 * Sincroniza productos desde Verial a WooCommerce usando paginación robusta
	 * con el sistema de inicio/fin según manual v1.8+
	 *
	 * @param int   $offset Offset para la consulta
	 * @param int   $limit Límite de productos a procesar
	 * @param array $filters Filtros adicionales
	 * @return array|WP_Error Resultado de la operación
	 */
	private function sync_products_from_verial( $offset, $limit, $filters ) {
		// IMPORTANTE: Verificar y ajustar límite para prevenir timeouts
		$max_safe_batch_size = 40; // Reducido a 40 para evitar timeouts incluso en servidores con recursos limitados
		$original_limit = $limit;
		
		if ($limit > $max_safe_batch_size) {
			// Ajustar el límite para esta ejecución
			$limit = $max_safe_batch_size;
			
			// Registrar ajuste automático
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
				$logger->warning(
					sprintf('Limitando tamaño de lote de %d a %d para prevenir timeouts',
						$original_limit, $limit),
					[
						'offset' => $offset,
						'original_limit' => $original_limit,
						'adjusted_limit' => $limit
					]
				);
			}
		}
		
		// Guardar progreso actual para recuperación
		$this->save_sync_batch_state($offset, $limit);
		
		// Calcular parámetros de paginación
		$inicio = $offset + 1; // API Verial comienza en 1
		$fin = $offset + $limit;
		
		// Crear parámetros para la consulta con soporte completo para filtros
		// Verificar si el tamaño del lote es muy grande y posiblemente problemático
		$batch_size = $fin - $inicio + 1;
		if ($batch_size > 75) {
			// Registrar advertencia sobre tamaño de lote
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
				$logger->warning(
					sprintf('Tamaño de lote grande (%d) podría causar problemas. Considere reducirlo.', $batch_size),
					[
						'inicio' => $inicio,
						'fin' => $fin,
						'batch_size' => $batch_size
					]
				);
			}
		}
		
		// Asegurarnos de que los parámetros estén correctamente formateados y sean consistentes
		$params = array(
			'inicio' => (int)$inicio,
			'fin'    => (int)$fin,
			// Incluir explícitamente la sesión para evitar problemas con parámetros
			'sesionwcf' => $this->api_connector->get_session_number(),
		);

		// Soporte para filtro de fecha y hora
		if ( ! empty( $filters['modified_after'] ) ) {
			$params['fecha'] = date( 'Y-m-d', $filters['modified_after'] );
			
			// Si hay hora específica, añadirla (soporte para V1.7.1+)
			if (!empty($filters['modified_after_time'])) {
				$params['hora'] = $filters['modified_after_time'];
			} else {
				// Si tenemos timestamp completo, extraer la hora
				$params['hora'] = date('H:i:s', $filters['modified_after']);
			}
		}
		
		// Registrar inicio de sincronización del lote
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
			$logger->info(
				sprintf('Sincronizando lote de productos (inicio=%d, fin=%d)', $inicio, $fin),
				[
					'params' => $params,
					'offset' => $offset,
					'limit' => $limit,
					'filters' => $filters
				]
			);
		}

		// Obtener productos de Verial con retry en caso de error
		$max_retries = 3;
		$retry_count = 0;
		$response = null;
		$last_error = null;
		
		while ($retry_count < $max_retries) {
			// Intentar obtener productos con opciones avanzadas para diagnóstico
			$api_options = [
				'timeout' => 45,                 // Tiempo de espera extendido para catálogos grandes
				'diagnostics' => true,           // Habilitar información de diagnóstico en respuesta de error
				'trace_request' => true,         // Rastrear detalles completos de la solicitud HTTP
				'retry_transient_errors' => true, // Reintentar automáticamente errores transitorios
				'wp_args' => [                   // Argumentos directos para wp_remote_request
					'timeout' => 45,             // Configurar el mismo timeout en los argumentos de WordPress
					'httpversion' => '1.1',      // Usar HTTP 1.1 para mejor compatibilidad
					'blocking' => true           // Esperar a que se complete la solicitud
				]
			];
			
			// Configurar backoff más agresivo para este lote específico
			if ($retry_count > 0) {
				$api_options['retry_config'] = [
					'max_retries' => 4,          // Un reintento adicional para este lote
					'base_delay' => 2,           // Mayor tiempo base de espera
					'backoff_multiplier' => 2.5, // Crecimiento más rápido del backoff
					'strategy' => 'exponential'  // Estrategia de crecimiento exponencial
				];
			}
			
			$response = $this->api_connector->get( 'GetArticulosWS', $params, $api_options );
			
			// Si no hay error, salir del bucle
			if (!is_wp_error($response)) {
				break;
			}
			
			// Guardar el último error
			$last_error = $response;
			$retry_count++;
			
			// Registrar intento fallido
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-retry');
				$logger->warning(
					sprintf('Intento %d fallido al obtener productos', $retry_count),
					[
						'error' => $response->get_error_message(),
						'params' => $params
					]
				);
			}
			
			// Esperar antes de reintentar (backoff exponencial)
			$wait_time = pow(2, $retry_count) * 1; // 2, 4, 8 segundos
			sleep($wait_time);
		}
		
		// Si después de todos los reintentos sigue habiendo error, devolverlo
		if (is_wp_error($response)) {
			return $response;
		}

		// Recuperar y validar el cuerpo de la respuesta
		$body = wp_remote_retrieve_body( $response );
		$status_code = wp_remote_retrieve_response_code($response);
		$api_url = $this->api_connector->get_last_request_url() ?? 'unknown';
		
		// Verificar si tenemos una respuesta vacía (posible timeout)
		if (empty($body)) {
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					'Respuesta vacía del servidor (posible timeout)',
					[
						'params' => $params,
						'inicio' => $inicio,
						'fin' => $fin,
						'response_status' => $status_code,
						'api_url' => $api_url,
						'retry_count' => $retry_count,
						'timeout' => $api_options['timeout'] ?? 45,
						'batch_size' => $fin - $inicio + 1,
						'headers' => wp_remote_retrieve_headers($response),
						'memory_usage' => memory_get_usage(true),
						'peak_memory' => memory_get_peak_usage(true),
						'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
					]
				);
			}
			
			// Verificar si debemos intentar una subdivisión automática del lote
			if (($fin - $inicio + 1) > 20 && $retry_count >= $max_retries) {
				// Registrar intento de subdivisión
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-subdivision');
					$logger->warning(
						'Intentando subdividir lote para evitar timeout',
						[
							'lote_original' => [$inicio, $fin],
							'tamaño' => $fin - $inicio + 1
						]
					);
				}
				
				// Crear un error especial que indique necesidad de subdivisión
				return new \WP_Error(
					'batch_too_large',
					__('Timeout persistente. Se recomienda subdividir este lote en unidades más pequeñas.', 'mi-integracion-api'),
					[
						'subdivision_recommended' => true,
						'original_range' => [$inicio, $fin],
						'suggested_size' => max(10, floor(($fin - $inicio + 1) / 2)),
						'params' => $params,
						'status_code' => $status_code
					]
				);
			}
			
			// Mensaje de error según el tamaño del lote
			if (($fin - $inicio + 1) > 35) {
				$error_message = sprintf(
					__('Posible timeout para lote grande (%d-%d). Considere reducir el tamaño de lote a 30 o menos.', 'mi-integracion-api'),
					$inicio, $fin
				);
			} else if ($status_code >= 400) {
				$error_message = sprintf(
					__('Error HTTP %d del servidor. Verifique la configuración de API y conexión.', 'mi-integracion-api'),
					$status_code
				);
			} else {
				$error_message = __('Respuesta vacía del servidor. Posible timeout o error de conexión. Intente con un lote más pequeño.', 'mi-integracion-api');
			}
			
			return new \WP_Error(
				'empty_response',
				$error_message,
				[
					'params' => $params,
					'status_code' => $status_code,
					'api_url' => $api_url,
					'batch_size' => $fin - $inicio + 1,
					'memory_usage' => memory_get_usage(true),
					'retry_count' => $retry_count
				]
			);
		}
		
		// Intentar decodificar el JSON de la respuesta
		$data = json_decode($body, true);
		$json_error = json_last_error();
		
		// Verificar si tenemos un JSON válido
		if ($json_error !== JSON_ERROR_NONE) {
			// Analizar el tipo de error JSON para mejor diagnóstico
			$error_type = json_last_error_msg();
			$truncated_response = false;
			
			// Intentar detectar respuestas truncadas (común en timeouts)
			if (strpos($error_type, 'Syntax error') !== false) {
				// Verificar si parece una respuesta truncada
				if (substr($body, -20) !== '}' && strpos($body, '{"Articulos":') !== false) {
					$truncated_response = true;
				}
			}
			
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					'Error al decodificar respuesta JSON',
					[
						'json_error' => $error_type,
						'body_preview' => substr($body, 0, 1000),
						'body_end' => strlen($body) > 100 ? substr($body, -100) : 'n/a',
						'params' => $params,
						'response_length' => strlen($body),
						'response_status' => $status_code,
						'api_url' => $api_url,
						'truncated_response' => $truncated_response,
						'memory_usage' => memory_get_usage(true),
						'memory_limit' => ini_get('memory_limit')
					]
				);
			}
			
			// Mensaje específico según el tipo de error
			if ($truncated_response) {
				$error_message = __('Respuesta JSON truncada. Probable timeout durante la transferencia de datos. Intente con un lote más pequeño.', 'mi-integracion-api');
			} else if (strpos($error_type, 'Maximum stack depth exceeded') !== false) {
				$error_message = __('Error de anidamiento excesivo en JSON. La respuesta es demasiado compleja.', 'mi-integracion-api');
			} else if (strpos($error_type, 'Memory limit') !== false || strlen($body) > 5000000) {
				$error_message = __('Respuesta demasiado grande para procesar. Considere aumentar memory_limit en PHP.', 'mi-integracion-api');
			} else {
				$error_message = sprintf(__('Error al decodificar respuesta JSON: %s', 'mi-integracion-api'), $error_type);
			}
			
			return new \WP_Error(
				'invalid_json',
				$error_message,
				[
					'response_body_preview' => substr($body, 0, 1000),
					'response_length' => strlen($body),
					'truncated_response' => $truncated_response,
					'params' => $params
				]
			);
		}

		// Comprobación de errores lógicos de la API de Verial ANTES de procesar los datos.
		if (isset($data['InfoError']['Codigo']) && $data['InfoError']['Codigo'] != 0) {
			$error_message = $data['InfoError']['Descripcion'] ?? __('Error desconocido desde la API de Verial.', 'mi-integracion-api');
			
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					$error_message,
					[
						'codigo' => $data['InfoError']['Codigo'],
						'params' => $params,
						'response_status' => $status_code,
						'response_message' => wp_remote_retrieve_response_message($response),
						'response_headers' => wp_remote_retrieve_headers($response),
						'api_url' => $api_url
					]
				);
			}
			
			return new WP_Error(
				'verial_api_error_' . $data['InfoError']['Codigo'],
				$error_message,
				[
					'response_body' => $body,
					'api_url' => $api_url
				]
			);
		}

		// Verificar la presencia de la clave Articulos en la respuesta
		if (!isset($data['Articulos']) || !is_array($data['Articulos'])) {
			// Realizar análisis profundo de la respuesta para determinar la causa real
			$is_empty_response = empty($data) || count($data) <= 1;
			$is_partial_response = false;
			$has_session_data = false;
			$error_code = 'invalid_response';
			
			// Verificar si hay signos de una respuesta parcial o de autenticación
			if (is_array($data)) {
				if (isset($data['InfoError']) || isset($data['Version']) || isset($data['sesionwcf'])) {
					$has_session_data = true;
				}
				
				if ($has_session_data && !isset($data['Articulos'])) {
					$is_partial_response = true;
				}
			}
			
			// Registrar el error con información diagnóstica completa
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-error');
				$logger->error(
					'Respuesta inválida del servidor. La clave "Articulos" no fue encontrada.',
					[
						'response_body' => substr($body, 0, 1000),
						'params' => $params,
						'response_length' => strlen($body),
						'response_keys' => is_array($data) ? array_keys($data) : 'no_data',
						'response_status' => $status_code,
						'api_url' => $api_url,
						'retry_count' => $retry_count,
						'batch_size' => $fin - $inicio + 1,
						'inicio' => $inicio,
						'fin' => $fin,
						'is_empty_response' => $is_empty_response,
						'is_partial_response' => $is_partial_response,
						'has_session_data' => $has_session_data,
						'memory_usage' => memory_get_usage(true),
						'memory_peak' => memory_get_peak_usage(true),
						'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']
					]
				);
			}
			
			// Diagnóstico avanzado basado en el tipo de respuesta
			if ($is_empty_response) {
				$error_message = __('Respuesta prácticamente vacía. Probable timeout durante la transmisión de datos.', 'mi-integracion-api');
				$error_code = 'empty_response';
			} else if ($is_partial_response) {
				$error_message = __('Respuesta parcial del servidor. Se recibió información de sesión pero faltan los datos de artículos.', 'mi-integracion-api');
				$error_code = 'partial_response';
			} else {
				$error_message = __('Respuesta inválida del servidor. La clave "Articulos" no fue encontrada.', 'mi-integracion-api');
			}
			
			// Si hay datos en la respuesta, intentar extraer información útil
			if (is_array($data) && !empty($data)) {
				if (isset($data['Mensaje'])) {
					$error_message .= ' ' . $data['Mensaje'];
				} elseif (isset($data['mensaje'])) {
					$error_message .= ' ' . $data['mensaje'];
				} elseif (isset($data['Error'])) {
					$error_message .= ' ' . (is_string($data['Error']) ? $data['Error'] : json_encode($data['Error']));
				} elseif (isset($data['InfoError']) && isset($data['InfoError']['Descripcion'])) {
					$error_message .= ' ' . $data['InfoError']['Descripcion'];
				}
				
				// Añadir información sobre las claves presentes
				$error_message .= ' ' . sprintf(__('Claves disponibles: %s', 'mi-integracion-api'),
					implode(', ', array_keys($data)));
			}
			
			// Sugerencias específicas basadas en el diagnóstico
			if ($fin - $inicio + 1 > 35) {
				$error_message .= ' ' . sprintf(__('Se recomienda reducir el tamaño del lote a 30 o menos (actual: %d).', 'mi-integracion-api'),
					$fin - $inicio + 1);
			}
			
			if ($is_empty_response || $is_partial_response) {
				$error_message .= ' ' . __('Considere aumentar el tiempo de espera en la configuración del servidor o reducir el tamaño del lote.', 'mi-integracion-api');
			}
			
			return new \WP_Error(
				$error_code,
				$error_message,
				[
					'response_body_preview' => substr($body, 0, 1000),
					'params' => $params,
					'api_url' => $api_url,
					'batch_size' => $fin - $inicio + 1,
					'is_empty_response' => $is_empty_response,
					'is_partial_response' => $is_partial_response,
					'status_code' => $status_code,
					'retry_count' => $retry_count
				]
			);
		}
		
		// Verificar si hay productos en la respuesta
		if (empty($data['Articulos'])) {
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
				$logger->warning(
					'No se encontraron productos en este lote',
					[
						'inicio' => $inicio,
						'fin' => $fin,
						'params' => $params
					]
				);
			}
			
			// Devolver resultado vacío pero válido
			return array(
				'count'  => 0,
				'errors' => 0,
				'empty_batch' => true
			);
		}

		// --- Optimización N+1: Precargar datos del lote ---
		$batch_skus = array_column($data['Articulos'], 'ReferenciaBarras');
		
		$verial_category_ids = [];
		$category_fields = ['ID_Categoria', 'ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'];
		foreach ($data['Articulos'] as $product) {
			foreach ($category_fields as $field) {
				if (!empty($product[$field])) {
					$verial_category_ids[] = (int)$product[$field];
				}
			}
		}
		
		$batch_cache = [
			'product_ids'       => $this->get_product_ids_by_sku($batch_skus),
			'category_mappings' => $this->get_category_mappings(array_unique($verial_category_ids)),
		];
		// --- Fin de la optimización ---

		// Registrar tamaño del lote para diagnóstico
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
			$logger->info(
				sprintf('Procesando lote de %d productos', count($data['Articulos'])),
				[
					'skus' => $batch_skus,
					'inicio' => $inicio,
					'fin' => $fin
				]
			);
		}
		
		// Procesar productos en transacción para mejorar rendimiento
		global $wpdb;
		$wpdb->query('START TRANSACTION');
		$transaction_successful = true;

		// Procesar cada producto
		$processed = 0;
		$errors = 0;
		$success_ids = []; // Almacenar SKUs procesados correctamente

		foreach ( $data['Articulos'] as $verial_product ) {
			// Guardar el producto actual para recuperación
			$this->save_current_product($verial_product);
			
			// Actualizar progreso visual para el usuario
			$this->update_visual_progress($processed, count($data['Articulos']), $verial_product);
			
			try {
				// Mapear producto de Verial a formato de WooCommerce
				$wc_product_data = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc( $verial_product, [], $batch_cache );

				// Crear o actualizar el producto en WooCommerce
				$result = $this->create_or_update_wc_product( $wc_product_data, $batch_cache );

				if ( is_wp_error( $result ) ) {
					$this->log_sync_error(
						$verial_product,
						$result->get_error_code(),
						$result->get_error_message()
					);
					$errors++;
				} else {
					$processed++;
					$success_ids[] = $verial_product['ReferenciaBarras'] ?? $verial_product['Id'];
				}
			} catch (\Throwable $e) {
				// Capturar cualquier excepción durante el procesamiento para evitar interrumpir el lote completo
				if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-product-error');
					$logger->error(
						'Error al procesar producto: ' . $e->getMessage(),
						[
							'exception' => get_class($e),
							'file' => $e->getFile(),
							'line' => $e->getLine(),
							'sku' => $verial_product['ReferenciaBarras'] ?? 'Desconocido',
							'nombre' => $verial_product['Nombre'] ?? 'Desconocido'
						]
					);
				}
				
				$this->log_sync_error(
					$verial_product,
					'exception',
					$e->getMessage()
				);
				$errors++;
				
				// Si hay demasiados errores en este lote, marcar transacción como fallida
				if ($errors > min(count($data['Articulos']) * 0.3, 10)) {
					$transaction_successful = false;
				}
			}
		}
		
		// Completar o revertir transacción según resultado
		if ($transaction_successful) {
			$wpdb->query('COMMIT');
		} else {
			$wpdb->query('ROLLBACK');
			
			if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-transaction');
				$logger->error(
					'Transacción revertida por exceso de errores',
					[
						'lote' => [$inicio, $fin],
						'total_articulos' => count($data['Articulos']),
						'errores' => $errors,
						'procesados' => $processed
					]
				);
			}
		}
		
		// Actualizar el contador de errores en el estado general
		if ($errors > 0) {
			$this->sync_status['current_sync']['errors'] += $errors;
			$this->save_sync_status();
		}
		
		// Guardar SKUs procesados correctamente para posible recuperación
		$this->save_processed_skus($success_ids);
		
		// Marcar este lote como completado
		$this->mark_batch_completed($offset, $limit);
		
		// Registrar finalización del lote
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
			$logger->info(
				sprintf('Lote completado (inicio=%d, fin=%d): %d procesados, %d errores',
					$inicio, $fin, $processed, $errors),
				[
					'transaccion_exitosa' => $transaction_successful
				]
			);
		}

		return array(
			'count'  => $processed,
			'errors' => $errors,
			'batch'  => [$inicio, $fin],
		);
	}
	
	/**
	 * Guarda el estado del lote actual para posible recuperación
	 *
	 * @param int $offset Offset actual
	 * @param int $limit Tamaño del lote
	 */
	private function save_sync_batch_state($offset, $limit) {
		set_transient('mia_sync_current_batch_offset', $offset, HOUR_IN_SECONDS * 6);
		set_transient('mia_sync_current_batch_limit', $limit, HOUR_IN_SECONDS * 6);
		set_transient('mia_sync_current_batch_time', time(), HOUR_IN_SECONDS * 6);
		set_transient('mia_sync_batch_start_time', microtime(true), HOUR_IN_SECONDS * 6);
	}
	
	/**
	 * Guarda información del producto actual que se está procesando
	 *
	 * @param array $product Datos del producto
	 */
	private function save_current_product($product) {
		$sku = $product['ReferenciaBarras'] ?? $product['Id'] ?? 'unknown';
		$name = $product['Nombre'] ?? 'Producto sin nombre';
		
		set_transient('mia_sync_current_product_sku', $sku, HOUR_IN_SECONDS * 6);
		set_transient('mia_sync_current_product_name', $name, HOUR_IN_SECONDS * 6);
		set_transient('mia_last_product', $name, HOUR_IN_SECONDS * 6);
		
		// Guardar también el timestamp para análisis de velocidad
		set_transient('mia_sync_last_product_time', microtime(true), HOUR_IN_SECONDS * 6);
	}
	
	/**
	 * Actualiza el progreso visual para la interfaz de usuario
	 *
	 * @param int $processed Número de productos procesados
	 * @param int $total Total de productos en el lote
	 * @param array $current_product Producto actual
	 */
	private function update_visual_progress($processed, $total, $current_product) {
		$sku = $current_product['ReferenciaBarras'] ?? $current_product['Id'] ?? 'unknown';
		$name = $current_product['Nombre'] ?? 'Producto sin nombre';
		
		if (class_exists('\MiIntegracionApi\Admin\AjaxSync')) {
			\MiIntegracionApi\Admin\AjaxSync::store_sync_progress(
				0, // El porcentaje se calcula internamente
				sprintf(__('Procesando producto %d de %d: %s', 'mi-integracion-api'), $processed + 1, $total, $name),
				[
					'articulo_actual' => $name,
					'sku' => $sku,
					'procesados' => $this->sync_status['current_sync']['items_synced'] + $processed,
					'errores' => $this->sync_status['current_sync']['errors'],
					'total' => $this->sync_status['current_sync']['total_items']
				]
			);
		}
	}
	
	/**
	 * Guarda SKUs procesados correctamente para posible recuperación
	 *
	 * @param array $skus Lista de SKUs procesados correctamente
	 */
	private function save_processed_skus($skus) {
		$processed_skus = get_transient('mia_sync_processed_skus');
		if (!is_array($processed_skus)) {
			$processed_skus = [];
		}
		
		$processed_skus = array_merge($processed_skus, $skus);
		set_transient('mia_sync_processed_skus', $processed_skus, HOUR_IN_SECONDS * 6);
	}
	
	/**
	 * Marca un lote como completado para recuperación
	 *
	 * @param int $offset Offset del lote
	 * @param int $limit Tamaño del lote
	 */
	private function mark_batch_completed($offset, $limit) {
		$completed_batches = get_transient('mia_sync_completed_batches');
		if (!is_array($completed_batches)) {
			$completed_batches = [];
		}
		
		$batch_key = $offset . '-' . ($offset + $limit);
		$completed_batches[$batch_key] = time();
		
		// Guardar tiempo de procesamiento para este lote
		$batch_start_time = get_transient('mia_sync_batch_start_time');
		if ($batch_start_time) {
			$batch_duration = microtime(true) - $batch_start_time;
			
			// Mantener un historial de tiempos de procesamiento para análisis
			$batch_times = get_transient('mia_sync_batch_times') ?: [];
			$batch_times[$batch_key] = [
				'start' => $batch_start_time,
				'end' => microtime(true),
				'duration' => $batch_duration,
				'offset' => $offset,
				'limit' => $limit,
				'items' => $limit  // Aproximación, podría ajustarse con el número real
			];
			
			// Limitar el tamaño del historial para evitar transient demasiado grande
			if (count($batch_times) > 50) {
				$batch_times = array_slice($batch_times, -50, 50, true);
			}
			
			set_transient('mia_sync_batch_times', $batch_times, HOUR_IN_SECONDS * 6);
		}
		
		set_transient('mia_sync_completed_batches', $completed_batches, HOUR_IN_SECONDS * 6);
	}

	/**
	 * Sincroniza pedidos desde WooCommerce a Verial
	 *
	 * @param int   $offset Offset para la consulta
	 * @param int   $limit Límite de pedidos a procesar
	 * @param array $filters Filtros adicionales
	 * @return array|WP_Error Resultado de la operación
	 */
	private function sync_orders_to_verial( $offset, $limit, $filters ) {
		// Obtener pedidos de WooCommerce
		$args = array(
			'limit'  => $limit,
			'offset' => $offset,
		);

		// Aplicar filtros adicionales
		if ( ! empty( $filters['status'] ) ) {
			$args['status'] = $filters['status'];
		}

		if ( ! empty( $filters['modified_after'] ) ) {
			$args['date_modified'] = '>=' . $filters['modified_after'];
		}

		// Obtener pedidos
		$query  = new \WC_Order_Query( $args );
		$orders = $query->get_orders();

		// Procesar cada pedido
		$processed = 0;
		$errors    = array();

		foreach ( $orders as $order ) {
			// Mapear pedido de WooCommerce a formato de Verial
			$verial_order = $this->map_wc_order_to_verial( $order );

			// Enviar a Verial
			// TODO: Implementar la lógica específica según la API de Verial

			++$processed;
		}

		return array(
			'count'  => $processed,
			'errors' => $errors,
		);
	}

	/**
	 * Sincroniza pedidos desde Verial a WooCommerce
	 *
	 * @param int   $offset Offset para la consulta
	 * @param int   $limit Límite de pedidos a procesar
	 * @param array $filters Filtros adicionales
	 * @return array|WP_Error Resultado de la operación
	 */
	private function sync_orders_from_verial( $offset, $limit, $filters ) {
		// Esta función necesitaría implementarse según la API de Verial
		// Por ahora, devolvemos un resultado simulado para demostración
		return array(
			'count'  => 0,
			'errors' => array(),
		);
	}

	/**
	 * Mapea un producto de WooCommerce al formato de Verial
	 *
	 * @param WC_Product $product Producto de WooCommerce
	 * @return array Datos del producto en formato Verial
	 */
	private function map_wc_product_to_verial( $product ) {
		// TODO: Implementar el mapeo según la documentación de la API de Verial
		return array(
			'ID'          => $product->get_id(),
			'Codigo'      => $product->get_sku(),
			'Descripcion' => $product->get_name(),
			'PVP'         => $product->get_price(),
			// Añadir más campos según la API de Verial
		);
	}

	/**
	 * Mapea un producto de Verial al formato de WooCommerce
	 *
	 * @param array $verial_product Datos del producto de Verial
	 * @return array Datos del producto en formato WooCommerce
	 */
	private function map_verial_product_to_wc( $verial_product ) {
		// Este método ahora es un contenedor simple. La lógica principal se ha movido
		// a la clase MapProduct para una mejor separación de responsabilidades.
		// La llamada real se hace directamente desde sync_products_from_verial.
		return \MiIntegracionApi\Helpers\MapProduct::verial_to_wc($verial_product);
	}

	/**
	 * Mapea un pedido de WooCommerce al formato de Verial
	 *
	 * @param WC_Order $order Pedido de WooCommerce
	 * @return array Datos del pedido en formato Verial
	 */
	private function map_wc_order_to_verial( $order ) {
		// TODO: Implementar el mapeo según la documentación de la API de Verial
		return array(
			'ID'      => $order->get_id(),
			'Fecha'   => $order->get_date_created()->format( 'Y-m-d' ),
			'Cliente' => array(
				'Nombre' => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'Email'  => $order->get_billing_email(),
				// Añadir más campos según la API de Verial
			),
			// Añadir más campos según la API de Verial
		);
	}

	/**
	 * Crea o actualiza un producto en WooCommerce
	 *
	 * @param array $product_data Datos del producto.
	 * @param array $batch_cache  Caché de datos precargados para el lote.
	 * @return WC_Product|WP_Error Producto creado/actualizado o error.
	 */
	private function create_or_update_wc_product( array $product_data, array $batch_cache = [] ): \WC_Product|WP_Error {
	 // Usar la caché de lote si está disponible, si no, consultar la BD.
	 $product_id = $batch_cache['product_ids'][ $product_data['sku'] ] ?? wc_get_product_id_by_sku( $product_data['sku'] );

	 if ( $product_id ) {
	 	// Actualizar producto existente
	 	$product = wc_get_product( $product_id );

			if ( ! $product ) {
				return new \WP_Error(
					'product_not_found',
					__( 'No se pudo encontrar el producto a actualizar.', 'mi-integracion-api' )
				);
			}
		} else {
			// Crear nuevo producto
			$product = new \WC_Product();
		}

		// Actualizar datos del producto
		$product->set_sku( $product_data['sku'] );
		$product->set_name( $product_data['name'] );
		$product->set_regular_price( $product_data['regular_price'] );

		// Añadir más campos según los datos disponibles
		if ( isset( $product_data['description'] ) ) {
			$product->set_description( $product_data['description'] );
		}

		if ( isset( $product_data['short_description'] ) ) {
			$product->set_short_description( $product_data['short_description'] );
		}

		if ( isset( $product_data['stock_quantity'] ) ) {
			$product->set_stock_quantity( $product_data['stock_quantity'] );
			$product->set_manage_stock( true );
			$product->set_stock_status( $product_data['stock_quantity'] > 0 ? 'instock' : 'outofstock' );
		}

		// Guardar producto
		$product_id = $product->save();

		if ( ! $product_id ) {
			return new \WP_Error(
				'save_failed',
				__( 'Error al guardar el producto.', 'mi-integracion-api' )
			);
		}

		return $product;
	}

	/**
		* Obtiene los IDs de productos de WooCommerce a partir de una lista de SKUs.
		*
		* @param array $skus Lista de SKUs a buscar.
		* @return array Array asociativo de ['sku' => 'product_id'].
		*/
	private function get_product_ids_by_sku( array $skus ): array {
		global $wpdb;
		if ( empty( $skus ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $skus ), '%s' ) );
		$sql          = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value IN ( {$placeholders} )";
		$results      = $wpdb->get_results( $wpdb->prepare( $sql, $skus ) );

		$product_ids = [];
		foreach ( $results as $result ) {
			$product_ids[ $result->meta_value ] = (int) $result->post_id;
		}

		return $product_ids;
	}

	/**
		* Obtiene los mapeos de categorías de WooCommerce a partir de una lista de IDs de Verial.
		*
		* @param array $verial_ids Lista de IDs de categorías de Verial.
		* @return array Array asociativo de ['verial_id' => 'wc_term_id'].
		*/
	private function get_category_mappings( array $verial_ids ): array {
		global $wpdb;
		if ( empty( $verial_ids ) ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $verial_ids ), '%d' ) );
		$sql = "SELECT tm.term_id, tm.meta_value as verial_id
				FROM {$wpdb->termmeta} tm
				WHERE tm.meta_key = '_verial_category_id'
				AND tm.meta_value IN ( {$placeholders} )";

		$results = $wpdb->get_results( $wpdb->prepare( $sql, $verial_ids ) );

		$mappings = [];
		foreach ( $results as $result ) {
			$mappings[ (int) $result->verial_id ] = (int) $result->term_id;
		}

		return $mappings;
	}

	/**
		* Registra un error de sincronización en la base de datos.
		*
		* @param array  $item_data     Los datos del item que falló.
		* @param string $error_code    El código del error.
		* @param string $error_message El mensaje del error.
		* @return void
		*/
	private function log_sync_error(array $item_data, string $error_code, string $error_message): void {
		global $wpdb;

		$run_id = $this->sync_status['current_sync']['run_id'] ?? 'unknown';
		$sku = $item_data['ReferenciaBarras'] ?? $item_data['Id'] ?? $item_data['CodigoArticulo'] ?? 'no-sku';

		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		$wpdb->insert(
			$table_name,
			array(
				'sync_run_id'   => $run_id,
				'item_sku'      => $sku,
				'item_data'     => wp_json_encode( $item_data ),
				'error_code'    => $error_code,
				'error_message' => $error_message,
				'timestamp'     => current_time( 'mysql' ),
			),
			array(
				'%s', // sync_run_id
				'%s', // item_sku
				'%s', // item_data
				'%s', // error_code
				'%s', // error_message
				'%s', // timestamp
			)
		);
	}

	/**
	 * Limpia los registros de errores de sincronización antiguos.
	 *
	 * @param int $days_to_keep Número de días de registros a mantener (por defecto 30)
	 * @return int Número de registros eliminados
	 */
	public function cleanup_old_sync_errors(int $days_to_keep = 30): int {
		global $wpdb;
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		// Calcular la fecha límite
		$cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));

		// Registrar inicio de limpieza
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-cleanup');
			$logger->info(
				sprintf('Iniciando limpieza de errores de sincronización anteriores a %s', $cutoff_date)
			);
		}

		// Eliminar registros antiguos
		$result = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table_name} WHERE timestamp < %s",
				$cutoff_date
			)
		);

		// Registrar resultado
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-cleanup');
			$logger->info(
				sprintf('Limpieza completada: %d registros de errores eliminados', $result)
			);
		}

		return (int) $result;
	}

	/**
	 * Reanuda una sincronización desde el último punto conocido o desde un punto específico.
	 *
	 * @param int|null $offset Offset específico para reanudar (opcional)
	 * @param int|null $batch_size Tamaño de lote específico para usar (opcional)
	 * @return array|WP_Error Resultado de la operación
	 */
	public function resume_sync(?int $offset = null, ?int $batch_size = null): array|WP_Error {
		$this->load_sync_status();

		// Verificar si hay una sincronización en progreso o datos para recuperar
		if (!$this->sync_status['current_sync']['in_progress'] && !$this->get_last_failed_batch()) {
			return new \WP_Error(
				'no_sync_to_resume',
				__('No hay una sincronización para reanudar.', 'mi-integracion-api')
			);
		}

		// Generar un nuevo ID de ejecución para la recuperación
		$sync_run_id = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('sync_recovery_', true);

		// Si la sincronización no está marcada como en progreso, restablecerla
		if (!$this->sync_status['current_sync']['in_progress']) {
			// Obtener datos del último lote fallido
			$last_batch = $this->get_last_failed_batch();
			
			if (!$last_batch) {
				return new \WP_Error(
					'no_recovery_data',
					__('No hay datos de recuperación disponibles.', 'mi-integracion-api')
				);
			}
			
			// Usar los parámetros proporcionados o los del último lote fallido
			$actual_offset = $offset ?? $last_batch['offset'];
			$actual_batch_size = $batch_size ?? $last_batch['limit'];
			
			// Calcular el número de lote basado en el offset
			$current_batch = floor($actual_offset / $actual_batch_size);
			
			// Actualizar estado para recuperación
			$this->sync_status['current_sync']['in_progress'] = true;
			$this->sync_status['current_sync']['run_id'] = $sync_run_id;
			$this->sync_status['current_sync']['current_batch'] = $current_batch;
			$this->sync_status['current_sync']['batch_size'] = $actual_batch_size;
			$this->sync_status['current_sync']['recovery_enabled'] = true;
			$this->sync_status['current_sync']['last_update'] = time();
			$this->save_sync_status();

			// Registrar recuperación
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-resume');
				$logger->info(
					'Reanudando sincronización desde punto de error previo',
					[
						'offset' => $actual_offset,
						'batch_size' => $actual_batch_size,
						'current_batch' => $current_batch,
						'run_id' => $sync_run_id
					]
				);
			}
		} else {
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-continue');
				$logger->info(
					'Continuando sincronización en progreso',
					[
						'current_batch' => $this->sync_status['current_sync']['current_batch'],
						'total_batches' => $this->sync_status['current_sync']['total_batches'],
						'items_synced' => $this->sync_status['current_sync']['items_synced'],
						'run_id' => $this->sync_status['current_sync']['run_id']
					]
				);
			}
		}

		// Procesar el siguiente lote en modo recuperación
		return $this->process_next_batch(true);
	}

	/**
	 * Valida la conexión y parámetros de API antes de iniciar una sincronización completa.
	 *
	 * @param string $entity Entidad a sincronizar ('products' o 'orders')
	 * @param string $direction Dirección de la sincronización
	 * @param array $filters Filtros a utilizar
	 * @return array|WP_Error Resultado de la validación
	 */
	public function validate_sync_prerequisites(string $entity, string $direction, array $filters = []): array|WP_Error {
		$results = [
			'api_connection' => false,
			'params_valid' => false,
			'count_test' => false,
			'sample_data' => false,
			'issues' => [],
			'warnings' => [],
			'sample_response' => null
		];

		// 1. Verificar credenciales de API
		if (!$this->api_connector->has_valid_credentials()) {
			$results['issues'][] = __('No hay credenciales válidas para Verial.', 'mi-integracion-api');
			return $results;
		}
		$results['api_connection'] = true;

		// 2. Validar parámetros básicos
		if (!in_array($entity, ['products', 'orders'])) {
			$results['issues'][] = __('Entidad inválida. Debe ser "products" o "orders".', 'mi-integracion-api');
		}
		
		if (!in_array($direction, ['wc_to_verial', 'verial_to_wc'])) {
			$results['issues'][] = __('Dirección inválida. Debe ser "wc_to_verial" o "verial_to_wc".', 'mi-integracion-api');
		}
		
		if (empty($results['issues'])) {
			$results['params_valid'] = true;
		} else {
			return $results;
		}

		// 3. Probar conteo de elementos (con un tiempo de espera menor)
		try {
			// Para productos de Verial, probar GetNumArticulosWS
			if ($entity === 'products' && $direction === 'verial_to_wc') {
				$count_result = $this->count_verial_products($filters);
				
				if (is_wp_error($count_result)) {
					$results['issues'][] = sprintf(
						__('Error al contar productos: %s', 'mi-integracion-api'),
						$count_result->get_error_message()
					);
				} else {
					$results['count_test'] = true;
					$results['item_count'] = $count_result;
					
					// Advertencia si el conteo es muy alto
					if ($count_result > 5000) {
						$results['warnings'][] = sprintf(
							__('El número de productos es muy alto (%d). Considere utilizar filtros para reducir el volumen.', 'mi-integracion-api'),
							$count_result
						);
					}
				}
				
				// 4. Probar obtención de muestra de datos
				// Intentar obtener un pequeño lote de prueba
				$params = ['inicio' => 1, 'fin' => 3];
				
				// Añadir filtros de fecha/hora si existen
				if (!empty($filters['modified_after'])) {
					$params['fecha'] = date('Y-m-d', $filters['modified_after']);
					if (!empty($filters['modified_after_time'])) {
						$params['hora'] = $filters['modified_after_time'];
					} else {
						$params['hora'] = date('H:i:s', $filters['modified_after']);
					}
				}
				
				$response = $this->api_connector->get('GetArticulosWS', $params);
				
				if (is_wp_error($response)) {
					$results['issues'][] = sprintf(
						__('Error al obtener datos de muestra: %s', 'mi-integracion-api'),
						$response->get_error_message()
					);
				} else {
					$body = wp_remote_retrieve_body($response);
					$data = json_decode($body, true);
					
					if (isset($data['InfoError']) && $data['InfoError']['Codigo'] != 0) {
						$results['issues'][] = sprintf(
							__('Error de API al obtener datos de muestra: %s', 'mi-integracion-api'),
							$data['InfoError']['Descripcion']
						);
					} else if (!isset($data['Articulos'])) {
						$results['issues'][] = __('Respuesta sin datos de productos.', 'mi-integracion-api');
					} else {
						$results['sample_data'] = true;
						$results['sample_count'] = count($data['Articulos']);
						// Guardar un extracto de la respuesta (solo primeros 2 productos)
						if (!empty($data['Articulos'])) {
							$sample = array_slice($data['Articulos'], 0, 2);
							// Filtrar campos sensibles o innecesarios
							foreach ($sample as &$item) {
								unset($item['Texto']); // Eliminar descripciones largas
							}
							$results['sample_response'] = $sample;
						}
					}
				}
			} else {
				// Para otras combinaciones, por ahora solo validar conexión
				$results['warnings'][] = __('Validación completa solo disponible para productos de Verial.', 'mi-integracion-api');
			}
		} catch (\Throwable $e) {
			$results['issues'][] = sprintf(
				__('Excepción durante validación: %s', 'mi-integracion-api'),
				$e->getMessage()
			);
		}
		
		// Calcular estado general
		$results['success'] = $results['api_connection'] &&
							 $results['params_valid'] &&
							 $results['count_test'] &&
							 empty($results['issues']);
		
		return $results;
	}

	/**
	 * Obtiene métricas de rendimiento de la sincronización actual o más reciente.
	 *
	 * @param string|null $run_id ID específico de ejecución (opcional)
	 * @return array Métricas de rendimiento
	 */
	public function get_sync_performance_metrics(?string $run_id = null): array {
		$this->load_sync_status();
		
		// Recopilar métricas del estado actual o historial
		$current_metrics = [];
		
		// Si hay una sincronización en progreso, usar sus métricas
		if ($this->sync_status['current_sync']['in_progress']) {
			$sync_data = $this->sync_status['current_sync'];
			
			// Si se especificó un run_id diferente, buscarlo en el historial
			if ($run_id && $run_id !== $sync_data['run_id']) {
				$history = $this->get_sync_history(100);
				foreach ($history as $entry) {
					if (isset($entry['run_id']) && $entry['run_id'] === $run_id) {
						$sync_data = $entry;
						break;
					}
				}
			}
			
			// Calcular duración hasta ahora
			$duration = time() - $sync_data['start_time'];
			$items_per_second = $duration > 0 ? $sync_data['items_synced'] / $duration : 0;
			$estimated_total_time = $items_per_second > 0 ? $sync_data['total_items'] / $items_per_second : 0;
			$estimated_remaining = $estimated_total_time - $duration;
			
			$current_metrics = [
				'run_id' => $sync_data['run_id'] ?? null,
				'entity' => $sync_data['entity'] ?? '',
				'direction' => $sync_data['direction'] ?? '',
				'batch_size' => $sync_data['batch_size'] ?? 0,
				'items_synced' => $sync_data['items_synced'] ?? 0,
				'total_items' => $sync_data['total_items'] ?? 0,
				'current_batch' => $sync_data['current_batch'] ?? 0,
				'total_batches' => $sync_data['total_batches'] ?? 0,
				'errors' => $sync_data['errors'] ?? 0,
				'duration_seconds' => $duration,
				'duration_formatted' => sprintf(
					'%02d:%02d:%02d',
					floor($duration / 3600),
					floor(($duration % 3600) / 60),
					$duration % 60
				),
				'items_per_second' => round($items_per_second, 2),
				'items_per_minute' => round($items_per_second * 60, 2),
				'estimated_total_time' => $estimated_total_time,
				'estimated_total_formatted' => sprintf(
					'%02d:%02d:%02d',
					floor($estimated_total_time / 3600),
					floor(($estimated_total_time % 3600) / 60),
					$estimated_total_time % 60
				),
				'estimated_remaining' => $estimated_remaining,
				'estimated_remaining_formatted' => sprintf(
					'%02d:%02d:%02d',
					floor($estimated_remaining / 3600),
					floor(($estimated_remaining % 3600) / 60),
					$estimated_remaining % 60
				),
				'percent_complete' => $sync_data['total_items'] > 0
					? round(($sync_data['items_synced'] / $sync_data['total_items']) * 100, 2)
					: 0,
				'in_progress' => $this->sync_status['current_sync']['in_progress'],
				'error_rate' => $sync_data['items_synced'] > 0
					? round(($sync_data['errors'] / $sync_data['items_synced']) * 100, 2)
					: 0,
			];
		} else {
			// No hay sincronización activa, buscar en el historial
			$history = $this->get_sync_history(100);
			$found_entry = null;
			
			if ($run_id) {
				// Buscar entrada específica por run_id
				foreach ($history as $entry) {
					if (isset($entry['run_id']) && $entry['run_id'] === $run_id) {
						$found_entry = $entry;
						break;
					}
				}
			} else if (!empty($history)) {
				// Usar la entrada más reciente
				$found_entry = $history[count($history) - 1];
			}
			
			if ($found_entry) {
				$duration = $found_entry['duration'] ?? ($found_entry['end_time'] - $found_entry['start_time']);
				$items_per_second = $duration > 0 ? $found_entry['items_synced'] / $duration : 0;
				
				$current_metrics = [
					'run_id' => $found_entry['run_id'] ?? null,
					'entity' => $found_entry['entity'] ?? '',
					'direction' => $found_entry['direction'] ?? '',
					'batch_size' => $found_entry['batch_size'] ?? 0,
					'items_synced' => $found_entry['items_synced'] ?? 0,
					'total_items' => $found_entry['total_items'] ?? 0,
					'errors' => $found_entry['errors'] ?? 0,
					'duration_seconds' => $duration,
					'duration_formatted' => sprintf(
						'%02d:%02d:%02d',
						floor($duration / 3600),
						floor(($duration % 3600) / 60),
						$duration % 60
					),
					'items_per_second' => round($items_per_second, 2),
					'items_per_minute' => round($items_per_second * 60, 2),
					'percent_complete' => $found_entry['total_items'] > 0
						? round(($found_entry['items_synced'] / $found_entry['total_items']) * 100, 2)
						: 0,
					'in_progress' => false,
					'completed' => true,
					'status' => $found_entry['status'] ?? 'completed',
					'error_rate' => $found_entry['items_synced'] > 0
						? round(($found_entry['errors'] / $found_entry['items_synced']) * 100, 2)
						: 0,
				];
			} else {
				$current_metrics = [
					'in_progress' => false,
					'completed' => false,
					'message' => __('No se encontraron datos de sincronización.', 'mi-integracion-api')
				];
			}
		}
		
		return $current_metrics;
	}

	/**
	 * Obtiene estadísticas de errores de sincronización.
	 *
	 * @param string|null $run_id ID específico de ejecución (opcional)
	 * @param int $limit Límite de resultados por tipo de error
	 * @return array Estadísticas de errores
	 */
	public function get_sync_error_stats(?string $run_id = null, int $limit = 10): array {
		global $wpdb;
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;

		// Base de la consulta
		$sql_base = "FROM {$table_name}";
		$where = [];
		$params = [];

		// Filtrar por run_id si se proporciona
		if ($run_id) {
			$where[] = "sync_run_id = %s";
			$params[] = $run_id;
		}

		// Construir cláusula WHERE
		$where_clause = '';
		if (!empty($where)) {
			$where_clause = 'WHERE ' . implode(' AND ', $where);
		}

		// Obtener recuento total de errores
		$total_errors = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) {$sql_base} {$where_clause}",
				$params
			)
		);

		// Obtener distribución por código de error
		$error_distribution = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT error_code, COUNT(*) as count
				{$sql_base}
				{$where_clause}
				GROUP BY error_code
				ORDER BY count DESC
				LIMIT %d",
				array_merge($params, [$limit])
			),
			ARRAY_A
		);

		// Obtener errores más recientes
		$recent_errors = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, sync_run_id, item_sku, error_code, error_message, timestamp
				{$sql_base}
				{$where_clause}
				ORDER BY timestamp DESC
				LIMIT %d",
				array_merge($params, [$limit])
			),
			ARRAY_A
		);

		// Obtener SKUs con más errores
		$problem_skus = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT item_sku, COUNT(*) as error_count
				{$sql_base}
				{$where_clause}
				GROUP BY item_sku
				ORDER BY error_count DESC
				LIMIT %d",
				array_merge($params, [$limit])
			),
			ARRAY_A
		);

		return [
			'total_errors' => (int) $total_errors,
			'error_distribution' => $error_distribution,
			'recent_errors' => $recent_errors,
			'problem_skus' => $problem_skus,
			'run_id' => $run_id,
			'generated_at' => current_time('mysql')
		];
	}
	
	/**
	 * Diagnostica problemas comunes de sincronización y devuelve recomendaciones.
	 *
	 * @return array Diagnóstico con problemas detectados y recomendaciones
	 */
	public function diagnose_sync_issues(): array {
		global $wpdb;
		
		$issues = [];
		$recommendations = [];
		$diagnostics = [];
		
		// Verificar si hay una sincronización activa
		$this->load_sync_status();
		if ($this->sync_status['current_sync']['in_progress']) {
			// Verificar si la sincronización está estancada
			$last_update = $this->sync_status['current_sync']['last_update'] ?? 0;
			$now = time();
			
			if ($now - $last_update > 600) { // 10 minutos sin actividad
				$issues[] = sprintf(
					__('Sincronización estancada. Última actualización hace %d minutos.', 'mi-integracion-api'),
					floor(($now - $last_update) / 60)
				);
				$recommendations[] = __('Cancelar la sincronización actual y reiniciar.', 'mi-integracion-api');
			}
			
			$diagnostics['current_sync'] = [
				'entity' => $this->sync_status['current_sync']['entity'],
				'direction' => $this->sync_status['current_sync']['direction'],
				'current_batch' => $this->sync_status['current_sync']['current_batch'],
				'total_batches' => $this->sync_status['current_sync']['total_batches'],
				'items_synced' => $this->sync_status['current_sync']['items_synced'],
				'batch_size' => $this->sync_status['current_sync']['batch_size'],
				'last_update' => date('Y-m-d H:i:s', $this->sync_status['current_sync']['last_update']),
				'elapsed_time' => $now - $this->sync_status['current_sync']['start_time']
			];
		}
		
		// Verificar tabla de errores
		$table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;
		
		if (!$table_exists) {
			$issues[] = __('La tabla de errores de sincronización no existe.', 'mi-integracion-api');
			$recommendations[] = __('Desactivar y reactivar el plugin para crear la tabla.', 'mi-integracion-api');
		} else {
			// Verificar número de errores
			$error_count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
			$diagnostics['error_table'] = [
				'exists' => true,
				'error_count' => (int)$error_count
			];
			
			if ($error_count > 1000) {
				$issues[] = sprintf(
					__('Exceso de registros de error (%d). Puede afectar al rendimiento.', 'mi-integracion-api'),
					$error_count
				);
				$recommendations[] = __('Ejecutar limpieza de registros de error antiguos.', 'mi-integracion-api');
			}
		}
		
		// Verificar transients
		$transients_to_check = [
			'mia_sync_last_activity',
			'mia_sync_completed_batches',
			'mia_sync_processed_skus',
			'mia_sync_current_batch_offset',
			'mia_sync_current_batch_limit'
		];
		
		$transient_status = [];
		foreach ($transients_to_check as $transient) {
			$value = get_transient($transient);
			$transient_status[$transient] = [
				'exists' => $value !== false,
				'value_type' => gettype($value)
			];
			
			if ($transient === 'mia_sync_processed_skus' && is_array($value)) {
				$transient_status[$transient]['count'] = count($value);
				
				if (count($value) > 10000) {
					$issues[] = sprintf(
						__('Exceso de SKUs procesados en caché (%d). Puede causar problemas de memoria.', 'mi-integracion-api'),
						count($value)
					);
					$recommendations[] = __('Considerar borrar el transient mia_sync_processed_skus.', 'mi-integracion-api');
				}
			}
		}
		$diagnostics['transients'] = $transient_status;
		
		// Verificar configuraciones de WooCommerce
		if (function_exists('wc_get_products')) {
			$diagnostics['woocommerce'] = [
				'active' => true,
				'version' => WC()->version ?? 'desconocida'
			];
			
			// Verificar límites CRUD de WooCommerce
			$max_execution_time = ini_get('max_execution_time');
			if ($max_execution_time > 0 && $max_execution_time < 120) {
				$issues[] = sprintf(
					__('Tiempo de ejecución PHP bajo (%d segundos). Puede causar interrupciones.', 'mi-integracion-api'),
					$max_execution_time
				);
				$recommendations[] = __('Aumentar max_execution_time a 300 segundos o más.', 'mi-integracion-api');
			}
			
			$memory_limit = ini_get('memory_limit');
			$memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
			
			if ($memory_limit_bytes < 256 * 1024 * 1024) { // 256 MB
				$issues[] = sprintf(
					__('Límite de memoria PHP bajo (%s). Puede causar fallos con catálogos grandes.', 'mi-integracion-api'),
					$memory_limit
				);
				$recommendations[] = __('Aumentar memory_limit a 256M o más.', 'mi-integracion-api');
			}
			
			$diagnostics['php_limits'] = [
				'max_execution_time' => $max_execution_time,
				'memory_limit' => $memory_limit,
				'memory_limit_bytes' => $memory_limit_bytes
			];
		} else {
			$issues[] = __('WooCommerce no está activo o no se detecta correctamente.', 'mi-integracion-api');
			$recommendations[] = __('Verificar la instalación de WooCommerce.', 'mi-integracion-api');
			$diagnostics['woocommerce'] = ['active' => false];
		}
		
		// Verificar API de Verial
		if (!$this->api_connector->has_valid_credentials()) {
			$issues[] = __('No hay credenciales válidas para la API de Verial.', 'mi-integracion-api');
			$recommendations[] = __('Configurar las credenciales de API en la página de configuración.', 'mi-integracion-api');
		} else {
			// Realizar prueba básica de conexión
			$test_result = $this->api_connector->test_connection();
			$diagnostics['api_connection'] = [
				'credentials_valid' => true,
				'test_result' => is_wp_error($test_result) ? 'error' : 'success'
			];
			
			if (is_wp_error($test_result)) {
				$issues[] = sprintf(
					__('Error al conectar con API de Verial: %s', 'mi-integracion-api'),
					$test_result->get_error_message()
				);
				$recommendations[] = __('Verificar URL y credenciales de API.', 'mi-integracion-api');
			}
		}
		
		// Devolver resultado completo
		return [
			'issues' => $issues,
			'recommendations' => $recommendations,
			'diagnostics' => $diagnostics,
			'sync_active' => $this->sync_status['current_sync']['in_progress'],
			'timestamp' => current_time('mysql'),
			'php_version' => PHP_VERSION,
			'wordpress_version' => get_bloginfo('version')
		];
	}
	
	/**
	 * Calcula el tamaño óptimo de lote basado en el rendimiento histórico.
	 *
	 * @return array Recomendación de tamaño de lote y análisis
	 */
	public function calculate_optimal_batch_size(): array {
		// Obtener historial de tiempos de procesamiento de lotes
		$batch_times = get_transient('mia_sync_batch_times') ?: [];
		
		if (empty($batch_times)) {
			return [
				'recommended_size' => 75, // Valor por defecto razonable
				'confidence' => 'low',
				'message' => __('No hay suficientes datos históricos. Usando valor predeterminado.', 'mi-integracion-api'),
				'analysis' => []
			];
		}
		
		// Agrupar por tamaño de lote y calcular promedios
		$grouped_data = [];
		foreach ($batch_times as $key => $data) {
			$size = $data['limit'] ?? 0;
			if (!$size) continue;
			
			if (!isset($grouped_data[$size])) {
				$grouped_data[$size] = [
					'count' => 0,
					'total_duration' => 0,
					'total_items' => 0,
					'samples' => []
				];
			}
			
			$grouped_data[$size]['count']++;
			$grouped_data[$size]['total_duration'] += $data['duration'] ?? 0;
			$grouped_data[$size]['total_items'] += $data['items'] ?? 0;
			
			// Guardar muestra para análisis (limitado a 5 por tamaño)
			if (count($grouped_data[$size]['samples']) < 5) {
				$grouped_data[$size]['samples'][] = [
					'duration' => $data['duration'] ?? 0,
					'items' => $data['items'] ?? 0,
					'key' => $key
				];
			}
		}
		
		// Calcular rendimiento por tamaño
		$performance_metrics = [];
		foreach ($grouped_data as $size => $data) {
			if ($data['count'] < 2) continue; // Ignorar tamaños con pocas muestras
			
			$avg_duration = $data['total_duration'] / $data['count'];
			$avg_items = $data['total_items'] / $data['count'];
			$items_per_second = $avg_items / $avg_duration;
			
			$performance_metrics[$size] = [
				'size' => (int)$size,
				'avg_duration' => round($avg_duration, 2),
				'items_per_second' => round($items_per_second, 2),
				'sample_count' => $data['count'],
				'samples' => $data['samples']
			];
		}
		
		if (empty($performance_metrics)) {
			return [
				'recommended_size' => 75,
				'confidence' => 'low',
				'message' => __('Datos insuficientes para análisis.', 'mi-integracion-api'),
				'analysis' => []
			];
		}
		
		// Encontrar el tamaño con mejor rendimiento
		$best_size = 75; // Valor predeterminado
		$best_performance = 0;
		$best_confidence = 'medium';
		
		foreach ($performance_metrics as $size => $metrics) {
			$performance_score = $metrics['items_per_second'] * min(1, $metrics['sample_count'] / 10);
			
			if ($performance_score > $best_performance) {
				$best_performance = $performance_score;
				$best_size = $size;
				$best_confidence = $metrics['sample_count'] >= 5 ? 'high' : 'medium';
			}
		}
		
		// Aplicar límites razonables
		$best_size = max(25, min(200, $best_size));
		
		// Mensaje personalizado
		$message = sprintf(
			__('Tamaño de lote recomendado: %d. Basado en %d muestras con un rendimiento de %.2f elementos/segundo.', 'mi-integracion-api'),
			$best_size,
			$performance_metrics[$best_size]['sample_count'] ?? 0,
			$performance_metrics[$best_size]['items_per_second'] ?? 0
		);
		
		return [
			'recommended_size' => $best_size,
			'confidence' => $best_confidence,
			'message' => $message,
			'analysis' => $performance_metrics,
			'raw_data_count' => count($batch_times)
		];
	}
}
