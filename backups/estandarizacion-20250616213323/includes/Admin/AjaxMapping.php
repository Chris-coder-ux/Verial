<?php

namespace MiIntegracionApi\Admin;


// AJAX handler para cargar y guardar mapeos complejos
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxMapping {
	public static function register_ajax_handlers() {
		add_action( 'wp_ajax_mi_integracion_get_mapping', [self::class, 'get_mapping'] );
		add_action( 'wp_ajax_mi_integracion_save_mapping', [self::class, 'save_mapping'] );
	}

	public static function get_mapping() {
		$mapping = get_option( 'mi_integracion_api_mapping', array() );
		wp_send_json_success( $mapping );
	}

	public static function save_mapping() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'No tienes permisos.' ) );
		}
		$mapping = isset( $_POST['mapping'] ) ? json_decode( stripslashes( $_POST['mapping'] ), true ) : array();
		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}
		update_option( 'mi_integracion_api_mapping', $mapping );
		wp_send_json_success( array( 'message' => 'Guardado correctamente.' ) );
	}
}

// Registrar los handlers al cargar el archivo
AjaxMapping::register_ajax_handlers();
