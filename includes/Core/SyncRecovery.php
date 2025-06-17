<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Sistema de recuperación para sincronizaciones
 */
class SyncRecovery
{
    private const RECOVERY_PREFIX = 'mia_sync_recovery_';
    private const DEFAULT_TTL = 86400; // 24 horas

    /**
     * Guarda el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @param array<string, mixed> $state Estado de la sincronización
     * @param int $ttl Tiempo de vida en segundos
     * @return bool Éxito de la operación
     */
    public static function saveState(string $entity, array $state, int $ttl = self::DEFAULT_TTL): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getRecoveryKey($entity);
        $state['timestamp'] = time();
        $state['entity'] = $entity;

        Logger::info(
            "Guardando estado de recuperación para {$entity}",
            [
                'batch' => $state['last_batch'] ?? 0,
                'processed' => $state['processed_items'] ?? 0,
                'category' => "sync-recovery-{$entity}"
            ]
        );

        return set_transient($key, $state, $ttl);
    }

    /**
     * Obtiene el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, mixed>|false Estado de recuperación o false si no existe
     */
    public static function getState(string $entity): array|false
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getRecoveryKey($entity);
        $state = get_transient($key);

        if ($state === false) {
            return false;
        }

        // Verificar si el estado ha expirado
        if (isset($state['timestamp']) && (time() - $state['timestamp']) > self::DEFAULT_TTL) {
            self::clearState($entity);
            return false;
        }

        return $state;
    }

    /**
     * Limpia el estado de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function clearState(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getRecoveryKey($entity);
        
        Logger::info(
            "Limpiando estado de recuperación para {$entity}",
            ['category' => "sync-recovery-{$entity}"]
        );

        return delete_transient($key);
    }

    /**
     * Verifica si existe un punto de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return bool True si existe un punto de recuperación
     */
    public static function hasRecoveryPoint(string $entity): bool
    {
        return self::getState($entity) !== false;
    }

    /**
     * Obtiene el mensaje de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return string Mensaje de recuperación
     */
    public static function getRecoveryMessage(string $entity): string
    {
        $state = self::getState($entity);
        
        if ($state === false) {
            return '';
        }

        $lastBatch = $state['last_batch'] ?? 0;
        $processed = $state['processed_items'] ?? 0;
        $total = $state['total_items'] ?? 0;
        $timestamp = $state['timestamp'] ?? 0;

        return sprintf(
            'Se encontró un punto de recuperación del %s. Último lote procesado: %d. Elementos procesados: %d/%d.',
            date('Y-m-d H:i:s', $timestamp),
            $lastBatch,
            $processed,
            $total
        );
    }

    /**
     * Obtiene la clave de recuperación
     * 
     * @param string $entity Nombre de la entidad
     * @return string Clave de recuperación
     */
    private static function getRecoveryKey(string $entity): string
    {
        return self::RECOVERY_PREFIX . sanitize_key($entity);
    }
} 