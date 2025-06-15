<?php
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para filtrado avanzado de productos en batch
 * Permite filtrar productos por precio, stock, búsqueda por nombre/SKU, categoría, estado, etc.
 * Uso: FilterProducts::advanced($args)
 */
class FilterProducts {
	/**
	 * Filtra productos de WooCommerce según criterios avanzados
	 *
	 * @param array $args Argumentos de filtrado: [
	 *   'min_price' => float,
	 *   'max_price' => float,
	 *   'min_stock' => int,
	 *   'max_stock' => int,
	 *   'search' => string,
	 *   'category' => int|array,
	 *   'status' => string|array,
	 *   ...
	 * ]
	 * @return array IDs de productos que cumplen los filtros
	 */
	public static function advanced( $args = array() ) {
		$defaults = array(
			'min_price' => null,
			'max_price' => null,
			'min_stock' => null,
			'max_stock' => null,
			'search'    => '',
			'category'  => '',
			'status'    => array( 'publish' ),
			'limit'     => 100,
			'offset'    => 0,
			'orderby'   => 'ID',
			'order'     => 'ASC',
		);
		$args     = wp_parse_args( $args, $defaults );

		$meta_query = array( 'relation' => 'AND' );
		if ( $args['min_price'] !== null ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => $args['min_price'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $args['max_price'] !== null ) {
			$meta_query[] = array(
				'key'     => '_price',
				'value'   => $args['max_price'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $args['min_stock'] !== null ) {
			$meta_query[] = array(
				'key'     => '_stock',
				'value'   => $args['min_stock'],
				'compare' => '>=',
				'type'    => 'NUMERIC',
			);
		}
		if ( $args['max_stock'] !== null ) {
			$meta_query[] = array(
				'key'     => '_stock',
				'value'   => $args['max_stock'],
				'compare' => '<=',
				'type'    => 'NUMERIC',
			);
		}
		$tax_query = array();
		if ( ! empty( $args['category'] ) ) {
			$tax_query[] = array(
				'taxonomy' => 'product_cat',
				'field'    => is_array( $args['category'] ) ? 'term_id' : 'term_id',
				'terms'    => (array) $args['category'],
			);
		}
		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => $args['status'],
			'posts_per_page' => $args['limit'],
			'offset'         => $args['offset'],
			'orderby'        => $args['orderby'],
			'order'          => $args['order'],
			'fields'         => 'ids',
			'meta_query'     => $meta_query,
			'tax_query'      => $tax_query,
			's'              => $args['search'],
		);
		$query      = new \WP_Query( $query_args );
		return $query->posts;
	}
}
