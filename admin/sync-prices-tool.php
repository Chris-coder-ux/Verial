<?php
/**
 * Herramienta de línea de comandos para sincronización de precios por lotes.
 * Este script adapta la lógica del test-sync-prices.php para usarla con el plugin real.
 * 
 * Uso: php admin/sync-prices-tool.php [--batch-size=5] [--max-batches=3] [--max-products=15]
 */

// Modo debug - Muestra errores de PHP
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Carga de WordPress necesaria para acceder a las clases del plugin
$wp_load_paths = [
    dirname(__FILE__) . '/../../../../wp-load.php',     // Ruta estándar 4 niveles arriba
    dirname(__FILE__) . '/../../../wp-load.php',        // 3 niveles arriba
    dirname(__FILE__) . '/../../wp-load.php',           // 2 niveles arriba
    '/var/www/html/wp-load.php',                        // Ruta típica en servidores Linux
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once($path);
        $wp_loaded = true;
        echo "WordPress cargado desde: " . $path . "\n";
        break;
    }
}

if (!$wp_loaded) {
    die("No se pudo cargar WordPress. Este script requiere un entorno WordPress activo.\n");
}

// Comprobar si el plugin está activo
if (!function_exists('is_plugin_active')) {
    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
}

if (!is_plugin_active('mi-integracion-api/mi-integracion-api.php')) {
    die("El plugin Mi Integración API no está activo.\n");
}

// Asegurarnos de cargar manualmente las clases principales para evitar problemas de autoloading
$required_files = [
    'includes/Core/DataSanitizer.php',
    'includes/Core/ApiConnector.php',
    'includes/Core/Config_Manager.php',
    'includes/Helpers/Logger.php',
    'includes/Core/Sync_Manager.php',
    'includes/Sync/BatchProcessor.php'
];

// Crear clase temporal para resolver problema de namespace
if (!class_exists('MiIntegracionApi\Helpers\DataSanitizer')) {
    class_alias('MiIntegracionApi\Core\DataSanitizer', 'MiIntegracionApi\Helpers\DataSanitizer');
}

echo "Cargando clases requeridas...\n";
$plugin_dir = plugin_dir_path(dirname(__FILE__));
foreach ($required_files as $file) {
    $full_path = $plugin_dir . $file;
    if (file_exists($full_path)) {
        require_once($full_path);
        echo "✓ Cargado: " . basename($file) . "\n";
    } else {
        echo "✗ No encontrado: " . $file . "\n";
    }
}

// Procesar argumentos de línea de comandos
$args = getopt('', ['batch-size::', 'max-batches::', 'max-products::']);

// Configurar parámetros de ejecución
define('SYNC_BATCH_SIZE', isset($args['batch-size']) ? (int)$args['batch-size'] : 5);
define('SYNC_MAX_BATCHES', isset($args['max-batches']) ? (int)$args['max-batches'] : 3);
define('SYNC_MAX_PRODUCTS', isset($args['max-products']) ? (int)$args['max-products'] : SYNC_BATCH_SIZE * SYNC_MAX_BATCHES);
define('SYNC_LOG_FILE', dirname(__FILE__) . '/../logs/sync-prices-' . date('Ymd-His') . '.log');

// Asegurar que existe el directorio de logs
if (!file_exists(dirname(__FILE__) . '/../logs/')) {
    mkdir(dirname(__FILE__) . '/../logs/', 0755, true);
}

// Inicializar log
function log_message($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $formatted .= "\n" . print_r($data, true);
        } else {
            $formatted .= " " . $data;
        }
    }
    
    echo $formatted . "\n";
    file_put_contents(SYNC_LOG_FILE, $formatted . "\n", FILE_APPEND);
}

// Crear archivo de log vacío
file_put_contents(SYNC_LOG_FILE, "=== SINCRONIZACIÓN DE PRECIOS POR LOTES ===\n" . date('Y-m-d H:i:s') . "\n\n");

log_message("Iniciando sincronización de precios por lotes");
log_message("Tamaño de lote: " . SYNC_BATCH_SIZE);
log_message("Lotes máximos a procesar: " . SYNC_MAX_BATCHES);
log_message("Productos máximos a procesar: " . SYNC_MAX_PRODUCTS);

