<?php
/**
 * Manejador de respuestas de la API
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

use WP_REST_Response;
use WP_Error;

class API_Response_Handler {
    /**
     * Formatea una respuesta exitosa
     *
     * @param mixed $data Datos a incluir en la respuesta
     * @param array $extra Datos adicionales para incluir
     * @return WP_REST_Response
     */
    public static function success($data = null, array $extra = []) {
        $response = [
            'success' => true,
            'data' => $data
        ];

        if (!empty($extra)) {
            $response = array_merge($response, $extra);
        }

        return new WP_REST_Response($response);
    }

    /**
     * Formatea una respuesta de error
     *
     * @param string $code Código de error
     * @param string $message Mensaje de error
     * @param array $data Datos adicionales del error
     * @param int $status Código de estado HTTP
     * @return WP_Error
     */
    public static function error($code, $message, $data = [], $status = 400) {
        return new WP_Error(
            $code,
            $message,
            array_merge(
                ['status' => $status],
                $data
            )
        );
    }

    /**
     * Formatea una respuesta de error de validación
     *
     * @param array $errors Errores de validación
     * @return WP_Error
     */
    public static function validation_error($errors) {
        return self::error(
            'validation_error',
            __('Error de validación', 'mi-integracion-api'),
            ['errors' => $errors],
            422
        );
    }

    /**
     * Formatea una respuesta de error de autenticación
     *
     * @param string $message Mensaje de error
     * @return WP_Error
     */
    public static function auth_error($message = '') {
        return self::error(
            'auth_error',
            $message ?: __('Error de autenticación', 'mi-integracion-api'),
            [],
            401
        );
    }

    /**
     * Formatea una respuesta de error de permisos
     *
     * @param string $message Mensaje de error
     * @return WP_Error
     */
    public static function permission_error($message = '') {
        return self::error(
            'permission_error',
            $message ?: __('No tienes permisos para realizar esta acción', 'mi-integracion-api'),
            [],
            403
        );
    }
} 