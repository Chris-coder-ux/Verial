<?php
/**
 * Script de prueba para diagnosticar la sincronización por lotes
 * 
 * Este script simula una sincronización por lotes similar a la que ocurre
 * en el proceso AJAX pero en un entorno controlado y con logs detallados.
 */

// Bootstrap WordPress para acceder a sus funciones
if (file_exists(dirname(__FILE__) . '/../../../../../wp-load.php')) {
    require_once(dirname(__FILE__) . '/../../../../../wp-load.php');
} else {
    die('No se puede cargar WordPress. Verifica la ruta relativa.');
}

// Configurar para mostrar errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Asegurarse de que solo se ejecuta en línea de comandos
if (php_sapi_name() !== 'cli') {
    die('Este script solo debe ejecutarse desde la línea de comandos.');
}

// Definir constantes y variables de configuración
define('BATCH_SIZE', 10); // Tamaño de lote más pequeño para pruebas
define('MAX_BATCHES', 3); // Número máximo de lotes a procesar
define('LOG_FILE', dirname(__FILE__) . '/sync-batch-test.log');
define('DETAIL_LEVEL', 3); // 1=básico, 2=intermedio, 3=detallado

// Inicializar el log
function log_message($message, $level = 1, $data = null) {
    if ($level > DETAIL_LEVEL) {
        return; // Ignorar mensajes con nivel de detalle superior al configurado
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $formatted .= "\n" . print_r($data, true);
    }
    
    echo $formatted . "\n";
    file_put_contents(LOG_FILE, $formatted . "\n", FILE_APPEND);
}

// Crear archivo de log vacío
file_put_contents(LOG_FILE, "=== TEST DE SINCRONIZACIÓN POR LOTES ===\n" . date('Y-m-d H:i:s') . "\n\n");

log_message("Iniciando prueba de sincronización por lotes");
log_message("Tamaño de lote: " . BATCH_SIZE);
log_message("Lotes máximos a procesar: " . MAX_BATCHES);

// Verificar que las clases necesarias estén disponibles
if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
    log_message("ERROR: No se encontró la clase Sync_Manager. Verificando rutas...", 1);
    
    $autoloader_path = dirname(__FILE__) . '/../includes/Autoloader.php';
    log_message("Buscando autoloader en: " . $autoloader_path, 2);
    
    if (file_exists($autoloader_path)) {
        log_message("Autoloader encontrado, cargando...", 2);
        require_once($autoloader_path);
        
        // Intentar cargar manualmente las clases principales
        $sync_manager_path = dirname(__FILE__) . '/../includes/Core/Sync_Manager.php';
        if (file_exists($sync_manager_path)) {
            log_message("Cargando Sync_Manager desde: " . $sync_manager_path, 2);
            require_once($sync_manager_path);
        } else {
            log_message("ERROR: No se encontró Sync_Manager en: " . $sync_manager_path, 1);
            die("No se puede continuar sin Sync_Manager.");
        }
    } else {
        log_message("ERROR: No se encontró el autoloader en: " . $autoloader_path, 1);
        die("No se puede continuar sin autoloader.");
    }
}

// Verificar la disponibilidad de otras clases importantes
$required_classes = [
    '\MiIntegracionApi\Core\ApiConnector',
    '\MiIntegracionApi\Sync\BatchProcessor',
    '\MiIntegracionApi\Helpers\Logger'
];

foreach ($required_classes as $class) {
    if (!class_exists($class)) {
        log_message("ADVERTENCIA: No se encontró la clase {$class}", 1);
    } else {
        log_message("Clase {$class} disponible", 3);
    }
}

