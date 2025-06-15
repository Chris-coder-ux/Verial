<?php
/**
 * Inicializador de Hooks para el plugin
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
 * Clase para inicializar y gestionar todos los hooks del plugin
 */
class HooksInit {
    /**
     * Inicializa todos los hooks principales del plugin
     * 
     * @return void
     */
    public static function init() {
        // Solo inicializar si HooksManager existe
        if (!class_exists('\MiIntegracionApi\Hooks\HooksManager')) {
            return;
        }
        
        // Cargar textdomain
        HooksManager::add_action(
            'init',
            '\MiIntegracionApi\load_plugin_textdomain_on_init',
            HookPriorities::get('INIT', 'DEFAULT')
        );
        
        // Mostrar errores de activación
        HooksManager::add_action(
            'admin_notices',
            '\MiIntegracionApi\display_activation_errors',
            HookPriorities::get('ADMIN', 'ADMIN_NOTICES')
        );
        
        // Inicializar el plugin en 'plugins_loaded'
        if (!has_action('plugins_loaded', '\MiIntegracionApi\init_plugin')) {
            HooksManager::add_action(
                'plugins_loaded',
                '\MiIntegracionApi\init_plugin',
                HookPriorities::get('INIT', 'EARLY')
            );
        }
        
        // Inicializar sistema de assets
        self::init_assets_hooks();
    }
    
    /**
     * Inicializa los hooks relacionados con assets
     * 
     * @return void
     */
    private static function init_assets_hooks() {
        HooksManager::add_action('plugins_loaded', function() {
            if (class_exists('\MiIntegracionApi\Assets')) {
                $assets = new \MiIntegracionApi\Assets('mi-integracion-api', MiIntegracionApi_VERSION);
                
                // Admin scripts y estilos
                HooksManager::add_action(
                    'admin_enqueue_scripts',
                    [$assets, 'enqueue_admin_styles'],
                    HookPriorities::get('ADMIN', 'ENQUEUE_SCRIPTS')
                );
                
                HooksManager::add_action(
                    'admin_enqueue_scripts',
                    [$assets, 'enqueue_admin_scripts'],
                    HookPriorities::get('ADMIN', 'ENQUEUE_SCRIPTS')
                );
                
                // Frontend scripts y estilos
                HooksManager::add_action(
                    'wp_enqueue_scripts',
                    [$assets, 'enqueue_public_styles']
                );
                
                HooksManager::add_action(
                    'wp_enqueue_scripts',
                    [$assets, 'enqueue_public_scripts']
                );
            }
        });
    }
}
