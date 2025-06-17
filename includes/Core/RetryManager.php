<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Gestor de reintentos para operaciones de sincronización
 */
class RetryManager
{
    private const MAX_RETRIES = 3;
    private const MAX_DELAY = 30; // segundos
    private const BASE_DELAY = 1; // segundo

    private Logger $logger;
    private array $retryCounts = [];

    public function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * Ejecuta una operación con reintentos
     * 
     * @param callable $operation Operación a ejecutar
     * @param string $operationId Identificador único de la operación
     * @param array<string, mixed> $context Contexto adicional
     * @return mixed Resultado de la operación
     * @throws SyncError Si la operación falla después de los reintentos
     */
    public function executeWithRetry(callable $operation, string $operationId, array $context = []): mixed
    {
        $attempt = 1;
        $lastError = null;

        while ($attempt <= self::MAX_RETRIES) {
            try {
                $result = $operation();
                $this->resetRetryCount($operationId);
                return $result;

            } catch (SyncError $e) {
                $lastError = $e;

                if (!$e->isRetryable()) {
                    $this->logger->error(
                        "Error no reintentable en operación {$operationId}",
                        array_merge($context, [
                            'error' => $e->getMessage(),
                            'code' => $e->getCode(),
                            'attempt' => $attempt
                        ])
                    );
                    throw $e;
                }

                $this->incrementRetryCount($operationId);
                $delay = $this->calculateDelay($attempt, $e->getRetryDelay());

                $this->logger->warning(
                    "Reintentando operación {$operationId}",
                    array_merge($context, [
                        'error' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'attempt' => $attempt,
                        'next_attempt' => $delay,
                        'retry_count' => $this->getRetryCount($operationId)
                    ])
                );

                sleep($delay);
                $attempt++;
            }
        }

        $this->logger->error(
            "Operación {$operationId} falló después de {$attempt} intentos",
            array_merge($context, [
                'last_error' => $lastError?->getMessage(),
                'last_error_code' => $lastError?->getCode(),
                'retry_count' => $this->getRetryCount($operationId)
            ])
        );

        throw $lastError ?? new SyncError(
            "Operación {$operationId} falló después de {$attempt} intentos",
            SyncError::API_ERROR,
            $context
        );
    }

    /**
     * Calcula el retraso para el siguiente reintento
     * 
     * @param int $attempt Número de intento actual
     * @param int $baseDelay Retraso base en segundos
     * @return int Retraso en segundos
     */
    private function calculateDelay(int $attempt, int $baseDelay): int
    {
        // Implementación de exponential backoff con jitter
        $delay = min(
            $baseDelay * (2 ** ($attempt - 1)) + rand(0, 1000) / 1000,
            self::MAX_DELAY
        );

        return (int) $delay;
    }

    /**
     * Incrementa el contador de reintentos para una operación
     * 
     * @param string $operationId Identificador de la operación
     */
    private function incrementRetryCount(string $operationId): void
    {
        if (!isset($this->retryCounts[$operationId])) {
            $this->retryCounts[$operationId] = 0;
        }
        $this->retryCounts[$operationId]++;
    }

    /**
     * Obtiene el número de reintentos para una operación
     * 
     * @param string $operationId Identificador de la operación
     * @return int Número de reintentos
     */
    private function getRetryCount(string $operationId): int
    {
        return $this->retryCounts[$operationId] ?? 0;
    }

    /**
     * Reinicia el contador de reintentos para una operación
     * 
     * @param string $operationId Identificador de la operación
     */
    private function resetRetryCount(string $operationId): void
    {
        unset($this->retryCounts[$operationId]);
    }
} 