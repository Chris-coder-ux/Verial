<?php
declare(strict_types=1);

/**
 * Helper para mapear datos de productos entre Verial y WooCommerce.
 *
 * VERSIÓN CONSOLIDADA: Este archivo contiene la implementación oficial de MapProduct.
 * El archivo map-product.php es un enlace simbólico a este archivo para mantener compatibilidad.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\Config_Manager;
use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente al archivo
}

// NOTA: Preferencia de desarrollo
// Si hace falta crear un archivo nuevo para helpers de mapeo, etc., se debe crear,
// nunca mezclar código en archivos que no corresponden. Esto asegura mantenibilidad profesional.

/**
 * NOTA PARA DESARROLLADORES Y QA:
 *
 * Este archivo ha sido robustecido para validar y tipar correctamente todas las rutas críticas, especialmente en el manejo de datos provenientes de Verial y WooCommerce.
 * Sin embargo, PHPStan (nivel máximo) puede seguir reportando falsos positivos relacionados con:
 *   - Llamadas redundantes a is_array() sobre valores ya validados.
 *   - Acceso a offsets sobre mixed, aunque el código valida que el valor es array antes de operar.
 *   - Concatenaciones y castings con mixed en logs, aunque se valida el tipo antes de operar.
 *   - Casts de mixed a float, aunque se valida con is_numeric.
 *
 * En todos los casos, el código valida explícitamente los tipos antes de operar, asignando valores seguros si el tipo no es el esperado.
 * Estos avisos pueden ignorarse con seguridad mientras se mantenga esta validación defensiva.
 *
 * Para más detalles, revisar la hoja de ruta y los comentarios en phpstan-errores.md.
 */

class MapProduct {
	private static $logger;
	private static $sanitizer;

	public static function init() {
		if (!self::$logger) {
			self::$logger = new Logger();
		}
		if (!self::$sanitizer) {
			self::$sanitizer = new DataSanitizer();
		}
	}

	/**
	 * Mapea un producto de Verial a un array compatible con WooCommerce.
	 *
	 * @param array<string, mixed> $verial_product Array asociativo con los datos del producto de Verial.
	 * @param array<string, mixed> $extra_fields   Campos extra opcionales a añadir o sobrescribir en el resultado mapeado.
	 * @param array<string, mixed> $batch_cache    Caché de datos precargados para el lote (ej. mapeos de categorías).
	 * @return ProductDTO|null DTO del producto mapeado o null si los datos son inválidos
	 */
	public static function verial_to_wc(array $verial_product, array $extra_fields = array(), array $batch_cache = []): ?ProductDTO {
		self::init();

		if (!function_exists('wc_format_decimal') || !function_exists('wc_stock_amount')) {
			return null;
		}

		// Validar datos mínimos requeridos
		if (empty($verial_product['Codigo']) || empty($verial_product['Descripcion'])) {
			self::$logger->error('Producto Verial inválido: faltan campos requeridos', [
				'product' => $verial_product
			]);
			return null;
		}

		// Preparar datos básicos
		$product_data = [
			'sku' => self::$sanitizer->sanitize($verial_product['Codigo'], 'sku'),
			'name' => self::$sanitizer->sanitize($verial_product['Descripcion'], 'text'),
			'price' => self::$sanitizer->sanitize($verial_product['PVP'] ?? 0, 'price'),
			'regular_price' => self::$sanitizer->sanitize($verial_product['PVP'] ?? 0, 'price'),
			'sale_price' => self::$sanitizer->sanitize($verial_product['PVPOferta'] ?? 0, 'price'),
			'description' => self::$sanitizer->sanitize($verial_product['DescripcionLarga'] ?? '', 'html'),
			'short_description' => self::$sanitizer->sanitize($verial_product['DescripcionCorta'] ?? '', 'html'),
			'stock_quantity' => self::$sanitizer->sanitize($verial_product['Stock'] ?? 0, 'int'),
			'stock_status' => self::$sanitizer->sanitize($verial_product['Stock'] ?? 0, 'int') > 0 ? 'instock' : 'outofstock',
			'weight' => self::$sanitizer->sanitize($verial_product['Peso'] ?? 0, 'float'),
			'dimensions' => [
				'length' => self::$sanitizer->sanitize($verial_product['Longitud'] ?? 0, 'float'),
				'width' => self::$sanitizer->sanitize($verial_product['Ancho'] ?? 0, 'float'),
				'height' => self::$sanitizer->sanitize($verial_product['Alto'] ?? 0, 'float')
			],
			'status' => 'publish',
			'external_id' => (string)($verial_product['Id'] ?? ''),
			'sync_status' => 'synced',
			'last_sync' => current_time('mysql')
		];

		// Añadir campos extra si existen
		if (!empty($extra_fields)) {
			$product_data = array_merge($product_data, $extra_fields);
		}

		// Validar datos críticos
		if (!self::$sanitizer->validate($product_data['sku'], 'sku')) {
			self::$logger->error('SKU de producto inválido', [
				'sku' => $product_data['sku']
			]);
			return null;
		}

		if (!self::$sanitizer->validate($product_data['price'], 'price')) {
			self::$logger->error('Precio de producto inválido', [
				'price' => $product_data['price']
			]);
			return null;
		}

		try {
			return new ProductDTO($product_data);
		} catch (\Exception $e) {
			self::$logger->error('Error al crear ProductDTO', [
				'error' => $e->getMessage(),
				'product' => $verial_product
			]);
			return null;
		}
	}

