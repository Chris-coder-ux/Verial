<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Gestor centralizado de configuración para la integración
 */
class ConfigManager
{
    private const DEFAULTS = [
        'batch_size_productos' => 100,
        'batch_size_clientes'  => 50,
        'batch_size_pedidos'   => 50,
        // Otros parámetros por defecto...
    ];

    private const VALIDATORS = [
        'batch_size_productos' => ['type' => 'int', 'min' => 1, 'max' => 1000],
        'batch_size_clientes'  => ['type' => 'int', 'min' => 1, 'max' => 1000],
        'batch_size_pedidos'   => ['type' => 'int', 'min' => 1, 'max' => 1000],
        // Otros validadores...
    ];

    private static ?ConfigManager $instance = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Obtiene un parámetro de configuración validado
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $option = get_option('mi_integracion_api_' . $key, self::DEFAULTS[$key] ?? $default);
        return $this->validate($key, $option);
    }

    /**
     * Obtiene el batch size para una entidad
     *
     * @param string $entity
     * @return int
     */
    public function getBatchSize(string $entity): int
    {
        $key = 'batch_size_' . strtolower($entity);
        return (int) $this->get($key, self::DEFAULTS[$key] ?? 50);
    }

    /**
     * Valida un parámetro según las reglas
     *
     * @param string $key
     * @param mixed $value
     * @return mixed
     */
    private function validate(string $key, $value)
    {
        if (!isset(self::VALIDATORS[$key])) {
            return $value;
        }
        $rules = self::VALIDATORS[$key];
        if ($rules['type'] === 'int') {
            $value = (int) $value;
            if (isset($rules['min']) && $value < $rules['min']) {
                $value = $rules['min'];
            }
            if (isset($rules['max']) && $value > $rules['max']) {
                $value = $rules['max'];
            }
        }
        // Otros tipos y validaciones...
        return $value;
    }

    /**
     * Permite actualizar un parámetro de configuración validado
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function set(string $key, $value): bool
    {
        $value = $this->validate($key, $value);
        return update_option('mi_integracion_api_' . $key, $value, true);
    }
} 