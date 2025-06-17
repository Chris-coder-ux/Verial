<?php
/**
 * Procesador de lotes para sincronización de productos con manejo de timeouts, errores por lote,
 * reintentos individuales, control de memoria, persistencia de estado y cancelación externa.
 * Incluye soporte para recuperación de sincronización desde el último punto exitoso.
 *
 * @package MiIntegracionApi\Sync
 *
 * Ejemplo de uso:
 * $batcher = new \MiIntegracionApi\Sync\BatchProcessor($api_connector);
 * $batcher->set_entity_name('productos'); // Identificar la entidad para recuperación
 * $result = $batcher->process($productos, 100, function($producto, $api_connector) {
 *     return \MiIntegracionApi\Sync\SyncProductos::sync_producto($producto);
 * });
 */
namespace MiIntegracionApi\Sync;

use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit;
}

class BatchProcessor
{
    /**
     * @var object|null Instancia del conector API
     */
    private $api_connector;

    /**
     * @var int Tiempo máximo de ejecución por lote (segundos)
     */
    private $timeout;

    /**
     * @var int Número máximo de reintentos por lote
     */
    private $max_retries;

    /**
     * @var int Límite de memoria en MB antes de abortar
     */
    private $memory_limit_mb;

    /**
     * @var string Clave para persistir el estado del lote
     */
    private $state_key;

    /**
     * @var string Clave para la cancelación externa del lote
     */
    private $cancel_key;

    /**
     * @var string Correo electrónico del administrador para notificaciones
     */
    private $admin_email;

    /**
     * @var string Nombre de la entidad para el sistema de recuperación (productos, clientes, pedidos)
     */
    private $entity_name;

    /**
     * @var array Filtros aplicados en la sincronización actual
     */
    private $filters;

    /**
     * @var bool Indica si estamos reanudando desde un punto de recuperación
     */
    private $is_resuming = false;

    /**
     * @var array Estado de recuperación si estamos reanudando
     */
    private $recovery_state = [];

    /**
     * @var Logger Instancia del logger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param object|null $api_connector
     * @param int $timeout Tiempo máximo de ejecución por lote (segundos)
     * @param int $max_retries Número máximo de reintentos por producto
     * @param int $memory_limit_mb Límite de memoria en MB antes de abortar
     */
    public function __construct($api_connector = null, $timeout = 30, $max_retries = 2, $memory_limit_mb = 256)
    {
        $this->api_connector = $api_connector;
        $this->timeout = $timeout;
        $this->max_retries = $max_retries;
        $this->memory_limit_mb = $memory_limit_mb;
        $this->state_key = 'mia_batch_state';
        $this->cancel_key = 'mia_batch_cancel';
        $this->admin_email = get_option('admin_email');
        $this->entity_name = ''; // Por defecto vacío, debe ser establecido
        $this->filters = [];
        $this->logger = new Logger('batch-processor');
    }
    
    /**
     * Establece el nombre de la entidad para el sistema de recuperación
     *
     * @param string $entity_name Nombre de la entidad (productos, clientes, pedidos)
     * @return $this
     */
    public function set_entity_name($entity_name)
    {
        $this->entity_name = $entity_name;
        return $this;
    }
    
    /**
     * Establece los filtros aplicados en la sincronización actual
     *
     * @param array $filters Filtros aplicados
     * @return $this
     */
    public function set_filters(array $filters)
    {
        $this->filters = $filters;
        return $this;
    }
    
    /**
     * Verifica si hay un punto de recuperación disponible y lo carga si existe
     *
     * @return bool Verdadero si hay un punto de recuperación disponible
     */
    public function check_recovery_point()
    {
        if (empty($this->entity_name)) {
            return false;
        }
        
        $this->recovery_state = \MiIntegracionApi\Sync\SyncRecovery::can_resume($this->entity_name, $this->filters);
        $this->is_resuming = !empty($this->recovery_state);
        
        if ($this->is_resuming) {
            $logger = new \MiIntegracionApi\Helpers\Logger("sync-recovery-{$this->entity_name}");
            $logger->info("Reanudando sincronización de {$this->entity_name} desde el lote #{$this->recovery_state['last_batch']}", [
                'processed' => $this->recovery_state['processed'] ?? 0,
                'total' => $this->recovery_state['total'] ?? 0,
                'category' => "sync-recovery-{$this->entity_name}"
            ]);
        }
        
        return $this->is_resuming;
    }
    
