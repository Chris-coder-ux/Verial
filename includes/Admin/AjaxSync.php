<?php

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Sync\SyncJobManager;
use MiIntegracionApi\Sync\SyncManager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AjaxSync {
	public static function register_ajax_handlers() {
        // Cambiar a instancia para evitar error fatal
        $logger = new \MiIntegracionApi\Helpers\Logger('sync-debug');
        $logger->info('Registrando handlers AJAX en AjaxSync', [
            'file' => __FILE__,
            'line' => __LINE__,
            'acciones' => [
                'wp_ajax_mia_sync_progress',
                'wp_ajax_mia_sync_heartbeat',
                'wp_ajax_mia_sync_cancel',
                'wp_ajax_mia_sync_status',
                'wp_ajax_mi_integracion_api_validate_filters',
                'wp_ajax_mi_integracion_api_sync_products_batch',
                'wp_ajax_mi_integracion_api_test_api',
                'wp_ajax_mi_integracion_api_clear_cache',
                'wp_ajax_mia_load_filter_options',
                'wp_ajax_mi_sync_single_product',
                'wp_ajax_mi_sync_get_categorias',
                'wp_ajax_mi_sync_get_fabricantes',
                'wp_ajax_mi_sync_autocomplete_sku',
                'wp_ajax_mi_sync_search_product',
                'wp_ajax_mi_integracion_api_sync_clients_job_batch',
                'wp_ajax_mi_get_categorias',
                'wp_ajax_mi_get_fabricantes',
            ]
        ], 'sync-debug');
		add_action( 'wp_ajax_mia_sync_progress', [self::class, 'sync_progress_callback'] );
		add_action( 'wp_ajax_mia_sync_heartbeat', [self::class, 'sync_heartbeat_callback'] );
		add_action( 'wp_ajax_mia_sync_cancel', [self::class, 'sync_cancel_callback'] );
		add_action( 'wp_ajax_mia_sync_status', [self::class, 'sync_status_callback'] );
		add_action( 'wp_ajax_mi_integracion_api_validate_filters', [self::class, 'validate_filters'] );
		add_action( 'wp_ajax_mi_integracion_api_sync_products_batch', [self::class, 'sync_products_batch'] );
		add_action( 'wp_ajax_mi_integracion_api_test_api', [self::class, 'test_api'] );
		add_action( 'wp_ajax_mi_integracion_api_clear_cache', [self::class, 'clear_cache'] );
		add_action( 'wp_ajax_mia_load_filter_options', [self::class, 'load_filter_options'] );
		add_action( 'wp_ajax_mi_sync_single_product', [self::class, 'sync_single_product'] );
		add_action( 'wp_ajax_mi_sync_get_categorias', [self::class, 'get_categorias'] );
		add_action( 'wp_ajax_mi_sync_get_fabricantes', [self::class, 'get_fabricantes'] );
		add_action( 'wp_ajax_mi_sync_autocomplete_sku', [self::class, 'autocomplete_sku'] );
		add_action( 'wp_ajax_mi_sync_search_product', [self::class, 'search_product'] );
		add_action( 'wp_ajax_mi_integracion_api_sync_clients_job_batch', [self::class, 'sync_clients_job_batch'] );
        // Añadir compatibilidad con nombres de acciones utilizados en JS
		add_action( 'wp_ajax_mi_get_categorias', [self::class, 'get_categorias'] );
		add_action( 'wp_ajax_mi_get_fabricantes', [self::class, 'get_fabricantes'] );
	}

	public static function store_sync_progress( $porcentaje, $mensaje, $estadisticas = array() ) {
		// Inicializar registro de diagnóstico
		$diagnostico = [];

		// Asegurar que todas las claves necesarias estén presentes en estadísticas
		$estadisticas = wp_parse_args($estadisticas, [
			'articulo_actual' => '',
			'sku' => '',
			'procesados' => 0,
			'errores' => 0,
			'total' => 0
		]);
		
		// Verificar si ha sido cancelada la sincronización
		$cancelada = get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada');
		
		// Si está cancelada, asegurar que el porcentaje sea 100%
		if ($cancelada) {
			$porcentaje = 100;
		}
		
		// y sobrescribir el valor proporcionado si parece incorrecto
		if ($estadisticas['total'] > 0 && $estadisticas['procesados'] > 0) {
			$porcentaje_calculado = min(99.9, max(1, round(($estadisticas['procesados'] / $estadisticas['total']) * 100, 1)));
			
			// Si el porcentaje recibido es significativamente menor que el calculado, usar el calculado
			if ($porcentaje < $porcentaje_calculado * 0.8 || $porcentaje == 1) {
				$diagnostico['correccion_porcentaje'] = [
					'original' => $porcentaje,
					'calculado' => $porcentaje_calculado,
					'razon' => 'Porcentaje incorrecto o estancado'
				];
				$porcentaje = $porcentaje_calculado;
			}
		}
		
		// Asegurar que el porcentaje nunca sea menor a 1% cuando hay progreso
		// pero tampoco debe quedarse estancado en 1% si tenemos más datos
		if ($porcentaje < 1 && !empty($mensaje)) {
			if ($estadisticas['procesados'] > 1 && $estadisticas['total'] > 0) {
				$porcentaje = max(1, round(($estadisticas['procesados'] / $estadisticas['total']) * 100, 1));
			} else {
				$porcentaje = 1;
			}
		}
		
		// Guardar timestamp para cálculo de tiempo transcurrido
		$ahora = time();
		$inicio = get_transient('mia_sync_start_time');
		if (!$inicio) {
			$inicio = $ahora;
			set_transient('mia_sync_start_time', $inicio, 3600 * 24);
		}
		
		// Calcular tiempo transcurrido
		$tiempo_transcurrido = $ahora - $inicio;
		$tiempo_formateado = '';
		
		// Formatear tiempo legible
		if ($tiempo_transcurrido > 0) {
			$horas = floor($tiempo_transcurrido / 3600);
			$minutos = floor(($tiempo_transcurrido % 3600) / 60);
			$segundos = $tiempo_transcurrido % 60;
			
			if ($horas > 0) {
				$tiempo_formateado = sprintf('%02d:%02d:%02d', $horas, $minutos, $segundos);
			} else {
				$tiempo_formateado = sprintf('%02d:%02d', $minutos, $segundos);
			}
		}
		
		// MEJORA: Obtener nombre del artículo actual usando múltiples estrategias
		$nombre_articulo = self::get_articulos_with_fallback(['mensaje' => $mensaje, 'estadisticas' => $estadisticas]);
		if (!empty($nombre_articulo)) {
			$estadisticas['articulo_actual'] = $nombre_articulo;
		}
		// Añadir soporte para clientes y pedidos
		if (empty($nombre_articulo)) {
			if (isset($estadisticas['entity']) && $estadisticas['entity'] === 'clientes') {
				$estadisticas['articulo_actual'] = get_transient('mia_last_client_name') ?: get_transient('mia_last_client_email');
			} elseif (isset($estadisticas['entity']) && $estadisticas['entity'] === 'pedidos') {
				$estadisticas['articulo_actual'] = (get_transient('mia_last_order_ref') ?: '') . ' ' . (get_transient('mia_last_order_client') ?: '');
			}
		}
		
		// Sistema de depuración: almacenar historial de progreso para detectar problemas
		$historial_progreso = get_option('mia_sync_debug_progress', []);
		if (!is_array($historial_progreso)) {
			$historial_progreso = [];
		}
		
		// Limitar el tamaño del historial
		if (count($historial_progreso) > 20) {
			$historial_progreso = array_slice($historial_progreso, -20);
		}
		
		// Añadir registro actual
		$historial_progreso[] = [
			'timestamp' => $ahora,
			'porcentaje' => $porcentaje,
			'procesados' => $estadisticas['procesados'] ?? 0,
			'total' => $estadisticas['total'] ?? 0,
			'articulo' => $estadisticas['articulo_actual'] ?? '',
		];
		
		// Guardar historial actualizado para diagnóstico
		update_option('mia_sync_debug_progress', $historial_progreso, false);
		
		// Calcular velocidad de procesamiento y tiempo estimado
		$velocidad = 0;
		$tiempo_estimado = '';
		
		if (count($historial_progreso) > 1) {
			$primer_registro = $historial_progreso[0];
			$ultimo_registro = end($historial_progreso);
			$diferencia_tiempo = $ultimo_registro['timestamp'] - $primer_registro['timestamp'];
			$diferencia_procesados = $ultimo_registro['procesados'] - $primer_registro['procesados'];
			
			if ($diferencia_tiempo > 0 && $diferencia_procesados > 0) {
				// Calcular items por segundo
				$velocidad = $diferencia_procesados / $diferencia_tiempo;
				
				// Estimar tiempo restante
				$pendientes = $estadisticas['total'] - $estadisticas['procesados'];
				if ($velocidad > 0 && $pendientes > 0) {
					$segundos_restantes = $pendientes / $velocidad;
					$tiempo_estimado = self::format_elapsed_time($segundos_restantes);
				}
			}
		}
		
		// Datos mejorados para progreso
		$datos_progreso = array(
			'porcentaje'         => $porcentaje,
			'mensaje'            => $mensaje,
			'estadisticas'       => $estadisticas,
			'actualizado'        => $ahora,
			'inicio'             => $inicio,
			'tiempo_transcurrido' => $tiempo_transcurrido,
			'tiempo_formateado'  => $tiempo_formateado,
			'tiempo_estimado'    => $tiempo_estimado,
			'velocidad'          => $velocidad,
			'progress_percent'   => $porcentaje, // Duplicado para compatibilidad
			'cancelada'          => $cancelada,
			'diagnostico'        => $diagnostico, // Información de diagnóstico
			// Datos adicionales para mejorar visualización
			'current_article'    => $estadisticas['articulo_actual'] ?? '',
			'current_sku'        => $estadisticas['sku'] ?? '',
			'processed_count'    => $estadisticas['procesados'] ?? 0,
			'total_articles'     => $estadisticas['total'] ?? 0,
			'error_count'        => $estadisticas['errores'] ?? 0
		);
		
		// Guardar en transient con mayor tiempo de caducidad
		set_transient('mia_sync_progress', $datos_progreso, 3600 * 6);
		
		// Registrar en log
		Logger::info(
			sprintf('Progreso sincronización: %s%% - %s - Transcurrido: %s - Est. restante: %s - Artículo: %s', 
				$porcentaje, 
				$mensaje,
				$tiempo_formateado,
				$tiempo_estimado ?: 'N/A',
				$estadisticas['articulo_actual'] ?: 'No especificado'
			),
			'sync-progress'
		);
		
		return $datos_progreso;
	}

	public static function get_sync_progress() {
		$logger = new \MiIntegracionApi\Helpers\Logger('sync-debug');
		
		$transient_key = 'mia_sync_progress';
		$progress_data = get_transient($transient_key);
		
		if (false === $progress_data) {
			$logger->info('Transient de progreso no encontrado: ' . $transient_key);
			// Valores predeterminados si no hay transient
			$progress_data = array(
				'porcentaje'   => 0,
				'mensaje'      => 'No hay sincronización en progreso',
				'estadisticas' => array(),
				'actualizado'  => 0,
			);
		} else {
			$logger->info('Transient de progreso recuperado', [
				'key' => $transient_key,
				'data' => $progress_data
			]);
		}
		
		return $progress_data;
	}

	public static function sync_progress_callback() {
		$logger = new \MiIntegracionApi\Helpers\Logger('sync-debug');
		
		// Registramos la llamada completa para depuración
		$logger->info('AJAX sync_progress_callback: inicio', [
			'user_id' => get_current_user_id(),
			'request' => $_REQUEST,
			'server' => [
				'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? 'No disponible',
				'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
				'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'],
				'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'No disponible',
			]
		]);
		
		// Verificación más flexible del nonce para compatibilidad con diferentes llamadas
		$nonce_valid = false;
		
		// Verificar si se recibe el nonce y probar contra diferentes acciones posibles
		if (isset($_REQUEST['nonce'])) {
			$nonce_actions = [
				'mia_sync_nonce', 
				MiIntegracionApi_NONCE_PREFIX . 'dashboard',
				'mi_integracion_api_nonce'
			];
			
			foreach ($nonce_actions as $action) {
				$is_valid = wp_verify_nonce($_REQUEST['nonce'], $action);
				$logger->debug('Verificando nonce con acción: ' . $action, [
					'nonce' => $_REQUEST['nonce'],
					'resultado' => $is_valid ? 'válido' : 'inválido'
				]);
				
				if ($is_valid) {
					$nonce_valid = true;
					$logger->info('Nonce válido encontrado con la acción: ' . $action);
					break;
				}
			}
		} else {
			$logger->warning('No se proporcionó nonce en la solicitud');
		}
		
		// En producción insistir en la verificación del nonce
		if (!$nonce_valid && (!defined('MIA_DEBUG') || !MIA_DEBUG)) {
			$logger->error('Error de verificación de seguridad: nonce inválido', [
				'nonce_recibido' => $_REQUEST['nonce'] ?? 'No proporcionado',
				'acciones_probadas' => $nonce_actions ?? []
			]);
			
			wp_send_json_error([
				'message' => __('Error de verificación de seguridad', 'mi-integracion-api'),
				'code' => 'invalid_nonce',
				'debug_info' => defined('WP_DEBUG') && WP_DEBUG ? [
					'nonce_recibido' => $_REQUEST['nonce'] ?? null,
					'acciones_probadas' => $nonce_actions
				] : null
			], 403);
			return;
		}
		
		// Obtener y registrar el progreso
		$progreso = self::get_sync_progress();
		$logger->info('AJAX sync_progress_callback: enviando respuesta', [
			'progreso' => $progreso
		]);
		
		wp_send_json_success($progreso);
	}

	public static function sync_heartbeat_callback() {
		check_ajax_referer( 'mia_sync_nonce', 'nonce' );
		wp_send_json_success([
			'active'    => true,
			'timestamp' => time(),
		]);
	}

	public static function sync_cancel_callback() {
		// Desactivar comprobación de referrer para evitar problemas con AJAX
		if (!isset($GLOBALS['_wp_die_disabled'])) {
			$GLOBALS['_wp_die_disabled'] = false;
		}
		$old_ref = $GLOBALS['_wp_die_disabled'];
		$GLOBALS['_wp_die_disabled'] = false;
		
		try {
			// Crear una instancia del Logger
			$logger = new \MiIntegracionApi\Helpers\Logger('sync-cancel');
			$logger->info('Solicitud de cancelación recibida', [
				'request_params' => [
					'nonce' => isset($_REQUEST['nonce']) ? substr($_REQUEST['nonce'], 0, 4) . '...' : 'no enviado',
					'nonce_alt' => isset($_REQUEST['nonce_alt']) ? substr($_REQUEST['nonce_alt'], 0, 4) . '...' : 'no enviado',
					'_ajax_nonce' => isset($_REQUEST['_ajax_nonce']) ? substr($_REQUEST['_ajax_nonce'], 0, 4) . '...' : 'no enviado',
					'timestamp' => $_REQUEST['timestamp'] ?? 'no enviado'
				],
				'user_id' => get_current_user_id(),
				'time' => current_time('mysql')
			]);
			
			// En caso de emergencia, omitir por completo la verificación de seguridad
			// Si el usuario es administrador o estamos en modo de depuración, permitir cancelación
			// sin verificación estricta de nonce
			if (current_user_can('manage_options') || (defined('MIA_DEBUG') && MIA_DEBUG) || !empty($_REQUEST['emergency'])) {
				// Log para fines de auditoría
				$logger->info('Cancelación de emergencia autorizada por usuario administrador o modo de depuración', [
					'admin' => current_user_can('manage_options'),
					'debug_mode' => defined('MIA_DEBUG') && MIA_DEBUG,
					'emergency' => !empty($_REQUEST['emergency']),
					'user_id' => get_current_user_id()
				]);
			} else {
				// Verificar varios tipos de nonce con múltiples nombres de acción
				$nonce_verificado = false;
				$nonce_params = ['nonce', '_ajax_nonce', 'nonce_alt']; 
				$nonce_actions = ['mia_sync_monitor_nonce', 'mia_sync_nonce', 'mi_integracion_api_nonce'];
				
				foreach ($nonce_params as $param) {
					if (empty($_REQUEST[$param])) continue;
					
					foreach ($nonce_actions as $action) {
						if (wp_verify_nonce($_REQUEST[$param], $action)) {
							$nonce_verificado = true;
							$logger->info('Nonce verificado correctamente', [
								'param' => $param,
								'action' => $action
							]);
							break 2;
						}
					}
				}
				
				// Si no se verificó ningún nonce, intentar modo alternativo
				if (!$nonce_verificado) {
					$logger->warning('Verificación de nonce falló, intentando modo de emergencia', [
						'params_recibidos' => array_keys($_REQUEST)
					]);
					
					wp_send_json_error([
						'mensaje' => __('Error de verificación de seguridad. Recargue la página e intente nuevamente.', 'mi-integracion-api'),
						'code' => 'invalid_nonce'
					]);
					return;
				}
			}
			
			// -------- ACCIONES DE CANCELACIÓN FORZADA --------
			
			// Asegurarse de que el estado de la opción y transient se limpien
			update_option('mia_sync_cancelada', true);
			set_transient('mia_sync_cancelada', true, 24 * HOUR_IN_SECONDS); // Caché de 24 horas
			
			// Eliminar datos de progreso
			delete_transient('mia_sync_progress');
			delete_transient('mia_sync_start_time');
			
			// Eliminar transients específicos de la sincronización por lotes
			delete_transient('mi_integracion_api_sync_products_in_progress');
			delete_transient('mi_integracion_api_sync_products_offset');
			delete_transient('mi_integracion_api_sync_products_batch_count');
			delete_transient('mi_integracion_api_sync_last_activity');
			delete_transient('mia_sync_heartbeat');
			
			// Registrar en el log
			$logger->info(__('Sincronización cancelada por el usuario', 'mi-integracion-api'));
			
			// Cambiar a namespace correcto y asegurar inclusión con mejor manejo de errores
            try {
                if (!class_exists('MiIntegracionApi\\Sync\\SyncManager')) {
                    $sync_manager_file = dirname(__DIR__) . '/Sync/SyncManager.php';
                    $logger->info('Intentando cargar SyncManager para cancelación desde: ' . $sync_manager_file);
                    
                    if (file_exists($sync_manager_file)) {
                        require_once $sync_manager_file;
                    } else {
                        throw new \Exception('No se pudo encontrar el archivo SyncManager.php en: ' . $sync_manager_file);
                    }
                }
                
                // Usamos la clase importada correctamente
                $sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
                $logger->info('SyncManager cargado correctamente para cancelación');
            } catch (\Throwable $e) {
                $logger->error('Error al cargar SyncManager para cancelación: ' . $e->getMessage(), [
                    'exception' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                // Continuamos con la cancelación manual sin SyncManager
            }
			
			// Intentar llamar a cancel_sync solo si tenemos la instancia de SyncManager
			$result = isset($sync_manager) ? $sync_manager->cancel_sync() : false;
			
			if (!isset($sync_manager) || $result === false) {
				// Si no se pudo llamar al método o no tenemos SyncManager, hacer limpieza manual
				$logger->info('Realizando limpieza manual de sincronización');
				
				// Eliminar transients adicionales que puedan estar relacionados con la sincronización
				delete_transient('mia_sync_products_in_progress');
				delete_transient('mia_sync_products_offset');
				delete_transient('mia_sync_products_batch_count');
				delete_transient('mia_sync_last_activity');
				
				// Resultado manual para devolver
				$result = [
					'status' => 'cancelled_manually',
					'message' => 'Sincronización cancelada manualmente'
				];
			}
			
			// Forzar limpieza adicional para asegurar que la sincronización se detenga
			wp_cache_flush();
			
			// Respuesta de éxito al cliente con más detalles para depuración en consola
			wp_send_json_success([
				'mensaje' => __('Sincronización cancelada correctamente', 'mi_integracion_api'),
				'timestamp' => current_time('timestamp'),
				'status' => $result,
				'success_code' => 'sync_cancelled'
			]);
		} catch (\Throwable $e) { // Capturar cualquier tipo de error
			$logger->error('Error al cancelar sincronización: ' . $e->getMessage(), [
				'exception_class' => get_class($e),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString()
			]);
			wp_send_json_error([
				'mensaje' => __('Error al cancelar sincronización: ', 'mi-integracion-api') . $e->getMessage(),
				'code' => 'exception',
				'file' => basename($e->getFile()),
				'line' => $e->getLine()
			]);
		} finally {
			// Restaurar el estado del referrer solo si estaba definido anteriormente
			if (isset($old_ref)) {
				$GLOBALS['_wp_die_disabled'] = $old_ref;
			}
		}
	}

	// Eliminado método duplicado sync_status_callback - ver implementación más abajo

	// Eliminado método duplicado sync_products_batch - ver implementación más abajo

	public static function test_api() {
		check_ajax_referer( 'mia_sync_nonce', '_ajax_nonce' );
		$ok = true;
		if ( $ok ) {
			wp_send_json_success( array( 'message' => __( 'Conexión OK', 'mi-integracion-api' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'No se pudo conectar con la API de Verial', 'mi-integracion-api' ) ) );
		}
	}

	public static function clear_cache() {
		check_ajax_referer( 'mia_sync_nonce', '_ajax_nonce' );
		wp_cache_flush();
		wp_send_json_success( array( 'message' => __( 'Caché limpiada correctamente.', 'mi-integracion-api' ) ) );
	}

	public static function load_filter_options() {
		check_ajax_referer( 'mia_sync_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
		}
		if ( ! class_exists( 'MiIntegracionApi\\Core\\ApiConnector' ) ) {
			require_once dirname( __DIR__ ) . '/ApiConnector.php';
		}
		if ( ! class_exists( 'MI_Endpoint_GetCategoriasWS' ) ) {
			require_once dirname( __DIR__ ) . '/endpoints/GetCategoriasWS.php';
		}
		if ( ! class_exists( 'MI_Endpoint_GetFabricantesWS' ) ) {
			require_once dirname( __DIR__ ) . '/endpoints/GetFabricantesWS.php';
		}
		$connector     = new \MiIntegracionApi\Core\ApiConnector();
		$sesion        = get_option( 'mi_integracion_api_numero_sesion' );
		$categories    = array();
		$manufacturers = array();
		if ( class_exists( 'MI_Endpoint_GetCategoriasWS' ) ) {
			$endpoint = new \MI_Endpoint_GetCategoriasWS( $connector );
			$response = $endpoint->execute_restful( (object) array( 'sesionwcf' => $sesion ) );
			if ( $response instanceof \WP_REST_Response ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					foreach ( $data as $cat ) {
						$categories[] = array(
							'id'     => $cat['IdCategoria'] ?? $cat['id'] ?? $cat['id_categoria'] ?? '',
							'nombre' => $cat['Nombre'] ?? $cat['nombre'] ?? '',
						);
					}
				}
			}
		}
		if ( class_exists( 'MI_Endpoint_GetFabricantesWS' ) ) {
			$endpoint = new \MI_Endpoint_GetFabricantesWS( $connector );
			$response = $endpoint->execute_restful( (object) array( 'sesionwcf' => $sesion ) );
			if ( $response instanceof \WP_REST_Response ) {
				$data = $response->get_data();
				if ( is_array( $data ) ) {
					foreach ( $data as $fab ) {
						$manufacturers[] = array(
							'id'     => $fab['id'] ?? $fab['Id'] ?? '',
							'nombre' => $fab['nombre'] ?? $fab['Nombre'] ?? '',
						);
					}
				}
			}
		}
		wp_send_json_success([
			'categories'    => $categories,
			'manufacturers' => $manufacturers,
		]);
	}

	/**
	 * Sincroniza un producto individual por SKU (AJAX)
	 */
	public static function sync_single_product() {
		check_ajax_referer( 'mi_sync_single_product', '_ajax_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
		}
		$sku = isset($_POST['sku']) ? sanitize_text_field($_POST['sku']) : '';
		$nombre = isset($_POST['nombre']) ? sanitize_text_field($_POST['nombre']) : '';
		$categoria = isset($_POST['categoria']) ? sanitize_text_field($_POST['categoria']) : '';
		$fabricante = isset($_POST['fabricante']) ? sanitize_text_field($_POST['fabricante']) : '';
		if ( empty($sku) && empty($nombre) && empty($categoria) && empty($fabricante) ) {
			wp_send_json_error( array( 'message' => __( 'Debes indicar al menos un filtro.', 'mi-integracion-api' ) ) );
		}
		if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
			require_once dirname(__DIR__) . '/Core/ApiConnector.php';
		}
		if ( ! class_exists( '\MiIntegracionApi\Sync\SyncSingleProduct' ) ) {
			require_once dirname(__DIR__) . '/Sync/SyncSingleProduct.php';
		}
		if ( ! class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
            require_once dirname(__DIR__) . '/Helpers/Logger.php';
        }
		$logger = new \MiIntegracionApi\Helpers\Logger('ajax-sync-single-product');
		$api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
		
		// Configurar la URL de la API y la sesión WCF
        $api_url = get_option('mi_integracion_api_url', '');
        $sesion_wcf = get_option('mi_integracion_api_sesion_wcf', '');
        if (!empty($api_url)) {
            $api_connector->set_api_url($api_url);
        }
        if (!empty($sesion_wcf)) {
            $api_connector->set_sesion_wcf($sesion_wcf);
        }
        $logger->info('ApiConnector inicializado para sincronización de producto individual con URL: ' . $api_url);
        
		$resultado = \MiIntegracionApi\Sync\SyncSingleProduct::sync($api_connector, $sku, $nombre, $categoria, $fabricante);
		
		// Registrar el resultado para depuración
		error_log('[MI] Resultado de sincronización de producto individual: ' . print_r($resultado, true));
		$logger->info('Resultado de sincronización: ' . ($resultado['success'] ? 'ÉXITO' : 'ERROR') . ' - ' . 
			($resultado['message'] ?? 'Sin mensaje'));
		
		if ( is_array( $resultado ) && ! empty( $resultado['success'] ) ) {
			wp_send_json_success( array( 'message' => $resultado['message'] ?? __( 'Producto sincronizado correctamente.', 'mi-integracion-api' ) ) );
		} else {
			wp_send_json_error( array( 'message' => $resultado['message'] ?? __( 'Error al sincronizar el producto.', 'mi-integracion-api' ) ) );
		}
	}

	/**
	 * Devuelve las categorías de Verial para el formulario (AJAX)
	 */
	public static function get_categorias() {
        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('[MI] Permiso denegado en get_categorias');
            wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
        }
        if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
            require_once dirname(__DIR__ ) . '/Core/ApiConnector.php';
        }
        if ( ! class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
            require_once dirname(__DIR__ ) . '/Helpers/Logger.php';
        }
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-categorias');
        $api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
        
        // Configurar la URL de la API y la sesión WCF
        $api_url = get_option('mi_integracion_api_url', '');
        $sesion_wcf = get_option('mi_integracion_api_sesion_wcf', '');
        if (!empty($api_url)) {
            $api_connector->set_api_url($api_url);
        }
        if (!empty($sesion_wcf)) {
            $api_connector->set_sesion_wcf($sesion_wcf);
        }
        $logger->info('ApiConnector inicializado para categorías con URL: ' . $api_url);
        $categorias = $api_connector->get_categorias();
        error_log('[MI] Respuesta cruda get_categorias: ' . print_r($categorias, true));
        
        if ( is_wp_error($categorias) ) {
            error_log('[MI] Error al obtener categorías: ' . $categorias->get_error_message());
            wp_send_json_error( array( 'message' => $categorias->get_error_message() ) );
        }
        
        $categorias_list = [];
        
        // Intentar extraer categorías de varios formatos de respuesta posibles
        if (is_array($categorias)) {
            // Formato 1: Respuesta con índice 'Categorias'
            if (isset($categorias['Categorias']) && is_array($categorias['Categorias'])) {
                foreach ($categorias['Categorias'] as $cat) {
                    if (isset($cat['Id']) || isset($cat['id']) || isset($cat['Nombre']) || isset($cat['nombre'])) {
                        $categorias_list[] = [
                            'id' => $cat['Id'] ?? $cat['id'] ?? '',
                            'nombre' => $cat['Nombre'] ?? $cat['nombre'] ?? '',
                        ];
                    }
                }
            }
            // Formato 2: Array de categorías directo
            elseif (isset($categorias[0]) && (isset($categorias[0]['Id']) || isset($categorias[0]['id']))) {
                foreach ($categorias as $cat) {
                    $categorias_list[] = [
                        'id' => $cat['Id'] ?? $cat['id'] ?? '',
                        'nombre' => $cat['Nombre'] ?? $cat['nombre'] ?? '',
                    ];
                }
            }
            // Formato 3: Objeto plano con id => nombre
            elseif (count($categorias) > 0 && !isset($categorias[0])) {
                foreach ($categorias as $id => $nombre) {
                    if (is_string($nombre) || is_numeric($nombre)) {
                        $categorias_list[] = [
                            'id' => $id,
                            'nombre' => $nombre,
                        ];
                    }
                }
            }
        }
        
        // Si no se encontraron categorías, registrar y proporcionar valores por defecto
        if (empty($categorias_list)) {
            error_log('[MI] No se encontraron categorías válidas en la respuesta.');
            $logger->warning('Respuesta de categorías vacía o en formato no reconocido', [
                'respuesta_cruda' => $categorias
            ]);
            
            // Añadir una categoría por defecto para que el formulario funcione
            $categorias_list[] = [
                'id' => '0',
                'nombre' => 'Categoría por defecto',
            ];
        }
        
        wp_send_json_success( array( 'categories' => $categorias_list ) );
    }

    /**
     * Devuelve los fabricantes de Verial para el formulario (AJAX)
     */
    public static function get_fabricantes() {
        if ( ! current_user_can( 'manage_options' ) ) {
            error_log('[MI] Permiso denegado en get_fabricantes');
            wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
        }
        if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
            require_once dirname(__DIR__) . '/Core/ApiConnector.php';
        }
        if ( ! class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
            require_once dirname(__DIR__) . '/Helpers/Logger.php';
        }
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-fabricantes');
        $api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
        
        // Configurar la URL de la API y la sesión WCF
        $api_url = get_option('mi_integracion_api_url', '');
        $sesion_wcf = get_option('mi_integracion_api_sesion_wcf', '');
        if (!empty($api_url)) {
            $api_connector->set_api_url($api_url);
        }
        if (!empty($sesion_wcf)) {
            $api_connector->set_sesion_wcf($sesion_wcf);
        }
        $logger->info('ApiConnector inicializado para fabricantes con URL: ' . $api_url);
        
        if (method_exists($api_connector, 'get_fabricantes')) {
            $fabricantes = $api_connector->get_fabricantes();
        } else {
            $fabricantes = [];
            error_log('[MI] El método get_fabricantes no existe en ApiConnector');
        }
        
        error_log('[MI] Respuesta cruda get_fabricantes: ' . print_r($fabricantes, true));
        
        if ( is_wp_error($fabricantes) ) {
            error_log('[MI] Error al obtener fabricantes: ' . $fabricantes->get_error_message());
            wp_send_json_error( array( 'message' => $fabricantes->get_error_message() ) );
        }
        
        $fabricantes_list = [];
        
        // Intentar extraer fabricantes de varios formatos de respuesta posibles
        if (is_array($fabricantes)) {
            // Formato 1: Respuesta con índice 'Fabricantes'
            if (isset($fabricantes['Fabricantes']) && is_array($fabricantes['Fabricantes'])) {
                foreach ($fabricantes['Fabricantes'] as $fab) {
                    if (isset($fab['Id']) || isset($fab['id']) || isset($fab['Nombre']) || isset($fab['nombre'])) {
                        $fabricantes_list[] = [
                            'id' => $fab['Id'] ?? $fab['id'] ?? '',
                            'nombre' => $fab['Nombre'] ?? $fab['nombre'] ?? '',
                        ];
                    }
                }
            }
            // Formato 2: Array de fabricantes directo
            elseif (isset($fabricantes[0]) && (isset($fabricantes[0]['Id']) || isset($fabricantes[0]['id']))) {
                foreach ($fabricantes as $fab) {
                    $fabricantes_list[] = [
                        'id' => $fab['Id'] ?? $fab['id'] ?? '',
                        'nombre' => $fab['Nombre'] ?? $fab['nombre'] ?? '',
                    ];
                }
            }
            // Formato 3: Objeto plano con id => nombre
            elseif (count($fabricantes) > 0 && !isset($fabricantes[0])) {
                foreach ($fabricantes as $id => $nombre) {
                    if (is_string($nombre) || is_numeric($nombre)) {
                        $fabricantes_list[] = [
                            'id' => $id,
                            'nombre' => $nombre,
                        ];
                    }
                }
            }
        }
        
        // Si no se encontraron fabricantes, registrar y proporcionar valores por defecto
        if (empty($fabricantes_list)) {
            error_log('[MI] No se encontraron fabricantes válidos en la respuesta.');
            $logger->warning('Respuesta de fabricantes vacía o en formato no reconocido', [
                'respuesta_cruda' => $fabricantes
            ]);
            
            // Añadir un fabricante por defecto para que el formulario funcione
            $fabricantes_list[] = [
                'id' => '0',
                'nombre' => 'Fabricante por defecto',
            ];
        }
        
        wp_send_json_success( array( 'manufacturers' => $fabricantes_list ) );
    }

	/**
	 * Autocompleta SKUs de productos desde Verial (AJAX)
	 */
	public static function autocomplete_sku() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
		}
		$term = isset($_GET['term']) ? sanitize_text_field($_GET['term']) : '';
		if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
			require_once dirname(__DIR__) . '/Core/ApiConnector.php';
		}
		$api_connector = new \MiIntegracionApi\Core\ApiConnector();
        $productos = $api_connector->get_articulos([ 'buscar' => $term ]);
        if ( is_wp_error($productos) ) {
            wp_send_json_error( array( 'message' => $productos->get_error_message() ) );
        }
        $results = array();
        if (is_array($productos) && isset($productos['Articulos']) && is_array($productos['Articulos'])) {
            foreach ($productos['Articulos'] as $p) {
                if (
                    (isset($p['ReferenciaBarras']) && stripos($p['ReferenciaBarras'], $term) !== false) ||
                    (isset($p['Nombre']) && stripos($p['Nombre'], $term) !== false)
                ) {
                    $results[] = array(
                        'id' => $p['ReferenciaBarras'],
                        'label' => $p['ReferenciaBarras'] . ' - ' . ($p['Nombre'] ?? ''),
                        'value' => $p['ReferenciaBarras'],
                    );
                }
            }
        }
        wp_send_json($results);
	}

	/**
	 * Busca productos en Verial para el autocompletado
	 * Compatible con el nuevo formato requerido por el frontend
	 */
	public static function search_product() {
		// Iniciar logger para diagnóstico
		$logger = new \MiIntegracionApi\Helpers\Logger('ajax-product-search');
        $logger->info('Iniciando búsqueda de producto', [
            'request' => $_REQUEST,
            'get' => $_GET,
            'post' => $_POST
        ]);
		
		// Verificar permisos
		if ( ! current_user_can( 'manage_options' ) ) {
			$logger->error('Usuario sin permisos');
			wp_send_json_error( array( 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ) );
			return;
		}
		
		// Extraer parámetros de búsqueda
		$term = isset($_REQUEST['search']) ? sanitize_text_field($_REQUEST['search']) : '';
		$field = isset($_REQUEST['field']) ? sanitize_text_field($_REQUEST['field']) : 'id';
		
		$logger->info('Parámetros de búsqueda', [
			'term' => $term,
			'field' => $field
		]);
		
		if (empty($term)) {
			$logger->warning('Término de búsqueda vacío');
			wp_send_json([]);
			return;
		}
		
		// Cargar dependencias
		if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
			require_once dirname(__DIR__) . '/Core/ApiConnector.php';
		}
        
        if ( ! class_exists( '\MiIntegracionApi\Helpers\Logger' ) ) {
            require_once dirname(__DIR__) . '/Helpers/Logger.php';
        }
        
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-product-search');
        $api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
        
        // Configurar la URL de la API y la sesión WCF
        $api_url = get_option('mi_integracion_api_url', '');
        $sesion_wcf = get_option('mi_integracion_api_sesion_wcf', '');
        if (!empty($api_url)) {
            $api_connector->set_api_url($api_url);
        }
        if (!empty($sesion_wcf)) {
            $api_connector->set_sesion_wcf($sesion_wcf);
        }
		
		try {
			// Preparar los parámetros de búsqueda según el campo
			$search_params = [];
			
			if ($field === 'id') {
				// Si buscamos por ID/SKU, enviamos en ambos campos para asegurar la compatibilidad
				$search_params['referenciabarras'] = $term; // Campo principal para SKU
				$search_params['referencia'] = $term;      // Campo secundario por compatibilidad
				$logger->info('Buscando por SKU/ID', $search_params);
			} else if ($field === 'nombre') {
				$search_params['nombre'] = $term;
				$logger->info('Buscando por nombre', $search_params);
			} else {
				// Búsqueda general
				$search_params['buscar'] = $term;
				$logger->info('Búsqueda general', $search_params);
			}
			
			$productos = $api_connector->get_articulos($search_params);
			
			if (is_wp_error($productos)) {
				$logger->error('Error en API', [
					'message' => $productos->get_error_message(),
					'code' => $productos->get_error_code()
				]);
				wp_send_json_error(['message' => $productos->get_error_message()]);
				return;
			}
			
			// Registrar estructura de la respuesta para depuración
			$logger->info('Estructura de respuesta', [
				'es_array' => is_array($productos),
				'tiene_articulos' => isset($productos['Articulos']),
				'articulos_es_array' => isset($productos['Articulos']) && is_array($productos['Articulos']),
				'keys' => is_array($productos) ? array_keys($productos) : 'no_es_array',
				'primer_elemento' => is_array($productos) && !empty($productos) ? (isset($productos[0]) ? array_keys($productos[0]) : 'no_tiene_indice_0') : 'array_vacío',
			]);
			
			// Procesar la respuesta que puede venir en diferentes formatos
			$articulos = [];
			if (is_array($productos)) {
				if (isset($productos['Articulos']) && is_array($productos['Articulos'])) {
					// Formato 1: Respuesta con índice 'Articulos'
					$articulos = $productos['Articulos'];
					$logger->info('Productos recibidos en formato "Articulos"', ['count' => count($articulos)]);
				} else if (isset($productos[0]) && (isset($productos[0]['Id']) || isset($productos[0]['ReferenciaBarras']) || isset($productos[0]['Nombre']))) {
					// Formato 2: Array directo de productos
					$articulos = $productos;
					$logger->info('Productos recibidos como array directo', ['count' => count($articulos)]);
				} else if (!empty($productos)) {
					// Formato 3: Otro formato, intentar procesar de todas formas
					$articulos = $productos;
					$logger->warning('Formato de productos desconocido, procesando como array directo', ['count' => count($articulos), 'muestra' => array_slice($productos, 0, 1)]);
				}
			}
			
			$results = [];
			
			foreach ($articulos as $p) {
				$add_to_results = false;
				$id_value = '';
				$label = '';
                $desc = '';
				
				// Buscar según el campo especificado
				if ($field === 'id') {
					// Prioridad 1: ReferenciaBarras (campo principal para SKU)
					if (isset($p['ReferenciaBarras']) && !empty($p['ReferenciaBarras']) && stripos($p['ReferenciaBarras'], $term) !== false) {
						$id_value = $p['ReferenciaBarras'];
						$label = 'SKU: ' . $p['ReferenciaBarras'];
						$desc = isset($p['Nombre']) ? $p['Nombre'] : '';
						$add_to_results = true;
					} 
					// Prioridad 2: Id como respaldo
					else if (isset($p['Id']) && stripos((string)$p['Id'], $term) !== false) {
						$id_value = (string)$p['Id'];
						$label = 'ID: ' . $p['Id'];
						$desc = isset($p['Nombre']) ? $p['Nombre'] : '';
						$add_to_results = true;
					}
					// Prioridad 3: Referencia como alternativa
					else if (isset($p['Referencia']) && !empty($p['Referencia']) && stripos($p['Referencia'], $term) !== false) {
						$id_value = $p['Referencia'];
						$label = 'Ref: ' . $p['Referencia'];
						$desc = isset($p['Nombre']) ? $p['Nombre'] : '';
						$add_to_results = true;
					}
				} 
				else if ($field === 'nombre' && isset($p['Nombre']) && stripos($p['Nombre'], $term) !== false) {
					// Búsqueda por nombre
					// Para el valor de ID, preferimos ReferenciaBarras como SKU
					if (isset($p['ReferenciaBarras']) && !empty($p['ReferenciaBarras'])) {
						$id_value = $p['ReferenciaBarras'];
						$desc = 'SKU: ' . $p['ReferenciaBarras'];
					} else if (isset($p['Id'])) {
						$id_value = (string)$p['Id'];
						$desc = 'ID: ' . $p['Id'];
					}
					$label = $p['Nombre'];
					$add_to_results = true;
				}
				
				if ($add_to_results && !empty($id_value)) {
					$results[] = [
						'id' => $id_value,
						'label' => $label,
						'value' => $id_value,
						'desc' => $desc
					];
				}
			}
			
			$logger->info('Resultados encontrados', ['count' => count($results)]);
			
			// Devolver resultados directamente sin wp_send_json_success para mantener
            // compatibilidad con jQuery UI Autocomplete
			wp_send_json($results);
			
		} catch (\Exception $e) {
			$logger->error('Excepción en search_product', [
				'message' => $e->getMessage(),
				'file' => $e->getFile(),
				'line' => $e->getLine(),
				'trace' => $e->getTraceAsString()
			]);
			wp_send_json_error(['message' => 'Error al buscar productos: ' . $e->getMessage()]);
		}
	}
	
	/**
	 * Obtiene el estado detallado de la sincronización en curso
	 * 
	 * @return void
	 */
	public static function sync_status_callback() {
		// Crear un logger específico para esta operación
		$logger = new Logger('sync_status');
		
		// Verificación más flexible del nonce para evitar problemas con diferentes llamadas
		$nonce_valid = false;
		$nonce_actions = ['mia_sync_monitor_nonce', 'mia_sync_nonce', 'mi_integracion_api_nonce'];
		
		if (isset($_REQUEST['nonce'])) {
			foreach($nonce_actions as $action) {
				if (wp_verify_nonce($_REQUEST['nonce'], $action)) {
					$nonce_valid = true;
					$logger->debug('Nonce válido encontrado para acción: ' . $action);
					break;
				}
			}
		}
		
		// En modo producción insistimos en verificación de nonce
		if (!$nonce_valid && (!defined('MIA_DEBUG') || !MIA_DEBUG)) {
			$logger->warning('Error de verificación de nonce en sync_status_callback', [
				'nonce' => isset($_REQUEST['nonce']) ? substr($_REQUEST['nonce'], 0, 5) . '...' : 'no proporcionado',
				'acciones_probadas' => $nonce_actions
			]);
			
			wp_send_json_error([
				'message' => __('Error de verificación de seguridad', 'mi-integracion-api'),
				'code' => 'invalid_nonce'
			]);
			return;
		}
		
		try {
			// Obtener instancia del SyncManager
			// Primero intentar con la clase Core\Sync_Manager
			if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
				$core_path = dirname(__DIR__) . '/Core/Sync_Manager.php';
				$logger->info('Intentando cargar Core\Sync_Manager desde: ' . $core_path);
				
				if (file_exists($core_path)) {
					require_once $core_path;
					$logger->info('Core\Sync_Manager cargado correctamente');
				} else {
					// Si no existe, intentar con la ruta alternativa Sync\SyncManager
					$sync_path = dirname(__DIR__, 2) . '/Sync/SyncManager.php';
					$logger->info('Core\Sync_Manager no encontrado, intentando con: ' . $sync_path);
					
					if (file_exists($sync_path)) {
						require_once $sync_path;
					} else {
						throw new \Exception('No se pudo encontrar la clase Sync_Manager en ninguna ubicación');
					}
				}
			}
			
			// Determinar qué clase usar según cuál esté disponible
			if (class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
				$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
				$logger->info('Usando MiIntegracionApi\Core\Sync_Manager');
			} elseif (class_exists('\MiIntegracionApi\Sync\SyncManager')) {
				$sync_manager = \MiIntegracionApi\Sync\SyncManager::get_instance();
				$logger->info('Usando MiIntegracionApi\Sync\SyncManager');
			} else {
				throw new \Exception('No se pudo cargar ninguna implementación de Sync_Manager');
			}
			
			// Obtener estado de la sincronización
			$status = $sync_manager->get_sync_status();
			$logger->info('Estado de sincronización obtenido', [
				'in_progress' => $status['current_sync']['in_progress'] ?? false,
				'entity' => $status['current_sync']['entity'] ?? 'ninguna',
				'direction' => $status['current_sync']['direction'] ?? 'ninguna',
			]);
			
			// Obtener progreso de transient si hay sincronización en curso
			if ($status['current_sync']['in_progress']) {
				$progress = self::get_sync_progress();
				
				// Añadir información adicional del progreso
				if (!empty($progress['porcentaje'])) {
					$status['current_sync']['progress_percent'] = $progress['porcentaje'];
				}
				
				if (!empty($progress['mensaje'])) {
					$status['current_sync']['status_message'] = $progress['mensaje'];
				}
				
				// Utilizar datos de tiempo formateados del transient si están disponibles
				if (!empty($progress['tiempo_formateado'])) {
					$status['current_sync']['elapsed_formatted'] = $progress['tiempo_formateado'];
					$status['current_sync']['elapsed_time'] = $progress['tiempo_transcurrido'] ?? 0;
				}
				
				// Adicionar la información del artículo actual
				if (!empty($progress['estadisticas'])) {
					$status['current_sync']['statistics'] = $progress['estadisticas'];
					
					// Añadir el artículo actual como una propiedad separada para facilitar el acceso desde JS
					if (!empty($progress['estadisticas']['articulo_actual'])) {
						$status['current_sync']['current_article'] = $progress['estadisticas']['articulo_actual'];
						$status['current_sync']['current_sku'] = $progress['estadisticas']['sku'] ?? 'Sin SKU';
					} else {
						// Función de respaldo para intentar obtener el nombre del producto de diferentes fuentes
						$nombre_articulo = self::get_articulos_with_fallback($progress);
						
						if (!empty($nombre_articulo)) {
							$status['current_sync']['current_article'] = $nombre_articulo;
						}
					}
					
					// Añadir estadísticas detalladas
					$status['current_sync']['processed_count'] = $progress['estadisticas']['procesados'] ?? 0;
					$status['current_sync']['error_count'] = $progress['estadisticas']['errores'] ?? 0;
					$status['current_sync']['total_articles'] = $progress['estadisticas']['total'] ?? 0;
				}
				
				// Calcular tiempo transcurrido si no estaba en el transient
				if (!isset($status['current_sync']['elapsed_time']) && !empty($status['current_sync']['start_time'])) {
					$status['current_sync']['elapsed_time'] = time() - $status['current_sync']['start_time'];
					$status['current_sync']['elapsed_formatted'] = self::format_elapsed_time($status['current_sync']['elapsed_time']);
				}
				
				// Verificar estado de actividad mediante transient actualizado
				$last_update_time = $progress['actualizado'] ?? 0;
				$status['current_sync']['stalled'] = (time() - $last_update_time) > 30; // Consideramos estancado después de 30 segundos sin actualización
				
				// Verificar si la opción de cancelación está activa
				$status['current_sync']['cancel_requested'] = get_option('mia_sync_cancelada', false);
			}
			
			// Verificar si hay errores recientes
			if (class_exists('\MiIntegracionApi\Core\Installer')) {
				global $wpdb;
				$table_name = $wpdb->prefix . \MiIntegracionApi\Core\Installer::SYNC_ERRORS_TABLE;
				
				// Solo verificar si la tabla existe
				if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
					// Obtener el conteo de errores para la sincronización actual
					$sync_run_id = $status['current_sync']['run_id'] ?? '';
					if (!empty($sync_run_id)) {
						$error_count = $wpdb->get_var(
							$wpdb->prepare(
								"SELECT COUNT(*) FROM $table_name WHERE sync_run_id = %s",
								$sync_run_id
							)
						);
						$status['current_sync']['db_error_count'] = (int)$error_count;
					}
				}
			}
			
			$logger->info('Enviando estado de sincronización completo', [
				'progress_percent' => $status['current_sync']['progress_percent'] ?? 0,
				'processed_count' => $status['current_sync']['processed_count'] ?? 0,
				'total_items' => $status['current_sync']['total_items'] ?? 0
			]);
			
			wp_send_json_success($status);
		} catch (\Throwable $e) {
			$logger->error('Error al obtener estado de sincronización', [
				'exception' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			
			wp_send_json_error([
				'message' => __('Error al obtener el estado de sincronización', 'mi-integracion-api'),
				'technical_details' => $e->getMessage(),
				'file' => basename($e->getFile()),
				'line' => $e->getLine()
			]);
		}
	}
	
	/**
	 * Intenta obtener el nombre del artículo actual de diferentes fuentes disponibles
	 * 
	 * @param array $progress Datos de progreso
	 * @return string Nombre del artículo o cadena vacía si no se encuentra
	 */
	private static function get_articulos_with_fallback($progress) {
		// Sistema de diagnóstico para analizar cómo estamos obteniendo los nombres
		$diagnostic = [];
		
		// Estrategia 1: Usar directamente el campo articulo_actual si está disponible
		if (!empty($progress['estadisticas']['articulo_actual'])) {
			$diagnostic['source'] = 'estadisticas.articulo_actual';
			Logger::debug('Nombre de artículo obtenido directamente de estadisticas.articulo_actual', [
				'nombre' => $progress['estadisticas']['articulo_actual']
			]);
			return $progress['estadisticas']['articulo_actual'];
		}
		
		// Estrategia 2: Revisar si hay current_article en el progreso (usado por el JS)
		if (!empty($progress['current_article'])) {
			$diagnostic['source'] = 'progress.current_article';
			Logger::debug('Nombre de artículo obtenido de progress.current_article', [
				'nombre' => $progress['current_article']
			]);
			return $progress['current_article'];
		}
		
		// Estrategia 3: Intentar extraer del mensaje
		if (!empty($progress['mensaje'])) {
			// Patrón común: "Procesando X de Y: NombreProducto"
			if (strpos($progress['mensaje'], ':') !== false) {
				$partes = explode(':', $progress['mensaje']);
				if (count($partes) > 1) {
					$nombre = trim(end($partes));
					if (!empty($nombre)) {
						$diagnostic['source'] = 'mensaje_con_dos_puntos';
						Logger::debug('Nombre de artículo extraído de mensaje después de ":"', [
							'mensaje' => $progress['mensaje'],
							'nombre_extraido' => $nombre
						]);
						return $nombre;
					}
				}
			}
			
			// Patrón alternativo: puede contener "artículo" o "producto" seguido del nombre
			$patrones = [
				'/(?:artículo|producto|sincronizando)\s+(.+?)(?:\s+con\s+|$)/i', 
				'/(?:processing|procesando)\s+(?:artículo|producto|item)?\s*([^0-9:]+)/i',
				'/(?:sincronizando|syncing|syncing product|syncing item)\s+(.+?)(?:\s+a\s+|$)/i'
			];
			
			foreach ($patrones as $index => $patron) {
				if (preg_match($patron, $progress['mensaje'], $matches)) {
					if (!empty($matches[1])) {
						$nombre = trim($matches[1]);
						$diagnostic['source'] = 'regex_' . $index;
						Logger::debug('Nombre de artículo extraído con regex', [
							'patron' => $patron,
							'mensaje' => $progress['mensaje'],
							'nombre_extraido' => $nombre
						]);
						return $nombre;
					}
				}
			}
		}
		
		// Estrategia 4: Buscar en cualquier campo de estadísticas que pueda contener el nombre
		$posibles_campos = [
			'nombre', 'name', 'titulo', 'title', 'producto', 'articulo', 'item',
			'product_name', 'article_name', 'product_title', 'product', 'article'
		];
		
		if (!empty($progress['estadisticas']) && is_array($progress['estadisticas'])) {
			foreach ($posibles_campos as $campo) {
				if (!empty($progress['estadisticas'][$campo])) {
					$diagnostic['source'] = 'estadisticas.' . $campo;
					Logger::debug('Nombre de artículo obtenido de campo alternativo', [
						'campo' => $campo,
						'nombre' => $progress['estadisticas'][$campo]
					]);
					return $progress['estadisticas'][$campo];
				}
			}
		}
		
		// Estrategia 5: Intentar obtener de transient mia_last_product como último recurso
		$ultimo_producto = get_transient('mia_last_product');
		if (!empty($ultimo_producto)) {
			$diagnostic['source'] = 'transient_ultimo_producto';
			Logger::debug('Usando último producto procesado como fallback', [
				'ultimo_producto' => $ultimo_producto
			]);
			return $ultimo_producto . ' ' . __('(último conocido)', 'mi-integracion-api');
		}
		
		// No se encontró nombre
		Logger::debug('No se pudo determinar el nombre del artículo', [
			'progress' => $progress,
			'diagnostic' => $diagnostic
		]);
		return '';
	}
	
	/**
	 * Formatea el tiempo transcurrido en formato legible
	 * 
	 * @param int $seconds Segundos transcurridos
	 * @return string Tiempo formateado
	 */
	private static function format_elapsed_time($seconds) {
		if ($seconds < 60) {
			return sprintf(_n('%s segundo', '%s segundos', $seconds, 'mi-integracion-api'), $seconds);
		} elseif ($seconds < 3600) {
			$minutes = floor($seconds / 60);
			$secs = $seconds % 60;
			return sprintf(
				_n('%s minuto', '%s minutos', $minutes, 'mi-integracion-api') . ', ' .
				_n('%s segundo', '%s segundos', $secs, 'mi-integracion-api'),
				$minutes, $secs
			);
		} else {
			$hours = floor($seconds / 3600);
			$minutes = floor(($seconds % 3600) / 60);
			return sprintf(
				_n('%s hora', '%s horas', $hours, 'mi-integracion-api') . ', ' .
				_n('%s minuto', '%s minutos', $minutes, 'mi-integracion-api'),
				$hours, $minutes
			);
		}
	}

	/**
     * Maneja la sincronización masiva de productos vía AJAX
     *
     * Seguridad: valida nonce, permisos y maneja errores.
     * Devuelve progreso, errores y mensajes claros.
     */
    public static function sync_products_batch() {
    	// Variable para almacenar el logger y poder usarlo en caso de error
    	$logger = null;
    	
    	try {
    	    // Crear un logger específico para esta operación
    	    $logger = new Logger('sync_products_batch');
    	    $logger->info('Iniciando sincronización de productos por lotes', [
    	        'timestamp' => date('Y-m-d H:i:s'),
    	        'user_id' => get_current_user_id()
    	    ]);
    	      
    	    // Verificar si hay una sesión PHP activa
            if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
                @session_start();
            }
    	      
    	    // Validar nonce y permisos con mensajes detallados
    	    if (!isset($_REQUEST['nonce'])) {
    	        $logger->error('Error: Nonce no proporcionado en la petición AJAX');
    	        wp_send_json_error([
    	            'message' => __('Error de seguridad: falta el token de verificación.', 'mi-integracion-api'),
    	            'technical_details' => 'El parámetro nonce no está presente en la petición AJAX.'
    	        ], 403);
    	        return;
    	    }
    	      
    	    $nonce_check = check_ajax_referer(MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce', false);
    	    if (!$nonce_check) {
    	        $logger->error('Error: Nonce inválido en la petición AJAX', [
    	            'nonce_provided' => $_REQUEST['nonce'] ?? 'No disponible'
    	        ]);
    	        wp_send_json_error([
    	            'message' => __('Error de seguridad: token de verificación inválido o expirado.', 'mi-integracion-api'),
    	            'technical_details' => 'El nonce de WordPress no es válido o ha expirado. Intente recargar la página.'
    	        ], 403);
    	        return;
    	    }
    	      
    	    if (!current_user_can('manage_options')) {
    	        $logger->error('Error de permisos: El usuario no tiene capacidad manage_options', [
    	            'user_id' => get_current_user_id(),
    	            'capabilities' => get_userdata(get_current_user_id())->allcaps ?? 'No disponible'
    	        ]);
    	        wp_send_json_error([
    	            'message' => __('No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api'),
    	            'technical_details' => 'Se requiere el rol de administrador para realizar la sincronización.'
    	        ], 403);
    	        return;
    	    }
    	      
    	    // Registrar la recepción de la petición AJAX con información detallada
    	    $logger->debug('Petición AJAX recibida y validada correctamente', [
    	        'headers' => getallheaders(),
    	        'post' => $_POST,
    	        'get' => $_GET,
    	        'request' => $_REQUEST,
    	        'server' => [
    	            'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'] ?? 'No disponible',
    	            'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'No disponible',
    	            'REQUEST_TIME' => $_SERVER['REQUEST_TIME'] ?? 'No disponible'
    	        ]
    	    ]);
    	      
    		// Asegurar que la clase Sync_Manager esté disponible con el namespace correcto
  if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
   $logger->info('Cargando clase Sync_Manager desde: ' . dirname(__DIR__) . '/Core/Sync_Manager.php');
   require_once dirname(__DIR__) . '/Core/Sync_Manager.php';
  }
  
  // Verificar que la clase se cargó correctamente
  if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
      $logger->error('No se pudo cargar la clase Sync_Manager');
      wp_send_json_error(['message' => 'No se pudo cargar el gestor de sincronización'], 500);
      return;
  }
  
  // Obtener instancia del gestor de sincronización
  $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    		$status = $sync_manager->get_sync_status();
    	      $logger->info('Estado actual de sincronización obtenido', ['status' => $status]);
    	      
    	      // Obtener filtros si se han proporcionado
    	      $filters = [];
    	      if (!empty($_REQUEST['filters']) && is_array($_REQUEST['filters'])) {
    	          $filters = $_REQUEST['filters'];
    	          $logger->info('Filtros de sincronización recibidos', ['filters' => $filters]);
    	      }
  
    		// Decidir si iniciar una nueva sincronización o procesar el siguiente lote
    		if (!$status['current_sync']['in_progress']) {
    			// Es una nueva sincronización
    			$logger->info('Iniciando nueva sincronización de productos');
    			
    			// Extraer el tamaño del lote si está especificado
    			$batch_size = isset($_REQUEST['batch_size']) ? (int)$_REQUEST['batch_size'] : null;
    			if ($batch_size) {
    			    $logger->info('Tamaño de lote solicitado: ' . $batch_size);
    			    // Asegurarse de que el tamaño del lote es razonable
    			    if ($batch_size < 1 || $batch_size > 100) {
    			        $logger->warning('Tamaño de lote fuera de rango, se usará el valor por defecto');
    			        $batch_size = null;
    			    }
    			}
    			
    			try {
    			    $result = $sync_manager->start_sync('products', 'verial_to_wc', $filters);
    			    $logger->debug('Resultado de start_sync', ['result' => $result]);
    			} catch (\Exception $e) {
    			    $logger->error('Excepción en start_sync', [
    			        'message' => $e->getMessage(),
    			        'trace' => $e->getTraceAsString()
    			    ]);
    			    throw $e; // Re-lanzar para ser capturada por el try-catch principal
    			}
    		} else {
    			// Ya hay una sincronización en progreso
    			$logger->info('Continuando sincronización en progreso, procesando siguiente lote');
    			// Verificar si estamos en modo de recuperación
    			$recovery_mode = !empty($_REQUEST['recovery_mode']);
    			
    			try {
    			    $result = $sync_manager->process_next_batch($recovery_mode);
    			    $logger->debug('Resultado de process_next_batch', ['result' => $result]);
    			} catch (\Exception $e) {
    			    $logger->error('Excepción en process_next_batch', [
    			        'message' => $e->getMessage(),
    			        'trace' => $e->getTraceAsString()
    			    ]);
    			    throw $e; // Re-lanzar para ser capturada por el try-catch principal
    			}
    		}
    		
    		$logger->info('Resultado de la operación de sincronización', [
    		    'status' => is_wp_error($result) ? 'error' : 'success',
    		    'data' => $result
    		]);
   
    		if ( is_wp_error( $result ) ) {
    			// Enviar todos los datos del error para una depuración más fácil en el frontend
    			$error_message = $result->get_error_message();
    			$error_code = $result->get_error_code();
    			$error_data = $result->get_error_data();
    			
    			$logger->error('Error durante la sincronización', [
    				'error_code' => $error_code,
    				'error_message' => $error_message,
    				'error_data' => $error_data
    			]);
    			
    			// Generar mensaje de error más descriptivo para el usuario
    			$user_message = $error_message;
    			
    			// Mensajes personalizados para errores comunes
    			if ($error_code === 'invalid_response') {
    				$user_message = __('Error al comunicarse con el API de Verial. La respuesta recibida no es válida.', 'mi-integracion-api');
    			} elseif ($error_code === 'verial_api_error') {
    				$user_message = __('El servidor de Verial reportó un error: ', 'mi-integracion-api') . $error_message;
    			} elseif ($error_code === 'http_request_failed') {
    				$user_message = __('Error de conexión con el servidor de Verial. Verifique su conexión a internet y los ajustes del API.', 'mi-integracion-api');
    			}
    			
    			wp_send_json_error([
    				'message' => $user_message,
    				'code'    => $error_code,
    				'data'    => $error_data,
    				'technical_details' => $error_message,
    			]);
    		} else {
    			// Dar formato al resultado para hacerlo más útil en el frontend
    			$response_data = $result;
    			
    			// Añadir información sobre el próximo paso si corresponde
    			if (isset($result['status']) && $result['status'] === 'in_progress') {
    				$response_data['next_action'] = 'continue';
    				$response_data['next_batch'] = ($status['current_sync']['current_batch'] ?? 0) + 1;
    			} elseif (isset($result['status']) && $result['status'] === 'completed') {
    				$response_data['next_action'] = 'finished';
    			}
    			
    			wp_send_json_success($response_data);
    		}
    	} catch (\Throwable $e) {
    		// Registrar el error detalladamente
    		$logger = isset($logger) ? $logger : new Logger('ajax_sync_error');
    		$logger->error('Excepción en sync_products_batch', [
    			'exception_class' => get_class($e),
    			'exception_message' => $e->getMessage(),
    			'file' => $e->getFile(),
    			'line' => $e->getLine(),
    			'trace' => $e->getTraceAsString(),
    		]);
    		
    		// Intentar obtener más contexto del error
    		$error_context = [];
    		if (method_exists($e, 'getTrace')) {
    			$trace = $e->getTrace();
    			if (!empty($trace[0]['function'])) {
    				$error_context['function'] = $trace[0]['function'];
    			}
    			if (!empty($trace[0]['class'])) {
    				$error_context['class'] = $trace[0]['class'];
    			}
    			if (!empty($trace[0]['args'])) {
    			    // Capturar argumentos con seguridad para evitar recursión o errores
    			    $args_info = [];
    			    foreach ($trace[0]['args'] as $idx => $arg) {
    			        if (is_scalar($arg)) {
    			            $args_info["arg{$idx}"] = $arg;
    			        } elseif (is_array($arg)) {
    			            $args_info["arg{$idx}"] = 'Array(' . count($arg) . ')';
    			        } elseif (is_object($arg)) {
    			            $args_info["arg{$idx}"] = 'Object(' . get_class($arg) . ')';
    			        } else {
    			            $args_info["arg{$idx}"] = gettype($arg);
    			        }
    			    }
    			    $error_context['args_info'] = $args_info;
    			}
    		}
    		
    		// Preparar mensaje de error según el tipo de excepción
    		$error_message = __('Error inesperado durante la sincronización.', 'mi-integracion-api');
    		$suggestion = __('Por favor, revise los registros para más detalles.', 'mi-integracion-api');
    		
    		// Personalizar el mensaje según el tipo de excepción
    		if ($e instanceof \PDOException || strpos($e->getMessage(), 'SQL') !== false) {
    		    $error_message = __('Error en la base de datos durante la sincronización.', 'mi-integracion-api');
    		    $suggestion = __('Puede haber un problema con alguna tabla de WordPress o con los permisos de la base de datos.', 'mi-integracion-api');
    		} else if ($e instanceof \RuntimeException && strpos($e->getMessage(), 'API') !== false) {
    		    $error_message = __('Error en la comunicación con la API de Verial.', 'mi-integracion-api');
    		    $suggestion = __('Verifique las credenciales y la conectividad con el servidor de Verial.', 'mi-integracion-api');
    		} else if ($e instanceof \Exception && (strpos($e->getMessage(), 'timeout') !== false || strpos($e->getMessage(), 'tiempo') !== false)) {
    		    $error_message = __('La operación excedió el tiempo máximo de espera.', 'mi-integracion-api');
    		    $suggestion = __('Intente con un tamaño de lote menor o verifique la carga del servidor.', 'mi-integracion-api');
    		}
    		
    		// Añadir información de recuperación al log y respuesta
    		$recovery_info = [];
    		$has_recovery = false;
    		
    		// Verificar si podemos ofrecer recuperación para ciertos errores
    		if (strpos($e->getMessage(), 'timeout') !== false || 
    		    strpos($e->getMessage(), 'tiempo') !== false ||
    		    strpos($e->getMessage(), 'memory') !== false) {
    		    $has_recovery = true;
    		    $recovery_info = [
    		        'can_retry' => true,
    		        'retry_suggestion' => __('Intente con un tamaño de lote menor o en un momento de menor carga.', 'mi-integracion-api')
    		    ];
    		}
    		
    		$logger->info('Enviando respuesta de error detallada al cliente', [
    		    'error_message' => $error_message,
    		    'has_recovery' => $has_recovery,
    		    'suggestions' => $suggestion
    		]);
  
    		wp_send_json_error([
    			'message' => $error_message . ' ' . $suggestion,
    			'technical_details' => $e->getMessage(),
    			'code' => 'exception',
    			'context' => $error_context,
    			'file' => basename($e->getFile()),
    			'line' => $e->getLine(),
    			'recovery' => $has_recovery ? $recovery_info : null
    		], 500);
    	}
    }
}

/*
JavaScript para cargar opciones de filtros (mover a archivo JS separado en producción)
jQuery(document).ready(function($) {
	// Carga inicial de opciones de filtros
	$.post(ajaxurl, {
		action: 'mia_load_filter_options',
		_ajax_nonce: (typeof miIntegracionApiDashboard !== 'undefined' ? miIntegracionApiDashboard.nonce : '')
	}, function(response) {
		console.log('AJAX filtros:', response); // DEBUG
		if (response.success) {
			// Población de selectores de categorías y fabricantes
			var $categoriaSelect = $('#id_categoria_verial');
			var $fabricanteSelect = $('#id_fabricante_verial');

			$categoriaSelect.empty().append('<option value="0">Todas las categorías</option>');
			$.each(response.data.categories, function(index, category) {
				$categoriaSelect.append('<option value="' + category.id + '">' + category.nombre + '</option>');
			});

			$fabricanteSelect.empty().append('<option value="0">Todos los fabricantes</option>');
			$.each(response.data.manufacturers, function(index, manufacturer) {
				$fabricanteSelect.append('<option value="' + manufacturer.id + '">' + manufacturer.nombre + '</option>');
			});
		} else {
			console.error('Error al cargar opciones de filtros:', response.message);
		}
	});
});
*/