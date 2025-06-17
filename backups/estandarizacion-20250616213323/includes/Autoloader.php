<?php
/**
 * Sistema mejorado de autoloading que actúa como respaldo al autoloader de Composer
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi;

// Evitar acceso directo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase Autoloader - Ahora funciona como respaldo si Composer falla
 * 
 * Esta clase mantiene compatibilidad con código antiguo que pueda estar
 * utilizándola, pero ahora también proporciona una funcionalidad real
 * de autoloading como respaldo si el autoloader de Composer falla.
 */
class Autoloader {
    /**
     * Instancia única del autoloader
     *
     * @var null|self
     */
    private static $instance = null;

    /**
     * Directorio base del plugin
     * 
     * @var string
     */
    private $base_dir;

    /**
     * Mapa de clases críticas del plugin
     * 
     * @var array
     */
    private $class_map = [];

    /**
     * Registro de errores de autoload
     * 
     * @var array
     */
    private $load_errors = [];

    /**
     * Indicador de si Composer autoloader está funcionando
     * 
     * @var bool
     */
    private $composer_autoloader_active = false;
    
    /**
     * Constructor
     */
    private function __construct() {
        // Establecer directorio base
        $this->base_dir = defined('MiIntegracionApi_PLUGIN_DIR') 
            ? MiIntegracionApi_PLUGIN_DIR 
            : dirname(__DIR__) . '/';
        
        // Verificar si el autoloader de Composer está funcionando
        $this->composer_autoloader_active = class_exists('Composer\\Autoload\\ClassLoader');
        
        // Inicializar el mapa de clases críticas
        $this->init_class_map();
    }
    
    /**
     * Inicializar el mapa de clases críticas
     */
    private function init_class_map() {
        // Mapa de clases críticas que siempre deben poder cargarse
        $this->class_map = [
            // Core
            'MiIntegracionApi\\Core\\MiIntegracionApi' => 'includes/Core/MiIntegracionApi.php',
            'MiIntegracionApi\\Core\\ApiConnector' => 'includes/Core/ApiConnector.php',
            'MiIntegracionApi\\Core\\REST_API_Handler' => 'includes/Core/REST_API_Handler.php',
            
            // Admin
            'MiIntegracionApi\\Admin\\AdminMenu' => 'includes/Admin/AdminMenu.php',
            'MiIntegracionApi\\Admin\\SettingsPage' => 'includes/Admin/SettingsPage.php',
            'MiIntegracionApi\\Admin\\Assets' => 'includes/Admin/Assets.php',
            
            // Helpers
            'MiIntegracionApi\\Helpers\\Logger' => 'includes/Helpers/Logger.php',
            'MiIntegracionApi\\ErrorHandler' => 'includes/ErrorHandler.php',
            
            // WooCommerce
            'MiIntegracionApi\\WooCommerce\\WooCommerceHooks' => 'includes/WooCommerce/WooCommerceHooks.php',
            
            // Sync
            'MiIntegracionApi\\Sync\\SyncManager' => 'includes/Sync/SyncManager.php',
        ];
    }

    /**
     * Método init - Registra el autoloader
     * 
     * @return self
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
            
            // Solo registramos nuestra función de autoload si no estamos duplicando trabajo
            if (!self::$instance->composer_autoloader_active || defined('WP_DEBUG') && WP_DEBUG) {
                // Registrar la función de autoload con prioridad baja para ejecutarse después de Composer
                spl_autoload_register([self::$instance, 'autoload'], true, false);
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Mi Integración API: Autoloader de respaldo registrado correctamente');
                }
            }
        }
        
        return self::$instance;
    }

    /**
     * Función de autoload mejorada
     * 
     * @param string $class_name Nombre de la clase con namespace
     * @return bool True si la clase fue cargada, false en caso contrario
     */
    public function autoload($class_name) {
        // 1. Verificar si la clase es parte de nuestro plugin (namespace MiIntegracionApi)
        if (strpos($class_name, 'MiIntegracionApi\\') !== 0) {
            return false;
        }
        
        // 2. Verificar si está en nuestro mapa de clases críticas
        if (isset($this->class_map[$class_name])) {
            $file_path = $this->base_dir . $this->class_map[$class_name];
            
            if (file_exists($file_path)) {
                require_once $file_path;
                return true;
            } else {
                $this->register_error(sprintf(
                    'No se pudo cargar la clase crítica %s desde %s (archivo no encontrado)',
                    $class_name,
                    $file_path
                ));
                return false;
            }
        }
        
        // 3. Intentar inferir la ubicación usando PSR-4
        $relative_class = substr($class_name, strlen('MiIntegracionApi\\'));
        $file = $this->base_dir . 'includes/' . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require_once $file;
            return true;
        }
        
        // 4. Buscar en subdirectorios específicos para casos especiales
        $subdirectories = ['admin/', 'api/', 'includes/'];
        
        foreach ($subdirectories as $subdir) {
            $file = $this->base_dir . $subdir . str_replace('\\', '/', $relative_class) . '.php';
            
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        
        // No pudimos cargar esta clase
        return false;
    }
    
    /**
     * Registra un error en el log
     * 
     * @param string $message Mensaje de error
     * @return void
     */
    private function register_error($message) {
        $this->load_errors[] = $message;
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Mi Integración API - Error de autoload: ' . $message);
        }
    }
    
    /**
     * Obtener los errores de carga registrados
     * 
     * @return array Errores de carga
     */
    public function get_load_errors() {
        return $this->load_errors;
    }
}

// No se detecta uso de log con nivel inválido en este fragmento.
