<?php
namespace MiIntegracionApi\Helpers;

/**
 * Helper para autenticación adicional (API key/JWT) en endpoints REST.
 * Permite validar API key en header o JWT en Authorization.
 */
class AuthHelper {
    /**
     * Número máximo de intentos permitidos en la ventana de tiempo
     */
    const RATE_LIMIT_MAX_ATTEMPTS = 20;

    /**
     * Duración de la ventana de tiempo en segundos
     */
    const RATE_LIMIT_WINDOW = 60;

    /**
     * Tiempo de bloqueo en segundos tras exceder el límite
     */
    const RATE_LIMIT_BLOCK_TIME = 600;

    /**
     * Valida la autenticación adicional para un request REST.
     *
     * @param \WP_REST_Request $request
     * @return true|\WP_Error
     */
    public static function validate_rest_auth($request) {
        // Sanitizar y validar headers
        $api_key = sanitize_text_field($request->get_header('X-Api-Key'));
        if ($api_key && self::is_valid_api_key($api_key)) {
            return true;
        }

        $auth_header = sanitize_text_field($request->get_header('Authorization'));
        if ($auth_header && preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
            $jwt = sanitize_text_field($matches[1]);
            if (self::is_valid_jwt($jwt)) {
                return true;
            }
        }

        return new \WP_Error(
            'rest_forbidden',
            esc_html__('Autenticación adicional requerida (API key o JWT no válida).', 'mi-integracion-api'),
            array('status' => 403)
        );
    }

    /**
     * Obtiene la IP real del cliente considerando proxies
     *
     * @return string
     */
    private static function get_real_ip() {
        $ip = '';
        $headers = array(
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = filter_var($_SERVER[$header], FILTER_VALIDATE_IP);
                if ($ip) {
                    break;
                }
            }
        }

