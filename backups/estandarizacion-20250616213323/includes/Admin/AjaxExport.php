<?php

namespace MiIntegracionApi\Admin;

/**
 * Funciones AJAX para exportar datos de sincronizaciones
 *
 * @package MiIntegracionApi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

if ( ! defined( 'MiIntegracionApi_VERSION' ) ) {
	define( 'MiIntegracionApi_VERSION', '1.2.1' );
}

class AjaxExport {
	public static function register_ajax_handler() {
		add_action( 'wp_ajax_mia_export_sync_json', [self::class, 'export_sync_json_callback'] );
	}

	public static function export_sync_json_callback() {
		// Verificar nonce
		check_ajax_referer( 'mia_export_sync_json', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'mensaje' => __( 'No tienes permisos para realizar esta acción.', 'mi-integracion-api' ) ) );
			return;
		}

		// Obtener ID de sincronización
		$sync_id = isset( $_POST['sync_id'] ) ? intval( $_POST['sync_id'] ) : 0;

		if ( ! $sync_id ) {
			wp_send_json_error( array( 'mensaje' => __( 'ID de sincronización no válido.', 'mi-integracion-api' ) ) );
			return;
		}

		// Obtener datos de la sincronización
		global $wpdb;
		$tabla = $wpdb->prefix . 'mi_integracion_api_logs';

		$registro = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tabla WHERE id = %d", $sync_id ), ARRAY_A );

		if ( ! $registro ) {
			wp_send_json_error( array( 'mensaje' => __( 'Registro de sincronización no encontrado.', 'mi-integracion-api' ) ) );
			return;
		}

		// Preparar datos para exportación
		$datos_exportacion = array(
			'id'             => $registro['id'],
			'tipo'           => $registro['tipo'],
			'usuario_id'     => $registro['usuario_id'],
			'usuario_nombre' => get_userdata( $registro['usuario_id'] ) ? get_userdata( $registro['usuario_id'] )->display_name : '',
			'fecha'          => $registro['fecha'],
			'duracion'       => $registro['duracion'],
			'status'         => $registro['status'],
			'datos'          => json_decode( $registro['datos'], true ),
			'resultado'      => json_decode( $registro['resultado'], true ),
			'exportado_el'   => current_time( 'mysql' ),
			'exportado_por'  => get_current_user_id(),
			'version_plugin' => defined( 'MiIntegracionApi_VERSION' ) ? MiIntegracionApi_VERSION : '1.0.0',
		);

		// Devolver datos
		wp_send_json_success( $datos_exportacion );
	}
}

// Registrar el handler al cargar el archivo
AjaxExport::register_ajax_handler();
