<?php
/**
 * Servicio para operaciones con productos y categorías en WooCommerce.
 * Adaptado de WoocommerceProductService.php
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// NOTA: Preferencia de desarrollo
// Si hace falta crear un archivo nuevo para helpers de servicios, etc., se debe crear, nunca mezclar código en archivos que no corresponden. Esto asegura mantenibilidad profesional.

class WcProductService {
	private $wc_api;

	/**
	 * @param object $wc_api Instancia de la API de WooCommerce
	 */
	public function __construct( $wc_api ) {
		$this->wc_api = $wc_api;
	}

	/**
	 * Obtener el ID de un producto por su SKU usando funciones nativas de WooCommerce.
	 *
	 * @param string $sku
	 * @return int|false
	 */
	public function get_product_id_by_sku( $sku ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return null;
		}
		$product_id = wc_get_product_id_by_sku( $sku );
		return $product_id ? $product_id : false;
	}

	/**
	 * Obtener o crear una categoría por su nombre usando funciones nativas de WooCommerce.
	 *
	 * @param string $name
	 * @return int|false
	 */
	public function get_or_create_category( $name ) {
		if ( ! function_exists( 'get_term_by' ) ) {
			return false;
		}
		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( $term && isset( $term->term_id ) ) {
			return (int) $term->term_id;
		}
		// Crear la categoría si no existe
		$result = wp_insert_term( $name, 'product_cat' );
		if ( is_wp_error( $result ) ) {
			return false;
		}
		return isset( $result['term_id'] ) ? (int) $result['term_id'] : false;
	}
}
