<?php
/**
 * Clase para gestionar la seguridad del plugin.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 */

namespace MiIntegracionApi;



// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para manejar la seguridad del plugin.
 *
 * Esta clase proporciona métodos para validar nonces, verificar permisos
 * de usuario, sanitizar entradas y proteger contra vulnerabilidades comunes.
 *
 * @since 1.0.0
 */
class Security {

	/**
	 * Instancia única de esta clase (patrón Singleton).
	 *
	 * @since 1.0.0
	 * @access   private
	 * @var      MI_Security    $instance    La única instancia de esta clase.
	 */
	private static $instance = null;

	/**
	 * Instancia del logger.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      MiIntegracionApi\Helpers\Logger    $logger    Instancia del logger.
	 */
	protected $logger;

	/**
	 * Tiempo de duración de los nonces en segundos.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @var      int    $nonce_lifetime    Tiempo de vida de los nonces.
	 */
	protected $nonce_lifetime;

	/**
	 * Constructor privado para implementar el patrón Singleton.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// Inicializar logger si existe la clase
		if ( class_exists( 'MiIntegracionApi\\Helpers\\Logger' ) ) {
			$this->logger = new MiIntegracionApi\Helpers\Logger( 'security' );
		}

		// Configurar duración de nonces
		$this->nonce_lifetime = (int) apply_filters(
			'mi_integracion_api_nonce_lifetime',
			24 * HOUR_IN_SECONDS // 24 horas por defecto
		);

		// Inicializar hooks de seguridad
		$this->init_security_hooks();
	}

	/**
	 * Obtiene la instancia única de esta clase.
	 *
	 * @since 1.0.0
	 * @return   MI_Security    La única instancia de esta clase.
	 */
	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Inicializa hooks relacionados con la seguridad.
	 *
	 * @since 1.0.0
	 * @access   protected
	 */
	protected function init_security_hooks() {
		// Agregar filtro para extender duración de nonces
		add_filter( 'nonce_life', array( $this, 'extend_nonce_lifetime' ) );

		// Protección contra ejecución de código en los logs
		add_filter( 'mi_integracion_api_log_data', array( $this, 'sanitize_log_data' ) );

		// Agregar seguridad a la API REST
		add_filter( 'rest_authentication_errors', array( $this, 'validate_rest_access' ), 90 );

		// CORS para la API REST
		add_action( 'rest_api_init', array( $this, 'handle_cors' ) );
	}

	/**
	 * Extiende el tiempo de vida de los nonces de WordPress.
	 *
	 * @since 1.0.0
	 * @param    int $lifetime    Duración actual en segundos.
	 * @return   int                 Nueva duración en segundos.
	 */
	public function extend_nonce_lifetime( $lifetime ) {
		// Solo modificar para nonces del plugin
		$current_action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';

		if ( strpos( $current_action, MiIntegracionApi_OPTION_PREFIX ) === 0 ||
			strpos( $current_action, str_replace( '_', '-', MiIntegracionApi_OPTION_PREFIX ) ) === 0 ) {
			return $this->nonce_lifetime;
		}

		// Para otros nonces, mantener el valor original
		return $lifetime;
	}

