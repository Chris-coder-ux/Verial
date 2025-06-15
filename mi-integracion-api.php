<?php
/**
 * Plugin Name: Mi Integración API
 * Plugin URI: https://www.verialerp.com
 * Description: Plugin para la integración con Verial ERP
 * Version: 1.3.1
 * Author: Verial ERP
 * Author URI: https://www.verialerp.com
 * Text Domain: mi-integracion-api
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

namespace MiIntegracionApi;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Verifica si WooCommerce está activo y muestra un mensaje si no lo está
 * 
 * @return bool True si WooCommerce está activo, False en caso contrario
 */
function check_woocommerce_active(): bool {
    $active_plugins = (array) get_option('active_plugins', array());
    
    // Comprobar si WooCommerce está en los plugins activos
    $woocommerce_active = in_array('woocommerce/woocommerce.php', $active_plugins) || array_key_exists('woocommerce/woocommerce.php', $active_plugins);
    
    // Si estamos en una instalación multisite, también verificamos los plugins de la red
    if (!$woocommerce_active && is_multisite()) {
        $active_network_plugins = (array) get_site_option('active_sitewide_plugins', array());
        $woocommerce_active = in_array('woocommerce/woocommerce.php', $active_network_plugins) || isset($active_network_plugins['woocommerce/woocommerce.php']);
    }
    
    // Si WooCommerce no está activo, mostrar mensaje de error
    if (!$woocommerce_active) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>' . esc_html__('Mi Integración API requiere WooCommerce', 'mi-integracion-api') . '</strong><br>';
            echo esc_html__('Por favor, instale y active WooCommerce antes de usar este plugin.', 'mi-integracion-api');
            echo '</p></div>';
        });
        
        // Si algún log está disponible, registrar el error
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $logger = new \MiIntegracionApi\Helpers\Logger('dependency_check');
            $logger->error('WooCommerce no está activo. El plugin no puede inicializarse.');
        } else {
            error_log('Mi Integración API: WooCommerce no está activo. El plugin no puede inicializarse.');
        }
    }
    
    return $woocommerce_active;
}

// Definir constantes del plugin
if (!defined('MiIntegracionApi_VERSION')) {
    define('MiIntegracionApi_VERSION', '1.3.1');
    define('MiIntegracionApi_PLUGIN_DIR', plugin_dir_path(__FILE__));
    define('MiIntegracionApi_PLUGIN_URL', plugin_dir_url(__FILE__));
    define('MiIntegracionApi_PATH', plugin_dir_path(__FILE__)); // Añadida esta constante para compatibilidad
    define('MiIntegracionApi_OPTION_PREFIX', 'mi_integracion_api_');
    define('MiIntegracionApi_TEXT_DOMAIN', 'mi-integracion-api');
    define('MiIntegracionApi_PLUGIN_BASENAME', plugin_basename(__FILE__));
    define('MiIntegracionApi_NONCE_PREFIX', 'mi_integracion_api_nonce_'); // Constante para prefijo de nonces
    define('MiIntegracionApi_PLUGIN_FILE', __FILE__); // Constante para el archivo principal del plugin
}

// Cargar el autoloader de Composer
$composer_loaded = false;
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
    $composer_loaded = class_exists('Composer\\Autoload\\ClassLoader');
}

// Si Composer no está disponible, cargar nuestro autoloader de respaldo para clases críticas
if (!$composer_loaded) {
    // Incluir el autoloader de respaldo primero
    require_once __DIR__ . '/includes/BackupAutoloader.php';
    
    // Inicializar el autoloader de respaldo
    \MiIntegracionApi\BackupAutoloader::init();
    
    // Notificar que estamos usando el modo de respaldo (podría reducirse en producción)
    add_action('admin_notices', function() {
        echo '<div class="notice notice-warning"><p>';
        echo '<strong>Mi Integración API:</strong> ';
        echo esc_html__('El plugin está funcionando en modo limitado porque Composer no está disponible. Algunas funcionalidades podrían estar desactivadas. Por favor, contacte al soporte técnico.', 'mi-integracion-api');
        echo '</p></div>';
    });
} else {
    // Con Composer funcionando, también cargar nuestros autoloaders de respaldo como precaución adicional
    
    // 1. Cargar el autoloader personalizado mejorado
    if (file_exists(__DIR__ . '/includes/Autoloader.php')) {
        require_once __DIR__ . '/includes/Autoloader.php';
        \MiIntegracionApi\Autoloader::init();
    }

    // 2. Cargar el autoloader de respaldo para clases críticas
    if (file_exists(__DIR__ . '/includes/BackupAutoloader.php')) {
        require_once __DIR__ . '/includes/BackupAutoloader.php';
        \MiIntegracionApi\BackupAutoloader::init();
    }
}

