<?php
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para filtrado avanzado de pedidos en batch
 * Permite filtrar pedidos por fecha, estado, cliente, importe, búsqueda, etc.
 * Uso: FilterOrders::advanced($args)
 */
class FilterOrders {
	/**
	 * Filtra pedidos de WooCommerce según criterios avanzados
	 *
	 * @param array $args Argumentos de filtrado: [
	 *   'customer_id' => int,
	 *   'status' => string|array,
	 *   'min_total' => float,
	 *   'max_total' => float,
	 *   'date_after' => string (Y-m-d),
	 *   'date_before' => string (Y-m-d),
	 *   'search' => string,
	 *   ...
	 * ]
	 * @return array IDs de pedidos que cumplen los filtros
	 */
	public static function advanced( $args = array() ) {
		$defaults   = array(
			'customer_id' => '',
			'status'      => '',
			'min_total'   => null,
			'max_total'   => null,
			'date_after'  => '',
			'date_before' => '',
			'search'      => '',
			'limit'       => 100,
			'offset'      => 0,
			'orderby'     => 'ID',
			'order'       => 'ASC',
		);
		$args       = wp_parse_args( $args, $defaults );
		$meta_query = array( 'relation' => 'AND' );
		if ( $args['min_total'] !== null ) {
			$meta_query[] = array(
				'key'     => '_order_total',
				'value'   => $args['min_total'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $args['max_total'] !== null ) {
			$meta_query[] = array(
				'key'     => '_order_total',
				'value'   => $args['max_total'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		$query_args = array(
			'post_type'      => 'shop_order',
			'post_status'    => $args['status'] ? (array) $args['status'] : array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'posts_per_page' => $args['limit'],
			'offset'         => $args['offset'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
			's'              => $args['search'],
		);
		if ( ! empty( $args['customer_id'] ) ) {
			$query_args['meta_query'][] = array(
				'key'     => '_customer_user',
				'value'   => $args['customer_id'],
				'compare' => '=',
			);
		}
		if ( ! empty( $args['date_after'] ) || ! empty( $args['date_before'] ) ) {
			$query_args['date_query'] = array();
			if ( ! empty( $args['date_after'] ) ) {
				$query_args['date_query'][] = array(
					'after'     => $args['date_after'],
					'inclusive' => true,
				);
			}
			if ( ! empty( $args['date_before'] ) ) {
				$query_args['date_query'][] = array(
					'before'    => $args['date_before'],
					'inclusive' => true,
				);
			}
		}
		$query = new \WP_Query( $query_args );
		return $query->posts;
	}
}
