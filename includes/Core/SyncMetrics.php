<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\WooCommerce\SyncHelper;

/**
 * Sistema de métricas y monitoreo para sincronizaciones
 */
class SyncMetrics
{
    private const METRICS_PREFIX = 'mia_sync_metrics_';
    private const DEFAULT_TTL = 604800; // 7 días
    private const METRICS_OPTION = 'mi_integracion_api_sync_metrics';
    private const MAX_HISTORY_DAYS = 30;
    private const MEMORY_THRESHOLD = 0.8; // 80% del límite de memoria
    private const CLEANUP_INTERVAL = 100; // Limpiar cada 100 items

    private array $currentMetrics = [];
    private array $startTimes = [];
    private array $memorySnapshots = [];
    private LogManager $logger;
    private int $lastCleanupTime = 0;
    private int $itemsSinceLastCleanup = 0;
    private ?string $currentOperationId = null;

    // Constantes para tipos de error
    private const ERROR_TYPE_VALIDATION = 'validation';
    private const ERROR_TYPE_API = 'api';
    private const ERROR_TYPE_CONCURRENCY = 'concurrency';
    private const ERROR_TYPE_MEMORY = 'memory';
    private const ERROR_TYPE_NETWORK = 'network';
    private const ERROR_TYPE_TIMEOUT = 'timeout';
    private const ERROR_TYPE_UNKNOWN = 'unknown';

    public function __construct()
    {
        $this->logger = new LogManager('sync-metrics');
        $this->loadMetrics();
    }

    /**
     * Registra una métrica de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @param array<string, mixed> $metrics Métricas a registrar
     * @param int $ttl Tiempo de vida en segundos
     * @return bool Éxito de la operación
     */
    public static function recordMetrics(string $entity, array $metrics, int $ttl = self::DEFAULT_TTL): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getMetricsKey($entity);
        $metrics['timestamp'] = time();
        $metrics['entity'] = $entity;

        Logger::info(
            "Registrando métricas para {$entity}",
            [
                'metrics' => $metrics,
                'category' => "sync-metrics-{$entity}"
            ]
        );

