<?php
namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Helpers\Logger;

class Assets {
    private $logger;

    public function __construct() {
        $this->logger = new Logger('assets');
    }

    public function init(): void {
        // Registrar scripts y estilos
        add_action('admin_enqueue_scripts', [$this, 'register_assets'], 20);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets'], 20);
        
        $this->logger->info('Assets hooks registered');
    }

    public function register_assets(): void {
        try {
            // Verificar si estamos en una página del plugin
            if (!$this->is_plugin_admin_page()) {
                $this->logger->info('Not a plugin admin page, skipping admin assets');
                return;
            }

            $this->logger->info('Registering admin assets');

            // Registrar y encolar estilos
            $admin_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/admin.css';
            $this->logger->info('Registering admin CSS: ' . $admin_css_url);
            
            wp_register_style(
                'mi-integracion-api-admin',
                $admin_css_url,
                [],
                MiIntegracionApi_VERSION
            );
            wp_enqueue_style('mi-integracion-api-admin');

            // Registrar script de utilidades (necesario para admin-main.js y logs-viewer-main.js)
            $utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/utils.js';
            $this->logger->info('Registering utils JS: ' . $utils_js_url);
            wp_register_script(
                'mi-integracion-api-utils',
                $utils_js_url,
                ['jquery'],
                MiIntegracionApi_VERSION,
                true
            );

            // Registrar y encolar script principal del admin
            $admin_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/admin-main.js';
            $this->logger->info('Registering admin-main JS: ' . $admin_main_js_url);
            
            wp_register_script(
                'mi-integracion-api-admin-main',
                $admin_main_js_url,
                ['jquery', 'mi-integracion-api-utils'],
                MiIntegracionApi_VERSION,
                true
            );
            wp_enqueue_script('mi-integracion-api-admin-main');

            // Localizar el script principal del admin
            wp_localize_script('mi-integracion-api-admin-main', 'miIntegracionApi', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mi_integracion_api_nonce'),
                'restUrl' => rest_url('mi-integracion-api/v1/'), // Necesario para logs-viewer
                'restNonce' => wp_create_nonce('wp_rest'), // Nonce específico para REST API
                'i18n' => [
                    'confirmDelete' => __('¿Estás seguro de que deseas eliminar este elemento?', 'mi-integracion-api'),
                    'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                    'success' => __('Operación realizada con éxito', 'mi-integracion-api'),
                    'confirmClearLogs' => __('¿Estás seguro de que deseas borrar todos los logs? Esta acción es irreversible.', 'mi-integracion-api'),
                    'genericError' => __('Ha ocurrido un error inesperado.', 'mi-integracion-api'),
                    'noLogsFound' => __('No se encontraron logs.', 'mi-integracion-api'),
                    'logId' => __('ID', 'mi-integracion-api'),
                    'logType' => __('Tipo', 'mi-integracion-api'),
                    'logDate' => __('Fecha', 'mi-integracion-api'),
                    'logMessage' => __('Mensaje', 'mi-integracion-api'),
                    'logContext' => __('Contexto', 'mi-integracion-api'),
                    'exportNotImplemented' => __('La función de exportar aún no está implementada.', 'mi-integracion-api'),
                    'clearLogsError' => __('Error al borrar los logs.', 'mi-integracion-api'),
                ]
            ]);

            // Opcional: registrar logs-viewer-main.js si es necesario en todas las páginas de admin
            // o se puede cargar condicionalmente en la página de logs. Por ahora, lo registraré.
            $logs_viewer_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/logs-viewer-main.js';
            $this->logger->info('Registering logs-viewer-main JS: ' . $logs_viewer_main_js_url);
            wp_register_script(
                'mi-integracion-api-logs-viewer-main',
                $logs_viewer_main_js_url,
                ['jquery', 'mi-integracion-api-utils'],
                MiIntegracionApi_VERSION,
                true
            );
            // No encolar aquí, se encolará solo en la página de logs si es necesario

            $this->logger->info('Admin assets registered successfully for page: ' . get_current_screen()->id);
        } catch (\Exception $e) {
            $this->logger->error('Error registering admin assets: ' . $e->getMessage());
        }
    }

    public function register_frontend_assets(): void {
        try {
            $this->logger->info('Registering frontend assets');

            // Registrar y encolar estilos frontend
            $frontend_css_url = MiIntegracionApi_PLUGIN_URL . 'assets/css/frontend.css';
            $this->logger->info('Registering frontend CSS: ' . $frontend_css_url);
            
            wp_register_style(
                'mi-integracion-api-frontend',
                $frontend_css_url,
                [],
                MiIntegracionApi_VERSION
            );
            wp_enqueue_style('mi-integracion-api-frontend');

            // Registrar script de utilidades (ya registrado en admin, pero asegurar para frontend si se carga solo)
            $utils_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/utils.js';
            if (!wp_script_is('mi-integracion-api-utils', 'registered')) {
                wp_register_script(
                    'mi-integracion-api-utils',
                    $utils_js_url,
                    ['jquery'],
                    MiIntegracionApi_VERSION,
                    true
                );
            }
            
            // Registrar y encolar script principal del frontend
            $frontend_main_js_url = MiIntegracionApi_PLUGIN_URL . 'assets/js/frontend-main.js';
            $this->logger->info('Registering frontend-main JS: ' . $frontend_main_js_url);
            
            wp_register_script(
                'mi-integracion-api-frontend-main',
                $frontend_main_js_url,
                ['jquery', 'mi-integracion-api-utils'],
                MiIntegracionApi_VERSION,
                true
            );
            wp_enqueue_script('mi-integracion-api-frontend-main');

            // Localizar el script frontend
            wp_localize_script('mi-integracion-api-frontend-main', 'miIntegracionApi', [
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('mi_integracion_api_nonce'),
                'i18n' => [
                    'error' => __('Ha ocurrido un error', 'mi-integracion-api'),
                    'success' => __('Operación realizada con éxito', 'mi-integracion-api')
                ]
            ]);

            $this->logger->info('Frontend assets registered successfully');
        } catch (\Exception $e) {
            $this->logger->error('Error registering frontend assets: ' . $e->getMessage());
        }
    }

    private function is_plugin_admin_page(): bool {
        try {
            $screen = get_current_screen();
            if (!$screen) {
                $this->logger->info('No screen object available');
                return false;
            }

            $this->logger->info('Checking if current page is plugin admin page. Screen ID: ' . $screen->id);

            // Verificar si estamos en una página del plugin
            if (strpos($screen->id, 'mi-integracion') !== false) {
                $this->logger->info('Current page is a plugin admin page');
                return true;
            }

            $this->logger->info('Current page is not a plugin admin page');
            return false;
        } catch (\Exception $e) {
            $this->logger->error('Error checking plugin admin page: ' . $e->getMessage());
            return false;
        }
    }
} 