<?php
/**
 * Clase auxiliar para la gestión centralizada del tamaño de lote (batch size)
 *
 * Esta clase proporciona métodos para obtener, validar y calcular rangos de tamaño de lote
 * para diferentes entidades (productos, clientes, pedidos, etc.). Sirve como punto único
 * de verdad para todas las operaciones relacionadas con batch size.
 *
 * @package MiIntegracionApi
 * @subpackage Helpers
 * @since 2.6.0
 */

namespace MiIntegracionApi\Helpers;

defined('ABSPATH') || exit;

class BatchSizeHelper {

    /**
     * Valores predeterminados para el tamaño de lote por entidad
     */
    const DEFAULT_BATCH_SIZES = [
        'productos' => 20,
        'products' => 20, // Alias para productos
        'clientes' => 50,
        'customers' => 50, // Alias para clientes
        'pedidos' => 50,
        'orders' => 50, // Alias para pedidos
        'precios' => 20,
        'prices' => 20, // Alias para precios
    ];

    /**
     * Límites de tamaño de lote por entidad
     */
    const BATCH_SIZE_LIMITS = [
        'productos' => ['min' => 1, 'max' => 200],
        'products' => ['min' => 1, 'max' => 200],
        'clientes' => ['min' => 1, 'max' => 200],
        'customers' => ['min' => 1, 'max' => 200],
        'pedidos' => ['min' => 1, 'max' => 100],
        'orders' => ['min' => 1, 'max' => 100],
        'precios' => ['min' => 1, 'max' => 500],
        'prices' => ['min' => 1, 'max' => 500],
    ];

    /**
     * Mapeo de nombres de entidades para estandarización
     */
    const ENTITY_MAPPINGS = [
        'products' => 'productos',
        'customers' => 'clientes',
        'orders' => 'pedidos',
        'prices' => 'precios',
    ];

    /**
     * Nombre base de las opciones de WordPress para tamaño de lote
     */
    const OPTION_PREFIX = 'mi_integracion_api_batch_size_';

    /**
     * Obtiene el tamaño de lote para una entidad específica.
     *
     * @param string $entity Nombre de la entidad (productos, clientes, pedidos, etc.)
     * @param int|null $override_value Valor opcional para sobreescribir temporalmente
     * @return int Tamaño de lote validado
     */
    public static function getBatchSize(string $entity, ?int $override_value = null): int {
        // Normalizar el nombre de la entidad
        $entity = self::normalizeEntityName($entity);
        
        // Si se proporciona un valor de sobreescritura, validarlo y usarlo
        if ($override_value !== null) {
            return self::validateBatchSize($entity, $override_value);
        }
        
        // Obtener el valor almacenado en la base de datos
        $option_name = self::OPTION_PREFIX . $entity;
        $batch_size = get_option($option_name, self::DEFAULT_BATCH_SIZES[$entity] ?? 20);
        
        // Registrar información de depuración si está habilitada
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[BatchSizeHelper] Obteniendo tamaño de lote para %s: %d (desde opción: %s)',
                $entity,
                $batch_size,
                $option_name
            ));
        }
        
        // Validar y devolver el valor
        return self::validateBatchSize($entity, $batch_size);
    }
    
    /**
     * Establece el tamaño de lote para una entidad específica.
     *
     * @param string $entity Nombre de la entidad
     * @param int $batch_size Tamaño de lote a establecer
     * @return bool Éxito de la operación
     */
    public static function setBatchSize(string $entity, int $batch_size): bool {
        // Normalizar el nombre de la entidad
        $entity = self::normalizeEntityName($entity);
        
        // Validar el valor
        $batch_size = self::validateBatchSize($entity, $batch_size);
        
        // Actualizar la opción
        $option_name = self::OPTION_PREFIX . $entity;
        $result = update_option($option_name, $batch_size);
        
        // Registrar información de depuración
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[BatchSizeHelper] Estableciendo tamaño de lote para %s: %d (opción: %s, resultado: %s)',
                $entity,
                $batch_size,
                $option_name,
                $result ? 'éxito' : 'sin cambios'
            ));
        }
        
        // Para mantener compatibilidad con código antiguo, actualizar también la opción 'products' si se actualiza 'productos'
        if ($entity === 'productos') {
            update_option(self::OPTION_PREFIX . 'products', $batch_size);
        }
        
        return $result;
    }

    /**
     * Valida y corrige un tamaño de lote según los límites definidos para la entidad.
     *
     * @param string $entity Nombre de la entidad
     * @param int $batch_size Tamaño de lote a validar
     * @return int Tamaño de lote corregido
     */
    public static function validateBatchSize(string $entity, $batch_size): int {
        // Asegurar que el valor sea numérico
        $batch_size = intval($batch_size);
        
        // Obtener límites para la entidad
        $limits = self::BATCH_SIZE_LIMITS[$entity] ?? ['min' => 1, 'max' => 200];
        
        // Aplicar límites
        $batch_size = max($limits['min'], min($limits['max'], $batch_size));
        
        return $batch_size;
    }
    
    /**
     * Normaliza el nombre de una entidad según el mapeo definido.
     *
     * @param string $entity Nombre de la entidad
     * @return string Nombre normalizado
     */
    public static function normalizeEntityName(string $entity): string {
        return self::ENTITY_MAPPINGS[$entity] ?? $entity;
    }
    
    /**
     * Calcula el rango para procesar un lote basado en un índice de inicio y el tamaño del lote.
     *
     * @param int $start_index Índice de inicio (cero-basado)
     * @param int $batch_size Tamaño del lote
     * @return array Arreglo con claves 'inicio' y 'fin'
     */
    public static function calculateRange(int $start_index, int $batch_size): array {
        $inicio = $start_index + 1; // Convertir a índice 1-basado para la API
        $fin = $inicio + $batch_size - 1;
        
        return [
            'inicio' => $inicio,
            'fin' => $fin
        ];
    }
    
    /**
     * Calcula el tamaño efectivo del lote basado en valores de inicio y fin.
     *
     * @param int $inicio Valor de inicio (1-basado)
     * @param int $fin Valor de fin (1-basado)
     * @return int Tamaño efectivo del lote
     */
    public static function calculateEffectiveBatchSize(int $inicio, int $fin): int {
        return $fin - $inicio + 1;
    }
    
    /**
     * Determina si un tamaño de lote es válido para su uso.
     *
     * @param int $batch_size Tamaño de lote a verificar
     * @return bool True si es válido
     */
    public static function isValidBatchSize(int $batch_size): bool {
        return $batch_size > 0;
    }
    
    /**
     * Divide un array en lotes del tamaño especificado.
     *
     * @param array $items Array a dividir
     * @param int $batch_size Tamaño de cada lote
     * @return array Array de lotes
     */
    public static function chunkItems(array $items, int $batch_size): array {
        // Validar tamaño del lote
        $batch_size = max(1, $batch_size);
        
        // Dividir en lotes
        return array_chunk($items, $batch_size);
    }
}
