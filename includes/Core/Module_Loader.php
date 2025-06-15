<?php
/**
 * Módulo de carga dinámica para Mi Integración API
 *
 * Este archivo implementa un cargador dinámico para los diferentes módulos del plugin
 *
 * @package    MiIntegracionApi
 * @subpackage Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase ModuleLoader
 * 
 * Maneja la carga dinámica de módulos del plugin
 */
class Module_Loader {
    
    /**
     * Obtiene la lista de módulos disponibles en el plugin
     *
     * @return array Lista de módulos disponibles con sus estados
     */
    public static function get_available_modules(): array {
        $modules = [
            'core' => [
                'ApiConnector' => true,
                'Auth_Manager' => true,
                'Cache_Manager' => true,
            ],
            'feature' => [
                'SyncManager' => true,
                'REST_API_Handler' => true,
            ]
        ];
        
        // Filtro para permitir que otros componentes añadan módulos
        if (function_exists('apply_filters')) {
            $modules = apply_filters('mi_integracion_api_available_modules', $modules);
        }
        
        return $modules;
    }
    
    /**
     * Carga todos los módulos disponibles
     *
     * @return void
     */
    public static function load_all() {
        self::load_core_modules();
        self::load_feature_modules();
    }
    
    /**
     * Carga los módulos centrales
     *
     * @return void
     */
    private static function load_core_modules() {
        $core_modules = [
            'ApiConnector',
            'Auth_Manager',
            'Cache_Manager',
        ];
        
        foreach ($core_modules as $module) {
            $module_path = MiIntegracionApi_PLUGIN_DIR . 'includes/Core/' . $module . '.php';
            if (file_exists($module_path)) {
                require_once $module_path;
            }
        }
    }
    
    /**
     * Carga módulos de características específicas
     *
     * @return void
     */
    private static function load_feature_modules() {
        $feature_modules = [
            'Sync/SyncManager',
            'Rest/REST_API_Handler',
        ];
        
        foreach ($feature_modules as $module) {
            $module_path = MiIntegracionApi_PLUGIN_DIR . 'includes/' . $module . '.php';
            if (file_exists($module_path)) {
                require_once $module_path;
            }
        }
    }
}