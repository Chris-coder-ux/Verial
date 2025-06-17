<?php
/**
 * Configuración centralizada para prioridades de hooks
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
 * Clase para gestionar las prioridades de los hooks
 * 
 * Esta clase centraliza todas las prioridades de los hooks para evitar
 * conflictos y facilitar el mantenimiento.
 */
class HookPriorities {
    
    /**
     * Prioridades para hooks de inicialización
     */
    const INIT = [
        'DEFAULT' => 10,
        'EARLY' => 5,
        'LATE' => 15,
        'VERY_LATE' => 999,
    ];
    
    /**
     * Prioridades para hooks de WooCommerce
     */
    const WOOCOMMERCE = [
        // Pedidos
        'ORDER_STATUS_CHANGED' => 20, // Mayor que la prioridad predeterminada para ejecutarse después
        'CHECKOUT_PROCESSED' => 10,   // Prioridad estándar
        'API_CREATE_ORDER' => 10,     // Prioridad estándar
        'BEFORE_ORDER_ITEMMETA' => 5, // Antes que otros plugins
        'ORDER_ITEM_META' => 10,      // Prioridad estándar
        
        // Productos
        'PRODUCT_SAVE' => 20,         // Después de que otros plugins hayan modificado el producto
        'PRODUCT_UPDATE' => 15,       // Después de actualizaciones estándar pero antes de operaciones finales
        'VARIATION_SAVE' => 20,       // Similar a PRODUCT_SAVE
        
        // Carrito y checkout
        'CART_UPDATED' => 10,         // Prioridad estándar
        'BEFORE_CHECKOUT' => 5,       // Ejecutar temprano en el proceso de checkout
        'AFTER_CHECKOUT' => 25,       // Ejecutar tarde en el proceso de checkout
    ];
    
    /**
     * Prioridades para hooks de administración
     */
    const ADMIN = [
        'ENQUEUE_SCRIPTS' => 20,      // Después de que otros scripts se hayan registrado
        'ADMIN_INIT' => 10,           // Prioridad estándar
        'ADMIN_MENU' => 10,           // Prioridad estándar
        'ADMIN_NOTICES' => 10,        // Prioridad estándar
        'PLUGIN_ACTION_LINKS' => 10,  // Prioridad estándar
    ];
    
    /**
     * Prioridades para hooks de sincronización
     */
    const SYNC = [
        'BEFORE_SYNC' => 5,           // Antes de iniciar la sincronización
        'AFTER_SYNC' => 15,           // Después de completar la sincronización
        'PROCESS_ITEM' => 10,         // Durante el procesamiento de cada ítem
    ];
    
    /**
     * Prioridades para hooks de REST API
     */
    const REST_API = [
        'REGISTER_ROUTES' => 10,      // Prioridad estándar
        'AUTHENTICATE' => 90,         // Alta prioridad para ejecutarse después de autenticación estándar
        'PRE_SERVE_REQUEST' => 5,     // Baja prioridad para ejecutarse antes
    ];
    
    /**
     * Obtiene la prioridad recomendada para un hook específico
     * 
     * @param string $hook_type El tipo de hook (constante de clase)
     * @param string $hook_name El nombre específico del hook dentro del tipo
     * @return int La prioridad recomendada o 10 si no se encuentra
     */
    public static function get($hook_type, $hook_name) {
        $const = "self::{$hook_type}";
        
        if (defined($const) && isset(constant($const)[$hook_name])) {
            return constant($const)[$hook_name];
        }
        
        return 10; // Prioridad predeterminada de WordPress
    }
}
