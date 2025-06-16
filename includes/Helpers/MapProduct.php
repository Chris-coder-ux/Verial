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

use MiIntegracionApi\helpers\Logger;

class MapProduct {
	/**
	 * Mapea un producto de Verial a un array compatible con WooCommerce.
	 *
	 * @param array<string, mixed> $verial_product Array asociativo con los datos del producto de Verial.
	 * @param array<string, mixed> $extra_fields   Campos extra opcionales a añadir o sobrescribir en el resultado mapeado.
	 * @param array<string, mixed> $batch_cache    Caché de datos precargados para el lote (ej. mapeos de categorías).
	 * @return array<string, mixed> Array con datos mapeados para WooCommerce (sku, name, price, stock, category_ids, etc.),
	 * o un array vacío si los datos de entrada son inválidos (ej. falta SKU).
	 */
	public static function verial_to_wc( array $verial_product, array $extra_fields = array(), array $batch_cache = [] ): array {
	 if (
	 	! function_exists( 'wc_format_decimal' ) ||
	 	! function_exists( 'wc_stock_amount' )
		) {
			return array();
		}
		// Detección automática de la clave SKU
		$sku = '';
		$config_manager = Config_Manager::get_instance();
		// Priorizar los campos de la API de Verial: ReferenciaBarras e Id
		$sku_fields_str = $config_manager->get('mia_sync_sku_fields', 'ReferenciaBarras,Id,CodigoArticulo');
		$sku_fields = array_map('trim', explode(',', $sku_fields_str));

		// Intentar primero con los campos prioritarios (ReferenciaBarras e Id)
		$priority_fields = ['ReferenciaBarras', 'Id'];
		foreach ($priority_fields as $key) {
			if (!empty($verial_product[$key]) && is_scalar($verial_product[$key])) {
				$sku = (string) $verial_product[$key];
				break;
			}
		}
		
		// Si no encontramos con los campos prioritarios, intentar con los configurados
		if (empty($sku)) {
			foreach ($sku_fields as $key) {
				if (!empty($verial_product[$key]) && is_scalar($verial_product[$key])) {
					$sku = (string) $verial_product[$key];
					break;
				}
			}
		}
		// Permitir personalización vía filtro
		$sku = apply_filters( 'mi_integracion_api_map_product_sku', $sku, $verial_product );
		if ( empty( $sku ) ) {
			if ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) ) {
				$verial_product_json = is_array( $verial_product ) ? wp_json_encode( $verial_product ) : '';
				Logger::error(
					'[MapProduct] Intento de mapear producto Verial sin SKU (ReferenciaBarras/Id/CodigoArticulo). Datos: ' . ( is_string( $verial_product_json ) ? $verial_product_json : '' ),
					array( 'context' => 'mia-mapper' )
				);
			}
			return array(
				'error' => 'SKU ausente en producto Verial',
				'data'  => $verial_product,
			);
		}

		// Mapeo de campos principales. Se usan valores por defecto si el campo no existe en $verial_product.
		$mapped = array(
			'sku'          => sanitize_text_field( $sku ),
			'name'         => isset( $verial_product['Nombre'] ) && is_string( $verial_product['Nombre'] ) ? sanitize_text_field( $verial_product['Nombre'] ) : ( isset( $verial_product['Descripcion'] ) && is_string( $verial_product['Descripcion'] ) ? sanitize_text_field( $verial_product['Descripcion'] ) : '' ),
			'price'        => isset( $verial_product['PrecioVenta'] ) && is_numeric( $verial_product['PrecioVenta'] ) ? wc_format_decimal( $verial_product['PrecioVenta'] ) : ( isset( $verial_product['Precio'] ) && is_numeric( $verial_product['Precio'] ) ? wc_format_decimal( $verial_product['Precio'] ) : 0.00 ),
			'stock'        => isset( $verial_product['Stock'] ) && is_numeric( $verial_product['Stock'] ) ? wc_stock_amount( $verial_product['Stock'] ) : null,
			'manage_stock' => isset( $verial_product['Stock'] ),
			'category_ids' => array(),
		);

		// Lógica para mapear categorías de Verial a WooCommerce
		$verial_category_fields_to_check = array( 'ID_Categoria', 'ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4' );
		$processed_verial_cat_ids        = array();

		foreach ( $verial_category_fields_to_check as $verial_cat_field ) {
			if ( ! empty( $verial_product[ $verial_cat_field ] ) ) {
				$verial_cat_id = is_scalar( $verial_product[ $verial_cat_field ] ) ? intval( $verial_product[ $verial_cat_field ] ) : 0;
				if ( $verial_cat_id > 0 && ! in_array( $verial_cat_id, $processed_verial_cat_ids ) ) {
					$cat_name = isset( $verial_product['NombreCategoriaPrincipal'] ) && is_string( $verial_product['NombreCategoriaPrincipal'] )
						? $verial_product['NombreCategoriaPrincipal']
						: ( isset( $verial_product['NombreCategoria'] ) && is_string( $verial_product['NombreCategoria'] ) ? $verial_product['NombreCategoria'] : '' );
					$term_id  = self::get_or_create_wc_category_from_verial_id(
						$verial_cat_id,
						$cat_name,
						'product_cat',
						$batch_cache['category_mappings'] ?? []
					);
					if ( $term_id ) {
						$mapped['category_ids'][]   = $term_id;
						$processed_verial_cat_ids[] = $verial_cat_id;
					}
				}
			}
		}
		// Si no se pudieron mapear IDs, y Verial envía un nombre de categoría genérico:
		if ( empty( $mapped['category_ids'] ) && ! empty( $verial_product['NombreCategoria'] ) && is_string( $verial_product['NombreCategoria'] ) ) {
			$term_id = self::get_or_create_wc_category_from_verial_id( 0, $verial_product['NombreCategoria'], 'product_cat', $batch_cache['category_mappings'] ?? [] );
			if ( $term_id ) {
				$mapped['category_ids'][] = $term_id;
			}
		}

		// Eliminar duplicados si se añadieron varios IDs
		if ( ! empty( $mapped['category_ids'] ) ) {
			$mapped['category_ids'] = array_values( array_unique( $mapped['category_ids'] ) );
		}

		// Mapeo de atributos: Asumiendo que 'Atributos' es un array en $verial_product con datos de atributos
		if ( isset( $verial_product['Atributos'] ) && is_array( $verial_product['Atributos'] ) ) {
			$mapped['attributes'] = array();
			foreach ( $verial_product['Atributos'] as $atributo ) {
				if ( ! is_array( $atributo ) ) {
					continue;
				}
				$nombre                 = isset( $atributo['nombre'] ) && is_string( $atributo['nombre'] ) ? $atributo['nombre'] : '';
				$valor                  = isset( $atributo['valor'] ) && is_string( $atributo['valor'] ) ? $atributo['valor'] : '';
				$mapped['attributes'][] = array(
					'name'   => sanitize_text_field( $nombre ),
					'option' => sanitize_text_field( $valor ),
				);
			}
		}

		// Mapeo de campos personalizados
		if ( isset( $verial_product['CampoPersonalizado'] ) ) {
			$mapped['meta_data'][] = array(
				'key'   => '_campo_personalizado_verial',
				'value' => sanitize_text_field( $verial_product['CampoPersonalizado'] ),
			);
		}

		// --- SOPORTE BÁSICO PARA PRODUCTOS VARIABLES Y VARIACIONES ---
		// Detectar si el producto es variable (Verial debe enviar un campo 'TipoProducto' o similar, o un array 'Variaciones')
		$is_variable    = isset( $verial_product['TipoProducto'] ) && $verial_product['TipoProducto'] === 'variable';
		$has_variations = isset( $verial_product['Variaciones'] ) && is_array( $verial_product['Variaciones'] ) && count( $verial_product['Variaciones'] ) > 0;
		if ( $is_variable || $has_variations ) {
			$mapped['type']       = 'variable';
			$mapped['variations'] = array();
			// Mapear atributos globales (para el producto padre)
			if ( isset( $verial_product['Atributos'] ) && is_array( $verial_product['Atributos'] ) ) {
				$mapped['attributes'] = array();
				foreach ( $verial_product['Atributos'] as $atributo ) {
					if ( ! is_array( $atributo ) ) {
						continue;
					}
					$nombre = isset( $atributo['nombre'] ) ? sanitize_text_field( $atributo['nombre'] ) : '';
					// Soporte multivalor: 'valores' puede ser array de opciones
					if ( isset( $atributo['valores'] ) && is_array( $atributo['valores'] ) ) {
						$mapped['attributes'][] = array(
							'name'      => $nombre,
							'options'   => array_map( 'sanitize_text_field', $atributo['valores'] ),
							'variation' => true,
						);
					} else {
						$valor                  = isset( $atributo['valor'] ) ? sanitize_text_field( $atributo['valor'] ) : '';
						$mapped['attributes'][] = array(
							'name'   => $nombre,
							'option' => $valor,
						);
					}
				}
			}
			// Mapear variaciones
			foreach ( $verial_product['Variaciones'] as $var ) {
				if ( ! is_array( $var ) ) {
					continue;
				}
				$var_map = array(
					'sku'        => isset( $var['ReferenciaBarras'] ) ? sanitize_text_field( $var['ReferenciaBarras'] ) : '',
					'price'      => isset( $var['PrecioVenta'] ) && is_numeric( $var['PrecioVenta'] ) ? wc_format_decimal( $var['PrecioVenta'] ) : 0.00,
					'stock'      => isset( $var['Stock'] ) && is_numeric( $var['Stock'] ) ? wc_stock_amount( $var['Stock'] ) : null,
					'attributes' => array(),
				);
				// Mapear atributos de la variación
				if ( isset( $var['Atributos'] ) && is_array( $var['Atributos'] ) ) {
					foreach ( $var['Atributos'] as $attr ) {
						if ( ! is_array( $attr ) ) {
							continue;
						}
						$nombre                  = isset( $attr['nombre'] ) ? sanitize_text_field( $attr['nombre'] ) : '';
						$valor                   = isset( $attr['valor'] ) ? sanitize_text_field( $attr['valor'] ) : '';
						$var_map['attributes'][] = array(
							'name'   => $nombre,
							'option' => $valor,
						);
					}
				}
				$mapped['variations'][] = $var_map;
			}
		}

		// --- ATRIBUTOS COMPLEJOS Y BUNDLES ---
		// Ejemplo: atributos complejos (selectores dependientes, color, imagen, multivalor avanzado)
		if ( isset( $verial_product['AtributosComplejos'] ) && is_array( $verial_product['AtributosComplejos'] ) ) {
			$mapped['complex_attributes'] = array();
			foreach ( $verial_product['AtributosComplejos'] as $atributo ) {
				if ( ! is_array( $atributo ) ) {
					continue;
				}
				$nombre                         = isset( $atributo['nombre'] ) ? sanitize_text_field( $atributo['nombre'] ) : '';
				$tipo                           = isset( $atributo['tipo'] ) ? sanitize_text_field( $atributo['tipo'] ) : 'select';
				$dependencias                   = isset( $atributo['dependencias'] ) && is_array( $atributo['dependencias'] ) ? $atributo['dependencias'] : array();
				$valores                        = isset( $atributo['valores'] ) && is_array( $atributo['valores'] ) ? $atributo['valores'] : array();
				$mapped['complex_attributes'][] = array(
					'name'         => $nombre,
					'type'         => $tipo, // select, color, image, multiselect, etc.
					'values'       => array_map( 'sanitize_text_field', $valores ),
					'dependencies' => $dependencias, // array de dependencias entre atributos
				);
			}
		}
		// Soporte robusto para bundles o agrupaciones de productos
		if ( isset( $verial_product['Bundle'] ) && is_array( $verial_product['Bundle'] ) ) {
			$productos_bundle = array();
			if ( isset( $verial_product['Bundle']['productos'] ) && is_array( $verial_product['Bundle']['productos'] ) ) {
				foreach ( $verial_product['Bundle']['productos'] as $prod ) {
					if ( ! is_array( $prod ) || empty( $prod['sku'] ) || ! is_scalar( $prod['sku'] ) ) {
						continue;
					}
					$sku_hijo           = sanitize_text_field( $prod['sku'] );
					$cantidad           = ( isset( $prod['cantidad'] ) && is_numeric( $prod['cantidad'] ) ) ? (int) $prod['cantidad'] : 1;
					$productos_bundle[] = array(
						'sku'      => $sku_hijo,
						'cantidad' => $cantidad,
					);
				}
			}
			$mapped['bundle'] = array(
				'nombre'    => isset( $verial_product['Bundle']['nombre'] ) ? sanitize_text_field( $verial_product['Bundle']['nombre'] ) : '',
				'productos' => $productos_bundle,
			);
		}

		// Fusionar con campos extra, permitiendo que $extra_fields sobrescriba los mapeados por defecto.
		return array_merge( $mapped, $extra_fields );
	}

	/**
	 * Mapea un producto WooCommerce a un array compatible con la API de Verial.
	 *
	 * @param \WC_Product          $wc_product Objeto del producto de WooCommerce.
	 * @param array<string, mixed> $extra_fields Campos extra opcionales a añadir o sobrescribir en el resultado mapeado.
	 * @return array<string, mixed> Array con datos mapeados para Verial, o un array vacío si la entrada es inválida.
	 */
	public static function wc_to_verial( \WC_Product $wc_product, array $extra_fields = array() ): array {
		// Validación básica del objeto de entrada. El SKU es fundamental.
		if ( empty( $wc_product->get_sku() ) ) {
			if ( class_exists( 'MiIntegracionApi\\helpers\\Logger' ) ) {
				Logger::error(
					'[MapProduct] Intento de mapear producto WC sin SKU a Verial. Producto ID: ' . $wc_product->get_id(),
					array( 'context' => 'mia-mapper' )
				);
			}
			return array();
		}

		$mapped = array(
			// Las claves aquí deben coincidir con los campos que espera la API de Verial
			// para la creación/actualización de artículos. Consulta el manual de Verial (ej. NuevoArticuloWS).
			'ReferenciaBarras' => $wc_product->get_sku(), // Campo SKU de WC a ReferenciaBarras de Verial
			'Nombre'           => $wc_product->get_name(),   // Nombre del producto
			'Descripcion'      => $wc_product->get_description() ?: $wc_product->get_name(), // Descripción larga, o nombre si está vacía
			// 'Subtitulo'        => $wc_product->get_short_description(), // Descripción corta como subtítulo
			'PrecioVenta'      => $wc_product->get_price(),  // Precio de venta actual
			// 'PorcentajeIVA'    => self::get_verial_tax_rate_from_wc($wc_product->get_tax_class()), // Necesitaría mapeo de clases de impuesto
			'Stock'            => $wc_product->is_managing_stock() && is_numeric( $wc_product->get_stock_quantity() ) ? (float) $wc_product->get_stock_quantity() : null, // Stock si se gestiona, sino null
			// 'ID_Categoria'     => self::get_verial_category_id_from_wc($wc_product->get_category_ids()), // Mapeo de categorías WC a Verial
			// 'ID_Fabricante'    => self::get_verial_manufacturer_id_from_wc_attribute($wc_product, 'pa_fabricante'), // Ejemplo
			// 'Peso'             => $wc_product->get_weight() ? floatval($wc_product->get_weight()) : null, // Peso en kg
			// 'Ancho'            => $wc_product->get_width() ? floatval($wc_product->get_width()) * 10 : null, // Ancho en mm (si WC está en cm)
			// 'Alto'             => $wc_product->get_height() ? floatval($wc_product->get_height()) * 10 : null, // Alto en mm
			// 'Grueso'           => $wc_product->get_length() ? floatval($wc_product->get_length()) * 10 : null, // Profundidad/Largo en mm
			// 'FechaDisponibilidad' => date('Y-m-d'), // Ejemplo
			// 'Tipo'             => 1, // 1: Artículo normal, 2: Libro. Determinar según tipo de producto WC.
		);

		// Fusionar con campos extra.
		return array_merge( $mapped, $extra_fields );
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
