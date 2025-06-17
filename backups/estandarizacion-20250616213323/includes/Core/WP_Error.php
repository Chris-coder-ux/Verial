<?php
/**
 * Fallbacks para funciones de WordPress cuando no están disponibles
 * Usado principalmente para modo de prueba fuera de WordPress
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Core
 */

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
    // En modo de prueba, permitir la ejecución
    if (!defined('MI_INTEGRACION_API_TEST_MODE')) {
        exit;
    }
}

// Definir WP_Error si no está disponible
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $error_code;
        private $error_message;
        private $error_data;
        
        public function __construct($code = '', $message = '', $data = '') {
            $this->error_code = $code;
            $this->error_message = $message;
            $this->error_data = $data;
        }
        
        public function get_error_code() {
            return $this->error_code;
        }
        
        public function get_error_message() {
            return $this->error_message;
        }
        
        public function get_error_data() {
            return $this->error_data;
        }
    }
}

// Función auxiliar is_wp_error
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

// Función auxiliar wp_remote_request (simplificada para modo prueba)
if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = array()) {
        // Delegar a makeRequestWithCurl a través de una instancia global
        if (isset($GLOBALS['api_connector_instance'])) {
            return $GLOBALS['api_connector_instance']->makeRequestWithCurl($url, $args);
        }
        return new WP_Error('function_not_available', 'wp_remote_request no está disponible en modo de prueba');
    }
}

// Funciones auxiliares para wp_remote_retrieve_*
if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) {
        if (is_array($response)) {
            return $response['status_code'] ?? 0;
        }
        return 0;
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        if (is_array($response)) {
            return $response['body'] ?? '';
        }
        return '';
    }
}

if (!function_exists('wp_remote_retrieve_response_message')) {
    function wp_remote_retrieve_response_message($response) {
        if (is_array($response)) {
            return $response['status_message'] ?? '';
        }
        return '';
    }
}

if (!function_exists('wp_remote_retrieve_headers')) {
    function wp_remote_retrieve_headers($response) {
        if (is_array($response)) {
            return $response['headers'] ?? array();
        }
        return array();
    }
}

// Función get_option (simplificada para modo de prueba)
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        // En modo de prueba, devolver valores por defecto razonables
        $test_options = [
            'mi_integracion_api_ajustes_cache_enabled' => true,
            'mi_integracion_api_ajustes_cache_ttl' => 3600,
            'mi_integracion_api_ajustes_api_url' => 'https://api.verial.com/',
            'mi_integracion_api_ajustes_usuario' => 'test_user',
            'mi_integracion_api_ajustes_password' => 'test_pass'
        ];
        
        return $test_options[$option] ?? $default;
    }
}

// Función update_option (simplificada para modo de prueba)
if (!function_exists('update_option')) {
    function update_option($option, $value, $autoload = null) {
        // En modo de prueba, simplemente retornar true
        return true;
    }
}

// Función wp_parse_args (simplificada)
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        if (is_array($args)) {
            return array_merge($defaults, $args);
        } elseif (is_string($args)) {
            parse_str($args, $parsed_args);
            return array_merge($defaults, $parsed_args);
        }
        return $defaults;
    }
}

// Función sanitize_text_field (simplificada)
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(strip_tags($str));
    }
}

// Función esc_html (simplificada)
if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}
