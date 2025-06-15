<?php
declare(strict_types=1);

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Gestiona las tareas de instalación y activación del plugin.
 *
 * @package MiIntegracionApi\Core
 * @since   1.1.0
 */
class Installer {

    /**
     * El nombre de la tabla para los errores de sincronización.
     *
     * @var string
     */
    const SYNC_ERRORS_TABLE = 'mi_api_sync_errors';

    /**
     * Callback para el hook de activación.
     *
     * Crea las tablas de base de datos necesarias para el plugin.
     *
     * @return void
     */
    public static function activate(): void {
        global $wpdb;

        $table_name      = $wpdb->prefix . self::SYNC_ERRORS_TABLE;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            sync_run_id VARCHAR(100) NOT NULL,
            item_sku VARCHAR(100) NOT NULL,
            item_data LONGTEXT NOT NULL,
            error_code VARCHAR(50) NOT NULL,
            error_message TEXT NOT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY sync_run_id (sync_run_id),
            KEY item_sku (item_sku)
        ) {$charset_collate};";

        if ( ! function_exists( 'dbDelta' ) ) {
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        }
        
        dbDelta( $sql );
    }
}
