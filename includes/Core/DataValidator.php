<?php
/**
 * Clase para manejar la validación de datos en toda la aplicación
 *
 * @package MiIntegracionApi
 * @subpackage Core
 */

namespace MiIntegracionApi\Core;

class DataValidator {
    /**
     * Valida un ID de producto
     *
     * @param mixed $id ID a validar
     * @return bool
     */
    public static function validate_product_id($id) {
        return is_numeric($id) && $id > 0;
    }

    /**
     * Valida un ID de pedido
     *
     * @param mixed $id ID a validar
     * @return bool
     */
    public static function validate_order_id($id) {
        return is_numeric($id) && $id > 0;
    }

    /**
     * Valida un ID de cliente
     *
     * @param mixed $id ID a validar
     * @return bool
     */
    public static function validate_customer_id($id) {
        return is_numeric($id) && $id > 0;
    }

    /**
     * Valida un ID de categoría
     *
     * @param mixed $id ID a validar
     * @return bool
     */
    public static function validate_category_id($id) {
        return is_numeric($id) && $id > 0;
    }

    /**
     * Valida un array de datos de producto
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_product_data($data) {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['name', 'price', 'sku'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida un array de datos de pedido
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_order_data($data) {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['customer_id', 'total', 'status'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida un array de datos de cliente
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_customer_data($data) {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['email', 'first_name', 'last_name'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return true;
    }

    /**
     * Valida un array de datos de categoría
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_category_data($data) {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['name', 'slug'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida un array de datos de configuración de caché
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_cache_config($data) {
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['ttl']) && (!is_numeric($data['ttl']) || $data['ttl'] < 60)) {
            return false;
        }

        if (isset($data['enabled']) && !is_bool($data['enabled'])) {
            return false;
        }

        if (isset($data['storage_method'])) {
            $valid_methods = ['transient', 'file', 'apcu'];
            if (!in_array($data['storage_method'], $valid_methods)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Valida un array de datos de configuración de API
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_api_config($data) {
        if (!is_array($data)) {
            return false;
        }

        $required_fields = ['api_key', 'api_url'];
        foreach ($required_fields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return false;
            }
        }

        if (!filter_var($data['api_url'], FILTER_VALIDATE_URL)) {
            return false;
        }

        return true;
    }

    /**
     * Valida un array de datos de configuración de sincronización
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_sync_config($data) {
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['batch_size']) && (!is_numeric($data['batch_size']) || $data['batch_size'] < 1)) {
            return false;
        }

        if (isset($data['interval']) && (!is_numeric($data['interval']) || $data['interval'] < 60)) {
            return false;
        }

        return true;
    }

    /**
     * Valida un array de datos de configuración de reintentos
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_retry_config($data) {
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['max_attempts']) && (!is_numeric($data['max_attempts']) || $data['max_attempts'] < 1)) {
            return false;
        }

        if (isset($data['delay']) && (!is_numeric($data['delay']) || $data['delay'] < 0)) {
            return false;
        }

        return true;
    }

    /**
     * Valida un array de datos de configuración de logging
     *
     * @param array $data Datos a validar
     * @return bool
     */
    public static function validate_logging_config($data) {
        if (!is_array($data)) {
            return false;
        }

        if (isset($data['enabled']) && !is_bool($data['enabled'])) {
            return false;
        }

        if (isset($data['level'])) {
            $valid_levels = ['debug', 'info', 'warning', 'error', 'critical'];
            if (!in_array($data['level'], $valid_levels)) {
                return false;
            }
        }

        return true;
    }
} 