	/**
	 * Mapea un producto de WooCommerce al formato de Verial.
	 *
	 * @param \WC_Product $wc_product Producto de WooCommerce
	 * @return array Datos del producto en formato Verial
	 */
	public static function wc_to_verial(\WC_Product $wc_product): array {
		self::init();

		if (!$wc_product instanceof \WC_Product) {
			self::$logger->error('Producto WooCommerce inválido');
			return [];
		}

		// Sanitizar datos
		$verial_product = [
			'Codigo' => self::$sanitizer->sanitize($wc_product->get_sku(), 'sku'),
			'Descripcion' => self::$sanitizer->sanitize($wc_product->get_name(), 'text'),
			'DescripcionLarga' => self::$sanitizer->sanitize($wc_product->get_description(), 'html'),
			'DescripcionCorta' => self::$sanitizer->sanitize($wc_product->get_short_description(), 'html'),
			'PVP' => self::$sanitizer->sanitize($wc_product->get_regular_price(), 'price'),
			'PVPOferta' => self::$sanitizer->sanitize($wc_product->get_sale_price(), 'price'),
			'Stock' => self::$sanitizer->sanitize($wc_product->get_stock_quantity(), 'int'),
			'Peso' => self::$sanitizer->sanitize($wc_product->get_weight(), 'float'),
			'Longitud' => self::$sanitizer->sanitize($wc_product->get_length(), 'float'),
			'Ancho' => self::$sanitizer->sanitize($wc_product->get_width(), 'float'),
			'Alto' => self::$sanitizer->sanitize($wc_product->get_height(), 'float'),
			'Categorias' => self::$sanitizer->sanitize($wc_product->get_category_ids(), 'int'),
			'Etiquetas' => self::$sanitizer->sanitize($wc_product->get_tag_ids(), 'int'),
			'Imagenes' => self::$sanitizer->sanitize($wc_product->get_gallery_image_ids(), 'int'),
			'Atributos' => self::$sanitizer->sanitize($wc_product->get_attributes(), 'text'),
			'MetaDatos' => self::$sanitizer->sanitize($wc_product->get_meta_data(), 'text')
		];

		// Validar datos críticos
		if (!self::$sanitizer->validate($verial_product['Codigo'], 'sku')) {
			self::$logger->error('SKU de producto inválido', [
				'sku' => $verial_product['Codigo']
			]);
			return [];
		}

		if (!self::$sanitizer->validate($verial_product['PVP'], 'price')) {
			self::$logger->error('Precio de producto inválido', [
				'price' => $verial_product['PVP']
			]);
			return [];
		}

		return $verial_product;
	}

	// --- EJEMPLOS DE FUNCIONES HELPER PARA MAPEOLOGÍA AVANZADA (NO IMPLEMENTADAS COMPLETAMENTE) ---