        return set_transient($key, $metrics, $ttl);
    }

    /**
     * Obtiene las métricas de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, mixed>|false Métricas o false si no existen
     */
    public static function getMetrics(string $entity): array|false
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getMetricsKey($entity);
        $metrics = get_transient($key);

        if ($metrics === false) {
            return false;
        }

        // Verificar si las métricas han expirado
        if (isset($metrics['timestamp']) && (time() - $metrics['timestamp']) > self::DEFAULT_TTL) {
            self::clearMetrics($entity);
            return false;
        }

        return $metrics;
    }

    /**
     * Limpia las métricas de sincronización
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function clearMetrics(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getMetricsKey($entity);
        
        Logger::info(
            "Limpiando métricas para {$entity}",
            ['category' => "sync-metrics-{$entity}"]
        );

        return delete_transient($key);
    }

    /**
     * Registra el uso de memoria
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, int> Métricas de memoria
     */
    public static function recordMemoryUsage(string $entity): array
    {
        $memoryUsage = memory_get_usage(true);
        $peakMemoryUsage = memory_get_peak_usage(true);
        
        $metrics = [
            'memory_usage' => $memoryUsage,
            'peak_memory_usage' => $peakMemoryUsage,
            'memory_limit' => ini_get('memory_limit')
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Registra el tiempo de ejecución
     * 
     * @param string $entity Nombre de la entidad
     * @param float $startTime Tiempo de inicio
     * @return array<string, float> Métricas de tiempo
     */
    public static function recordExecutionTime(string $entity, float $startTime): array
    {
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        $metrics = [
            'execution_time' => $executionTime,
            'start_time' => $startTime,
            'end_time' => $endTime
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Registra estadísticas de procesamiento
     * 
     * @param string $entity Nombre de la entidad
     * @param int $processedItems Elementos procesados
     * @param int $totalItems Total de elementos
     * @param int $errorCount Contador de errores
     * @return array<string, mixed> Métricas de procesamiento
     */
    public static function recordProcessingStats(
        string $entity,
        int $processedItems,
        int $totalItems,
        int $errorCount
    ): array {
        $metrics = [
            'processed_items' => $processedItems,
            'total_items' => $totalItems,
            'error_count' => $errorCount,
            'success_rate' => $totalItems > 0 ? (($totalItems - $errorCount) / $totalItems) * 100 : 0
        ];

        self::recordMetrics($entity, $metrics);

        return $metrics;
    }

    /**
     * Obtiene la clave de métricas
     * 
     * @param string $entity Nombre de la entidad
     * @return string Clave de métricas
     */
    private static function getMetricsKey(string $entity): string
    {
        return self::METRICS_PREFIX . sanitize_key($entity);
    }

    /**
     * Registra métricas de un lote
     * 
     * @param int $batchNumber Número del lote
     * @param int $processedItems Elementos procesados
     * @param float $duration Duración del procesamiento
     * @param int $errors Errores encontrados
     * @param int $retryProcessed Elementos procesados en reintentos
     * @param int $retryErrors Errores en reintentos
     * @return void
     */
    public function recordBatchMetrics(
        int $batchNumber,
        int $processedItems,
        float $duration,
        int $errors,
        int $retryProcessed = 0,
        int $retryErrors = 0
    ): void {
        // Obtener el operation_id actual
        $operationId = $this->currentOperationId ?? 'default_operation';
        
        // Inicializar las métricas si no existen
        if (!isset($this->currentMetrics[$operationId])) {
            $this->currentMetrics[$operationId] = [
                'entity' => 'unknown',
                'direction' => 'unknown',
                'start_time' => date('Y-m-d H:i:s'),
                'status' => 'in_progress',
                'items_processed' => 0,
                'items_succeeded' => 0,
                'items_failed' => 0,
                'errors' => [],
                'memory_usage' => [],
                'performance' => [],
                'error_types' => [],
                'total' => [
                    'processed' => 0,
                    'errors' => 0,
                    'retry_processed' => 0,
                    'retry_errors' => 0,
                    'duration' => 0
                ],
                'batches' => []
            ];
        }
        
        // Inicializar las métricas totales si no existen
        if (!isset($this->currentMetrics[$operationId]['total'])) {
            $this->currentMetrics[$operationId]['total'] = [
                'processed' => 0,
                'errors' => 0,
                'retry_processed' => 0,
                'retry_errors' => 0,
                'duration' => 0
            ];
        }
        
        // Inicializar el array de lotes si no existe
        if (!isset($this->currentMetrics[$operationId]['batches'])) {
            $this->currentMetrics[$operationId]['batches'] = [];
        }
        
        // Registrar métricas del lote
        $this->currentMetrics[$operationId]['batches'][$batchNumber] = [
            'processed' => $processedItems,
            'duration' => $duration,
            'errors' => $errors,
            'retry_processed' => $retryProcessed,
            'retry_errors' => $retryErrors,
            'timestamp' => time()
        ];
        
        // Actualizar métricas totales
        $this->currentMetrics[$operationId]['total']['processed'] += $processedItems;
        $this->currentMetrics[$operationId]['total']['errors'] += $errors;
        $this->currentMetrics[$operationId]['total']['retry_processed'] += $retryProcessed;
        $this->currentMetrics[$operationId]['total']['retry_errors'] += $retryErrors;
        $this->currentMetrics[$operationId]['total']['duration'] += $duration;
        
        // Guardar las métricas
        $this->saveMetrics($operationId);
    }

    /**
     * Obtiene estadísticas de reintentos
     * 
     * @return array<string, mixed> Estadísticas de reintentos
     */
    public function getRetryStats(): array
    {
        $stats = [
            'total_retries' => 0,
            'successful_retries' => 0,
            'failed_retries' => 0,
            'avg_retry_delay' => 0,
            'retry_by_batch' => []
        ];

        foreach ($this->metrics['batches'] as $batchNumber => $batch) {
            $stats['total_retries'] += $batch['retry_processed'] + $batch['retry_errors'];
            $stats['successful_retries'] += $batch['retry_processed'];
            $stats['failed_retries'] += $batch['retry_errors'];
            
            $stats['retry_by_batch'][$batchNumber] = [
                'processed' => $batch['retry_processed'],
                'errors' => $batch['retry_errors'],
                'success_rate' => $batch['retry_processed'] > 0 
                    ? ($batch['retry_processed'] / ($batch['retry_processed'] + $batch['retry_errors'])) * 100 
                    : 0
            ];
        }

        if ($stats['total_retries'] > 0) {
            $stats['avg_retry_delay'] = $this->metrics['total']['duration'] / $stats['total_retries'];
        }

        return $stats;
    }

    /**
     * Inicia el seguimiento de una operación
     */
    public function startOperation(string $operationId, string $entity, string $direction): void
    {
        // Establecer el ID de operación actual
        $this->currentOperationId = $operationId;
        
        $this->startTimes[$operationId] = microtime(true);
        $this->memorySnapshots[$operationId] = [
            'start' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true)
        ];

        $this->currentMetrics[$operationId] = [
            'entity' => $entity,
            'direction' => $direction,
            'start_time' => date('Y-m-d H:i:s'),
            'status' => 'in_progress',
            'items_processed' => 0,
            'items_succeeded' => 0,
            'items_failed' => 0,
            'errors' => [],
            'memory_usage' => [],
            'performance' => [],
            'error_types' => [],
            'total' => [
                'processed' => 0,
                'errors' => 0,
                'retry_processed' => 0,
                'retry_errors' => 0,
                'duration' => 0
            ],
            'batches' => []
        ];

        $this->logger->info("Iniciando operación", [
            'operation_id' => $operationId,
            'entity' => $entity,
            'direction' => $direction
        ]);
    }

    /**
     * Registra el procesamiento de un item
     */
    public function recordItemProcessed(string $operationId, bool $success, ?string $error = null): void
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return;
        }

        $this->currentMetrics[$operationId]['items_processed']++;
        
        if ($success) {
            $this->currentMetrics[$operationId]['items_succeeded']++;
        } else {
            $this->currentMetrics[$operationId]['items_failed']++;
            if ($error) {
                $this->currentMetrics[$operationId]['errors'][] = [
                    'time' => date('Y-m-d H:i:s'),
                    'message' => $error
                ];
            }
        }

        $this->incrementItemCount($operationId);
        $this->checkMemoryUsage($operationId);
    }

    /**
     * Verifica y gestiona el uso de memoria
     */
    public function checkMemoryUsage(string $operationId): bool
    {
        $currentMemory = memory_get_usage(true);
        $memoryLimit = $this->getMemoryLimit();
        $memoryUsage = $currentMemory / $memoryLimit;

        $this->currentMetrics[$operationId]['memory_usage'][] = [
            'time' => date('Y-m-d H:i:s'),
            'current' => $currentMemory,
            'peak' => memory_get_peak_usage(true),
            'limit' => $memoryLimit,
            'usage_percentage' => round($memoryUsage * 100, 2)
        ];

        // Si el uso de memoria supera el umbral, intentar limpiar
        if ($memoryUsage > self::MEMORY_THRESHOLD) {
            $this->cleanupMemory($operationId);
            return false;
        }

        return true;
    }

    /**
     * Obtiene el límite de memoria en bytes
     */
    private function getMemoryLimit(): int
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_INT_MAX;
        }

        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;

        return match($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value
        };
    }

    /**
     * Limpia la memoria y recursos
     */
    public function cleanupMemory(string $operationId): void
    {
        $this->logger->info("Iniciando limpieza de memoria", [
            'operation_id' => $operationId,
            'memory_before' => memory_get_usage(true)
        ]);

        // Limpiar métricas antiguas
        $this->cleanupOldMetrics();

        // Forzar recolección de basura
        $collected = gc_collect_cycles();
        
        // Limpiar caché de OPcache si está disponible
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }

        // Limpiar caché de transients
        $this->cleanupTransients();

        $this->logger->info("Limpieza de memoria completada", [
            'operation_id' => $operationId,
            'memory_after' => memory_get_usage(true),
            'cycles_collected' => $collected
        ]);

        $this->lastCleanupTime = time();
        $this->itemsSinceLastCleanup = 0;
    }

    /**
     * Limpia métricas antiguas
     */
    private function cleanupOldMetrics(): void
    {
        $cutoffDate = strtotime("-" . self::MAX_HISTORY_DAYS . " days");
        $this->currentMetrics = array_filter(
            $this->currentMetrics,
            fn($metrics) => strtotime($metrics['start_time']) >= $cutoffDate
        );
    }

    /**
     * Limpia transients antiguos
     */
    private function cleanupTransients(): void
    {
        global $wpdb;
        
        $cutoffTime = time() - self::DEFAULT_TTL;
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE %s 
                AND option_name NOT LIKE %s 
                AND autoload = 'no'",
                $wpdb->esc_like('_transient_') . '%',
                $wpdb->esc_like('_transient_timeout_') . '%'
            )
        );
    }

    /**
     * Verifica si es necesario realizar limpieza
     */
    public function shouldCleanup(): bool
    {
        return $this->itemsSinceLastCleanup >= self::CLEANUP_INTERVAL ||
               (time() - $this->lastCleanupTime) >= 300; // 5 minutos
    }

    /**
     * Incrementa el contador de items y verifica limpieza
     */
    public function incrementItemCount(string $operationId): void
    {
        $this->itemsSinceLastCleanup++;

        if ($this->shouldCleanup()) {
            $this->cleanupMemory($operationId);
        }
    }

    /**
     * Obtiene estadísticas de memoria
     */
    public function getMemoryStats(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return [];
        }

        $memoryUsage = $this->currentMetrics[$operationId]['memory_usage'] ?? [];
        if (empty($memoryUsage)) {
            return [];
        }

        $latest = end($memoryUsage);
        $peak = max(array_column($memoryUsage, 'peak'));

        return [
            'current' => $latest['current'],
            'peak' => $peak,
            'limit' => $latest['limit'],
            'usage_percentage' => $latest['usage_percentage'],
            'history' => $memoryUsage
        ];
    }

    /**
     * Finaliza una operación
     */
    public function endOperation(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return [];
        }

        $duration = microtime(true) - $this->startTimes[$operationId];
        $memoryDiff = memory_get_usage(true) - $this->memorySnapshots[$operationId]['start'];

        $this->currentMetrics[$operationId]['end_time'] = date('Y-m-d H:i:s');
        $this->currentMetrics[$operationId]['duration'] = round($duration, 2);
        $this->currentMetrics[$operationId]['status'] = 'completed';
        $this->currentMetrics[$operationId]['memory_final'] = [
            'current' => memory_get_usage(true),
            'peak' => memory_get_peak_usage(true),
            'diff' => $memoryDiff
        ];

        $this->saveMetrics($operationId);
        $this->cleanupOperation($operationId);

        $this->logger->info("Operación finalizada", [
            'operation_id' => $operationId,
            'duration' => $duration,
            'items_processed' => $this->currentMetrics[$operationId]['items_processed'],
            'success_rate' => $this->calculateSuccessRate($operationId)
        ]);

        return $this->currentMetrics[$operationId];
    }

    /**
     * Calcula la tasa de éxito
     */
    private function calculateSuccessRate(string $operationId): float
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return 0.0;
        }

        $total = $this->currentMetrics[$operationId]['items_processed'];
        if ($total === 0) {
            return 0.0;
        }

        return round(
            ($this->currentMetrics[$operationId]['items_succeeded'] / $total) * 100,
            2
        );
    }

    /**
     * Obtiene métricas de una operación
     */
    public function getOperationMetrics(string $operationId): ?array
    {
        $allMetrics = get_option(self::METRICS_OPTION, []);
        return $allMetrics[$operationId] ?? null;
    }

    /**
     * Obtiene métricas resumidas
     */
    public function getSummaryMetrics(int $days = 7): array
    {
        $allMetrics = get_option(self::METRICS_OPTION, []);
        $cutoffDate = strtotime("-{$days} days");
        
        $summary = [
            'total_operations' => 0,
            'total_items' => 0,
            'success_rate' => 0,
            'avg_duration' => 0,
            'avg_memory_usage' => 0,
            'error_count' => 0,
            'by_entity' => []
        ];

        foreach ($allMetrics as $operation) {
            if (strtotime($operation['start_time']) < $cutoffDate) {
                continue;
            }

            $summary['total_operations']++;
            $summary['total_items'] += $operation['items_processed'];
            $summary['error_count'] += count($operation['errors']);
            
            $entity = $operation['entity'];
            if (!isset($summary['by_entity'][$entity])) {
                $summary['by_entity'][$entity] = [
                    'total_operations' => 0,
                    'total_items' => 0,
                    'success_rate' => 0
                ];
            }
            
            $summary['by_entity'][$entity]['total_operations']++;
            $summary['by_entity'][$entity]['total_items'] += $operation['items_processed'];
        }

        // Calcular promedios
        if ($summary['total_operations'] > 0) {
            $summary['avg_duration'] = round(
                array_sum(array_column($allMetrics, 'duration')) / $summary['total_operations'],
                2
            );
            
            $summary['avg_memory_usage'] = round(
                array_sum(array_map(function($op) {
                    return $op['memory_final']['peak'] ?? 0;
                }, $allMetrics)) / $summary['total_operations'],
                2
            );
        }

        return $summary;
    }

    /**
     * Guarda las métricas
     */
    private function saveMetrics(string $operationId): void
    {
        // Verificar que operationId existe en currentMetrics
        if (!isset($this->currentMetrics[$operationId])) {
            $this->logger->warning("Intento de guardar métricas para una operación no iniciada", [
                'operation_id' => $operationId
            ]);
            return;
        }
        
        $allMetrics = get_option(self::METRICS_OPTION, []);
        $allMetrics[$operationId] = $this->currentMetrics[$operationId];
        
        // Limpiar métricas antiguas
        $cutoffDate = strtotime("-" . self::MAX_HISTORY_DAYS . " days");
        $allMetrics = array_filter($allMetrics, function($metrics) use ($cutoffDate) {
            return isset($metrics['start_time']) && strtotime($metrics['start_time']) >= $cutoffDate;
        });

        update_option(self::METRICS_OPTION, $allMetrics, true);
        
        // Registrar en el log
        $this->logger->debug("Métricas guardadas para operación", [
            'operation_id' => $operationId,
            'total_processed' => $this->currentMetrics[$operationId]['total']['processed'] ?? 0,
            'total_errors' => $this->currentMetrics[$operationId]['total']['errors'] ?? 0,
            'batch_count' => count($this->currentMetrics[$operationId]['batches'] ?? [])
        ]);
    }

    /**
     * Limpia los datos de una operación
     */
    private function cleanupOperation(string $operationId): void
    {
        unset($this->startTimes[$operationId]);
        unset($this->memorySnapshots[$operationId]);
        unset($this->currentMetrics[$operationId]);
    }

    /**
     * Carga las métricas existentes
     */
    private function loadMetrics(): void
    {
        $this->currentMetrics = get_option(self::METRICS_OPTION, []);
    }

    /**
     * Registra un error con su tipo y contexto
     */
    public function recordError(
        string $operationId,
        string $errorType,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        if (!isset($this->currentMetrics[$operationId])) {
            return;
        }

        $error = [
            'time' => date('Y-m-d H:i:s'),
            'type' => $errorType,
            'message' => $message,
            'code' => $code,
            'context' => $context
        ];

        $this->currentMetrics[$operationId]['errors'][] = $error;
        $this->currentMetrics[$operationId]['error_types'][$errorType] = 
            ($this->currentMetrics[$operationId]['error_types'][$errorType] ?? 0) + 1;

        $this->logger->error("Error registrado en operación", [
            'operation_id' => $operationId,
            'error_type' => $errorType,
            'message' => $message,
            'code' => $code
        ]);
    }

    /**
     * Obtiene estadísticas de errores
     */
    public function getErrorStats(string $operationId): array
    {
        if (!isset($this->currentMetrics[$operationId])) {
            return [];
        }

        $metrics = $this->currentMetrics[$operationId];
        $totalErrors = count($metrics['errors']);
        $errorTypes = $metrics['error_types'] ?? [];

        return [
            'total_errors' => $totalErrors,
            'error_types' => $errorTypes,
            'error_distribution' => $totalErrors > 0 
                ? array_map(
                    fn($count) => round(($count / $totalErrors) * 100, 2),
                    $errorTypes
                )
                : [],
            'errors' => $metrics['errors']
        ];
    }

    /**
     * Obtiene estadísticas de errores por tipo
     */
    public function getErrorTypeStats(int $days = 7): array
    {
        $allMetrics = get_option(self::METRICS_OPTION, []);
        $cutoffDate = strtotime("-{$days} days");
        
        $stats = [
            'total_errors' => 0,
            'by_type' => [],
            'by_entity' => [],
            'trend' => []
        ];

        foreach ($allMetrics as $operation) {
            if (strtotime($operation['start_time']) < $cutoffDate) {
                continue;
            }

            $entity = $operation['entity'];
            $errorTypes = $operation['error_types'] ?? [];

            foreach ($errorTypes as $type => $count) {
                // Estadísticas por tipo
                $stats['by_type'][$type] = ($stats['by_type'][$type] ?? 0) + $count;
                
                // Estadísticas por entidad
                if (!isset($stats['by_entity'][$entity])) {
                    $stats['by_entity'][$entity] = [
                        'total' => 0,
                        'by_type' => []
                    ];
                }
                $stats['by_entity'][$entity]['total'] += $count;
                $stats['by_entity'][$entity]['by_type'][$type] = 
                    ($stats['by_entity'][$entity]['by_type'][$type] ?? 0) + $count;

                // Tendencia temporal
                $date = date('Y-m-d', strtotime($operation['start_time']));
                if (!isset($stats['trend'][$date])) {
                    $stats['trend'][$date] = [
                        'total' => 0,
                        'by_type' => []
                    ];
                }
                $stats['trend'][$date]['total'] += $count;
                $stats['trend'][$date]['by_type'][$type] = 
                    ($stats['trend'][$date]['by_type'][$type] ?? 0) + $count;
            }

            $stats['total_errors'] += array_sum($errorTypes);
        }

        return $stats;
    }

    /**
     * Registra un error de validación
     */
    public function recordValidationError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_VALIDATION,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de API
     */
    public function recordApiError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_API,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de concurrencia
     */
    public function recordConcurrencyError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_CONCURRENCY,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de memoria
     */
    public function recordMemoryError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_MEMORY,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de red
     */
    public function recordNetworkError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_NETWORK,
            $message,
            $context,
            $code
        );
    }

    /**
     * Registra un error de timeout
     */
    public function recordTimeoutError(
        string $operationId,
        string $message,
        array $context = [],
        ?int $code = null
    ): void {
        $this->recordError(
            $operationId,
            self::ERROR_TYPE_TIMEOUT,
            $message,
            $context,
            $code
        );
    }
} 