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
                'wp_ajax_mi_integracion_api_sync_clients_job_batch',
                'wp_ajax_mi_integracion_api_save_batch_size',
                'wp_ajax_mi_integracion_api_diagnose_range',
                'wp_ajax_mi_integracion_api_get_skipped_ranges_stats',
                'wp_ajax_mi_integracion_api_get_sync_recommendations',
                'wp_ajax_mi_refresh_nonce',
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
		add_action( 'wp_ajax_mi_single_product', [self::class, 'sync_single_product'] ); // Añadir compatibilidad con nombre corto
		add_action( 'wp_ajax_mi_sync_get_categorias', [self::class, 'get_categorias'] );
		add_action( 'wp_ajax_mi_get_categorias', [self::class, 'get_categorias'] ); // Añadir compatibilidad con ambas acciones
		add_action( 'wp_ajax_mi_sync_get_fabricantes', [self::class, 'get_fabricantes'] );
		add_action( 'wp_ajax_mi_get_fabricantes', [self::class, 'get_fabricantes'] ); // Añadir compatibilidad con ambas acciones
		add_action( 'wp_ajax_mi_sync_autocomplete_sku', [self::class, 'autocomplete_sku'] );
		add_action( 'wp_ajax_mi_search_product', [self::class, 'search_product'] );
		add_action( 'wp_ajax_mi_integracion_api_sync_clients_job_batch', [self::class, 'sync_clients_job_batch'] );
		add_action( 'wp_ajax_mi_integracion_api_save_batch_size', [self::class, 'save_batch_size'] );
		add_action( 'wp_ajax_mi_integracion_api_diagnose_range', [self::class, 'diagnose_range'] );
		add_action( 'wp_ajax_mi_integracion_api_get_skipped_ranges_stats', [self::class, 'get_skipped_ranges_stats'] );
		add_action( 'wp_ajax_mi_integracion_api_get_sync_recommendations', [self::class, 'get_sync_recommendations'] );
		add_action( 'wp_ajax_mi_integracion_api_save_batch_size', [self::class, 'save_batch_size'] );
		add_action( 'wp_ajax_mi_refresh_nonce', [self::class, 'refresh_nonce'] ); // Endpoint para refrescar nonce
		add_action( 'wp_ajax_mi_unlock_sync', [self::class, 'unlock_sync'] ); // Endpoint para desbloquear sincronización
		add_action( 'wp_ajax_mi_force_unlock_sync', [self::class, 'force_unlock_sync'] ); // Endpoint para forzar liberación de bloqueos
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
		$logger = new Logger('sync-progress');
		$logger->info(
			sprintf('Progreso sincronización: %s%% - %s - Transcurrido: %s - Est. restante: %s - Artículo: %s', 
				$porcentaje, 
				$mensaje,
				$tiempo_formateado,
				$tiempo_estimado ?: 'N/A',
				$estadisticas['articulo_actual'] ?: 'No especificado'
			),
			['category' => 'sync-progress']
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
		// Preparar el conector de API
		if ( ! class_exists( 'MiIntegracionApi\\Core\\ApiConnector' ) ) {
			require_once dirname( __DIR__ ) . '/Core/ApiConnector.php';
		}
		
		$connector = new \MiIntegracionApi\Core\ApiConnector();
		$categories = array();
		$manufacturers = array();
		
		// Obtener categorías usando ApiConnector
		try {
			$this->logger->debug('Intentando obtener categorías desde ApiConnector');
			$categories_response = $connector->get_categorias();
			$this->logger->debug('Respuesta de categorías: ' . print_r($categories_response, true));
			
			if ( is_array( $categories_response ) && isset( $categories_response['data'] ) ) {
				$categories_data = $categories_response['data'];
				if ( is_array( $categories_data ) ) {
					foreach ( $categories_data as $cat ) {
						$categories[] = array(
							'id'     => $cat['IdCategoria'] ?? $cat['id'] ?? $cat['id_categoria'] ?? '',
							'nombre' => $cat['Nombre'] ?? $cat['nombre'] ?? '',
						);
					}
					$this->logger->info('Se obtuvieron ' . count($categories) . ' categorías exitosamente');
				}
			}
		} catch ( Exception $e ) {
			$this->logger->error( 'Error al obtener categorías: ' . $e->getMessage() );
			// Usar datos de emergencia para categorías
			$categories = array(
				array( 'id' => 'general', 'nombre' => 'General' ),
				array( 'id' => 'otros', 'nombre' => 'Otros' ),
			);
			$this->logger->warning( 'Usando datos de emergencia para categorías debido a error' );
		}
		
		// Obtener fabricantes usando ApiConnector
		try {
			$this->logger->debug('Intentando obtener fabricantes desde ApiConnector');
			$manufacturers_response = $connector->get_fabricantes();
			$this->logger->debug('Respuesta de fabricantes: ' . print_r($manufacturers_response, true));
			
			if ( is_array( $manufacturers_response ) && isset( $manufacturers_response['data'] ) ) {
				$manufacturers_data = $manufacturers_response['data'];
				if ( is_array( $manufacturers_data ) ) {
					foreach ( $manufacturers_data as $fab ) {
						$manufacturers[] = array(
							'id'     => $fab['id'] ?? $fab['Id'] ?? $fab['Codigo'] ?? '',
							'nombre' => $fab['nombre'] ?? $fab['Nombre'] ?? '',
						);
					}
					$this->logger->info('Se obtuvieron ' . count($manufacturers) . ' fabricantes exitosamente');
				}
			}
		} catch ( Exception $e ) {
			$this->logger->error( 'Error al obtener fabricantes: ' . $e->getMessage() );
			// Usar datos de emergencia para fabricantes
			$manufacturers = array(
				array( 'id' => 'generico', 'nombre' => 'Genérico' ),
				array( 'id' => 'sin_fabricante', 'nombre' => 'Sin Fabricante' ),
			);
			$this->logger->warning( 'Usando datos de emergencia para fabricantes debido a error' );
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
		// Iniciar logger para diagnóstico de AJAX
		$logger = new \MiIntegracionApi\Helpers\Logger('ajax-single-product');
		$logger->info('Iniciando sincronización de producto individual', [
			'request' => $_REQUEST,
			'server' => [
				'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'Unknown',
				'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? 'No disponible',
				'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
			]
		]);
		
		// Verificar nonce con múltiples posibles nombres y acciones para mayor compatibilidad
		$nonce_valid = false;
		$nonce_tried = [];
		
		// Posibles nombres de campos para nonce
		$nonce_fields = ['_ajax_nonce', 'nonce', '_wpnonce'];
		
		// Posibles acciones para el nonce
		$nonce_actions = [
			'mi_sync_single_product',
			'mi_integracion_api_sync_single',
			'wp_ajax_mi_sync_single_product'
		];
		
		// Probar todas las combinaciones posibles
		foreach ($nonce_fields as $field) {
			if (!isset($_REQUEST[$field])) {
				continue;
			}
			
			$nonce_value = $_REQUEST[$field];
			$nonce_tried[$field] = substr($nonce_value, 0, 5) . '...';
			
			foreach ($nonce_actions as $action) {
				$valid = wp_verify_nonce($nonce_value, $action);
				if ($valid) {
					$nonce_valid = true;
					$logger->info("Nonce válido encontrado", [
						'campo' => $field,
						'accion' => $action
					]);
					break 2;
				}
			}
		}
		
		if (!$nonce_valid) {
			$logger->error('Verificación de nonce fallida', [
				'nonce_intentados' => $nonce_tried,
				'request' => $_REQUEST
			]);
			wp_send_json_error([
				'message' => __('Error de seguridad: verificación de nonce fallida. Por favor, recarga la página e intenta nuevamente.', 'mi-integracion-api'),
				'debug' => [
					'nonce_tried' => $nonce_tried,
					'time' => time()
				]
			], 400);
			return;
		}
		
		if (!current_user_can('manage_options')) {
			$logger->error('Usuario sin permisos intenta sincronizar producto', [
				'user_id' => get_current_user_id(),
				'caps' => get_userdata(get_current_user_id())->allcaps ?? ['not_available']
			]);
			wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mi-integracion-api')], 403);
			return;
		}
		
		// Obtener y validar parámetros de filtro
		$sku = isset($_REQUEST['sku']) ? sanitize_text_field($_REQUEST['sku']) : '';
		$nombre = isset($_REQUEST['nombre']) ? sanitize_text_field($_REQUEST['nombre']) : '';
		$categoria = isset($_REQUEST['categoria']) ? sanitize_text_field($_REQUEST['categoria']) : '';
		$fabricante = isset($_REQUEST['fabricante']) ? sanitize_text_field($_REQUEST['fabricante']) : '';
		
		$logger->info('Parámetros de sincronización', [
			'sku' => $sku,
			'nombre' => $nombre,
			'categoria' => $categoria,
			'fabricante' => $fabricante
		]);
		
		if (empty($sku) && empty($nombre) && empty($categoria) && empty($fabricante)) {
			$logger->warning('Intento de sincronización sin filtros');
			wp_send_json_error(['message' => __('Debes indicar al menos un filtro.', 'mi-integracion-api')], 400);
			return;
		}
		
		// Cargar las clases necesarias
		if (!class_exists('\MiIntegracionApi\Core\ApiConnector')) {
			require_once dirname(__DIR__) . '/Core/ApiConnector.php';
		}
		if (!class_exists('\MiIntegracionApi\Sync\SyncSingleProduct')) {
			require_once dirname(__DIR__) . '/Sync/SyncSingleProduct.php';
		}
		
		try {
			$logger->info('Iniciando sincronización con ApiConnector');
			$api_connector = new \MiIntegracionApi\Core\ApiConnector();
			
			// Ejecutar sincronización con tiempo límite ampliado
			$timeout_original = ini_get('max_execution_time');
			set_time_limit(120); // 2 minutos para la sincronización
			
			$logger->info('Llamando a SyncSingleProduct::sync');
			$resultado = \MiIntegracionApi\Sync\SyncSingleProduct::sync(
				$api_connector, 
				$sku, 
				$nombre, 
				$categoria, 
				$fabricante
			);
			
			// Restaurar timeout original
			if ($timeout_original) {
				set_time_limit((int)$timeout_original);
			}
			
			$logger->debug('Resultado de sincronización: ', [
				'resultado' => $resultado
			]);
			
			if (is_array($resultado) && !empty($resultado['success'])) {
				$message = $resultado['message'] ?? __('Producto sincronizado correctamente.', 'mi-integracion-api');
				$logger->info('Sincronización exitosa: ' . $message);
				wp_send_json_success(['message' => $message]);
			} else {
				$error_message = '';
				
				// Intentar extraer mensaje de error
				if (is_array($resultado) && isset($resultado['message'])) {
					$error_message = $resultado['message'];
				} elseif (is_array($resultado) && isset($resultado['error'])) {
					$error_message = $resultado['error'];
				} elseif (is_wp_error($resultado)) {
					$error_message = $resultado->get_error_message();
				} else {
					$error_message = __('Error al sincronizar el producto. No se recibió respuesta válida.', 'mi-integracion-api');
				}
				
				$logger->warning('Error en sincronización: ' . $error_message, [
					'resultado' => $resultado
				]);
				
				wp_send_json_error(['message' => $error_message]);
			}
		} catch (\Exception $e) {
			$logger->error('Excepción en sincronización: ' . $e->getMessage(), [
				'exception' => [
					'message' => $e->getMessage(),
					'file' => $e->getFile(),
					'line' => $e->getLine(),
					'trace' => $e->getTraceAsString()
				]
			]);
			
			wp_send_json_error([
				'message' => __('Error en la sincronización: ', 'mi-integracion-api') . $e->getMessage(),
				'technical_details' => $e->getFile() . ':' . $e->getLine()
			]);
		}
	}

	/**
	 * Devuelve las categorías de Verial para el formulario (AJAX)
	 */
	public static function get_categorias() {
        // Iniciar logger para diagnóstico
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-debug');
        $logger->info('Iniciando get_categorias', [
            'request' => $_REQUEST,
            'server' => [
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
                'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? 'No disponible',
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'],
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'],
            ]
        ]);
        
        // Verificar nonce con múltiples posibles acciones para mayor compatibilidad
        $nonce_valid = false;
        
        if (isset($_REQUEST['_ajax_nonce'])) {
            $nonce_actions = [
                'mi_sync_single_product',      // Acción original
                'mi_sync_get_categorias',      // Acción enviada por el JS
                'mi_get_categorias',           // Acción simplificada
                'mi_single_product'            // Acción simplificada para sync
            ];
            
            foreach ($nonce_actions as $action) {
                $valid = wp_verify_nonce($_REQUEST['_ajax_nonce'], $action);
                $logger->debug('Verificando nonce', [
                    'action' => $action,
                    'nonce' => substr($_REQUEST['_ajax_nonce'], 0, 4) . '...',
                    'valid' => $valid ? 'SÍ' : 'NO'
                ]);
                if ($valid) {
                    $nonce_valid = true;
                    break;
                }
            }
        } else {
            $logger->error('No se recibió nonce en la petición');
        }
        
        if (!$nonce_valid) {
            $logger->error('Nonce inválido en get_categorias', [
                'request' => $_REQUEST,
                'nonce_recibido' => $_REQUEST['_ajax_nonce'] ?? 'No proporcionado'
            ]);
            wp_send_json_error(array('message' => __('Verificación de seguridad fallida. Recarga la página e intenta nuevamente.', 'mi-integracion-api')));
            return;
        }
        
        $logger->info('Nonce validado correctamente');
        
        if (!current_user_can('manage_options')) {
            $logger->error('Permiso denegado en get_categorias', [
                'user_id' => get_current_user_id(),
                'capabilities' => get_userdata(get_current_user_id())->allcaps
            ]);
            wp_send_json_error(array('message' => __('No tienes permisos suficientes.', 'mi-integracion-api')));
            return;
        }
        $logger->info('Permisos validados correctamente');
        if (!class_exists('\MiIntegracionApi\Core\ApiConnector')) {
            $logger->info('Cargando clase ApiConnector');
            require_once dirname(__DIR__) . '/Core/ApiConnector.php';
        }
        
        try {
            $api_connector = new \MiIntegracionApi\Core\ApiConnector();
            $logger->info('Instancia de ApiConnector creada correctamente');
            
            $logger->info('Obteniendo categorías...');
            $categorias = $api_connector->get_categorias();
            
            $logger->debug('Respuesta cruda de get_categorias', [
                'tipo' => gettype($categorias),
                'es_wp_error' => is_wp_error($categorias) ? 'SÍ' : 'NO',
                'es_array' => is_array($categorias) ? 'SÍ' : 'NO',
                'array_vacio' => (is_array($categorias) && empty($categorias)) ? 'SÍ' : 'NO',
                'keys' => is_array($categorias) ? array_keys($categorias) : 'NO DISPONIBLE',
                'contenido_muestra' => is_array($categorias) ? json_encode(array_slice($categorias, 0, 3, true)) : 'NO ES ARRAY',
                'contenido' => $categorias
            ]);
            
            if (is_wp_error($categorias)) {
                $logger->error('Error al obtener categorías', [
                    'message' => $categorias->get_error_message(),
                    'code' => $categorias->get_error_code(),
                    'data' => $categorias->get_error_data()
                ]);
                wp_send_json_error(['message' => $categorias->get_error_message()]);
                return;
            }
            
            $categorias_list = [];
            
            // Nueva forma más robusta de procesar las categorías
            // 1. Verificar si son un array directo de categorías normalizadas (nuevo formato de ApiConnector)
            if (is_array($categorias) && !isset($categorias['Categorias']) && !isset($categorias['CategoriasWeb'])) {
                $logger->info('Procesando lista de categorías normalizadas', ['count' => count($categorias)]);
                
                foreach ($categorias as $i => $cat) {
                    $id = $cat['id'] ?? '';
                    $nombre = $cat['nombre'] ?? '';
                    
                    if (!empty($id) && !empty($nombre)) {
                        $categorias_list[$id] = $nombre; // Formato para selects: id => nombre
                        
                        if ($i < 3 || $i > count($categorias) - 4) {
                            $logger->debug('Categoría normalizada procesada', [
                                'indice' => $i, 
                                'id' => $id, 
                                'nombre' => $nombre
                            ]);
                        }
                    }
                }
            }
            // 2. Verificar el formato antiguo (con clave 'Categorias')
            else if (is_array($categorias) && isset($categorias['Categorias']) && is_array($categorias['Categorias'])) {
                $logger->info('Procesando lista de categorías (formato antiguo)', ['count' => count($categorias['Categorias'])]);
                
                foreach ($categorias['Categorias'] as $i => $cat) {
                    $id = $cat['Id'] ?? $cat['id'] ?? '';
                    $nombre = $cat['Nombre'] ?? $cat['nombre'] ?? '';
                    
                    if (!empty($id) && !empty($nombre)) {
                        $categorias_list[$id] = $nombre; // Formato para selects: id => nombre
                        
                        if ($i < 3 || $i > count($categorias['Categorias']) - 4) {
                            $logger->debug('Categoría procesada', [
                                'indice' => $i, 
                                'id' => $id, 
                                'nombre' => $nombre
                            ]);
                        }
                    } else {
                        $logger->warning('Categoría con datos incompletos', [
                            'indice' => $i,
                            'datos' => $cat
                        ]);
                    }
                }
            } else {
                $logger->error('Formato de categorías inesperado', [
                    'es_array' => is_array($categorias) ? 'SÍ' : 'NO',
                    'tiene_categorias' => isset($categorias['Categorias']) ? 'SÍ' : 'NO',
                    'categorias_es_array' => isset($categorias['Categorias']) && is_array($categorias['Categorias']) ? 'SÍ' : 'NO',
                    'muestra' => is_array($categorias) ? array_slice($categorias, 0, 3) : 'No es array'
                ]);
            }
            
            if (empty($categorias_list)) {
                $logger->warning('No se encontraron categorías válidas en la respuesta');
            } else {
                $logger->info('Categorías procesadas correctamente', ['count' => count($categorias_list)]);
            }
            
            // Obtener fabricantes
            $fabricantes_list = [];
            
            if (method_exists($api_connector, 'get_fabricantes')) {
                $logger->info('Obteniendo fabricantes...');
                $fabricantes = $api_connector->get_fabricantes();
                
                $logger->debug('Respuesta cruda de get_fabricantes', [
                    'tipo' => gettype($fabricantes),
                    'es_wp_error' => is_wp_error($fabricantes) ? 'SÍ' : 'NO',
                    'contenido' => $fabricantes
                ]);
                
                if (!is_wp_error($fabricantes) && is_array($fabricantes)) {
                    $logger->info('Procesando lista de fabricantes', ['count' => count($fabricantes)]);
                    
                    foreach ($fabricantes as $i => $fab) {
                        $id = $fab['id'] ?? $fab['Id'] ?? '';
                        $nombre = $fab['nombre'] ?? $fab['Nombre'] ?? '';
                        
                        if (!empty($id) && !empty($nombre)) {
                            $fabricantes_list[$id] = $nombre; // Formato para selects: id => nombre
                            
                            if ($i < 3 || $i > count($fabricantes) - 4) {
                                $logger->debug('Fabricante procesado', [
                                    'indice' => $i, 
                                    'id' => $id, 
                                    'nombre' => $nombre
                                ]);
                            }
                        } else {
                            $logger->warning('Fabricante con datos incompletos', [
                                'indice' => $i,
                                'datos' => $fab
                            ]);
                        }
                    }
                } else {
                    $logger->error('Error al obtener fabricantes o formato incorrecto', [
                        'es_error' => is_wp_error($fabricantes) ? 'SÍ' : 'NO',
                        'es_array' => is_array($fabricantes) ? 'SÍ' : 'NO'
                    ]);
                }
            } else {
                $logger->warning('El método get_fabricantes no existe en ApiConnector');
            }
        } catch (\Exception $e) {
            $logger->error('Excepción al procesar categorías/fabricantes', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error(['message' => 'Error interno: ' . $e->getMessage()]);
            return;
        }
        
        $logger->info('Datos preparados para enviar al frontend', [
            'categorias_count' => count($categorias_list),
            'fabricantes_count' => count($fabricantes_list)
        ]);
        
        // Muestra algunos ejemplos para verificar el formato
        if (!empty($categorias_list)) {
            $logger->debug('Ejemplo de categorías', array_slice($categorias_list, 0, 3, true));
        } else {
            // Si no hay categorías, proporcionar al menos una para pruebas
            $logger->warning('Creando lista de categorías de emergencia para evitar error empty string');
            $categorias_list = [
                '0' => '-- Sin categoría --',
                '1' => 'Categoría general'
            ];
        }
        
        if (!empty($fabricantes_list)) {
            $logger->debug('Ejemplo de fabricantes', array_slice($fabricantes_list, 0, 3, true));
        } else {
            // Si no hay fabricantes, proporcionar al menos uno para pruebas
            $logger->warning('Creando lista de fabricantes de emergencia para evitar error empty string');
            $fabricantes_list = [
                '0' => '-- Sin fabricante --',
                '1' => 'Fabricante general'
            ];
        }
        
        // Asegurarse de que los datos son enviados en formato consistente y no vacío
        // Mantener la compatibilidad con el código que espera 'categories' y 'manufacturers'
        $response_data = [
            'categories'    => !empty($categorias_list) ? $categorias_list : ['0' => 'Sin categorías'],
            'manufacturers' => !empty($fabricantes_list) ? $fabricantes_list : ['0' => 'Sin fabricantes'],
            // Formato alternativo para otras implementaciones
            'fabricantes'   => !empty($fabricantes_list) ? $fabricantes_list : ['0' => 'Sin fabricantes'],
        ];
        
        // Verificar que el formato es correcto para JavaScript (objeto con pares clave-valor)
        // y que no se está enviando un array vacío que causaría el error "empty string"
        $logger->debug('JSON a enviar', [
            'data' => $response_data,
            'categorias_vacio' => empty($response_data['categories']),
            'fabricantes_vacio' => empty($response_data['manufacturers']),
            'json' => json_encode($response_data),
            'json_error' => json_last_error_msg()
        ]);
        
        wp_send_json_success($response_data);
    }

    /**
     * Devuelve los fabricantes de Verial para el formulario (AJAX)
     */
    public static function get_fabricantes() {
        // Iniciar logger para diagnóstico
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-debug');
        $logger->info('Iniciando get_fabricantes', [
            'request' => $_REQUEST,
            'server' => [
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'],
                'HTTP_REFERER' => $_SERVER['HTTP_REFERER'] ?? 'No disponible',
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'],
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT'],
            ]
        ]);
        
        // Verificar nonce con múltiples posibles acciones para mayor compatibilidad
        $nonce_valid = false;
        
        if (isset($_REQUEST['_ajax_nonce'])) {
            $nonce_actions = [
                'mi_sync_single_product',      // Acción original
                'mi_sync_get_fabricantes',     // Acción enviada por el JS
                'mi_get_fabricantes',          // Acción simplificada
                'mi_single_product'            // Acción simplificada para sync
            ];
            
            foreach ($nonce_actions as $action) {
                $valid = wp_verify_nonce($_REQUEST['_ajax_nonce'], $action);
                $logger->debug('Verificando nonce', [
                    'action' => $action,
                    'nonce' => substr($_REQUEST['_ajax_nonce'], 0, 4) . '...',
                    'valid' => $valid ? 'SÍ' : 'NO'
                ]);
                if ($valid) {
                    $nonce_valid = true;
                    break;
                }
            }
        } else {
            $logger->error('No se recibió nonce en la petición');
        }
        
        if (!$nonce_valid) {
            $logger->error('Nonce inválido en get_fabricantes', [
                'request' => $_REQUEST,
                'nonce_recibido' => $_REQUEST['_ajax_nonce'] ?? 'No proporcionado'
            ]);
            wp_send_json_error(array('message' => __('Verificación de seguridad fallida. Recarga la página e intenta nuevamente.', 'mi-integracion-api')));
            return;
        }
        
        $logger->info('Nonce validado correctamente');
        
        if (!current_user_can('manage_options')) {
            $logger->error('Permiso denegado en get_fabricantes', [
                'user_id' => get_current_user_id(),
                'capabilities' => get_userdata(get_current_user_id())->allcaps
            ]);
            wp_send_json_error(array('message' => __('No tienes permisos suficientes.', 'mi-integracion-api')));
            return;
        }
        
        $logger->info('Permisos validados correctamente');
        
        try {
            if (!class_exists('\MiIntegracionApi\Core\ApiConnector')) {
                $logger->info('Cargando clase ApiConnector');
                require_once dirname(__DIR__) . '/Core/ApiConnector.php';
            }
            
            $api_connector = new \MiIntegracionApi\Core\ApiConnector();
            $logger->info('Instancia de ApiConnector creada correctamente');
            
            $logger->info('Obteniendo fabricantes...');
            $fabricantes = $api_connector->get_fabricantes();
            
            $logger->debug('Respuesta cruda de get_fabricantes', [
                'tipo' => gettype($fabricantes),
                'es_wp_error' => is_wp_error($fabricantes) ? 'SÍ' : 'NO',
                'contenido' => $fabricantes
            ]);
            
            if (is_wp_error($fabricantes)) {
                $logger->error('Error al obtener fabricantes', [
                    'message' => $fabricantes->get_error_message(),
                    'code' => $fabricantes->get_error_code(),
                    'data' => $fabricantes->get_error_data()
                ]);
                wp_send_json_error(['message' => $fabricantes->get_error_message()]);
                return;
            }
            
            $fabricantes_list = [];
            
            if (is_array($fabricantes)) {
                $logger->info('Procesando lista de fabricantes', ['count' => count($fabricantes)]);
                
                foreach ($fabricantes as $i => $fab) {
                    $id = $fab['id'] ?? $fab['Id'] ?? '';
                    $nombre = $fab['nombre'] ?? $fab['Nombre'] ?? '';
                    
                    if (!empty($id) && !empty($nombre)) {
                        $fabricantes_list[$id] = $nombre;
                        
                        if ($i < 3 || $i > count($fabricantes) - 4) {
                            $logger->debug('Fabricante procesado', [
                                'indice' => $i, 
                                'id' => $id, 
                                'nombre' => $nombre
                            ]);
                        }
                    } else {
                        $logger->warning('Fabricante con datos incompletos', [
                            'indice' => $i,
                            'datos' => $fab
                        ]);
                    }
                }
            } else {
                $logger->error('Formato de fabricantes inesperado', [
                    'es_array' => is_array($fabricantes) ? 'SÍ' : 'NO',
                    'tipo' => gettype($fabricantes)
                ]);
            }
            
            if (empty($fabricantes_list)) {
                $logger->warning('No se encontraron fabricantes válidos en la respuesta');
                // Crear fabricantes de emergencia para evitar error empty string
                $fabricantes_list = [
                    '0' => '-- Sin fabricante --',
                    '1' => 'Fabricante general'
                ];
            } else {
                $logger->info('Fabricantes procesados correctamente', ['count' => count($fabricantes_list)]);
            }
            
            $logger->debug('Enviando respuesta', [
                'manufacturers_count' => count($fabricantes_list)
            ]);
            
            // Mostrar algunos ejemplos para verificar el formato
            if (!empty($fabricantes_list)) {
                $logger->debug('Ejemplo de fabricantes', array_slice($fabricantes_list, 0, 3, true));
            }
            
            // Asegurarse de que los datos son enviados en formato consistente y no vacío
            $response_data = [
                'manufacturers' => !empty($fabricantes_list) ? $fabricantes_list : ['0' => 'Sin fabricantes']
            ];
            
            // Verificar que el formato es correcto para JavaScript
            $logger->debug('JSON a enviar', [
                'data' => $response_data,
                'fabricantes_vacio' => empty($response_data['manufacturers']),
                'json' => json_encode($response_data),
                'json_error' => json_last_error_msg()
            ]);
            
            // Enviar respuesta estandarizada
            wp_send_json_success($response_data);
            
        } catch (\Exception $e) {
            $logger->error('Excepción al procesar fabricantes', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            wp_send_json_error(['message' => 'Error interno: ' . $e->getMessage()]);
        }
        if ( is_wp_error($fabricantes) ) {
            error_log('[MI] Error al obtener fabricantes: ' . $fabricantes->get_error_message());
            wp_send_json_error( array( 'message' => $fabricantes->get_error_message() ) );
        }
        // Mapeo correcto para el frontend con detección de formato
        $fabricantes_list = [];
        $logger = new \MiIntegracionApi\Helpers\Logger('ajax-debug');
        
        // 1. Verificar si son un array directo de fabricantes normalizados (nuevo formato de ApiConnector)
        if (is_array($fabricantes) && !isset($fabricantes['Fabricantes'])) {
            $logger->info('Procesando lista de fabricantes normalizados', ['count' => count($fabricantes)]);
            
            foreach ($fabricantes as $fab) {
                if (isset($fab['id']) && isset($fab['nombre'])) {
                    $fabricantes_list[$fab['id']] = $fab['nombre'];
                }
            }
        } 
        // 2. Verificar formato antiguo (con clave 'Fabricantes')
        else if (is_array($fabricantes) && isset($fabricantes['Fabricantes']) && is_array($fabricantes['Fabricantes'])) {
            $logger->info('Procesando lista de fabricantes (formato antiguo)', ['count' => count($fabricantes['Fabricantes'])]);
            
            foreach ($fabricantes['Fabricantes'] as $fab) {
                $id = $fab['Id'] ?? '';
                $nombre = $fab['Nombre'] ?? '';
                
                if (!empty($id) && !empty($nombre)) {
                    $fabricantes_list[$id] = $nombre;
                }
            }
        } else {
            $logger->error('Formato de fabricantes inesperado', [
                'es_array' => is_array($fabricantes) ? 'SÍ' : 'NO',
                'tiene_fabricantes' => isset($fabricantes['Fabricantes']) ? 'SÍ' : 'NO',
                'muestra' => is_array($fabricantes) ? json_encode(array_slice($fabricantes, 0, 3)) : 'No es array'
            ]);
        }
        
        if (empty($fabricantes_list)) {
            $logger->warning('No se encontraron fabricantes válidos, creando valores por defecto');
            $fabricantes_list = [
                '0' => '-- Sin fabricante --',
                '1' => 'Fabricante general'
            ];
        }
        
        $logger->info('Fabricantes disponibles para frontend', ['count' => count($fabricantes_list)]);
        wp_send_json_success([
            'manufacturers' => $fabricantes_list,
            'fabricantes' => $fabricantes_list // Proporcionar ambos formatos para mayor compatibilidad
        ]);
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
                // Buscar en Id (numérico)
                if (isset($p['Id']) && stripos((string)$p['Id'], $term) !== false) {
                    $results[] = array(
                        'id' => $p['Id'],
                        'label' => 'ID: ' . $p['Id'] . ' - ' . ($p['Nombre'] ?? ''),
                        'value' => $p['Id'],
                    );
                }
                // Buscar en ReferenciaBarras (código de barras)
                else if (isset($p['ReferenciaBarras']) && stripos($p['ReferenciaBarras'], $term) !== false) {
                    $results[] = array(
                        'id' => $p['ReferenciaBarras'],
                        'label' => 'Ref: ' . $p['ReferenciaBarras'] . ' - ' . ($p['Nombre'] ?? ''),
                        'value' => $p['ReferenciaBarras'],
                    );
                }
                // Buscar en Nombre como alternativa
                else if (isset($p['Nombre']) && stripos($p['Nombre'], $term) !== false) {
                    // Si el producto tiene tanto Id como ReferenciaBarras, priorizamos ReferenciaBarras
                    $id_value = isset($p['ReferenciaBarras']) ? $p['ReferenciaBarras'] : (isset($p['Id']) ? $p['Id'] : '');
                    $results[] = array(
                        'id' => $id_value,
                        'label' => ($p['Nombre'] ?? '') . ' (' . $id_value . ')',
                        'value' => $id_value,
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
			wp_send_json_success(['products' => []]);
			return;
		}
		
		// Cargar dependencias
		if ( ! class_exists( '\MiIntegracionApi\Core\ApiConnector' ) ) {
			require_once dirname(__DIR__) . '/Core/ApiConnector.php';
		}
		
		try {
			$api_connector = new \MiIntegracionApi\Core\ApiConnector();
			$productos = $api_connector->get_articulos([ 'buscar' => $term ]);
			
			if (is_wp_error($productos)) {
				$logger->error('Error en API', [
					'message' => $productos->get_error_message(),
					'code' => $productos->get_error_code()
				]);
				wp_send_json_error(['message' => $productos->get_error_message()]);
				return;
			}
			
			$results = [];
			
			if (is_array($productos) && isset($productos['Articulos']) && is_array($productos['Articulos'])) {
				$logger->info('Productos recibidos', ['count' => count($productos['Articulos'])]);
				
				foreach ($productos['Articulos'] as $p) {
					$add_to_results = false;
					$id_value = '';
					$label = '';
					
					// Buscar según el campo especificado
					if ($field === 'id' && isset($p['Id'])) {
						// Búsqueda por ID (incluir tanto ID como código de barras)
						if (isset($p['Id']) && stripos((string)$p['Id'], $term) !== false) {
							$id_value = $p['Id'];
							$label = 'ID: ' . $p['Id'] . ' - ' . ($p['Nombre'] ?? '');
							$add_to_results = true;
						} 
						// También buscar en ReferenciaBarras
						else if (isset($p['ReferenciaBarras']) && stripos($p['ReferenciaBarras'], $term) !== false) {
							$id_value = $p['ReferenciaBarras'];
							$label = 'Ref: ' . $p['ReferenciaBarras'] . ' - ' . ($p['Nombre'] ?? '');
							$add_to_results = true;
						}
					} 
					else if ($field === 'name' && isset($p['Nombre']) && stripos($p['Nombre'], $term) !== false) {
						// Búsqueda por nombre
						$id_value = isset($p['ReferenciaBarras']) ? $p['ReferenciaBarras'] : (isset($p['Id']) ? $p['Id'] : '');
						$label = ($p['Nombre'] ?? '') . ' (' . $id_value . ')';
						$add_to_results = true;
					}
					
					if ($add_to_results && !empty($id_value)) {
						$results[] = [
							'id' => $id_value,
							'label' => $label,
							'value' => $field === 'name' ? ($p['Nombre'] ?? '') : $id_value,
						];
					}
				}
			} else {
				$logger->warning('Formato de respuesta inesperado o sin productos', [
					'response' => $productos
				]);
			}
			
			$logger->info('Resultados encontrados', ['count' => count($results)]);
			wp_send_json_success(['products' => $results]);
			
		} catch (\Exception $e) {
			$logger->error('Excepción en search_product', [
				'message' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			wp_send_json_error(['message' => 'Error interno: ' . $e->getMessage()]);
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
		$logger = new Logger('sync-article');
		
		// Estrategia 1: Usar directamente el campo articulo_actual si está disponible
		if (!empty($progress['estadisticas']['articulo_actual'])) {
			$diagnostic['source'] = 'estadisticas.articulo_actual';
			$logger->debug('Nombre de artículo obtenido directamente de estadisticas.articulo_actual', [
				'nombre' => $progress['estadisticas']['articulo_actual']
			]);
			return $progress['estadisticas']['articulo_actual'];
		}
		
		// Estrategia 2: Revisar si hay current_article en el progreso (usado por el JS)
		if (!empty($progress['current_article'])) {
			$diagnostic['source'] = 'progress.current_article';
			$logger->debug('Nombre de artículo obtenido de progress.current_article', [
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
						$logger->debug('Nombre de artículo extraído de mensaje después de ":"', [
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
						$logger->debug('Nombre de artículo extraído con regex', [
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
					$logger->debug('Nombre de artículo obtenido de campo alternativo', [
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
			$logger->debug('Usando último producto procesado como fallback', [
				'ultimo_producto' => $ultimo_producto
			]);
			return $ultimo_producto . ' ' . __('(último conocido)', 'mi-integracion-api');
		}
		
		// No se encontró nombre
		$logger->debug('No se pudo determinar el nombre del artículo', [
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
    	// Validar nonce y permisos
    	check_ajax_referer( MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce' );
    	if ( ! current_user_can( 'manage_options' ) ) {
    		wp_send_json_error( [ 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ], 403 );
    	}
  
    	try {
    	      // Crear un logger específico para esta operación
    	      $logger = new Logger('sync_products_batch');
    	      $logger->info('Iniciando sincronización de productos por lotes', [
    	          'request' => $_REQUEST,
    	          'user_id' => get_current_user_id()
    	      ]);
    	      
    		// Asegurar que la clase Sync_Manager esté disponible con el namespace correcto
  if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
   $logger->info('Cargando clase Sync_Manager desde: ' . dirname(__DIR__) . '/Core/Sync_Manager.php');
   require_once dirname(__DIR__) . '/Core/Sync_Manager.php';
  }
  
  // Obtener instancia del gestor de sincronización
  $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    		$status = $sync_manager->get_sync_status();
    	      $logger->info('Estado actual de sincronización obtenido', ['status' => $status]);
    	      
    	      // Obtener filtros si se han proporcionado
    	      $filters = [];
    	      if (!empty($_REQUEST['filters']) && is_array($_REQUEST['filters'])) {
    	          $logger->info('Filtros de sincronización recibidos', ['filters' => $filters]);
    	      }
    	    	      // Capturar y validar el tamaño de lote seleccionado por el usuario
	      $batch_size = isset($_REQUEST['batch_size']) ? intval($_REQUEST['batch_size']) : 20; // Valor por defecto unificado
	      
	      // Asegurar que el tamaño de lote esté en un rango razonable
	      $batch_size = max(5, min($batch_size, 100));
	      $logger->info('Tamaño de lote validado: ' . $batch_size, [
	          'raw_value' => $_REQUEST['batch_size'] ?? 'no_provided',
	          'final_value' => $batch_size
	      ]);
	      
	      // UNIFICACIÓN: Usar una sola opción para el tamaño de lote
	      // Eliminar configuraciones redundantes y usar solo 'mi_integracion_api_batch_size'
	      update_option('mi_integracion_api_batch_size', $batch_size);
	      
	      // Para compatibilidad temporal, también actualizar la opción legacy
	      // TODO: Remover esto en versiones futuras una vez que todo el código use la nueva opción
	      update_option('mi_integracion_api_optimal_batch_size', $batch_size);
  
    		// Decidir si iniciar una nueva sincronización o procesar el siguiente lote
    		try {
    			if (!$status['current_sync']['in_progress']) {
    				// Es una nueva sincronización
    				$logger->info('Iniciando nueva sincronización de productos');
    				$result = $sync_manager->start_sync('products', 'verial_to_wc', $filters);
    			} else {
    				// Ya hay una sincronización en progreso
    				$logger->info('Continuando sincronización en progreso, procesando siguiente lote');
    				// Verificar si estamos en modo de recuperación
    				$recovery_mode = !empty($_REQUEST['recovery_mode']);
    				
    				// SOLUCIÓN RADICAL: Interceptar posibles errores de WP_Error como array
    				try {					// Añadimos información detallada antes de procesar
					$logger->info('Procesando siguiente lote', [
						'memory_before' => memory_get_usage(true),
						'time_before' => microtime(true),
						'recovery_mode' => $recovery_mode,
						'batch_size_from_request' => $batch_size,
						'batch_size_stored' => get_option('mi_integracion_api_batch_size', 'not_set'),
						'batch_size_legacy' => get_option('mi_integracion_api_optimal_batch_size', 'not_set')
					]);
    					
    					$result = $sync_manager->process_next_batch($recovery_mode);
    					
    					// Verificar explícitamente si el resultado es un WP_Error
    					if (is_wp_error($result)) {
    						$logger->warning('process_next_batch devolvió WP_Error', [
    							'error_code' => $result->get_error_code(),
    							'error_message' => $result->get_error_message(),
    							'error_data' => $result->get_error_data()
    						]);
    					} else {
    						$logger->info('Lote procesado correctamente', [
    							'memory_after' => memory_get_usage(true),
    							'time_after' => microtime(true),
    							'result_type' => gettype($result)
    						]);
    					}
    				} catch (\Error $e) {
    					// Si el error es "Cannot use object of type WP_Error as array"
    					if (strpos($e->getMessage(), 'Cannot use object of type WP_Error as array') !== false) {
    						$logger->error('Interceptado error crítico de WP_Error', [
    							'error' => $e->getMessage(),
    							'line' => $e->getLine(),
    							'file' => $e->getFile()
    						]);
    						
    						// Crear un WP_Error para manejar correctamente el error
    						$result = new \WP_Error(
    							'api_error_intercepted',
    							__('Se ha interceptado un error en la comunicación con Verial. Intente de nuevo con un tamaño de lote menor.', 'mi-integracion-api'),
    							[
    								'original_error' => $e->getMessage(),
    								'line' => $e->getLine(),
    								'file' => $e->getFile()
    							]
    						);
    					} else {
    						// Si es otro tipo de error, relanzarlo
    						throw $e;
    					}
    				}
    			}
    		} catch (\Exception $e) {
    			$logger->error('Excepción en proceso de sincronización', [
    				'message' => $e->getMessage(),
    				'line' => $e->getLine(),
    				'file' => $e->getFile(),
    				'trace' => $e->getTraceAsString()
    			]);
    			
    			// Convertir la excepción en un WP_Error para un manejo uniforme
    			$result = new \WP_Error(
    				'sync_exception',
    				__('Error en el proceso de sincronización: ', 'mi-integracion-api') . $e->getMessage()
    			);
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
    			
    			// Registrar información detallada del error
    			$logger->error('Error durante la sincronización', [
    				'error_code' => $error_code,
    				'error_message' => $error_message,
    				'error_data' => $error_data,
    				'memory_usage' => memory_get_usage(true),
    				'peak_memory' => memory_get_peak_usage(true),
    				'execution_time' => microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'],
    				'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
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
    			} elseif ($error_code === 'empty_response') {
    				$user_message = __('El servidor de Verial no devolvió respuesta (posible timeout). Intente reducir el tamaño del lote a 10 productos.', 'mi-integracion-api');
    			} elseif ($error_code === 'batch_too_large') {
    				$user_message = __('El tamaño del lote es demasiado grande para ser procesado. Reduzca el tamaño del lote a 10 productos e intente de nuevo.', 'mi-integracion-api');
    			} elseif ($error_code === 'api_error_intercepted') {
    				$user_message = __('Se ha detectado un error en la comunicación con Verial. Se recomienda reducir el tamaño del lote a 10 productos y reintentar.', 'mi-integracion-api');
    				
    				// Incluir información de diagnóstico para el administrador
    				if (current_user_can('manage_options')) {
    					$user_message .= '<br><br><small>' . __('Información de diagnóstico (solo visible para administradores):', 'mi-integracion-api') . '<br>';
    					$user_message .= __('Error original:', 'mi-integracion-api') . ' ' . ($error_data['original_error'] ?? 'Desconocido') . '<br>';
    					$user_message .= __('Archivo:', 'mi-integracion-api') . ' ' . ($error_data['file'] ?? 'Desconocido') . '<br>';
    					$user_message .= __('Línea:', 'mi-integracion-api') . ' ' . ($error_data['line'] ?? 'Desconocida') . '</small>';
    				}
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
    		}
  
    		wp_send_json_error([
    			'message' => __('Error inesperado durante la sincronización. Por favor, revise los registros para más detalles.', 'mi-integracion-api'),
    			'technical_details' => $e->getMessage(),
    			'code' => 'exception',
    			'context' => $error_context,
    			'file' => basename($e->getFile()),
    			'line' => $e->getLine()
    		], 500);
    	}
    }
    
    // Handler para guardar el batch size seleccionado
	public static function save_batch_size() {
		// Validar nonce y permisos
		check_ajax_referer( MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ], 403 );
		}

		$batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 20;
		$batch_size = max(5, min($batch_size, 100)); // Validar rango

		// Guardar la configuración
		update_option('mi_integracion_api_batch_size', $batch_size);

		// Log de la operación
		$logger = new Logger('batch-size-config');
		$logger->info('Batch size actualizado por el usuario', [
			'new_size' => $batch_size,
			'user_id' => get_current_user_id(),
			'timestamp' => current_time('mysql')
		]);

		wp_send_json_success([
			'message' => sprintf(__('Tamaño de lote guardado: %d productos', 'mi-integracion-api'), $batch_size),
			'batch_size' => $batch_size
		]);
	}

	// Handler para diagnosticar rangos problemáticos
	public static function diagnose_range() {
		// Validar nonce y permisos
		check_ajax_referer( MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ], 403 );
		}

		$inicio = isset($_POST['inicio']) ? intval($_POST['inicio']) : 0;
		$fin = isset($_POST['fin']) ? intval($_POST['fin']) : 0;
		$deep_analysis = isset($_POST['deep_analysis']) && $_POST['deep_analysis'] === 'true';

		if ($inicio <= 0 || $fin <= 0 || $inicio > $fin) {
			wp_send_json_error(['message' => 'Rango inválido especificado']);
		}

		try {
			// Obtener instancia del Sync Manager
			if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
				require_once dirname(__DIR__) . '/Core/Sync_Manager.php';
			}
			
			$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			$diagnostic_result = $sync_manager->diagnose_problematic_range($inicio, $fin, $deep_analysis);

			// Log de la operación
			$logger = new Logger('range-diagnostics');
			$logger->info('Diagnóstico de rango ejecutado', [
				'range' => [$inicio, $fin],
				'deep_analysis' => $deep_analysis,
				'issues_found' => count($diagnostic_result['issues_found']),
				'user_id' => get_current_user_id()
			]);

			wp_send_json_success([
				'message' => sprintf(__('Diagnóstico completado para rango %d-%d', 'mi-integracion-api'), $inicio, $fin),
				'diagnostic_result' => $diagnostic_result
			]);

		} catch (\Exception $e) {
			$logger = new Logger('range-diagnostics');
			$logger->error('Error en diagnóstico de rango', [
				'range' => [$inicio, $fin],
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);

			wp_send_json_error([
				'message' => __('Error al ejecutar diagnóstico: ', 'mi-integracion-api') . $e->getMessage()
			]);
		}
	}

	// Handler para obtener estadísticas de rangos saltados
	public static function get_skipped_ranges_stats() {
		// Validar nonce y permisos
		check_ajax_referer( MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'No tienes permisos suficientes.', 'mi-integracion-api' ) ], 403 );
		}

		try {
			// Obtener instancia del Sync Manager
			if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
				require_once dirname(__DIR__) . '/Core/Sync_Manager.php';
			}
			
			$sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
			$stats = $sync_manager->get_skipped_ranges_statistics();

			wp_send_json_success([
				'message' => __('Estadísticas obtenidas exitosamente', 'mi-integracion-api'),
				'stats' => $stats
			]);

		} catch (\Exception $e) {
			wp_send_json_error([
				'message' => __('Error al obtener estadísticas: ', 'mi-integracion-api') . $e->getMessage()
			]);
		}
	}

	/**
	 * Obtiene recomendaciones de optimización basadas en el historial de timeouts
	 */
	public static function get_sync_recommendations() {
		check_ajax_referer(MiIntegracionApi_NONCE_PREFIX . 'dashboard', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mi-integracion-api')], 403);
		}

		try {
			$timeout_history = get_option('mia_timeout_history', []);
			$current_batch_size = get_option('mi_integracion_api_batch_size', 20);
			
			$recommendations = [];
			$problematic_ranges = [];
			$suggested_batch_size = $current_batch_size;
			
			// Analizar historial de timeouts
			foreach ($timeout_history as $range_key => $data) {
				$timeout_ratio = $data['timeout_count'] / max(1, $data['success_count'] + $data['timeout_count']);
				
				if ($timeout_ratio > 0.5) { // Más del 50% de fallos
					$problematic_ranges[] = [
						'range' => $range_key,
						'timeout_count' => $data['timeout_count'],
						'success_count' => $data['success_count'],
						'timeout_ratio' => round($timeout_ratio * 100, 1),
						'last_successful_size' => $data['last_successful_size']
					];
					
					// Ajustar tamaño de lote recomendado basado en el último éxito
					if ($data['last_successful_size'] && $data['last_successful_size'] < $suggested_batch_size) {
						$suggested_batch_size = $data['last_successful_size'];
					}
				}
			}
			
			// Generar recomendaciones específicas
			if (!empty($problematic_ranges)) {
				$recommendations[] = [
					'type' => 'batch_size',
					'priority' => 'high',
					'message' => sprintf(
						__('Se detectaron %d rangos problemáticos. Se recomienda reducir el tamaño de lote a %d productos.', 'mi-integracion-api'),
						count($problematic_ranges),
						$suggested_batch_size
					),
					'action' => 'reduce_batch_size',
					'value' => $suggested_batch_size
				];
			}
			
			if ($current_batch_size > 20 && !empty($problematic_ranges)) {
				$recommendations[] = [
					'type' => 'performance',
					'priority' => 'medium',
					'message' => __('El tamaño de lote actual es alto. Considere reducirlo para mejorar la estabilidad.', 'mi-integracion-api'),
					'action' => 'optimize_batch_size',
					'value' => min(20, $suggested_batch_size)
				];
			}
			
			// Recomendaciones de horario basadas en datos
			$recommendations[] = [
				'type' => 'timing',
				'priority' => 'low',
				'message' => __('Para mejores resultados, ejecute sincronizaciones durante horas de menor tráfico.', 'mi-integracion-api'),
				'action' => 'schedule_sync',
				'value' => 'off_peak_hours'
			];

			wp_send_json_success([
				'current_batch_size' => $current_batch_size,
				'suggested_batch_size' => $suggested_batch_size,
				'problematic_ranges' => $problematic_ranges,
				'recommendations' => $recommendations,
				'total_ranges_analyzed' => count($timeout_history)
			]);

		} catch (\Exception $e) {
			wp_send_json_error([
				'message' => __('Error al obtener recomendaciones: ', 'mi-integracion-api') . $e->getMessage()
			]);
		}
	}

	/**
	 * Refresca el nonce para peticiones AJAX
	 */
	public static function refresh_nonce() {
		// Verificar que sea un usuario válido
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mi-integracion-api')], 403);
			return;
		}
		
		// Generar nuevo nonce
		$nonce = wp_create_nonce('mi_sync_single_product');
		
		// Enviar respuesta
		wp_send_json_success([
			'nonce' => $nonce,
			'message' => __('Nonce actualizado correctamente', 'mi-integracion-api')
		]);
	}
	
	/**
	 * Fuerza la liberación de todos los bloqueos de sincronización
	 * Útil para cuando queda un bloqueo huérfano tras un error
	 */
	public static function force_unlock_sync() {
		// Verificar que sea un usuario administrador
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => __('No tienes permisos suficientes.', 'mi-integracion-api')], 403);
			return;
		}
		
		// Cargar la clase SyncLock si no está disponible
		if (!class_exists('\MiIntegracionApi\Sync\SyncLock')) {
			require_once dirname(__DIR__) . '/Sync/SyncLock.php';
		}
		
		// Forzar liberación de todos los bloqueos
		$result = \MiIntegracionApi\Sync\SyncLock::force_unlock_all();
		
		if ($result) {
			wp_send_json_success([
				'message' => __('Todos los bloqueos de sincronización han sido liberados.', 'mi-integracion-api')
			]);
		} else {
			wp_send_json_success([
				'message' => __('No había bloqueos activos que liberar.', 'mi-integracion-api')
			]);
		}
	}
	
	/**
	 * Desbloquea la sincronización en caso de que se haya quedado bloqueada
	 * Endpoint AJAX: mi_unlock_sync
	 */
	public static function unlock_sync() {
		// Verificar que sea un usuario con permisos administrativos
		if (!current_user_can('manage_options')) {
			wp_send_json_error([
				'message' => __('No tienes permisos suficientes para realizar esta acción.', 'mi-integracion-api')
			], 403);
			return;
		}
		
		// Iniciar logger para diagnóstico
		$logger = new \MiIntegracionApi\Helpers\Logger('sync-unlock');
		$logger->warning('Desbloqueo manual de sincronización solicitado', [
			'user_id' => get_current_user_id(),
			'timestamp' => current_time('mysql'),
			'request_data' => $_REQUEST
		]);
		
		// Verificar qué tipo de bloqueo se quiere liberar
		$type = isset($_REQUEST['type']) ? sanitize_text_field($_REQUEST['type']) : 'all';
		$unlocked = [];
		
		// Validar que existan las clases necesarias
		if (!class_exists('\MiIntegracionApi\Sync\SyncLock')) {
			require_once dirname(__DIR__) . '/Sync/SyncLock.php';
		}
		
		// Liberar bloqueos según el tipo solicitado
		if ($type === 'all' || $type === 'batch') {
			if (\MiIntegracionApi\Sync\SyncLock::is_locked('batch')) {
				\MiIntegracionApi\Sync\SyncLock::release('batch');
				$unlocked[] = 'batch';
				$logger->info('Bloqueo de sincronización por lotes liberado');
				
				// También eliminar la opción de cancelación si existe
				delete_option('mia_sync_cancelada');
				delete_transient('mia_sync_cancelada');
			}
		}
		
		if ($type === 'all' || $type === 'single') {
			if (\MiIntegracionApi\Sync\SyncLock::is_locked('single')) {
				\MiIntegracionApi\Sync\SyncLock::release('single');
				$unlocked[] = 'single';
				$logger->info('Bloqueo de sincronización individual liberado');
			}
		}
		
		// Limpiar también cualquier estado de progreso en memoria
		delete_transient('mia_sync_progress');
		
		// Devolver respuesta
		if (!empty($unlocked)) {
			wp_send_json_success([
				'message' => __('Sincronización desbloqueada correctamente', 'mi-integracion-api'),
				'unlocked_types' => $unlocked,
				'timestamp' => current_time('mysql')
			]);
		} else {
			wp_send_json_success([
				'message' => __('No había sincronizaciones bloqueadas', 'mi-integracion-api'),
				'timestamp' => current_time('mysql')
			]);
		}
	}
}