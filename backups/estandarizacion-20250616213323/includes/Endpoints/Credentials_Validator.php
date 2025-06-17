<?php
/**
 * Validador de credenciales
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

class Credentials_Validator {
    /**
     * Valida las credenciales básicas
     *
     * @param array $credentials Credenciales a validar
     * @return array Array con errores de validación o array vacío si no hay errores
     */
    public static function validate_basic_credentials($credentials) {
        $errors = [];

        if (!isset($credentials['api_url']) || empty($credentials['api_url'])) {
            $errors['api_url'] = __('La URL de la API es obligatoria', 'mi-integracion-api');
        }

        if (!isset($credentials['session_id']) || empty($credentials['session_id'])) {
            $errors['session_id'] = __('El número de sesión es obligatorio', 'mi-integracion-api');
        }

        return $errors;
    }

    /**
     * Sanitiza las credenciales
     *
     * @param array $credentials Credenciales a sanitizar
     * @return array Credenciales sanitizadas
     */
    public static function sanitize_credentials($credentials) {
        $sanitized = [];

        if (isset($credentials['api_url'])) {
            $sanitized['api_url'] = sanitize_text_field($credentials['api_url']);
        }

        if (isset($credentials['session_id'])) {
            $sanitized['session_id'] = sanitize_text_field($credentials['session_id']);
        }

        if (isset($credentials['username'])) {
            $sanitized['username'] = sanitize_text_field($credentials['username']);
        }

        // La contraseña no se sanitiza por seguridad
        if (isset($credentials['password'])) {
            $sanitized['password'] = $credentials['password'];
        }

        return $sanitized;
    }

    /**
     * Valida y sanitiza las credenciales
     *
     * @param array $credentials Credenciales a validar y sanitizar
     * @return array Array con las credenciales sanitizadas y errores de validación
     */
    public static function validate_and_sanitize($credentials) {
        $errors = self::validate_basic_credentials($credentials);
        $sanitized = self::sanitize_credentials($credentials);

        return [
            'errors' => $errors,
            'credentials' => $sanitized
        ];
    }
} 