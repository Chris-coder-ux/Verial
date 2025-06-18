<?php

namespace MiIntegracionApi\Admin;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPageView {
	/**
	 * Renderiza el dashboard
	 * 
	 * @return void
	 */
	public static function render_dashboard() {
		self::render();
	}
	
	/**
	 * Método de compatibilidad para versiones anteriores
	 * 
	 * @return void
	 */
	public static function render() {
		?>
		<div class="wrap mi-integracion-api-admin">
			<div class="mi-integracion-api-header">
				<h1 style="margin:0;font-size:1.5em;"><?php esc_html_e( 'Dashboard Integración Verial ERP', 'mi-integracion-api' ); ?></h1>
			</div>
			<div class="mi-integracion-api-dashboard">
				<div class="mi-integracion-api-stats-grid">
					<div class="mi-integracion-api-stat-card products">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e8f5e9;"><span class="dashicons dashicons-products"></span></span>
							<?php esc_html_e( 'Productos sincronizados', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value"><?php echo intval( get_option( 'mia_last_sync_count', 0 ) ); ?></div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Total sincronizados', 'mi-integracion-api' ); ?></div>
					</div>
					<div class="mi-integracion-api-stat-card orders">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#ffebee;"><span class="dashicons dashicons-cart"></span></span>
							<?php esc_html_e( 'Errores recientes', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value mi-integracion-api-stat-error"><?php echo intval( get_option( 'mia_last_sync_errors', 0 ) ); ?></div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Errores en la última sync', 'mi-integracion-api' ); ?></div>
					</div>
					<div class="mi-integracion-api-stat-card">
						<div class="mi-integracion-api-stat-title">
							<span class="mi-integracion-api-stat-icon" style="background:#e3f2fd;"><span class="dashicons dashicons-clock"></span></span>
							<?php esc_html_e( 'Última sincronización', 'mi-integracion-api' ); ?>
						</div>
						<div class="mi-integracion-api-stat-value">
							<?php
							$last_sync_time = get_option( 'mia_last_sync_time' );
							echo $last_sync_time
								? esc_html( date_i18n( 'd/m/Y H:i', $last_sync_time ) )
								: esc_html__( 'Nunca', 'mi-integracion-api' );
							?>
						</div>
						<div class="mi-integracion-api-stat-desc"><?php esc_html_e( 'Fecha y hora', 'mi-integracion-api' ); ?></div>
					</div>
				</div>
			</div>
			<div class="mi-integracion-api-card" style="margin-bottom:32px;">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Accesos rápidos', 'mi-integracion-api' ); ?></h3>
				<div style="display:flex;gap:12px;flex-wrap:wrap;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mi-sync-single-product' ) ); ?>" class="mi-integracion-api-button success"><?php esc_html_e( 'Sincronizar un producto', 'mi-integracion-api' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mi-endpoints-page' ) ); ?>" class="mi-integracion-api-button info"><?php esc_html_e( 'Probar endpoints', 'mi-integracion-api' ); ?></a>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mi-logs-page' ) ); ?>" class="mi-integracion-api-button warning"><?php esc_html_e( 'Ver logs', 'mi-integracion-api' ); ?></a>
				</div>
			</div>
			<!-- Sincronización batch de productos -->
			<div class="mi-integracion-api-card">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Registro de actividad', 'mi-integracion-api' ); ?></h3>
				<p><?php esc_html_e( 'Por razones de seguridad, los logs ya no se muestran directamente en el dashboard.', 'mi-integracion-api' ); ?></p>
				<p><?php esc_html_e( 'Por favor, utilice la página dedicada de logs para visualizar y filtrar el registro de actividad.', 'mi-integracion-api' ); ?></p>
				<div style="margin-top: 15px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=mi-integracion-api-logs' ) ); ?>" class="mi-integracion-api-button primary">
						<span class="dashicons dashicons-visibility" style="margin-right:5px;"></span>
						<?php esc_html_e( 'Acceder a los logs', 'mi-integracion-api' ); ?>
					</a>
				</div>
			</div>
			<!-- --- Añadir bloque de sincronización batch de productos --- -->
			<div class="mi-integracion-api-card">
				<h3 style="margin-top:0;"><?php esc_html_e( 'Sincronización Masiva de Productos', 'mi-integracion-api' ); ?></h3>
				<p class="sync-description"><?php esc_html_e('Sincronice todos los productos desde Verial a WooCommerce en un proceso por lotes.', 'mi-integracion-api'); ?></p>
				
				<?php 
				// Comprobar si hay una sincronización en curso
				$sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
				$sync_status = $sync_manager->get_sync_status();
				$in_progress = (isset($sync_status['status']) && $sync_status['status'] === 'running');
				?>
				
				<div class="mi-batch-sync-controls">
					<div class="mi-batch-size-selector">
						<label for="mi-batch-size"><?php esc_html_e('Productos por lote:', 'mi-integracion-api'); ?></label>
						<select id="mi-batch-size" name="mi-batch-size" <?php echo $in_progress ? 'disabled' : ''; ?>>
							<option value="1">1</option>
							<option value="5">5</option>
							<option value="10" selected>10</option>
							<option value="20">20</option>
						</select>
					</div>
					<button id="mi-batch-sync-products" class="button button-primary" <?php echo $in_progress ? 'disabled' : ''; ?>>
						<?php esc_html_e('Sincronizar productos en lote', 'mi-integracion-api'); ?> <span class="dashicons dashicons-update"></span>
					</button>
				</div>
				<div id="mi-batch-sync-feedback" style="margin-top:10px;" class="<?php echo $in_progress ? 'in-progress' : ''; ?>">
					<?php if ($in_progress): ?>
						<?php esc_html_e('Sincronización en progreso...', 'mi-integracion-api'); ?>
					<?php endif; ?>
				</div>
				<!-- Bloque de barra de progreso y botón de cancelación siempre presente, oculto por defecto si no hay sync -->
				<div id="mi-sync-status-details" class="sync-status-container" style="margin-top:15px; display:<?php echo $in_progress ? 'block' : 'none'; ?>;">
					<h4><?php esc_html_e('Estado de la Sincronización', 'mi-integracion-api'); ?></h4>
					<div class="sync-progress-bar-container">
						<div class="sync-progress-bar" style="width: 0%;"></div>
					</div>
					<div class="sync-status-info">
						<p><?php esc_html_e('Cargando datos...', 'mi-integracion-api'); ?></p>
					</div>
					<div class="sync-status-actions">
						<button id="mi-cancel-sync" class="button button-secondary">
							<?php esc_html_e('Cancelar Sincronización', 'mi-integracion-api'); ?>
						</button>
					</div>
				</div>
			</div>
		</div>
		
		<?php
		// Registrar script de dashboard con mensajes de confirmación localizados
		wp_enqueue_script(
			'mi-integracion-api-dashboard',
			MiIntegracionApi_PLUGIN_URL . 'assets/js/dashboard.js',
			array('jquery'),
			MiIntegracionApi_VERSION,
			true
		);
		
		// Localizar el script de dashboard con los mensajes de confirmación
		wp_localize_script(
			'mi-integracion-api-dashboard',
			'miIntegracionApiDashboard',
			array(
				'nonce' => wp_create_nonce( MiIntegracionApi_NONCE_PREFIX . 'dashboard' ),
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => rest_url( MiIntegracionApi_TEXT_DOMAIN . '/v1/' ),
				'version' => MiIntegracionApi_VERSION,
				'confirmSync' => __('¿Estás seguro de que deseas iniciar una sincronización manual ahora?', MiIntegracionApi_TEXT_DOMAIN),
				'syncStarted' => __('Sincronización iniciada. Por favor espere...', MiIntegracionApi_TEXT_DOMAIN),
				'syncFinished' => __('Sincronización completada.', MiIntegracionApi_TEXT_DOMAIN),
				'syncError' => __('Error durante la sincronización: ', MiIntegracionApi_TEXT_DOMAIN),
				'confirmCancel' => __('¿Seguro que deseas cancelar la sincronización?', MiIntegracionApi_TEXT_DOMAIN)
			)
		);
		?>
		<?php
	}
}