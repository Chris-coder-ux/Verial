<?php

namespace MiIntegracionApi\Hooks;

/**
 * Ganchos de sincronización para Mi Integración API
 *
 * Define los filtros y acciones relacionados con la sincronización
 * de productos, clientes y pedidos.
 *
 * @package MiIntegracionApi
 * @subpackage Hooks
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SyncHooks {
	public static function register_hooks() {
		add_filter(
			'mi_integracion_api_map_product_data',
			[self::class, 'map_product_data'],
			10,
			2
		);
		add_filter(
			'mi_integracion_api_map_customer_data',
			[self::class, 'map_customer_data'],
			10,
			2
		);
		add_filter(
			'mi_integracion_api_map_order_data',
			[self::class, 'map_order_data'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_product',
			[self::class, 'after_sync_product'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_customer',
			[self::class, 'after_sync_customer'],
			10,
			2
		);
		add_action(
			'mi_integracion_api_after_sync_order',
			[self::class, 'after_sync_order'],
			10,
			2
		);
	}

	public static function map_product_data($data, $wc_product) {
		return $data;
	}
	public static function map_customer_data($data, $user) {
		return $data;
	}
	public static function map_order_data($data, $order) {
		return $data;
	}
	public static function after_sync_product($wc_product_id, $verial_response) {
		// ...
	}
	public static function after_sync_customer($user_id, $verial_response) {
		// ...
	}
	public static function after_sync_order($order_id, $verial_response) {
		// ...
	}
}

// Registrar los hooks al cargar el archivo
SyncHooks::register_hooks();