    /**
     * Obtiene el mensaje informativo sobre el estado de recuperación
     *
     * @return string Mensaje informativo o cadena vacía
     */
    public function get_recovery_message()
    {
        if (empty($this->entity_name)) {
            return '';
        }
        
        return \MiIntegracionApi\Sync\SyncRecovery::get_recovery_message($this->entity_name);
    }

    /**
     * Procesa productos en lotes, con robustez y persistencia de estado.
     * Con soporte para reanudar desde el último lote exitoso.
     *
     * @param array $productos Lista de productos a procesar
     * @param int $batch_size Tamaño del lote
     * @param callable|null $callback Función de procesamiento de cada producto (opcional)
     * @param bool $force_restart Fuerza reiniciar desde el principio incluso si hay punto de recuperación
     * @return array Resultados del procesamiento
     */
    public function process(array $productos, int $batch_size = 100, callable $callback = null, bool $force_restart = false): array
    {
        $total = count($productos);
        $processed = 0;
        $errors = 0;
        $log = [];
        $failed_products = [];
        $batches = array_chunk($productos, $batch_size);
        $start_time = microtime(true);
        $batch_num = 0;
        $memory_exceeded = false;
        $cancelled = false;
        $resumed = false;
        $skipped_batches = 0;
        
        // Verificar si hay un punto de recuperación y no se forzó reinicio
        if (!$force_restart && !empty($this->entity_name) && $this->check_recovery_point()) {
            $resumed = true;
            $last_batch = $this->recovery_state['last_batch'] ?? 0;
            $processed = $this->recovery_state['processed'] ?? 0;
            $errors = $this->recovery_state['errors'] ?? 0;
            $skipped_batches = $last_batch;
            
            $log[] = sprintf(
                __('Reanudando sincronización de %s desde el lote #%d (%d productos ya procesados).', 'mi-integracion-api'),
                $this->entity_name,
                $last_batch + 1,
                $processed
            );
            
            $this->logger->info("Reanudando sincronización", [
                'entity' => $this->entity_name,
                'last_batch' => $last_batch,
                'processed' => $processed,
                'total' => $total
            ]);
        }

        // Estado inicial de la sincronización
        $initial_state = [
            'total' => $total,
            'batch_size' => $batch_size,
            'start_time' => $start_time,
            'entity' => $this->entity_name,
            'filters' => $this->filters,
            'resumed' => $resumed,
        ];
        
        // Persistencia de estado: guardar lote actual y progreso
        $this->save_state($initial_state);
        
        // Si estamos usando recuperación, también guardamos en SyncRecovery
        if (!empty($this->entity_name)) {
            \MiIntegracionApi\Sync\SyncRecovery::save_recovery_state($this->entity_name, $initial_state);
        }

        foreach ($batches as $lote_index => $lote) {
            $batch_num = $lote_index + 1;
            
            // Si estamos reanudando, saltamos los lotes ya procesados
            if ($resumed && $batch_num <= $skipped_batches) {
                $this->logger->info("Saltando lote ya procesado previamente", [
                    'batch_num' => $batch_num, 
                    'skipped_batches' => $skipped_batches
                ]);
                continue;
            }
            
            $batch_start = microtime(true);
            $batch_errors = 0;
            $batch_processed = 0;
            $batch_failed = [];
            $batch_log = [];
            
            // MEJORA: Implementación completa de reintentos por lote
            $batch_retry_count = 0;
            $max_batch_retries = 2; // Máximo de reintentos por lote completo
            $batch_success = false;
            
            // Variables para el seguimiento de memoria
            $memory_start = memory_get_usage(true);
            $memory_peak = 0;
            
            // Registrar inicio del procesamiento del lote
            $this->logger->info("Iniciando procesamiento de lote #{$batch_num}", [
                'total_items' => count($lote),
                'batch_size' => $batch_size,
                'memory_start' => round($memory_start / 1024 / 1024, 2) . ' MB'
            ]);
            
            // Establecer timeout de lote
            $batch_timeout = time() + $this->timeout;
            
            // MEJORA: Actualizar heartbeat para mostrar actividad
            $this->update_heartbeat($batch_num, count($lote));

            // Cancelación externa
            if ($this->is_cancelled()) {
                $cancelled = true;
                $log[] = "Procesamiento cancelado externamente en el lote #$batch_num.";
                $this->logger->warning('Procesamiento cancelado externamente', ['batch' => $batch_num]);
                break;
            }
            
            // MEJORA: Verificar la salud del sistema antes de procesar
            if (!$this->check_system_health()) {
                $log[] = "La verificación de salud del sistema falló antes del lote #$batch_num. Pausando sincronización.";
                $this->logger->error('Verificación de salud del sistema falló', [
                    'batch' => $batch_num,
                    'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB',
                    'memory_limit' => $this->memory_limit_mb . ' MB'
                ]);
                break;
            }

            foreach ($lote as $producto) {
                $retries = 0;
                $success = false;
                $producto_id = $this->get_producto_identifier($producto);
                
                // MEJORA: Guardar el producto actual para diagnóstico y recuperación
                if (!empty($this->entity_name) && !empty($producto_id)) {
                    set_transient("mia_last_{$this->entity_name}_id", $producto_id, HOUR_IN_SECONDS);
                    
                    // Si es un producto, también guardar su nombre para mejor UX
                    if ($this->entity_name === 'productos' && !empty($producto['Descripcion'])) {
                        set_transient("mia_last_product_name", $producto['Descripcion'], HOUR_IN_SECONDS);
                    }
                }
                
                do {
                    try {
                        // MEJORA: Gestión mejorada de resultados
                        if ($callback && is_callable($callback)) {
                            $result = call_user_func($callback, $producto, $this->api_connector);
                            
                            // Si el resultado es un WP_Error, lo manejamos apropiadamente
                            if (is_wp_error($result)) {
                                $error_code = $result->get_error_code();
                                $error_message = $result->get_error_message();
                                $error_data = $result->get_error_data();
                                
                                // Determinamos si el error es recuperable o crítico
                                $recoverable_codes = ['timeout', 'api_error', 'temporary_error', 'network_error'];
                                $is_recoverable = in_array($error_code, $recoverable_codes);
                                
                                if ($is_recoverable) {
                                    throw new \RuntimeException($error_message);
                                } else {
                                    // Error no recuperable, registramos y continuamos con el siguiente producto
                                    $batch_errors++;
                                    $batch_failed[] = $producto;
                                    $batch_log[] = sprintf('Error no recuperable: %s [%s]', $error_message, $error_code);
                                    $this->logger->error('Error no recuperable en producto', [
                                        'producto_id' => $producto_id,
                                        'batch' => $batch_num,
                                        'error_code' => $error_code,
                                        'error_message' => $error_message,
                                        'error_data' => $error_data
                                    ]);
                                    break; // Salir del bucle de reintentos
                                }
                            }
                        } elseif (method_exists('MiIntegracionApi\\Sync\\SyncProductos', 'sync_producto')) {
                            $result = \MiIntegracionApi\Sync\SyncProductos::sync_producto($producto);
                        } else {
                            throw new \Exception('No hay método de procesamiento definido para productos.');
                        }
                        
                        // Comprobación de errores en formato de array
                        if (is_array($result) && !empty($result['error'])) {
                            throw new \RuntimeException($result['message'] ?? $result['msg'] ?? 'Error procesando producto.');
                        }
                        
                        $batch_processed++;
                        $success = true;
                        
                        // MEJORA: Registrar éxito para estadísticas
                        $this->logger->debug('Producto procesado con éxito', [
                            'producto_id' => $producto_id,
                            'batch' => $batch_num
                        ]);
                        
                    } catch (\RuntimeException $e) {
                        $batch_errors++;
                        $batch_failed[] = $producto;
                        $batch_log[] = sprintf('Error recuperable en producto %s: %s', $producto_id, $e->getMessage());
                        $this->logger->warning('Error recuperable en producto', [
                            'producto_id' => $producto_id,
                            'batch' => $batch_num,
                            'error' => $e->getMessage(),
                            'retries' => $retries
                        ]);
                        
                        $retries++;
                        
                        // MEJORA: Esperar entre reintentos con backoff exponencial
                        if ($retries <= $this->max_retries) {
                            $wait_seconds = min(pow(2, $retries - 1), 10); // 1, 2, 4, 8, 10 segundos máximo
                            $this->logger->info(sprintf('Esperando %d segundos antes del reintento %d/%d', 
                                $wait_seconds, $retries, $this->max_retries));
                            sleep($wait_seconds);
                        } else {
                            break; // Salir después de agotar reintentos
                        }
                    } catch (\Exception $e) {
                        // Error crítico, abortar el procesamiento de este producto
                        $batch_errors++;
                        $batch_failed[] = $producto;
                        $batch_log[] = sprintf('Error crítico en producto %s: %s', $producto_id, $e->getMessage());
                        $this->logger->error('Error crítico en producto', [
                            'producto_id' => $producto_id,
                            'batch' => $batch_num,
                            'error' => $e->getMessage(),
                            'file' => $e->getFile(),
                            'line' => $e->getLine()
                        ]);
                        break;
                    }
                } while (!$success && $retries <= $this->max_retries);

                // Timeout por lote
                if ((microtime(true) - $batch_start) > $this->timeout) {
                    $batch_log[] = 'Timeout alcanzado en el lote #' . $batch_num;
                    $this->logger->warning('Timeout alcanzado en lote', ['batch' => $batch_num]);
                    break;
                }
                // Control de memoria
                if ($this->memory_limit_mb > 0 && (memory_get_usage() / 1024 / 1024) > $this->memory_limit_mb) {
                    $memory_exceeded = true;
                    $batch_log[] = 'Límite de memoria superado en el lote #' . $batch_num;
                    $this->logger->error('Límite de memoria superado', ['batch' => $batch_num]);
                    break;
                }
            }

            $processed += $batch_processed;
            $errors += $batch_errors;
            $failed_products = array_merge($failed_products, $batch_failed);
            $log = array_merge($log, $batch_log);

            // Notificación admin si el lote falla completamente
            if ($batch_errors === count($lote)) {
                $this->notify_admin("Fallo completo en el lote #$batch_num de la sincronización de productos.");
            }

            // Persistencia de estado: guardar progreso
            $batch_state = [
                'last_batch' => $batch_num,
                'processed' => $processed,
                'errors' => $errors,
                'failed_products' => $failed_products,
                'entity' => $this->entity_name,
                'filters' => $this->filters,
                'duration_so_far' => round(microtime(true) - $start_time, 2)
            ];
            
            $this->save_state($batch_state);
            
            // Si estamos usando el sistema de recuperación
            if (!empty($this->entity_name)) {
                \MiIntegracionApi\Sync\SyncRecovery::save_recovery_state(
                    $this->entity_name, 
                    array_merge($batch_state, ['total' => $total])
                );
            }

            if ($memory_exceeded) break;
        }

        $duration = round(microtime(true) - $start_time, 2);
        $this->logger->info('Procesamiento por lotes finalizado', [
            'total' => $total,
            'procesados' => $processed,
            'errores' => $errors,
            'duration' => $duration,
            'cancelled' => $cancelled,
            'memory_exceeded' => $memory_exceeded,
            'resumed' => $resumed
        ]);

        // Limpiar estado si terminó correctamente
        if (!$cancelled && !$memory_exceeded && $errors === 0) {
            $this->clear_state();
            
            // También limpiar punto de recuperación si todo salió bien
            if (!empty($this->entity_name)) {
                \MiIntegracionApi\Sync\SyncRecovery::clear_recovery_state($this->entity_name);
                $this->logger->info("Punto de recuperación eliminado tras finalización exitosa", [
                    'entity' => $this->entity_name
                ]);
            }
        } else if (!empty($this->entity_name)) {
            // Actualizar estado final si hubo problemas
            $final_state = [
                'last_batch' => $batch_num,
                'processed' => $processed,
                'errors' => $errors,
                'total' => $total,
                'entity' => $this->entity_name,
                'filters' => $this->filters,
                'status' => $cancelled ? 'cancelled' : ($memory_exceeded ? 'memory_limit' : 'error'),
                'duration' => $duration
            ];
            
            \MiIntegracionApi\Sync\SyncRecovery::save_recovery_state($this->entity_name, $final_state);
            $this->logger->info("Punto de recuperación guardado para reanudar más tarde", [
                'entity' => $this->entity_name,
                'status' => $final_state['status']
            ]);
        }

        return [
            'success' => $errors === 0 && !$cancelled && !$memory_exceeded,
            'processed' => $processed,
            'errors' => $errors,
            'log' => $log,
            'duration' => $duration,
            'total' => $total,
            'failed_products' => $failed_products,
            'cancelled' => $cancelled,
            'memory_exceeded' => $memory_exceeded
        ];
    }

