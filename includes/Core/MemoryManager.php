<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de memoria para operaciones de sincronización
 */
class MemoryManager
{
    private const DEFAULT_MEMORY_LIMIT = 256; // MB
    private const MEMORY_BUFFER = 0.8; // 80% del límite

    /**
     * Verifica si se ha excedido el límite de memoria
     * 
     * @param int $memoryLimit Límite de memoria en MB
     * @return bool True si se ha excedido el límite
     */
    public static function isMemoryLimitExceeded(int $memoryLimit = self::DEFAULT_MEMORY_LIMIT): bool
    {
        $currentUsage = memory_get_usage(true) / 1024 / 1024;
        $limit = $memoryLimit * self::MEMORY_BUFFER;
        
        return $currentUsage > $limit;
    }

    /**
     * Obtiene estadísticas de uso de memoria
     * 
     * @return array<string, mixed> Estadísticas de memoria
     */
    public static function getMemoryStats(): array
    {
        return [
            'current' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'peak' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'limit' => ini_get('memory_limit'),
            'available' => self::getAvailableMemory()
        ];
    }

    /**
     * Limpia la memoria y fuerza la recolección de basura
     * 
     * @param string $context Contexto de la operación para logging
     * @return array<string, mixed> Estadísticas de memoria antes y después de la limpieza
     */
    public static function cleanup(string $context = ''): array
    {
        $before = self::getMemoryStats();
        
        // Liberar variables globales
        global $wpdb;
        if (isset($wpdb)) {
            $wpdb->flush();
        }
        
        // Forzar recolección de basura
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
        
        // Limpiar caché de WordPress
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        $after = self::getMemoryStats();
        
        if (!empty($context)) {
            Logger::info(
                "Limpieza de memoria completada",
                [
                    'context' => $context,
                    'before' => $before,
                    'after' => $after,
                    'reduction' => round($before['current'] - $after['current'], 2)
                ]
            );
        }
        
        return [
            'before' => $before,
            'after' => $after
        ];
    }

    /**
     * Calcula la memoria disponible en MB
     * 
     * @return float Memoria disponible en MB
     */
    private static function getAvailableMemory(): float
    {
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit === '-1') {
            return PHP_FLOAT_MAX;
        }
        
        $unit = strtolower(substr($memoryLimit, -1));
        $value = (int) $memoryLimit;
        
        switch ($unit) {
            case 'g':
                $value *= 1024;
                break;
            case 'm':
                break;
            case 'k':
                $value /= 1024;
                break;
        }
        
        return $value;
    }

    /**
     * Ajusta el tamaño del lote basado en el uso de memoria
     * 
     * @param int $currentBatchSize Tamaño actual del lote
     * @param int $minBatchSize Tamaño mínimo del lote
     * @return int Tamaño ajustado del lote
     */
    public static function adjustBatchSize(int $currentBatchSize, int $minBatchSize = 10): int
    {
        $stats = self::getMemoryStats();
        $availableMemory = $stats['available'];
        $currentUsage = $stats['current'];
        
        // Si el uso actual es más del 70% de la memoria disponible
        if ($currentUsage > ($availableMemory * 0.7)) {
            $newBatchSize = max($minBatchSize, (int) ($currentBatchSize * 0.5));
            
            Logger::warning(
                "Reduciendo tamaño de lote por uso de memoria",
                [
                    'current_batch_size' => $currentBatchSize,
                    'new_batch_size' => $newBatchSize,
                    'memory_usage' => $currentUsage,
                    'memory_available' => $availableMemory
                ]
            );
            
            return $newBatchSize;
        }
        
        return $currentBatchSize;
    }
} 