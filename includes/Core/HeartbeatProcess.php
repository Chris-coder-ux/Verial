<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Proceso de heartbeat para sincronización
 */
class HeartbeatProcess extends \WP_Background_Process
{
    private string $entity;
    private const HEARTBEAT_INTERVAL = 60; // 1 minuto

    /**
     * Constructor
     * 
     * @param string $entity Nombre de la entidad
     */
    public function __construct(string $entity)
    {
        parent::__construct();
        $this->entity = $entity;
        $this->action = "mia_heartbeat_{$entity}";
    }

    /**
     * Procesa una tarea de heartbeat
     * 
     * @param array<string, mixed> $item Datos de la tarea
     * @return bool|array<string, mixed> Resultado del procesamiento
     */
    protected function task($item)
    {
        try {
            if (!SyncLock::isLocked($this->entity)) {
                Logger::warning(
                    "Bloqueo no encontrado al actualizar heartbeat",
                    [
                        'entity' => $this->entity,
                        'category' => "sync-heartbeat-{$this->entity}"
                    ]
                );
                return false;
            }

            if (!SyncLock::updateHeartbeat($this->entity)) {
                Logger::error(
                    "Error al actualizar heartbeat",
                    [
                        'entity' => $this->entity,
                        'category' => "sync-heartbeat-{$this->entity}"
                    ]
                );
                return false;
            }

            // Programar siguiente actualización
            $this->push_to_queue([
                'timestamp' => time(),
                'entity' => $this->entity
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::error(
                "Excepción en proceso de heartbeat",
                [
                    'entity' => $this->entity,
                    'error' => $e->getMessage(),
                    'category' => "sync-heartbeat-{$this->entity}"
                ]
            );
            return false;
        }
    }

    /**
     * Completado del proceso
     * 
     * @return void
     */
    protected function complete()
    {
        parent::complete();

        Logger::info(
            "Proceso de heartbeat completado",
            [
                'entity' => $this->entity,
                'category' => "sync-heartbeat-{$this->entity}"
            ]
        );
    }

    /**
     * Inicia el proceso de heartbeat
     * 
     * @return self
     */
    public function start(): self
    {
        Logger::info(
            "Iniciando proceso de heartbeat",
            [
                'entity' => $this->entity,
                'interval' => self::HEARTBEAT_INTERVAL,
                'category' => "sync-heartbeat-{$this->entity}"
            ]
        );

        $this->push_to_queue([
            'timestamp' => time(),
            'entity' => $this->entity
        ]);

        return $this->save()->dispatch();
    }

    /**
     * Detiene el proceso de heartbeat
     * 
     * @return void
     */
    public function stop(): void
    {
        Logger::info(
            "Deteniendo proceso de heartbeat",
            [
                'entity' => $this->entity,
                'category' => "sync-heartbeat-{$this->entity}"
            ]
        );

        $this->cancel_process();
    }
} 