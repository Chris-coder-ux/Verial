<?php

namespace MiIntegracionApi\Admin;

/**
 * Endpoints AJAX para el Dashboard
 *
 * @package MiIntegracionApi\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar las operaciones AJAX del Dashboard
 *
 * @package MiIntegracionApi\Admin
 * @since 1.0.0
 */
class AjaxDashboard {

    /**
     * Inicializa los hooks de AJAX para el dashboard
     */
    public static function init() {
        // Acciones AJAX para el dashboard
        add_action('wp_ajax_mi_integracion_api_get_dashboard_data', [self::class, 'get_dashboard_data']);
        add_action('wp_ajax_mi_integracion_api_get_recent_activity', [self::class, 'get_recent_activity']);
        // Handler solicitado: recarga de métricas
        add_action('wp_ajax_mi_integracion_api_reload_metrics', [self::class, 'reload_metrics']);
    }

    /**
     * Obtener datos para el dashboard
     */
    public static function get_dashboard_data() {
        // Verificar nonce
        check_ajax_referer('mi_integracion_api_dashboard_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }

        // Obtener estadísticas
        $stats = array(
            'products'    => intval(get_option('mia_last_sync_count', 0)),
            'errors'      => intval(get_option('mia_last_sync_errors', 0)),
            'lastSync'    => get_option('mia_last_sync_time') ? date_i18n('d/m/Y H:i', get_option('mia_last_sync_time')) : '',
            'pendingSync' => intval(get_option('mia_pending_sync_count', 0)),
            'status'      => self::get_sync_status(),
        );

        // Obtener actividad reciente (últimos 5 logs)
        $activity = self::get_recent_logs(5);

        // Devolver datos
        wp_send_json_success(
            array(
                'stats'    => $stats,
                'activity' => $activity,
            )
        );
    }
    
    /**
     * Obtener estado actual de sincronización
     *
     * @return string Estado de sincronización (active, scheduled, error, success, inactive)
     */
    public static function get_sync_status() {
        // Verificar si hay una sincronización en curso
        $sync_running = get_transient('mia_sync_running');
        if ($sync_running) {
            return 'active';
        }

        // Verificar si hubo errores en la última sincronización
        $last_sync_errors = intval(get_option('mia_last_sync_errors', 0));
        if ($last_sync_errors > 0) {
            return 'error';
        }

        // Verificar si la última sincronización fue exitosa
        $last_sync_time = get_option('mia_last_sync_time');
        if ($last_sync_time && $last_sync_time > time() - DAY_IN_SECONDS) {
            return 'success';
        }

        // Verificar si hay una sincronización programada
        $next_scheduled = wp_next_scheduled('mi_integracion_api_cron_sync');
        if ($next_scheduled) {
            return 'scheduled';
        }

        // Por defecto, inactivo
        return 'inactive';
    }

    /**
     * Obtener logs recientes para la sección de actividad
     *
     * @param int $limit Número de logs a obtener
     * @return array Logs formateados para el dashboard
     */
    public static function get_recent_logs($limit = 5) {
        global $wpdb;

        // Tabla de logs
        $tabla_logs = $wpdb->prefix . 'mi_integracion_api_logs';

        // Clave de caché para esta consulta específica
        $cache_key = 'dashboard_recent_logs_' . $limit;

        // Comprobar si la tabla existe antes de consultar
        $tabla_existe = \MiIntegracionApi\Core\QueryOptimizer::get_var(
            'SHOW TABLES LIKE %s',
            array($tabla_logs),
            'tabla_logs_existe',
            HOUR_IN_SECONDS
        );

        if (!$tabla_existe) {
            return array();
        }

        // Consulta para obtener logs recientes, usando el optimizador con caché
        $query = "SELECT id, fecha, tipo, mensaje, usuario, entidad, contexto FROM {$tabla_logs} ORDER BY fecha DESC LIMIT %d";
        $logs  = \MiIntegracionApi\Core\QueryOptimizer::get_results(
            $query,
            array($limit),
            $cache_key,
            30, // Caché de 30 segundos para datos recientes
            ARRAY_A
        );

        // Formatear logs para el dashboard
        $activity = array();
        if ($logs) {
            foreach ($logs as $log) {
                $contexto = !empty($log['contexto']) ? json_decode($log['contexto'], true) : array();

                $activity[] = array(
                    'type'    => $log['tipo'],
                    'message' => $log['mensaje'],
                    'time'    => human_time_diff(strtotime($log['fecha']), current_time('timestamp')) . ' ' . __('atrás', 'mi-integracion-api'),
                    'user'    => $log['usuario'],
                    'entity'  => $log['entidad'],
                );
            }
        }

        return $activity;
    }
    
    /**
     * Endpoint AJAX para obtener actividad reciente
     */
    public static function get_recent_activity() {
        // Verificar nonce
        check_ajax_referer('mi_integracion_api_dashboard_nonce', 'nonce');

        // Verificar permisos
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }

        // Obtener límite de la solicitud o usar 5 por defecto
        $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 5;
        
        // Limitar a un máximo de 20 para evitar consultas pesadas
        $limit = min($limit, 20);
        
        // Obtener logs
        $activity = self::get_recent_logs($limit);
        
        wp_send_json_success([
            'activity' => $activity,
            'count' => count($activity)
        ]);
    }

    /**
     * Handler AJAX para recargar métricas del dashboard (compatibilidad con dashboard.js)
     */
    public static function reload_metrics() {
        // No requiere nonce porque dashboard.js no lo envía, pero se puede añadir si se actualiza el JS
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permiso denegado', 403);
            return;
        }
        // Ejemplo: devolver el total de productos sincronizados
        $metric = intval(get_option('mia_last_sync_count', 0));
        wp_send_json_success(['metric' => $metric]);
    }
}

// Inicializar la clase
AjaxDashboard::init();

// Funciones de compatibilidad con código antiguo
if (!function_exists('mi_integracion_api_get_dashboard_data')) {
    function mi_integracion_api_get_dashboard_data() {
        AjaxDashboard::get_dashboard_data();
    }
}

if (!function_exists('mi_integracion_api_get_sync_status')) {
    function mi_integracion_api_get_sync_status() {
        return AjaxDashboard::get_sync_status();
    }
}

if (!function_exists('mi_integracion_api_get_recent_logs')) {
    function mi_integracion_api_get_recent_logs($limit = 5) {
        return AjaxDashboard::get_recent_logs($limit);
    }
}
