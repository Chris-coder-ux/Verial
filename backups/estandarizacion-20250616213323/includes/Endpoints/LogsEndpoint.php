<?php
/**
 * Endpoint REST API para la gestión segura de logs
 *
 * @package MiIntegracionApi\Endpoints
 * @since 1.0.0
 */

namespace MiIntegracionApi\Endpoints;

// Si este archivo es llamado directamente, abortar.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para gestionar el endpoint REST API de logs
 *
 * Esta clase proporciona endpoints seguros para acceder, filtrar
 * y administrar los logs del plugin, con validación adecuada de permisos.
 *
 * @since 1.0.0
 */
class LogsEndpoint {


	/**
	 * El namespace para los endpoints REST API
	 *
	 * @var string
	 */
	private $namespace;

	/**
	 * La ruta base para estos endpoints
	 *
	 * @var string
	 */
	private $route;

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->namespace = 'mi-integracion-api/v1';
		$this->route     = 'logs';

		// Registrar endpoints
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registra las rutas REST API para la gestión de logs
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/' . $this->route,
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_logs' ),
				'permission_callback' => array( $this, 'check_permissions' ),
				'args'                => $this->get_collection_params(),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->route . '/clear',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear_logs' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Verifica si el usuario tiene permisos para ver logs
	 *
	 * @return bool|\WP_Error True si tiene permisos, WP_Error en caso contrario
	 */
	public function check_permissions() {
		// Verificar autenticación
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Necesita iniciar sesión para acceder a los logs.', 'mi-integracion-api' ),
				array( 'status' => 401 )
			);
		}

		// Verificar permisos - ahora restringido solo a administradores
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'No tiene permisos suficientes para ver los logs.', 'mi-integracion-api' ),
				array( 'status' => 403 )
			);
		}

		// Verificar nonce específico para logs
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
		if ( ! wp_verify_nonce( $nonce, 'mi_integracion_api_logs' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Verificación de seguridad fallida (nonce específico de logs).', 'mi-integracion-api' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Verifica si el usuario tiene permisos de administrador
	 *
	 * @return bool|\WP_Error True si tiene permisos, WP_Error en caso contrario
	 */
	public function check_admin_permissions() {
		// Primero verificar permisos básicos
		$permission_check = $this->check_permissions();
		if ( is_wp_error( $permission_check ) ) {
			return $permission_check;
		}

		// Verificación adicional para acciones administrativas
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Solo los administradores pueden realizar esta acción.', 'mi-integracion-api' ),
				array( 'status' => 403 )
			);
		}

		// Verificar nonce específico para logs administrativos
		$nonce = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? $_SERVER['HTTP_X_WP_NONCE'] : '';
		if ( ! wp_verify_nonce( $nonce, 'mi_integracion_api_logs_admin' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Verificación de seguridad fallida (nonce específico de logs admin).', 'mi-integracion-api' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Obtiene los logs según los filtros proporcionados
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud
	 * @return \WP_REST_Response|\WP_Error Respuesta con los logs o error
	 */
	public function get_logs( $request ) {
		// Obtener parámetros
		$page      = $request->get_param( 'page' ) ? (int) $request->get_param( 'page' ) : 1;
		$per_page  = $request->get_param( 'per_page' ) ? (int) $request->get_param( 'per_page' ) : 20;
		$type      = $request->get_param( 'type' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$search    = $request->get_param( 'search' );

		// Construir filtros
		$filtros = array();
		if ( $type ) {
			$filtros['tipo'] = sanitize_text_field( $type );
		}
		if ( $date_from ) {
			$filtros['fecha_desde'] = sanitize_text_field( $date_from );
		}
		if ( $date_to ) {
			$filtros['fecha_hasta'] = sanitize_text_field( $date_to );
		}
		if ( $search ) {
			$filtros['busqueda'] = sanitize_text_field( $search );
		}

		// Obtener logs filtrados
		$total = 0;
		$logs  = \MiIntegracionApi\Core\QueryOptimizer::get_filtered_logs( $filtros, $page, $per_page, $total );

		// Calcular total de páginas
		$total_paginas = ceil( $total / $per_page );

		// Preparar respuesta
		$response = array(
			'logs'        => $logs,
			'total'       => $total,
			'total_pages' => $total_paginas,
			'page'        => $page,
			'per_page'    => $per_page,
		);

		return rest_ensure_response( $response );
	}

	/**
	 * Limpia los logs (acción administrativa)
	 *
	 * @param \WP_REST_Request $request Datos de la solicitud
	 * @return \WP_REST_Response|\WP_Error Respuesta con resultado o error
	 */
	public function clear_logs( $request ) {
		// Implementar lógica para limpiar logs
		$success = true;

		// Limpiar la tabla de logs
		global $wpdb;
		$table_name = $wpdb->prefix . 'mi_integracion_api_logs';
		$result     = $wpdb->query( "TRUNCATE TABLE {$table_name}" );

		if ( $result === false ) {
			return new \WP_Error(
				'logs_clear_failed',
				__( 'No se pudieron limpiar los logs. Por favor, inténtelo de nuevo.', 'mi-integracion-api' ),
				array( 'status' => 500 )
			);
		}

		// Registrar esta acción en el log de auditoría
		if ( class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
			\MiIntegracionApi\Helpers\Logger::info(
				'Logs limpiados manualmente',
				array( 'user_id' => get_current_user_id() ),
				'auditoria'
			);
		}

		return rest_ensure_response(
			$this->format_success_response(null, [
				'message' => __( 'Los logs han sido limpiados correctamente.', 'mi-integracion-api' ),
			])
		);
	}

	/**
	 * Define los parámetros permitidos para la colección
	 *
	 * @return array Argumentos para la colección
	 */
	public function get_collection_params() {
		return array(
			'page'      => array(
				'description'       => __( 'Número de página actual.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'description'       => __( 'Máximo número de elementos a devolver por página.', 'mi-integracion-api' ),
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'type'      => array(
				'description'       => __( 'Filtrar por tipo de log.', 'mi-integracion-api' ),
				'type'              => 'string',
				'enum'              => array( 'info', 'error', 'warning', 'debug', 'auditoria' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_from' => array(
				'description'       => __( 'Filtrar desde una fecha (formato Y-m-d).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to'   => array(
				'description'       => __( 'Filtrar hasta una fecha (formato Y-m-d).', 'mi-integracion-api' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'    => array(
				'description'       => __( 'Buscar texto en los mensajes de log.', 'mi-integracion-api' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
		);
	}
}
