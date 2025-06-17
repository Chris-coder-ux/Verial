<?php
namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Helpers\Map_Customer;
use MiIntegracionApi\Helpers\Validation;
use MiIntegracionApi\Sync\MI_Sync_Lock;
use MiIntegracionApi\Core\SyncError;
use MiIntegracionApi\Core\Validation\CustomerValidator;
use MiIntegracionApi\Core\BatchProcessor;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Core\TransactionManager;
use MiIntegracionApi\Core\ConfigManager;

/**
 * Clase para la sincronización de clientes
 *
 * Maneja la sincronización de clientes con el sistema externo,
 * incluyendo reintentos automáticos en caso de fallo.
 *
 * @package MiIntegracionApi
 * @subpackage Sync
 * @since 1.0.0
 */

class SyncClientes extends BatchProcessor {
	private CustomerValidator $validator;
	private MemoryManager $memory;
	private RetryManager $retry;
	private TransactionManager $transaction;
	private Logger $logger;

	public function __construct() {
		parent::__construct();
		$this->validator = new CustomerValidator();
		$this->memory = new MemoryManager();
		$this->retry = new RetryManager();
		$this->transaction = new TransactionManager();
		$this->logger = new Logger();
	}

	const RETRY_OPTION = 'mia_sync_clientes_retry';
	const MAX_RETRIES  = 3;
	const RETRY_DELAY  = 300; // segundos entre reintentos (5 min)