// Cargar funciones de compatibilidad
if (file_exists(__DIR__ . '/includes/compatibility.php')) {
    require_once __DIR__ . '/includes/compatibility.php';
}

// Cargar registro manual de comandos WP-CLI
if (file_exists(__DIR__ . '/includes/register-commands-manual.php')) {
    require_once __DIR__ . '/includes/register-commands-manual.php';
}

if (!function_exists(__NAMESPACE__ . '\\init_plugin')) {
    // Inicializar el plugin
    function init_plugin() {
        try {
            // Verificar que WooCommerce esté activo antes de continuar
            if (!check_woocommerce_active()) {
                return;
            }
            
            // Inicializar el logger para capturar posibles errores tempranos
            if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                \MiIntegracionApi\Helpers\Logger::init();
            }
            
            // Verificar que exista la clase principal antes de instanciarla
            if (!class_exists('\\MiIntegracionApi\\Core\\MiIntegracionApi')) {
                throw new \Exception(__('Clase principal del plugin no encontrada. El plugin no puede inicializarse.', 'mi-integracion-api'));
            }
            
            // Instanciar la clase principal del plugin y llamar a init()
            $plugin = new \MiIntegracionApi\Core\MiIntegracionApi();
            $plugin->init(); // Llamar a init() solo una vez
        } catch (\Throwable $e) {
            // Registrar el error de forma segura
            if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
                $logger = new \MiIntegracionApi\Helpers\Logger('plugin_init');
                $logger->error('Error al inicializar Mi Integración API: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            } else {
                // Fallback solo si el logger no está disponible
            error_log('Error al inicializar Mi Integración API: ' . $e->getMessage());
            }
            
            // Si estamos en el admin, mostrar notificación
            if (is_admin()) {
                add_action('admin_notices', function() use ($e) {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>Error en Mi Integración API:</strong> ' . esc_html($e->getMessage());
                    echo '</p></div>';
                });
            }
        }
    }
}

// Cargar textdomain en el momento correcto (en el hook init en lugar de plugins_loaded)
function load_plugin_textdomain_on_init() {
    load_plugin_textdomain('mi-integracion-api', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
// El registro del hook se hace a través de HooksInit

/**
 * Función global para obtener el servicio de criptografía.
 * 
 * Esta función proporciona una manera fácil y consistente de acceder
 * al servicio centralizado de criptografía desde cualquier parte del plugin.
 *
 * @since 1.0.0
 * @return \MiIntegracionApi\Core\CryptoService|null Instancia del servicio o null si no está disponible
 */
function mi_integracion_api_get_crypto() {
    return \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
}

// Función de activación para inicializar el plugin
function plugin_activation() {
    // Usar el instalador para configurar todo correctamente
    if (class_exists('MiIntegracionApi\\Core\\Installer')) {
        \MiIntegracionApi\Core\Installer::activate();
    } else {
        // Fallback para compatibilidad con versiones antiguas
        global $wpdb;
        $table_name = $wpdb->prefix . 'verial_product_mapping';
        $activation_errors = [];
        
        // 1. Verificar y crear tablas necesarias
        try {
            // Verificar si la tabla ya existe
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
            $charset_collate = $wpdb->get_charset_collate();
            
            // Crear la tabla de mapeo
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                wc_id bigint(20) NOT NULL,
                verial_id bigint(20) NOT NULL,
                sku varchar(100) DEFAULT '',
                created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                PRIMARY KEY (id),
                KEY wc_id (wc_id),
                KEY verial_id (verial_id),
                KEY sku (sku)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            }
            
            // Verificar si la tabla se creó correctamente
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
                error_log('Tabla de mapeo Verial-WooCommerce creada correctamente.');
            } else {
                $activation_errors[] = 'No se pudo crear la tabla de mapeo Verial-WooCommerce.';
            }
        } catch (\Throwable $e) {
            $activation_errors[] = 'Error al crear la tabla de mapeo: ' . $e->getMessage();
            error_log('Error al crear la tabla de mapeo: ' . $e->getMessage());
        }
    }
    
    // 2. Verificar archivos críticos
    $critical_files = [
        'Module_Loader.php' => __DIR__ . '/includes/Core/Module_Loader.php',
        'ApiConnector.php' => __DIR__ . '/includes/Core/ApiConnector.php',
        'WooCommerceHooks.php' => __DIR__ . '/includes/WooCommerce/WooCommerceHooks.php',
        'Custom_Fields.php' => __DIR__ . '/includes/WooCommerce/Custom_Fields.php'
    ];
    
    foreach ($critical_files as $name => $path) {
        if (!file_exists($path)) {
            $activation_errors[] = "Archivo crítico no encontrado: $name";
            error_log("Error de activación: Archivo crítico no encontrado: $path");
        }
    }
    
    // 3. Verificar dependencias
    if (!class_exists('WooCommerce') && function_exists('is_plugin_active')) {
        if (!is_plugin_active('woocommerce/woocommerce.php')) {
            $activation_errors[] = 'WooCommerce no está activo. Este plugin requiere WooCommerce para funcionar correctamente.';
        }
    }
    
    // Si hay errores, registrarlos y mostrarlos al activar
    if (!empty($activation_errors)) {
        update_option('mi_integracion_api_activation_errors', $activation_errors);
        error_log('Errores de activación en Mi Integración API: ' . implode(', ', $activation_errors));
    } else {
        delete_option('mi_integracion_api_activation_errors');
    }
}

