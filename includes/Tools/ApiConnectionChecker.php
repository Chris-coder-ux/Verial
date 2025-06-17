<?php
/**
 * Clase para verificar la conectividad con la API de Verial ERP
 * desde la interfaz de administración.
 *
 * @package mi-integracion-api
 * @since 1.0.0
 */

namespace MiIntegracionApi\Tools;

use MiIntegracionApi\Core\ApiConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para verificar la conectividad con la API de Verial ERP
 */
class ApiConnectionChecker {
	/**
	 * @var ApiConnector|null Instancia del conector API
	 */
	private $api_connector;

	/**
	 * @var string|null Error de inicialización si el ApiConnector falló
	 */
	private $init_error;

	/**
	 * Constructor
	 */
	public function __construct() {
		// Obtener configuración guardada en WordPress
		$options = get_option('mi_integracion_api_ajustes', array());
		$config = [
			'api_url' => isset($options['mia_url_base']) ? $options['mia_url_base'] : '',
			'sesionwcf' => isset($options['mia_numero_sesion']) ? $options['mia_numero_sesion'] : 18
		];
		
		try {
			// Crear un logger para ApiConnectionChecker
			$logger = new \MiIntegracionApi\Helpers\Logger('api-connection-checker');
			$this->api_connector = new ApiConnector($logger);
			
			// Configurar la API URL y sesión WCF
			if (isset($config['api_url'])) {
				$this->api_connector->set_api_url($config['api_url']);
			}
			if (isset($config['sesionwcf'])) {
				$this->api_connector->set_sesion_wcf($config['sesionwcf']);
			}
			
			$this->init_error = null;
		} catch (\Exception $e) {
			$this->api_connector = null;
			$this->init_error = $e->getMessage();
			
			// Log del error si el logger está disponible
			if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('api_connection_checker');
				$logger->log('[ERROR] Error al inicializar ApiConnector en ApiConnectionChecker: ' . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
			}
		}
	}

	/**
	 * Registra los hooks necesarios
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_mi_check_api_connection', array( $this, 'ajax_check_connection' ) );
	}

	/**
	 * Realiza la comprobación de conexión vía AJAX
	 */
	public function ajax_check_connection() {
		// Log de diagnóstico al inicio del handler AJAX
		if (class_exists('MiIntegracionApi\\Helpers\\Logger')) {
			$logger = new \MiIntegracionApi\Helpers\Logger('api_connector');
			$logger->info('[DEBUG][AJAX] ajax_check_connection() llamado desde AJAX', [
				'user_id' => get_current_user_id(),
				'request_method' => $_SERVER['REQUEST_METHOD'] ?? null,
				'request_uri' => $_SERVER['REQUEST_URI'] ?? null,
				'post_data' => $_POST,
			]);
		}

		// Verificar seguridad
		check_ajax_referer( 'mi_api_connection_check', 'security' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api' ),
				)
			);
		}

		// Verificar si el ApiConnector se pudo inicializar
		if ($this->api_connector === null) {
			wp_send_json_error(
				array(
					'message' => __( 'Error de configuración:', 'mi-integracion-api' ) . ' ' . $this->init_error,
					'errors'  => [$this->init_error],
					'step'    => 'initialization',
				)
			);
		}

		// Verificar configuración y conexión
		$config_result = $this->api_connector->check_api_config();

		if ( ! $config_result['is_valid'] ) {
			wp_send_json_error(
				array(
					'message' => __( 'La configuración de la API es inválida:', 'mi-integracion-api' ) . ' ' .
							implode( '; ', $config_result['errors'] ),
					'errors'  => $config_result['errors'],
					'step'    => 'config',
				)
			);
		}

		// Si la configuración es válida, probar la conectividad
		$connectivity_result = $this->api_connector->test_connectivity();

		if ($connectivity_result !== true) {
			wp_send_json_error(
				array(
					'message' => __( 'No se pudo conectar con la API:', 'mi-integracion-api' ) . ' ' . $connectivity_result,
					'url' => $this->api_connector->get_last_request_url(),
					'step' => 'connectivity',
				)
			);
		}

		// Conexión exitosa
		wp_send_json_success(
			array(
				'message' => __( 'Conexión exitosa con Verial ERP.', 'mi-integracion-api' ),
				'url' => $this->api_connector->get_last_request_url(),
			)
		);
	}

	/**
	 * Renderiza el botón de comprobación de conexión
	 */
	public function render_check_button() {
		$nonce = wp_create_nonce( 'mi_api_connection_check' );

		?>
		<div class="mi-api-connection-check">
			<button type="button" class="button button-secondary" id="mi-check-api-connection">
				<?php _e( 'Comprobar conexión con API', 'mi-integracion-api' ); ?>
			</button>
			<span class="spinner" style="float: none; margin-left: 5px;"></span>
			<div class="mi-connection-result" style="margin-top: 10px;"></div>
			
			<script>
			jQuery(document).ready(function($) {
				$('#mi-check-api-connection').on('click', function() {
					var $button = $(this);
					var $spinner = $button.next('.spinner');
					var $result = $('.mi-connection-result');
					
					$button.prop('disabled', true);
					$spinner.addClass('is-active');
					$result.html('');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'mi_check_api_connection',
							security: '<?php echo $nonce; ?>'
						},
						success: function(response) {
							if (response.success) {
								$result.html('<div class="notice notice-success"><p>' + 
									response.data.message + ' ' +
									'<?php _e( 'Versión:', 'mi-integracion-api' ); ?> ' + 
									response.data.version + '</p></div>');
							} else {
								$result.html('<div class="notice notice-error"><p>' + 
									response.data.message + '</p></div>');
							}
						},
						error: function() {
							$result.html('<div class="notice notice-error"><p>' + 
								'<?php _e( 'Error al procesar la solicitud. Por favor, inténtalo de nuevo.', 'mi-integracion-api' ); ?>' +
								'</p></div>');
						},
						complete: function() {
							$button.prop('disabled', false);
							$spinner.removeClass('is-active');
						}
					});
				});
			});
			</script>
		</div>
		<?php
	}
}

// Inicialización
$checker = new ApiConnectionChecker();
$checker->register_hooks();
