<?php
/**
 * Clase para manejar la configuración de caché del plugin
 * 
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit;
}

class CacheConfig {
    /**
     * Opciones de configuración de caché
     */
    const OPTIONS = [
        'enabled' => 'mi_integracion_api_cache_enabled',
        'default_ttl' => 'mi_integracion_api_cache_default_ttl',
        'storage_method' => 'mi_integracion_api_cache_storage_method',
        'entity_ttls' => 'mi_integracion_api_cache_entity_ttls'
    ];

    /**
     * TTLs predeterminados por entidad
     */
    const DEFAULT_ENTITY_TTLS = [
        'product' => 3600,    // 1 hora
        'order' => 1800,      // 30 minutos
        'customer' => 7200,   // 2 horas
        'category' => 86400,  // 24 horas
        'global' => 300       // 5 minutos
    ];

    /**
     * Obtiene el TTL configurado para una entidad específica
     * 
     * @param string $entity Nombre de la entidad
     * @return int TTL en segundos
     */
    public static function get_ttl_for_entity(string $entity): int {
        $entity_ttls = get_option(self::OPTIONS['entity_ttls'], self::DEFAULT_ENTITY_TTLS);
        return $entity_ttls[$entity] ?? self::DEFAULT_ENTITY_TTLS['global'];
    }

    /**
     * Establece el TTL para una entidad específica
     * 
     * @param string $entity Nombre de la entidad
     * @param int $ttl TTL en segundos
     * @return bool True si se actualizó correctamente
     */
    public static function set_ttl_for_entity(string $entity, int $ttl): bool {
        $entity_ttls = get_option(self::OPTIONS['entity_ttls'], self::DEFAULT_ENTITY_TTLS);
        $entity_ttls[$entity] = max(60, $ttl); // Mínimo 60 segundos
        return update_option(self::OPTIONS['entity_ttls'], $entity_ttls);
    }

    /**
     * Obtiene el TTL predeterminado global
     * 
     * @return int TTL en segundos
     */
    public static function get_default_ttl(): int {
        return (int) get_option(self::OPTIONS['default_ttl'], self::DEFAULT_ENTITY_TTLS['global']);
    }

    /**
     * Establece el TTL predeterminado global
     * 
     * @param int $ttl TTL en segundos
     * @return bool True si se actualizó correctamente
     */
    public static function set_default_ttl(int $ttl): bool {
        return update_option(self::OPTIONS['default_ttl'], max(60, $ttl));
    }

    /**
     * Verifica si la caché está habilitada
     * 
     * @return bool True si está habilitada
     */
    public static function is_enabled(): bool {
        return (bool) get_option(self::OPTIONS['enabled'], true);
    }

    /**
     * Habilita o deshabilita la caché
     * 
     * @param bool $enabled Estado deseado
     * @return bool True si se actualizó correctamente
     */
    public static function set_enabled(bool $enabled): bool {
        return update_option(self::OPTIONS['enabled'], $enabled);
    }

    /**
     * Obtiene el método de almacenamiento configurado
     * 
     * @return string Método de almacenamiento
     */
    public static function get_storage_method(): string {
        return get_option(self::OPTIONS['storage_method'], 'transient');
    }

    /**
     * Establece el método de almacenamiento
     * 
     * @param string $method Método de almacenamiento
     * @return bool True si se actualizó correctamente
     */
    public static function set_storage_method(string $method): bool {
        $valid_methods = ['transient', 'file', 'apcu'];
        if (!in_array($method, $valid_methods)) {
            return false;
        }
        return update_option(self::OPTIONS['storage_method'], $method);
    }
} 