        return $ip ?: 'unknown';
    }

    /**
     * Valida la API key recibida.
     *
     * @param string $api_key
     * @return bool
     */
    public static function is_valid_api_key($api_key) {
        if (empty($api_key)) {
            return false;
        }

        $api_key = sanitize_text_field($api_key);
        
        $api_connector = new \MiIntegracionApi\Core\ApiConnector();
        $env_key = $api_connector->get_api_key();
        
        if ($env_key && $api_key === $env_key) {
            if (\SettingsHelper::is_api_key_revoked($api_key)) {
                self::log_security_event('api_key_revoked', [
                    'api_key' => substr($api_key, 0, 8),
                    'source' => 'env'
                ]);
                return false;
            }
            return true;
        }

        $valid_keys = get_option('mi_integracion_api_keys', array());
        if (!is_array($valid_keys)) {
            $valid_keys = array();
        }

        $is_valid = in_array($api_key, $valid_keys, true);
        
        if ($is_valid && \SettingsHelper::is_api_key_revoked($api_key)) {
            self::log_security_event('api_key_revoked', [
                'api_key' => substr($api_key, 0, 8),
                'source' => 'option'
            ]);
            return false;
        }

        if (!$is_valid) {
            self::log_security_event('api_key_invalid', [
                'api_key' => substr($api_key, 0, 8)
            ]);
        }

        return $is_valid;
    }

    /**
     * Registra eventos de seguridad usando el sistema de logging
     *
     * @param string $event_type
     * @param array $context
     */
    private static function log_security_event($event_type, $context = array()) {
        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            $message = sprintf(
                __('[mi-integracion-api] Evento de seguridad: %s', 'mi-integracion-api'),
                $event_type
            );
            \MiIntegracionApi\Helpers\Logger::warning($message, $context);
        }
    }

    /**
     * Valida el JWT recibido con comprobaciones avanzadas.
     *
     * @param string $jwt
     * @return bool
     */
    public static function is_valid_jwt($jwt) {
        if (empty($jwt)) {
            return false;
        }

        if (!class_exists('Firebase\JWT\JWT')) {
            self::log_security_event('jwt_library_missing');
            return false;
        }

        try {
            $secret = defined('MiIntegracionApi_JWT_SECRET') ? constant('MiIntegracionApi_JWT_SECRET') : '';
            if (!$secret) {
                self::log_security_event('jwt_secret_missing');
                return false;
            }

            $decoded = \Firebase\JWT\JWT::decode($jwt, new \Firebase\JWT\Key($secret, 'HS256'));
            
            $now = time();
            if (isset($decoded->exp) && $decoded->exp < $now) {
                self::log_security_event('jwt_expired');
                return false;
            }

            if (defined('MiIntegracionApi_JWT_AUD') && isset($decoded->aud) && 
                $decoded->aud !== constant('MiIntegracionApi_JWT_AUD')) {
                self::log_security_event('jwt_invalid_audience');
                return false;
            }

            if (defined('MiIntegracionApi_JWT_ISS') && isset($decoded->iss) && 
                $decoded->iss !== constant('MiIntegracionApi_JWT_ISS')) {
                self::log_security_event('jwt_invalid_issuer');
                return false;
            }

            if (method_exists(__CLASS__, 'is_jwt_revoked') && self::is_jwt_revoked($jwt)) {
                self::log_security_event('jwt_revoked');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            self::log_security_event('jwt_validation_error', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Comprueba si un JWT está revocado.
     *
     * @param string $jwt
     * @return bool
     */
    public static function is_jwt_revoked($jwt) {
        if (empty($jwt)) {
            return false;
        }

        $revoked_tokens = get_option('mi_integracion_api_revoked_jwts', array());
        if (!is_array($revoked_tokens)) {
            return false;
        }

        return in_array($jwt, $revoked_tokens, true);
    }

    /**
     * Comprueba y actualiza el rate limit para la IP y API key/JWT.
     *
     * @param string $context
     * @param string|null $api_key
     * @return true|\WP_Error
     */
    public static function check_rate_limit($context, $api_key = null) {
        if (empty($context)) {
            return new \WP_Error(
                'invalid_context',
                esc_html__('Contexto inválido para rate limiting.', 'mi-integracion-api'),
                array('status' => 400)
            );
        }

        $context = sanitize_text_field($context);
        $ip = self::get_real_ip();
        $key = $api_key ? substr(sanitize_text_field($api_key), 0, 16) : '';
        
        $option_key = 'mia_rate_limit_' . wp_hash($context . '|' . $ip . '|' . $key, 'nonce');
        
        $data = get_transient($option_key);
        if (!is_array($data)) {
            $data = array(
                'count' => 0,
                'start' => time(),
                'blocked_until' => 0,
            );
        }

        if ($data['blocked_until'] > time()) {
            self::alert_admin_abuse($context, $ip, $key, $data);
            return new \WP_Error(
                'rate_limited',
                esc_html__('Demasiadas peticiones. Intenta de nuevo más tarde.', 'mi-integracion-api'),
                array(
                    'status' => 429,
                    'retry_after' => $data['blocked_until'] - time(),
                )
            );
        }

        if (time() - $data['start'] > self::RATE_LIMIT_WINDOW) {
            $data = array(
                'count' => 0,
                'start' => time(),
                'blocked_until' => 0,
            );
        }

        ++$data['count'];
        if ($data['count'] > self::RATE_LIMIT_MAX_ATTEMPTS) {
            $data['blocked_until'] = time() + self::RATE_LIMIT_BLOCK_TIME;
            set_transient($option_key, $data, self::RATE_LIMIT_BLOCK_TIME);
            self::alert_admin_abuse($context, $ip, $key, $data);
            
            self::log_security_event('rate_limit_exceeded', [
                'ip' => $ip,
                'context' => $context,
                'key' => $key,
                'count' => $data['count']
            ]);
            
            return new \WP_Error(
                'rate_limited',
                esc_html__('Demasiadas peticiones. Intenta de nuevo más tarde.', 'mi-integracion-api'),
                array(
                    'status' => 429,
                    'retry_after' => self::RATE_LIMIT_BLOCK_TIME,
                )
            );
        }

        set_transient($option_key, $data, self::RATE_LIMIT_BLOCK_TIME);
        return true;
    }

    /**
     * Envía una alerta por email al admin si se detecta abuso.
     *
     * @param string $context
     * @param string $ip
     * @param string $key
     * @param array $data
     */
    private static function alert_admin_abuse($context, $ip, $key, $data) {
        $alert_transient = 'mia_abuse_alert_' . wp_hash($context . '|' . $ip . '|' . $key, 'nonce');
        
        if (get_transient($alert_transient)) {
            return;
        }

        set_transient($alert_transient, 1, HOUR_IN_SECONDS);
        
        $admin_email = get_option('admin_email');
        if (!is_email($admin_email)) {
            self::log_security_event('invalid_admin_email', [
                'email' => $admin_email
            ]);
            return;
        }

        $subject = sprintf(
            __('[Alerta] Abuso detectado en API Verial (%s)', 'mi-integracion-api'),
            $context
        );

        $message = sprintf(
            __(
                "Se ha detectado un posible abuso en un endpoint protegido de la API Verial.\n\n" .
                "Contexto: %s\n" .
                "IP: %s\n" .
                "API key/JWT (parcial): %s\n" .
                "Fecha/hora: %s\n" .
                "Intentos en ventana: %s\n" .
                "Bloqueado hasta: %s\n\n" .
                'Revisa los logs del plugin para más detalles.',
                'mi-integracion-api'
            ),
            esc_html($context),
            esc_html($ip),
            $key ? esc_html(substr($key, 0, 8)) . '...' : __('(no enviada)', 'mi-integracion-api'),
            date('Y-m-d H:i:s'),
            esc_html($data['count'] ?? '?'),
            isset($data['blocked_until']) ? date('Y-m-d H:i:s', $data['blocked_until']) : '-'
        );

        if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
            \MiIntegracionApi\Helpers\Logger::critical('[ALERTA ABUSO] ' . $message, array(), 'abuse');
        }

        wp_mail($admin_email, $subject, $message);
    }
}