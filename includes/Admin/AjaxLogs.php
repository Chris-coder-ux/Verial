<?php

namespace MiIntegracionApi\Admin;

/**
 * Funciones AJAX para la página de logs
 *
 * @package MiIntegracionApi\Admin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxLogs {
	public static function register_ajax_handler() {
		add_action( 'wp_ajax_verial_logs_get', [self::class, 'get_logs_ajax'] );
	}

	public static function get_logs_ajax() {
		// Verificar nonce
		check_ajax_referer( 'verial_logs_nonce', 'nonce' );

		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permiso denegado', 403 );
			return;
		}

		// Obtener filtros
		$filtros = array();
		if ( isset( $_POST['filtros'] ) && is_array( $_POST['filtros'] ) ) {
			$filtros = array_map( 'sanitize_text_field', $_POST['filtros'] );
		}

		// Página actual (por defecto 1)
		$pagina     = isset( $filtros['pagina'] ) ? absint( $filtros['pagina'] ) : 1;
		$por_pagina = 20; // Logs por página

		// Obtener logs filtrados
		$total = 0;
		$logs  = self::filtrar_logs( $filtros, $pagina, $por_pagina, $total );

		// Calcular total de páginas
		$total_paginas = ceil( $total / $por_pagina );

		// Devolver datos
		wp_send_json_success(
			array(
				'logs'          => $logs,
				'total'         => $total,
				'total_paginas' => $total_paginas,
				'pagina'        => $pagina,
			)
		);
	}

	/**
	 * Filtra logs según parámetros
	 */
	public static function filtrar_logs( $filtros, $pagina = 1, $por_pagina = 20, &$total = 0 ) {
		return \MiIntegracionApi\Core\QueryOptimizer::get_filtered_logs( $filtros, $pagina, $por_pagina, $total );
	}
}

// Registrar el handler al cargar el archivo
AjaxLogs::register_ajax_handler();
