<?php

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class LogsPage {
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'No tienes permisos suficientes para ver esta página.', 'mi-integracion-api' ),
				esc_html__( 'Acceso restringido', 'mi-integracion-api' ),
				array( 'back_link' => true )
			);
		}

		// Los scripts y estilos se gestionan centralizadamente desde la clase MI_Assets
		// La nueva versión utiliza la REST API en lugar de AJAX para mayor seguridad

		// Pasar datos al script
		$rest_url   = rest_url( 'mi-integracion-api/v1/' );
		$rest_nonce = wp_create_nonce( 'wp_rest' );

		wp_localize_script(
			'mi-integracion-api-logs-viewer-secure',
			'miIntegracionApi',
			array(
				'restUrl'   => $rest_url,
				'restNonce' => $rest_nonce,
				'i18n'      => array(
					'confirmClearLogs'     => __( '¿Estás seguro de que deseas borrar todos los logs? Esta acción no se puede deshacer.', 'mi-integracion-api' ),
					'exportNotImplemented' => __( 'La exportación de logs está en desarrollo y estará disponible próximamente.', 'mi-integracion-api' ),
					'noLogsFound'          => __( 'No se encontraron logs con los filtros actuales.', 'mi-integracion-api' ),
					'genericError'         => __( 'Ha ocurrido un error al procesar su solicitud.', 'mi-integracion-api' ),
					'clearLogsError'       => __( 'Error al limpiar los logs.', 'mi-integracion-api' ),
					'totalItems'           => __( 'Total de registros', 'mi-integracion-api' ),
					'items'                => __( 'registros', 'mi-integracion-api' ),
					'logId'                => __( 'ID', 'mi-integracion-api' ),
					'logType'              => __( 'Tipo', 'mi-integracion-api' ),
					'logDate'              => __( 'Fecha', 'mi-integracion-api' ),
					'logMessage'           => __( 'Mensaje', 'mi-integracion-api' ),
					'logContext'           => __( 'Contexto', 'mi-integracion-api' ),
				),
			)
		);
		?>
		<div class="wrap mi-integracion-api-admin" id="mi-integracion-api-logs-admin">
			<h1><?php esc_html_e( 'Registros y Auditoría', 'mi-integracion-api' ); ?></h1>
			<div class="notice notice-info is-dismissible">
				<p>
					<?php esc_html_e( 'Esta página muestra los registros del plugin de forma segura usando la REST API. Los logs ya no se muestran directamente en el dashboard por razones de seguridad.', 'mi-integracion-api' ); ?>
				</p>
			</div>
			<!-- Panel de filtros -->
			<div class="mi-integracion-api-card">
				<h2><?php esc_html_e( 'Filtros', 'mi-integracion-api' ); ?></h2>
				<form id="log-filter-form" class="mi-integracion-api-filters">
					<div class="mi-integracion-api-filter-row">
						<div class="mi-integracion-api-filter-item">
							<label for="log-type-filter"><?php esc_html_e( 'Tipo de registro:', 'mi-integracion-api' ); ?></label>
							<select id="log-type-filter" name="type" class="mi-integracion-api-select">
								<option value=""><?php esc_html_e( 'Todos', 'mi-integracion-api' ); ?></option>
								<option value="info"><?php esc_html_e( 'Información', 'mi-integracion-api' ); ?></option>
								<option value="error"><?php esc_html_e( 'Error', 'mi-integracion-api' ); ?></option>
								<option value="warning"><?php esc_html_e( 'Advertencia', 'mi-integracion-api' ); ?></option>
								<option value="debug"><?php esc_html_e( 'Depuración', 'mi-integracion-api' ); ?></option>
								<option value="audit"><?php esc_html_e( 'Auditoría', 'mi-integracion-api' ); ?></option>
							</select>
						</div>
						<div class="mi-integracion-api-filter-item">
							<label for="log-date-from"><?php esc_html_e( 'Desde:', 'mi-integracion-api' ); ?></label>
							<input type="date" id="log-date-from" name="date_from" class="mi-integracion-api-input">
						</div>
						<div class="mi-integracion-api-filter-item">
							<label for="log-date-to"><?php esc_html_e( 'Hasta:', 'mi-integracion-api' ); ?></label>
							<input type="date" id="log-date-to" name="date_to" class="mi-integracion-api-input">
						</div>
						<div class="mi-integracion-api-filter-item">
							<label for="log-search"><?php esc_html_e( 'Búsqueda:', 'mi-integracion-api' ); ?></label>
							<input type="text" id="log-search" name="search" class="mi-integracion-api-input" placeholder="<?php esc_attr_e( 'Buscar en mensajes...', 'mi-integracion-api' ); ?>">
						</div>
						<div class="mi-integracion-api-filter-actions">
							<button type="submit" class="mi-integracion-api-button">
								<?php esc_html_e( 'Filtrar', 'mi-integracion-api' ); ?>
							</button>
						</div>
					</div>
				</form>
			</div>
			<!-- Acciones -->
			<div class="mi-integracion-api-actions">
				<button id="refresh-logs" class="mi-integracion-api-button">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Actualizar', 'mi-integracion-api' ); ?>
				</button>
				<button id="clear-logs" class="mi-integracion-api-button">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Limpiar logs', 'mi-integracion-api' ); ?>
				</button>
				<button id="export-logs" class="button">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Exportar', 'mi-integracion-api' ); ?>
				</button>
			</div>
			<!-- Contenedor de logs -->
			<div id="log-container" class="mi-api-logs-container loading">
				<div class="mi-api-loading">
					<?php esc_html_e( 'Cargando logs...', 'mi-integracion-api' ); ?>
				</div>
			</div>
			<!-- Paginación -->
			<div id="log-pagination" class="mi-api-pagination"></div>
		</div>
		<?php
	}
}
