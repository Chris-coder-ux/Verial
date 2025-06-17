<?php
namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Core\Config_Manager;

/**
 * Gestiona el registro de las configuraciones y opciones del formulario.
 */
class SettingsRegistration {

    /**
     * Inicializa el registro de opciones.
     */
    public static function init() {
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_init', [self::class, 'handle_api_key_save']);
    }

    /**
     * Registra las configuraciones necesarias para el formulario.
     */
    public static function register_settings() {
        // Usar Config_Manager para registrar las configuraciones
        $config_manager = Config_Manager::get_instance();
        $config_manager->register_settings();
        
        // Registrar sección de ajustes
        add_settings_section(
            'mi_integracion_api_general_settings',
            __('Configuración General', 'mi-integracion-api'),
            [self::class, 'render_general_settings_section'],
            'mi-integracion-api'
        );
    }
    
    /**
     * Renderiza la descripción de la sección de configuración general.
     */
    public static function render_general_settings_section() {
        echo '<p>' . __('Configure los parámetros necesarios para conectar con Verial ERP.', 'mi-integracion-api') . '</p>';
    }

    /**
     * Maneja el guardado de la API key de Verial.
     */
    public static function handle_api_key_save() {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['api_key'])) {
            $api_key = sanitize_text_field($_POST['api_key']);
            
            // Usar Config_Manager para guardar la API key
            $config_manager = Config_Manager::get_instance();
            $config_manager->update('mia_clave_api', $api_key);
            
            // Registrar la acción en el log
            if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
                \MiIntegracionApi\Helpers\Logger::info('API Key actualizada', [
                    'accion' => 'guardar_api_key',
                    'user_id' => get_current_user_id()
                ]);
            }
        }
    }
}
