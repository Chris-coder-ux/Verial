<?php
/**
 * Clase para el endpoint GetImagenesArticulosWS de la API de Verial ERP.
 * Obtiene el listado de imágenes de artículos, según el manual v1.7.5.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Traits\EndpointLogger;
use WP_REST_Request;
use WP_Error;
use MiIntegracionApi\Helpers\Logger;

/**
 * Clase para gestionar el endpoint de imágenes de artículos
 */
class ImagenesArticulosWS extends Base {

	use EndpointLogger;

	public const ENDPOINT_NAME        = 'GetImagenesArticulosWS';
	public const CACHE_KEY_PREFIX     = 'mi_api_img_art_';
	public const CACHE_EXPIRATION     = 6 * HOUR_IN_SECONDS;
	public const VERIAL_ERROR_SUCCESS = 0;

	/**
	 * Registra la ruta REST WP para este endpoint
	 *
	 * @return void
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/imagenesarticulosws',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'execute_restful' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	public function __construct( $api_connector ) {
		parent::__construct( $api_connector );
		$this->init_logger(); // Logger base, no específico de productos
	}

	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				esc_html__( 'No tienes permiso para ver las imágenes de los artículos.', 'mi-integracion-api' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		if ( class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			$auth_result = \MiIntegracionApi\Helpers\AuthHelper::validate_rest_auth( $request );
			if ( $auth_result !== true ) {
				return $auth_result;
			}
		}
		return true;
	}

	public function get_endpoint_args( bool $is_update = false ): array {
		$args = array(
			'sesionwcf'          => array(
				'description'       => __( 'Número de sesión de Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
			),
			'force_refresh'      => array(
				'description'       => __( 'Forzar la actualización de la caché.', 'mi-integracion-api' ),
				'type'              => 'boolean',
				'required'          => false,
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
			'numpixelsladomenor' => array(
				'description'       => __( 'Si es > 0, las imágenes se reducen manteniendo proporciones y el lado menor tendrá estos píxeles.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => false,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param >= 0;
				},
			),
			'fecha_desde'        => array(
				'description'       => __( 'Fecha (YYYY-MM-DD) para filtrar imágenes de artículos creados/modificados desde esta fecha.', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_date_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'hora_desde'         => array(
				'description'       => __( 'Hora (HH:MM) para el filtro de fecha_desde.', 'mi-integracion-api' ),
				'type'              => 'string',
				'required'          => false,
				'validate_callback' => array( $this, 'validate_time_format_optional' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'context'            => array(
				'description' => __( 'El contexto de la petición (view, embed, edit).', 'mi-integracion-api' ),
				'type'        => 'string',
				'enum'        => array( 'view', 'embed', 'edit' ),
				'default'     => 'view',
			),
		);
		if ( $is_update ) {
			$args['id_articulo_verial'] = array(
				'description'       => __( 'ID del artículo en Verial.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => function ( $param ) {
					return is_numeric( $param ) && $param > 0;
				},
			);
		}
		return $args;
	}

		/**
		 * Valida que un valor sea una fecha opcional en formato ISO
		 *
		 * @param mixed            $value Valor a validar
		 * @param \WP_REST_Request $request Petición
		 * @param string           $key Clave del parámetro
		 * @return bool|\WP_Error True si es válido, WP_Error si no
		 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		// @phpstan-ignore-next-line
		return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' ), $key ), array( 'status' => 400 ) );
	}
		/**
		 * Valida que un valor sea una hora opcional en formato HH:MM
		 *
		 * @param mixed            $value Valor a validar
		 * @param \WP_REST_Request $request Petición
		 * @param string           $key Clave del parámetro
		 * @return bool|\WP_Error True si es válido, WP_Error si no
		 */
	public function validate_time_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return true;
		}
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return true;
		}
		// @phpstan-ignore-next-line
		return new \WP_Error( 'rest_invalid_param', sprintf( esc_html__( '%s debe ser una hora válida en formato HH:MM.', 'mi-integracion-api' ), $key ), array( 'status' => 400 ) );
	}

	protected function format_verial_response( array $verial_response ) {
		$data_key = '';
		if ( isset( $verial_response['ImagenesArticulos'] ) && is_array( $verial_response['ImagenesArticulos'] ) ) {
			$data_key = 'ImagenesArticulos';
			$this->logger->errorProducto(
				'[MI Integracion API] GetImagenesArticulosWS: Verial devolvió "ImagenesArticulos" en lugar de "Imagenes" como indica el manual.',
				array( 'response' => $verial_response )
			);
		} elseif ( isset( $verial_response['Imagenes'] ) && is_array( $verial_response['Imagenes'] ) ) {
			$data_key = 'Imagenes';
		} else {
			return new \WP_Error(
				'verial_api_malformed_imagenes_data',
				__( 'Los datos de imágenes recibidos de Verial no tienen el formato esperado.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		$imagenes_articulos = array();
		foreach ( $verial_response[ $data_key ] as $imagen_item_verial ) {
			$imagen_base64        = isset( $imagen_item_verial['Imagen'] ) && is_string( $imagen_item_verial['Imagen'] ) ? $imagen_item_verial['Imagen'] : null;
			$imagenes_articulos[] = array(
				'id_articulo'   => isset( $imagen_item_verial['ID_Articulo'] ) ? intval( $imagen_item_verial['ID_Articulo'] ) : null,
				'imagen_base64' => $imagen_base64,
			);
		}
		return $imagenes_articulos;
	}

	public function execute_restful( WP_REST_Request $request ): WP_REST_Response {
		$params            = $request->get_params();
		$sesionwcf         = $params['sesionwcf'];
		$force_refresh     = $params['force_refresh'] ?? false;
		$id_articulo_param = isset( $params['id_articulo_verial'] ) ? absint( $params['id_articulo_verial'] ) : 0;

		$numpixels          = $params['numpixelsladomenor'] ?? 0;
		$fecha_desde_filter = $params['fecha_desde'] ?? null;
		$hora_desde_filter  = $params['hora_desde'] ?? null;

		$cache_params_for_key = array(
			'sesionwcf'          => $sesionwcf,
			'id_articulo'        => $id_articulo_param,
			'numpixelsladomenor' => $numpixels,
			'fecha_desde'        => $fecha_desde_filter,
			'hora_desde'         => $hora_desde_filter,
		);

		if ( ! $force_refresh ) {
			$cached_data = $this->get_cached_data( $cache_params_for_key );
			if ( false !== $cached_data ) {
				return new \WP_REST_Response( $cached_data, 200 );
			}
		}

		$verial_api_params = array(
			'x'                  => $sesionwcf,
			'id_articulo'        => $id_articulo_param,
			'numpixelsladomenor' => $numpixels,
		);
		if ( $fecha_desde_filter !== null ) {
			$verial_api_params['fecha'] = $fecha_desde_filter;
		}
		if ( $hora_desde_filter !== null && $fecha_desde_filter !== null ) {
			$verial_api_params['hora'] = $hora_desde_filter;
		}

		$response_verial = $this->connector->get( self::ENDPOINT_NAME, $verial_api_params );
		if ( is_wp_error( $response_verial ) ) {
			return rest_ensure_response( $response_verial );
		}
		$formatted = $this->format_verial_response( $response_verial );
		if ( is_wp_error( $formatted ) ) {
			return rest_ensure_response( $formatted );
		}

		$this->set_cached_data( $cache_params_for_key, $formatted );
		\LoggerAuditoria::log(
			'Acceso a GetImagenesArticulosWS',
			array(
				'params'    => $verial_api_params,
				'usuario'   => get_current_user_id(),
				'resultado' => 'OK',
			)
		);
		return rest_ensure_response( $formatted );
	}
}
