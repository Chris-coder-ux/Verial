<?php
namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Core\SyncError;
use MiIntegracionApi\Core\Validation\OrderValidator;
use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Core\TransactionManager;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\ConfigManager;

/**
 * Clase para la sincronización de pedidos
 *
 * Maneja la sincronización de pedidos con el sistema externo,
 * incluyendo reintentos automáticos en caso de fallo y recuperación
 * desde el último punto exitoso.
 *
 * @package MiIntegracionApi
 * @subpackage Sync
 * @since 1.0.0
 */

class SyncPedidos extends BatchProcessor {
	const RETRY_OPTION = 'mia_sync_pedidos_retry_queue';
	const MAX_RETRIES  = 3;
	const RETRY_DELAY  = 300; // segundos entre reintentos (5 min)
	
	/**
	 * Entidad para el sistema de recuperación
	 */
	const ENTITY_NAME = 'pedidos';

	private OrderValidator $validator;
	private MemoryManager $memory;
	private RetryManager $retry;
	private TransactionManager $transaction;
	private Logger $logger;

	public function __construct()
	{
		parent::__construct();
		$this->validator = new OrderValidator();
		$this->memory = new MemoryManager();
		$this->retry = new RetryManager();
		$this->transaction = new TransactionManager();
		$this->logger = new Logger();
	}

	/**
	 * Añade un pedido a la cola de reintentos
	 *
	 * @param int    $order_id ID del pedido
	 * @param string $error_msg Mensaje de error
	 */
	private static function add_to_retry_queue( $order_id, $error_msg ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( ! isset( $queue[ $order_id ] ) ) {
			$queue[ $order_id ] = array(
				'attempts'     => 1,
				'last_attempt' => time(),
				'error'        => $error_msg,
			);
		} else {
			++$queue[ $order_id ]['attempts'];
			$queue[ $order_id ]['last_attempt'] = time();
			$queue[ $order_id ]['error']        = $error_msg;
		}
		update_option( self::RETRY_OPTION, $queue, false );
	}

	/**
	 * Elimina un pedido de la cola de reintentos
	 *
	 * @param int $order_id ID del pedido
	 */
	private static function remove_from_retry_queue( $order_id ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( isset( $queue[ $order_id ] ) ) {
			unset( $queue[ $order_id ] );
			update_option( self::RETRY_OPTION, $queue, false );
		}
	}

	/**
	 * Obtiene el email de alerta configurado
	 *
	 * @return string Email de alerta
	 */
	private static function get_alert_email() {
		$custom = get_option( 'mia_alert_email' );
		if ( $custom && is_email( $custom ) ) {
			return $custom;
		}
		return get_option( 'admin_email' );
	}

	/**
	 * Procesa la cola de reintentos
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector Conector a la API
	 */
	public static function process_retry_queue( \MiIntegracionApi\Core\ApiConnector $api_connector ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $order_id => $info ) {
			if ( $info['attempts'] >= self::MAX_RETRIES ) {

				$msg = sprintf( __( 'Pedido ID %1$d falló tras %2$d reintentos: %3$s', 'mi-integracion-api' ), $order_id, $info['attempts'], $info['error'] );
				\MiIntegracionApi\helpers\Logger::critical( $msg, array( 'context' => 'sync-pedidos-retry' ) );
				$alert_email = self::get_alert_email();
				wp_mail( $alert_email, __( 'Pedido no sincronizado tras reintentos', 'mi-integracion-api' ), $msg );
				// (Opcional) Registrar en tabla de incidencias si se implementa
				self::remove_from_retry_queue( $order_id );
				continue;
			}
			// Respetar RETRY_DELAY
			if ( time() - $info['last_attempt'] < self::RETRY_DELAY ) {
				continue;
			}
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				self::remove_from_retry_queue( $order_id );
				continue;
			}
			// Intentar sincronizar de nuevo (solo si sigue sin _verial_documento_id)
			$verial_doc_id = $order->get_meta( '_verial_documento_id' );
			if ( $verial_doc_id ) {
				self::remove_from_retry_queue( $order_id );
				continue;
			}
			$payload_documento_verial = \MiIntegracionApi\Helpers\Map_Order::wc_to_verial( $order, array(), null );
			$response_verial          = $api_connector->post( 'NuevoDocClienteWS', $payload_documento_verial );
			if ( is_wp_error( $response_verial ) ) {
				self::add_to_retry_queue( $order_id, $response_verial->get_error_message() );
				continue;
			} elseif ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
				$order->update_meta_data( '_verial_documento_id', intval( $response_verial['Id'] ?? 0 ) );
				$order->save_meta_data();