    /**
     * Guarda el estado del lote en un transient de WordPress.
     * 
     * @param array $state
     * @return bool Éxito de la operación
     */
    private function save_state(array $state)
    {
        $state['timestamp'] = current_time('mysql');
        return set_transient($this->state_key, $state, 60 * 60); // 1 hora
    }

    /**
     * Limpia el estado del lote.
     * 
     * @return bool Éxito de la operación
     */
    private function clear_state()
    {
        return delete_transient($this->state_key);
    }
    
    /**
     * Obtiene el estado actual del lote
     * 
     * @return array|false Estado del lote o falso si no existe
     */
    public function get_state()
    {
        return get_transient($this->state_key);
    }

    /**
     * Permite cancelar el procesamiento desde fuera (por ejemplo, desde un endpoint admin).
     */
    public function cancel()
    {
        set_transient($this->cancel_key, 1, 60 * 60);
    }

    /**
     * Verifica si se ha solicitado la cancelación externa.
     * @return bool
     */
    public function is_cancelled(): bool
    {
        return (bool) get_transient($this->cancel_key);
    }

    /**
     * Notifica al administrador por email si un lote falla completamente.
     * @param string $message
     */
    private function notify_admin($message)
    {
        if ($this->admin_email) {
            wp_mail($this->admin_email, '[Verial/WC] Alerta de sincronización por lotes', $message);
        }
    }