try {
    // Obtener instancia del gestor de sincronización
    log_message("Obteniendo instancia de Sync_Manager");
    $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    
    // Verificar estado de la API
    log_message("Comprobando estado de la API...");
    $api_connector = $sync_manager->get_api_connector();
    
    if (!$api_connector) {
        log_message("ERROR: No se pudo obtener el API Connector");
        die("No se puede continuar sin API Connector.");
    }
    
    $api_config = [
        'url' => $api_connector->get_api_base_url(),
        'session_id' => $api_connector->get_numero_sesion(),
    ];
    log_message("Configuración de API:", $api_config);
    
    // Verificar conexión a la API
    log_message("Probando conexión a la API de Verial...");
    try {
        // Usar el método get() para obtener datos y comprobar la conexión
        // GetArticulosWS con limit=1 es una forma ligera de verificar la conexión
        $test_result = $api_connector->get('GetArticulosWS', ['limit' => 1]);
        
        if (is_wp_error($test_result)) {
            log_message("ERROR de conexión API: " . $test_result->get_error_message());
            die("No se puede continuar sin conexión a la API.");
        } elseif (empty($test_result) || !is_array($test_result)) {
            log_message("ERROR: La API no devolvió una respuesta válida. Respuesta recibida:", $test_result);
            die("No se puede continuar. La API no devolvió una respuesta válida.");
        } else {
            log_message("Conexión a la API establecida correctamente");
            log_message("Respuesta de prueba de la API:", $test_result);
        }
    } catch (\Exception $e) {
        log_message("EXCEPCIÓN al probar conexión API: " . $e->getMessage());
        die("No se puede continuar debido a excepción en la conexión API.");
    }
    
    // ==== SIMULACIÓN DE SINCRONIZACIÓN POR LOTES ENFOCADA EN PRECIOS ====
    log_message("=== COMENZANDO SINCRONIZACIÓN DE PRECIOS POR LOTES ===");
    
    // Iniciar una nueva sincronización
    $filters = [
        'categoria' => '',
        'fabricante' => '',
        'limit' => SYNC_MAX_PRODUCTS
    ];
    
    log_message("Iniciando una nueva sincronización con los siguientes filtros:", $filters);
    $start_result = $sync_manager->start_sync('products', 'verial_to_wc', $filters);
    
    if (is_wp_error($start_result)) {
        log_message("ERROR al iniciar sincronización: " . $start_result->get_error_message());
        die("No se puede continuar. Error al iniciar sincronización.");
    }
    
    log_message("Sincronización iniciada correctamente:", $start_result);
    
    // Procesar lotes
    $lotes_procesados = 0;
    $productos_procesados = 0;
    $productos_con_precio = 0;
    $productos_sin_precio = 0;
    $errores = 0;
    $seguir_procesando = true;
    
    while ($seguir_procesando && $lotes_procesados < SYNC_MAX_BATCHES) {
        $lotes_procesados++;
        log_message("\n=== PROCESANDO LOTE #{$lotes_procesados} ===");
        
        $batch_start_time = microtime(true);
        
        // Procesar el siguiente lote utilizando el sistema del plugin
        $batch_result = $sync_manager->process_next_batch(false);
        $batch_duration = microtime(true) - $batch_start_time;
        
        log_message("Duración del procesamiento del lote: " . round($batch_duration, 2) . " segundos");
        
        if (is_wp_error($batch_result)) {
            log_message("ERROR en lote #{$lotes_procesados}: " . $batch_result->get_error_message());
            $errores++;
        } else {
            log_message("Resultado del lote #{$lotes_procesados}:", $batch_result);
            
            // Actualizar estadísticas
            if (isset($batch_result['processed'])) {
                $productos_procesados += $batch_result['processed'];
                
                // Intentar determinar cuántos productos tienen precio
                if (isset($batch_result['processed_products']) && is_array($batch_result['processed_products'])) {
                    foreach ($batch_result['processed_products'] as $product) {
                        if (!empty($product['regular_price'])) {
                            $productos_con_precio++;
                        } else {
                            $productos_sin_precio++;
                        }
                    }
                }
            }
            
            if (isset($batch_result['errors'])) {
                $errores += $batch_result['errors'];
            }
            
            // Verificar si hay más productos para procesar
            if (isset($batch_result['done']) && $batch_result['done']) {
                log_message("No hay más productos para procesar. Sincronización completada.");
                $seguir_procesando = false;
            }
        }
        
        // Obtener y mostrar el estado después del lote
        $status_after_batch = $sync_manager->get_sync_status();
        log_message("Estado después del lote #{$lotes_procesados}:", $status_after_batch);
        
        // Si tenemos estadísticas, mostrarlas
        if (isset($status_after_batch['current_sync']['statistics'])) {
            $stats = $status_after_batch['current_sync']['statistics'];
            log_message("Progreso después del lote #{$lotes_procesados}:", [
                'procesados' => $stats['processed'] ?? 0,
                'errores' => $stats['errors'] ?? 0,
                'total' => $stats['total'] ?? 0,
                'porcentaje' => $stats['percentage'] ?? 0,
            ]);
        }
        
        // Verificar uso de memoria
        $memory_usage = memory_get_usage(true) / 1024 / 1024; // MB
        log_message("Uso de memoria después del lote #{$lotes_procesados}: " . round($memory_usage, 2) . " MB");
        
        // Si hemos alcanzado el límite de productos, terminamos
        if ($productos_procesados >= SYNC_MAX_PRODUCTS) {
            log_message("Se alcanzó el límite de {$SYNC_MAX_PRODUCTS} productos. Finalizando sincronización.");
            $seguir_procesando = false;
        }
        
        // Pequeña pausa entre lotes para reducir la carga
        if ($seguir_procesando) {
            log_message("Pausa de 2 segundos antes del siguiente lote...");
            sleep(2);
        }
    }
    
    // Completar la sincronización
    log_message("\n=== FINALIZANDO SINCRONIZACIÓN ===");
    $complete_result = $sync_manager->complete_sync(true); // true = éxito
    log_message("Resultado de finalización:", $complete_result);
    
    // Estadísticas finales
    log_message("\n=== ESTADÍSTICAS FINALES DE SINCRONIZACIÓN ===");
    log_message("Lotes procesados: {$lotes_procesados}");
    log_message("Productos procesados: {$productos_procesados}");
    
    if ($productos_procesados > 0) {
        $porcentaje_con_precio = ($productos_con_precio / $productos_procesados) * 100;
        $porcentaje_sin_precio = ($productos_sin_precio / $productos_procesados) * 100;
        
        log_message("Productos con precio: {$productos_con_precio} (" . round($porcentaje_con_precio, 2) . "%)");
        log_message("Productos sin precio: {$productos_sin_precio} (" . round($porcentaje_sin_precio, 2) . "%)");
    } else {
        log_message("Productos con precio: {$productos_con_precio} (0%)");
        log_message("Productos sin precio: {$productos_sin_precio} (0%)");
    }
    
    log_message("Errores encontrados: {$errores}");
    
    // Recomendaciones basadas en los resultados
    log_message("\n=== ANÁLISIS Y RECOMENDACIONES ===");
    
    if ($productos_sin_precio > 0) {
        log_message("⚠️ Se detectaron {$productos_sin_precio} productos sin precio. Revisar la estructura de respuesta de la API para estos productos.");
    }
    
    if ($errores > 0) {
        log_message("⚠️ Se encontraron {$errores} errores durante la sincronización. Revisar los mensajes de error específicos en el log.");
    }
    
    if ($productos_procesados > 0) {
        if (($productos_con_precio / $productos_procesados) < 0.9) {
            log_message("⚠️ Menos del 90% de los productos tienen precios. Puede haber un problema con la API o con la configuración de precios.");
        } else {
            log_message("✅ La mayoría de los productos tienen precios configurados correctamente.");
        }
    } else {
        log_message("⚠️ No se procesaron productos, no se puede evaluar la relación de precios.");
    }
    
    // Verificación final de memoria
    $memory_peak = memory_get_peak_usage(true) / 1024 / 1024; // MB
    log_message("Uso máximo de memoria durante la ejecución: " . round($memory_peak, 2) . " MB");
    
    if ($memory_peak > 100) {
        log_message("⚠️ El uso de memoria alcanzó niveles altos. Considerar reducir el tamaño de lote para producción.");
    } else {
        log_message("✅ El uso de memoria se mantuvo en niveles aceptables.");
    }
    
} catch (\Exception $e) {
    log_message("ERROR CRÍTICO: " . $e->getMessage());
    log_message("Traza de la excepción: " . $e->getTraceAsString());
}

log_message("\nSincronización completada. Archivo de log guardado en: " . SYNC_LOG_FILE);
echo "Finalización exitosa de la sincronización de precios por lotes.\n";
