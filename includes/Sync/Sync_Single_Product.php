<?php
/**
 * Sincronización manual de un solo producto/artículo desde Verial a WooCommerce por SKU o nombre.
 * Archivo separado para mantener la lógica desacoplada y profesional.
 *
 * @package MiIntegracionApi\Sync
 */

namespace MiIntegracionApi\Sync;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Sync_Single_Product {
	/**
	 * Sincroniza un solo producto desde Verial a WooCommerce por SKU o nombre.
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector El conector de API
	 * @param string                              $sku SKU del producto a sincronizar
	 * @param string                              $nombre Nombre del producto a sincronizar
	 * @return array<string, mixed> Resultado de la sincronización con claves 'success' y 'message'
	 */
	public static function sync( \MiIntegracionApi\Core\ApiConnector $api_connector, string $sku = '', string $nombre = '' ): array {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return array(
				'success' => false,
				'message' => 'WooCommerce no está activo.',
			);
		}
		if ( ! MI_Sync_Lock::acquire() ) {
			return array(
				'success' => false,
				'message' => __( 'Ya hay una sincronización en curso.', 'mi-integracion-api' ),
			);
		}
		try {
			/**
			 * Obtener artículos de la API
			 */
			$productos = $api_connector->get_articulos();
			/** @phpstan-ignore-next-line */
			if ( is_wp_error( $productos ) ) {
				/** @phpstan-ignore-next-line */
				$error_message = $productos->get_error_message();
				$error_message = is_string( $error_message ) ? $error_message : 'Error desconocido';
				return array(
					'success' => false,
					'message' => $error_message,
				);
			}
			/** @phpstan-ignore-next-line */
			if ( ! is_array( $productos ) || empty( $productos ) ) {
				return array(
					'success' => false,
					'message' => __( 'No se obtuvieron productos de Verial.', 'mi-integracion-api' ),
				);
			}
			/** @var array<string, mixed>|null $producto */
			$producto = null;
			/** @var array<string, mixed> $p */
			foreach ( $productos as $p ) {
				if ( $sku && isset( $p['Referencia'] ) && is_string( $p['Referencia'] ) && $p['Referencia'] === $sku ) {
					$producto = $p;
					break;
				}
				if ( $nombre && isset( $p['Nombre'] ) && is_string( $p['Nombre'] ) && stripos( $p['Nombre'], $nombre ) !== false ) {
					$producto = $p;
					break;
				}
			}
			if ( ! $producto ) {
				$error_message = __( 'No se encontró el producto solicitado.', 'mi-integracion-api' );
				$error_message = is_string( $error_message ) ? $error_message : 'No se encontró el producto solicitado.';
				return array(
					'success' => false,
					'message' => $error_message,
				);
			}
			/** @var array<string, mixed> $wc_data */
			$wc_data = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc( $producto );
			if ( isset( $wc_data['type'] ) && $wc_data['type'] === 'variable' ) {
				// Buscar producto existente por SKU
				$wc_product_id = function_exists( 'wc_get_product_id_by_sku' ) && isset( $wc_data['sku'] ) && is_string( $wc_data['sku'] )
					? wc_get_product_id_by_sku( $wc_data['sku'] )
					: 0;

				$parent_id = $wc_product_id;
				if ( ! $parent_id ) {
					if ( class_exists( 'WC_Product_Variable' ) ) {
						/** @var \WC_Product_Variable $parent */
						$parent = new \WC_Product_Variable();
						$sku    = isset( $wc_data['sku'] ) && ( is_string( $wc_data['sku'] ) || is_int( $wc_data['sku'] ) )
							? (string) $wc_data['sku']
							: '';
						$parent->set_sku( $sku );

						$default_name = __( 'Nuevo Producto Variable', 'mi-integracion-api' );
						$default_name = is_string( $default_name ) ? $default_name : 'Nuevo Producto Variable';
						$name         = isset( $wc_data['name'] ) && is_string( $wc_data['name'] ) ? $wc_data['name'] : $default_name;

						$parent->set_name( $name );
						$parent->set_status( 'publish' );
						$parent->set_catalog_visibility( 'visible' );
						$parent_id = $parent->save();
					} else {
						return array(
							'success' => false,
							'message' => __( 'No se pudo crear producto variable: clase WC_Product_Variable no encontrada.', 'mi-integracion-api' ),
						);
					}
				} else {
					/** @var \WC_Product|false $parent_product */
					$parent_product = function_exists( 'wc_get_product' ) ? wc_get_product( $parent_id ) : false;

					if ( ! $parent_product ) {
						/** @var \WC_Product_Variable $parent */
						$parent = new \WC_Product_Variable();
					} else {
						$product_type = method_exists( $parent_product, 'get_type' ) ? $parent_product->get_type() : '';
						if ( $product_type !== 'variable' ) {
							$parent = new \WC_Product_Variable( $parent_id );
						} else {
							/** @var \WC_Product_Variable $parent */
							$parent = $parent_product;
						}
					}
				}
				// Asignar atributos globales
				if ( isset( $wc_data['attributes'] ) && is_array( $wc_data['attributes'] ) ) {
					/** @var \WC_Product_Attribute[] $attributes */
					$attributes = array();
					/** @var array<string, mixed> $attr */
					foreach ( $wc_data['attributes'] as $attr ) {
						if ( ! isset( $attr['name'] ) || ! is_string( $attr['name'] ) ) {
							continue;
						}
						/** @phpstan-ignore-next-line */
						$taxonomy = 'pa_' . sanitize_title( $attr['name'] );
						/** @var \WC_Product_Attribute $attr_obj */
						$attr_obj = new \WC_Product_Attribute();
						$attr_obj->set_name( $taxonomy );

						$options = isset( $attr['options'] ) && is_array( $attr['options'] )
							? $attr['options']
							: array();

						$attr_obj->set_options( $options );
						$attr_obj->set_visible( true );
						$attr_obj->set_variation( true );
						$attributes[] = $attr_obj;
					}
					$parent->set_attributes( $attributes );
				}
				// Guardar atributos complejos y bundle en meta datos del producto padre
				if ( isset( $wc_data['complex_attributes'] ) && is_array( $wc_data['complex_attributes'] ) ) {
					$complex_json = wp_json_encode( $wc_data['complex_attributes'] );
					if ( is_string( $complex_json ) ) {
						$parent->update_meta_data( '_verial_complex_attributes', $complex_json );
					}
				}
				if ( isset( $wc_data['bundle'] ) && is_array( $wc_data['bundle'] ) ) {
					$bundle_json = wp_json_encode( $wc_data['bundle'] );
					if ( is_string( $bundle_json ) ) {
						$parent->update_meta_data( '_verial_bundle', $bundle_json );
					}
				}
				$parent->save();
				// Crear/actualizar variaciones
				if ( isset( $wc_data['variations'] ) && is_array( $wc_data['variations'] ) ) {
					/** @var array<string, mixed> $var */
					foreach ( $wc_data['variations'] as $var ) {
						if ( ! isset( $var['sku'] ) || ! is_scalar( $var['sku'] ) ) {
							continue;
						}

						$sku_value = (string) $var['sku'];

						$args = array(
							'post_type'   => 'product_variation',
							'post_status' => 'any',
							'numberposts' => 1,
							'meta_key'    => '_sku',
							'meta_value'  => $sku_value,
							'post_parent' => $parent_id,
						);

						/** @var \WP_Post[]|\WP_Error|null $existing */
						$existing = get_posts( $args );

						/** @var \WC_Product_Variation $variation */
						if ( is_array( $existing ) && ! empty( $existing ) && isset( $existing[0]->ID ) ) {
							$variation = new \WC_Product_Variation( $existing[0]->ID );
						} else {
							$variation = new \WC_Product_Variation();
							$variation->set_parent_id( $parent_id );
							$variation->set_sku( $sku_value );
						}

						// Establecer precio
						if ( isset( $var['price'] ) && is_scalar( $var['price'] ) ) {
							$variation->set_regular_price( (string) $var['price'] );
						}

						// Establecer stock
						if ( isset( $var['stock'] ) && is_scalar( $var['stock'] ) ) {
							$variation->set_stock_quantity( (float) $var['stock'] );
						}

						$variation->set_manage_stock( true );

						// Establecer atributos
						if ( isset( $var['attributes'] ) && is_array( $var['attributes'] ) ) {
							$var_attrs = array();
							/** @var array<string, mixed> $attr */
							foreach ( $var['attributes'] as $attr ) {
								if ( isset( $attr['name'] ) && is_string( $attr['name'] ) &&
									isset( $attr['option'] ) && is_scalar( $attr['option'] ) ) {
									/** @phpstan-ignore-next-line */
									$taxonomy               = 'pa_' . sanitize_title( $attr['name'] );
									$var_attrs[ $taxonomy ] = (string) $attr['option'];
								}
							}
							$variation->set_attributes( $var_attrs );
						}

						$variation->save();
					}
				}
				$template = __( 'Producto variable sincronizado: %s', 'mi-integracion-api' );
				$template = is_string( $template ) ? $template : 'Producto variable sincronizado: %s';

				$sku = isset( $wc_data['sku'] ) && is_scalar( $wc_data['sku'] ) ? (string) $wc_data['sku'] : '';
				$msg = sprintf( $template, $sku );

				return array(
					'success' => true,
					'message' => $msg,
				);
			}
			$sku           = isset( $wc_data['sku'] ) && is_scalar( $wc_data['sku'] ) ? (string) $wc_data['sku'] : '';
			$wc_product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;

			if ( $wc_product_id ) {
				/** @var \WC_Product|false $wc_product */
				$wc_product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_product_id ) : null;

				if ( $wc_product ) {
					// Establecer nombre
					if ( isset( $wc_data['name'] ) && is_string( $wc_data['name'] ) ) {
						$wc_product->set_name( $wc_data['name'] );
					}

					// Establecer precio
					if ( isset( $wc_data['price'] ) && is_scalar( $wc_data['price'] ) ) {
						$wc_product->set_price( (string) $wc_data['price'] );
					}

					// Establecer stock
					if ( isset( $wc_data['stock'] ) && is_scalar( $wc_data['stock'] ) ) {
						$wc_product->set_stock_quantity( (float) $wc_data['stock'] );
					}

					// Guardar atributos complejos y bundle en meta datos del producto simple
					if ( isset( $wc_data['complex_attributes'] ) && is_array( $wc_data['complex_attributes'] ) ) {
						$complex_json = wp_json_encode( $wc_data['complex_attributes'] );
						if ( is_string( $complex_json ) ) {
							$wc_product->update_meta_data( '_verial_complex_attributes', $complex_json );
						}
					}

					if ( isset( $wc_data['bundle'] ) && is_array( $wc_data['bundle'] ) ) {
						$bundle_json = wp_json_encode( $wc_data['bundle'] );
						if ( is_string( $bundle_json ) ) {
							$wc_product->update_meta_data( '_verial_bundle', $bundle_json );
						}
					}

					$wc_product->save();

					// --- FORZAR GUARDADO DEL MAPEADO DE PRODUCTO ---
					if (isset($producto['Id']) && !empty($producto['Id'])) {
                        \MiIntegracionApi\Sync\SyncManager::get_instance()->update_product_mapping($wc_product_id, $producto['Id'], $sku);
                    }

					$template = __( 'Producto actualizado: %s', 'mi-integracion-api' );
					$template = is_string( $template ) ? $template : 'Producto actualizado: %s';
					$msg      = sprintf( $template, $sku );
				} else {
					$template = __( 'Error al cargar producto WooCommerce: %s', 'mi-integracion-api' );
					$template = is_string( $template ) ? $template : 'Error al cargar producto WooCommerce: %s';
					$msg      = sprintf( $template, $sku );
					return array(
						'success' => false,
						'message' => $msg,
					);
				}
			} else {
				/** @var \WC_Product_Simple|null $new_product */
				$new_product = class_exists( 'WC_Product_Simple' ) ? new \WC_Product_Simple() : null;

				if ( $new_product ) {
					// SKU ya validado como $sku
					if ( ! empty( $sku ) ) {
                        $new_product->set_sku( $sku );
                    }

                    // Establecer nombre
                    if ( isset( $wc_data['name'] ) && is_string( $wc_data['name'] ) ) {
                        $new_product->set_name( $wc_data['name'] );
                    } else {
                        $default_name = __( 'Nuevo Producto', 'mi-integracion-api' );
                        $new_product->set_name( is_string( $default_name ) ? $default_name : 'Nuevo Producto' );
                    }

                    // Establecer precio
                    if ( isset( $wc_data['price'] ) && is_scalar( $wc_data['price'] ) ) {
                        $new_product->set_price( (string) $wc_data['price'] );
                    }

                    // Establecer stock
                    if ( isset( $wc_data['stock'] ) && is_scalar( $wc_data['stock'] ) ) {
                        $new_product->set_stock_quantity( (float) $wc_data['stock'] );
                    }

                    $new_product_id = $new_product->save();

                    // --- FORZAR GUARDADO DEL MAPEADO DE PRODUCTO ---
                    if (isset($producto['Id']) && !empty($producto['Id'])) {
                        \MiIntegracionApi\Sync\SyncManager::get_instance()->update_product_mapping($new_product_id, $producto['Id'], $sku);
                    }

                    $template = __( 'Producto creado: %s', 'mi-integracion-api' );
                    $template = is_string( $template ) ? $template : 'Producto creado: %s';
                    $msg      = sprintf( $template, $sku );
                } else {
                    $template = __( 'No se pudo crear producto WooCommerce: %s', 'mi-integracion-api' );
                    $template = is_string( $template ) ? $template : 'No se pudo crear producto WooCommerce: %s';
                    $msg      = sprintf( $template, $sku );
                    return array(
						'success' => false,
						'message' => $msg,
					);
                }
			}
			// --- Lógica para productos tipo bundle (agrupados) ---
			if ( isset( $wc_data['bundle'] ) && is_array( $wc_data['bundle'] ) &&
				isset( $wc_data['bundle']['productos'] ) && is_array( $wc_data['bundle']['productos'] ) ) {

				// Buscar o crear el producto grouped
				$sku           = isset( $wc_data['sku'] ) && is_scalar( $wc_data['sku'] ) ? (string) $wc_data['sku'] : '';
				$wc_product_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $sku ) : 0;

