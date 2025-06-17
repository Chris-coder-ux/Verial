<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Core\SyncError;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\MemoryManager;
use MiIntegracionApi\Core\RetryManager;

/**
 * Procesador base para sincronización por lotes
 */
abstract class BatchProcessor
{
    protected const DEFAULT_BATCH_SIZE = 50;
    protected const MAX_RETRIES = 3;
    protected const MEMORY_LIMIT_MB = 256;
    protected const BATCH_TIMEOUT = 300; // 5 minutos

    protected string $entityName;
    protected array $filters = [];
    protected array $recoveryState = [];
    protected bool $isResuming = false;
    protected int $processedItems = 0;
    protected int $errorCount = 0;
    protected array $failedItems = [];
    protected float $startTime;
    protected bool $isCancelled = false;

    public function __construct(
        protected readonly ApiConnector $apiConnector
    ) {
        $this->startTime = microtime(true);
    }

    /**
     * Procesa los elementos en lotes
     * 
     * @param array $items Elementos a procesar
     * @param int $batchSize Tamaño del lote
     * @param callable $processCallback Función de procesamiento
     * @param bool $forceRestart Forzar reinicio
     * @return array Resultado del procesamiento
     */
    public function process(array $items, int $batchSize, callable $processCallback, bool $forceRestart = false): array
    {
        $total = count($items);
        $processed = 0;
        $errors = 0;
        $log = [];
        $startTime = microtime(true);
        
        // Verificar punto de recuperación
        if (!$forceRestart && $this->checkRecoveryPoint()) {
            $processed = $this->recoveryState['processed'] ?? 0;
            $errors = $this->recoveryState['errors'] ?? 0;
            $log[] = sprintf(
                'Reanudando sincronización desde el lote #%d (%d elementos procesados)',
                $this->recoveryState['last_batch'] ?? 0,
                $processed
            );
        }

        // Dividir en lotes
        $batches = array_chunk($items, $batchSize);
        
        foreach ($batches as $batchIndex => $batch) {
            $batchNum = $batchIndex + 1;
            
            // Verificar memoria antes de procesar el lote
            if (MemoryManager::isMemoryLimitExceeded($this->memoryLimitMb)) {
                $log[] = sprintf('Límite de memoria superado en el lote #%d', $batchNum);
                break;
            }
            
            // Ajustar tamaño del lote si es necesario
            $currentBatchSize = MemoryManager::adjustBatchSize($batchSize);
            if ($currentBatchSize !== $batchSize) {
                $batchSize = $currentBatchSize;
                $batches = array_chunk($items, $batchSize);
                $batch = $batches[$batchIndex];
            }
            
            $batchStartTime = microtime(true);
            $batchErrors = 0;
            $batchProcessed = 0;
            
            foreach ($batch as $item) {
                try {
                    $result = $processCallback($item);
                    if ($result['success']) {
                        $batchProcessed++;
                    } else {
                        $batchErrors++;
                        $log[] = sprintf(
                            'Error procesando elemento en lote #%d: %s',
                            $batchNum,
                            $result['message'] ?? 'Error desconocido'
                        );
                    }
                } catch (\Exception $e) {
                    $batchErrors++;
                    $log[] = sprintf(
                        'Excepción procesando elemento en lote #%d: %s',
                        $batchNum,
                        $e->getMessage()
                    );
                }
            }
            
            $processed += $batchProcessed;
            $errors += $batchErrors;
            
            // Guardar estado de recuperación
            $this->saveRecoveryState([
                'last_batch' => $batchNum,
                'processed' => $processed,
                'errors' => $errors,
                'total' => $total
            ]);
            
            // Limpiar memoria después de cada lote
            MemoryManager::cleanup("batch_{$batchNum}");
            
            // Registrar métricas del lote
            $batchDuration = microtime(true) - $batchStartTime;
            Logger::info(
                sprintf('Lote #%d completado', $batchNum),
                [
                    'batch_size' => count($batch),
                    'processed' => $batchProcessed,
                    'errors' => $batchErrors,
                    'duration' => round($batchDuration, 2),
                    'memory' => MemoryManager::getMemoryStats()
                ]
            );
        }
        
        $duration = microtime(true) - $startTime;
        
        return [
            'success' => $errors === 0,
            'processed' => $processed,
            'errors' => $errors,
            'total' => $total,
            'duration' => round($duration, 2),
            'log' => $log,
            'memory_stats' => MemoryManager::getMemoryStats()
        ];
    }

