<?php
/**
 * Dashboard Page para Mi Integración API
 *
 * @package MiIntegracionApi\Admin
 */

namespace MiIntegracionApi\Admin;



if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DashboardPage {
	/**
	 * Inicializa el dashboard
	 */
	public static function init() {
		// Se elimina la acción add_action('admin_menu'...) para evitar duplicación
		// Ya que esta funcionalidad está siendo manejada por la clase MI_Admin_Menu
		// Los assets ya no se manejan aquí, se gestionan desde MI_Assets
	}

	/**
	 * Agrega la página de dashboard al menú de administración
	 * Esta función ya no se utiliza directamente para evitar duplicidad en el menú
	 * pero se mantiene por compatibilidad con código existente
	 */
	public static function add_dashboard_page() {
		// Función mantenida por compatibilidad pero ya no se utiliza directamente
	}

	/**
	 * Renderiza la página de dashboard
	 */
	public static function render_dashboard_page() {
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die(
				esc_html__( 'No tienes permisos suficientes para acceder a esta página.', 'mi-integracion-api' ),
				esc_html__( 'Error de permisos', 'mi-integracion-api' ),
				array( 'response' => 403 )
			);
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
		echo '<div id="mi-integracion-api-root">';
		echo '<p>' . esc_html__( 'Cargando dashboard...', 'mi-integracion-api' ) . '</p>';
		echo '</div>';
		echo '</div>';
	}

	// El método enqueue_dashboard_assets se ha eliminado y movido a la clase MI_Assets
}

// Inicializar el dashboard
MiIntegracionApi\Admin\DashboardPage::init();