				if ( $wc_product_id ) {
					/** @var \WC_Product|false $grouped_product */
					$grouped_product = function_exists( 'wc_get_product' ) ? wc_get_product( $wc_product_id ) : false;

					if ( ! $grouped_product ) {
						if ( class_exists( 'WC_Product_Grouped' ) ) {
							$grouped = new \WC_Product_Grouped();
						} else {
							$error_msg = __( 'No se puede crear bundle: WooCommerce no soporta grouped.', 'mi-integracion-api' );
							$error_msg = is_string( $error_msg ) ? $error_msg : 'No se puede crear bundle: WooCommerce no soporta grouped.';
							return array(
								'success' => false,
								'message' => $error_msg,
							);
						}
					} else {
						$product_type = method_exists( $grouped_product, 'get_type' ) ? $grouped_product->get_type() : '';

						if ( $product_type !== 'grouped' && class_exists( 'WC_Product_Grouped' ) ) {
							$grouped = new \WC_Product_Grouped( $wc_product_id );
						} else {
							/** @var \WC_Product_Grouped $grouped */
							$grouped = $grouped_product;
						}
					}
				} elseif ( class_exists( 'WC_Product_Grouped' ) ) {
						$grouped = new \WC_Product_Grouped();
					if ( ! empty( $sku ) ) {
						$grouped->set_sku( $sku );
					}

						$name = '';
					if ( isset( $wc_data['name'] ) && is_string( $wc_data['name'] ) ) {
						$name = $wc_data['name'];
					} else {
						$default_name = __( 'Nuevo Bundle', 'mi-integracion-api' );
						$name         = is_string( $default_name ) ? $default_name : 'Nuevo Bundle';
					}

						$grouped->set_name( $name );
						$grouped->set_status( 'publish' );
						$grouped->set_catalog_visibility( 'visible' );
						$wc_product_id = $grouped->save();
				} else {
					$error_msg = __( 'No se puede crear bundle: WooCommerce no soporta grouped.', 'mi-integracion-api' );
					$error_msg = is_string( $error_msg ) ? $error_msg : 'No se puede crear bundle: WooCommerce no soporta grouped.';
					return array(
						'success' => false,
						'message' => $error_msg,
					);
				}
				// Guardar atributos complejos y bundle en meta datos
				if ( isset( $wc_data['complex_attributes'] ) && is_array( $wc_data['complex_attributes'] ) ) {
					$complex_json = wp_json_encode( $wc_data['complex_attributes'] );
					if ( is_string( $complex_json ) ) {
						$grouped->update_meta_data( '_verial_complex_attributes', $complex_json );
					}
				}

