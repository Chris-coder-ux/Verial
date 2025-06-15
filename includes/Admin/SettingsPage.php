<?php

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {
	public static function render() {
		// Guardar la API key cifrada si se envía el formulario
		if ( $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['api_key'] ) ) {
			$api_key = sanitize_text_field( $_POST['api_key'] );
			\MiIntegracionApi\Helpers\SettingsHelper::save_api_key( $api_key );
			
			// Registrar la acción en el log
			if (class_exists('\MiIntegracionApi\Helpers\Logger')) {
				$logger = new \MiIntegracionApi\Helpers\Logger('settings');
				$logger->info('API Key actualizada', [
					'accion' => 'guardar_api_key',
					'user_id' => get_current_user_id()
				]);
			}
		}
		$connection_status = function_exists( 'mi_integracion_api_check_connection_status' ) ? mi_integracion_api_check_connection_status() : 'disconnected';
		$options = get_option( 'mi_integracion_api_ajustes', array() );
		?>
		<div class="wrap verial-admin-wrap">
			<div class="verial-header">
				<div class="verial-title-nav" style="display:flex;align-items:center;justify-content:space-between;width:100%;">
					<div style="flex:1;display:flex;align-items:center;gap:18px;">
						<?php if ( file_exists( plugin_dir_path( dirname( __DIR__ ) ) . '../../assets/img/logo.png' ) ) : ?>
							<img src="<?php echo plugins_url( '../../assets/img/logo.png', __FILE__ ); ?>" alt="Verial" class="verial-logo">
						<?php endif; ?>
						<span class="verial-title-text verial-section-title"><?php echo esc_html( get_admin_page_title() ); ?></span>
					</div>
				</div>
			</div>
			<div class="verial-connection-status <?php echo esc_attr( $connection_status ); ?>">
				<span class="status-indicator"></span>
				<span class="status-text">
					<?php
					if ( $connection_status === 'connected' ) {
						_e( 'Conectado a Verial', 'mi-integracion-api' );
					} elseif ( $connection_status === 'disconnected' ) {
						_e( 'Desconectado', 'mi-integracion-api' );
					} else {
						_e( 'Estado desconocido', 'mi-integracion-api' );
					}
					?>
				</span>
			</div>
			<div class="verial-card">
				<div class="verial-tabs">
					<a href="#" class="verial-tab-link active" data-tab="verial-settings-tab"><?php _e( 'Configuración', 'mi-integracion-api' ); ?></a>
					<a href="#" class="verial-tab-link" data-tab="verial-test-tab"><?php _e( 'Prueba de conexión', 'mi-integracion-api' ); ?></a>
					<a href="#" class="verial-tab-link" data-tab="verial-sync-tab"><?php _e( 'Sincronización', 'mi-integracion-api' ); ?></a>
				</div>
				<div id="verial-settings-tab" class="verial-tab-content">
					<?php \MiIntegracionApi\Admin\SettingsFormBlock::render(); ?>
				</div>
				<div id="verial-test-tab" class="verial-tab-content" style="display:none;">
					<?php \MiIntegracionApi\Admin\ConnectionTestBlock::render(); ?>
				</div>
				<div id="verial-sync-tab" class="verial-tab-content" style="display:none;">
					<p><?php _e( 'La sincronización se gestiona desde la nueva página de sincronización.', 'mi-integracion-api' ); ?></p>
				</div>
			</div>
		</div>
		<?php
		// Al mostrar valores en inputs:
		if ( ! isset( $options ) || ! is_array( $options ) ) {
			$options = array();
		}
		?>
		<?php
		// Al procesar POST:
		\MiIntegracionApi\Helpers\LoggerAuditoria::log(
			'Guardar configuración',
			array(
				'campo' => 'configuracion_guardada',
				'valor' => 'true',
			)
		);
	}

	/**
	 * Maneja el guardado del formulario de configuración
	 */
	public static function handle_save_settings() {
		// Verificar nonce
		if ( ! isset( $_POST['mi_integracion_api_settings_nonce'] ) || 
			 ! wp_verify_nonce( $_POST['mi_integracion_api_settings_nonce'], 'mi_integracion_api_save_settings' ) ) {
			wp_die( 'Error de seguridad. Por favor, recargue la página e intente nuevamente.', 'Error', ['response' => 403] );
		}
		
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tiene permisos suficientes para realizar esta acción.', 'Error', ['response' => 403] );
		}
		
		// Obtener opciones existentes
		$options = get_option( 'mi_integracion_api_ajustes', array() );
		
		// Actualizar opciones
		$options['mia_url_base'] = isset( $_POST['mia_url_base'] ) ? esc_url_raw( $_POST['mia_url_base'] ) : '';
		$options['mia_numero_sesion'] = isset( $_POST['mia_numero_sesion'] ) ? sanitize_text_field( $_POST['mia_numero_sesion'] ) : '';
		$options['mia_timeout'] = isset( $_POST['mia_timeout'] ) ? intval( $_POST['mia_timeout'] ) : 30;
		
		// Módulos habilitados
		$options['mia_enabled_modules'] = isset( $_POST['mia_enabled_modules'] ) ? array_map( 'sanitize_text_field', $_POST['mia_enabled_modules'] ) : array();
		
		// Opciones avanzadas
		$options['mia_ssl_verify'] = isset( $_POST['mia_ssl_verify'] ) ? sanitize_text_field( $_POST['mia_ssl_verify'] ) : '1';
		$options['mia_log_level'] = isset( $_POST['mia_log_level'] ) ? sanitize_text_field( $_POST['mia_log_level'] ) : 'info';
		
		// Guardar opciones
		update_option( 'mi_integracion_api_ajustes', $options );
		
		// Registrar en el log si está disponible
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			$logger = new \MiIntegracionApi\Helpers\Logger('settings');
			$logger->info( 'Configuración actualizada', [
				'accion' => 'guardar_configuracion',
				'user_id' => get_current_user_id()
			] );
		}
		
		// Redireccionar con mensaje de éxito
		wp_redirect( admin_url( 'admin.php?page=mi-integracion-api-settings&message=saved' ) );
		exit;
	}

	/**
	 * Inicializa la clase y registra los hooks con WordPress.
	 *
	 * @since 1.0.0
	 */
	public static function init() {
		add_action( 'admin_post_mi_integracion_api_save_settings', array( __CLASS__, 'handle_save_settings' ) );
	}
}