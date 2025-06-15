<?php
/**
 * Clase para gestionar mapeos complejos
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase MappingManager
 *
 * Gestiona la creación, lectura, actualización y eliminación de mapeos
 * entre la API externa y WooCommerce.
 */
class MappingManager {
	/**
	 * Nombre de la opción en la base de datos
	 */
	const OPTION_NAME = 'mi_integracion_api_mappings';

	/**
	 * Tipos de mapeo válidos
	 *
	 * @var array
	 */
	private static $valid_types = array( 'product', 'category', 'attribute', 'tax', 'custom' );

	/**
	 * Obtiene todos los mapeos almacenados
	 *
	 * @return array Lista de mapeos
	 */
	public static function get_all_mappings() {
		return get_option( self::OPTION_NAME, array() );
	}

	/**
	 * Obtiene mapeos filtrados por tipo
	 *
	 * @param string $type Tipo de mapeo
	 * @return array Mapeos del tipo especificado
	 */
	public static function get_mappings_by_type( $type ) {
		$mappings = self::get_all_mappings();

		return array_filter(
			$mappings,
			function ( $mapping ) use ( $type ) {
				return isset( $mapping['type'] ) && $mapping['type'] === $type;
			}
		);
	}

	/**
	 * Busca un mapeo por su ID
	 *
	 * @param string $id ID único del mapeo
	 * @return array|null Mapeo encontrado o null
	 */
	public static function get_mapping_by_id( $id ) {
		$mappings = self::get_all_mappings();

		foreach ( $mappings as $mapping ) {
			if ( isset( $mapping['id'] ) && $mapping['id'] === $id ) {
				return $mapping;
			}
		}

		return null;
	}

	/**
	 * Busca un mapeo por su fuente
	 *
	 * @param string $source Valor de origen (API)
	 * @param string $type Tipo de mapeo (opcional)
	 * @return array|null Mapeo encontrado o null
	 */
	public static function get_mapping_by_source( $source, $type = null ) {
		$mappings = self::get_all_mappings();

		foreach ( $mappings as $mapping ) {
			if ( isset( $mapping['source'] ) && $mapping['source'] === $source ) {
				if ( $type === null || ( isset( $mapping['type'] ) && $mapping['type'] === $type ) ) {
					return $mapping;
				}
			}
		}

		return null;
	}

	/**
	 * Busca un mapeo por su destino
	 *
	 * @param string $target Valor de destino (WooCommerce)
	 * @param string $type Tipo de mapeo (opcional)
	 * @return array|null Mapeo encontrado o null
	 */
	public static function get_mapping_by_target( $target, $type = null ) {
		$mappings = self::get_all_mappings();

		foreach ( $mappings as $mapping ) {
			if ( isset( $mapping['target'] ) && $mapping['target'] === $target ) {
				if ( $type === null || ( isset( $mapping['type'] ) && $mapping['type'] === $type ) ) {
					return $mapping;
				}
			}
		}

		return null;
	}

	/**
	 * Guarda un nuevo mapeo
	 *
	 * @param array $mapping Datos del mapeo a guardar
	 * @return true|WP_Error True en caso de éxito, WP_Error en caso de error
	 */
	public static function save_mapping( $mapping ) {
		// Validar datos
		if ( ! isset( $mapping['source'] ) || empty( $mapping['source'] ) ) {
			return new WP_Error( 'missing_source', __( 'El campo fuente es obligatorio', 'mi-integracion-api' ) );
		}

		if ( ! isset( $mapping['target'] ) || empty( $mapping['target'] ) ) {
			return new WP_Error( 'missing_target', __( 'El campo destino es obligatorio', 'mi-integracion-api' ) );
		}

		if ( ! isset( $mapping['type'] ) || ! in_array( $mapping['type'], self::$valid_types ) ) {
			return new WP_Error( 'invalid_type', __( 'Tipo de mapeo no válido', 'mi-integracion-api' ) );
		}

		// Sanear datos
		$sanitized_mapping = array(
			'source'  => sanitize_text_field( $mapping['source'] ),
			'target'  => sanitize_text_field( $mapping['target'] ),
			'type'    => sanitize_text_field( $mapping['type'] ),
			'id'      => isset( $mapping['id'] ) ? sanitize_text_field( $mapping['id'] ) : uniqid( 'map_' ),
			'created' => isset( $mapping['created'] ) ? sanitize_text_field( $mapping['created'] ) : current_time( 'mysql' ),
			'updated' => current_time( 'mysql' ),
		);

		$mappings = self::get_all_mappings();

		// Comprobar si ya existe un mapeo con este ID
		$exists = false;
		foreach ( $mappings as $key => $existing ) {
			if ( isset( $existing['id'] ) && $existing['id'] === $sanitized_mapping['id'] ) {
				$mappings[ $key ] = $sanitized_mapping;
				$exists           = true;
				break;
			}
		}

		// Si no existe, añadirlo
		if ( ! $exists ) {
			$mappings[] = $sanitized_mapping;
		}

		// Guardar en la base de datos
		$updated = update_option( self::OPTION_NAME, $mappings );

		if ( ! $updated ) {
			return new WP_Error( 'save_failed', __( 'Error al guardar el mapeo', 'mi-integracion-api' ) );
		}

		return true;
	}

	/**
	 * Elimina un mapeo por su ID
	 *
	 * @param string $id ID del mapeo a eliminar
	 * @return true|WP_Error True en caso de éxito, WP_Error en caso de error
	 */
	public static function delete_mapping( $id ) {
		$mappings = self::get_all_mappings();

		$found = false;
		foreach ( $mappings as $key => $mapping ) {
			if ( isset( $mapping['id'] ) && $mapping['id'] === $id ) {
				unset( $mappings[ $key ] );
				$found = true;
				break;
			}
		}

		if ( ! $found ) {
			return new WP_Error( 'not_found', __( 'Mapeo no encontrado', 'mi-integracion-api' ) );
		}

		// Reindexar array
		$mappings = array_values( $mappings );

		// Guardar en la base de datos
		$updated = update_option( self::OPTION_NAME, $mappings );

		if ( ! $updated ) {
			return new WP_Error( 'delete_failed', __( 'Error al eliminar el mapeo', 'mi-integracion-api' ) );
		}

		return true;
	}

	/**
	 * Elimina todos los mapeos
	 *
	 * @return bool Resultado de la operación
	 */
	public static function delete_all_mappings() {
		return delete_option( self::OPTION_NAME );
	}

	/**
	 * Transforma un valor usando los mapeos disponibles
	 *
	 * @param string $value Valor a transformar
	 * @param string $direction Dirección de la transformación ('to_woo' o 'from_woo')
	 * @param string $type Tipo de mapeo
	 * @return string Valor transformado o el original si no hay mapeo
	 */
	public static function transform_value( $value, $direction = 'to_woo', $type = 'product' ) {
		$mappings = self::get_mappings_by_type( $type );

		if ( empty( $mappings ) ) {
			return $value;
		}

		if ( $direction === 'to_woo' ) {
			// Buscar mapeo de API a WooCommerce
			foreach ( $mappings as $mapping ) {
				if ( $mapping['source'] === $value ) {
					return $mapping['target'];
				}
			}
		} else {
			// Buscar mapeo de WooCommerce a API
			foreach ( $mappings as $mapping ) {
				if ( $mapping['target'] === $value ) {
					return $mapping['source'];
				}
			}
		}

		return $value;
	}
}
