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

// Importamos la clase SyncLock
use MiIntegracionApi\Sync\SyncLock;

class Sync_Single_Product {
	/**
	 * Sincroniza un solo producto desde Verial a WooCommerce por SKU o nombre.
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $api_connector El conector de API
	 * @param string                              $sku SKU del producto a sincronizar
	 * @param string                              $nombre Nombre del producto a sincronizar
	 * @return array<string, mixed> Resultado de la sincronización con claves 'success' y 'message'
	 */
	public static function sync( \MiIntegracionApi\Core\ApiConnector $api_connector, string $sku = '', string $nombre = '', string $categoria = '', string $fabricante = '' ): array {
		// Crear un logger para depuración
		$logger = new \MiIntegracionApi\Helpers\Logger('sync-single-product');
		$logger->info('Iniciando sincronización de producto individual', [
			'sku' => $sku,
			'nombre' => $nombre,
			'categoria' => $categoria,
			'fabricante' => $fabricante
		]);
		
		// Verificar que la API esté configurada correctamente
		if (empty($api_connector->get_api_base_url())) {
			$logger->error('URL de API no configurada');
			return array(
				'success' => false,
				'message' => 'Error de configuración: URL de API no configurada.',
			);
		}
		
		if ( ! class_exists( 'WooCommerce' ) ) {
			$logger->error('WooCommerce no está activo');
			return array(
				'success' => false,
				'message' => 'WooCommerce no está activo.',
			);
		}
		
		if ( ! SyncLock::acquire() ) {
			$logger->warning('Intento de sincronización mientras otra está en curso');
			return array(
				'success' => false,
				'message' => __( 'Ya hay una sincronización en curso.', 'mi-integracion-api' ),
			);
		}
		
		try {
			/**
			 * Obtener artículos de la API
			 */
			$logger->info('Obteniendo artículos de la API');
			
			// Preparar parámetros para la solicitud a la API
			$params = array();
			
			// Si tenemos un SKU, intentamos pasarlo como filtro a la API (puede ser específico de la implementación de Verial)
			if (!empty($sku)) {
				// CORRECCIÓN: Utilizar el formato correcto según la API
				$params['sku'] = $sku;  // Parámetro principal
				// Mantener compatibilidad con formatos anteriores
				$params['referencia'] = $sku;
				$params['referenciabarras'] = $sku;
				$logger->info('Añadiendo filtro de SKU a la petición API', ['sku' => $sku]);
			}
			
			// Si tenemos un nombre, intentamos pasarlo como filtro a la API
			if (!empty($nombre)) {
				$params['nombre'] = $nombre;
				$logger->info('Añadiendo filtro de nombre a la petición API', ['nombre' => $nombre]);
			}
			
			// Añadir filtros de categoría y fabricante si se proporcionan
			if (!empty($categoria)) {
				$params['categoria'] = $categoria;
				$logger->info('Añadiendo filtro de categoría a la petición API', ['categoria' => $categoria]);
			}
			
			if (!empty($fabricante)) {
				$params['fabricante'] = $fabricante;
				$logger->info('Añadiendo filtro de fabricante a la petición API', ['fabricante' => $fabricante]);
			}
			
			// CORRECCIÓN: Agregar campo adicional 'field' con valor 'cod' como se ve en la captura de pantalla
			$params['field'] = 'cod';
			
			$logger->info('Solicitando productos con parámetros completos', [
			    'params' => $params,
			    'endpoint' => 'GetArticulosWS'
			]);
			
			$productos = $api_connector->get_articulos($params);
			
			// Registrar resultado de la llamada a la API
			if (is_wp_error($productos)) {
				$logger->error('Error al obtener artículos', ['error' => $productos->get_error_message()]);
			} else {
				$count = is_array($productos) ? count($productos) : 0;
				$logger->info('Artículos obtenidos de la API', ['count' => $count]);
			}
			
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
			
			// Depuración detallada del JSON recibido para entender mejor su estructura
			$logger->info('Estructura JSON recibida de Verial', [
			    'tipo' => gettype($productos),
			    'muestra_json' => json_encode(array_slice((array)$productos, 0, 2, true)),
			    'propiedades' => array_keys((array)$productos)
			]);
			
			/** @var array<string, mixed>|null $producto */
			$producto = null;
			
			// Normalizar la estructura de productos para el procesamiento
			$articulos_normalizados = [];
			
			// Registrar la estructura completa de la respuesta para diagnóstico
			$logger->debug('Estructura de respuesta API completa', [
			    'tipo' => gettype($productos),
			    'keys_nivel_superior' => is_array($productos) ? array_keys($productos) : 'No es un array',
			    'muestra_json' => json_encode(array_slice((array)$productos, 0, 3))
			]);
			
			// Determinar el formato de la respuesta y normalizar
			if (isset($productos['Articulos']) && is_array($productos['Articulos'])) {
				$logger->info('Formato detectado: Array con clave Articulos');
				$articulos_normalizados = $productos['Articulos'];
			} elseif (isset($productos['data']) && is_array($productos['data'])) {
				// Formato común de API: {success: true, data: [...]}
				$logger->info('Formato detectado: Estructura con data');
				$articulos_normalizados = $productos['data'];
			} elseif (isset($productos[0])) {
				$logger->info('Formato detectado: Array simple de productos');
				$articulos_normalizados = $productos;
			} else {
				// Si es un único objeto de producto (no en array)
				if (isset($productos['Id']) || isset($productos['ReferenciaBarras']) || isset($productos['Nombre'])) {
					$logger->info('Formato detectado: Producto único', [
					    'id' => $productos['Id'] ?? 'No disponible',
					    'nombre' => $productos['Nombre'] ?? 'No disponible'
					]);
					$articulos_normalizados = [$productos];
				} else {
					// Si recibimos algún formato desconocido, intentamos procesarlo lo mejor posible
					$logger->warning('Formato desconocido de respuesta', ['keys' => is_array($productos) ? array_keys($productos) : 'No es un array']);
					
					// Si hay alguna otra clave que pueda contener productos
					if (is_array($productos)) {
						foreach ($productos as $key => $value) {
							if (is_array($value) && !empty($value)) {
								if (isset($value[0]) && (
									isset($value[0]['Id']) || 
									isset($value[0]['ReferenciaBarras']) || 
									isset($value[0]['Nombre'])
								)) {
									$logger->info("Productos encontrados en clave: {$key}");
									$articulos_normalizados = $value;
									break;
								} elseif (isset($value['Id']) || isset($value['ReferenciaBarras']) || isset($value['Nombre'])) {
									$logger->info("Producto único encontrado en clave: {$key}");
									$articulos_normalizados = [$value];
									break;
								}
							}
						}
					}
				}
			}
			
			// Registrar parámetros de búsqueda
			$logger->info('Buscando producto por criterios', [
				'sku_busqueda' => $sku,
				'nombre_busqueda' => $nombre,
				'total_productos_normalizados' => count($articulos_normalizados)
			]);
			
			// Registrar una muestra de los primeros productos para depuración
			if (count($articulos_normalizados) > 0) {
				$logger->info('Muestra del primer producto normalizado', [
					'muestra' => $articulos_normalizados[0]
				]);
			}
			
			/** @var array<string, mixed> $p */
			foreach ( $articulos_normalizados as $p ) {
			    // Depurar la estructura del producto actual para diagnóstico
			    $logger->debug('Analizando producto de API', [
			        'id' => $p['Id'] ?? 'No disponible',
			        'nombre' => $p['Nombre'] ?? 'No disponible',
			        'referencia_barras' => $p['ReferenciaBarras'] ?? 'No disponible',
			        'referencia' => $p['Referencia'] ?? 'No disponible',
			        'keys_disponibles' => array_keys($p)
			    ]);
				
				// Buscar por SKU en campo ReferenciaBarras (método principal según estructura JSON)
				if ( $sku && isset( $p['ReferenciaBarras'] ) && is_string( $p['ReferenciaBarras'] ) && $p['ReferenciaBarras'] === $sku ) {
					$producto = $p;
					$logger->info('Producto encontrado por campo ReferenciaBarras', ['sku' => $sku, 'referenciaBarras' => $p['ReferenciaBarras'], 'nombre' => $p['Nombre'] ?? 'No especificado']);
					break;
				}
				
				// Alternativa: Buscar por SKU en campo Referencia (compatibilidad)
				if ( $sku && isset( $p['Referencia'] ) && is_string( $p['Referencia'] ) && $p['Referencia'] === $sku ) {
					$producto = $p;
					$logger->info('Producto encontrado por campo Referencia', ['sku' => $sku, 'nombre' => $p['Nombre'] ?? 'No especificado']);
					break;
				}
				
				// Buscar por nombre
				if ( $nombre && isset( $p['Nombre'] ) && is_string( $p['Nombre'] ) && stripos( $p['Nombre'], $nombre ) !== false ) {
					$producto = $p;
					$logger->info('Producto encontrado por nombre', ['nombre_busqueda' => $nombre, 'nombre_encontrado' => $p['Nombre']]);
					break;
				}
			}
			
			if ( ! $producto ) {
				$logger->warning('No se encontró el producto solicitado', ['sku' => $sku, 'nombre' => $nombre]);
				$error_message = __( 'No se encontró el producto solicitado.', 'mi-integracion-api' );
				$error_message = is_string( $error_message ) ? $error_message : 'No se encontró el producto solicitado.';
				return array(
					'success' => false,
					'message' => $error_message,
				);
			}
			
			// Mapeo de campos para WooCommerce (según estructura JSON de ejemplo)
			$mapped_product = [
			    'id' => $producto['Id'] ?? 0,
			    'sku' => $producto['ReferenciaBarras'] ?? '',  // Campo principal para SKU
			    'nombre' => $producto['Nombre'] ?? '',
			    'descripcion' => $producto['Descripcion'] ?? '',
			    'categoria_id' => $producto['ID_Categoria'] ?? 0,
			    'fabricante_id' => $producto['ID_Fabricante'] ?? 0,
			    'iva' => $producto['PorcentajeIVA'] ?? 0,
			    'peso' => $producto['Peso'] ?? 0,
			    'dimensiones' => [
			        'alto' => $producto['Alto'] ?? 0,
			        'ancho' => $producto['Ancho'] ?? 0,
			        'grueso' => $producto['Grueso'] ?? 0
			    ],
			    'datos_adicionales' => [
			        'autores' => $producto['Autores'] ?? '',
			        'edicion' => $producto['Edicion'] ?? '',
			        'paginas' => $producto['Paginas'] ?? 0,
			        'subtitulo' => $producto['Subtitulo'] ?? '',
			        'tipo' => $producto['Tipo'] ?? 0
			    ]
			];
			
			$logger->info('Datos del producto mapeados correctamente', [
			    'id' => $mapped_product['id'],
			    'sku' => $mapped_product['sku'],
			    'nombre' => $mapped_product['nombre']
			]);
			
			// Asignar el producto mapeado para el procesamiento posterior
			$producto['mapped_wc_data'] = $mapped_product;
			
			$logger->info('Procesando datos del producto', ['producto_data' => $producto]);
			
			// Obtener las condiciones de tarifa para este producto
			if (isset($producto['Id']) && !empty($producto['Id'])) {
				try {
					$logger->info('Obteniendo condiciones de tarifa para el producto', ['id_articulo' => $producto['Id']]);
					$condiciones_tarifa = $api_connector->get_condiciones_tarifa($producto['Id']);
					
					if (is_wp_error($condiciones_tarifa)) {
						$logger->warning('Error al obtener condiciones de tarifa', ['error' => $condiciones_tarifa->get_error_message()]);
					} else {
						$logger->info('Condiciones de tarifa obtenidas', ['condiciones' => $condiciones_tarifa]);
						
						// Actualizar precios del producto si hay condiciones disponibles
						if (is_array($condiciones_tarifa) && !empty($condiciones_tarifa)) {
							// Manejar la estructura específica que devuelve la API de Verial
							$condiciones_lista = [];
							
							// Verificar si las condiciones vienen en formato CondicionesTarifa (formato oficial API Verial)
							if (isset($condiciones_tarifa['CondicionesTarifa']) && is_array($condiciones_tarifa['CondicionesTarifa'])) {
								$logger->info('Detectada estructura CondicionesTarifa en la respuesta');
								$condiciones_lista = $condiciones_tarifa['CondicionesTarifa'];
							} elseif (isset($condiciones_tarifa[0])) {
								// Array de condiciones directo
								$condiciones_lista = $condiciones_tarifa;
							} elseif (isset($condiciones_tarifa['Precio'])) {
								// Condición única
								$condiciones_lista = [$condiciones_tarifa];
							}
							
							// Procesar la primera condición de tarifa encontrada
							if (!empty($condiciones_lista)) {
								$condicion = $condiciones_lista[0];
								
								// Verificar si el precio existe en las condiciones
								if (isset($condicion['Precio']) && is_numeric($condicion['Precio']) && $condicion['Precio'] > 0) {
									$logger->info('Actualizando precio del producto con condiciones de tarifa', ['precio' => $condicion['Precio']]);
									$producto['PVP'] = $condicion['Precio'];
									
									// Si hay descuento, calcular el precio final
									if (isset($condicion['Dto']) && is_numeric($condicion['Dto']) && $condicion['Dto'] > 0) {
										$descuento = ($condicion['Precio'] * $condicion['Dto']) / 100;
										$precio_final = $condicion['Precio'] - $descuento;
										$logger->info('Aplicando descuento al precio', [
											'descuento_porcentaje' => $condicion['Dto'],
											'descuento_valor' => $descuento,
											'precio_final' => $precio_final
										]);
										$producto['PVPOferta'] = $precio_final;
									}
									
									// Si hay descuento en euros por unidad
									if (isset($condicion['DtoEurosXUd']) && is_numeric($condicion['DtoEurosXUd']) && $condicion['DtoEurosXUd'] > 0) {
										$precio_final = $condicion['Precio'] - $condicion['DtoEurosXUd'];
										$logger->info('Aplicando descuento en euros por unidad', [
											'descuento_euros' => $condicion['DtoEurosXUd'],
											'precio_final' => $precio_final
										]);
										$producto['PVPOferta'] = $precio_final;
									}
								}
							}
						}
					}
				} catch (\Exception $e) {
					$logger->error('Excepción al obtener condiciones de tarifa', [
						'error' => $e->getMessage(),
						'trace' => $e->getTraceAsString()
					]);
				}
			}
			
			/** @var \MiIntegracionApi\DTOs\ProductDTO|null $wc_data_dto */
			$wc_data_dto = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc($producto);
			
			// Verificar que el DTO se creó correctamente
			if (!$wc_data_dto) {
				$logger->error('No se pudo crear el DTO del producto');
				return array(
					'success' => false,
					'message' => __('Error al procesar los datos del producto.', 'mi-integracion-api'),
				);
			}
			
			// Convertir el DTO a array para mantener compatibilidad con el código existente
			$wc_data = $wc_data_dto->toArray();
			$logger->info('Datos mapeados para WooCommerce', ['wc_data' => $wc_data]);
			
			if (isset($wc_data['type']) && $wc_data['type'] === 'variable') {
				// Buscar producto existente por SKU
				$sku_to_search = isset($wc_data['sku']) && is_string($wc_data['sku']) ? $wc_data['sku'] : '';
				$logger->info('Buscando producto variable por SKU', ['sku' => $sku_to_search]);
				
				$wc_product_id = function_exists('wc_get_product_id_by_sku') && !empty($sku_to_search)
					? wc_get_product_id_by_sku($sku_to_search)
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

					// Establecer descripciones
                    if ( isset( $wc_data['description'] ) && is_string( $wc_data['description'] ) ) {
                        $wc_product->set_description( $wc_data['description'] );
                    }
                    if ( isset( $wc_data['short_description'] ) && is_string( $wc_data['short_description'] ) ) {
                        $wc_product->set_short_description( $wc_data['short_description'] );
                    }

					// Establecer precios
					if ( isset( $wc_data['price'] ) && is_scalar( $wc_data['price'] ) ) {
						$wc_product->set_price( (string) $wc_data['price'] );
					}
					if ( isset( $wc_data['regular_price'] ) && is_scalar( $wc_data['regular_price'] ) ) {
                        $wc_product->set_regular_price( (string) $wc_data['regular_price'] );
                    }
                    if ( isset( $wc_data['sale_price'] ) && is_scalar( $wc_data['sale_price'] ) && (float) $wc_data['sale_price'] > 0 ) {
                        $wc_product->set_sale_price( (string) $wc_data['sale_price'] );
                    }

					// Establecer stock
					if ( isset( $wc_data['stock_quantity'] ) && is_scalar( $wc_data['stock_quantity'] ) ) {
						$wc_product->set_stock_quantity( (float) $wc_data['stock_quantity'] );
					}
					if ( isset( $wc_data['stock_status'] ) && is_string( $wc_data['stock_status'] ) ) {
                        $wc_product->set_stock_status( $wc_data['stock_status'] );
                    }
                    
                    // Establecer dimensiones
                    if ( isset( $wc_data['dimensions'] ) && is_array( $wc_data['dimensions'] ) ) {
                        if ( isset( $wc_data['dimensions']['length'] ) && is_scalar( $wc_data['dimensions']['length'] ) ) {
                            $wc_product->set_length( (string) $wc_data['dimensions']['length'] );
                        }
                        if ( isset( $wc_data['dimensions']['width'] ) && is_scalar( $wc_data['dimensions']['width'] ) ) {
                            $wc_product->set_width( (string) $wc_data['dimensions']['width'] );
                        }
                        if ( isset( $wc_data['dimensions']['height'] ) && is_scalar( $wc_data['dimensions']['height'] ) ) {
                            $wc_product->set_height( (string) $wc_data['dimensions']['height'] );
                        }
                    }
                    if ( isset( $wc_data['weight'] ) && is_scalar( $wc_data['weight'] ) ) {
                        $wc_product->set_weight( (string) $wc_data['weight'] );
                    }
                    
                    // Establecer categorías
                    if ( isset( $wc_data['categories'] ) && is_array( $wc_data['categories'] ) && !empty( $wc_data['categories'] ) ) {
                        $wc_product->set_category_ids( $wc_data['categories'] );
                    } elseif ( !empty( $categoria ) ) {
                        // Intentar asignar la categoría seleccionada durante la sincronización
                        $term = term_exists( $categoria, 'product_cat' );
                        if ( $term !== 0 && $term !== null && is_array( $term ) ) {
                            $wc_product->set_category_ids( [ $term['term_id'] ] );
                        }
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

					// Procesar imágenes si existen
					self::process_product_images($wc_product, $producto, $logger);
                    
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

                    // Establecer descripciones
                    if ( isset( $wc_data['description'] ) && is_string( $wc_data['description'] ) ) {
                        $new_product->set_description( $wc_data['description'] );
                    }
                    if ( isset( $wc_data['short_description'] ) && is_string( $wc_data['short_description'] ) ) {
                        $new_product->set_short_description( $wc_data['short_description'] );
                    }

                    // Establecer precios
                    if ( isset( $wc_data['price'] ) && is_scalar( $wc_data['price'] ) ) {
                        $new_product->set_price( (string) $wc_data['price'] );
                    }
                    if ( isset( $wc_data['regular_price'] ) && is_scalar( $wc_data['regular_price'] ) ) {
                        $new_product->set_regular_price( (string) $wc_data['regular_price'] );
                    }
                    if ( isset( $wc_data['sale_price'] ) && is_scalar( $wc_data['sale_price'] ) && (float) $wc_data['sale_price'] > 0 ) {
                        $new_product->set_sale_price( (string) $wc_data['sale_price'] );
                    }

                    // Establecer stock
                    if ( isset( $wc_data['stock_quantity'] ) && is_scalar( $wc_data['stock_quantity'] ) ) {
                        $new_product->set_stock_quantity( (float) $wc_data['stock_quantity'] );
                    }
                    if ( isset( $wc_data['stock_status'] ) && is_string( $wc_data['stock_status'] ) ) {
                        $new_product->set_stock_status( $wc_data['stock_status'] );
                    }

                    // Establecer dimensiones
                    if ( isset( $wc_data['dimensions'] ) && is_array( $wc_data['dimensions'] ) ) {
                        if ( isset( $wc_data['dimensions']['length'] ) && is_scalar( $wc_data['dimensions']['length'] ) ) {
                            $new_product->set_length( (string) $wc_data['dimensions']['length'] );
                        }
                        if ( isset( $wc_data['dimensions']['width'] ) && is_scalar( $wc_data['dimensions']['width'] ) ) {
                            $new_product->set_width( (string) $wc_data['dimensions']['width'] );
                        }
                        if ( isset( $wc_data['dimensions']['height'] ) && is_scalar( $wc_data['dimensions']['height'] ) ) {
                            $new_product->set_height( (string) $wc_data['dimensions']['height'] );
                        }
                    }
                    if ( isset( $wc_data['weight'] ) && is_scalar( $wc_data['weight'] ) ) {
                        $new_product->set_weight( (string) $wc_data['weight'] );
                    }

                    // Establecer categorías
                    if ( isset( $wc_data['categories'] ) && is_array( $wc_data['categories'] ) && !empty( $wc_data['categories'] ) ) {
                        $new_product->set_category_ids( $wc_data['categories'] );
                    } elseif ( !empty( $categoria ) ) {
                        // Intentar asignar la categoría seleccionada durante la sincronización
                        $term = term_exists( $categoria, 'product_cat' );
                        if ( $term !== 0 && $term !== null && is_array( $term ) ) {
                            $new_product->set_category_ids( [ $term['term_id'] ] );
                        }
                    }

                    $new_product_id = $new_product->save();
                    
                    // Procesar imágenes si existen
					self::process_product_images($new_product, $producto, $logger);
					
					// Guardar nuevamente para asegurar que las imágenes se han guardado correctamente
					$new_product->save();

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
			$result = array(
				'success' => true,
				'message' => $msg,
			);
			$logger->info('Sincronización finalizada con éxito', [
				'product_id' => $wc_product_id ?? 0,
				'result' => $result
			]);
			return $result;
		} catch ( \Exception $e ) {
			$error_message = $e->getMessage();
			$logger->error('Error en sincronización: ' . $error_message, [
				'exception' => get_class($e),
				'trace' => $e->getTraceAsString()
			]);
			return array(
				'success' => false,
				'message' => $error_message,
			);
		} finally {
			$logger->info('Liberando bloqueo de sincronización');
			SyncLock::release();
		}
	}
	
	/**
	 * Procesa las imágenes del producto de Verial y las asigna al producto de WooCommerce
	 * 
	 * @param \WC_Product $product Producto de WooCommerce al que asignar las imágenes
	 * @param array $verial_product Datos del producto de Verial
	 * @param \MiIntegracionApi\Helpers\Logger $logger Logger para registrar eventos
	 * @return void
	 */
	private static function process_product_images(\WC_Product $product, array $verial_product, \MiIntegracionApi\Helpers\Logger $logger): void {
		// Primero verificamos si hay URLs de imágenes en el producto de Verial
		$image_urls = [];
		
		// Comprobar varios formatos posibles de imágenes en Verial
		if (isset($verial_product['Imagenes']) && is_array($verial_product['Imagenes'])) {
			// Si es un array de URLs de imágenes
			foreach ($verial_product['Imagenes'] as $img) {
				if (is_string($img) && !empty($img)) {
					$image_urls[] = $img;
				} elseif (is_array($img) && isset($img['URL']) && is_string($img['URL']) && !empty($img['URL'])) {
					$image_urls[] = $img['URL'];
				}
			}
		}
		
		// Si hay un campo imagen individual
		if (isset($verial_product['Imagen']) && is_string($verial_product['Imagen']) && !empty($verial_product['Imagen'])) {
			$image_urls[] = $verial_product['Imagen'];
		}
		
		// Si no hay imágenes, no seguimos
		if (empty($image_urls)) {
			$logger->info('No se encontraron imágenes en el producto de Verial');
			return;
		}
		
		$logger->info('Procesando imágenes de producto', ['count' => count($image_urls)]);
		
		// Descargamos y asignamos cada imagen
		$image_ids = [];
		$featured_set = false;
		
		foreach ($image_urls as $url) {
			// Descargar imagen y crear attachment
			$image_id = self::download_and_attach_image($url, $product->get_id(), $logger);
			
			if ($image_id) {
				$image_ids[] = $image_id;
				
				// La primera imagen válida la usamos como imagen destacada
				if (!$featured_set) {
					$product->set_image_id($image_id);
					$featured_set = true;
				}
			}
		}
		
		// Si tenemos más de una imagen, establecer galería
		if (count($image_ids) > 1) {
			// Quitamos la imagen principal de la galería para evitar duplicados
			$gallery_ids = array_slice($image_ids, 1);
			$product->set_gallery_image_ids($gallery_ids);
		}
	}
	
	/**
	 * Descarga una imagen desde una URL y la adjunta a un producto
	 * 
	 * @param string $url URL de la imagen a descargar
	 * @param int $product_id ID del producto al que adjuntar la imagen
	 * @param \MiIntegracionApi\Helpers\Logger $logger Logger para registrar eventos
	 * @return int|false ID del attachment creado o false si falla
	 */
	private static function download_and_attach_image(string $url, int $product_id, \MiIntegracionApi\Helpers\Logger $logger) {
		// Verificar que la función de descarga exista
		if (!function_exists('download_url') || !function_exists('media_handle_sideload')) {
			require_once(ABSPATH . 'wp-admin/includes/file.php');
			require_once(ABSPATH . 'wp-admin/includes/media.php');
			require_once(ABSPATH . 'wp-admin/includes/image.php');
		}
		
		// Limpiar la URL y añadir protocolo si falta
		$url = trim($url);
		if (!preg_match('~^(?:f|ht)tps?://~i', $url)) {
			$url = 'https://' . $url;
		}
		
		$logger->info('Intentando descargar imagen', ['url' => $url]);
		
		try {
			// Descargar temporalmente la imagen
			$temp_file = download_url($url);
			
			if (is_wp_error($temp_file)) {
				$logger->error('Error al descargar imagen', ['error' => $temp_file->get_error_message()]);
				return false;
			}
			
			// Extraer nombre de archivo de la URL
			$filename = basename(parse_url($url, PHP_URL_PATH));
			
			// Si no se pudo extraer, usar un nombre genérico
			if (empty($filename) || strlen($filename) < 3) {
				$filename = 'producto-' . $product_id . '-' . uniqid() . '.jpg';
			}
			
			// Crear un array con los datos del archivo
			$file_array = array(
				'name'     => sanitize_file_name($filename),
				'tmp_name' => $temp_file,
				'error'    => 0,
				'size'     => filesize($temp_file),
			);
			
			// Subir el archivo al media library de WordPress
			$attachment_id = media_handle_sideload($file_array, $product_id);
			
			// Eliminar el archivo temporal incluso si hubo error
			@unlink($temp_file);
			
			if (is_wp_error($attachment_id)) {
				$logger->error('Error al procesar imagen', ['error' => $attachment_id->get_error_message()]);
				return false;
			}
			
			$logger->info('Imagen procesada correctamente', ['id' => $attachment_id]);
			return $attachment_id;
			
		} catch (\Exception $e) {
			$logger->error('Excepción al procesar imagen', ['error' => $e->getMessage()]);
			return false;
		}
	}
}
