<?php
/**
 * Compatibilidad con HPOS (High Performance Order Storage) de WooCommerce
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;



// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar la compatibilidad con HPOS de WooCommerce
 */
class HposCompatibility {
	/**
	 * Inicializa la compatibilidad con HPOS
	 */
	public static function init() {
		// Registrar compatibilidad con HPOS
		add_action( 'before_woocommerce_init', array( self::class, 'declare_hpos_compatibility' ) );

		// Hooks para manejo de datos con HPOS
		add_action( 'woocommerce_order_object_updated_props', array( self::class, 'on_order_updated' ), 10, 2 );
	}

	/**
	 * Declara la compatibilidad con HPOS de WooCommerce
	 */
	public static function declare_hpos_compatibility() {
		// Verificar que la clase de features exista (WooCommerce 6.0+)
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			// Declarar compatibilidad con HPOS
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MiIntegracionApi_PLUGIN_FILE,
				true
			);

			// Declarar compatibilidad con el nuevo sistema de gestión de stock
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'product_block_editor',
				MiIntegracionApi_PLUGIN_FILE,
				true
			);
		}
	}

	/**
	 * Determina si HPOS está activo
	 *
	 * @return bool True si HPOS está activo
	 */
	public static function is_hpos_active() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Obtiene el ID de un pedido de forma compatible con HPOS
	 *
	 * @param WC_Order|mixed $order Objeto de pedido
	 * @return int|string ID del pedido
	 */
	public static function get_order_id( $order ) {
		if ( ! is_object( $order ) ) {
			return 0;
		}

		if ( self::is_hpos_active() ) {
			return $order->get_id();
		} else {
			// Fallback al método tradicional
			return method_exists( $order, 'get_id' ) ? $order->get_id() : $order->id;
		}
	}

	/**
	 * Obtiene datos de un pedido de forma compatible con HPOS
	 *
	 * @param int|WC_Order $order_id ID del pedido o objeto de pedido
	 * @return WC_Order|null Objeto de pedido o null si no existe
	 */
	public static function get_order( $order_id ) {
		if ( is_object( $order_id ) ) {
			return $order_id;
		}

		if ( self::is_hpos_active() ) {
			try {
				return wc_get_order( (int) $order_id );
			} catch ( Exception $e ) {
				return null;
			}
		} else {
			// Método tradicional
			return wc_get_order( $order_id );
		}
	}

	/**
	 * Actualiza metadatos de un pedido de forma compatible con HPOS
	 *
	 * @param int|WC_Order $order_id ID del pedido o objeto de pedido
	 * @param string       $meta_key Clave del metadato
	 * @param mixed        $meta_value Valor del metadato
	 * @return bool|int True/ID del metadato si se actualizó, false si hubo error
	 */
	public static function update_order_meta( $order_id, $meta_key, $meta_value ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		// HPOS utiliza métodos nativos de WC_Order
		$order->update_meta_data( $meta_key, $meta_value );
		return $order->save() ? true : false;
	}

	/**
	 * Obtiene metadatos de un pedido de forma compatible con HPOS
	 *
	 * @param int|WC_Order $order_id ID del pedido o objeto de pedido
	 * @param string       $meta_key Clave del metadato
	 * @param bool         $single Devolver valor único o array
	 * @return mixed Valor del metadato
	 */
	public static function get_order_meta( $order_id, $meta_key, $single = true ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return $single ? '' : array();
		}

		return $order->get_meta( $meta_key, $single );
	}

	/**
	 * Elimina metadatos de un pedido de forma compatible con HPOS
	 *
	 * @param int|WC_Order $order_id ID del pedido o objeto de pedido
	 * @param string       $meta_key Clave del metadato
	 * @return bool True si se eliminó, false si hubo error
	 */
	public static function delete_order_meta( $order_id, $meta_key ) {
		$order = self::get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$order->delete_meta_data( $meta_key );
		return $order->save() ? true : false;
	}

	/**
	 * Hook para cuando se actualiza un pedido
	 *
	 * @param WC_Order $order Objeto de pedido
	 * @param array    $updated_props Propiedades actualizadas
	 */
	public static function on_order_updated( $order, $updated_props ) {
		// Ejemplo de sincronización cuando cambia un pedido
		if ( in_array( 'status', $updated_props, true ) ) {
			// Disparar evento para nuestro sistema cuando cambia el estado
			do_action( 'mi_integracion_api_order_status_changed', $order );
		}
	}

	/**
	 * Maneja de forma segura los metadatos de pedidos para compatibilidad con HPOS
	 * Esta función actúa como un wrapper para métodos compatibles con HPOS
	 *
	 * @param string       $action La acción a realizar: 'get', 'update', 'delete'
	 * @param int|WC_Order $order_id ID u objeto del pedido
	 * @param string       $meta_key La clave del metadato
	 * @param mixed        $meta_value El valor a almacenar (solo para update)
	 * @param bool         $single Si se debe devolver un único valor (solo para get)
	 * @return mixed El resultado de la operación
	 */
	public static function manage_order_meta( $action, $order_id, $meta_key, $meta_value = null, $single = true ) {
		switch ( $action ) {
			case 'get':
				return self::get_order_meta( $order_id, $meta_key, $single );
			case 'update':
				return self::update_order_meta( $order_id, $meta_key, $meta_value );
			case 'delete':
				return self::delete_order_meta( $order_id, $meta_key );
			default:
				return null;
		}
	}
}
