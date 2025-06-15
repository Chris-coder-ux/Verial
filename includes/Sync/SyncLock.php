<?php
namespace MiIntegracionApi\Sync;

/**
 * Clase para gestionar el bloqueo de sincronización y evitar solapamientos.
 */
class SyncLock {
	const LOCK_KEY = 'mi_integracion_api_sync_lock';
	const LOCK_TTL = 600; // 10 minutos

	/**
	 * Intenta adquirir el lock. Devuelve true si lo consigue, false si ya está bloqueado.
	 */
	public static function acquire() {
		if ( self::is_locked() ) {
			return false;
		}
		set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
		return true;
	}

	/**
	 * Libera el lock.
	 */
	public static function release() {
		delete_transient( self::LOCK_KEY );
	}

	/**
	 * Comprueba si el lock está activo.
	 */
	public static function is_locked() {
		return (bool) get_transient( self::LOCK_KEY );
	}

	/**
	 * Devuelve el timestamp en el que se adquirió el lock, o false si no hay lock.
	 */
	public static function get_lock_time() {
		return get_transient( self::LOCK_KEY );
	}
}
