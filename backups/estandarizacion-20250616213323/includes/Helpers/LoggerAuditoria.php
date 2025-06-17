<?php
namespace MiIntegracionApi\Helpers;

class LoggerAuditoria {
    /**
     * Registra un mensaje en el log de auditoría.
     * @param string $msg
     * @param string $level
     * @param array $context
     */
    public static function log($msg, $level = 'info', $context = []) {
        $date = date('Y-m-d H:i:s');
        
        // Convertir $msg a string seguro
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_null($msg)) {
            $msg = 'NULL';
        } else {
            $msg = (string)$msg;
        }
        
        // Convertir contexto a string seguro
        $context_str = '';
        if (!empty($context)) {
            if (is_array($context) || is_object($context)) {
                $context_str = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $context_str = (string)$context;
            }
        }
        
        $formatted = "[$date][$level] $msg" . (!empty($context_str) ? " $context_str" : "") . "\n";
        
        // Guardar en archivo de log personalizado
        $log_file = defined('MiIntegracionApi_LOG') ? MiIntegracionApi_LOG : WP_CONTENT_DIR . '/mi-integracion-api-auditoria.log';
        @file_put_contents($log_file, $formatted, FILE_APPEND);
        
        // También enviar al log de WordPress si está habilitado
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log($formatted);
        }
    }

    /**
     * Atajo para log de error
     */
    public static function error($msg, $context = []) {
        // Convertir a string seguro
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_null($msg)) {
            $msg = 'NULL';
        } else {
            $msg = (string)$msg;
        }
        self::log($msg, 'error', $context);
    }

    /**
     * Atajo para log de advertencia
     */
    public static function warning($msg, $context = []) {
        if (is_array($msg) || is_object($msg)) {
            $msg = json_encode($msg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } elseif (is_null($msg)) {
            $msg = 'NULL';
        } else {
            $msg = (string)$msg;
        }
        self::log($msg, 'warning', $context);
    }

    /**
     * Atajo para log de info
     */
    public static function info($msg, $context = []) {
        self::log($msg, 'info', $context);
    }
}
