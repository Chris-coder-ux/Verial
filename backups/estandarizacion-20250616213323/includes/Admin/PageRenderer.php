<?php
/**
 * Renderizador de páginas simplificado
 *
 * @package MiIntegracionApi\Admin
 */

namespace MiIntegracionApi\Admin;



if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MI_Page_Renderer' ) ) {
	class PageRenderer {
		/**
		 * Renderiza la página de conexión
		 */
		public static function render_connection_page() {
			echo '<div class="wrap mi-integracion-api-admin">';
			echo '<h1>Prueba de Conexión</h1>';
			echo '<div id="mi-integracion-api-connection" class="mi-integracion-api-content">';
			echo '<div class="notice notice-info"><p>El componente para la prueba de conexión se está cargando...</p></div>';
			echo '<div class="mi-api-card mi-integracion-api-card">';
			echo '<h2>Estado de la conexión</h2>';
			echo '<form id="test-connection-form">';
			echo '<p><button type="submit" class="button button-primary">Probar conexión</button></p>';
			echo '</form>';
			echo '<div id="connection-results"></div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Renderiza la página de endpoints
		 */
		public static function render_endpoints_page() {
			echo '<div class="wrap mi-integracion-api-admin">';
			echo '<h1>Endpoints API</h1>';
			echo '<div id="mi-integracion-api-endpoints" class="mi-integracion-api-content">';
			echo '<div class="notice notice-info"><p>El componente para probar los endpoints se está cargando...</p></div>';
			echo '<div class="mi-api-card mi-integracion-api-card">';
			echo '<h2>Prueba de endpoints</h2>';
			echo '<form id="test-endpoint-form">';
			echo '<p>';
			echo '<label for="endpoint-select">Seleccione un endpoint:</label>';
			echo '<select id="endpoint-select" name="endpoint" class="regular-text">';
			echo '<option value="">Seleccione un endpoint...</option>';
			echo '<option value="users">Usuarios</option>';
			echo '<option value="products">Productos</option>';
			echo '<option value="orders">Pedidos</option>';
			echo '</select>';
			echo '</p>';
			echo '<p><button type="submit" class="button button-primary">Probar endpoint</button></p>';
			echo '</form>';
			echo '<div id="endpoint-results"></div>';
			echo '</div>';
			echo '</div>';
			echo '</div>';
		}

		/**
		 * Renderiza la página de logs
		 */
		public static function render_logs_page() {
			echo '<div class="wrap mi-integracion-api-admin">';
			echo '<h1>Registros</h1>';
			echo '<div id="mi-integracion-api-logs" class="mi-integracion-api-logs mi-integracion-api-content">';
			echo '<div class="notice notice-info"><p>El componente para visualizar los logs se está cargando...</p></div>';
			echo '<div class="logs-actions">';
			echo '<button id="refresh-logs" class="button">Actualizar logs</button> ';
			echo '<button id="clear-logs" class="button">Borrar logs</button>';
			echo '</div>';
			echo '<div class="logs-filter">';
			echo '<form id="log-filter-form">';
			echo '<label for="log-level-filter">Filtrar por nivel:</label> ';
			echo '<select id="log-level-filter" name="level">';
			echo '<option value="">Todos</option>';
			echo '<option value="error">Error</option>';
			echo '<option value="warning">Advertencia</option>';
			echo '<option value="info">Información</option>';
			echo '<option value="debug">Depuración</option>';
			echo '</select> ';
			echo '<input type="text" name="search" placeholder="Buscar..." class="regular-text"> ';
			echo '<button type="submit" class="button">Filtrar</button>';
			echo '</form>';
			echo '</div>';
			echo '<div class="logs-loading" style="display: none;">Cargando logs...</div>';
			echo '<div class="logs-notifications"></div>';
			echo '<div class="logs-table-container">';
			echo '<table id="logs-table" class="wp-list-table widefat fixed striped">';
			echo '<thead><tr>';
			echo '<th>ID</th><th>Fecha/Hora</th><th>Nivel</th><th>Mensaje</th><th>Acciones</th>';
			echo '</tr></thead>';
			echo '<tbody><tr><td colspan="5" class="no-logs">Cargando logs...</td></tr></tbody>';
			echo '</table>';
			echo '</div>';
			echo '<div class="logs-pagination"></div>';
			echo '</div>';
			echo '</div>';
		}
	}
}
