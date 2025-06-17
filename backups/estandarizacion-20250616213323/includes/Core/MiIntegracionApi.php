<?php
namespace MiIntegracionApi\Core;

class MiIntegracionApi {
    private $connector;
    private $logger;

    public function init(): void {
        try {
            // Inicializar logger si está disponible
            if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
                $this->logger = new \MiIntegracionApi\Helpers\Logger('core');
            }

            // Obtener las opciones de configuración
            $options = get_option('mi_integracion_api_ajustes', array());
            
            // Inicializar el conector API con la configuración
            $config = [
                'api_url' => isset($options['mia_url_base']) ? $options['mia_url_base'] : '',
                'sesionwcf' => isset($options['mia_numero_sesion']) ? $options['mia_numero_sesion'] : '18'
            ];

            // Verificar que la clase ApiConnector exista antes de instanciarla
            if (class_exists('\\MiIntegracionApi\\Core\\ApiConnector')) {
                $this->connector = new ApiConnector($config);
            } else {
                $this->log_error('Clase ApiConnector no encontrada. Algunas funcionalidades no estarán disponibles.');
            }

            // Inicializar el manejador REST centralizado para registrar todos los endpoints REST (incluyendo sync)
            if (class_exists('\\MiIntegracionApi\\Core\\REST_API_Handler')) {
                \MiIntegracionApi\Core\REST_API_Handler::init();
            } else {
                $this->log_error('Clase REST_API_Handler no encontrada. Los endpoints REST no estarán disponibles.');
            }
            
            // Registrar endpoints legacy (compatibilidad)
            add_action('rest_api_init', [$this, 'register_endpoints']);

            // Inicializar registro de configuraciones
            if (class_exists('\\MiIntegracionApi\\Admin\\SettingsRegistration')) {
                \MiIntegracionApi\Admin\SettingsRegistration::init();
            } else {
                $this->log_error('Clase SettingsRegistration no encontrada. La configuración no estará disponible.');
            }

            // Inicializar menú de administración
            if (class_exists('\\MiIntegracionApi\\Admin\\AdminMenu')) {
                $admin_menu = new \MiIntegracionApi\Admin\AdminMenu();
                $admin_menu->init(); // Inicializar el menú de administración
            } else {
                $this->log_error('Clase AdminMenu no encontrada. El menú de administración no estará disponible.');
            }

            // Inicializar assets
            if (class_exists('\\MiIntegracionApi\\Admin\\Assets')) {
                $assets = new \MiIntegracionApi\Admin\Assets();
                $assets->init();
            } else {
                $this->log_error('Clase Assets no encontrada. Los recursos CSS/JS no estarán disponibles.');
            }

            // Inicializar la página de configuración
            if (class_exists('\\MiIntegracionApi\\Admin\\SettingsPage')) {
                \MiIntegracionApi\Admin\SettingsPage::init();
            } else {
                $this->log_error('Clase SettingsPage no encontrada. Las opciones de configuración no estarán disponibles.');
            }
        } catch (\Throwable $e) {
            $this->log_error('Error en init: ' . $e->getMessage());
        }
    }

    public function register_endpoints(): void {
        if (!isset($this->connector) || !$this->connector) {
            $this->log_error('No se pueden registrar endpoints: Conector API no disponible');
            return;
        }

        try {
            // Registrar endpoints verificando primero la existencia de las clases
            if (class_exists('\\MiIntegracionApi\\Endpoints\\PaisesWS')) {
                $paises = new \MiIntegracionApi\Endpoints\PaisesWS($this->connector);
                $paises->register_route();
            }

            if (class_exists('\\MiIntegracionApi\\Endpoints\\ProvinciasWS')) {
                $provincias = new \MiIntegracionApi\Endpoints\ProvinciasWS($this->connector);
                $provincias->register_route();
            }

            if (class_exists('\\MiIntegracionApi\\Endpoints\\ClientesWS')) {
                $clientes = new \MiIntegracionApi\Endpoints\ClientesWS($this->connector);
                $clientes->register_route();
            }
        } catch (\Throwable $e) {
            $this->log_error('Error al registrar endpoints: ' . $e->getMessage());
        }
    }

    /**
     * Registra un error de forma segura
     *
     * @param string $message El mensaje de error
     * @return void
     */
    private function log_error($message): void {
        // Usar el logger si está disponible
        if (isset($this->logger) && $this->logger) {
            $this->logger->error($message);
            return;
        }
        
        // Fallback a error_log si el logger no está disponible
        error_log('Mi Integración API: ' . $message);
        
        // Si estamos en el admin, mostrar notificación
        if (is_admin() && function_exists('add_action')) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . esc_html__('Error en Mi Integración API:', 'mi-integracion-api') . '</strong> ' . esc_html($message);
                echo '</p></div>';
            });
        }
    }
}

// No se detecta uso de Logger::log, solo $this->logger->error y error_log estándar.
