<?php
/**
 * Gestión de la tabla de logs en la base de datos
 *
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar los logs en la base de datos
 *
 * @package MiIntegracionApi\Helpers
 * @since 1.0.0
 */
class DbLogs {
    
    /**
     * Crea o actualiza la tabla de logs en la base de datos
     */
    public static function crear_tabla_logs() {
        global $wpdb;

        $tabla           = $wpdb->prefix . 'mi_integracion_api_logs';
        $charset_collate = $wpdb->get_charset_collate();

        // SQL para crear la tabla de logs
        $sql = "CREATE TABLE {$tabla} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            fecha datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            tipo varchar(20) NOT NULL DEFAULT 'info',
            usuario varchar(100),
            entidad varchar(100),
            mensaje text NOT NULL,
            contexto longtext,
            PRIMARY KEY (id),
            KEY idx_fecha (fecha),
            KEY idx_tipo (tipo),
            KEY idx_entidad (entidad)
        ) {$charset_collate};";

        // Incluir archivo para dbDelta()
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Crear o actualizar tabla
        dbDelta( $sql );
    }
    
    /**
     * Registra hook de activación para crear la tabla de logs
     */
    public static function register_hooks() {
        register_activation_hook(MiIntegracionApi_PLUGIN_FILE, [self::class, 'crear_tabla_logs']);
    }

    /**
     * Registra un log en la base de datos
     *
     * @param string $mensaje Mensaje del log
     * @param string $tipo Tipo de log (info, error, warning, critical, debug)
     * @param string $entidad Entidad relacionada (endpoint, producto, etc.)
     * @param array  $contexto Datos adicionales
     * @return int|false ID del log insertado o false en caso de error
     */
    public static function registrar_log($mensaje, $tipo = 'info', $entidad = '', $contexto = array()) {
        global $wpdb;

        // Validar tipos de log permitidos
        $tipos_permitidos = array('info', 'error', 'warning', 'critical', 'debug');
        if (!in_array($tipo, $tipos_permitidos)) {
            $tipo = 'info';
        }

        // Sanitizar y validar datos
        $mensaje = sanitize_text_field($mensaje);
        $tipo = sanitize_text_field($tipo);
        $entidad = sanitize_text_field($entidad);

        // Validar longitud máxima
        if (strlen($mensaje) > 65535) {
            $mensaje = substr($mensaje, 0, 65535);
        }
        if (strlen($entidad) > 100) {
            $entidad = substr($entidad, 0, 100);
        }

        // Sanitizar contexto
        if (!empty($contexto)) {
            if (is_array($contexto)) {
                array_walk_recursive($contexto, function(&$value) {
                    if (is_string($value)) {
                        $value = sanitize_text_field($value);
                    }
                });
            }
            $contexto = json_encode($contexto);
        }

        // Obtener usuario actual
        $usuario = '';
        if (function_exists('wp_get_current_user')) {
            $current_user = wp_get_current_user();
            if ($current_user && $current_user->exists()) {
                $usuario = $current_user->user_login;
            }
        }

        // Tabla de logs (sin prefijo ya que QueryOptimizer::upsert lo agrega)
        $tabla = 'mi_integracion_api_logs';

        // Preparar datos para inserción
        $datos = array(
            'fecha'    => current_time('mysql'),
            'tipo'     => $tipo,
            'usuario'  => $usuario,
            'entidad'  => $entidad,
            'mensaje'  => $mensaje,
            'contexto' => !empty($contexto) ? json_encode($contexto) : null,
        );

        // Insertar usando la función optimizada
        // Como no hay condición de "upsert", pasamos un array vacío como condición WHERE
        $resultado = \MiIntegracionApi\Core\QueryOptimizer::upsert($tabla, $datos, array());

        return $resultado;
    }
    
    /**
     * Función de compatibilidad para mantener código antiguo funcionando
     * 
     * @deprecated Usar DbLogs::registrar_log() en su lugar
     * @internal Este método existe para compatibilidad con código existente
     */
    public static function mi_integracion_api_registrar_log($mensaje, $tipo = 'info', $entidad = '', $contexto = array()) {
        return self::registrar_log($mensaje, $tipo, $entidad, $contexto);
    }
}
// Las funciones globales se han movido al archivo includes/compatibility.php