    /**
     * Obtiene un identificador único para el producto o entidad
     * 
     * @param array $item Producto o entidad a identificar
     * @return string Identificador único del producto
     */
    private function get_producto_identifier($item)
    {
        // Intentar obtener el identificador más adecuado según la entidad
        if ($this->entity_name === 'productos') {
            // Prioridad de campos para identificar productos
            if (!empty($item['ReferenciaBarras'])) {
                return 'SKU:' . $item['ReferenciaBarras'];
            } elseif (!empty($item['Codigo'])) {
                return 'COD:' . $item['Codigo'];
            } elseif (!empty($item['Id'])) {
                return 'ID:' . $item['Id'];
            } elseif (!empty($item['Descripcion'])) {
                return 'DESC:' . substr($item['Descripcion'], 0, 30);
            }
        } elseif ($this->entity_name === 'clientes') {
            // Identificadores para clientes
            if (!empty($item['Codigo'])) {
                return 'COD:' . $item['Codigo'];
            } elseif (!empty($item['Email'])) {
                return 'EMAIL:' . $item['Email'];
            } elseif (!empty($item['Nombre'])) {
                return 'NOM:' . substr($item['Nombre'], 0, 30);
            }
        } elseif ($this->entity_name === 'pedidos') {
            // Identificadores para pedidos
            if (!empty($item['Codigo'])) {
                return 'PED:' . $item['Codigo'];
            } elseif (!empty($item['NumeroDocumento'])) {
                return 'DOC:' . $item['NumeroDocumento'];
            }
        }
        
        // Si no podemos identificarlo por ninguno de los campos esperados
        // Usar un hash de los datos para asegurar un identificador único
        return 'HASH:' . substr(md5(serialize($item)), 0, 10);
    }
    
