<?php
/**
 * Funciones de ayuda para REST API
 *
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RestHelpers {
    /**
     * Devuelve el código de estado HTTP apropiado para errores de autorización REST.
     * Si el usuario está conectado pero no tiene permisos, devuelve 403 Forbidden.
     * Si el usuario no está conectado, devuelve 401 Unauthorized.
     *
     * @return int Código de estado HTTP
     */
    public static function rest_authorization_required_code(): int {
        return is_user_logged_in() ? 403 : 401;
    }
}
