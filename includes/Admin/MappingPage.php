<?php
/**
 * Página de administración para mapeos complejos (UI)
 *
 * @package MiIntegracionApi\Admin
 */
namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Core\MappingManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MappingPage {
	/**
	 * Inicializar la página
	 */
	public static function init() {
		add_action( 'wp_ajax_mi_get_mappings', array( __CLASS__, 'ajax_get_mappings' ) );
		add_action( 'wp_ajax_mi_save_mapping', array( __CLASS__, 'ajax_save_mapping' ) );
		add_action( 'wp_ajax_mi_delete_mapping', array( __CLASS__, 'ajax_delete_mapping' ) );
	}

	/**
	 * Renderiza la página de administración
	 */
	public static function render() {
		?>
		<div class="wrap">
			<h1><?php _e( 'Mapeos complejos', 'mi-integracion-api' ); ?></h1>
			<div id="mi-mapping-app"></div>
		</div>
		<?php
	}

	/**
	 * Carga los assets necesarios
	 */
	public static function enqueue_assets() {
		wp_enqueue_style( 'mi-mapping-ui', 'https://cdn.jsdelivr.net/npm/gridjs/dist/theme/mermaid.min.css', array(), null );
		wp_enqueue_script( 'mi-mapping-ui', 'https://cdn.jsdelivr.net/npm/gridjs/dist/gridjs.umd.js', array(), null, true );
		wp_enqueue_script( 'mi-mapping-app', MiIntegracionApi_PLUGIN_URL . 'assets/js/mapping-app.js', array( 'mi-mapping-ui' ), MiIntegracionApi_VERSION, true );

		// Agregar estilos adicionales
		wp_add_inline_style(
			'mi-mapping-ui',
			'
            .mi-mapping-container {
                display: grid;
                grid-template-columns: 1fr 2fr;
                gap: 20px;
                margin-top: 20px;
            }
            @media (max-width: 782px) {
                .mi-mapping-container {
                    grid-template-columns: 1fr;
                }
            }
            .mi-mapping-form .form-field {
                margin-bottom: 15px;
            }
            .mi-mapping-form label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            .mi-mapping-form input,
            .mi-mapping-form select {
                width: 100%;
                max-width: 100%;
            }
            .mi-mapping-search {
                margin-bottom: 15px;
            }
            .mi-mapping-search input {
                width: 100%;
                padding: 6px 8px;
            }
            .gridjs-wrapper {
                border-radius: 4px;
                box-shadow: 0 1px 2px rgba(0,0,0,0.05);
            }
            .gridjs-pagination {
                border-top: 1px solid #e2e2e2;
            }
        '
		);

		// Pasar datos a JS
		wp_localize_script(
			'mi-mapping-app',
			'mi_mapping_data',
			array(
				'nonce'   => wp_create_nonce( 'mi_mapping_nonce' ),
				'strings' => array(
					'confirmDelete' => __( '¿Estás seguro de que quieres eliminar este mapeo?', 'mi-integracion-api' ),
				),
			)
		);
	}

	/**
	 * Manejador AJAX para obtener mapeos
	 */
	public static function ajax_get_mappings() {
		check_ajax_referer( 'mi_mapping_nonce', 'X-WP-Nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permisos insuficientes', 'mi-integracion-api' ), 403 );
		}

		$mappings = MappingManager::get_all_mappings();

		wp_send_json_success( $mappings );
	}

	/**
	 * Manejador AJAX para guardar un mapeo
	 */
	public static function ajax_save_mapping() {
		check_ajax_referer( 'mi_mapping_nonce', 'X-WP-Nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permisos insuficientes', 'mi-integracion-api' ), 403 );
		}

		// Obtener datos del request
		$data = json_decode( file_get_contents( 'php://input' ), true );

		if ( ! $data ) {
			wp_send_json_error( __( 'Datos no válidos', 'mi-integracion-api' ), 400 );
		}

		// Guardar mapeo
		$result = MappingManager::save_mapping( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}

		wp_send_json_success( true );
	}

	/**
	 * Manejador AJAX para eliminar un mapeo
	 */
	public static function ajax_delete_mapping() {
		check_ajax_referer( 'mi_mapping_nonce', 'X-WP-Nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permisos insuficientes', 'mi-integracion-api' ), 403 );
		}

		// Obtener datos del request
		$data = json_decode( file_get_contents( 'php://input' ), true );

		if ( ! isset( $data['id'] ) ) {
			wp_send_json_error( __( 'ID de mapeo no proporcionado', 'mi-integracion-api' ), 400 );
		}

		// Eliminar mapeo
		$result = MappingManager::delete_mapping( $data['id'] );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message(), 400 );
		}

		wp_send_json_success( true );
	}
}

// Vamos a utilizar el nombre de clase correcto (MappingPage, no Mapping_Page)
// Inicializar la página
MappingPage::init();

// Cargar assets solo en la página correcta
add_action(
	'admin_enqueue_scripts',
	function ( $hook ) {
		if ( $hook === 'mi-integracion-api_page_mi-integracion-api-mapping' ) {
			\MiIntegracionApi\Admin\MappingPage::enqueue_assets();
		}
	}
);