    /**
     * Actualiza el heartbeat de la sincronización para indicar actividad
     * 
     * @param int $batch_num Número de lote actual
     * @param int $item_count Cantidad de elementos en el lote
     * @return bool Éxito de la operación
     */
    private function update_heartbeat($batch_num, $item_count)
    {
        $heartbeat_data = [
            'timestamp' => time(),
            'batch' => $batch_num,
            'items' => $item_count,
            'entity' => $this->entity_name,
            'memory_usage' => round(memory_get_usage(true) / 1024 / 1024, 2) . ' MB'
        ];
        
        // Guardar transient para monitoreo
        set_transient('mia_sync_heartbeat', $heartbeat_data, HOUR_IN_SECONDS);
        
        // Si tenemos información de la entidad actual, también guardarlo específicamente para ella
        if (!empty($this->entity_name)) {
            set_transient("mia_sync_{$this->entity_name}_heartbeat", $heartbeat_data, HOUR_IN_SECONDS);
        }
        
        return true;
    }
    
    /**
     * Verifica la salud del sistema antes de procesar un lote
     * 
     * @return bool True si el sistema está en buen estado, False si hay problemas
     */
    private function check_system_health()
    {
        // Verificar uso de memoria
        $memory_usage = memory_get_usage(true);
        $memory_usage_mb = round($memory_usage / 1024 / 1024, 2);
        
        // Si el uso de memoria supera el 90% del límite configurable, no continuar
        if ($this->memory_limit_mb > 0 && $memory_usage_mb > ($this->memory_limit_mb * 0.9)) {
            $this->logger->warning('Uso de memoria crítico', [
                'memory_usage' => $memory_usage_mb . ' MB',
                'memory_limit' => $this->memory_limit_mb . ' MB',
                'percentage' => round(($memory_usage_mb / $this->memory_limit_mb) * 100, 1) . '%'
            ]);
            return false;
        }
        
        // Verificar tiempo máximo de ejecución
        $max_execution_time = (int)ini_get('max_execution_time');
        if ($max_execution_time > 0) {
            $script_time = time() - $_SERVER['REQUEST_TIME'];
            
            // Si estamos a más del 80% del tiempo máximo de ejecución, mejor no continuar
            if ($script_time > ($max_execution_time * 0.8)) {
                $this->logger->warning('Tiempo de ejecución próximo al límite', [
                    'script_time' => $script_time . ' segundos',
                    'max_execution_time' => $max_execution_time . ' segundos',
                    'percentage' => round(($script_time / $max_execution_time) * 100, 1) . '%'
                ]);
                return false;
            }
        }
        
        // Verificar si la API está respondiendo correctamente
        if ($this->api_connector) {
            try {
                $api_health = $this->api_connector->check_api_health();
                if (is_wp_error($api_health)) {
                    $this->logger->error('API no responde correctamente', [
                        'error' => $api_health->get_error_message()
                    ]);
                    return false;
                }
            } catch (\Exception $e) {
                $this->logger->error('Error al verificar salud de la API', [
                    'exception' => $e->getMessage()
                ]);
                // No bloqueamos sólo por este error ya que podría ser temporal
            }
        }
        
        return true;
    }
}