try {
    // Obtener instancias necesarias
    log_message("Obteniendo instancia de Sync_Manager", 2);
    $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    
    // Verificar estado de la API
    log_message("Comprobando estado de la API...", 2);
    $api_connector = $sync_manager->get_api_connector();
    
    if (!$api_connector) {
        log_message("ERROR: No se pudo obtener el API Connector", 1);
        die("No se puede continuar sin API Connector.");
    }
    
    $api_config = [
        'url' => $api_connector->get_api_base_url(),
        'session_id' => $api_connector->get_session_id(),
    ];
    log_message("Configuración de API:", 2, $api_config);
    
    // Verificar conexión a la API
    log_message("Probando conexión a la API de Verial...", 1);
    
    try {
        // Hacer prueba de conexión simple
        $test_result = $api_connector->test_connection();
        if (is_wp_error($test_result)) {
            log_message("ERROR de conexión API: " . $test_result->get_error_message(), 1);
            die("No se puede continuar sin conexión a la API.");
        } else {
            log_message("Conexión a la API establecida correctamente", 1);
        }
    } catch (\Exception $e) {
        log_message("EXCEPCIÓN al probar conexión API: " . $e->getMessage(), 1);
        die("No se puede continuar debido a excepción en la conexión API.");
    }
    
    // Simular inicio de una nueva sincronización
    log_message("=== COMENZANDO SIMULACIÓN DE SINCRONIZACIÓN POR LOTES ===", 1);
    log_message("Iniciando nueva sincronización...", 1);
    
    // Obtener estado actual antes de iniciar
    $status_before = $sync_manager->get_sync_status();
    log_message("Estado antes de iniciar:", 2, $status_before);
    
    // Establecer filtros de prueba
    $filters = [
        'categoria' => '',
        'fabricante' => '',
        'offset' => 0,
        'limit' => BATCH_SIZE * MAX_BATCHES, // Para asegurar que tenemos suficientes datos para las pruebas
    ];
    log_message("Filtros para la sincronización:", 2, $filters);
    
    // Iniciar sincronización
    try {
        $start_result = $sync_manager->start_sync('products', 'verial_to_wc', $filters);
        log_message("Resultado de inicio de sincronización:", 2, $start_result);
        
        if (is_wp_error($start_result)) {
            log_message("ERROR al iniciar sincronización: " . $start_result->get_error_message(), 1);
            die("No se puede continuar debido a error al iniciar sincronización.");
        }
        
        // Verificar que la sincronización está en progreso
        $status_after_start = $sync_manager->get_sync_status();
        log_message("Estado después de iniciar:", 2, $status_after_start);
        
        if (!$status_after_start['current_sync']['in_progress']) {
            log_message("ERROR: La sincronización no está marcada como en progreso", 1);
            die("No se puede continuar porque la sincronización no inició correctamente.");
        }
        
        // Procesar lotes
        log_message("=== PROCESANDO LOTES ===", 1);
        
        $batch_number = 1;
        $keep_processing = true;
        $total_processed = 0;
        $errors_found = 0;
        
        while ($keep_processing && $batch_number <= MAX_BATCHES) {
            log_message("Procesando lote #{$batch_number}...", 1);
            
            // Procesar siguiente lote
            try {
                $batch_start_time = microtime(true);
                $batch_result = $sync_manager->process_next_batch(false);
                $batch_duration = microtime(true) - $batch_start_time;
                
                log_message("Duración del procesamiento del lote: " . round($batch_duration, 2) . " segundos", 2);
                
                // Verificar resultado del lote
                if (is_wp_error($batch_result)) {
                    log_message("ERROR en lote #{$batch_number}: " . $batch_result->get_error_message(), 1);
                    $errors_found++;
                } else {
                    log_message("Resultado de lote #{$batch_number}:", 2, $batch_result);
                    
                    // Actualizar estadísticas
                    if (isset($batch_result['processed'])) {
                        $total_processed += $batch_result['processed'];
                    }
                    
                    if (isset($batch_result['errors'])) {
                        $errors_found += $batch_result['errors'];
                    }
                    
                    // Verificar si hay más datos para procesar
                    if (isset($batch_result['done']) && $batch_result['done']) {
                        log_message("No hay más productos para procesar. Sincronización completada.", 1);
                        $keep_processing = false;
                    }
                }
            } catch (\Exception $e) {
                log_message("EXCEPCIÓN en lote #{$batch_number}: " . $e->getMessage(), 1);
                log_message("Traza de la excepción:", 3, $e->getTraceAsString());
                $errors_found++;
            }
            
            // Obtener y registrar el estado después del lote
            $status_after_batch = $sync_manager->get_sync_status();
            log_message("Estado después del lote #{$batch_number}:", 3, $status_after_batch);
            
            // Registrar resumen de progreso
            if (isset($status_after_batch['current_sync']['statistics'])) {
                $stats = $status_after_batch['current_sync']['statistics'];
                log_message("Progreso después del lote #{$batch_number}:", 1, [
                    'procesados' => $stats['processed'] ?? 0,
                    'errores' => $stats['errors'] ?? 0,
                    'total' => $stats['total'] ?? 0,
                    'porcentaje' => $stats['percentage'] ?? 0,
                ]);
            }
            
            // Verificar condiciones de memoria
            $memory_usage = memory_get_usage(true) / 1024 / 1024; // MB
            log_message("Uso de memoria después del lote #{$batch_number}: " . round($memory_usage, 2) . " MB", 2);
            
            if ($memory_usage > 100) {
                log_message("ADVERTENCIA: Alto uso de memoria detectado!", 1);
            }
            
            // Verificar la estructura de la respuesta de la API para identificar problemas con precios
            log_message("Analizando respuesta de API para verificar estructura de precios...", 2);
            $api_response = $api_connector->get_articulos([
                'offset' => ($batch_number - 1) * BATCH_SIZE,
                'limit' => 1 // Solo obtener un producto para análisis
            ]);
            
            if (!is_wp_error($api_response) && !empty($api_response)) {
                // Intentar detectar estructura del producto
                $sample_product = null;
                
                if (isset($api_response['Articulos']) && is_array($api_response['Articulos']) && !empty($api_response['Articulos'])) {
                    $sample_product = $api_response['Articulos'][0];
                } elseif (isset($api_response[0])) {
                    $sample_product = $api_response[0];
                } elseif (isset($api_response['Id'])) {
                    $sample_product = $api_response;
                }
                
                if ($sample_product) {
                    log_message("Muestra de producto para análisis:", 3, $sample_product);
                    
                    // Verificar si tiene precio
                    if (isset($sample_product['Id'])) {
                        $product_id = $sample_product['Id'];
                        log_message("Verificando precios para producto ID: " . $product_id, 2);
                        
                        // Obtener condiciones de tarifa
                        try {
                            $condiciones = $api_connector->get_condiciones_tarifa($product_id);
                            log_message("Condiciones de tarifa para producto ID " . $product_id . ":", 3, $condiciones);
                            
                            // Analizar estructura de precios
                            if (is_array($condiciones)) {
                                if (isset($condiciones['CondicionesTarifa']) && is_array($condiciones['CondicionesTarifa'])) {
                                    log_message("Estructura detectada: Array con clave CondicionesTarifa", 2);
                                    
                                    if (!empty($condiciones['CondicionesTarifa'])) {
                                        $first_condition = $condiciones['CondicionesTarifa'][0];
                                        if (isset($first_condition['Precio'])) {
                                            log_message("✓ Precio encontrado correctamente en CondicionesTarifa: " . $first_condition['Precio'], 1);
                                        } else {
                                            log_message("⚠️ No se encontró precio en las condiciones de tarifa", 1);
                                        }
                                    } else {
                                        log_message("⚠️ CondicionesTarifa está vacío", 1);
                                    }
                                } else {
                                    log_message("⚠️ Estructura de condiciones de tarifa no reconocida", 1, $condiciones);
                                }
                            } else {
                                log_message("⚠️ Respuesta de condiciones de tarifa no es un array", 1);
                            }
                        } catch (\Exception $e) {
                            log_message("ERROR al obtener condiciones de tarifa: " . $e->getMessage(), 1);
                        }
                    }
                }
            }
            
            $batch_number++;
        }
        
        // Resumen final
        log_message("=== RESUMEN DE LA PRUEBA ===", 1);
        log_message("Total de lotes procesados: " . ($batch_number - 1), 1);
        log_message("Total de productos procesados: " . $total_processed, 1);
        log_message("Total de errores encontrados: " . $errors_found, 1);
        
        // Estado final
        $final_status = $sync_manager->get_sync_status();
        log_message("Estado final de sincronización:", 2, $final_status);
        
        // Finalizar sincronización (simular fin de proceso)
        // En una sincronización real esto ocurriría cuando todos los productos están procesados
        if ($final_status['current_sync']['in_progress']) {
            log_message("Finalizando sincronización...", 1);
            // Marcar como completada
            $sync_manager->complete_sync(true); // true = éxito, false = fallo
            log_message("Sincronización finalizada manualmente", 1);
        }
    } catch (\Exception $e) {
        log_message("EXCEPCIÓN CRÍTICA: " . $e->getMessage(), 1);
        log_message("Traza de la excepción:", 2, $e->getTraceAsString());
    }
} catch (\Exception $e) {
    log_message("EXCEPCIÓN GLOBAL: " . $e->getMessage(), 1);
    log_message("Traza de la excepción:", 2, $e->getTraceAsString());
}

// Resumen final y recomendaciones
log_message("\n=== DIAGNÓSTICO FINALIZADO ===", 1);
log_message("Archivo de log completo guardado en: " . LOG_FILE, 1);
log_message("Recomendaciones:", 1);
log_message("- Revisa en detalle los errores y advertencias encontrados", 1);
log_message("- Verifica la correcta estructura de precios en la API", 1);
log_message("- Analiza posibles problemas de memoria o rendimiento", 1);
echo "\n";
