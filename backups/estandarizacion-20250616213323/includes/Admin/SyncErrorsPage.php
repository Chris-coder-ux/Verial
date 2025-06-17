<?php
declare(strict_types=1);

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

use MiIntegracionApi\Core\Installer;
use WP_List_Table;

/**
 * Gestiona la visualización de la página de errores de sincronización.
 */
class SyncErrorsPage {

    /**
     * Renderiza la página de errores de sincronización.
     */
    public static function render(): void {
        // Asegurarse de que la tabla de errores exista antes de usarla.
        global $wpdb;
        $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table_name ) ) !== $table_name ) {
            Installer::activate();
        }
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Registro de Errores de Sincronización', 'mi-integracion-api' ); ?></h1>
            <p><?php esc_html_e( 'Aquí se muestran los errores ocurridos durante los procesos de sincronización.', 'mi-integracion-api' ); ?></p>
            
            <form method="post">
                <?php
                $errors_list_table = new Sync_Errors_List_Table();
                $errors_list_table->prepare_items();
                $errors_list_table->display();
                ?>
            </form>
        </div>
        <?php
    }
}

/**
 * Clase para mostrar la tabla de errores de sincronización.
 */
class Sync_Errors_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct( [
            'singular' => __( 'Error de Sincronización', 'mi-integracion-api' ),
            'plural'   => __( 'Errores de Sincronización', 'mi-integracion-api' ),
            'ajax'     => false
        ] );
    }

    public function get_columns(): array {
        return [
            'cb'            => '<input type="checkbox" />',
            'item_sku'      => __( 'SKU del Producto', 'mi-integracion-api' ),
            'error_message' => __( 'Mensaje de Error', 'mi-integracion-api' ),
            'timestamp'     => __( 'Fecha', 'mi-integracion-api' ),
            'sync_run_id'   => __( 'ID de Sincronización', 'mi-integracion-api' ),
        ];
    }

    public function prepare_items(): void {
        global $wpdb;

        $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
        $per_page = 20;
        $current_page = $this->get_pagenum();
        $total_items = $wpdb->get_var( "SELECT COUNT(id) FROM {$table_name}" );

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page
        ] );

        $offset = ( $current_page - 1 ) * $per_page;
        $this->items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} ORDER BY timestamp DESC LIMIT %d OFFSET %d",
                $per_page,
                $offset
            ),
            ARRAY_A
        );

        $this->_column_headers = [ $this->get_columns(), [], [] ];
    }

    public function column_default( $item, $column_name ) {
        return $item[ $column_name ] ?? '';
    }

    public function column_cb( $item ): string {
        return sprintf( '<input type="checkbox" name="error[]" value="%s" />', $item['id'] );
    }

    public function get_bulk_actions(): array {
        return [
            'retry' => __( 'Reintentar Sincronización', 'mi-integracion-api' ),
            'delete' => __( 'Eliminar', 'mi-integracion-api' ),
        ];
    }

    public function process_bulk_action(): void {
        $ids = isset( $_REQUEST['error'] ) ? array_map( 'intval', $_REQUEST['error'] ) : [];

        if ( 'delete' === $this->current_action() ) {
            if ( ! empty( $ids ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . Installer::SYNC_ERRORS_TABLE;
                $ids_placeholder = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table_name} WHERE id IN ($ids_placeholder)", $ids ) );
                
                add_settings_error(
                    'sync-errors',
                    'sync-errors-deleted',
                    __( 'Los errores seleccionados han sido eliminados.', 'mi-integracion-api' ),
                    'updated'
                );
            }
        }

        if ( 'retry' === $this->current_action() ) {
            // La lógica de reintento se manejará a través de un endpoint REST y AJAX.
            // Aquí solo preparamos los datos para el script.
            wp_enqueue_script( 'mi-integracion-api-sync-errors' );
            wp_localize_script( 'mi-integracion-api-sync-errors', 'syncErrors', [
                'nonce' => wp_create_nonce( 'wp_rest' ),
                'retry_url' => esc_url_raw( rest_url( 'mi-integracion-api/v1/sync/retry' ) ),
                'error_ids' => $ids,
            ] );

            add_settings_error(
                'sync-errors',
                'sync-errors-retrying',
                __( 'Iniciando reintento de sincronización para los errores seleccionados...', 'mi-integracion-api' ),
                'info'
            );
        }
    }
}