				$msg = sprintf( __( 'Pedido ID %1$d sincronizado tras reintento.', 'mi-integracion-api' ), $order_id );
				\MiIntegracionApi\helpers\Logger::info( $msg, array( 'context' => 'sync-pedidos-retry' ) );
				self::remove_from_retry_queue( $order_id );
			} else {
				$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
				self::add_to_retry_queue( $order_id, $error_desc );
			}
		}
	}

	/**
	 * Sincroniza los pedidos desde WooCommerce a Verial
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector Conector a la API
	 * @param array|null                          $filters Filtros adicionales
	 * @param int                                 $batch_size Tamaño del lote
	 * @param array                               $options Opciones adicionales
	 * @return array Resultado de la sincronización
	 */
	public static function sync( 
		\MiIntegracionApi\Core\ApiConnector $api_connector, 
		$filters = null, 
		$batch_size = 50, 
		$options = [] 
	) {
		if (!class_exists('WooCommerce')) {
			throw new \MiIntegracionApi\Core\SyncError(
				'WooCommerce no está activo.',
				400,
				['entity' => 'pedidos']
			);
		}
		if (!\MiIntegracionApi\Core\SyncLock::acquire('pedidos')) {
			throw new \MiIntegracionApi\Core\SyncError(
				'Ya hay una sincronización en curso.',
				409,
				['entity' => 'pedidos']
			);
		}
		
		// Si se usa el nuevo sistema de sincronización por lotes con recuperación
		if ( isset( $options['use_batch_processor'] ) && $options['use_batch_processor'] ) {
			$force_restart = isset( $options['force_restart'] ) && $options['force_restart'];
			return self::sync_batch( $api_connector, [], $filters ?: [], $force_restart );
		}
		if ( ! class_exists( '\MiIntegracionApi\Helpers\Map_Order' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Clase Map_Order no disponible.', 'mi-integracion-api' ),
				'processed' => 0,
				'errors'    => 1,
			);
		}
		if ( ! is_object( $api_connector ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'ApiConnector no válido.', 'mi-integracion-api' ),
				'processed' => 0,
				'errors'    => 1,
			);
		}

		$processed = 0;
		$errors    = 0;
		$log       = array();

		try {
			$args      = array(
				'status'  => array( 'wc-processing', 'wc-completed' ),
				'limit'   => -1,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'ids',
			);
			$order_ids = wc_get_orders( $args );

			if ( empty( $order_ids ) ) {
				\MiIntegracionApi\helpers\Logger::info( 'No se encontraron pedidos en WooCommerce con estados procesables para sincronizar.', array( 'context' => 'sync-pedidos' ) );
				return array(
					'success'   => true,
					'message'   => __( 'No se encontraron pedidos en WooCommerce para sincronizar.', 'mi-integracion-api' ),
					'processed' => 0,
					'errors'    => 0,
					'log'       => array( __( 'No se encontraron pedidos en WooCommerce para sincronizar.', 'mi-integracion-api' ) ),
				);
			}

			\MiIntegracionApi\helpers\Logger::info( 'Iniciando sincronización de ' . count( $order_ids ) . ' pedidos de WooCommerce a Verial.', array( 'context' => 'sync-pedidos' ) );

			foreach ( $order_ids as $order_id ) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					++$errors;

					$error_msg = sprintf( __( 'Pedido ID %1$d no encontrado o inválido.', 'mi-integracion-api' ), $order_id );
					$log[]     = $error_msg;
					\MiIntegracionApi\helpers\Logger::warning( $error_msg, array( 'context' => 'sync-pedidos' ) );
					continue;
				}
				$verial_doc_id = $order->get_meta( '_verial_documento_id' );
				// --- Control de duplicados por número externo (si existe) ---
				$numero_externo = $order->get_meta( '_verial_documento_numero' );
				if ( $numero_externo ) {
					$orders_by_numero = wc_get_orders(
						array(
							'meta_key'   => '_verial_documento_numero',
							'meta_value' => $numero_externo,
							'exclude'    => array( $order_id ),
							'limit'      => 1,
							'return'     => 'ids',
						)
					);
					if ( ! empty( $orders_by_numero ) ) {

						$msg   = sprintf( __( 'Posible duplicado: pedido con número Verial "%1$s" ya existe (ID: %2$d).', 'mi-integracion-api' ), $numero_externo, $orders_by_numero[0] );
						$log[] = $msg;
						\MiIntegracionApi\helpers\Logger::warning( $msg, array( 'context' => 'sync-pedidos-duplicados' ) );
						// Alerta proactiva por email al admin
						$alert_email = self::get_alert_email();
						wp_mail( $alert_email, '[Verial/WC] Duplicado crítico de pedido', $msg );
					}
				}
				// --- Hash de sincronización: incluir campos clave y metadatos relevantes ---
				$payload_documento_verial = \MiIntegracionApi\Helpers\Map_Order::wc_to_verial( $order, array(), null );
				$hash_fields              = array(
					$payload_documento_verial['Referencia'] ?? '',
					$payload_documento_verial['Cliente']['Email'] ?? '',
					$payload_documento_verial['TotalImporte'] ?? '',
					isset( $payload_documento_verial['Contenido'] ) ? wp_json_encode( $payload_documento_verial['Contenido'] ) : '',
					$order->get_status(),
					$numero_externo ?? '',
					isset( $payload_documento_verial['meta_data'] ) ? wp_json_encode( $payload_documento_verial['meta_data'] ) : '',
				);
				// Documentación: los campos incluidos en el hash son: referencia, email cliente, importe total, líneas de contenido, estado, número externo y metadatos clave.
				$hash_actual   = md5( json_encode( $hash_fields ) );
				$hash_guardado = $order->get_meta( '_verial_sync_hash' );
				if ( $hash_guardado && $hash_actual === $hash_guardado ) {
					$log[] = sprintf( __( 'Pedido ID %d omitido (hash sin cambios).', 'mi-integracion-api' ), $order->get_id() );
					continue;
				}

				$response_verial = $api_connector->post( 'NuevoDocClienteWS', $payload_documento_verial );
				if ( is_wp_error( $response_verial ) ) {
					// Solo reintentar en errores temporales (red, HTTP 5xx, timeout)
					$err = $response_verial->get_error_code();
					if ( strpos( $err, 'http_error_5' ) !== false || strpos( $err, 'connection' ) !== false || strpos( $err, 'timeout' ) !== false ) {
						self::add_to_retry_queue( $order_id, $response_verial->get_error_message() );
					}
					++$errors;

					$error_msg = sprintf( __( 'Error al sincronizar pedido ID %1$d con Verial: %2$s', 'mi-integracion-api' ), $order->get_id(), $response_verial->get_error_message() );
					$log[]     = $error_msg;
					\MiIntegracionApi\helpers\Logger::error( $error_msg, array( 'context' => 'sync-pedidos' ) );
					continue;
				}

				if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
					++$processed;

					$log_msg = sprintf( __( 'Pedido ID %1$d sincronizado. ID Documento Verial: %2$s, Número Verial: %3$s', 'mi-integracion-api' ), $order->get_id(), ( $response_verial['Id'] ?? 'N/A' ), ( $response_verial['Numero'] ?? 'N/A' ) );
					$log[]   = $log_msg;
					if ( isset( $response_verial['Id'] ) ) {
						$order->update_meta_data( '_verial_documento_id', intval( $response_verial['Id'] ) );
					}
					if ( isset( $response_verial['Numero'] ) ) {
						$order->update_meta_data( '_verial_documento_numero', sanitize_text_field( $response_verial['Numero'] ) );
					}
					$order->update_meta_data( '_verial_sync_hash', $hash_actual );
					$order->update_meta_data( '_verial_sync_last', current_time( 'mysql' ) );
					$order->save_meta_data();
				} else {
					++$errors;
					$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );

					$error_msg = sprintf( __( 'Error al sincronizar pedido ID %1$d con Verial: %2$s', 'mi-integracion-api' ), $order->get_id(), $error_desc );
					$log[]     = $error_msg;
					\MiIntegracionApi\helpers\Logger::error( $error_msg . ' Respuesta Verial: ' . wp_json_encode( $response_verial ), array( 'context' => 'sync-pedidos' ) );
				}
			}

			$final_message = sprintf(
				__( 'Sincronización de pedidos completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$processed,
				$errors
			);
			\MiIntegracionApi\helpers\Logger::info( $final_message, array( 'context' => 'sync-pedidos' ) );

			return array(
				'success'   => $errors === 0,
				'message'   => $final_message,
				'processed' => $processed,
				'errors'    => $errors,
				'log'       => $log,
			);

		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización de pedidos: %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-pedidos' ) );
			$log[] = $exception_msg;
			return array(
				'success'   => false,
				'message'   => $exception_msg,
				'processed' => $processed,
				'errors'    => $errors + 1,
				'log'       => $log,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	/**
	 * Sincroniza un lote de pedidos
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector Conector a la API
	 * @param array                               $order_ids IDs de los pedidos a sincronizar
	 * @param array                               $filters Filtros adicionales para la sincronización
	 * @param bool                                $force_restart Fuerza reiniciar desde el principio
	 * @return array Resultado de la sincronización
	 */
	public static function sync_batch( \MiIntegracionApi\Core\ApiConnector $api_connector, array $order_ids = array(), array $filters = array(), bool $force_restart = false ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new \WP_Error( 'woocommerce_missing', __( 'WooCommerce no está activo.', 'mi-integracion-api' ) );
		}
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return new \WP_Error( 'sync_locked', __( 'Ya hay una sincronización en curso.', 'mi-integracion-api' ) );
		}

		$processed = 0;
		$errors    = 0;
		$log       = array();
		$start_time = microtime(true);
		try {
			$query_args = $filters;
			if ( ! empty( $order_ids ) ) {
				$query_args['post__in'] = $order_ids;
			}
			if ( empty( $query_args['status'] ) ) {
				$query_args['status'] = array( 'wc-processing', 'wc-completed' );
			}
			$filtered_order_ids = \MiIntegracionApi\Helpers\FilterOrders::advanced( $query_args );
			if ( empty( $filtered_order_ids ) ) {
				$log_msg = __( 'No se encontraron pedidos con los filtros aplicados.', 'mi-integracion-api' );
				\MiIntegracionApi\helpers\Logger::info( $log_msg . ' Filtros: ' . wp_json_encode( $filters ), array( 'context' => 'sync-pedidos-batch' ) );
				return array(
					'success'   => true,
					'message'   => $log_msg,
					'processed' => 0,
					'errors'    => 0,
					'log'       => array( $log_msg ),
					'filters'   => $filters,
				);
			}
			
			\MiIntegracionApi\helpers\Logger::info( 'Iniciando sincronización batch de ' . count( $filtered_order_ids ) . ' pedidos. Filtros: ' . wp_json_encode( $filters ), array( 'context' => 'sync-pedidos-batch' ) );
			\MiIntegracionApi\helpers\Logger::filters( 'pedidos_wc_batch', $filters, array( 'context' => 'sync-pedidos-batch' ) );
			
			// Crear instancia del procesador de lotes con soporte de recuperación
			$batcher = new BatchProcessor($api_connector);
			$batcher->set_entity_name(self::ENTITY_NAME)
			       ->set_filters($filters);
			
			// Verificar si existe un punto de recuperación
			$recovery_message = '';
			if ($batcher->check_recovery_point() && !$force_restart) {
				$recovery_message = $batcher->get_recovery_message();
				\MiIntegracionApi\Helpers\Logger::info(
					'Punto de recuperación detectado para sincronización de pedidos', 
					array(
						'category' => 'sync-pedidos-recovery',
						'message' => $recovery_message
					)
				);
			}
			
			// Procesar pedidos con recuperación mediante una función de callback
			$result = $batcher->process($filtered_order_ids, 25, function($order_id, $api_connector) {
				$order = wc_get_order( $order_id );
				if ( ! $order instanceof \WC_Order ) {
					throw new \RuntimeException(sprintf( __( 'Pedido ID %d no encontrado o inválido (batch).', 'mi-integracion-api' ), $order_id ));
				}
				
				// Lógica de sincronización del pedido
				$item_result = self::process_single_order($order, $api_connector);
				
				// Retornamos el resultado
				return $item_result;
			}, $force_restart);
			
			// Si hubo un punto de recuperación, añadir el mensaje al resultado
			if (!empty($recovery_message)) {
				$result['recovery_message'] = $recovery_message;
				$result['resumed'] = true;
			}
			
			// Añadir información adicional y asegurar estructura estándar en el resultado
			$result['filters']    = $filters;
			$result['memory_mb']  = round(memory_get_peak_usage() / 1024 / 1024, 2);
			$result['duration']   = round(microtime(true) - $start_time, 2);
			$result['success']    = isset($result['success']) ? $result['success'] : ($result['errors'] ?? 0) === 0;
			$result['processed']  = $result['processed'] ?? 0;
			$result['errors']     = $result['errors'] ?? 0;
			$result['log']        = $result['log'] ?? [];
			$result['message']    = $result['message'] ?? sprintf(
				__( 'Sincronización batch de pedidos completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$result['processed'],
				$result['errors']
			);

			// Procesar cola de reintentos al final de la sincronización
			self::process_retry_queue($api_connector);

			\MiIntegracionApi\helpers\Logger::sync_operation('pedidos_batch', [
				'total'         => count($filtered_order_ids),
				'procesados'    => $result['processed'],
				'errores'       => $result['errors'],
				'filtros'       => $filters,
				'duration'      => $result['duration'],
				'memory_peak_mb'=> $result['memory_mb']
			]);

			return $result;
			
		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización batch de pedidos: %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-pedidos-batch' ) );
			$log[] = $exception_msg;
			return array(
				'success'   => false,
				'message'   => $exception_msg,
				'processed' => $processed,
				'errors'    => $errors + 1,
				'log'       => $log,
				'filters'   => $filters,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	/**
	 * Procesa un pedido individual
	 *
	 * @param int $pedido_id ID del pedido a procesar
	 */
	public static function procesar_pedido( $pedido_id ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		// ...lógica...
	}

	/**
	 * Restaura el estado de los pedidos en caso de fallo
	 *
	 * @param array $snapshots Instantáneas de los pedidos
	 * @param array $ids IDs de los pedidos a restaurar
	 */
	private static function rollback_pedidos( array $snapshots, array $ids ): void {
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order || ! isset( $snapshots[ $id ] ) ) {
				continue;
			}
			$snap = $snapshots[ $id ];
			$order->set_status( $snap['status'] );
			foreach ( $snap['meta'] as $meta ) {
				if ( isset( $meta->key ) ) {
					$order->update_meta_data( $meta->key, $meta->value );
				}
			}
			$order->save_meta_data();
			\MiIntegracionApi\helpers\Logger::info( 'Pedido restaurado tras rollback (ID: ' . $id . ')', array( 'context' => 'sync-pedidos-batch' ) );
		}
	}

	/**
	 * Procesa un pedido individual para la sincronización por lotes con soporte de recuperación
	 *
	 * @param \WC_Order $order Objeto del pedido
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector Conector a la API
	 * @return array Resultado del procesamiento
	 * @throws \RuntimeException Si hay errores durante el procesamiento
	 */
	private static function process_single_order( \WC_Order $order, \MiIntegracionApi\Core\ApiConnector $api_connector ) {
		// Verificar si el pedido ya está sincronizado y es modificable
		$verial_doc_id = $order->get_meta( '_verial_documento_id' );
		if ( $verial_doc_id ) {
			// Verificar si es modificable
			$modificable_resp = $api_connector->get(
				'PedidoModificableWS',
				array(
					'x'         => $api_connector->get_session_id(),
					'id_pedido' => $verial_doc_id,
				)
			);
			$es_modificable = is_array( $modificable_resp ) && isset( $modificable_resp['Modificable'] ) && $modificable_resp['Modificable'];
			
			if ( $es_modificable ) {
				// Actualizar pedido existente
				$payload_update = array(
					'sesionwcf' => $api_connector->get_session_id(),
					'Id'        => $verial_doc_id,
				);
				$referencia    = $order->get_order_number();
				if ( $referencia ) {
					$payload_update['Referencia'] = $referencia;
				}
				
				$resp_update = $api_connector->post( 'UpdateDocClienteWS', $payload_update );
				if ( is_wp_error( $resp_update ) ) {
					throw new \RuntimeException( sprintf( 
						__( 'Error al actualizar pedido ID %1$d en Verial: %2$s', 'mi-integracion-api' ), 
						$order->get_id(), 
						$resp_update->get_error_message() 
					));
				} elseif ( isset( $resp_update['InfoError']['Codigo'] ) && intval( $resp_update['InfoError']['Codigo'] ) === 0 ) {
					return [
						'success' => true,
						'message' => sprintf( __( 'Pedido ID %1$d actualizado en Verial (ID: %2$s).', 'mi-integracion-api' ), $order->get_id(), $verial_doc_id ),
					];
				} else {
					$error_desc = isset( $resp_update['InfoError']['Descripcion'] ) ? $resp_update['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
					throw new \RuntimeException( sprintf(
						__( 'Error al actualizar pedido ID %1$d en Verial: %2$s', 'mi-integracion-api' ),
						$order->get_id(),
						$error_desc
					));
				}
			} else {
				// Pedido ya sincronizado y no modificable
				return [
					'success' => true,
					'message' => sprintf( __( 'Pedido ID %1$d omitido: ya sincronizado y no modificable en Verial (ID: %2$s).', 'mi-integracion-api' ), $order->get_id(), $verial_doc_id ),
				];
			}
		}
		
		// Crear nuevo documento en Verial
		$payload_documento_verial = \MiIntegracionApi\Helpers\Map_Order::wc_to_verial( $order, array(), null );
		
		// Validar información del cliente
		$cliente_info_presente = isset( $payload_documento_verial['ID_Cliente'] ) || (
			isset( $payload_documento_verial['Cliente'] ) &&
			is_array( $payload_documento_verial['Cliente'] ) &&
			! empty( $payload_documento_verial['Cliente']['Email'] ) &&
			filter_var( $payload_documento_verial['Cliente']['Email'], FILTER_VALIDATE_EMAIL )
		);
		
		if ( ! $cliente_info_presente ) {
			throw new \RuntimeException( sprintf( 
				__( 'Pedido ID %d omitido: Información del cliente insuficiente para Verial.', 'mi-integracion-api' ), 
				$order->get_id() 
			));
		}
		
		// Validar líneas de contenido
		if ( empty( $payload_documento_verial['Contenido'] ) || ! is_array( $payload_documento_verial['Contenido'] ) ) {
			throw new \RuntimeException( sprintf( 
				__( 'Pedido ID %d omitido: No se generaron líneas de contenido válidas para Verial.', 'mi-integracion-api' ), 
				$order->get_id() 
			));
		}
		
		// Hash de datos relevantes para control de cambios reales
		$hash_actual = md5(
			json_encode(
				array(
					$payload_documento_verial['Referencia'] ?? '',
					$payload_documento_verial['Cliente']['Email'] ?? '',
					$payload_documento_verial['TotalImporte'] ?? '',
					$payload_documento_verial['Contenido'] ?? array(),
				)
			)
		);
		
		$hash_guardado = $order->get_meta( '_verial_sync_hash' );
		if ( $hash_guardado && $hash_actual === $hash_guardado ) {
			return [
				'success' => true,
				'message' => sprintf( __( 'Pedido ID %d omitido (hash sin cambios).', 'mi-integracion-api' ), $order->get_id() ),
			];
		}
		
		// Enviar a Verial
		$response_verial = $api_connector->post( 'NuevoDocClienteWS', $payload_documento_verial );
		
		// Procesar respuesta
		if ( is_wp_error( $response_verial ) ) {
			throw new \RuntimeException( sprintf( 
				__( 'Error al sincronizar pedido ID %1$d con Verial: %2$s', 'mi-integracion-api' ), 
				$order->get_id(), 
				$response_verial->get_error_message() 
			));
		} elseif ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
			// Éxito - actualizar metadatos
			if ( isset( $response_verial['Id'] ) ) {
				$order->update_meta_data( '_verial_documento_id', intval( $response_verial['Id'] ) );
			}
			if ( isset( $response_verial['Numero'] ) ) {
				$order->update_meta_data( '_verial_documento_numero', sanitize_text_field( $response_verial['Numero'] ) );
			}
			$order->update_meta_data( '_verial_sync_hash', $hash_actual );
			$order->update_meta_data( '_verial_sync_last', current_time( 'mysql' ) );
			$order->save_meta_data();
			
			return [
				'success' => true,
				'message' => sprintf( 
					__( 'Pedido ID %1$d sincronizado. ID Documento Verial: %2$s, Número Verial: %3$s', 'mi-integracion-api' ), 
					$order->get_id(), 
					( $response_verial['Id'] ?? 'N/A' ), 
					( $response_verial['Numero'] ?? 'N/A' )
				),
			];
		} else {
			// Error de Verial
			$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
			throw new \RuntimeException( sprintf( 
				__( 'Error al sincronizar pedido ID %1$d con Verial: %2$s', 'mi-integracion-api' ), 
				$order->get_id(), 
				$error_desc 
			));
		}
	}

	/**
	 * Sincroniza un pedido con la API externa
	 * 
	 * @param array $pedido Datos del pedido a sincronizar
	 * @return array Resultado de la operación
	 */
	public function sync_pedido($pedido) {
		$operation_id = uniqid('pedido_sync_');
		$this->metrics->startOperation($operation_id, 'pedidos', 'push');
		
		try {
			if (empty($pedido['numero'])) {
				throw new SyncError('Número de pedido no proporcionado', 400);
			}

			// Verificar memoria antes de procesar
			if (!$this->metrics->checkMemoryUsage($operation_id)) {
				throw new SyncError('Umbral de memoria alcanzado', 500);
			}

			// Ejecutar la sincronización dentro de una transacción
			$result = TransactionManager::getInstance()->executeInTransaction(
				function() use ($pedido, $operation_id) {
					return $this->retryOperation(
						function() use ($pedido) {
							return $this->sincronizarPedido($pedido);
						},
						[
							'operation_id' => $operation_id,
							'numero' => $pedido['numero'],
							'pedido_id' => $pedido['id'] ?? null
						]
					);
				},
				'pedidos',
				$operation_id
			);

			$this->metrics->recordItemProcessed($operation_id, true);
			return [
				'success' => true,
				'message' => 'Pedido sincronizado correctamente',
				'data' => $result
			];

		} catch (SyncError $e) {
			$this->metrics->recordError(
				$operation_id,
				'sync_error',
				$e->getMessage(),
				['numero' => $pedido['numero'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error sincronizando pedido", [
				'numero' => $pedido['numero'] ?? 'unknown',
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
				['numero' => $pedido['numero'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error inesperado sincronizando pedido", [
				'numero' => $pedido['numero'] ?? 'unknown',
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

	/**
	 * Procesa un lote de pedidos
	 * 
	 * @param array<int, array<string, mixed>> $batch Lote de pedidos
	 * @param string $direction Dirección de sincronización
	 * @param array<string, mixed> $options Opciones adicionales
	 * @return array<string, mixed> Resultado del procesamiento
	 */
	protected function processBatch(array $batch, string $direction, array $options = []): array
	{
		$results = [
			'success' => true,
			'processed' => 0,
			'errors' => 0,
			'details' => []
		];

		foreach ($batch as $index => $pedido) {
			try {
				// Validar pedido
				$this->validator->validate($pedido);

				// Verificar memoria disponible
				if (!$this->memory->checkMemory()) {
					throw SyncError::memoryError(
						"Memoria insuficiente para procesar el pedido"
					);
				}

				// Iniciar transacción para el pedido
				$this->transaction->beginTransaction(
					'order',
					"sync_order_{$pedido['id']}"
				);

				// Procesar pedido con reintentos
				$result = $this->retry->executeWithRetry(
					fn() => $this->processItem($pedido),
					"sync_order_{$pedido['id']}",
					['id' => $pedido['id']]
				);

				// Confirmar transacción
				$this->transaction->commit('order');

				$results['processed']++;
				$results['details'][] = [
					'id' => $pedido['id'],
					'success' => true,
					'result' => $result
				];

			} catch (SyncError $e) {
				// Revertir transacción en caso de error
				if ($this->transaction->isActive()) {
					$this->transaction->rollback('order');
				}

				$this->logger->error(
					"Error al procesar pedido en lote",
					[
						'id' => $pedido['id'] ?? 'unknown',
						'error' => $e->getMessage(),
						'code' => $e->getCode()
					]
				);

				$results['errors']++;
				$results['details'][] = [
					'id' => $pedido['id'] ?? 'unknown',
					'success' => false,
					'error' => $e->getMessage(),
					'code' => $e->getCode()
				];

				if (!$e->isRetryable()) {
					$results['success'] = false;
				}
			}
		}

		// Limpiar memoria después del lote
		$this->memory->cleanup();

		return $results;
	}

	/**
	 * Procesa un pedido individual
	 * 
	 * @param array<string, mixed> $pedido Datos del pedido
	 * @return array<string, mixed> Resultado del procesamiento
	 * @throws SyncError Si ocurre un error durante el procesamiento
	 */
	protected function processItem(array $pedido): array
	{
		// Implementar lógica específica de procesamiento
		// Este método debe ser implementado según los requisitos específicos
		throw new \RuntimeException('Método processItem debe ser implementado');
	}
}

// Fin de la clase MI_Sync_Pedidos
