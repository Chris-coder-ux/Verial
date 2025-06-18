<?php
/**
 * Clase para asistir en la sincronización de productos entre Verial y WooCommerce
 * 
 * Esta clase proporciona métodos auxiliares para validar, procesar y gestionar
 * la sincronización de productos entre el sistema Verial y WooCommerce.
 * 
 * @package MiIntegracionApi\WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

use MiIntegracionApi\Core\SyncMetrics;
use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class SyncHelper {
    
    /**
     * Instancia del logger
     *
     * @var \MiIntegracionApi\Helpers\Logger
     */
    private static $logger;
    
    /**
     * Inicializa la instancia de logger si no existe
     */
    private static function get_logger() {
        if (!self::$logger) {
            self::$logger = new Logger('SyncHelper');
        }
        return self::$logger;
    }
    
    /**
     * Normaliza un lote de productos de Verial para asegurar una estructura consistente
     *
     * @param array $products Array de productos de Verial
     * @return array Productos normalizados
     */
    public static function normalize_batch($products) {
        if (!is_array($products)) {
            self::get_logger()->error('El lote de productos no es un array válido', [
                'source' => 'SyncHelper', 
                'products' => $products
            ]);
            return [];
        }
        
        $normalized = [];
        foreach ($products as $product) {
            $normalized_product = VerialProductMapper::normalize_verial_product($product);
            if (!empty($normalized_product)) {
                $normalized[] = $normalized_product;
            }
        }
        
        self::get_logger()->info('Lote de productos normalizado', [
            'source' => 'SyncHelper', 
            'count' => count($normalized),
            'original_count' => count($products)
        ]);
        
        return $normalized;
    }
    
    /**
     * Calcular estadísticas del proceso de sincronización
     *
     * @param array $results Resultados de la sincronización
     * @return array Estadísticas calculadas
     */
    public static function calculate_stats($results) {
        $stats = [
            'total' => count($results),
            'processed' => 0,
            'errors' => 0,
            'skipped' => 0,
            'created' => 0,
            'updated' => 0,
            'retry_processed' => 0,
            'retry_errors' => 0,
            'duration' => 0
        ];
        
        foreach ($results as $result) {
            if (isset($result['success']) && $result['success']) {
                $stats['processed']++;
                
                if (isset($result['created']) && $result['created']) {
                    $stats['created']++;
                } elseif (isset($result['updated']) && $result['updated']) {
                    $stats['updated']++;
                }
                
                if (isset($result['retry_processed'])) {
                    $stats['retry_processed'] += $result['retry_processed'];
                }
            } else {
                $stats['errors']++;
                
                if (isset($result['retry_errors'])) {
                    $stats['retry_errors'] += $result['retry_errors'];
                }
            }
            
            if (isset($result['skipped']) && $result['skipped']) {
                $stats['skipped']++;
            }
            
            if (isset($result['duration'])) {
                $stats['duration'] += $result['duration'];
            }
        }
        
        return $stats;
    }
    
    /**
     * Registra métricas de una sincronización por lotes
     *
     * @param string $operation_id ID de la operación
     * @param int $batch_number Número de lote
     * @param array $stats Estadísticas del lote
     * @return void
     */
    public static function record_batch_metrics($operation_id, $batch_number, $stats) {
        try {
            $metrics = new SyncMetrics();
            $metrics->recordBatchMetrics(
                $batch_number,
                $stats['processed'],
                $stats['duration'],
                $stats['errors'],
                $stats['retry_processed'],
                $stats['retry_errors']
            );
            
            self::get_logger()->info('Métricas de lote registradas', [
                'source' => 'SyncHelper', 
                'operation_id' => $operation_id,
                'batch_number' => $batch_number,
                'stats' => $stats
            ]);
        } catch (\Exception $e) {
            self::get_logger()->error('Error al registrar métricas de lote', [
                'source' => 'SyncHelper', 
                'operation_id' => $operation_id,
                'batch_number' => $batch_number,
                'error' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * Verifica si una sincronización debe continuar o ha sido cancelada
     *
     * @return bool True si la sincronización debe continuar, False si debe detenerse
     */
    public static function should_continue_sync() {
        $canceled = get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada');
        if ($canceled) {
            self::get_logger()->info('Sincronización cancelada por el usuario', [
                'source' => 'SyncHelper'
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Actualiza el progreso de la sincronización
     *
     * @param int $current Progreso actual
     * @param int $total Total de elementos
     * @param string $message Mensaje de estado
     * @param array $stats Estadísticas adicionales
     * @return void
     */
    public static function update_sync_progress($current, $total, $message, $stats = []) {
        $percentage = ($total > 0) ? round(($current / $total) * 100, 2) : 0;
        $progress_data = [
            'porcentaje' => $percentage,
            'mensaje' => $message,
            'estadisticas' => [
                'procesados' => $current,
                'total' => $total,
                'errores' => isset($stats['errors']) ? $stats['errors'] : 0
            ]
        ];
        
        // Si hay información del producto/lote actual
        if (isset($stats['current_item'])) {
            $progress_data['estadisticas']['articulo_actual'] = $stats['current_item'];
        }
        
        if (isset($stats['batch_number'])) {
            $progress_data['estadisticas']['lote_actual'] = $stats['batch_number'];
        }
        
        // Guardar el progreso en la opción/transient
        set_transient('mia_sync_progress', $progress_data, 3600); // TTL 1 hora
        
        self::get_logger()->debug('Progreso de sincronización actualizado', [
            'source' => 'SyncHelper', 
            'progress' => $progress_data
        ]);
    }
    
    /**
     * Finaliza una sincronización y registra los resultados
     *
     * @param string $operation_id ID de la operación
     * @param array $stats Estadísticas finales
     * @return void
     */
    public static function finalize_sync($operation_id, $stats) {
        // Registrar el final de la sincronización
        update_option('mia_last_sync_time', time());
        update_option('mia_last_sync_count', $stats['processed']);
        update_option('mia_last_sync_errors', $stats['errors']);
        
        // Limpiar el estado de la sincronización
        delete_transient('mia_sync_progress');
        
        // Registrar la finalización en el log
        self::get_logger()->info('Sincronización finalizada', [
            'source' => 'SyncHelper', 
            'operation_id' => $operation_id,
            'stats' => $stats
        ]);
        
        // Disparar acción para que otros plugins puedan responder
        do_action('mi_integracion_api_sync_completed', $operation_id, $stats);
    }
}
