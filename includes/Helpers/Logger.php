<?php
/**
 * Sistema unificado de logging y manejo de errores
 *
 * Este archivo contiene la implementación unificada de logging
 * para todo el plugin Mi Integración API.
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */

namespace MiIntegracionApi\Helpers;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Incluir la interfaz ILogger
require_once __DIR__ . '/ILogger.php';

/**
 * Clase principal para logging y manejo de errores
 */
class Logger implements ILogger {
    /**
     * Niveles de log disponibles
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    const LEVEL_EMERGENCY = 'emergency';
    const LEVEL_ALERT = 'alert';
    const LEVEL_NOTICE = 'notice';
    
    /**
     * Archivo de log actual
     *
     * @var string
     */
    private static $log_file = '';
    
    /**
     * Tamaño máximo del archivo de log en bytes (5MB)
     *
     * @var int
     */
    private static $max_log_size = 5242880;
    
    /**
     * Lista de claves sensibles que deben ser oscurecidas en los logs
     *
     * @var array
     */
    private static $sensitive_keys = array(
        'password', 'pass', 'pwd', 'secret', 'token', 'api_key', 
        'apikey', 'api_secret', 'apisecret', 'key', 'auth', 
        'credentials', 'credential', 'private', 'security',
        'hash', 'salt', 'iv', 'cipher', 'crypt', 'secure',
        'banco', 'tarjeta', 'cvv', 'account', 'cuenta', 'iban',
        'swift', 'bic', 'pin', 'pass_token', 'refresh', 'jwt',
        'authorization'
    );
    
    /**
     * Categoría de log por instancia
     *
     * @var string|null
     */
    private ?string $category = null;

    /**
     * Constructor opcionalmente acepta una categoría
     *
     * @param string|null $category
     */
    public function __construct($category = null) {
        if ($category) {
            $this->category = $category;
        }
    }
    
    /**
     * Inicializa el sistema de logging
     *
     * @return void
     */
    public static function init() {
        // Establecer el archivo de log
        $log_dir = dirname(__FILE__, 3) . '/api_connector';
        if (!file_exists($log_dir)) {
            mkdir($log_dir, 0755, true);
        }
        
        self::$log_file = $log_dir . '/mi-integracion-' . date('Y-m-d') . '.log';
        
        // Rotar el log si es necesario
        self::maybe_rotate_log();
    }
    
    /**
     * Rota el archivo de log si excede el tamaño máximo
     *
     * @return void
     */
    private static function maybe_rotate_log() {
        if (!file_exists(self::$log_file)) {
            return;
        }
        
        if (filesize(self::$log_file) > self::$max_log_size) {
            $backup_file = self::$log_file . '.' . time() . '.bak';
            rename(self::$log_file, $backup_file);
            
            // Eliminar logs antiguos (más de 30 días)
            $files = glob(dirname(self::$log_file) . '/*.bak');
            foreach ($files as $file) {
                if (filemtime($file) < time() - 30 * 86400) {
                    unlink($file);
                }
            }
        }
    }
    
    /**
     * Prepara el contexto del log añadiendo información estándar
     *
     * @param array|string $context Contexto original
     * @return array Contexto enriquecido
     */
    private static function prepare_context($context) {
        // Convertir string a array para estructura estándar
        if (!is_array($context)) {
            if (empty($context)) {
                $context = array();
            } else {
                $context = array('message_context' => $context);
            }
        }
        
        // Añadir información del usuario actual
        if (!isset($context['user_id']) && function_exists('get_current_user_id')) {
            $user_id = get_current_user_id();
            if ($user_id > 0) {
                $context['user_id'] = $user_id;
                
                // Añadir rol si está disponible
                if (!isset($context['user_role'])) {
                    $user = get_userdata($user_id);
                    if ($user && !empty($user->roles)) {
                        $context['user_role'] = implode(',', $user->roles);
                    }
                }
            }
        }
        
        // Añadir URL y método si es una petición HTTP
        if (!isset($context['request_url']) && isset($_SERVER['REQUEST_URI'])) {
            $url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') .
                   (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost') .
                   $_SERVER['REQUEST_URI'];
            
            if (function_exists('esc_url_raw')) {
                $context['request_url'] = esc_url_raw($url);
            } else {
                $context['request_url'] = $url;
            }
            
            if (!isset($context['request_method']) && isset($_SERVER['REQUEST_METHOD'])) {
                if (function_exists('sanitize_text_field')) {
                    $context['request_method'] = sanitize_text_field($_SERVER['REQUEST_METHOD']);
                } else {
                    $context['request_method'] = $_SERVER['REQUEST_METHOD'];
                }
            }
        }
        
        // Añadir traceback para errores (si debug está activado)
        if (!isset($context['trace']) && defined('WP_DEBUG') && WP_DEBUG) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4);
            // Eliminar las llamadas internas a Logger
            $filtered_trace = array_filter($backtrace, function($trace) {
                return !(isset($trace['class']) && $trace['class'] === __CLASS__);
            });
            
            if (!empty($filtered_trace)) {
                $trace = reset($filtered_trace);
                if (isset($trace['file']) && isset($trace['line'])) {
                    $file = $trace['file'];
                    if (defined('ABSPATH')) {
                        $file = str_replace(ABSPATH, '', $file); // Relativo a WP
                    }
                    $context['trace'] = $file . ':' . $trace['line'];
                }
            }
        }
        
