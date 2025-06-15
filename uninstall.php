<?php
// Desinstalación segura para WordPress
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Verificar que $wpdb esté disponible
global $wpdb;
if ( ! isset( $wpdb ) ) {
    return;
}

// Eliminar opciones del plugin
$options_to_delete = [
    'mia_numero_sesion', 'mia_url_base', 'mia_sync_clientes_enabled',
    'mia_sync_pedidos_enabled', 'mia_payment_method_mapping', 'mia_plugin_version',
    'mia_last_sync_time', 'mia_last_sync_status', 'mia_last_sync_log',
    'mia_sync_products_in_progress', 'mia_sync_products_offset', 'mia_sync_products_batch_count',
    'mia_sync_products_total_items', 'mia_sync_products_processed_count',
    'mia_sync_products_created_count', 'mia_sync_products_updated_count',
    'mia_sync_products_error_count', 'mia_sync_products_filter_desc',
    'mia_sync_products_items_to_process',
];
foreach ( $options_to_delete as $option_name ) {
    delete_option( $option_name );
}

// Eliminar transients de bloqueo y caché
$cache_prefixes_to_clean = [
    'mia_paises_', 'mia_provincias_', 'mia_localidades_', 'mia_clientes_', 'mia_articulos_',
    'mia_cursos_', 'mia_asignaturas_', 'mia_colecciones_', 'mia_fabricantes_',
    'mia_categorias_', 'mia_categorias_web_', 'mia_campos_conf_art_', 'mia_arbol_campo_conf_',
    'mia_val_val_campo_conf_', 'mia_stock_art_', 'mia_img_art_', 'mia_cond_tarifa_',
    'mia_metodos_pago_', 'mia_verial_version_', 'mia_hist_pedidos_', 'mia_next_num_docs_',
    'mia_agentes_', 'mia_formas_envio_', 'mia_sync_progress'
];
foreach ( $cache_prefixes_to_clean as $prefix ) {
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_' . $prefix . '%' ) );
    $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", '_transient_timeout_' . $prefix . '%' ) );
    // Multisitio
    if ( isset( $wpdb->sitemeta ) ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", '_site_transient_' . $prefix . '%' ) );
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s", '_site_transient_timeout_' . $prefix . '%' ) );
    }
}

// Eliminar tablas personalizadas del plugin
$tables_to_drop = [
    $wpdb->prefix . 'mi_integracion_api_logs',
    $wpdb->prefix . 'verial_product_mapping',
    $wpdb->prefix . 'mi_integracion_api_cache'
];

foreach ($tables_to_drop as $table_name) {
    $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
}

// Eliminar la vista de compatibilidad
$view_name = $wpdb->prefix . 'mia_sync_history';
$wpdb->query("DROP VIEW IF EXISTS `{$view_name}`");

// Limpiar metadatos de productos y categorías
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_verial_%'");
$wpdb->query("DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE '_verial_%'");
