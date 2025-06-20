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
use MiIntegracionApi\Core\DataValidator;
use MiIntegracionApi\Core\LogManager;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\DTOs\CustomerDTO;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Helpers\MapCustomer;
use MiIntegracionApi\Core\DataSanitizer; // Cambiado de Helpers a Core
use MiIntegracionApi\Helpers\BatchSizeHelper;
use MiIntegracionApi\Core\Validation\ProductValidator;
use MiIntegracionApi\Core\Validation\OrderValidator;
use MiIntegracionApi\Core\Validation\CustomerValidator;
use MiIntegracionApi\WooCommerce\SyncHelper;

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
	 * Instancia del logger
	 *
	 * @var LogManager
	 */
	private $logger;

	/**
	 * Instancia del sanitizer
	 *
	 * @var DataSanitizer
	 */
	private $sanitizer;

	private ?SyncMetrics $metrics = null;
	private ?HeartbeatProcess $heartbeat_process = null;

	/**
	 * Constructor
	 */
	private function __construct() {
		$this->logger = new LogManager('sync-manager');
		$this->sanitizer = new DataSanitizer();
		// Crear ApiConnector con el logger interno del LogManager y valores predeterminados para los reintentos
		// La clase ApiConnector espera un objeto Helpers\Logger, no un Core\LogManager
		$logger_instance = $this->logger->get_logger_instance();
		$this->api_connector = new ApiConnector($logger_instance);
		$this->config_manager = Config_Manager::get_instance();
		$this->metrics = new SyncMetrics();
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
	 * Obtiene la instancia del conector API
	 *
	 * @return ApiConnector|null
	 */
	public function get_api_connector() {
		return $this->api_connector;
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
						'batch_size'    => BatchSizeHelper::getBatchSize('productos'),
						'current_batch' => 0,
						'total_batches' => 0,
						'items_synced'  => 0,
						'total_items'   => 0,
						'errors'        => 0,
						'start_time'    => 0,
						'last_update'   => 0,
						'operation_id'  => '',  // Asegurar que siempre exista esta clave
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
	 * Inicia una sincronización
	 * 
	 * @param string $entity Nombre de la entidad
	 * @param string $direction Dirección de la sincronización
	 * @param array<string, mixed> $filters Filtros adicionales
	 * @return array<string, mixed> Resultado de la sincronización
	 * @throws SyncError
	 */
	public function start_sync(string $entity, string $direction, array $filters = []): array
	{
		$operationId = uniqid('sync_', true);
		$this->metrics->startOperation($operationId, $entity, $direction);

		try {
			// Validar parámetros
			$validation = $this->validate_sync_prerequisites($entity, $direction, $filters);
			if (is_wp_error($validation)) {
				$this->metrics->recordItemProcessed($operationId, false, $validation->get_error_message());
				return [
					'success' => false,
					'error' => $validation->get_error_message()
				];
			}

			// Verificar proceso en curso
			if ($this->sync_status['current_sync']['in_progress']) {
				$error = "Ya hay un proceso de sincronización en curso";
				$this->metrics->recordItemProcessed($operationId, false, $error);
				return [
					'success' => false,
					'error' => $error
				];
			}

			// Iniciar sincronización
			$this->sync_status['current_sync'] = [
				'in_progress' => true,
				'entity' => $entity,
				'direction' => $direction,
				'batch_size' => BatchSizeHelper::getBatchSize($entity),
				'current_batch' => 0,
				'total_batches' => 0,
				'items_synced' => 0,
				'total_items' => 0,
				'errors' => 0,
				'start_time' => time(),
				'last_update' => time(),
				'operation_id' => $operationId
			];

			$this->save_sync_status();

			// Contar items totales
			$total_items = $this->count_items_for_sync($entity, $direction, $filters);
			$this->sync_status['current_sync']['total_items'] = $total_items;
			$this->sync_status['current_sync']['total_batches'] = ceil($total_items / $this->sync_status['current_sync']['batch_size']);
			
			$this->save_sync_status();

			return [
				'success' => true,
				'operation_id' => $operationId,
				'total_items' => $total_items,
				'total_batches' => $this->sync_status['current_sync']['total_batches']
			];

		} catch (\Exception $e) {
			$this->metrics->recordItemProcessed($operationId, false, $e->getMessage());
			$this->metrics->endOperation($operationId);
			
			return [
				'success' => false,
				'error' => $e->getMessage()
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
				
				// Si es un resultado de error pero no una excepción
				if (is_array($result) && isset($result['success']) && !$result['success']) {
					// Asegurar que el código de error sea un entero
					$errorCode = isset($result['error_code']) ? intval($result['error_code']) : 0;
					
					throw new SyncError(
						$result['error'] ?? 'Error desconocido',
						$errorCode,
						$context
					);
				}
				
				return $result;
				
			} catch (SyncError $e) {
				$lastError = $e;
				$attempts++;
				
				// Registrar el intento fallido
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
				
				// Backoff exponencial con jitter
				$delay = pow(2, $attempts) + rand(0, 1000) / 1000;
				usleep(intval($delay * 1000000)); // Convertir a microsegundos (entero)
			}
		}
		
		// Si llegamos aquí, todos los reintentos fallaron
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

	public function process_next_batch($recovery_mode = false) {
		if (!$this->sync_status['current_sync']['in_progress']) {
			return [
				'success' => false,
				'error' => 'No hay proceso de sincronización en curso'
			];
		}

		// Verificar si falta operation_id y generarlo si es necesario
		if (empty($this->sync_status['current_sync']['operation_id'])) {
			$this->sync_status['current_sync']['operation_id'] = uniqid('sync_', true);
			$this->save_sync_status();
			$this->logger->info("Se generó un operation_id porque no existía", [
				'operation_id' => $this->sync_status['current_sync']['operation_id']
			]);
		}

		$operationId = $this->sync_status['current_sync']['operation_id'];
		$entity = $this->sync_status['current_sync']['entity'];
		$direction = $this->sync_status['current_sync']['direction'];
		$batch_size = $this->sync_status['current_sync']['batch_size'];
		$current_batch = $this->sync_status['current_sync']['current_batch'];
		$offset = $current_batch * $batch_size;

		try {
			// Iniciar operación en el sistema de métricas
			$this->metrics->startOperation($operationId, $entity, $direction);

			// Verificar memoria disponible
			if (!$this->metrics->checkMemoryUsage($operationId)) {
				$this->logger->warning("Umbral de memoria alcanzado, pausando procesamiento", [
					'entity' => $entity,
					'batch' => $current_batch
				]);
				return [
					'success' => false,
					'error' => 'Umbral de memoria alcanzado',
					'should_pause' => true
				];
			}

			// Ejecutar el procesamiento del lote con reintentos
			$result = $this->retryOperation(
				function() use ($entity, $direction, $offset, $batch_size) {
					return $this->process_sync_batch($entity, $direction, $offset, $batch_size);
				},
				[
					'operation_id' => $operationId,
					'entity' => $entity,
					'direction' => $direction,
					'batch' => $current_batch,
					'offset' => $offset,
					'batch_size' => $batch_size
				],
				$recovery_mode ? 5 : 3 // Más reintentos en modo recuperación
			);
			
			if ($result['success']) {
				$this->metrics->recordItemProcessed($operationId, true);
				$this->sync_status['current_sync']['items_synced'] += $result['processed'];
				
				// Registrar métricas del lote exitoso utilizando los valores del resultado
				$this->metrics->recordBatchMetrics(
					$current_batch,
					$result['processed'],
					isset($result['duration']) ? $result['duration'] : microtime(true) - $this->sync_status['current_sync']['start_time'],
					$result['errors'] ?? 0,
					$result['retry_processed'] ?? 0,
					$result['retry_errors'] ?? 0
				);
			} else {
				$this->metrics->recordItemProcessed($operationId, false, $result['error']);
				$this->metrics->recordError(
					$operationId,
					'batch_error',
					$result['error'],
					['batch' => $current_batch],
					$result['error_code'] ?? 0
				);
				$this->sync_status['current_sync']['errors']++;
			}

			$this->sync_status['current_sync']['current_batch']++;
			$this->sync_status['current_sync']['last_update'] = time();
			$this->save_sync_status();

			// Registrar estadísticas de memoria
			$memoryStats = $this->metrics->getMemoryStats($operationId);
			$this->logger->info("Estadísticas de memoria después del lote", [
				'entity' => $entity,
				'batch' => $current_batch,
				'memory_stats' => $memoryStats
			]);

			// Verificar si es el último lote
			if ($this->sync_status['current_sync']['current_batch'] >= $this->sync_status['current_sync']['total_batches']) {
				$this->finish_sync();
			}

			return $result;

		} catch (\Exception $e) {
			$this->metrics->recordError(
				$operationId,
				'exception',
				$e->getMessage(),
				['trace' => $e->getTraceAsString()],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operationId, false, $e->getMessage());
			
			$this->logger->error("Error procesando lote", [
				'entity' => $entity,
				'batch' => $current_batch,
				'error' => $e->getMessage()
			]);
			
			return [
				'success' => false,
				'error' => $e->getMessage()
			];
		}
	}

	/**
	 * Finaliza el proceso de sincronización actual
	 *
	 * @return array Resultado de la operación
	 */
	public function finish_sync() {
		if (!$this->sync_status['current_sync']['in_progress']) {
			return;
		}

		$operationId = $this->sync_status['current_sync']['operation_id'];
		$entity = $this->sync_status['current_sync']['entity'];
		$direction = $this->sync_status['current_sync']['direction'];

		// Registrar métricas finales
		$metrics = $this->metrics->endOperation($operationId);

		// Actualizar estado
		$this->sync_status['last_sync'][$entity][$direction] = time();
		$this->sync_status['current_sync'] = [
			'in_progress' => false,
			'entity' => '',
			'direction' => '',
			'batch_size' => 0,
			'current_batch' => 0,
			'total_batches' => 0,
			'items_synced' => 0,
			'total_items' => 0,
			'errors' => 0,
			'start_time' => 0,
			'last_update' => 0,
			'operation_id' => ''
		];

		$this->save_sync_status();

		$this->logger->info("Sincronización finalizada", [
			'entity' => $entity,
			'direction' => $direction,
			'metrics' => $metrics
		]);
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
		// Respetamos completamente el tamaño del lote configurado por el usuario
		// No aplicamos límites de seguridad adicionales, confiamos en la configuración explícita
		
		// Para fines de registro, guardamos el valor original
		$original_limit = $limit;
		
		// Registrar información sobre el tamaño del lote que se está usando
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
			$logger->info(
				sprintf('LOTE EFECTIVO EN EJECUCIÓN: %d productos', $limit),
				[
					'offset' => $offset,
					'limit' => $limit,
					'configured_value' => $limit,
					'batch_size_helper_value' => BatchSizeHelper::getBatchSize('productos'),
					'sync_status_batch_size' => $this->sync_status['current_sync']['batch_size'] ?? 'no disponible',
					'request_batch_size' => isset($_REQUEST['batch_size']) ? (int)$_REQUEST['batch_size'] : 'no especificado en request',
					'inicio_fin_rango' => sprintf('Rango API: %d-%d', $offset + 1, $offset + $limit),
					'productos_esperados_en_rango' => $limit
				]
			);
			
			// Añadir alerta si parece que el valor no coincide con lo configurado
			if ($limit != BatchSizeHelper::getBatchSize('productos') && 
			    $limit != $this->sync_status['current_sync']['batch_size']) {
			    $logger->warning(
			        sprintf('¡POSIBLE INCONSISTENCIA! El tamaño de lote real (%d) no coincide con la configuración', $limit),
			        [
			            'limit_real' => $limit,
			            'batch_size_helper_value' => BatchSizeHelper::getBatchSize('productos'),
			            'sync_status' => $this->sync_status['current_sync']['batch_size'] ?? 'no disponible'
			        ]
			    );
			}
		}
		
		// Guardar progreso actual para recuperación
		$this->save_sync_batch_state($offset, $limit);
		
		// Calcular parámetros de paginación
		$inicio = $offset + 1; // API Verial comienza en 1
		$fin = $offset + $limit; // Corrección real: offset + limit para que fin - inicio + 1 = limit exactamente
		
		// Crear parámetros para la consulta con soporte completo para filtros
		// Calcular el tamaño real del lote para logeo y verificaciones
		$batch_size = BatchSizeHelper::calculateEffectiveBatchSize($inicio, $fin);
		$max_recommended = BatchSizeHelper::BATCH_SIZE_LIMITS['productos']['max'] * 0.75; // 75% del máximo como límite de advertencia
		if ($batch_size > $max_recommended) {
			// Registrar advertencia sobre tamaño de lote
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
				$logger->warning(
					sprintf('Tamaño de lote grande (%d) podría causar problemas. Considere reducirlo.', $batch_size),
					[
						'inicio' => $inicio,
						'fin' => $fin,
						'batch_size' => $batch_size,
						'max_recomendado' => $max_recommended
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

		// Verificar si el lote es conocido por ser problemático
		$is_known_problematic_range = $this->is_problematic_range($inicio, $fin);
		
		// Si sabemos que este rango causa problemas o es grande, usar la función de subdivisión
		$subdivision_threshold = BatchSizeHelper::DEFAULT_BATCH_SIZES['productos'];
		if ($is_known_problematic_range || $batch_size > $subdivision_threshold) {
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-batch');
				$logger->info(
					'Usando subdivisión inteligente para rango potencialmente problemático',
					[
						'inicio' => $inicio,
						'fin' => $fin,
						'batch_size' => $batch_size,
						'is_known_problematic' => $is_known_problematic_range
					]
				);
			}
			
			// Usar la función especializada para subdivisión y manejo avanzado de lotes
			return $this->sync_products_from_verial_range($inicio, $fin, $filters);
		}
		
		// Si el lote es pequeño o no es problemático, usar el enfoque estándar
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
				],
				'headers' => [                   // Encabezados HTTP esenciales
					'Content-Type' => 'application/json',
					'Accept' => 'application/json',
					'User-Agent' => 'MiIntegracionAPI/1.0',
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
			
			// CORRECCIÓN: GetArticulosWS requiere POST con body JSON, no GET con parámetros
			$response = $this->api_connector->post('GetArticulosWS', $params, [], $api_options);
			
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
				
				// CORRECCIÓN: GetArticulosWS requiere POST con body JSON, no GET con parámetros
				$response = $this->api_connector->post('GetArticulosWS', $params, []);
				
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

	/**
	 * Verifica si hay suficiente memoria disponible para procesar el lote actual.
	 * Si no hay suficiente memoria, intenta reducir el tamaño del lote dinámicamente.
	 *
	 * @param int $batch_size Tamaño actual del lote
	 * @param int $min_batch_size Tamaño mínimo permitido para el lote
	 * @return int|WP_Error Devuelve el batch_size ajustado o WP_Error si no es posible continuar
	 */
	private function ensure_sufficient_memory(int $batch_size, int $min_batch_size = 1) {
		$memory_limit = ini_get('memory_limit');
		if ($memory_limit === false || $memory_limit === '-1') {
			// Sin límite de memoria, continuar
			return $batch_size;
		}
		$memory_limit_bytes = wp_convert_hr_to_bytes($memory_limit);
		$memory_usage = memory_get_usage(true);
		$memory_free = $memory_limit_bytes - $memory_usage;

		// Estimar el consumo por ítem (ajustable según experiencia)
		$estimated_per_item = 1024 * 80; // 80 KB por ítem (ajustar si se observa mayor consumo)
		$estimated_needed = $batch_size * $estimated_per_item;

		// Si la memoria libre es menor a la estimada, intentar reducir el lote
		while ($estimated_needed > $memory_free && $batch_size > $min_batch_size) {
			$batch_size = (int) max($min_batch_size, floor($batch_size / 2));
			$estimated_needed = $batch_size * $estimated_per_item;
		}

		if ($estimated_needed > $memory_free) {
			return new \WP_Error(
				'insufficient_memory',
				__('No hay suficiente memoria disponible para procesar el lote, incluso con el tamaño mínimo.', 'mi-integracion-api'),
				[
					'memory_limit' => $memory_limit,
					'memory_usage' => $memory_usage,
					'batch_size' => $batch_size,
					'min_batch_size' => $min_batch_size
				]
			);
		}
		return $batch_size;
	}

	/**
	 * Inicializa la configuración de sincronización
	 *
	 * @param array $config Configuración de sincronización
	 * @return bool
	 */
	public function init_config($config) {
		if (!DataValidator::validate_sync_config($config)) {
			Logger::error('Configuración de sincronización inválida');
			return false;
		}

		$this->batch_size = $config['batch_size'] ?? 50;
		$this->interval = $config['interval'] ?? 300;
		return true;
	}

	/**
	 * Sincroniza productos
	 *
	 * @param array $products Datos de productos
	 * @return bool
	 */
	public function sync_products($products) {
		if (!is_array($products)) {
			Logger::error('Datos de productos inválidos');
			return false;
		}

		foreach ($products as $product) {
			if (!DataValidator::validate_product_data($product)) {
				Logger::error('Datos de producto inválidos en lote');
				continue;
			}

			// ... existing code ...
		}

		return true;
	}

	/**
	 * Sincroniza pedidos
	 *
	 * @param array $orders Datos de pedidos
	 * @return bool
	 */
	public function sync_orders($orders) {
		if (!is_array($orders)) {
			Logger::error('Datos de pedidos inválidos');
			return false;
		}

		foreach ($orders as $order) {
			if (!DataValidator::validate_order_data($order)) {
				Logger::error('Datos de pedido inválidos en lote');
				continue;
			}

			// ... existing code ...
		}

		return true;
	}

	/**
	 * Sincroniza clientes
	 *
	 * @param array $customers Datos de clientes
	 * @return bool
	 */
	public function sync_customers($customers) {
		if (!is_array($customers)) {
			Logger::error('Datos de clientes inválidos');
			return false;
		}

		foreach ($customers as $customer) {
			if (!DataValidator::validate_customer_data($customer)) {
				Logger::error('Datos de cliente inválidos en lote');
				continue;
			}

			// ... existing code ...
		}

		return true;
	}

	/**
	 * Sincroniza categorías
	 *
	 * @param array $categories Datos de categorías
	 * @return bool
	 */
	public function sync_categories($categories) {
		if (!is_array($categories)) {
			Logger::error('Datos de categorías inválidos');
			return false;
		}

		foreach ($categories as $category) {
			if (!DataValidator::validate_category_data($category)) {
				Logger::error('Datos de categoría inválidos en lote');
				continue;
			}

			// ... existing code ...
		}

		return true;
	}

	/**
	 * Inicializa el proceso de heartbeat
	 * 
	 * @param string $entity Nombre de la entidad
	 * @return void
	 */
	private function initHeartbeatProcess(string $entity): void
	{
		if (!class_exists('\WP_Background_Process')) {
			Logger::warning(
				"WP_Background_Process no disponible",
				[
					'entity' => $entity,
					'category' => "sync-{$entity}"
				]
			);
			return;
		}

		$this->heartbeat_process = new HeartbeatProcess($entity);
		$this->heartbeat_process->start();
	}

	/**
	 * Detiene el proceso de heartbeat
	 * 
	 * @return void
	 */
	private function stopHeartbeatProcess(): void
	{
		if ($this->heartbeat_process instanceof HeartbeatProcess) {
			$this->heartbeat_process->stop();
			$this->heartbeat_process = null;
		}
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
	 * Obtiene métricas de rendimiento de la sincronización actual o más reciente.
	 *
	 * @param string|null $run_id ID específico de ejecución (opcional)
	 * @return array Métricas de rendimiento
	 */
	public function get_sync_metrics(int $days = 7): array {
		return $this->metrics->getSummaryMetrics($days);
	}

	/**
	 * Procesa un lote de sincronización según la entidad y dirección
	 *
	 * @param string $entity Entidad a sincronizar (products, orders)
	 * @param string $direction Dirección (wc_to_verial, verial_to_wc)
	 * @param int $offset Posición de inicio
	 * @param int $batch_size Tamaño del lote
	 * @return array Resultado de la sincronización
	 */
	private function process_sync_batch($entity, $direction, $offset, $batch_size) {
		// Filtros por defecto
		$filters = [];
		
		// Registrar el inicio del procesamiento
		$this->logger->info("Procesando lote de sincronización", [
			'entity' => $entity,
			'direction' => $direction,
			'offset' => $offset,
			'batch_size' => $batch_size
		]);
		
		// Determinar qué método específico llamar según entidad y dirección
		if ($entity === 'products') {
			if ($direction === 'wc_to_verial') {
				$result = $this->sync_products_to_verial($offset, $batch_size, $filters);
			} else {
				$result = $this->sync_products_from_verial($offset, $batch_size, $filters);
			}
		} elseif ($entity === 'orders') {
			// Implementación para órdenes
			if ($direction === 'wc_to_verial') {
				// Sincronizar órdenes de WooCommerce a Verial
				$result = [
					'success' => true,
					'processed' => 0,
					'errors' => [],
					'message' => 'Sincronización de órdenes no implementada'
				];
			} else {
				// Sincronizar órdenes de Verial a WooCommerce
				$result = [
					'success' => true,
					'processed' => 0,
					'errors' => [],
					'message' => 'Sincronización de órdenes no implementada'
				];
			}
		} else {
			return [
				'success' => false,
				'error' => "Entidad desconocida: {$entity}",
				'error_code' => 404 // Código de error explícitamente entero
			];
		}
		
		// Transformar el resultado al formato esperado
		if (is_wp_error($result)) {
			// Los códigos de WP_Error pueden ser strings, así que convertimos explícitamente a entero
			$errorCode = $result->get_error_code();
			$errorCode = is_numeric($errorCode) ? intval($errorCode) : 0;
			
			return [
				'success' => false,
				'error' => $result->get_error_message(),
				'error_code' => $errorCode
			];
		}
		
		// Si el resultado es un array pero no tiene 'success', asumimos éxito
		if (!isset($result['success'])) {
			return [
				'success' => true,
				'processed' => $result['count'] ?? count($result),
				'errors' => $result['errors'] ?? []
			];
		}
		
		return $result;
	}

	/**
	 * Sincroniza productos desde Verial utilizando subdivisión dinámica de lotes
	 * para manejar timeouts y errores persistentes
	 *
	 * @param int   $inicio Índice de inicio (1-based)
	 * @param int   $fin Índice de fin (inclusive)
	 * @param array $filters Filtros adicionales
	 * @param int   $depth Profundidad de la recursión para evitar bucles infinitos
	 * @return array|WP_Error Resultado de la operación
	 */
	private function sync_products_from_verial_range($inicio, $fin, $filters = [], $depth = 0) {
		// Evitar recursión excesiva
		if ($depth > 5) {
			return new \WP_Error(
				'excessive_subdivision',
				__('Se alcanzó la profundidad máxima de subdivisión de lotes.', 'mi-integracion-api'),
				['inicio' => $inicio, 'fin' => $fin, 'depth' => $depth]
			);
		}
		
		// Registrar el intento con la profundidad actual
		if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-range');
			$logger->info("Procesando rango [{$inicio}-{$fin}] (profundidad: {$depth})", [
				'inicio' => $inicio,
				'fin' => $fin,
				'batch_size' => $fin - $inicio + 1,
				'depth' => $depth,
				'filters' => $filters
			]);
		}
		
		// Tamaño seguro para lotes - se reduce a medida que aumenta la profundidad
		$safe_batch_size = 10;
		if ($depth == 0) {
			$safe_batch_size = 15;
		} elseif ($depth == 1) {
			$safe_batch_size = 10;
		} else {
			$safe_batch_size = 5;
		}
		
		// Para lotes pequeños (dentro del tamaño seguro), usar la API directamente
		if (($fin - $inicio + 1) <= $safe_batch_size) {
			// Asegurarnos de que los parámetros están correctamente formateados y son consistentes
			$params = array(
				'inicio' => (int)$inicio,
				'fin'    => (int)$fin,
				// Incluir explícitamente la sesión para evitar problemas con parámetros
				'sesionwcf' => $this->api_connector->get_session_number(),
			);

			// Soporte para filtro de fecha y hora
			if ( ! empty( $filters['modified_after'] ) ) {
				$params['fecha'] = date( 'Y-m-d', $filters['modified_after'] );
				
				// Si hay hora específica, añadirla
				if (!empty($filters['modified_after_time'])) {
					$params['hora'] = $filters['modified_after_time'];
				} else {
					// Si tenemos timestamp completo, extraer la hora
					$params['hora'] = date('H:i:s', $filters['modified_after']);
				}
			}
			
			// Obtener productos con retries integrados y pausas progresivas
			$max_retries = 3;
			$retry_count = 0;
			$response = null;
			$last_error = null;
			
			while ($retry_count < $max_retries) {
				try {
					// Liberamos memoria antes de cada solicitud
					if (function_exists('gc_collect_cycles')) {
					    gc_collect_cycles();
					}
					
					// Si no es el primer intento, hacer una pausa progresiva antes de reintentar
					if ($retry_count > 0) {
						$wait_time = $retry_count * 2000000; // 2s, 4s, 6s en microsegundos
						if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
							$logger = new \MiIntegracionApi\Helpers\Logger('sync-retry');
							$logger->info("Esperando {$wait_time}μs antes del reintento #{$retry_count}", [
								'inicio' => $inicio,
								'fin' => $fin
							]);
						}
						usleep($wait_time);
					}
					
					// Establecemos un heartbeat para mostrar que el proceso sigue vivo
					update_option('mi_integracion_last_heartbeat', time());
					update_option('mi_integracion_current_operation', "Consultando API rango: {$inicio}-{$fin}");
					
					$response = $this->api_connector->get_articulos_rango($params);
					if (!is_wp_error($response)) {
						break; // Éxito, salir del bucle
					}
					
					// Registrar el error
					$last_error = $response;
					$retry_count++;
					
					if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-retry');
						$logger->warning("Reintento {$retry_count} para rango [{$inicio}-{$fin}]", [
							'error' => $response->get_error_message(),
							'params' => $params
						]);
					}
					
					// Esperar antes de reintentar (backoff exponencial)
					$sleep_time = intval(pow(2, $retry_count) * 1000000); // 2, 4, 8 segundos en microsegundos
					usleep($sleep_time);
					
				} catch (\Exception $e) {
					$last_error = new \WP_Error('api_exception', $e->getMessage());
					$retry_count++;
					
					if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-exception');
						$logger->error("Excepción en reintento {$retry_count} para rango [{$inicio}-{$fin}]", [
							'error' => $e->getMessage(),
							'trace' => $e->getTraceAsString()
						]);
					}
					
					// Esperar antes de reintentar
					$sleep_time = intval(pow(2, $retry_count) * 1000000);
					usleep($sleep_time);
				}
			}
			
			// Procesar respuesta
			if (is_wp_error($response)) {
				if ($response->get_error_code() === 'empty_response' || 
					$response->get_error_code() === 'http_request_failed') {
					// Consideramos este lote como problemático, lo reportamos para revisión manual
					if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
						$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-problematic');
						$logger->error("Rango [{$inicio}-{$fin}] marcado como problemático", [
							'inicio' => $inicio,
							'fin' => $fin,
							'error' => $response->get_error_message(),
							'depth' => $depth
						]);
					}
				}
				return $response;
			}
			
			// Crear logger para este procesamiento
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-process');
			
			// Normalizar la estructura de productos para el procesamiento
			$articulos_normalizados = [];
			
			// Registrar la estructura completa de la respuesta para diagnóstico
			$logger->debug('Estructura de respuesta API para lote', [
			    'tipo' => gettype($response),
			    'keys_nivel_superior' => is_array($response) ? array_keys($response) : 'No es un array',
			    'muestra_json' => json_encode(array_slice((array)$response, 0, 3))
			]);
			
			// Determinar el formato de la respuesta y normalizar
			if (isset($response['Articulos']) && is_array($response['Articulos'])) {
				$logger->info('Formato detectado: Array con clave Articulos');
				$articulos_normalizados = $response['Articulos'];
			} elseif (isset($response['data']) && is_array($response['data'])) {
				// Formato común de API: {success: true, data: [...]}
				$logger->info('Formato detectado: Estructura con data');
				$articulos_normalizados = $response['data'];
			} elseif (isset($response[0])) {
				$logger->info('Formato detectado: Array simple de productos');
				$articulos_normalizados = $response;
			} else {
				// Si recibimos algún formato desconocido, intentamos procesarlo lo mejor posible
				$logger->warning('Formato desconocido de respuesta', ['keys' => is_array($response) ? array_keys($response) : 'No es un array']);
			}
			
			// Contar productos normalizados
			$total_productos = count($articulos_normalizados);
			$logger->info("Se obtuvieron {$total_productos} productos para el rango [{$inicio}-{$fin}]");
			
			// Si no hay productos, devolver mensaje informativo
			if ($total_productos === 0) {
				$logger->warning("No se encontraron productos en el rango [{$inicio}-{$fin}]");
				return [
					'success' => true,
					'processed' => 0,
					'errors' => 0,
					'range' => [$inicio, $fin],
					'message' => "No se encontraron productos en el rango especificado"
				];
			}
			
			// Mostrar un ejemplo del primer producto para verificar la estructura
			if ($total_productos > 0) {
				$primer_producto = $articulos_normalizados[0];
				$logger->debug('Estructura del primer producto', [
					'id' => $primer_producto['Id'] ?? 'No disponible',
					'nombre' => $primer_producto['Nombre'] ?? 'No disponible',
					'referencia_barras' => $primer_producto['ReferenciaBarras'] ?? 'No disponible',
					'keys' => array_keys($primer_producto)
				]);
			}
			
			// Registrar tiempo de inicio para calcular duración
			$start_time = microtime(true);
			
			// Procesar cada artículo aplicando el mapeo correcto utilizando VerialProductMapper
			$procesados = 0;
			$errores = 0;
			$created = 0;
			$updated = 0;
			$skipped = 0;
			
			foreach ($articulos_normalizados as $articulo) {
				try {
					// Normalizar el producto usando el nuevo VerialProductMapper (con namespace completo)
					$producto_normalizado = \MiIntegracionApi\WooCommerce\VerialProductMapper::normalize_verial_product($articulo);
					
					// Convertir el producto Verial a formato WooCommerce (con namespace completo)
					$wc_product_data = \MiIntegracionApi\WooCommerce\VerialProductMapper::to_woocommerce($producto_normalizado);
					
					// Verificar ReferenciaBarras (que se convierte en SKU)
					$sku = $wc_product_data['sku'] ?? '';
					if (empty($sku)) {
						$logger->warning("Producto sin ReferenciaBarras/SKU, omitiendo", [
							'id' => $articulo['Id'] ?? 'No disponible',
							'nombre' => $wc_product_data['name'] ?? 'No disponible',
							'datos_verial' => $articulo
						]);
						$skipped++;
						continue;
					}
					
					// Buscar producto existente por SKU
					$existing_product_id = wc_get_product_id_by_sku($sku);
					
					if ($existing_product_id) {
						// Actualizar producto existente
						$product = wc_get_product($existing_product_id);
						if ($product) {
							// Actualizar propiedades del producto
							if (isset($wc_product_data['name'])) {
								$product->set_name($wc_product_data['name']);
							}
							if (isset($wc_product_data['description'])) {
								$product->set_description($wc_product_data['description']);
							}
							if (isset($wc_product_data['regular_price'])) {
								$product->set_regular_price($wc_product_data['regular_price']);
							}
							if (isset($wc_product_data['stock_quantity'])) {
								$product->set_stock_quantity($wc_product_data['stock_quantity']);
							}
							if (isset($wc_product_data['manage_stock'])) {
								$product->set_manage_stock($wc_product_data['manage_stock']);
							}
							if (isset($wc_product_data['weight'])) {
								$product->set_weight($wc_product_data['weight']);
							}
							if (isset($wc_product_data['dimensions'])) {
								if (isset($wc_product_data['dimensions']['length'])) {
									$product->set_length($wc_product_data['dimensions']['length']);
								}
								if (isset($wc_product_data['dimensions']['width'])) {
									$product->set_width($wc_product_data['dimensions']['width']);
								}
								if (isset($wc_product_data['dimensions']['height'])) {
									$product->set_height($wc_product_data['dimensions']['height']);
								}
							}
							
							// Actualizar metadatos
							if (isset($wc_product_data['meta_data']) && is_array($wc_product_data['meta_data'])) {
								foreach ($wc_product_data['meta_data'] as $meta) {
									$product->update_meta_data($meta['key'], $meta['value']);
								}
							}
							
							// Guardar cambios
							$product->save();
							$updated++;
						}
					} else {
						// Crear nuevo producto simple
						$product = new \WC_Product_Simple();
						
						// Establecer propiedades básicas
						$product->set_name($wc_product_data['name']);
						$product->set_sku($sku);
						if (isset($wc_product_data['description'])) {
							$product->set_description($wc_product_data['description']);
						}
						if (isset($wc_product_data['regular_price'])) {
							$product->set_regular_price($wc_product_data['regular_price']);
						}
						if (isset($wc_product_data['stock_quantity'])) {
							$product->set_stock_quantity($wc_product_data['stock_quantity']);
						}
						if (isset($wc_product_data['manage_stock'])) {
							$product->set_manage_stock($wc_product_data['manage_stock']);
						}
						if (isset($wc_product_data['weight'])) {
							$product->set_weight($wc_product_data['weight']);
						}
						if (isset($wc_product_data['dimensions'])) {
							if (isset($wc_product_data['dimensions']['length'])) {
								$product->set_length($wc_product_data['dimensions']['length']);
							}
							if (isset($wc_product_data['dimensions']['width'])) {
								$product->set_width($wc_product_data['dimensions']['width']);
							}
							if (isset($wc_product_data['dimensions']['height'])) {
								$product->set_height($wc_product_data['dimensions']['height']);
							}
						}
						
						// Establecer metadatos
						if (isset($wc_product_data['meta_data']) && is_array($wc_product_data['meta_data'])) {
							foreach ($wc_product_data['meta_data'] as $meta) {
								$product->update_meta_data($meta['key'], $meta['value']);
							}
						}
						
						// Guardar el nuevo producto
						$product->save();
						$created++;
					}
					
					$procesados++;
				} catch (\Exception $e) {
					$logger->error("Error al procesar producto", [
						'id' => $articulo['Id'] ?? 'No disponible',
						'nombre' => $articulo['Nombre'] ?? 'No disponible',
						'error' => $e->getMessage()
					]);
					$errores++;
				}
			}
			
			// Calcular duración del procesamiento
			$duration = microtime(true) - $start_time;
			
			// Registrar resultado del procesamiento
			$logger->info("Procesamiento completado para rango [{$inicio}-{$fin}]", [
				'productos_procesados' => $procesados,
				'errores' => $errores,
				'creados' => $created,
				'actualizados' => $updated,
				'omitidos' => $skipped,
				'total_productos' => $total_productos,
				'duracion' => $duration
			]);
			
			// Usar SyncHelper para registrar las métricas del lote
			$lote_stats = [
				'processed' => $procesados,
				'errors' => $errores,
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'duration' => $duration,
				'retry_processed' => 0,
				'retry_errors' => 0
			];
			
			// Generar un operation_id único si no existe
			$operation_id = get_transient('mia_current_sync_operation_id');
			if (!$operation_id) {
				$operation_id = 'sync_' . time() . '_' . mt_rand(1000, 9999);
				set_transient('mia_current_sync_operation_id', $operation_id, HOUR_IN_SECONDS * 6);
			}
			
			// Calcular el tamaño del lote actual
			$batch_size = max(1, $fin - $inicio + 1); // Aseguramos que no sea cero
			
			// Registrar métricas del lote usando SyncHelper (con namespace completo)
			\MiIntegracionApi\WooCommerce\SyncHelper::record_batch_metrics($operation_id, $lote_actual = ($inicio / $batch_size) + 1, $lote_stats);
			
			// Devolver resultado del procesamiento con las claves que espera SyncMetrics
			return [
				'success' => true,
				'processed' => $procesados,     // Para SyncMetrics.recordBatchMetrics
				'errors' => $errores,           // Para SyncMetrics.recordBatchMetrics
				'created' => $created,          // Productos nuevos creados
				'updated' => $updated,          // Productos actualizados
				'skipped' => $skipped,          // Productos omitidos
				'range' => [$inicio, $fin],
				'total' => $total_productos,    // Para compatibilidad con SyncMetrics
				'retry_processed' => 0,         // Para compatibilidad con SyncMetrics
				'retry_errors' => 0,            // Para compatibilidad con SyncMetrics
				'duration' => $duration,        // Duración real calculada para SyncMetrics
				'total_productos' => $total_productos // Mantenemos para uso interno
			];
		} else {
			// Para lotes grandes, subdividir para procesamiento más seguro
			$mid_point = $inicio + floor(($fin - $inicio) / 2);
			
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-subdivision');
				$logger->info("Subdividiendo rango [{$inicio}-{$fin}] en [{$inicio}-{$mid_point}] y [{$mid_point}+1-{$fin}]", [
					'mid_point' => $mid_point,
					'depth' => $depth
				]);
			}
			
			// Procesar primera mitad
			$first_half_result = $this->sync_products_from_verial_range($inicio, $mid_point, $filters, $depth + 1);
			
			// Procesar segunda mitad
			$second_half_result = $this->sync_products_from_verial_range($mid_point + 1, $fin, $filters, $depth + 1);
			
			// Combinar resultados
			if (is_wp_error($first_half_result) && is_wp_error($second_half_result)) {
				// Ambas mitades fallaron
				return new \WP_Error(
					'both_halves_failed',
					__('Ambas mitades del lote fallaron en la sincronización.', 'mi-integracion-api'),
					[
						'first_half' => $first_half_result,
						'second_half' => $second_half_result
					]
				);
			} else if (is_wp_error($first_half_result)) {
				// Solo falló la primera mitad
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-partial');
					$logger->warning("Primera mitad falló para rango [{$inicio}-{$fin}]", [
						'error' => $first_half_result->get_error_message()
					]);
				}
				return $second_half_result;
			} else if (is_wp_error($second_half_result)) {
				// Solo falló la segunda mitad
				if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
					$logger = new \MiIntegracionApi\Helpers\Logger('sync-verial-partial');
					$logger->warning("Segunda mitad falló para rango [{$inicio}-{$fin}]", [
						'error' => $second_half_result->get_error_message()
					]);
				}
				return $first_half_result;
			} else {
				// Ambas mitades tuvieron éxito, combinar resultados
				return [
					'success' => true,
					'processed' => ($first_half_result['processed'] ?? 0) + ($second_half_result['processed'] ?? 0),
					'errors' => ($first_half_result['errors'] ?? 0) + ($second_half_result['errors'] ?? 0),
					'range' => [$inicio, $fin],
					'total' => max($first_half_result['total'] ?? 0, $second_half_result['total'] ?? 0),
					'retry_processed' => ($first_half_result['retry_processed'] ?? 0) + ($second_half_result['retry_processed'] ?? 0),
					'retry_errors' => ($first_half_result['retry_errors'] ?? 0) + ($second_half_result['retry_errors'] ?? 0),
					'duration' => ($first_half_result['duration'] ?? 0) + ($second_half_result['duration'] ?? 0),
					'total_productos' => max($first_half_result['total_productos'] ?? 0, $second_half_result['total_productos'] ?? 0)
				];
			}
		}
	}
	
	/**
	 * Verifica si un rango de IDs de productos es conocido por ser problemático
	 * basado en datos históricos o heurísticas
	 *
	 * @param int $inicio ID de inicio del rango
	 * @param int $fin ID final del rango
	 * @return bool True si el rango es probable que cause problemas
	 */
	private function is_problematic_range(int $inicio, int $fin): bool {
		// Rangos específicos conocidos por ser problemáticos (por ejemplo, del log)
		$known_problematic_ranges = [
			['start' => 4500, 'end' => 4550],  // Rango extraído del log de errores
			['start' => 6000, 'end' => 6100],  // Ejemplo de otro rango potencialmente problemático
		];
		
		// Verificar si el rango actual se solapa con alguno de los rangos problemáticos
		foreach ($known_problematic_ranges as $range) {
			// Si hay algún solapamiento entre los rangos
			if ($inicio <= $range['end'] && $fin >= $range['start']) {
				return true;
			}
		}
		
		// También verificar el tamaño del lote - lotes grandes pueden ser problemáticos
		$batch_size = $fin - $inicio + 1;
		if ($batch_size > 30) {
			return true;
		}
		
		// Consultar el registro de errores para este rango
		// Las siguientes líneas están comentadas porque requieren implementación específica
		// $error_count = $this->get_error_count_for_range($inicio, $fin);
		// if ($error_count > 2) {
		//     return true;
		// }
		
		return false;
	}
}