	/**
	 * Verifica un nonce de WordPress.
	 *
	 * @since 1.0.0
	 * @param    string  $nonce         El valor del nonce a verificar.
	 * @param    string  $action        Acción asociada al nonce.
	 * @param    boolean $die           Si debe terminar la ejecución en caso de fallo.
	 * @return   boolean                  True si el nonce es válido, false en caso contrario.
	 */
	public function verify_nonce( $nonce, $action, $die = true ) {
		// Prepender prefijo si no está presente
		if ( strpos( $action, MiIntegracionApi_NONCE_PREFIX ) !== 0 ) {
			$action = MiIntegracionApi_NONCE_PREFIX . $action;
		}

		// Verificar nonce
		$valid = wp_verify_nonce( $nonce, $action );

		// Registrar intento fallido
		if ( ! $valid && $this->logger ) {
			$this->logger->warning(
				'Intento de verificación de nonce fallido',
				array(
					'action'  => $action,
					'user_id' => get_current_user_id(),
				)
			);
		}

		// Terminar ejecución si se solicita
		if ( ! $valid && $die ) {
			wp_die(
				esc_html__( 'Error de seguridad: Nonce inválido. Por favor, recarga la página e intenta nuevamente.', MiIntegracionApi_TEXT_DOMAIN ),
				esc_html__( 'Error de Seguridad', MiIntegracionApi_TEXT_DOMAIN ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return (bool) $valid;
	}

	/**
	 * Verifica si un usuario tiene los permisos necesarios.
	 *
	 * @since 1.0.0
	 * @param    string  $capability     Capacidad requerida.
	 * @param    int     $object_id      ID del objeto para capacidades específicas.
	 * @param    boolean $die            Si debe terminar la ejecución en caso de fallo.
	 * @return   boolean                   True si el usuario tiene permiso, false en caso contrario.
	 */
	public function verify_capability( $capability, $object_id = null, $die = true ) {
		$user_id = get_current_user_id();

		// Si no hay usuario autenticado, denegar acceso
		if ( ! $user_id ) {
			if ( $die ) {
				wp_die(
					esc_html__( 'Necesitas iniciar sesión para realizar esta acción.', MiIntegracionApi_TEXT_DOMAIN ),
					esc_html__( 'Acceso Denegado', MiIntegracionApi_TEXT_DOMAIN ),
					array(
						'response'  => 401,
						'back_link' => true,
					)
				);
			}
			return false;
		}

		// Verificar capacidad
		$has_capability = $object_id ?
			current_user_can( $capability, $object_id ) :
			current_user_can( $capability );

		// Registrar intento fallido
		if ( ! $has_capability && $this->logger ) {
			$this->logger->warning(
				'Intento de acceso no autorizado',
				array(
					'capability' => $capability,
					'object_id'  => $object_id,
					'user_id'    => $user_id,
				)
			);
		}

		// Terminar ejecución si se solicita
		if ( ! $has_capability && $die ) {
			wp_die(
				esc_html__( 'No tienes permisos para realizar esta acción.', MiIntegracionApi_TEXT_DOMAIN ),
				esc_html__( 'Acceso Denegado', MiIntegracionApi_TEXT_DOMAIN ),
				array(
					'response'  => 403,
					'back_link' => true,
				)
			);
		}

		return $has_capability;
	}

	/**
	 * Sanitiza y valida datos de formulario basado en reglas especificadas.
	 *
	 * @since 1.0.0
	 * @param    array $data          Datos a validar.
	 * @param    array $rules         Reglas de validación.
	 * @return   array                    Datos sanitizados o errores.
	 */
	public function validate_form_data( $data, $rules ) {
		$sanitized = array();
		$errors    = array();

		foreach ( $rules as $field => $rule ) {
			$value = isset( $data[ $field ] ) ? $data[ $field ] : null;

			// Verificar si el campo es requerido
			if ( isset( $rule['required'] ) && $rule['required'] && ( $value === null || $value === '' ) ) {
				$errors[ $field ] = isset( $rule['error_message'] ) ?
									$rule['error_message'] :
									sprintf( __( 'El campo "%s" es requerido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
				continue;
			}

			// Si el campo no tiene valor y no es requerido, saltar validaciones
			if ( $value === null || $value === '' ) {
				// Si hay valor predeterminado, usarlo
				if ( isset( $rule['default'] ) ) {
					$sanitized[ $field ] = $rule['default'];
				}
				continue;
			}

			// Sanitizar según el tipo
			$type = isset( $rule['type'] ) ? $rule['type'] : 'text';

			switch ( $type ) {
				case 'email':
					$value = sanitize_email( $value );
					if ( ! is_email( $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser un email válido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
					break;

				case 'url':
					$value = esc_url_raw( $value );
					if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser una URL válida.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
					break;

				case 'number':
					if ( ! is_numeric( $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser un número.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					} else {
						$value = (float) $value;

						// Verificar mínimo y máximo
						if ( isset( $rule['min'] ) && $value < $rule['min'] ) {
							$errors[ $field ] = isset( $rule['error_message'] ) ?
												$rule['error_message'] :
												sprintf( __( 'El campo "%1$s" debe ser mayor o igual a %2$s.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['min'] );
						}

						if ( isset( $rule['max'] ) && $value > $rule['max'] ) {
							$errors[ $field ] = isset( $rule['error_message'] ) ?
												$rule['error_message'] :
												sprintf( __( 'El campo "%1$s" debe ser menor o igual a %2$s.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['max'] );
						}
					}
					break;

				case 'integer':
					if ( ! is_numeric( $value ) || intval( $value ) != $value ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser un número entero.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					} else {
						$value = intval( $value );

						// Verificar mínimo y máximo
						if ( isset( $rule['min'] ) && $value < $rule['min'] ) {
							$errors[ $field ] = isset( $rule['error_message'] ) ?
												$rule['error_message'] :
												sprintf( __( 'El campo "%1$s" debe ser mayor o igual a %2$s.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['min'] );
						}

						if ( isset( $rule['max'] ) && $value > $rule['max'] ) {
							$errors[ $field ] = isset( $rule['error_message'] ) ?
												$rule['error_message'] :
												sprintf( __( 'El campo "%1$s" debe ser menor o igual a %2$s.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['max'] );
						}
					}
					break;

				case 'boolean':
					$value = (bool) $value;
					break;

				case 'array':
					if ( ! is_array( $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser una lista.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					} else {
						// Si hay reglas para cada elemento del array
						if ( isset( $rule['items'] ) ) {
							$sanitized_array = array();

							foreach ( $value as $index => $item ) {
								// Validar cada elemento como un campo independiente
								$item_result = $this->validate_form_data(
									array( 'item' => $item ),
									array( 'item' => $rule['items'] )
								);

								if ( isset( $item_result['errors']['item'] ) ) {
									$errors[ $field ] = isset( $rule['error_message'] ) ?
														$rule['error_message'] :
														sprintf( __( 'El elemento %1$d del campo "%2$s" no es válido.', MiIntegracionApi_TEXT_DOMAIN ), $index, $field );
									break;
								}

								$sanitized_array[] = $item_result['item'];
							}

							if ( ! isset( $errors[ $field ] ) ) {
								$value = $sanitized_array;
							}
						}
					}
					break;

				case 'date':
					// Validar formato de fecha
					$format = isset( $rule['format'] ) ? $rule['format'] : 'Y-m-d';
					$date   = \DateTime::createFromFormat( $format, $value );

					if ( ! $date || $date->format( $format ) !== $value ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%1$s" debe ser una fecha válida en formato %2$s.', MiIntegracionApi_TEXT_DOMAIN ), $field, $format );
					}
					break;

				case 'html':
					$allowed_html = isset( $rule['allowed_html'] ) ? $rule['allowed_html'] : 'post';
					$value        = wp_kses( $value, $allowed_html );
					break;

				case 'json':
					if ( ! $this->is_valid_json( $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" debe ser un JSON válido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
					break;

				case 'select':
					if ( isset( $rule['options'] ) && ! in_array( $value, $rule['options'] ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El valor seleccionado para "%s" no es válido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
					break;

				case 'checkbox':
					$value = (bool) $value;
					break;

				case 'api_key':
					// Sanitizar como texto pero verificar formato habitual de API keys
					$value = sanitize_text_field( $value );
					if ( ! preg_match( '/^[a-zA-Z0-9_\-\.]{8,64}$/', $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%s" no parece ser una API key válida.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
					break;

				case 'password':
					// No aplicamos sanitización para no alterar la contraseña
					// pero podríamos verificar requisitos mínimos
					if ( isset( $rule['min_length'] ) && strlen( $value ) < $rule['min_length'] ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%1$s" debe tener al menos %2$d caracteres.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['min_length'] );
					}
					break;

				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;

				case 'text':
				default:
					$value = sanitize_text_field( $value );

					// Verificar longitud
					if ( isset( $rule['min_length'] ) && mb_strlen( $value ) < $rule['min_length'] ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%1$s" debe tener al menos %2$d caracteres.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['min_length'] );
					}

					if ( isset( $rule['max_length'] ) && mb_strlen( $value ) > $rule['max_length'] ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El campo "%1$s" debe tener máximo %2$d caracteres.', MiIntegracionApi_TEXT_DOMAIN ), $field, $rule['max_length'] );
					}

					// Verificar patrón si existe
					if ( isset( $rule['pattern'] ) && ! preg_match( $rule['pattern'], $value ) ) {
						$errors[ $field ] = isset( $rule['error_message'] ) ?
											$rule['error_message'] :
											sprintf( __( 'El formato del campo "%s" no es válido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
					}
			}

			// Aplicar función de validación personalizada si existe
			if ( isset( $rule['custom_validate'] ) && is_callable( $rule['custom_validate'] ) ) {
				$custom_result = call_user_func( $rule['custom_validate'], $value );

				if ( $custom_result !== true ) {
					$errors[ $field ] = is_string( $custom_result ) ?
										$custom_result :
										sprintf( __( 'El campo "%s" no es válido.', MiIntegracionApi_TEXT_DOMAIN ), $field );
				}
			}

			// Si no hay errores para este campo, agregarlo a los datos sanitizados
			if ( ! isset( $errors[ $field ] ) ) {
				$sanitized[ $field ] = $value;
			}
		}

		// Devolver resultado
		return array(
			'sanitized' => $sanitized,
			'errors'    => $errors,
			'is_valid'  => empty( $errors ),
		);
	}

	/**
	 * Verifica si una cadena es un JSON válido.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $string    Cadena a verificar.
	 * @return   boolean              True si es JSON válido, false en caso contrario.
	 */
	protected function is_valid_json( $string ) {
		if ( ! is_string( $string ) ) {
			return false;
		}

		json_decode( $string );
		return json_last_error() === JSON_ERROR_NONE;
	}

	/**
	 * Sanitiza datos para registro en log.
	 *
	 * @since 1.0.0
	 * @param    array $data    Datos a sanitizar.
	 * @return   array              Datos sanitizados.
	 */
	public function sanitize_log_data( $data ) {
		// Si no es array, devolver como está
		if ( ! is_array( $data ) ) {
			return $data;
		}

		// Lista de claves sensibles a ocultar
		$sensitive_keys = array(
			'password',
			'pass',
			'pwd',
			'api_key',
			'api_secret',
			'token',
			'auth',
			'secret',
			'private',
			'key',
			'credentials',
			'contraseña',
		);

		// Recorrer datos y ocultar valores sensibles
		foreach ( $data as $key => $value ) {
			// Verificar si la clave es sensible
			$is_sensitive = false;

			foreach ( $sensitive_keys as $sensitive ) {
				if ( stripos( $key, $sensitive ) !== false ) {
					$is_sensitive = true;
					break;
				}
			}

			// Ocultar valor si es sensible
			if ( $is_sensitive ) {
				$data[ $key ] = '********';
			}
			// Procesar subarray si es necesario
			elseif ( is_array( $value ) ) {
				$data[ $key ] = $this->sanitize_log_data( $value );
			}
		}

		return $data;
	}

	/**
	 * Maneja CORS para la API REST.
	 *
	 * @since 1.0.0
	 * @return   void
	 */
	public function handle_cors() {
		// Solo procesar para endpoints del plugin
		if ( strpos( $_SERVER['REQUEST_URI'], MiIntegracionApi_TEXT_DOMAIN ) === false ) {
			return;
		}

		// Verificar si CORS está habilitado
		$enable_cors = defined( 'MIA_ENABLE_CORS' ) ? MIA_ENABLE_CORS : false;

		if ( ! $enable_cors ) {
			return;
		}

		// Obtener dominios permitidos
		$allowed_origins = (array) get_option(
			MiIntegracionApi_OPTION_PREFIX . 'cors_origins',
			array( '*' ) // Por defecto permitir cualquier origen
		);

		// Obtener origen actual
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? $_SERVER['HTTP_ORIGIN'] : '';

		// Si el origen está permitido o se permite cualquier origen
		if ( in_array( '*', $allowed_origins ) || in_array( $origin, $allowed_origins ) ) {
			header( 'Access-Control-Allow-Origin: ' . esc_attr( $origin ) );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key' );

			// Para solicitudes OPTIONS, detener aquí
			if ( $_SERVER['REQUEST_METHOD'] == 'OPTIONS' ) {
				status_header( 200 );
				exit;
			}
		}
	}

	/**
	 * Valida acceso a la API REST.
	 *
	 * @since 1.0.0
	 * @param    WP_Error|null|boolean $errors    Errores existentes.
	 * @return   WP_Error|null|boolean               Errores actualizados o null.
	 */
	public function validate_rest_access( $errors ) {
		// Si ya hay errores, no hacer nada
		if ( is_wp_error( $errors ) ) {
			return $errors;
		}

		// Solo procesar para endpoints del plugin
		if ( strpos( $_SERVER['REQUEST_URI'], '/' . MiIntegracionApi_TEXT_DOMAIN . '/' ) === false ) {
			return $errors;
		}

		// Verificar autenticación
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

		// Verificar token JWT si viene en cabecera
		$auth_header = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? $_SERVER['HTTP_AUTHORIZATION'] : '';

		if ( strpos( $auth_header, 'Bearer ' ) === 0 ) {
			$token = substr( $auth_header, 7 );

			// Validar token con AuthHelper
			if ( class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
				$is_valid = MiIntegracionApi\Helpers\AuthHelper::validate_jwt( $token );

				if ( $is_valid === true ) {
					// Token válido, permitir acceso
					return true;
				} else {
					// Token inválido, devolver error
					return new \WP_Error(
						'invalid_token',
						__( 'Token de autenticación inválido.', MiIntegracionApi_TEXT_DOMAIN ),
						array( 'status' => 401 )
					);
				}
			}
		}

		// Verificar API Key si viene en cabecera
		$api_key = isset( $_SERVER['HTTP_X_API_KEY'] ) ? $_SERVER['HTTP_X_API_KEY'] : '';

		if ( ! empty( $api_key ) ) {
			// Validar API Key
			$valid_api_key = get_option( MiIntegracionApi_OPTION_PREFIX . 'api_key', '' );

			if ( $api_key === $valid_api_key ) {
				// API Key válida, permitir acceso
				return true;
			} else {
				// API Key inválida, devolver error
				return new \WP_Error(
					'invalid_api_key',
					__( 'API Key inválida.', MiIntegracionApi_TEXT_DOMAIN ),
					array( 'status' => 401 )
				);
			}
		}

		// Para endpoints públicos, devolver null para continuar con la autenticación estándar
		if ( $this->is_public_endpoint( $request_path ) ) {
			return null;
		}

		// Para los demás endpoints, requerir autenticación
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_not_logged_in',
				__( 'Necesitas iniciar sesión para acceder a este recurso.', MiIntegracionApi_TEXT_DOMAIN ),
				array( 'status' => 401 )
			);
		}

		// Continuar con la autenticación estándar
		return $errors;
	}

	/**
	 * Determina si un endpoint es público.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @param    string $path    Ruta del endpoint.
	 * @return   boolean            True si es público, false en caso contrario.
	 */
	protected function is_public_endpoint( $path ) {
		// Lista de endpoints públicos
		$public_endpoints = array(
			'status',
			'products',
			'catalog',
		);

		// Verificar si la ruta coincide con algún endpoint público
		foreach ( $public_endpoints as $endpoint ) {
			if ( strpos( $path, '/' . MiIntegracionApi_TEXT_DOMAIN . '/v1/' . $endpoint ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Genera un token de autenticación JWT.
	 *
	 * @since 1.0.0
	 * @param    array $payload    Datos a incluir en el token.
	 * @param    int   $expiry     Tiempo de expiración en segundos.
	 * @return   string                Token generado.
	 */
	public function generate_auth_token( $payload, $expiry = 3600 ) {
		if ( ! class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			return '';
		}

		return MiIntegracionApi\Helpers\AuthHelper::generate_jwt( $payload, $expiry );
	}

	/**
	 * Valida un token de autenticación JWT.
	 *
	 * @since 1.0.0
	 * @param    string $token    Token a validar.
	 * @return   boolean|array       Payload si es válido, false en caso contrario.
	 */
	public function validate_auth_token( $token ) {
		if ( ! class_exists( 'MiIntegracionApi\\Helpers\\AuthHelper' ) ) {
			return false;
		}

		return MiIntegracionApi\Helpers\AuthHelper::validate_jwt( $token );
	}

	/**
	 * Encripta datos sensibles.
	 *
	 * @since 1.0.0
	 * @param    string $data    Datos a encriptar.
	 * @return   string             Datos encriptados.
	 */
	public function encrypt_data( $data ) {
		if ( empty( $data ) ) {
			return '';
		}

		// Obtener clave de encriptación
		$encryption_key = $this->get_encryption_key();

		// Si no hay clave disponible, devolver datos codificados en base64
		if ( empty( $encryption_key ) ) {
			return base64_encode( $data );
		}

		// Generar IV aleatorio
		$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );
		$iv        = openssl_random_pseudo_bytes( $iv_length );

		// Encriptar datos
		$encrypted = openssl_encrypt(
			$data,
			'aes-256-cbc',
			$encryption_key,
			OPENSSL_RAW_DATA,
			$iv
		);

		// Combinar IV y datos encriptados
		$encrypted_data = $iv . $encrypted;

		// Codificar en base64 para almacenamiento seguro
		return base64_encode( $encrypted_data );
	}

	/**
	 * Desencripta datos sensibles.
	 *
	 * @since 1.0.0
	 * @param    string $encrypted_data    Datos encriptados.
	 * @return   string                       Datos originales o cadena vacía en caso de error.
	 */
	public function decrypt_data( $encrypted_data ) {
		if ( empty( $encrypted_data ) ) {
			return '';
		}

		// Decodificar de base64
		$data = base64_decode( $encrypted_data );

		// Obtener clave de encriptación
		$encryption_key = $this->get_encryption_key();

		// Si no hay clave disponible, asumir que son solo datos codificados en base64
		if ( empty( $encryption_key ) ) {
			return $data;
		}

		try {
			// Obtener tamaño del IV
			$iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

			// Extraer IV y datos encriptados
			$iv        = substr( $data, 0, $iv_length );
			$encrypted = substr( $data, $iv_length );

			// Desencriptar
			$decrypted = openssl_decrypt(
				$encrypted,
				'aes-256-cbc',
				$encryption_key,
				OPENSSL_RAW_DATA,
				$iv
			);

			return $decrypted !== false ? $decrypted : '';
		} catch ( \Exception $e ) {
			if ( $this->logger ) {
				$this->logger->error(
					'Error al desencriptar datos',
					array(
						'error' => $e->getMessage(),
					)
				);
			}

			return '';
		}
	}

	/**
	 * Obtiene la clave de encriptación.
	 *
	 * @since 1.0.0
	 * @access   protected
	 * @return   string    Clave de encriptación.
	 */
	protected function get_encryption_key() {
		// Intentar usar clave definida en wp-config.php
		if ( defined( 'MiIntegracionApi_ENCRYPTION_KEY' ) && ! empty( MiIntegracionApi_ENCRYPTION_KEY ) ) {
			return MiIntegracionApi_ENCRYPTION_KEY;
		}

		// Usar constantes de WordPress como fallback
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_KEY' ) ) {
			return substr( hash( 'sha256', AUTH_KEY . SECURE_AUTH_KEY ), 0, 32 );
		}

		// Si no hay clave disponible, usar clave derivada del sitio
		$site_url = get_site_url();
		return substr( hash( 'sha256', 'mi_integracion_api_' . $site_url ), 0, 32 );
	}
}
