<?php

namespace MiIntegracionApi\Admin;


/**
 * Plugin Name: Mi Integración API
 * Description: Integración con la API de Verial ERP.
 * Version: 1.0.0
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class ConnectionCheckPage {
	public static function render() {
		// Estado de conexión
		$connection_status = function_exists( 'mi_integracion_api_check_connection_status' ) ? mi_integracion_api_check_connection_status() : 'disconnected';
		?>
		<div class="wrap verial-admin-wrap">
			<div class="verial-header">
				<div class="verial-title-nav" style="display:flex;align-items:center;justify-content:space-between;width:100%;">
					<div style="flex:1;display:flex;align-items:center;gap:18px;">
						<!-- Logo opcional -->
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
					<?php 
					// Mostrar mensajes de error/éxito si existen
					settings_errors('mi_integracion_api_settings_group');
					
					// Renderizar el formulario de configuración usando SettingsFormBlock
					SettingsFormBlock::render(); 
					?>
				</div>
				<div id="verial-test-tab" class="verial-tab-content" style="display:none;">
					<div class="verial-dashboard">
						<div class="verial-test-section">
							<h3><?php _e( 'Prueba de conexión Verial API', 'mi-integracion-api' ); ?></h3>
							<p><?php _e( 'Haz clic en el botón para verificar la conexión con la API de Verial.', 'mi-integracion-api' ); ?></p>
							<button id="mia-btn-test-connection-verial" class="button verial-button" type="button">
								<?php _e( 'Probar conexión Verial', 'mi-integracion-api' ); ?>
								<span class="verial-spinner" style="display:none;margin-left:8px;"><span></span><span></span><span></span><span></span><span></span><span></span></span>
							</button>
							<span id="mia-test-connection-verial-result" style="margin-left:10px;"></span>
						</div>
						<div class="verial-test-section">
							<h3><?php _e( 'Prueba de conexión WooCommerce API', 'mi-integracion-api' ); ?></h3>
							<p><?php _e( 'Haz clic en el botón para verificar la conexión con la API de WooCommerce.', 'mi-integracion-api' ); ?></p>
							<button id="mia-btn-test-connection-woocommerce" class="button verial-button" type="button">
								<?php _e( 'Probar conexión WooCommerce', 'mi-integracion-api' ); ?>
								<span class="verial-spinner" style="display:none;margin-left:8px;"><span></span><span></span><span></span><span></span><span></span><span></span></span>
							</button>
							<span id="mia-test-connection-woocommerce-result" style="margin-left:10px;"></span>
						</div>
						<div class="verial-test-section">
							<h3><?php _e( 'Diagnóstico de conectividad', 'mi-integracion-api' ); ?></h3>
							<p><?php _e( 'Realiza un diagnóstico detallado de la conexión con Verial.', 'mi-integracion-api' ); ?></p>
							<button id="run-connection-diagnosis" class="button verial-button" type="button">
								<?php _e( 'Ejecutar diagnóstico', 'mi-integracion-api' ); ?>
								<span class="verial-spinner" style="display:none;margin-left:8px;"><span></span><span></span><span></span><span></span><span></span><span></span></span>
							</button>
							<div id="diagnosis-result" style="margin-top:15px;"></div>
						</div>
					</div>
					<div id="verial-toast-container" class="verial-toast-container"></div>
				</div>
				<div id="verial-sync-tab" class="verial-tab-content" style="display:none;">
					<h3><?php _e( 'Sincronización de productos', 'mi-integracion-api' ); ?></h3>
					<p><?php _e( 'Accede a la página de sincronización para gestionar la importación de productos.', 'mi-integracion-api' ); ?></p>
					<div style="margin-top: 20px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mi-sync-single-product' ) ); ?>" class="button verial-button">
						<?php _e( 'Ir a Sincronización de Productos', 'mi-integracion-api' ); ?>
					</a>
				</div>
				</div>
			</div>
			<!-- Opcional: sección de sincronización avanzada -->
			<!-- ... -->
		</div>
		<?php
	}
}
