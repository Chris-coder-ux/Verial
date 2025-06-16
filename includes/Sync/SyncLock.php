<?php
namespace MiIntegracionApi\Sync;

/**
 * Clase para gestionar el bloqueo de sincronización y evitar solapamientos.
 */
class SyncLock {
	const LOCK_KEY = 'mi_integracion_api_sync_lock';
	const LOCK_TTL = 600; // 10 minutos
    const BATCH_LOCK_KEY = 'mi_integracion_api_batch_sync_lock';
    const SINGLE_LOCK_KEY = 'mi_integracion_api_single_sync_lock';

	/**
	 * Intenta adquirir el lock. Devuelve true si lo consigue, false si ya está bloqueado.
     * 
     * @param string $type Tipo de bloqueo: 'any' para cualquier tipo, 'batch' para sincronización por lotes, 
     *                     'single' para sincronización individual
     * @return boolean True si adquiere el lock, false si no lo consigue
	 */
	public static function acquire($type = 'any') {
        $logger = new \MiIntegracionApi\Helpers\Logger('sync-lock');
        
		if ($type === 'single') {
            // Para sincronización individual, solo comprobamos si hay bloqueo de lotes
            if (get_transient(self::BATCH_LOCK_KEY)) {
                $logger->info('Sincronización individual rechazada - hay una sincronización por lotes activa');
                return false;
            }
            
            // Marcar que hay una sincronización individual en curso (permitimos múltiples)
            $count = (int)get_transient(self::SINGLE_LOCK_KEY) ?: 0;
            set_transient(self::SINGLE_LOCK_KEY, $count + 1, self::LOCK_TTL);
            $logger->info('Lock de sincronización individual adquirido', ['count' => $count + 1]);
            return true;
        } 
        else if ($type === 'batch') {
            // Para sincronización por lotes, verificamos ambos tipos de bloqueo
            if (get_transient(self::BATCH_LOCK_KEY)) {
                $logger->info('Sincronización por lotes rechazada - ya hay otra en curso');
                return false;
            }
            
            if (get_transient(self::SINGLE_LOCK_KEY)) {
                $logger->info('Sincronización por lotes rechazada - hay sincronizaciones individuales en curso');
                return false;
            }
            
            // Establecer bloqueo de lotes
            set_transient(self::BATCH_LOCK_KEY, time(), self::LOCK_TTL);
            $logger->info('Lock de sincronización por lotes adquirido');
            return true;
        }
        else {
            // Comportamiento antiguo para compatibilidad
    		if (self::is_locked()) {
                $logger->info('Sincronización rechazada - ya hay otra en curso');
    			return false;
    		}
    		set_transient(self::LOCK_KEY, time(), self::LOCK_TTL);
            $logger->info('Lock de sincronización adquirido');
    		return true;
        }
	}

	/**
	 * Libera el lock.
     * 
     * @param string $type Tipo de bloqueo: 'any' para el tipo genérico, 'batch' para sincronización por lotes, 
     *                     'single' para reducir conteo de sincronización individual
	 */
	public static function release($type = 'any') {
        $logger = new \MiIntegracionApi\Helpers\Logger('sync-lock');
        
        if ($type === 'single') {
            // Para sincronizaciones individuales, reducimos el contador
            $count = (int)get_transient(self::SINGLE_LOCK_KEY) ?: 0;
            
            if ($count > 1) {
                set_transient(self::SINGLE_LOCK_KEY, $count - 1, self::LOCK_TTL);
                $logger->info('Lock de sincronización individual reducido', ['count' => $count - 1]);
            } else {
                delete_transient(self::SINGLE_LOCK_KEY);
                $logger->info('Lock de sincronización individual liberado completamente');
            }
        } 
        else if ($type === 'batch') {
            // Liberar el bloqueo de lotes
            delete_transient(self::BATCH_LOCK_KEY);
            $logger->info('Lock de sincronización por lotes liberado');
        }
        else {
            // Comportamiento antiguo
            delete_transient(self::LOCK_KEY);
            $logger->info('Lock genérico de sincronización liberado');
            
            // También intentamos limpiar los otros locks para seguridad
            delete_transient(self::BATCH_LOCK_KEY);
            delete_transient(self::SINGLE_LOCK_KEY);
        }
	}

	/**
	 * Comprueba si el lock está activo.
     * 
     * @param string $type Tipo de bloqueo a verificar: 'any' para cualquiera, 
     *                     'batch' solo para lotes, 'single' solo para individuales
     * @return boolean True si hay un bloqueo activo del tipo especificado
	 */
	public static function is_locked($type = 'any') {
        if ($type === 'batch') {
            return (bool) get_transient(self::BATCH_LOCK_KEY);
        } 
        else if ($type === 'single') {
            return (bool) get_transient(self::SINGLE_LOCK_KEY);
        }
        else {
            // Para verificación genérica, comprobamos cualquiera de los bloqueos
            return (bool) (
                get_transient(self::LOCK_KEY) || 
                get_transient(self::BATCH_LOCK_KEY) || 
                get_transient(self::SINGLE_LOCK_KEY)
            );
        }
	}

	/**
	 * Devuelve el timestamp en el que se adquirió el lock, o false si no hay lock.
     * 
     * @param string $type Tipo de bloqueo: 'any' (por defecto), 'batch', o 'single'
     * @return int|bool Timestamp de adquisición del lock o false si no hay lock
	 */
	public static function get_lock_time($type = 'any') {
        if ($type === 'batch') {
            return get_transient(self::BATCH_LOCK_KEY);
        } 
        else if ($type === 'single') {
            return get_transient(self::SINGLE_LOCK_KEY);
        }
        else {
            // Para el tipo original
            return get_transient(self::LOCK_KEY);
        }
	}
    
    /**
     * Fuerza la liberación de todos los bloqueos de sincronización
     * Útil para casos donde los bloqueos quedan huérfanos
     * 
     * @return bool True si algún bloqueo fue eliminado
     */
    public static function force_unlock_all() {
        $logger = new \MiIntegracionApi\Helpers\Logger('sync-lock');
        $logger->warning('Se ha forzado la liberación de TODOS los bloqueos de sincronización');
        
        $result1 = delete_transient(self::LOCK_KEY);
        $result2 = delete_transient(self::BATCH_LOCK_KEY);
        $result3 = delete_transient(self::SINGLE_LOCK_KEY);
        
        return $result1 || $result2 || $result3;
    }
}
