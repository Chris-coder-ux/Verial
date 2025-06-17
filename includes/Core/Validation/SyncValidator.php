<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

use MiIntegracionApi\Core\SyncError;
use MiIntegracionApi\Helpers\Logger;

/**
 * Validador base para sincronización
 */
abstract class SyncValidator
{
    protected array $errors = [];
    protected array $warnings = [];
    protected array $validatedData = [];

    /**
     * Valida los datos de entrada
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return bool Resultado de la validación
     * @throws SyncError Si hay errores de validación
     */
    public function validate(array $data): bool
    {
        $this->errors = [];
        $this->warnings = [];
        $this->validatedData = [];

        try {
            // Validar estructura básica
            $this->validateStructure($data);

            // Validar campos requeridos
            $this->validateRequiredFields($data);

            // Validar tipos de datos
            $this->validateDataTypes($data);

            // Validar reglas específicas
            $this->validateSpecificRules($data);

            // Validar relaciones
            $this->validateRelationships($data);

            // Validar límites y restricciones
            $this->validateLimits($data);

            // Procesar advertencias
            $this->processWarnings();

            // Si hay errores, lanzar excepción
            if (!empty($this->errors)) {
                throw SyncError::validationError(
                    "Errores de validación encontrados",
                    [
                        'errors' => $this->errors,
                        'warnings' => $this->warnings
                    ]
                );
            }

            return true;
        } catch (SyncError $e) {
            Logger::error(
                "Error de validación",
                [
                    'errors' => $this->errors,
                    'warnings' => $this->warnings,
                    'data' => $data,
                    'category' => 'sync-validation'
                ]
            );
            throw $e;
        } catch (\Exception $e) {
            Logger::error(
                "Error inesperado en validación",
                [
                    'error' => $e->getMessage(),
                    'data' => $data,
                    'category' => 'sync-validation'
                ]
            );
            throw new SyncError(
                "Error inesperado en validación: " . $e->getMessage(),
                500,
                ['data' => $data]
            );
        }
    }

    /**
     * Obtiene los datos validados
     * 
     * @return array<string, mixed> Datos validados
     */
    public function getValidatedData(): array
    {
        return $this->validatedData;
    }

    /**
     * Obtiene los errores de validación
     * 
     * @return array<string, mixed> Errores encontrados
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Obtiene las advertencias de validación
     * 
     * @return array<string, mixed> Advertencias encontradas
     */
    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Valida la estructura básica de los datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateStructure(array $data): void;

    /**
     * Valida los campos requeridos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateRequiredFields(array $data): void;

    /**
     * Valida los tipos de datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateDataTypes(array $data): void;

    /**
     * Valida reglas específicas
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateSpecificRules(array $data): void;

    /**
     * Valida relaciones entre datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateRelationships(array $data): void;

    /**
     * Valida límites y restricciones
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    abstract protected function validateLimits(array $data): void;

    /**
     * Procesa las advertencias encontradas
     * 
     * @return void
     */
    protected function processWarnings(): void
    {
        if (!empty($this->warnings)) {
            Logger::warning(
                "Advertencias de validación",
                [
                    'warnings' => $this->warnings,
                    'category' => 'sync-validation'
                ]
            );
        }
    }

    /**
     * Agrega un error de validación
     * 
     * @param string $field Campo con error
     * @param string $message Mensaje de error
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    protected function addError(string $field, string $message, array $context = []): void
    {
        $this->errors[$field] = [
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Agrega una advertencia de validación
     * 
     * @param string $field Campo con advertencia
     * @param string $message Mensaje de advertencia
     * @param array<string, mixed> $context Contexto adicional
     * @return void
     */
    protected function addWarning(string $field, string $message, array $context = []): void
    {
        $this->warnings[$field] = [
            'message' => $message,
            'context' => $context
        ];
    }

    /**
     * Valida que un valor sea de un tipo específico
     * 
     * @param mixed $value Valor a validar
     * @param string $type Tipo esperado
     * @param string $field Nombre del campo
     * @return bool Resultado de la validación
     */
    protected function validateType(mixed $value, string $type, string $field): bool
    {
        $valid = match($type) {
            'string' => is_string($value),
            'int' => is_int($value) || (is_string($value) && ctype_digit($value)),
            'float' => is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, ['0', '1', 'true', 'false'], true),
            'array' => is_array($value),
            'object' => is_object($value),
            default => false
        };

        if (!$valid) {
            $this->addError(
                $field,
                "El campo debe ser de tipo {$type}",
                ['value' => $value, 'type' => gettype($value)]
            );
        }

        return $valid;
    }

    /**
     * Valida que un valor esté dentro de un rango
     * 
     * @param mixed $value Valor a validar
     * @param mixed $min Valor mínimo
     * @param mixed $max Valor máximo
     * @param string $field Nombre del campo
     * @return bool Resultado de la validación
     */
    protected function validateRange(mixed $value, mixed $min, mixed $max, string $field): bool
    {
        if ($value < $min || $value > $max) {
            $this->addError(
                $field,
                "El valor debe estar entre {$min} y {$max}",
                ['value' => $value, 'min' => $min, 'max' => $max]
            );
            return false;
        }

        return true;
    }

    /**
     * Valida que un valor cumpla con un patrón
     * 
     * @param mixed $value Valor a validar
     * @param string $pattern Patrón regex
     * @param string $field Nombre del campo
     * @return bool Resultado de la validación
     */
    protected function validatePattern(mixed $value, string $pattern, string $field): bool
    {
        if (!is_string($value) || !preg_match($pattern, $value)) {
            $this->addError(
                $field,
                "El valor no cumple con el patrón requerido",
                ['value' => $value, 'pattern' => $pattern]
            );
            return false;
        }

        return true;
    }
} 