	/**
	 * Ejemplo conceptual: Obtiene o crea un ID de categoría de WooCommerce
	 * basado en un ID de categoría de Verial y un nombre.
	 * Esta función necesitaría ser llamada desde verial_to_wc.
	 *
	 * @param int    $verial_category_id ID de la categoría en Verial.
	 * @param string               $verial_category_name Nombre de la categoría en Verial (para crearla si no existe un mapeo).
	 * @param string               $taxonomy             Taxonomía de WooCommerce (ej. 'product_cat').
	 * @param array<int, int>      $category_cache       Caché de mapeos de categorías ['verial_id' => 'wc_id'].
	 * @return int|null ID del término de WooCommerce o null si falla.
	 */
	private static function get_or_create_wc_category_from_verial_id( int $verial_category_id, string $verial_category_name = '', string $taxonomy = 'product_cat', array $category_cache = [] ): ?int {
	 if ( empty( $verial_category_id ) ) {
	 	return null;
	 }

	 // 1. Buscar primero en la caché de lote.
	 if ( ! empty( $category_cache ) && isset( $category_cache[ $verial_category_id ] ) ) {
	 	return $category_cache[ $verial_category_id ];
	 }

	 // 2. Si no está en caché, buscar si ya existe un mapeo en la BD (ej. en term_meta)
	 $args  = array(
	 	'taxonomy'   => $taxonomy,
	 	'hide_empty' => false,
	 	'meta_query' => array(
	 		array(
	 			'key'     => '_verial_category_id', // Meta key para guardar el ID de Verial
	 			'value'   => $verial_category_id,
	 			'compare' => '=',
	 		),
	 	),
	 	'fields'     => 'ids', // Obtener solo IDs
	 );
	 $terms = get_terms( $args );
	 if ( ! empty( $terms ) && is_array( $terms ) && ! is_wp_error( $terms ) && isset( $terms[0] ) && is_numeric( $terms[0] ) ) {
	 	return (int) $terms[0];
	 }

		// 2. Si no hay mapeo y se proporciona un nombre, intentar crear la categoría
		if ( ! empty( $verial_category_name ) ) {
			// Verificar si ya existe una categoría con ese nombre (para evitar duplicados por nombre)
			$term_exists = term_exists( $verial_category_name, $taxonomy );
			if ( $term_exists && is_array( $term_exists ) && isset( $term_exists['term_id'] ) && is_numeric( $term_exists['term_id'] ) ) {
				// La categoría ya existe por nombre, guardar el mapeo y devolver su ID
				update_term_meta( (int) $term_exists['term_id'], '_verial_category_id', $verial_category_id );
				return (int) $term_exists['term_id'];
			}

			// Crear la nueva categoría
			$new_term_data = wp_insert_term( sanitize_text_field( $verial_category_name ), $taxonomy );
			if ( ! is_wp_error( $new_term_data ) && is_array( $new_term_data ) && isset( $new_term_data['term_id'] ) && is_numeric( $new_term_data['term_id'] ) ) {
				// Guardar el ID de Verial como metadato del término para futuro mapeo
				update_term_meta( (int) $new_term_data['term_id'], '_verial_category_id', $verial_category_id );
				if ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) ) {
					$term_id_str   = (string) $new_term_data['term_id'];
					$verial_id_str = (string) $verial_category_id;
					Logger::info( '[MapProduct] Creada nueva categoría WC ' . $verial_category_name . ' (ID: ' . $term_id_str . ') para Verial ID ' . $verial_id_str . '.', array( 'context' => 'mia-mapper' ) );
				}
				return (int) $new_term_data['term_id'];
			} elseif ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) && is_wp_error( $new_term_data ) && is_object( $new_term_data ) && method_exists( $new_term_data, 'get_error_message' ) ) {
					Logger::error( '[MapProduct] Error al crear categoría WC para ' . $verial_category_name . ': ' . $new_term_data->get_error_message(), array( 'context' => 'mia-mapper' ) );
			}
		}

		if ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) ) {
			$verial_id_str = (string) $verial_category_id;
			Logger::error( '[MapProduct] No se pudo mapear ni crear categoría WC para Verial ID ' . $verial_id_str . ' (Nombre: ' . $verial_category_name . ').', array( 'context' => 'mia-mapper' ) );
		}
		return null;
	}
}
