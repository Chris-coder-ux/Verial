<?php
namespace MiIntegracionApi\Traits;

use MiIntegracionApi\Helpers\Logger;

trait ErrorHandlerTrait {
    // Puedes implementar aquí métodos de manejo de errores personalizados si lo necesitas

    /**
     * Registra un error en el log del plugin.
     */
    protected function log_error($message, $context = []) {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            Logger::error($message, $context, 'auditoria');
        }
    }

    /**
     * Lanza una excepción personalizada.
     */
    protected function throw_error($message, $code = 0) {
        throw new \Exception($message, $code);
    }

    /**
     * Muestra un aviso de error en el admin de WordPress.
     */
    protected function admin_notice($message) {
        if (is_admin()) {
            add_action('admin_notices', function() use ($message) {
                echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
            });
        }
    }

    /**
     * Devuelve el último error registrado por PHP.
     */
    protected function get_last_error() {
        return error_get_last();
    }
}
