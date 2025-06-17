<?php
/**
 * Clase base para los DTOs (Data Transfer Objects)
 *
 * @package MiIntegracionApi
 * @subpackage DTOs
 */

namespace MiIntegracionApi\DTOs;

abstract class BaseDTO {
    /**
     * @var array Esquema de validación
     */
    protected static $schema = [];

    /**
     * @var array Datos del DTO
     */
    protected $data = [];

    /**
     * Constructor
     *
     * @param array $data Datos iniciales
     */
    public function __construct(array $data = []) {
        $this->data = $data;
    }

    /**
     * Valida los datos según el esquema
     *
     * @return bool
     */
    public function validate(): bool {
        foreach (static::$schema as $field => $rules) {
            if (isset($rules['required']) && $rules['required'] && !isset($this->data[$field])) {
                return false;
            }

            if (isset($this->data[$field])) {
                if (isset($rules['type'])) {
                    if (!$this->validateType($field, $rules['type'])) {
                        return false;
                    }
                }

                if (isset($rules['min']) && is_numeric($this->data[$field])) {
                    if ($this->data[$field] < $rules['min']) {
                        return false;
                    }
                }

                if (isset($rules['max']) && is_numeric($this->data[$field])) {
                    if ($this->data[$field] > $rules['max']) {
                        return false;
                    }
                }

                if (isset($rules['pattern']) && is_string($this->data[$field])) {
                    if (!preg_match($rules['pattern'], $this->data[$field])) {
                        return false;
                    }
                }

                if (isset($rules['enum']) && is_array($rules['enum'])) {
                    if (!in_array($this->data[$field], $rules['enum'])) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Valida el tipo de un campo
     *
     * @param string $field Nombre del campo
     * @param string $type Tipo esperado
     * @return bool
     */
    protected function validateType(string $field, string $type): bool {
        switch ($type) {
            case 'string':
                return is_string($this->data[$field]);
            case 'integer':
                return is_integer($this->data[$field]);
            case 'float':
                return is_float($this->data[$field]) || is_numeric($this->data[$field]);
            case 'boolean':
                return is_bool($this->data[$field]);
            case 'array':
                return is_array($this->data[$field]);
            case 'object':
                return is_object($this->data[$field]);
            case 'email':
                return filter_var($this->data[$field], FILTER_VALIDATE_EMAIL) !== false;
            case 'url':
                return filter_var($this->data[$field], FILTER_VALIDATE_URL) !== false;
            default:
                return true;
        }
    }

    /**
     * Obtiene un valor del DTO
     *
     * @param string $key Clave del valor
     * @return mixed
     */
    public function get(string $key) {
        return $this->data[$key] ?? null;
    }

    /**
     * Establece un valor en el DTO
     *
     * @param string $key Clave del valor
     * @param mixed $value Valor a establecer
     * @return self
     */
    public function set(string $key, $value): self {
        $this->data[$key] = $value;
        return $this;
    }

    /**
     * Obtiene todos los datos del DTO
     *
     * @return array
     */
    public function toArray(): array {
        return $this->data;
    }

    /**
     * Crea una instancia desde un array
     *
     * @param array $data Datos para crear el DTO
     * @return static
     */
    public static function fromArray(array $data): self {
        return new static($data);
    }
} 