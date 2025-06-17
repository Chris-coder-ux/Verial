<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

use MiIntegracionApi\Helpers\Logger;

/**
 * Clase para manejar transacciones de base de datos de manera robusta
 */
class TransactionManager
{
    private static $instance = null;
    private $logger;
    private $active_transactions = [];
    private $transaction_depth = 0;
    private $savepoints = [];

    /**
     * Constructor privado para implementar Singleton
     */
    private function __construct()
    {
        $this->logger = new Logger();
    }

    /**
     * Obtiene la instancia única de TransactionManager
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Inicia una nueva transacción
     * 
     * @param string $context Contexto de la transacción (ej: 'productos', 'pedidos', etc.)
     * @param string $operation_id ID único de la operación
     * @return bool True si la transacción se inició correctamente
     */
    public function beginTransaction(string $context, string $operation_id): bool
    {
        global $wpdb;

        try {
            if ($this->transaction_depth === 0) {
                $wpdb->query('START TRANSACTION');
                $this->logger->info("Iniciando transacción", [
                    'context' => $context,
                    'operation_id' => $operation_id
                ]);
            } else {
                $savepoint = "SP_{$this->transaction_depth}";
                $wpdb->query("SAVEPOINT {$savepoint}");
                $this->savepoints[] = $savepoint;
                $this->logger->info("Creando punto de guardado", [
                    'context' => $context,
                    'operation_id' => $operation_id,
                    'savepoint' => $savepoint
                ]);
            }

            $this->active_transactions[] = [
                'context' => $context,
                'operation_id' => $operation_id,
                'start_time' => microtime(true)
            ];
            $this->transaction_depth++;

            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error al iniciar transacción", [
                'context' => $context,
                'operation_id' => $operation_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Confirma una transacción
     * 
     * @param string $context Contexto de la transacción
     * @param string $operation_id ID de la operación
     * @return bool True si la transacción se confirmó correctamente
     */
    public function commit(string $context, string $operation_id): bool
    {
        global $wpdb;

        try {
            if ($this->transaction_depth === 0) {
                $this->logger->warning("No hay transacción activa para confirmar", [
                    'context' => $context,
                    'operation_id' => $operation_id
                ]);
                return false;
            }

            $this->transaction_depth--;

            if ($this->transaction_depth === 0) {
                $wpdb->query('COMMIT');
                $this->logger->info("Transacción confirmada", [
                    'context' => $context,
                    'operation_id' => $operation_id
                ]);
            } else {
                $savepoint = array_pop($this->savepoints);
                $wpdb->query("RELEASE SAVEPOINT {$savepoint}");
                $this->logger->info("Punto de guardado liberado", [
                    'context' => $context,
                    'operation_id' => $operation_id,
                    'savepoint' => $savepoint
                ]);
            }

            array_pop($this->active_transactions);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error al confirmar transacción", [
                'context' => $context,
                'operation_id' => $operation_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Revierte una transacción
     * 
     * @param string $context Contexto de la transacción
     * @param string $operation_id ID de la operación
     * @return bool True si la transacción se revirtió correctamente
     */
    public function rollback(string $context, string $operation_id): bool
    {
        global $wpdb;

        try {
            if ($this->transaction_depth === 0) {
                $this->logger->warning("No hay transacción activa para revertir", [
                    'context' => $context,
                    'operation_id' => $operation_id
                ]);
                return false;
            }

            $this->transaction_depth--;

            if ($this->transaction_depth === 0) {
                $wpdb->query('ROLLBACK');
                $this->logger->info("Transacción revertida", [
                    'context' => $context,
                    'operation_id' => $operation_id
                ]);
            } else {
                $savepoint = array_pop($this->savepoints);
                $wpdb->query("ROLLBACK TO SAVEPOINT {$savepoint}");
                $this->logger->info("Revertido a punto de guardado", [
                    'context' => $context,
                    'operation_id' => $operation_id,
                    'savepoint' => $savepoint
                ]);
            }

            array_pop($this->active_transactions);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error al revertir transacción", [
                'context' => $context,
                'operation_id' => $operation_id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Ejecuta una operación dentro de una transacción
     * 
     * @param callable $operation Operación a ejecutar
     * @param string $context Contexto de la transacción
     * @param string $operation_id ID de la operación
     * @return mixed Resultado de la operación
     * @throws \Exception Si la operación falla
     */
    public function executeInTransaction(callable $operation, string $context, string $operation_id)
    {
        if (!$this->beginTransaction($context, $operation_id)) {
            throw new \Exception("No se pudo iniciar la transacción");
        }

        try {
            $result = $operation();
            $this->commit($context, $operation_id);
            return $result;
        } catch (\Exception $e) {
            $this->rollback($context, $operation_id);
            throw $e;
        }
    }

    /**
     * Verifica si hay una transacción activa
     * 
     * @return bool True si hay una transacción activa
     */
    public function isActive(): bool
    {
        return $this->transaction_depth > 0;
    }

    /**
     * Obtiene información sobre las transacciones activas
     * 
     * @return array Información de las transacciones activas
     */
    public function getActiveTransactions(): array
    {
        return $this->active_transactions;
    }
} 