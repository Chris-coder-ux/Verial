<?php
/**
 * Clase para gestionar hooks de forma segura
 *
 * @package MiIntegracionApi
 * @subpackage Hooks
 * @since 1.0.0
 */

namespace MiIntegracionApi\Hooks;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para gestionar hooks de forma segura
 * 
 * Esta clase proporciona métodos para registrar acciones y filtros de WordPress
 * verificando primero que los callbacks existan y sean válidos.
 */
class HooksManager {
    /**
     * Registro de errores en los hooks
     * 
     * @var array
     */
    private static $errors = [];

    /**
     * Registrar una acción de WordPress de forma segura
     * 
     * @param string   $hook_name     El nombre del hook de WordPress
     * @param callable $callback      La función callback a ejecutar
     * @param int      $priority      La prioridad (por defecto 10)
     * @param int      $accepted_args El número de argumentos que acepta el callback (por defecto 1)
     * @param bool     $conditional   Una condición adicional que debe cumplirse para registrar el hook
     * @return bool                   True si se registró correctamente, false en caso contrario
     */
    public static function add_action($hook_name, $callback, $priority = 10, $accepted_args = 1, $conditional = true) {
        if (!$conditional) {
            return false;
        }
        
        if (!self::is_valid_callback($callback)) {
            self::$errors[] = sprintf('Intento de registrar acción %s con un callback inválido', $hook_name);
            return false;
        }
        
        add_action($hook_name, $callback, $priority, $accepted_args);
        return true;
    }

    /**
     * Registrar un filtro de WordPress de forma segura
     * 
     * @param string   $hook_name     El nombre del hook de WordPress
     * @param callable $callback      La función callback a ejecutar
     * @param int      $priority      La prioridad (por defecto 10)
     * @param int      $accepted_args El número de argumentos que acepta el callback (por defecto 1)
     * @param bool     $conditional   Una condición adicional que debe cumplirse para registrar el hook
     * @return bool                   True si se registró correctamente, false en caso contrario
     */
    public static function add_filter($hook_name, $callback, $priority = 10, $accepted_args = 1, $conditional = true) {
        if (!$conditional) {
            return false;
        }
        
        if (!self::is_valid_callback($callback)) {
            self::$errors[] = sprintf('Intento de registrar filtro %s con un callback inválido', $hook_name);
            return false;
        }
        
        add_filter($hook_name, $callback, $priority, $accepted_args);
        return true;
    }

    /**
     * Registrar una acción de WooCommerce de forma segura (verificando que WC esté activo)
     * 
     * @param string   $hook_name     El nombre del hook de WooCommerce
     * @param callable $callback      La función callback a ejecutar
     * @param int      $priority      La prioridad (por defecto 10)
     * @param int      $accepted_args El número de argumentos que acepta el callback (por defecto 1)
     * @return bool                   True si se registró correctamente, false en caso contrario
     */
    public static function add_wc_action($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return self::add_action($hook_name, $callback, $priority, $accepted_args, self::is_woocommerce_active());
    }

    /**
     * Registrar un filtro de WooCommerce de forma segura (verificando que WC esté activo)
     * 
     * @param string   $hook_name     El nombre del hook de WooCommerce
     * @param callable $callback      La función callback a ejecutar
     * @param int      $priority      La prioridad (por defecto 10)
     * @param int      $accepted_args El número de argumentos que acepta el callback (por defecto 1)
     * @return bool                   True si se registró correctamente, false en caso contrario
     */
    public static function add_wc_filter($hook_name, $callback, $priority = 10, $accepted_args = 1) {
        return self::add_filter($hook_name, $callback, $priority, $accepted_args, self::is_woocommerce_active());
    }

    /**
     * Verifica si un callback es válido
     * 
     * @param mixed $callback El callback a verificar
     * @return bool           True si el callback es válido, false en caso contrario
     */
    private static function is_valid_callback($callback) {
        // Verificar función anónima
        if (is_callable($callback)) {
            return true;
        }
        
        // Verificar string (nombre de función)
        if (is_string($callback) && function_exists($callback)) {
            return true;
        }
        
        // Verificar array [objeto, método]
        if (is_array($callback) && count($callback) === 2) {
            list($object_or_class, $method) = $callback;
            
            // Caso 1: [objeto, método]
            if (is_object($object_or_class) && method_exists($object_or_class, $method)) {
                return true;
            }
            
            // Caso 2: [clase, método estático]
            if (is_string($object_or_class) && class_exists($object_or_class) && method_exists($object_or_class, $method)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Verifica si WooCommerce está activo
     * 
     * @return bool True si WooCommerce está activo, false en caso contrario
     */
    public static function is_woocommerce_active() {
        // Si la función está definida en el archivo principal, usarla
        if (function_exists('\MiIntegracionApi\check_woocommerce_active')) {
            return \MiIntegracionApi\check_woocommerce_active();
        }
        
        // Verificación interna
        $active_plugins = (array) get_option('active_plugins', []);
        $active = in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
        
        // Verificar si estamos en multisite
        if (!$active && is_multisite()) {
            $active_network_plugins = (array) get_site_option('active_sitewide_plugins', []);
            $active = in_array('woocommerce/woocommerce.php', $active_network_plugins) || isset($active_network_plugins['woocommerce/woocommerce.php']);
        }
        
        return $active;
    }

    /**
     * Obtener los errores registrados
     * 
     * @return array Los errores registrados
     */
    public static function get_errors() {
        return self::$errors;
    }

    /**
     * Limpiar los errores registrados
     */
    public static function clear_errors() {
        self::$errors = [];
    }
}