// Registrar el gancho de activación
register_activation_hook(__FILE__, __NAMESPACE__ . '\\plugin_activation');

// Mostrar errores de activación si existen
function display_activation_errors() {
    $activation_errors = get_option('mi_integracion_api_activation_errors');
    if (!empty($activation_errors) && is_array($activation_errors)) {
        echo '<div class="notice notice-error is-dismissible">';
        echo '<p><strong>Mi Integración API - Errores de activación:</strong></p>';
        echo '<ul>';
        foreach ($activation_errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul>';
        echo '<p>Por favor, corrige estos errores para usar el plugin correctamente.</p>';
        echo '</div>';
    }
}
add_action('admin_notices', __NAMESPACE__ . '\\display_activation_errors');

// Inicializar el sistema seguro de hooks
if (file_exists(__DIR__ . '/includes/Hooks/HooksManager.php') && 
    file_exists(__DIR__ . '/includes/Hooks/HookPriorities.php') &&
    file_exists(__DIR__ . '/includes/Hooks/HooksInit.php')) {
    
    require_once __DIR__ . '/includes/Hooks/HooksManager.php';
    require_once __DIR__ . '/includes/Hooks/HookPriorities.php';
    require_once __DIR__ . '/includes/Hooks/HooksInit.php';
    
    // Inicializar todos los hooks del plugin de forma segura
    \MiIntegracionApi\Hooks\HooksInit::init();
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('Mi Integración API: Sistema de hooks seguros inicializado');
    }
} else {
    // Fallback al sistema antiguo si los archivos no existen
    add_action('init', __NAMESPACE__ . '\\load_plugin_textdomain_on_init');
    
    add_action('admin_notices', __NAMESPACE__ . '\\display_activation_errors');
    
    // Inicializar el sistema de assets del plugin (forma antigua)
    add_action('plugins_loaded', function() {
        if (class_exists('MiIntegracionApi\\Assets')) {
            $assets = new \MiIntegracionApi\Assets('mi-integracion-api', MiIntegracionApi_VERSION);
            add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin_styles'], 20);
            add_action('admin_enqueue_scripts', [$assets, 'enqueue_admin_scripts'], 20);
            add_action('wp_enqueue_scripts', [$assets, 'enqueue_public_styles']);
            add_action('wp_enqueue_scripts', [$assets, 'enqueue_public_scripts']);
        }
    });
}

// Registrar handlers AJAX del admin (sin duplicar si ya existe sistema de hooks)
add_action('init', function() {
    if (class_exists('MiIntegracionApi\\Admin\\AjaxSync')) {
        \MiIntegracionApi\Admin\AjaxSync::register_ajax_handlers();
    }
});

// Inicializar manejadores de formularios de configuración
add_action('init', function() {
    if (class_exists('\\MiIntegracionApi\\Admin\\SettingsPage')) {
        \MiIntegracionApi\Admin\SettingsPage::init();
    }
}, 5);

// No se detecta uso de Logger::log, solo error_log estándar y $logger->error.
