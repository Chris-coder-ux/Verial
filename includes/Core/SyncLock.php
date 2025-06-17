<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de bloqueos para sincronización
 */
class SyncLock
{
    private const LOCK_PREFIX = 'mia_sync_lock_';
    private const DEFAULT_TIMEOUT = 3600; // 1 hora
    private const DEFAULT_RETRY_DELAY = 5; // 5 segundos
    private const MAX_RETRIES = 3;
    private const HEARTBEAT_INTERVAL = 60; // 1 minuto
    private const HEARTBEAT_TIMEOUT = 300; // 5 minutos

    /**
     * Intenta adquirir un bloqueo
     * 
     * @param string $entity Nombre de la entidad
     * @param int $timeout Tiempo máximo de bloqueo en segundos
     * @param int $retries Número máximo de intentos
     * @return bool Éxito de la operación
     */
    public static function acquire(
        string $entity,
        int $timeout = self::DEFAULT_TIMEOUT,
        int $retries = self::MAX_RETRIES
    ): bool {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $attempt = 0;

        while ($attempt < $retries) {
            // Verificar si ya existe un bloqueo
            $existingLock = get_transient($key);
            
            if ($existingLock === false) {
                // No hay bloqueo, intentar crear uno
                $lockData = [
                    'entity' => $entity,
                    'timestamp' => time(),
                    'timeout' => $timeout,
                    'pid' => getmypid()
                ];

                if (set_transient($key, $lockData, $timeout)) {
                    Logger::info(
                        "Bloqueo adquirido",
                        [
                            'entity' => $entity,
                            'timeout' => $timeout,
                            'pid' => $lockData['pid'],
                            'category' => "sync-lock-{$entity}"
                        ]
                    );
                    return true;
                }
            } else {
                // Verificar si el bloqueo ha expirado
                if (time() - $existingLock['timestamp'] > $existingLock['timeout']) {
                    self::release($entity);
                    continue;
                }

                // Verificar si el proceso que creó el bloqueo sigue activo
                if (self::isProcessActive($existingLock['pid'])) {
                    Logger::warning(
                        "Bloqueo activo encontrado",
                        [
                            'entity' => $entity,
                            'pid' => $existingLock['pid'],
                            'age' => time() - $existingLock['timestamp'],
                            'category' => "sync-lock-{$entity}"
                        ]
                    );
                } else {
                    // El proceso ya no está activo, liberar el bloqueo
                    self::release($entity);
                    continue;
                }
            }

            $attempt++;
            if ($attempt < $retries) {
                sleep(self::DEFAULT_RETRY_DELAY);
            }
        }

        return false;
    }

    /**
     * Libera un bloqueo
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function release(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData !== false) {
            Logger::info(
                "Bloqueo liberado",
                [
                    'entity' => $entity,
                    'pid' => $lockData['pid'],
                    'age' => time() - $lockData['timestamp'],
                    'category' => "sync-lock-{$entity}"
                ]
            );
        }

        return delete_transient($key);
    }

    /**
     * Verifica si un bloqueo está activo
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Estado del bloqueo
     */
    public static function isLocked(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // Verificar si el bloqueo ha expirado
        if (time() - $lockData['timestamp'] > $lockData['timeout']) {
            self::release($entity);
            return false;
        }

        // Verificar si el proceso que creó el bloqueo sigue activo
        if (!self::isProcessActive($lockData['pid'])) {
            self::release($entity);
            return false;
        }

        // Verificar heartbeat
        if (isset($lockData['heartbeat']) && 
            time() - $lockData['heartbeat'] > self::HEARTBEAT_TIMEOUT) {
            Logger::warning(
                "Bloqueo expirado por falta de heartbeat",
                [
                    'entity' => $entity,
                    'pid' => $lockData['pid'],
                    'last_heartbeat' => $lockData['heartbeat'],
                    'category' => "sync-lock-{$entity}"
                ]
            );
            self::release($entity);
            return false;
        }

        return true;
    }

    /**
     * Obtiene información del bloqueo
     * 
     * @param string $entity Nombre de la entidad
     * @return array<string, mixed>|false Información del bloqueo o false si no existe
     */
    public static function getLockInfo(string $entity): array|false
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        return [
            'entity' => $lockData['entity'],
            'timestamp' => $lockData['timestamp'],
            'timeout' => $lockData['timeout'],
            'pid' => $lockData['pid'],
            'age' => time() - $lockData['timestamp'],
            'is_active' => self::isProcessActive($lockData['pid'])
        ];
    }

    /**
     * Verifica si un proceso está activo
     * 
     * @param int $pid ID del proceso
     * @return bool Estado del proceso
     */
    private static function isProcessActive(int $pid): bool
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            return count($output) > 1;
        }

        return file_exists("/proc/{$pid}");
    }

    /**
     * Obtiene la clave para el bloqueo
     * 
     * @param string $entity Nombre de la entidad
     * @return string Clave del bloqueo
     */
    private static function getLockKey(string $entity): string
    {
        return self::LOCK_PREFIX . sanitize_key($entity);
    }

    /**
     * Actualiza el heartbeat del bloqueo
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function updateHeartbeat(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // Verificar si el proceso que creó el bloqueo sigue activo
        if (!self::isProcessActive($lockData['pid'])) {
            self::release($entity);
            return false;
        }

        // Actualizar timestamp y extender el bloqueo
        $lockData['heartbeat'] = time();
        $lockData['timestamp'] = time();

        Logger::debug(
            "Heartbeat actualizado",
            [
                'entity' => $entity,
                'pid' => $lockData['pid'],
                'age' => time() - $lockData['timestamp'],
                'category' => "sync-lock-{$entity}"
            ]
        );

        return set_transient($key, $lockData, $lockData['timeout']);
    }

    /**
     * Inicia el proceso de heartbeat
     * 
     * @param string $entity Nombre de la entidad
     * @return bool Éxito de la operación
     */
    public static function startHeartbeat(string $entity): bool
    {
        if (empty($entity)) {
            return false;
        }

        $key = self::getLockKey($entity);
        $lockData = get_transient($key);

        if ($lockData === false) {
            return false;
        }

        // Agregar timestamp de heartbeat
        $lockData['heartbeat'] = time();

        Logger::info(
            "Iniciando heartbeat",
            [
                'entity' => $entity,
                'pid' => $lockData['pid'],
                'interval' => self::HEARTBEAT_INTERVAL,
                'category' => "sync-lock-{$entity}"
            ]
        );

        return set_transient($key, $lockData, $lockData['timeout']);
    }
} 