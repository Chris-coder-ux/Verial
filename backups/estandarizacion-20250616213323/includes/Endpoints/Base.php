<?php
/**
 * Clase base abstracta para los endpoints de la API de Verial.
 * Maneja la conexión, logging básico, y procesamiento común de respuestas.
 */

namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Helpers\RestHelpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class Base {


	/**
	 * @var \MiIntegracionApi\Core\ApiConnector Instancia del conector de la API.
	 */
	protected \MiIntegracionApi\Core\ApiConnector $connector;

	/**
	 * @var string Nombre del endpoint específico en la API de Verial (debe ser definido por la subclase).
	 */
	public const ENDPOINT_NAME = '';

	/**
	 * @var string Prefijo para la clave de caché (debe ser definido por la subclase si usa caché).
	 */
	public const CACHE_KEY_PREFIX = 'mia_endpoint_';

	/**
	 * @var int Duración de la caché en segundos (debe ser definido por la subclase si usa caché).
	 */
	public const CACHE_EXPIRATION = HOUR_IN_SECONDS; // Default a 1 hora, puede ser sobrescrito.

	/**
	 * Constructor.
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $connector Instancia del conector de la API.
	 */
	public function __construct( \MiIntegracionApi\Core\ApiConnector $connector ) {
		$this->connector = $connector;
	}

	/**
	 * Método estático para instanciar la clase.
	 *
	 * @param \MiIntegracionApi\Core\ApiConnector $connector Instancia del conector.
	 * @return static
	 * @phpstan-return static
	 */
	public static function make( \MiIntegracionApi\Core\ApiConnector $connector ): static {
		/** @phpstan-ignore-next-line new.static */
		return new static( $connector );
	}

	/**
	 * Método abstracto para registrar la ruta REST específica del endpoint.
	 * Debe ser implementado por cada subclase.
	 */
	abstract public function register_route(): void;

	/**
	 * Método abstracto para definir los argumentos del endpoint REST.
	 * Debe ser implementado por cada subclase.
	 *
	 * @param bool $is_update
	 * @return array<string, mixed>
	 */
	abstract public function get_endpoint_args( bool $is_update = false ): array;

	/**
	 * Método abstracto para ejecutar la lógica principal del endpoint.
	 * Debe ser implementado por cada subclase.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	abstract public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error;

	/**
	 * Verifica los permisos para acceder al endpoint.
	 * Puede ser sobrescrito por subclases si necesitan permisos diferentes.
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud.
	 * @return bool|\WP_Error True si tiene permiso, WP_Error si no.
	 */
	public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
		if ( ! current_user_can( 'manage_options' ) ) {
			$msg = esc_html__( 'No tienes permiso para realizar esta acción.', 'mi-integracion-api' );
			$msg = is_string( $msg ) ? $msg : 'No tienes permiso para realizar esta acción.';
			return new \WP_Error(
				'rest_forbidden',
				$msg,
				array( 'status' => \MiIntegracionApi\Helpers\RestHelpers::rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Procesa la respuesta cruda de la API de Verial, verificando errores comunes.
	 *
	 * @param array|\WP_Error $verial_response La respuesta del ApiConnector.
	 * @param string $endpoint_context_for_log Contexto para el logging (ej. 'GetArticulosWS').
	 * @return array|\WP_Error Los datos de la respuesta si es exitosa, o WP_Error.
	 */
	protected function process_verial_response( array|\WP_Error $verial_response, string $endpoint_context_for_log = '' ): array|\WP_Error {
		if ( is_wp_error( $verial_response ) ) {
			if ( class_exists( '\\MiIntegracionApi\\helpers\\Logger' ) ) {
				$msg     = $verial_response->get_error_message();
				$log_msg = sprintf( __( "[API Endpoint Error] %s - Error devuelto por ApiConnector: %s", 'mi-integracion-api' ), $endpoint_context_for_log, $msg );
				\MiIntegracionApi\helpers\Logger::error(
					$log_msg,
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			return $verial_response;
		}
		if ( ! is_array( $verial_response ) || ! isset( $verial_response['InfoError'] ) || ! is_array( $verial_response['InfoError'] ) ) {
			if ( class_exists( '\\MiIntegracionApi\\helpers\\Logger' ) ) {
				\MiIntegracionApi\helpers\Logger::error(
					sprintf( __( "[API Endpoint Error] %s - Respuesta inesperada de Verial (sin InfoError o no es array): %s", 'mi-integracion-api' ), $endpoint_context_for_log, print_r( $verial_response, true ) ),
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			// Asegurar que el mensaje es string
			$msg = __( 'Respuesta inesperada de la API de Verial.', 'mi-integracion-api' );
			$msg = is_string( $msg ) ? $msg : 'Respuesta inesperada de la API de Verial.';
			/** @phpstan-ignore-next-line */
			return new \WP_Error(
				'verial_api_unexpected_response',
				$msg,
				array(
					'status'          => 500,
					'verial_response' => $verial_response,
				)
			);
		}
		/** @var array{InfoError: array<string, mixed>} $verial_response */
		$info_error        = $verial_response['InfoError'];
		$codigo            = isset( $info_error['Codigo'] ) && ( is_string( $info_error['Codigo'] ) || is_int( $info_error['Codigo'] ) ) ? (int) $info_error['Codigo'] : -1;
		$error_code_verial = $codigo;
		$reflection        = new \ReflectionClass( get_called_class() );
		$constants         = $reflection->getConstants();
		$success_code      = isset( $constants['VERIAL_ERROR_SUCCESS'] ) ? $constants['VERIAL_ERROR_SUCCESS'] : 0;
		if ( $error_code_verial !== $success_code ) {
			$error_description = isset( $info_error['Descripcion'] ) ? $info_error['Descripcion'] : null;
			$error_message     = '';
			if ( is_string( $error_description ) ) {
				$error_message = $error_description;
			} elseif ( is_int( $error_description ) ) {
				$error_message = (string) $error_description;
			} else {
				$default_msg   = __( 'Error desconocido de Verial.', 'mi-integracion-api' );
				$error_message = is_string( $default_msg ) ? $default_msg : 'Error desconocido de Verial.';
			}
			if ( class_exists( '\\MiIntegracionApi\\helpers\\Logger' ) ) {
				\MiIntegracionApi\helpers\Logger::error(
					sprintf( __( "[API Endpoint Error] %s - Error Verial (Código: %s): %s", 'mi-integracion-api' ), $endpoint_context_for_log, $error_code_verial, $error_message ),
					array( 'context' => 'mia-endpoint-' . strtolower( $endpoint_context_for_log ) )
				);
			}
			$error_slug_name = array_search( $error_code_verial, $constants, true );
			$error_slug      = $error_slug_name ? strtolower( $error_slug_name ) : 'verial_error_' . $error_code_verial;
			$http_status     = 400;
			if ( defined( get_called_class() . '::VERIAL_ERROR_DOC_NOT_FOUND_FOR_MODIFICATION' ) &&
				$error_code_verial === constant( get_called_class() . '::VERIAL_ERROR_DOC_NOT_FOUND_FOR_MODIFICATION' )
			) {
				$http_status = 404;
			} elseif ( defined( get_called_class() . '::VERIAL_ERROR_MODIFICATION_NOT_ALLOWED' ) &&
				$error_code_verial === constant( get_called_class() . '::VERIAL_ERROR_MODIFICATION_NOT_ALLOWED' )
			) {
				$http_status = 403;
			}
			$sanitized_error     = sanitize_text_field( $error_message );
			$final_error_message = is_string( $sanitized_error ) ? $sanitized_error : 'Error en la API de Verial';
			/** @phpstan-ignore-next-line */
			return new \WP_Error(
				'verial_api_error_' . $error_slug,
				$final_error_message,
				array(
					'status'          => $http_status,
					'verial_response' => $verial_response,
				)
			);
		}
		return $verial_response;
	}

	// Métodos de caché movidos a CacheableTrait

	/**
	 * Funciones de validación comunes que pueden ser usadas por las subclases en `get_endpoint_args`.
	 */
	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\WP_Error
	 */
	public function validate_date_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$parts = explode( '-', $value );
			if ( count( $parts ) === 3 && checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] ) ) {
				return true;
			}
		}
		$error_template = esc_html__( '%s debe ser una fecha válida en formato YYYY-MM-DD.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una fecha válida en formato YYYY-MM-DD.';
		/** @phpstan-ignore-next-line */
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_template, $key ), array( 'status' => 400 ) );
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\WP_Error
	 */
	public function validate_time_format_optional( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( $value === '' ) {
			return true;
		}
		if ( preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $value ) ) {
			return true;
		}
		$error_template = esc_html__( '%s debe ser una hora válida en formato HH:MM.', 'mi-integracion-api' );
		$error_template = is_string( $error_template ) ? $error_template : '%s debe ser una hora válida en formato HH:MM.';
		/** @phpstan-ignore-next-line */
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_template, $key ), array( 'status' => 400 ) );
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return float|null
	 */
	public function sanitize_decimal_text_to_float( string $value, \WP_REST_Request $request, string $key ): ?float {
		return $value !== '' ? floatval( str_replace( ',', '.', $value ) ) : null;
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\WP_Error
	 */
	public function validate_positive_numeric_strict( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( is_numeric( $value ) && floatval( $value ) > 0 ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un valor numérico estrictamente positivo.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un valor numérico estrictamente positivo.';
		/** @phpstan-ignore-next-line */
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_msg, $key ), array( 'status' => 400 ) );
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\WP_Error
	 */
	public function validate_email( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( empty( $value ) ) {
			return true;
		}
		if ( is_email( $value ) ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un correo electrónico válido.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un correo electrónico válido.';
		/** @phpstan-ignore-next-line */
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_msg, $key ), array( 'status' => 400 ) );
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return bool|\WP_Error
	 */
	public function validate_phone_number( string $value, \WP_REST_Request $request, string $key ): bool|\WP_Error {
		if ( $value === '' ) {
			return true;
		}
		// Asegurar que es un string antes de preg_replace
		$cleanPhone = preg_replace( '/[\s\-\(\)\+]/', '', (string) $value ); // Forzar string
		// Comprobar que el resultado es un string (lo es siempre, pero PHPStan necesita la garantía)
		$cleanPhone = is_string( $cleanPhone ) ? $cleanPhone : '';
		if ( preg_match( '/^[\d\s\-\(\)\+]{9,}$/', $value ) && preg_match( '/\d{9,}/', $cleanPhone ) ) {
			return true;
		}
		$error_msg = esc_html__( '%s debe ser un número de teléfono válido.', 'mi-integracion-api' );
		$error_msg = is_string( $error_msg ) ? $error_msg : '%s debe ser un número de teléfono válido.';
		/** @phpstan-ignore-next-line */
		return new \WP_Error( 'rest_invalid_param', sprintf( $error_msg, $key ), array( 'status' => 400 ) );
	}

	/**
	 * @param string                  $value
	 * @param \WP_REST_Request<mixed> $request
	 * @param string                  $key
	 * @return string
	 */
	public function sanitize_simple_html( string $value, \WP_REST_Request $request, string $key ): string {
		if ( empty( $value ) ) {
			return '';
		}
		$allowed_tags = array(
			'a'      => array(
				'href'   => array(),
				'title'  => array(),
				'target' => array(),
			),
			'br'     => array(),
			'p'      => array(),
			'b'      => array(),
			'strong' => array(),
			'i'      => array(),
			'em'     => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
		);
		$sanitized    = wp_kses( $value, $allowed_tags );
		return is_string( $sanitized ) ? $sanitized : '';
	}

	/**
	 * Valida los parámetros de la solicitud según reglas definidas
	 *
	 * @param \WP_REST_Request<array> $request La solicitud REST
	 * @param array                   $rules Las reglas de validación
	 * @return array|WP_Error Array con los datos validados o WP_Error si hay error
	 */
	protected function validate_request_params( $request, $rules ) {
		$data = $request->get_params();

		// Usar la nueva clase unificada de validación
		$result = \MiIntegracionApi\Core\InputValidation::validate_data( $data, $rules );

		if ( ! $result['valid'] ) {
			$errors = array();

			foreach ( $result['errors'] as $field => $field_errors ) {
				if ( ! empty( $field_errors ) ) {
					$first_error = reset( $field_errors );
					$errors[]    = sprintf(
						/* translators: 1: nombre del campo, 2: mensaje de error */
						__( 'Campo %1$s: %2$s', 'mi-integracion-api' ),
						$field,
						$first_error['message']
					);
				}
			}

			$error_message = implode( ' ', $errors );

			/** @phpstan-ignore-next-line */
			return new \WP_Error(
				'invalid_parameters',
				$error_message,
				array( 'status' => 400 )
			);
		}

		return $result['sanitized'];
	}

	/**
	 * Sanitiza los parámetros de la solicitud
	 *
	 * @param array $params Los parámetros a sanitizar
	 * @param array $types Los tipos de cada parámetro
	 * @return array Los parámetros sanitizados
	 */
	protected function sanitize_params( $params, $types ) {
		$sanitized = array();

		foreach ( $params as $key => $value ) {
			$type              = isset( $types[ $key ] ) ? $types[ $key ] : 'text';
			$sanitized[ $key ] = \MiIntegracionApi\Core\InputValidation::sanitize( $value, $type );
		}

		return $sanitized;
	}

	/**
	 * Obtiene datos en caché si existen.
	 *
	 * @param string $key La clave de caché
	 * @return mixed|false Los datos en caché o false si no existen
	 */
	public function get_cached_data(string $key) {
		$cached_data = get_transient(static::CACHE_KEY_PREFIX . $key);
		return $cached_data !== false ? $cached_data : null;
	}

	/**
	 * Guarda datos en caché.
	 *
	 * @param string $key La clave de caché
	 * @param mixed $data Los datos a guardar
	 * @param int|null $expiration Tiempo de expiración en segundos
	 * @return bool True si se guardó correctamente, false si no
	 */
	public function set_cached_data(string $key, $data, ?int $expiration = null): bool {
		$expiration = $expiration ?? $this->get_cache_expiration();
		return set_transient(static::CACHE_KEY_PREFIX . $key, $data, $expiration);
	}

	/**
	 * Establece el tiempo de expiración de la caché.
	 *
	 * @param int $seconds Tiempo en segundos.
	 * @return void
	 */
	protected $cache_expiration;

	public function set_cache_expiration(int $seconds): void {
		$this->cache_expiration = $seconds;
	}

	public function get_cache_expiration(): int {
		return $this->cache_expiration ?? static::CACHE_EXPIRATION;
	}

	/**
	 * Devuelve una respuesta estándar de éxito para endpoints.
	 *
	 * @param mixed $data Datos principales a devolver (array, objeto, etc.)
	 * @param array $extra (opcional) Datos extra a incluir en la respuesta raíz
	 * @return array Respuesta estándar: ['success' => true, 'data' => $data, ...$extra]
	 *
	 * @example
	 *   return $this->format_success_response($datos_formateados);
	 */
	protected function format_success_response($data, array $extra = []): array {
		return array_merge([
			'success' => true,
			'data'    => $data,
		], $extra);
	}
}
