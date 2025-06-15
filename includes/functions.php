<?php
/**
 * Funciones globales para Mi Integración API
 *
 * Este archivo contiene funciones de utilidad que no pertenecen
 * a ninguna clase específica pero son necesarias para el plugin.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Obtiene el servicio de criptografía
 *
 * @return \MiIntegracionApi\Core\CryptoService|null
 */
function mi_integracion_api_get_crypto() {
    return \MiIntegracionApi\Helpers\ApiHelpers::get_crypto();
}

/**
 * Registra información en el log del plugin.
 *
 * @param string $message Mensaje a registrar
 * @param string|array $context Contexto del mensaje. Si es string, se tratará como categoría.
 * @param string $level Nivel de log ('info', 'warning', 'error', 'debug', 'critical')
 * @return void
 */
function mi_integracion_api_log($message, $context = 'general', $level = 'info') {
    if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
        // Si el contexto es un string, convertirlo a array con formato estándar
        if (!is_array($context)) {
            $context = array('category' => $context);
        } elseif (!isset($context['category'])) {
            // Si es un array pero no tiene categoría, añadirla
            $context['category'] = 'general';
        }
        
        switch ($level) {
            case 'critical':
                $logger = new \MiIntegracionApi\Helpers\Logger('general');
                $logger->critical($message, $context);
                break;
            case 'error':
                $logger = new \MiIntegracionApi\Helpers\Logger('general');
                $logger->error($message, $context);
                break;
            case 'warning':
                $logger = new \MiIntegracionApi\Helpers\Logger('general');
                $logger->warning($message, $context);
                break;
            case 'debug':
                $logger = new \MiIntegracionApi\Helpers\Logger('general');
                $logger->debug($message, $context);
                break;
            case 'info':
            default:
                $logger = new \MiIntegracionApi\Helpers\Logger('general');
                $logger->info($message, $context);
                break;
        }
    }
}

// La función mi_integracion_api_log usa los métodos correctos del Logger y no llama a log() directamente con nivel inválido.

/**
 * Comprueba si un feature está activado
 *
 * @param string  Nombre del feature
 * @return boolean True si el feature está activado
 */
function mi_integracion_api_feature_enabled() {
    // Implementar lógica de verificación de features
    return apply_filters('mi_integracion_api_feature_enabled', false, );
}
