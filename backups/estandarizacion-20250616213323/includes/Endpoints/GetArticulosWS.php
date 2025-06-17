<?php
namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Traits\CacheableTrait;
use MiIntegracionApi\helpers\Logger;
use MiIntegracionApi\Helpers\AuthHelper;
use MiIntegracionApi\Helpers\rest_authorization_required_code;
use MiIntegracionApi\Helpers\Utils;
use MiIntegracionApi\Helpers\EndpointArgs;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar el endpoint GetArticulosWS
 */
class GetArticulosWS extends Base {

	use EndpointLogger;
	use CacheableTrait;

	public const ENDPOINT_NAME    = 'GetArticulosWS';
	public const CACHE_KEY_PREFIX = 'mia_articulos_';
	public const CACHE_EXPIRATION = 21600;

	public function get_endpoint_args( bool $is_update = false ): array {
		return array(
			'sesionwcf'     => EndpointArgs::sesionwcf(),
			'force_refresh' => EndpointArgs::force_refresh(),
			'fecha_desde'   => array(
				'validate_callback' => function($value) {
					// Validación centralizada de fecha opcional
					return Utils::is_valid_date_format_optional($value) ? true : new \WP_Error('rest_invalid_param', __('fecha_desde debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api'), array('status' => 400));
				}
			),
			'hora_desde'    => array(
				'validate_callback' => function($value) {
					// Validación centralizada de hora opcional
					return Utils::is_valid_time_format_optional($value) ? true : new \WP_Error('rest_invalid_param', __('hora_desde debe ser una hora válida en formato HH:MM o HH:MM:SS.', 'mi-integracion-api'), array('status' => 400));
				}
			),
			'context'       => EndpointArgs::context(),
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $verial_data_success
	 * @return array<int, array<string, mixed>>
	 */
	protected function format_specific_data( array $verial_data_success ): array {
		$formatted_articulos = array();
		foreach ( $verial_data_success as $articulo_verial ) {
			if ( is_array( $articulo_verial ) ) {
				$formatted_articulos[] = array(
					'id_verial'    => $articulo_verial['Id'] ?? null,
					'nombre'       => $articulo_verial['Nombre'] ?? '',
					'descripcion'  => $articulo_verial['Descripcion'] ?? '',
					'precio'       => $articulo_verial['Precio'] ?? 0.0,
					'stock'        => $articulo_verial['Stock'] ?? 0,
					'categoria'    => $articulo_verial['Categoria'] ?? '',
					'sku'          => $articulo_verial['SKU'] ?? '',
					'estado'       => $articulo_verial['Estado'] ?? 'activo',
					'fecha_modif'  => $articulo_verial['FechaModificacion'] ?? null,
				);
			}
		}
		return $formatted_articulos;
	}

	/**
	 * Ejecuta la lógica del endpoint.
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$params = $request->get_params();
			// Validación de fecha y hora usando Helpers\Utils (centralizado)
			if (!empty($params['fecha_desde']) && !Utils::is_valid_date_format_optional($params['fecha_desde'])) {
				return new \WP_Error('rest_invalid_param', __('El formato de fecha debe ser YYYY-MM-DD', 'mi-integracion-api'), array('status' => 400));
			}
			if (!empty($params['hora_desde']) && !Utils::is_valid_time_format_optional($params['hora_desde'])) {
				return new \WP_Error('rest_invalid_param', __('El formato de hora debe ser HH:MM o HH:MM:SS', 'mi-integracion-api'), array('status' => 400));
			}

			// Validar parámetros requeridos
			if ( empty( $params['sesionwcf'] ) ) {
				return new \WP_Error(
					'rest_missing_callback_param',
					__( 'Falta el parámetro sesionwcf', 'mi-integracion-api' ),
					array( 'status' => 400 )
				);
			}

			$force_refresh = ! empty( $params['force_refresh'] );
			$sesion_wcf = absint( $params['sesionwcf'] );
			
			// Construir clave de caché
			$cache_key = self::CACHE_KEY_PREFIX . $sesion_wcf;
			if ( ! empty( $params['fecha_desde'] ) ) {
				$cache_key .= '_' . $params['fecha_desde'];
			}
			if ( ! empty( $params['hora_desde'] ) ) {
				$cache_key .= '_' . $params['hora_desde'];
			}

			// Intentar obtener de caché si no se fuerza refresco
			if ( ! $force_refresh ) {
				$cached_data = $this->get_cached_data( $cache_key );
				if ( false !== $cached_data ) {
					return new \WP_REST_Response( $cached_data, 200 );
				}
			}

			// Preparar parámetros para la API
			$api_params = array(
				'sesionwcf' => $sesion_wcf,
			);
			if ( ! empty( $params['fecha_desde'] ) ) {
				$api_params['fecha_desde'] = $params['fecha_desde'];
			}
			if ( ! empty( $params['hora_desde'] ) ) {
				$api_params['hora_desde'] = $params['hora_desde'];
			}

			// Obtener datos de la API
			$api_connector = new \MiIntegracionApi\Core\ApiConnector();
			$response = $api_connector->get( 'GetArticulos', $api_params );

			// Procesar respuesta
			$processed_response = $this->process_verial_response( $response, self::ENDPOINT_NAME );
			if ( is_wp_error( $processed_response ) ) {
				return $processed_response;
			}

			// Formatear datos usando la función base estandarizada
			$formatted_data = $this->format_success_response(
				$this->format_specific_data( $processed_response )
			);

			// Guardar en caché
			$this->set_cached_data( $cache_key, $formatted_data, self::CACHE_EXPIRATION );

			return new \WP_REST_Response( $formatted_data, 200 );

		} catch ( \Exception $e ) {
			return new \WP_Error(
				'rest_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Registra la ruta del endpoint.
	 */
	public function register_route(): void {
		register_rest_route(
			'mi-integracion-api/v1',
			'/articulos',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'execute_restful' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'               => $this->get_endpoint_args(),
				),
			)
		);
	}
}

// Nota: Para toda validación de fecha/hora y formateo de respuesta, utilice siempre los métodos centralizados de Helpers\Utils y Base para mantener la coherencia y robustez.
