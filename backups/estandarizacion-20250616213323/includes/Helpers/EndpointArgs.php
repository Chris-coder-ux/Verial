<?php
/**
 * Definiciones centralizadas de argumentos comunes para endpoints.
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EndpointArgs {
	/**
	 * Argumento común: sesionwcf
	 */
	public static function sesionwcf(): array {
		return [
			'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
			'type'              => 'integer',
			'required'          => true,
			'sanitize_callback' => 'absint',
		];
	}

	/**
	 * Argumento común: context
	 */
	public static function context(): array {
		return [
			'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
			'type'        => 'string',
			'enum'        => [ 'view', 'embed', 'edit' ],
			'default'     => 'view',
		];
	}

	/**
	 * Argumento común: force_refresh
	 */
	public static function force_refresh(): array {
		return [
			'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
			'type'              => 'boolean',
			'required'          => false,
			'default'           => false,
			'sanitize_callback' => 'rest_sanitize_boolean',
		];
	}

	// Agregar aquí otros argumentos comunes (paginación, filtros, etc.)
}