        // Añadir información de rendimiento para análisis (tiempo y memoria)
        if (!isset($context['memory_usage']) && function_exists('memory_get_usage')) {
            $memory_bytes = memory_get_usage(true);
            if (function_exists('size_format')) {
                $context['memory_usage'] = size_format($memory_bytes, 2);
            } else {
                // Formatear manualmente si size_format no está disponible
                $units = ['B', 'KB', 'MB', 'GB'];
                $bytes = max($memory_bytes, 0);
                $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                $pow = min($pow, count($units) - 1);
                $bytes /= pow(1024, $pow);
                $context['memory_usage'] = round($bytes, 2) . ' ' . $units[$pow];
            }
        }
        
        // Añadir identificador de transacción para seguimiento
        if (!isset($context['transaction_id'])) {
            if (!defined('MI_API_TRANSACTION_ID')) {
                define('MI_API_TRANSACTION_ID', uniqid('mi_', true));
            }
            $context['transaction_id'] = MI_API_TRANSACTION_ID;
        }
        
        return $context;
    }
    
    /**
     * Registra un mensaje con el nivel especificado
     *
     * @param string $level Nivel de log (debug, info, warning, error)
     * @param string $message Mensaje a registrar
     * @param array|\string $context Contexto del mensaje
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    private static function writeLog($level, $message, $context = '', $category = null) {
        // Inicializar el sistema si no se ha hecho ya
        if (empty(self::$log_file)) {
            self::init();
        }
        
        // Enriquecer el contexto con información estándar
        $context = self::prepare_context($context);
        
        // Formatear el mensaje
        $timestamp = date('Y-m-d H:i:s');
        $formatted_context = is_array($context) ? json_encode(self::sanitize_log_data($context)) : $context;
        $log_entry = "[$timestamp] [$level]" . ($category ? " [$category]" : '') . " $message" . (empty($formatted_context) ? '' : " | $formatted_context") . PHP_EOL;
        
        // Escribir en el archivo de log
        error_log($log_entry, 3, self::$log_file);
        
        // Si es un error, también registrarlo en el log de errores de WordPress
        if ($level === self::LEVEL_ERROR || $level === self::LEVEL_CRITICAL) {
            error_log("Mi Integración API: $message" . (empty($formatted_context) ? '' : " | $formatted_context"));
        }
        
        // Aplicar hook para integraciones externas de monitoring/logging
        if (function_exists('do_action')) {
            do_action('mi_integracion_api_after_log', $level, $message, $context);
        }
    }
    
    /**
     * Sanitiza datos sensibles para evitar filtración en logs
     *
     * @param array $data Datos a sanitizar
     * @return array Datos sanitizados
     */
    private static function sanitize_log_data($data) {
        if (!is_array($data)) {
            return $data;
        }
        
        foreach ($data as $key => $value) {
            // Revisar si la clave es sensible
            $is_sensitive = false;
            foreach (self::$sensitive_keys as $sensitive_key) {
                if (stripos($key, $sensitive_key) !== false) {
                    $is_sensitive = true;
                    break;
                }
            }
            
            if ($is_sensitive) {
                // Ocultar el valor sensible
                $data[$key] = is_string($value) ? '***DATO_SENSIBLE***' : '[DATO_SENSIBLE]';
            } elseif (is_array($value)) {
                // Procesar recursivamente arrays anidados
                $data[$key] = self::sanitize_log_data($value);
            }
        }
        
        return $data;
    }
    
    // ============================================================================
    // IMPLEMENTACIÓN DE INTERFAZ ILogger (métodos de instancia)
    // ============================================================================
    
    /**
     * Sistema para registrar mensajes de emergencia.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function emergency($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_EMERGENCY, $message, $context, $category);
    }

    /**
     * Sistema para registrar alertas.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function alert($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_ALERT, $message, $context, $category);
    }

    /**
     * Sistema para registrar errores críticos.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function critical($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_CRITICAL, $message, $context, $category);
    }

    /**
     * Sistema para registrar errores.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function error($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_ERROR, $message, $context, $category);
    }

    /**
     * Sistema para registrar advertencias.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function warning($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_WARNING, $message, $context, $category);
    }

    /**
     * Sistema para registrar avisos.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function notice($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_NOTICE, $message, $context, $category);
    }

    /**
     * Sistema para registrar información.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function info($message, array $context = array(), $category = null) {
        self::writeLog(self::LEVEL_INFO, $message, $context, $category);
    }

    /**
     * Sistema para registrar mensajes de depuración.
     *
     * @param string $message
     * @param array $context
     * @param string|null $category Categoría opcional para el log
     * @return void
     */
    public function debug($message, array $context = array(), $category = null) {
        // Solo registrar mensajes de depuración si está habilitado
        if (defined('WP_DEBUG') && WP_DEBUG) {
            self::writeLog(self::LEVEL_DEBUG, $message, $context, $category);
        }
    }

    /**
     * Registra un mensaje en el log
     *
     * @param string $message
     * @param string $level Uno de: debug, info, warning, error, critical, emergency, alert, notice
     * @param array $context
     * @return void
     * @throws \InvalidArgumentException Si el nivel no es válido
     */
    public function log($message, $level = self::LEVEL_INFO, $context = array()) {
        // Primero normalizar el nivel para aceptar tanto constantes como cadenas de texto
        $normalized_level = $this->normalizeLevel($level);
        
        $valid_levels = [
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_EMERGENCY,
            self::LEVEL_ALERT,
            self::LEVEL_NOTICE
        ];
        
        if (!in_array($normalized_level, $valid_levels, true)) {
            throw new \InvalidArgumentException('Invalid log level provided.');
        }
        
        self::writeLog($normalized_level, $message, $context, $this->category);
    }
    
    /**
     * Normaliza el nivel de log para aceptar tanto constantes como cadenas
     *
     * @param string $level Nivel de log
     * @return string Nivel normalizado
     */
    private function normalizeLevel($level)
    {
        // Si ya es una constante válida, devolverla directamente
        $valid_levels = [
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_EMERGENCY,
            self::LEVEL_ALERT,
            self::LEVEL_NOTICE
        ];
        
        if (in_array($level, $valid_levels, true)) {
            return $level;
        }
        
        // Si es una cadena de texto como 'info', 'debug', etc., convertirla a constante
        $level_map = [
            'debug'     => self::LEVEL_DEBUG,
            'info'      => self::LEVEL_INFO,
            'notice'    => self::LEVEL_NOTICE,
            'warning'   => self::LEVEL_WARNING,
            'error'     => self::LEVEL_ERROR,
            'critical'  => self::LEVEL_CRITICAL,
            'alert'     => self::LEVEL_ALERT,
            'emergency' => self::LEVEL_EMERGENCY
        ];
        
        return $level_map[strtolower($level)] ?? self::LEVEL_INFO;
    }
    
    /**
     * Establece la categoría de log para la instancia.
     *
     * @since 1.0.0
     * @param string $category Categoría a establecer
     * @return void
     */
    public function set_category(string $category): void {
        $this->category = $category;
    }

    /**
     * Obtiene la categoría de log de la instancia.
     *
     * @since 1.0.0
     * @return string|null Categoría actual
     */
    public function get_category(): ?string {
        return $this->category;
    }
}

// Inicializar el sistema de logging
Logger::init();