	private static function add_to_retry_queue( $email, $error_msg ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( ! isset( $queue[ $email ] ) ) {
			$queue[ $email ] = array(
				'attempts'     => 1,
				'last_attempt' => time(),
				'error'        => $error_msg,
			);
		} else {
			++$queue[ $email ]['attempts'];
			$queue[ $email ]['last_attempt'] = time();
			$queue[ $email ]['error']        = $error_msg;
		}
		update_option( self::RETRY_OPTION, $queue, false );
	}
	private static function remove_from_retry_queue( $email ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( isset( $queue[ $email ] ) ) {
			unset( $queue[ $email ] );
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
	public static function process_retry_queue( \MiIntegracionApi\Core\ApiConnector $api_connector ) {
		$queue = get_option( self::RETRY_OPTION, array() );
		if ( empty( $queue ) ) {
			return;
		}
		foreach ( $queue as $email => $info ) {
			if ( $info['attempts'] >= self::MAX_RETRIES ) {

				$msg = sprintf( __( 'Cliente %1$s falló tras %2$d reintentos: %3$s', 'mi-integracion-api' ), $email, $info['attempts'], $info['error'] );
				\MiIntegracionApi\Helpers\Logger::critical( $msg, array( 
					'category' => 'sync-clientes-retry',
					'email' => $email,
					'attempts' => $info['attempts'],
					'last_error' => $info['error'],
					'retry_queue_size' => count($queue)
				));
				$alert_email = self::get_alert_email();
				wp_mail( $alert_email, __( 'Cliente no sincronizado tras reintentos', 'mi-integracion-api' ), $msg );
				// (Opcional) Registrar en tabla de incidencias si se implementa
				self::remove_from_retry_queue( $email );
				continue;
			}
			if ( time() - $info['last_attempt'] < self::RETRY_DELAY ) {
				continue;
			}
			$user = get_user_by( 'email', $email );
			if ( ! $user ) {
				self::remove_from_retry_queue( $email );
				continue;
			}
			$payload_cliente_verial = \MiIntegracionApi\Helpers\Map_Customer::wc_to_verial( $user );
			$response_verial        = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
			if ( is_wp_error( $response_verial ) ) {
				self::add_to_retry_queue( $email, $response_verial->get_error_message() );
				continue;
			}
			if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
				if ( isset( $response_verial['Id'] ) ) {
					update_user_meta( $user->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
				}
				$msg = sprintf( __( 'Cliente %s sincronizado tras reintento.', 'mi-integracion-api' ), $email );
				\MiIntegracionApi\Helpers\Logger::info( $msg, array( 
					'category' => 'sync-clientes-retry',
					'email' => $email,
					'usuario_id' => $user->ID,
					'attempts' => $info['attempts'],
					'verial_id' => isset($response_verial['Id']) ? intval($response_verial['Id']) : null,
					'tiempo_total_ms' => round((time() - $info['last_attempt']) * 1000)
				));
				self::remove_from_retry_queue( $email );
			} else {
				$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
				self::add_to_retry_queue( $email, $error_desc );
			}
		}
	}
	public static function sync(
		\MiIntegracionApi\Core\ApiConnector $api_connector,
		$batch_size = 50,
		$offset = 0,
		$fecha_desde = null
	) {
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return new \WP_Error( 'sync_locked', __( 'Ya hay una sincronización en curso o falta MI_Sync_Lock.', 'mi-integracion-api' ) );
		}
		if ( ! class_exists( '\MiIntegracionApi\Helpers\Map_Customer' ) ) {
			return array(
				'success'   => false,
				'message'   => __( 'Clase Map_Customer no disponible.', 'mi-integracion-api' ),
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
			$args = array(
				'role'    => 'customer',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'fields'  => 'all',
				'number'  => $batch_size,
				'offset'  => $offset,
			);
			// --- Sincronización incremental: filtrar por fecha si se indica ---
			if ( $fecha_desde ) {
				$args['date_query'] = array(
					array(
						'column' => 'user_registered',
						'after'  => $fecha_desde,
					),
				);
			}
			$user_query  = new \WP_User_Query( $args );
			$clientes_wc = $user_query->get_results();
			if ( empty( $clientes_wc ) ) {
				\MiIntegracionApi\Helpers\Logger::info( 'No se encontraron clientes con rol "customer" en WooCommerce para sincronizar.', array( 
					'category' => 'sync-clientes',
					'batch_size' => $batch_size, 
					'offset' => $offset,
					'fecha_desde' => $fecha_desde
				));
				return array(
					'success'     => true,
					'message'     => __( 'No se encontraron clientes en WooCommerce para sincronizar.', 'mi-integracion-api' ),
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( __( 'No se encontraron clientes en WooCommerce para sincronizar.', 'mi-integracion-api' ) ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			\MiIntegracionApi\Helpers\Logger::info( 'Iniciando sincronización de ' . count( $clientes_wc ) . ' clientes de WooCommerce a Verial.', array( 
				'category' => 'sync-clientes',
				'total_clientes' => count($clientes_wc),
				'batch_size' => $batch_size,
				'offset' => $offset,
				'fecha_desde' => $fecha_desde,
				'memory_start' => memory_get_usage(true)
			));
			foreach ( $clientes_wc as $cliente_wc ) {
				$payload_cliente_verial = \MiIntegracionApi\Helpers\Map_Customer::wc_to_verial( $cliente_wc );
				if ( empty( $payload_cliente_verial['Email'] ) || ! \MiIntegracionApi\Helpers\Validation::is_email( $payload_cliente_verial['Email'] ) ) {
					++$errors;
					$error_msg = sprintf( __( 'Cliente ID %s omitido: Email inválido o faltante.', 'mi-integracion-api' ), $cliente_wc->ID );
					$log[]     = $error_msg;
					\MiIntegracionApi\Helpers\Logger::warning( $error_msg, array( 
						'category' => 'sync-clientes',
						'usuario_id' => $cliente_wc->ID, 
						'email' => $payload_cliente_verial['Email'] ?? 'no_disponible',
						'problema' => empty($payload_cliente_verial['Email']) ? 'email_vacio' : 'formato_email_invalido'
					));
					continue;
				}
				// --- Control de duplicados en WooCommerce (email, _verial_cliente_id, NIF/DNI) ---
				$existing_user     = get_user_by( 'email', $payload_cliente_verial['Email'] );
				$id_externo_verial = isset( $payload_cliente_verial['Id'] ) ? $payload_cliente_verial['Id'] : null;
				$user_by_verial_id = null;
				if ( $id_externo_verial ) {
					$user_query = new \WP_User_Query(
						array(
							'meta_key'   => '_verial_cliente_id',
							'meta_value' => $id_externo_verial,
							'number'     => 1,
						)
					);
					$results    = $user_query->get_results();
					if ( ! empty( $results ) ) {
						$user_by_verial_id = $results[0];
					}
				}
				// Comprobar duplicados por NIF/DNI si existe
				$nif = isset( $payload_cliente_verial['NIF'] ) ? $payload_cliente_verial['NIF'] : '';
				if ( $nif ) {
					$user_query_nif = new \WP_User_Query(
						array(
							'meta_key'   => 'billing_nif',
							'meta_value' => $nif,
							'number'     => 1,
						)
					);
					$results_nif    = $user_query_nif->get_results();
					if ( ! empty( $results_nif ) && $results_nif[0]->ID != $cliente_wc->ID ) {

						$msg   = sprintf( __( 'Cliente duplicado detectado por NIF/DNI en WooCommerce (NIF: %1$s, ID: %2$d). Se omite la creación/actualización.', 'mi-integracion-api' ), $nif, $results_nif[0]->ID );
						$log[] = $msg;
						\MiIntegracionApi\Helpers\Logger::warning( $msg, array( 
							'category' => 'sync-clientes-duplicados',
							'nif' => $nif,
							'usuario_id_original' => $cliente_wc->ID,
							'usuario_id_duplicado' => $results_nif[0]->ID,
							'tipo_duplicado' => 'nif',
							'email' => $payload_cliente_verial['Email'] ?? 'no_disponible'
						));
						// Alerta proactiva por email al admin
						$alert_email = self::get_alert_email();
						wp_mail( $alert_email, '[Verial/WC] Duplicado crítico de cliente', $msg );
						continue;
					}
				}
				if ( ( $existing_user && $existing_user->ID != $cliente_wc->ID ) || ( $user_by_verial_id && $user_by_verial_id->ID != $cliente_wc->ID ) ) {

					$msg   = sprintf( __( 'Cliente duplicado detectado en WooCommerce (Email: %1$s, ID externo Verial: %2$s). Se omite la creación/actualización.', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $id_externo_verial );
					$log[] = $msg;
					\MiIntegracionApi\Helpers\Logger::warning( $msg, array( 
						'category' => 'sync-clientes-duplicados',
						'email' => $payload_cliente_verial['Email'],
						'verial_id' => $id_externo_verial,
						'usuario_id_original' => $cliente_wc->ID,
						'usuario_id_duplicado' => $existing_user ? $existing_user->ID : ($user_by_verial_id ? $user_by_verial_id->ID : 'desconocido'),
						'tipo_duplicado' => $existing_user ? 'email' : 'verial_id'
					));
					// Alerta proactiva por email al admin
					$alert_email = self::get_alert_email();
					wp_mail( $alert_email, '[Verial/WC] Duplicado crítico de cliente', $msg );
					continue;
				}
				// --- Hash de sincronización: incluir campos clave y metadatos relevantes ---
				$hash_fields = array(
					$payload_cliente_verial['Email'],
					$payload_cliente_verial['Nombre'] ?? '',
					$payload_cliente_verial['Telefono'] ?? '',
					$payload_cliente_verial['Direccion'] ?? '',
					$payload_cliente_verial['NIF'] ?? '',
					isset( $payload_cliente_verial['meta_data'] ) ? wp_json_encode( $payload_cliente_verial['meta_data'] ) : '',
				);
				// Documentación: los campos incluidos en el hash son: email, nombre, teléfono, dirección, NIF/DNI y metadatos personalizados.
				$hash_actual   = md5( json_encode( $hash_fields ) );
				$hash_guardado = get_user_meta( $cliente_wc->ID, '_verial_sync_hash', true );
				if ( $hash_guardado && $hash_actual === $hash_guardado ) {
					$log[] = sprintf( __( 'Cliente %s omitido (hash sin cambios).', 'mi-integracion-api' ), $payload_cliente_verial['Email'] );
					continue;
				}
				// Si existe en Verial, actualizar; si no, crear
				if ( $cliente_verial && isset( $cliente_verial['Id'] ) ) {
					$payload_cliente_verial['Id'] = $cliente_verial['Id'];
					$response_verial              = $api_connector->post( 'ActualizarClienteWS', $payload_cliente_verial );
					$accion                       = 'actualizado';
				} else {
					$response_verial = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
					$accion          = 'creado';
				}
				if ( is_wp_error( $response_verial ) ) {
					// Solo reintentar en errores temporales (red, HTTP 5xx, timeout)
					$err = $response_verial->get_error_code();
					if ( strpos( $err, 'http_error_5' ) !== false || strpos( $err, 'connection' ) !== false || strpos( $err, 'timeout' ) !== false ) {
						self::add_to_retry_queue( $payload_cliente_verial['Email'], $response_verial->get_error_message() );
					}
					++$errors;

					$error_msg = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d): %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $response_verial->get_error_message() );
					$log[]     = $error_msg;
					\MiIntegracionApi\Helpers\Logger::error( $error_msg, array( 
						'category' => 'sync-clientes',
						'email' => $payload_cliente_verial['Email'],
						'usuario_id' => $cliente_wc->ID,
						'error_code' => $response_verial->get_error_code(),
						'error_message' => $response_verial->get_error_message(),
						'retry_queued' => strpos($response_verial->get_error_code(), 'http_error_5') !== false || 
										 strpos($response_verial->get_error_code(), 'connection') !== false || 
										 strpos($response_verial->get_error_code(), 'timeout') !== false
					));
					continue;
				}
				if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
					++$processed;

					$log_msg = sprintf( __( 'Cliente %1$s %2$s correctamente (ID WC: %3$d, ID Verial: %4$s)', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $accion, $cliente_wc->ID, ( $response_verial['Id'] ?? 'N/A' ) );
					// Registrar operación de entidad usando el nuevo método especializado
					\MiIntegracionApi\Helpers\Logger::entity_operation('cliente', $accion == 'creado' ? 'create' : 'update', $cliente_wc->ID, [
						'email' => $payload_cliente_verial['Email'],
						'verial_id' => $response_verial['Id'] ?? null,
						'campos' => array_keys($payload_cliente_verial),
						'hash' => $hash_actual,
						'hash_anterior' => $hash_guardado
					]);
					$log[]   = $log_msg;
					if ( isset( $response_verial['Id'] ) ) {
						update_user_meta( $cliente_wc->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
					}
					update_user_meta( $cliente_wc->ID, '_verial_sync_hash', $hash_actual );
					update_user_meta( $cliente_wc->ID, '_verial_sync_last', current_time( 'mysql' ) );
				} else {
					++$errors;
					$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
					$error_msg  = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d) con Verial: %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $error_desc );
					$log[]      = $error_msg;
					\MiIntegracionApi\Helpers\Logger::error( $error_msg, array( 
						'category' => 'sync-clientes',
						'email' => $payload_cliente_verial['Email'],
						'usuario_id' => $cliente_wc->ID,
						'accion' => $accion,
						'error_code' => $response_verial['InfoError']['Codigo'] ?? 'desconocido',
						'error_desc' => $error_desc,
						'response' => $response_verial
					));
				}
			}
			$final_message = sprintf(
				__( 'Sincronización de clientes completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$processed,
				$errors
			);
			
			// Usar el nuevo método específico para operaciones de sincronización
			\MiIntegracionApi\Helpers\Logger::sync_operation('clientes', [
				'total' => count($clientes_wc),
				'procesados' => $processed,
				'errores' => $errors,
				'hash_omitidos' => count($clientes_wc) - $processed - $errors,
				'batch_size' => $batch_size,
				'offset' => $offset,
				'memory_pico' => size_format(memory_get_peak_usage(true), 2)
			], $errors > 0 ? 'partial' : 'success');
			return array(
				'success'     => $errors === 0,
				'message'     => $final_message,
				'processed'   => $processed,
				'errors'      => $errors,
				'log'         => $log,
				'next_offset' => $offset + $batch_size,
				'has_more'    => count( $clientes_wc ) === $batch_size,
			);
		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización de clientes: %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\Helpers\Logger::exception($e, $exception_msg, [
				'category' => 'sync-clientes',
				'procesados_antes_excepcion' => $processed,
				'batch_size' => $batch_size,
				'offset' => $offset
			]);
			$log[] = $exception_msg;
			return array(
				'success'     => false,
				'message'     => $exception_msg,
				'processed'   => $processed,
				'errors'      => $errors + 1,
				'log'         => $log,
				'next_offset' => $offset,
				'has_more'    => false,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	public static function sync_batch( \MiIntegracionApi\Core\ApiConnector $api_connector, array $user_ids = array(), array $filters = array(), $batch_size = 50, $offset = 0 ) {
		if ( ! class_exists( 'MI_Sync_Lock' ) || ! MI_Sync_Lock::acquire() ) {
			return new \WP_Error( 'sync_locked', __( 'Ya hay una sincronización en curso.', 'mi-integracion-api' ) );
		}
		$processed = 0;
		$errors    = 0;
		$log       = array();
		try {
			// Nuevo: usar helper como clase autoloaded
			$query_args         = $filters;
			$query_args['role'] = 'customer';
			if ( ! empty( $user_ids ) ) {
				$query_args['include'] = $user_ids;
			}
			$filtered_user_ids = \MiIntegracionApi\Helpers\FilterCustomers::advanced( $query_args );
			if ( empty( $filtered_user_ids ) ) {
				$log_msg = __( 'No se encontraron clientes con los filtros aplicados.', 'mi-integracion-api' );
				\MiIntegracionApi\helpers\Logger::info( $log_msg . ' Filtros: ' . wp_json_encode( $filters ), array( 'context' => 'sync-clientes-batch' ) );
				return array(
					'success'     => true,
					'message'     => $log_msg,
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( $log_msg ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			// Procesar solo el lote actual
			$user_ids_batch = array_slice( $filtered_user_ids, $offset, $batch_size );
			if ( empty( $user_ids_batch ) ) {
				$log_msg = __( 'No hay más clientes para procesar en este lote.', 'mi-integracion-api' );
				return array(
					'success'     => true,
					'message'     => $log_msg,
					'processed'   => 0,
					'errors'      => 0,
					'log'         => array( $log_msg ),
					'next_offset' => $offset,
					'has_more'    => false,
				);
			}
			$rollback_snapshots = array();
			$rollback_ids       = array();
			$clientes_wc        = array_map(
				function ( $user_id ) {
					return get_userdata( $user_id );
				},
				$user_ids_batch
			);
			foreach ( $clientes_wc as $cliente_wc ) {
				// --- Captura snapshot antes de modificar ---
				$rollback_snapshots[ $cliente_wc->ID ] = array(
					'meta' => get_user_meta( $cliente_wc->ID ),
				);
				$payload_cliente_verial                = \MiIntegracionApi\Helpers\Map_Customer::wc_to_verial( $cliente_wc );
				// --- Hook para resolución de conflictos ---
				$payload_cliente_verial = apply_filters( 'mi_integracion_api_resolver_conflicto_cliente', $payload_cliente_verial, $cliente_wc );
				if ( empty( $payload_cliente_verial['Email'] ) || ! \MiIntegracionApi\Helpers\Validation::is_email( $payload_cliente_verial['Email'] ) ) {
					++$errors;
					$error_msg = sprintf( __( 'Cliente ID %s omitido: Email inválido o faltante.', 'mi-integracion-api' ), $cliente_wc->ID );
					$log[]     = $error_msg;
					\MiIntegracionApi\helpers\Logger::warning( $error_msg, array( 'context' => 'sync-clientes-batch' ) );
					continue;
				}
				try {
					$response_verial = $api_connector->post( 'NuevoClienteWS', $payload_cliente_verial );
					if ( is_wp_error( $response_verial ) ) {
						// Manejo del error
						throw new \Exception( $response_verial->get_error_message() );
					}
					if ( isset( $response_verial['InfoError']['Codigo'] ) && intval( $response_verial['InfoError']['Codigo'] ) === 0 ) {
						++$processed;

						$log_msg = sprintf( __( 'Cliente sincronizado: %1$s (ID WC: %2$d, ID Verial: %3$s)', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, ( $response_verial['Id'] ?? 'N/A' ) );
						$log[]   = $log_msg;
						if ( isset( $response_verial['Id'] ) ) {
							update_user_meta( $cliente_wc->ID, '_verial_cliente_id', intval( $response_verial['Id'] ) );
						}
						$rollback_ids[] = $cliente_wc->ID;
					} else {
						++$errors;
						$error_desc = isset( $response_verial['InfoError']['Descripcion'] ) ? $response_verial['InfoError']['Descripcion'] : __( 'Error desconocido de Verial', 'mi-integracion-api' );
						$error_msg  = sprintf( __( 'Error al sincronizar cliente %1$s (ID: %2$d) con Verial: %3$s', 'mi-integracion-api' ), $payload_cliente_verial['Email'], $cliente_wc->ID, $error_desc );
						$log[]      = $error_msg;
						\MiIntegracionApi\helpers\Logger::error( $error_msg . ' Respuesta Verial: ' . wp_json_encode( $response_verial ), array( 'context' => 'sync-clientes-batch' ) );
					}
				} catch ( \Exception $e ) {

					$exception_msg = sprintf( __( 'Excepción durante la sincronización de cliente (ID: %1$d): %2$s', 'mi-integracion-api' ), $cliente_wc->ID, $e->getMessage() );
					\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-clientes-batch' ) );
					$log[] = $exception_msg;
					// --- Rollback inmediato si error crítico ---
					self::rollback_clientes( $rollback_snapshots, $rollback_ids );
					\MiIntegracionApi\helpers\Logger::critical( 'Rollback ejecutado para clientes afectados tras error crítico en lote.', array( 'context' => 'sync-clientes-batch' ) );
					break;
				}
			}
			$final_message = sprintf(
				__( 'Sincronización de clientes (batch) completada. Procesados: %1$d, Errores: %2$d.', 'mi-integracion-api' ),
				$processed,
				$errors
			);
			\MiIntegracionApi\helpers\Logger::info( $final_message, array( 'context' => 'sync-clientes-batch' ) );
			return array(
				'success'     => $errors === 0,
				'message'     => $final_message,
				'processed'   => $processed,
				'errors'      => $errors,
				'log'         => $log,
				'next_offset' => $offset + $batch_size,
				'has_more'    => ( $offset + $batch_size ) < count( $filtered_user_ids ),
			);
		} catch ( \Exception $e ) {

			$exception_msg = sprintf( __( 'Excepción durante la sincronización de clientes (batch): %s', 'mi-integracion-api' ), $e->getMessage() );
			\MiIntegracionApi\helpers\Logger::critical( $exception_msg, array( 'context' => 'sync-clientes-batch' ) );
			$log[] = $exception_msg;
			return array(
				'success'     => false,
				'message'     => $exception_msg,
				'processed'   => $processed,
				'errors'      => $errors + 1,
				'log'         => $log,
				'next_offset' => $offset,
				'has_more'    => false,
			);
		} finally {
			if ( class_exists( 'MI_Sync_Lock' ) ) {
				MI_Sync_Lock::release();
			}
		}
	}

	private static function rollback_clientes( array $snapshots, array $ids ): void {
		foreach ( $ids as $id ) {
			if ( ! isset( $snapshots[ $id ] ) ) {
				continue;
			}
			foreach ( $snapshots[ $id ]['meta'] as $meta_key => $meta_values ) {
				delete_user_meta( $id, $meta_key );
				foreach ( $meta_values as $meta_value ) {
					add_user_meta( $id, $meta_key, $meta_value );
				}
			}
			\MiIntegracionApi\helpers\Logger::info( 'Cliente restaurado tras rollback (ID: ' . $id . ')', array( 'context' => 'sync-clientes-batch' ) );
		}
	}

	/**
	 * Sincroniza un cliente con la API externa
	 * 
	 * @param array $cliente Datos del cliente a sincronizar
	 * @return array Resultado de la operación
	 */
	public function sync_cliente($cliente) {
		$operation_id = uniqid('cliente_sync_');
		$this->metrics->startOperation($operation_id, 'clientes', 'push');
		
		try {
			if (empty($cliente['dni'])) {
				throw new SyncError('DNI del cliente no proporcionado', 400);
			}

			// Verificar memoria antes de procesar
			if (!$this->metrics->checkMemoryUsage($operation_id)) {
				throw new SyncError('Umbral de memoria alcanzado', 500);
			}

			// Ejecutar la sincronización dentro de una transacción
			$result = TransactionManager::getInstance()->executeInTransaction(
				function() use ($cliente, $operation_id) {
					return $this->retryOperation(
						function() use ($cliente) {
							return $this->sincronizarCliente($cliente);
						},
						[
							'operation_id' => $operation_id,
							'dni' => $cliente['dni'],
							'cliente_id' => $cliente['id'] ?? null
						]
					);
				},
				'clientes',
				$operation_id
			);

			$this->metrics->recordItemProcessed($operation_id, true);
			return [
				'success' => true,
				'message' => 'Cliente sincronizado correctamente',
				'data' => $result
			];

		} catch (SyncError $e) {
			$this->metrics->recordError(
				$operation_id,
				'sync_error',
				$e->getMessage(),
				['dni' => $cliente['dni'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error sincronizando cliente", [
				'dni' => $cliente['dni'] ?? 'unknown',
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
				['dni' => $cliente['dni'] ?? 'unknown'],
				$e->getCode()
			);
			$this->metrics->recordItemProcessed($operation_id, false, $e->getMessage());
			
			$this->logger->error("Error inesperado sincronizando cliente", [
				'dni' => $cliente['dni'] ?? 'unknown',
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
	 * Procesa un lote de clientes
	 * 
	 * @param array<int, array<string, mixed>> $batch Lote de clientes
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

		foreach ($batch as $index => $cliente) {
			try {
				// Validar cliente
				$this->validator->validate($cliente);

				// Verificar memoria disponible
				if (!$this->memory->checkMemory()) {
					throw SyncError::memoryError(
						"Memoria insuficiente para procesar el cliente"
					);
				}

				// Iniciar transacción para el cliente
				$this->transaction->beginTransaction(
					'customer',
					"sync_customer_{$cliente['email']}"
				);

				// Procesar cliente con reintentos
				$result = $this->retry->executeWithRetry(
					fn() => $this->processItem($cliente),
					"sync_customer_{$cliente['email']}",
					['email' => $cliente['email']]
				);

				// Confirmar transacción
				$this->transaction->commit('customer');

				$results['processed']++;
				$results['details'][] = [
					'email' => $cliente['email'],
					'success' => true,
					'result' => $result
				];

			} catch (SyncError $e) {
				// Revertir transacción en caso de error
				if ($this->transaction->isActive()) {
					$this->transaction->rollback('customer');
				}

				$this->logger->error(
					"Error al procesar cliente en lote",
					[
						'email' => $cliente['email'] ?? 'unknown',
						'error' => $e->getMessage(),
						'code' => $e->getCode()
					]
				);

				$results['errors']++;
				$results['details'][] = [
					'email' => $cliente['email'] ?? 'unknown',
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
	 * Procesa un cliente individual
	 * 
	 * @param array<string, mixed> $cliente Datos del cliente
	 * @return array<string, mixed> Resultado del procesamiento
	 * @throws SyncError Si ocurre un error durante el procesamiento
	 */
	protected function processItem(array $cliente): array
	{
		// Implementar lógica específica de procesamiento
		// Este método debe ser implementado según los requisitos específicos
		throw new \RuntimeException('Método processItem debe ser implementado');
	}
}
// Fin de la clase MI_Sync_Clientes
