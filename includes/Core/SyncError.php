<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use Exception;

/**
 * Clase base para errores de sincronización
 */
class SyncError extends Exception
{
    public const VALIDATION_ERROR = 400;
    public const API_ERROR = 500;
    public const CONCURRENCY_ERROR = 409;
    public const MEMORY_ERROR = 507;
    public const NETWORK_ERROR = 503;
    public const TIMEOUT_ERROR = 504;
    public const RETRYABLE_ERROR = 429;

    protected array $context = [];
    protected bool $isRetryable = false;
    protected int $retryDelay = 0;

    /**
     * @param string $message Mensaje de error
     * @param int $code Código de error
     * @param array<string, mixed> $context Datos adicionales del error
     * @param bool $isRetryable Indica si el error es reintentable
     * @param int $retryDelay Retraso entre reintentos
     */
    public function __construct(
        string $message,
        int $code = 0,
        array $context = [],
        bool $isRetryable = false,
        int $retryDelay = 0
    ) {
        parent::__construct($message, $code);
        $this->context = $context;
        $this->isRetryable = $isRetryable;
        $this->retryDelay = $retryDelay;
    }

    /**
     * Obtiene los datos adicionales del error
     * 
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Indica si el error es reintentable
     * 
     * @return bool
     */
    public function isRetryable(): bool
    {
        return $this->isRetryable;
    }

    /**
     * Obtiene el retraso entre reintentos
     * 
     * @return int
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }

    /**
     * Crea un error de validación
     */
    public static function validationError(string $message, array $context = []): self
    {
        return new self($message, self::VALIDATION_ERROR, $context);
    }

    /**
     * Crea un error de API
     */
    public static function apiError(string $message, array $context = []): self
    {
        return new self($message, self::API_ERROR, $context);
    }

    /**
     * Crea un error de concurrencia
     */
    public static function concurrencyError(string $message, array $context = []): self
    {
        return new self($message, self::CONCURRENCY_ERROR, $context);
    }

    /**
     * Crea un error de memoria
     */
    public static function memoryError(string $message, array $context = []): self
    {
        return new self($message, self::MEMORY_ERROR, $context);
    }

    /**
     * Crea un error de red
     */
    public static function networkError(string $message, array $context = []): self
    {
        return new self(
            $message,
            self::NETWORK_ERROR,
            $context,
            true,
            5 // 5 segundos de retraso
        );
    }

    /**
     * Crea un error de timeout
     */
    public static function timeoutError(string $message, array $context = []): self
    {
        return new self(
            $message,
            self::TIMEOUT_ERROR,
            $context,
            true,
            10 // 10 segundos de retraso
        );
    }

    /**
     * Crea un error reintentable
     */
    public static function retryableError(string $message, array $context = [], int $retryDelay = 5): self
    {
        return new self(
            $message,
            self::RETRYABLE_ERROR,
            $context,
            true,
            $retryDelay
        );
    }
} 