				if ( isset( $wc_data['bundle'] ) && is_array( $wc_data['bundle'] ) ) {
					$bundle_json = wp_json_encode( $wc_data['bundle'] );
					if ( is_string( $bundle_json ) ) {
						$grouped->update_meta_data( '_verial_bundle', $bundle_json );
					}
				}

				$grouped->save();
				// Procesar hijos
				$child_ids = array();

				if ( isset( $wc_data['bundle']['productos'] ) && is_array( $wc_data['bundle']['productos'] ) ) {
					/** @var array<string, mixed> $child */
					foreach ( $wc_data['bundle']['productos'] as $child ) {
						if ( ! isset( $child['sku'] ) || ! is_scalar( $child['sku'] ) ) {
							continue;
						}

						$child_sku = (string) $child['sku'];
						if ( empty( $child_sku ) ) {
							continue;
						}

						$child_id = function_exists( 'wc_get_product_id_by_sku' ) ? wc_get_product_id_by_sku( $child_sku ) : 0;

						if ( ! $child_id ) {
							// Crear producto simple hijo si no existe
							if ( class_exists( 'WC_Product_Simple' ) ) {
								/** @var \WC_Product_Simple $child_product */
								$child_product = new \WC_Product_Simple();
								$child_product->set_sku( $child_sku );
								$child_product->set_name( $child_sku );
								$child_product->set_status( 'publish' );
								$child_product->set_catalog_visibility( 'visible' );
								$child_id = $child_product->save();
							} else {
								continue;
							}
						}

						// Guardar cantidad como meta en el hijo
						/** @var \WC_Product|false $child_product */
						$child_product = function_exists( 'wc_get_product' ) ? wc_get_product( $child_id ) : false;

						if ( $child_product ) {
							$child_product->update_meta_data( '_verial_bundle_parent', $wc_product_id );

							if ( isset( $child['cantidad'] ) && is_scalar( $child['cantidad'] ) ) {
								$cantidad = (int) $child['cantidad'];
								$child_product->update_meta_data( '_verial_bundle_qty', $cantidad );
							}

							$child_product->save();
						}

						$child_ids[] = $child_id;
					}
				}

				// Asociar hijos al grouped
				if ( $grouped && method_exists( $grouped, 'set_children' ) ) {
					$grouped->set_children( $child_ids );
				} elseif ( $wc_product_id ) {
					update_post_meta( $wc_product_id, 'children', $child_ids );
				}

				$grouped->save();
				$success_msg = __( 'Bundle sincronizado correctamente', 'mi-integracion-api' );
				$success_msg = is_string( $success_msg ) ? $success_msg : 'Bundle sincronizado correctamente';
				return array(
					'success' => true,
					'message' => $success_msg,
				);
			}
			return array(
				'success' => true,
				'message' => $msg,
			);
		} catch ( \Exception $e ) {
			return array(
				'success' => false,
				'message' => $e->getMessage(),
			);
		} finally {
			MI_Sync_Lock::release();
		}
	}
}
