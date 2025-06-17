<?php
/**
 * Sistema de Recuperación desde Último Punto Exitoso en Sincronizaciones
 *
 * Esta clase permite que las sincronizaciones por lotes puedan reanudarse
 * desde el último punto exitoso en caso de error, timeout, corte de conexión
 * o cancelación, evitando duplicados y omisiones.
 *
 * @package MiIntegracionApi\Sync
 */

namespace MiIntegracionApi\Sync;

if (!defined('ABSPATH')) {
    exit;
}

class SyncRecovery {
    /**
     * Prefijo para las claves de recuperación en transients/options
     */
    const RECOVERY_PREFIX = 'mia_sync_recovery_';
    
    /**
     * TTL por defecto para el estado de recuperación (24 horas)
     */
    const DEFAULT_TTL = 86400;
    
    /**
     * Almacena el estado de recuperación para una entidad específica
     *
     * @param string $entity Nombre de la entidad (productos, clientes, pedidos)
     * @param array $state Estado completo de la sincronización
     * @param int $ttl Tiempo de vida del estado en segundos
     * @return bool Verdadero si se guardó correctamente
     */
    public static function save_recovery_state($entity, array $state, $ttl = self::DEFAULT_TTL) {
        if (empty($entity)) {
            return false;
        }
        
        $key = self::get_recovery_key($entity);
        $state['timestamp'] = current_time('mysql');
        $state['entity'] = $entity;
        
        \MiIntegracionApi\Helpers\Logger::info(
            "Guardando punto de recuperación para sincronización de {$entity}", 
            [
                'batch' => $state['last_batch'] ?? 0,
                'processed' => $state['processed'] ?? 0,
                'category' => "sync-recovery-{$entity}",
            ]
        );
        
        return set_transient($key, $state, $ttl);
    }
    
    /**
     * Recupera el estado de recuperación para una entidad específica
     *
     * @param string $entity Nombre de la entidad (productos, clientes, pedidos)
     * @return array|false Estado de recuperación o falso si no existe
     */
    public static function get_recovery_state($entity) {
        if (empty($entity)) {
            return false;
        }
        
        $key = self::get_recovery_key($entity);
        return get_transient($key);
    }
    
    /**
     * Elimina el estado de recuperación para una entidad específica
     *
     * @param string $entity Nombre de la entidad (productos, clientes, pedidos)
     * @return bool Verdadero si se eliminó correctamente
     */
    public static function clear_recovery_state($entity) {
        if (empty($entity)) {
            return false;
        }
        
        $key = self::get_recovery_key($entity);
        \MiIntegracionApi\Helpers\Logger::info(
            "Eliminando punto de recuperación para sincronización de {$entity}", 
            ['category' => "sync-recovery-{$entity}"]
        );
        
        return delete_transient($key);
    }
    
    /**
     * Verifica si existe un estado de recuperación para una entidad
     *
     * @param string $entity Nombre de la entidad (productos, clientes, pedidos)
     * @return bool Verdadero si existe un estado de recuperación
     */
    public static function has_recovery_state($entity) {
        return self::get_recovery_state($entity) !== false;
    }
    
    /**
     * Obtiene la clave de recuperación para una entidad
     *
     * @param string $entity Nombre de la entidad
     * @return string Clave de transient/option
     */
    private static function get_recovery_key($entity) {
        return self::RECOVERY_PREFIX . sanitize_key($entity);
    }
    
    /**
     * Comprueba si una sincronización puede continuar desde un punto previo
     *
     * @param string $entity Nombre de la entidad
     * @param array $current_filters Filtros actuales de sincronización
     * @return bool|array False si no se puede continuar, o el estado de recuperación si es compatible
     */
    public static function can_resume($entity, array $current_filters = []) {
        $state = self::get_recovery_state($entity);
        
        if (!$state || empty($state['filters']) || empty($state['last_batch'])) {
            return false;
        }
        
        // Verificar compatibilidad de filtros si se proporcionan
        if (!empty($current_filters)) {
            // Verificar filtros fundamentales (fecha, categoría, etc.)
            $critical_filters = ['fecha', 'categoria', 'marca'];
            foreach ($critical_filters as $filter) {
                if (isset($current_filters[$filter]) && isset($state['filters'][$filter]) &&
                    $current_filters[$filter] !== $state['filters'][$filter]) {
                    return false;
                }
            }
        }
        
        return $state;
    }
    
    /**
     * Calcula el progreso de la sincronización basado en el estado de recuperación
     *
     * @param string $entity Nombre de la entidad
     * @return array Información de progreso
     */
    public static function get_recovery_progress($entity) {
        $state = self::get_recovery_state($entity);
        
        if (!$state) {
            return [
                'exists' => false,
                'percentage' => 0,
                'processed' => 0,
                'total' => 0,
                'date' => null,
            ];
        }
        
        $total = $state['total'] ?? 0;
        $processed = $state['processed'] ?? 0;
        $percentage = $total > 0 ? round(($processed / $total) * 100, 1) : 0;
        
        return [
            'exists' => true,
            'percentage' => $percentage,
            'processed' => $processed,
            'total' => $total,
            'date' => $state['timestamp'] ?? null,
            'last_batch' => $state['last_batch'] ?? 0,
            'errors' => $state['errors'] ?? 0,
        ];
    }
    
    /**
     * Genera un mensaje informativo sobre el estado de recuperación
     *
     * @param string $entity Nombre de la entidad
     * @return string Mensaje informativo o cadena vacía si no hay recuperación
     */
    public static function get_recovery_message($entity) {
        $progress = self::get_recovery_progress($entity);
        
        if (!$progress['exists']) {
            return '';
        }
        
        return sprintf(
            /* translators: %1$s: entidad, %2$d: porcentaje, %3$d: procesados, %4$d: total, %5$s: fecha */
            __('Existe una sincronización de %1$s incompleta (%2$d%% completada, %3$d de %4$d procesados) del %5$s. Puede reanudarla o iniciar una nueva.', 'mi-integracion-api'),
            $entity,
            $progress['percentage'],
            $progress['processed'],
            $progress['total'],
            $progress['date']
        );
    }
}
