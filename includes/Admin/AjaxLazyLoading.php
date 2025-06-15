<?php

namespace MiIntegracionApi\Admin;

/**
 * Gestión AJAX para la carga diferida de componentes
 *
 * @package MiIntegracionApi
 */

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxLazyLoading {
	public static function register_ajax_handler() {
		add_action( 'init', [self::class, 'init_lazyload_ajax'] );
	}

	public static function init_lazyload_ajax() {
		add_action( 'wp_ajax_mi_integracion_api_lazyload', [self::class, 'handle_lazyload'] );
	}

	public static function handle_lazyload() {
		// Verificar nonce
		$nonce_valid = check_ajax_referer( 'wp_rest', false, false );
		if ( ! $nonce_valid ) {
			wp_send_json_error( __( 'Nonce no válido', 'mi-integracion-api' ) );
			exit;
		}

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permisos insuficientes', 'mi-integracion-api' ) );
			exit;
		}

		// Obtener el componente solicitado
		$component = isset( $_POST['component'] ) ? sanitize_text_field( $_POST['component'] ) : '';

		if ( empty( $component ) ) {
			wp_send_json_error( __( 'Componente no especificado', 'mi-integracion-api' ) );
			exit;
		}

		// Cargar el componente mediante el LazyLoader
		if ( class_exists( 'MiIntegracionApi\\Core\\LazyLoader' ) ) {
			$result = \MiIntegracionApi\Core\LazyLoader::execute_observer( $component );

			if ( $result ) {
				// Registrar log para depuración
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					// Translators: %s is the component name
					error_log( sprintf( __( 'MiIntegracionApi: Componente cargado vía AJAX: %s', 'mi-integracion-api' ), $component ) );
				}

				wp_send_json_success( __( 'Componente cargado correctamente', 'mi-integracion-api' ) );
			} else {
				wp_send_json_error( __( 'No se pudo cargar el componente', 'mi-integracion-api' ) );
			}
		} else {
			wp_send_json_error( __( 'LazyLoader no está disponible', 'mi-integracion-api' ) );
		}

		exit;
	}
}

// Registrar el handler al cargar el archivo
AjaxLazyLoading::register_ajax_handler();
