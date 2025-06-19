<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core;

/**
 * Gestor centralizado de configuración para la integración
 */
class ConfigManager
{
    private const DEFAULTS = [
        // Los valores de tamaño de lote ahora son gestionados por BatchSizeHelper
        // Otros parámetros por defecto...
    ];

    private const VALIDATORS = [
        // Los validadores de tamaño de lote ahora son gestionados por BatchSizeHelper
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
     * Delega la responsabilidad a BatchSizeHelper, que es la fuente única de verdad
     * para el tamaño de lote.
     *
     * @param string $entity
     * @return int
     */
    public function getBatchSize(string $entity): int
    {
        // Agregar log para depuración
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('config-manager');
            $logger->debug("ConfigManager::getBatchSize delegando a BatchSizeHelper para entidad '{$entity}'");
        }
        
        // Delegar a BatchSizeHelper
        return \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize($entity);
    }

    /**
     * Establece el batch size para una entidad
     *
     * Delega la responsabilidad a BatchSizeHelper, que es la fuente única de verdad
     * para el tamaño de lote.
     *
     * @param string $entity
     * @param int $batch_size
     * @return bool
     */
    public function setBatchSize(string $entity, int $batch_size): bool
    {
        // Agregar log para depuración
        if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('config-manager');
            $logger->debug("ConfigManager::setBatchSize delegando a BatchSizeHelper para entidad '{$entity}'", [
                'batch_size' => $batch_size
            ]);
        }
        
        // Delegar a BatchSizeHelper
        return \MiIntegracionApi\Helpers\BatchSizeHelper::setBatchSize($entity, $batch_size);
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