    /**
     * Procesa un lote individual
     * 
     * @param array<int|string, mixed> $batch Lote a procesar
     * @param int $batchNumber Número de lote
     * @param int $totalBatches Total de lotes
     * @throws SyncError
     */
    protected function processBatch(array $batch): array
    {
        $batchStartTime = microtime(true);
        $batchErrors = [];
        $batchSuccess = true;
        $processedItems = 0;

        try {
            // Ejecutar en transacción
            $this->executeInTransaction(function() use ($batch, &$processedItems, &$batchErrors) {
                foreach ($batch as $item) {
                    try {
                        $result = $this->processItem($item);
                        
                        if ($result['success']) {
                            $processedItems++;
                        } else {
                            // Agregar a cola de reintentos
                            RetryManager::addToRetryQueue(
                                $this->entityName,
                                $item,
                                [
                                    'batch_id' => $this->current_batch,
                                    'error' => $result['message'] ?? 'Error desconocido',
                                    'timestamp' => time()
                                ]
                            );
                            
                            $batchErrors[] = [
                                'item' => $item,
                                'error' => $result['message'] ?? 'Error desconocido'
                            ];
                            $batchSuccess = false;
                        }
                    } catch (\Exception $e) {
                        // Agregar a cola de reintentos
                        RetryManager::addToRetryQueue(
                            $this->entityName,
                            $item,
                            [
                                'batch_id' => $this->current_batch,
                                'error' => $e->getMessage(),
                                'timestamp' => time()
                            ]
                        );
                        
                        $batchErrors[] = [
                            'item' => $item,
                            'error' => $e->getMessage()
                        ];
                        $batchSuccess = false;
                    }
                }
            });

            // Procesar cola de reintentos
            $retryResults = RetryManager::processRetryQueue(
                $this->entityName,
                function($item) {
                    return $this->processItem($item);
                }
            );

            // Actualizar estadísticas
            $processedItems += $retryResults['processed'];
            $this->errorCount += $retryResults['errors'];

            // Registrar métricas
            $batchEndTime = microtime(true);
            $batchDuration = $batchEndTime - $batchStartTime;
            
            $this->metrics->recordBatchMetrics(
                $this->current_batch,
                $processedItems,
                $batchDuration,
                count($batchErrors),
                $retryResults['processed'],
                $retryResults['errors']
            );

            // Limpiar memoria
            $this->memoryManager->cleanup();

            return [
                'success' => $batchSuccess && $retryResults['success'],
                'processed' => $processedItems,
                'errors' => count($batchErrors) + $retryResults['errors'],
                'retry_stats' => $retryResults
            ];

        } catch (\Exception $e) {
            Logger::error(
                "Error procesando lote",
                [
                    'batch' => $this->current_batch,
                    'error' => $e->getMessage(),
                    'category' => "sync-batch-{$this->entityName}"
                ]
            );

            return [
                'success' => false,
                'processed' => $processedItems,
                'errors' => count($batchErrors),
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Procesa un elemento individual
     * 
     * @param mixed $item Elemento a procesar
     * @throws SyncError
     */
    abstract protected function processItem($item): void;

    /**
     * Ejecuta una operación dentro de una transacción
     * 
     * @param callable $operation Operación a ejecutar
     * @return mixed Resultado de la operación
     * @throws SyncError
     */
    protected function executeInTransaction(callable $operation): mixed
    {
        global $wpdb;
        
        $wpdb->query('START TRANSACTION');
        
        try {
            $result = $operation();
            $wpdb->query('COMMIT');
            return $result;
        } catch (\Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw new SyncError(
                'Error en transacción: ' . $e->getMessage(),
                500,
                ['exception' => $e]
            );
        }
    }

    /**
     * Limpia la memoria después de procesar un lote
     */
    protected function cleanupMemory(): void
    {
        gc_collect_cycles();
        
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }

    /**
     * Valida los datos de entrada
     * 
     * @param array<int|string, mixed> $items
     * @param int $batchSize
     * @throws SyncError
     */
    protected function validateInput(array $items, int $batchSize): void
    {
        if (empty($items)) {
            throw SyncError::validationError('No hay elementos para procesar');
        }

        if ($batchSize <= 0) {
            throw SyncError::validationError('El tamaño del lote debe ser mayor que 0');
        }

        if ($batchSize > self::DEFAULT_BATCH_SIZE * 2) {
            throw SyncError::validationError(
                'El tamaño del lote excede el máximo permitido',
                ['max_size' => self::DEFAULT_BATCH_SIZE * 2]
            );
        }
    }

    /**
     * Inicializa el procesamiento
     */
    protected function initializeProcessing(bool $forceRestart): void
    {
        $this->processedItems = 0;
        $this->errorCount = 0;
        $this->failedItems = [];
        $this->isCancelled = false;
        
        if (!$forceRestart && $this->checkRecoveryPoint()) {
            $this->isResuming = true;
            $this->loadRecoveryState();
        }
    }

    /**
     * Verifica si se debe detener el procesamiento
     */
    protected function shouldStopProcessing(): bool
    {
        return $this->isCancelled || 
               $this->isMemoryLimitExceeded() || 
               $this->isTimeoutExceeded();
    }

    /**
     * Verifica si se excedió el límite de memoria
     */
    protected function isMemoryLimitExceeded(): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) > self::MEMORY_LIMIT_MB;
    }

    /**
     * Verifica si se excedió el timeout
     */
    protected function isTimeoutExceeded(): bool
    {
        return (microtime(true) - $this->startTime) > self::BATCH_TIMEOUT;
    }

    /**
     * Obtiene el resultado del procesamiento
     * 
     * @return array<string, mixed>
     */
    protected function getProcessingResult(): array
    {
        return [
            'success' => $this->errorCount === 0 && !$this->isCancelled,
            'processed' => $this->processedItems,
            'errors' => $this->errorCount,
            'failed_items' => $this->failedItems,
            'duration' => round(microtime(true) - $this->startTime, 2),
            'memory_peak_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'cancelled' => $this->isCancelled,
            'memory_exceeded' => $this->isMemoryLimitExceeded(),
            'timeout_exceeded' => $this->isTimeoutExceeded()
        ];
    }

    /**
     * Maneja un error en el procesamiento del lote
     */
    protected function handleBatchError(SyncError $error, int $batchNumber): void
    {
        $this->errorCount++;
        
        Logger::error(
            "Error en lote #{$batchNumber}: {$error->getMessage()}",
            [
                'batch' => $batchNumber,
                'error_code' => $error->getCode(),
                'error_data' => $error->getData()
            ]
        );
    }

    /**
     * Guarda el progreso del lote
     */
    protected function saveBatchProgress(int $batchNumber, int $totalBatches): void
    {
        $this->recoveryState = [
            'last_batch' => $batchNumber,
            'total_batches' => $totalBatches,
            'processed_items' => $this->processedItems,
            'error_count' => $this->errorCount,
            'timestamp' => time()
        ];

        // Implementar guardado del estado según necesidades
    }
} 