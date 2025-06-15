<?php
/**
 * Funciones de compatibilidad para Mi Integración API
 *
 * Este archivo contiene funciones de compatibilidad con versiones anteriores
 * que deben mantenerse en el espacio de nombres global.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

// Estas funciones están en el espacio de nombres global intencionalmente
// No usar PSR-4 para este archivo

/**
 * Funciones de compatibilidad para Crypto
 */
if (!function_exists('mia_encrypt_api_secret')) {
    /**
     * Cifra un texto plano usando AES-256-CBC
     * 
     * @deprecated Usar \MiIntegracionApi\Helpers\Crypto::encrypt_api_secret() en su lugar
     * 
     * @param string $plain_text Texto a cifrar
     * @param string $password Contraseña para cifrar (usa AUTH_KEY si no se proporciona)
     * @return string Texto cifrado en formato base64
     */
    function mia_encrypt_api_secret($plain_text, $password = null) {
        return \MiIntegracionApi\Helpers\Crypto::encrypt_api_secret($plain_text, $password);
    }
}

if (!function_exists('mia_decrypt_api_secret')) {
    /**
     * Descifra un texto cifrado usando AES-256-CBC
     * 
     * @deprecated Usar \MiIntegracionApi\Helpers\Crypto::decrypt_api_secret() en su lugar
     * 
     * @param string $ciphertext Texto cifrado en formato base64
     * @param string $password Contraseña para descifrar (usa AUTH_KEY si no se proporciona)
     * @return string|false Texto descifrado o false si falla la verificación de integridad
     */
    function mia_decrypt_api_secret($ciphertext, $password = null) {
        return \MiIntegracionApi\Helpers\Crypto::decrypt_api_secret($ciphertext, $password);
    }
}

/**
 * Funciones de compatibilidad para DbLogs
 */
if (!function_exists('mi_integracion_api_crear_tabla_logs')) {
    /**
     * Crea o actualiza la tabla de logs en la base de datos
     * 
     * @deprecated Usar \MiIntegracionApi\Helpers\DbLogs::crear_tabla_logs() en su lugar
     * 
     * @return void
     */
    function mi_integracion_api_crear_tabla_logs() {
        return \MiIntegracionApi\Helpers\DbLogs::crear_tabla_logs();
    }
}

if (!function_exists('mi_integracion_api_registrar_log')) {
    /**
     * Registra un log en la base de datos
     * 
     * @deprecated Usar \MiIntegracionApi\Helpers\DbLogs::registrar_log() en su lugar
     * 
     * @param string $mensaje Mensaje del log
     * @param string $tipo Tipo de log (info, error, warning, critical, debug)
     * @param string $entidad Entidad relacionada (endpoint, producto, etc.)
     * @param array  $contexto Datos adicionales
     * @return int|false ID del log insertado o false en caso de error
     */
    function mi_integracion_api_registrar_log($mensaje, $tipo = 'info', $entidad = '', $contexto = array()) {
        return \MiIntegracionApi\Helpers\DbLogs::registrar_log($mensaje, $tipo, $entidad, $contexto);
    }
}

// No se detecta uso de Logger::log, solo funciones de compatibilidad.
