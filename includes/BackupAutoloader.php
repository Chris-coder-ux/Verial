<?php
/**
 * Autoloader de respaldo para garantizar que las clases críticas se carguen
 * incluso si falla el autoloader de Composer
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Clase BackupAutoloader
 * 
 * Proporciona una capa adicional de seguridad para cargar clases críticas
 * en caso de que el autoloader de Composer falle por cualquier razón.
 */
class BackupAutoloader {
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
     * Mapeo de clases críticas que se deben poder cargar siempre
     * 
     * @var array
     */
    private $critical_classes = [
        'MiIntegracionApi\\Core\\MiIntegracionApi' => 'includes/Core/MiIntegracionApi.php',
        'MiIntegracionApi\\Core\\ApiConnector' => 'includes/Core/ApiConnector.php',
        'MiIntegracionApi\\Helpers\\Logger' => 'includes/Helpers/Logger.php',
        'MiIntegracionApi\\Admin\\AdminMenu' => 'includes/Admin/AdminMenu.php',
        'MiIntegracionApi\\Admin\\SettingsRegistration' => 'includes/Admin/SettingsRegistration.php',
        'MiIntegracionApi\\Admin\\Assets' => 'includes/Admin/Assets.php'
    ];

    /**
     * Registro de errores de autoload
     * 
     * @var array
     */
    private $load_errors = [];

    /**
     * Constructor privado (patrón singleton)
     */
    private function __construct() {
        // Establecer directorio base
        $this->base_dir = MiIntegracionApi_PLUGIN_DIR;
    }

    /**
     * Inicializar el autoloader
     * 
     * @return self
     */
    public static function init() {
        if (self::$instance === null) {
            self::$instance = new self();
            
            // Registrar la función de autoload con prioridad baja (para ejecutarse después de Composer)
            spl_autoload_register([self::$instance, 'autoload'], true, false);
            
            // Log de registro exitoso
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Mi Integración API: Autoloader de respaldo registrado correctamente');
            }
        }
        
        return self::$instance;
    }

    /**
     * Función de autoload para clases críticas
     * 
     * @param string $class_name Nombre de la clase con namespace
     * @return bool True si la clase fue cargada, false en caso contrario
     */
    public function autoload($class_name) {
        // Verificar si la clase solicitada está en nuestra lista de clases críticas
        if (isset($this->critical_classes[$class_name])) {
            $file_path = $this->base_dir . $this->critical_classes[$class_name];
            
            if (file_exists($file_path)) {
                require_once $file_path;
                return true;
            } else {
                // Registrar el error
                $this->load_errors[] = sprintf(
                    'No se pudo cargar la clase crítica %s desde %s (archivo no encontrado)',
                    $class_name,
                    $file_path
                );
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('Mi Integración API - Error crítico: ' . end($this->load_errors));
                }
                return false;
            }
        }
        
        // Si la clase tiene nuestro namespace pero no está en la lista crítica,
        // intentaremos deducir su ubicación siguiendo la convención PSR-4
        if (strpos($class_name, 'MiIntegracionApi\\') === 0) {
            // Eliminar el namespace base
            $relative_class = substr($class_name, strlen('MiIntegracionApi\\'));
            
            // Convertir namespace a ruta de archivo
            $file = $this->base_dir . 'includes/' . str_replace('\\', '/', $relative_class) . '.php';
            
            // Intentar cargar si el archivo existe
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
        }
        
        // No pudimos cargar esta clase
        return false;
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
