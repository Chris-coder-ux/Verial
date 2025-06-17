<?php
/**
 * Script de prueba para diagnosticar la sincronización de precios por lotes
 * 
 * Este script se enfoca específicamente en la sincronización de precios,
 * asegurando que los precios se obtengan correctamente de la API en cada lote.
 * Procesa los productos en lotes pequeños y controlados para evitar problemas de memoria.
 */

// Bootstrap WordPress para acceder a sus funciones
// Buscar wp-load.php en varias ubicaciones relativas posibles
$wp_load_paths = [
    dirname(__FILE__) . '/../../../../../wp-load.php',  // Ruta estándar 5 niveles arriba
    dirname(__FILE__) . '/../../../../wp-load.php',     // 4 niveles arriba
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
    // Intento alternativo: buscar en directorios parent
    $current_dir = dirname(__FILE__);
    $max_depth = 6; // Limitar la búsqueda para evitar bucles infinitos
    
    for ($i = 0; $i < $max_depth; $i++) {
        $parent_dir = dirname($current_dir);
        $possible_path = $parent_dir . '/wp-load.php';
        
        if (file_exists($possible_path)) {
            require_once($possible_path);
            $wp_loaded = true;
            echo "WordPress cargado desde: " . $possible_path . "\n";
            break;
        }
        
        $current_dir = $parent_dir;
        if ($current_dir == '/' || empty($current_dir)) {
            break; // Llegamos a la raíz del sistema
        }
    }
}

// Si no se encuentra WordPress, intentar ejecutar sin él con funcionalidad limitada
if (!$wp_loaded) {
    echo "ADVERTENCIA: No se pudo cargar WordPress. El script ejecutará en modo de prueba simulado.\n";
    
    // Definir funciones de compatibilidad básicas para evitar errores fatales
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            // Detectar objetos que simulamos como WP_Error
            if (is_array($thing) && isset($thing['is_wp_error']) && $thing['is_wp_error'] === true) {
                return true;
            }
            return false;
        }
    }
    
    // Crear una simulación mínima de clases necesarias para pruebas
    if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
        class DummyApiConnector {
            public function test_connection() {
                return ['success' => true, 'message' => 'Conexión simulada exitosa'];
            }
            
            public function get_api_base_url() {
                return 'http://x.verial.org:8000/WcfServiceLibraryVerial';
            }
            
            public function get_session_id() {
                return 18; // ID de sesión simulado
            }
            
            public function get_articulos($params) {
                // Generar productos de prueba
                $productos = [];
                $limite = min(100, $params['limit'] ?? 10);
                $offset = $params['offset'] ?? 0;
                
                for ($i = 1; $i <= $limite; $i++) {
                    $index = $offset + $i;
                    $productos[] = [
                        'Id' => $index,
                        'Nombre' => 'Producto de prueba ' . $index,
                        'Referencia' => 'REF-' . $index,
                        'ReferenciaBarras' => '123456789' . str_pad($index, 4, '0', STR_PAD_LEFT),
                        'Stock' => rand(0, 100),
                        'Descripcion' => 'Descripción del producto ' . $index
                    ];
                }
                
                return ['Articulos' => $productos];
            }
            
            public function get_condiciones_tarifa($id_articulo) {
                // 80% de probabilidad de tener precio
                if (rand(1, 100) <= 80) {
                    return [
                        'CondicionesTarifa' => [
                            [
                                'Precio' => round(rand(10, 10000) / 100, 2),
                                'Dto' => rand(0, 20)
                            ]
                        ]
                    ];
                } else {
                    // Sin precio
                    return ['CondicionesTarifa' => []];
                }
            }
        }
        
        class DummySyncManager {
            private static $instance;
            private $api_connector;
            
            public function __construct() {
                $this->api_connector = new DummyApiConnector();
            }
            
            public static function get_instance() {
                if (null === self::$instance) {
                    self::$instance = new self();
                }
                return self::$instance;
            }
            
            public function get_api_connector() {
                return $this->api_connector;
            }
        }
        
        // Simular clase en espacio de nombres MiIntegracionApi\Core
        class_alias('DummySyncManager', 'MiIntegracionApi\Core\Sync_Manager');
    }
    
    echo "Modo de simulación activado para pruebas sin WordPress.\n";
}

// Configurar para mostrar errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Asegurarse de que solo se ejecuta en línea de comandos
if (php_sapi_name() !== 'cli') {
    die('Este script solo debe ejecutarse desde la línea de comandos.');
}

// Definir constantes y variables de configuración
define('BATCH_SIZE', 5); // Tamaño de lote más pequeño para pruebas
define('MAX_BATCHES', 3); // Número máximo de lotes a procesar
define('MAX_PRODUCTS', BATCH_SIZE * MAX_BATCHES); // Límite de productos a procesar en total
define('LOG_FILE', dirname(__FILE__) . '/sync-prices-test-' . date('Ymd-His') . '.log');
define('DETAIL_LEVEL', 2); // 1=básico, 2=detallado

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
file_put_contents(LOG_FILE, "=== TEST DE SINCRONIZACIÓN DE PRECIOS POR LOTES ===\n" . date('Y-m-d H:i:s') . "\n\n");

log_message("Iniciando prueba de sincronización de precios por lotes");
log_message("Tamaño de lote: " . BATCH_SIZE);
log_message("Lotes máximos a procesar: " . MAX_BATCHES);
log_message("Total máximo de productos: " . MAX_PRODUCTS);

// Verificar que las clases necesarias estén disponibles
if (!class_exists('\MiIntegracionApi\Core\Sync_Manager')) {
    log_message("ERROR: No se encontró la clase Sync_Manager. Verificando rutas...");
    
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
            log_message("ERROR: No se encontró Sync_Manager en: " . $sync_manager_path);
            die("No se puede continuar sin Sync_Manager.");
        }
    } else {
        log_message("ERROR: No se encontró el autoloader en: " . $autoloader_path);
        die("No se puede continuar sin autoloader.");
    }
}

try {
    // Obtener instancias necesarias
    log_message("Obteniendo instancia de Sync_Manager");
    $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    
    // Verificar estado de la API
    log_message("Comprobando estado de la API...");
    $api_connector = $sync_manager->get_api_connector();
    
    if (!$api_connector) {
        log_message("ERROR: No se pudo obtener el API Connector");
        die("No se puede continuar sin API Connector.");
    }
    
    // Configuración de la API
    $api_config = [
        'url' => $api_connector->get_api_base_url(),
        'session_id' => $api_connector->get_session_id(),
    ];
    log_message("Configuración de API:", 1, $api_config);
    
    // Verificar conexión a la API
    log_message("Probando conexión a la API de Verial...");
    
    try {
        // Hacer prueba de conexión simple
        $test_result = $api_connector->test_connection();
        if (is_wp_error($test_result)) {
            log_message("ERROR de conexión API: " . $test_result->get_error_message());
            die("No se puede continuar sin conexión a la API.");
        } else {
            log_message("Conexión a la API establecida correctamente");
        }
    } catch (\Exception $e) {
        log_message("EXCEPCIÓN al probar conexión API: " . $e->getMessage());
        die("No se puede continuar debido a excepción en la conexión API.");
    }
    
    // ==== SIMULACIÓN DE SINCRONIZACIÓN POR LOTES ENFOCADA EN PRECIOS ====
    log_message("=== COMENZANDO SIMULACIÓN DE SINCRONIZACIÓN DE PRECIOS POR LOTES ===");
    
    // Obtener todos los productos con límite para procesar por lotes
    log_message("Obteniendo lista inicial de productos (máximo " . MAX_PRODUCTS . ")...");
    
    $productos_response = $api_connector->get_articulos([
        'offset' => 0,
        'limit' => MAX_PRODUCTS
    ]);
    
    // Verificar y normalizar la respuesta de productos
    $productos = [];
    
    if (is_wp_error($productos_response)) {
        log_message("ERROR al obtener productos: " . $productos_response->get_error_message());
        die("No se puede continuar sin productos para sincronizar.");
    }
    
    if (isset($productos_response['Articulos']) && is_array($productos_response['Articulos'])) {
        $productos = $productos_response['Articulos'];
    } elseif (isset($productos_response[0])) {
        $productos = $productos_response;
    } else {
        log_message("Estructura de respuesta no reconocida:", 1, $productos_response);
        die("No se puede continuar con una estructura de respuesta desconocida.");
    }
    
    $total_productos = count($productos);
    log_message("Se obtuvieron {$total_productos} productos para sincronizar");
    
    // Función para extraer precios de las condiciones de tarifa
    function extraer_precio($condiciones) {
        $precio_info = [
            'encontrado' => false,
            'precio_base' => null,
            'precio_oferta' => null
        ];
        
        if (!is_array($condiciones)) {
            return $precio_info;
        }
        
        // Manejar la estructura específica de la API de Verial
        $condiciones_lista = [];
        
        // Verificar si las condiciones vienen en formato CondicionesTarifa
        if (isset($condiciones['CondicionesTarifa']) && is_array($condiciones['CondicionesTarifa'])) {
            log_message("Detectada estructura CondicionesTarifa", 2);
            $condiciones_lista = $condiciones['CondicionesTarifa'];
            
            // Si es un objeto único y no un array
            if (isset($condiciones_lista['Precio'])) {
                $condiciones_lista = [$condiciones_lista];
            }
        } elseif (isset($condiciones[0])) {
            // Array de condiciones directo
            $condiciones_lista = $condiciones;
        } elseif (isset($condiciones['Precio'])) {
            // Condición única
            $condiciones_lista = [$condiciones];
        }
        
        // Procesar la primera condición de tarifa encontrada
        if (!empty($condiciones_lista)) {
            foreach ($condiciones_lista as $condicion) {
                if (isset($condicion['Precio']) && is_numeric($condicion['Precio']) && $condicion['Precio'] > 0) {
                    // Encontramos un precio válido
                    $precio_info['encontrado'] = true;
                    $precio_info['precio_base'] = $condicion['Precio'];
                    
                    // Si hay descuento porcentual, calcular el precio final
                    if (isset($condicion['Dto']) && is_numeric($condicion['Dto']) && $condicion['Dto'] > 0) {
                        $descuento = ($condicion['Precio'] * $condicion['Dto']) / 100;
                        $precio_info['precio_oferta'] = $condicion['Precio'] - $descuento;
                    }
                    
                    // Si hay descuento en euros por unidad
                    elseif (isset($condicion['DtoEurosXUd']) && is_numeric($condicion['DtoEurosXUd']) && $condicion['DtoEurosXUd'] > 0) {
                        $precio_info['precio_oferta'] = $condicion['Precio'] - $condicion['DtoEurosXUd'];
                    }
                    
                    break; // Solo procesamos la primera condición válida
                }
            }
        }
        
        return $precio_info;
    }
    
    // Procesar productos en lotes
    $lotes_procesados = 0;
    $productos_procesados = 0;
    $productos_con_precio = 0;
    $productos_sin_precio = 0;
    $errores = 0;
    
    for ($batch_index = 0; $batch_index < MAX_BATCHES; $batch_index++) {
        $batch_offset = $batch_index * BATCH_SIZE;
        $batch_limit = min(BATCH_SIZE, $total_productos - $batch_offset);
        
        if ($batch_limit <= 0) {
            log_message("No hay más productos para procesar");
            break;
        }
        
        $lotes_procesados++;
        log_message("\n=== PROCESANDO LOTE #{$lotes_procesados} (productos {$batch_offset} - " . ($batch_offset + $batch_limit - 1) . ") ===");
        
        $batch_start_time = microtime(true);
        $batch_productos_procesados = 0;
        $batch_productos_con_precio = 0;
        $batch_productos_sin_precio = 0;
        $batch_errores = 0;
        
        // Procesar cada producto en este lote
        for ($i = $batch_offset; $i < $batch_offset + $batch_limit; $i++) {
            $producto = $productos[$i];
            
            // Verificar si el producto tiene un ID válido
            $id_producto = $producto['Id'] ?? null;
            $nombre = $producto['Nombre'] ?? "Desconocido";
            $referencia = $producto['Referencia'] ?? ($producto['ReferenciaBarras'] ?? "Sin referencia");
            
            log_message("Procesando producto: {$nombre} (ID: {$id_producto}, REF: {$referencia})", 1);
            
            if (empty($id_producto)) {
                log_message("⚠️ Producto sin ID, saltando...");
                $batch_errores++;
                continue;
            }
            
            try {
                // Obtener condiciones de tarifa para precio
                $condiciones = $api_connector->get_condiciones_tarifa($id_producto);
                
                if (is_wp_error($condiciones)) {
                    log_message("ERROR al obtener condiciones de tarifa: " . $condiciones->get_error_message());
                    $batch_errores++;
                    continue;
                }
                
                if (empty($condiciones)) {
                    log_message("⚠️ No se obtuvieron condiciones de tarifa para el producto ID: {$id_producto}");
                    $batch_productos_sin_precio++;
                    continue;
                }
                
                // Extraer información de precios
                $precios = extraer_precio($condiciones);
                
                if ($precios['encontrado']) {
                    log_message("✅ Precio encontrado para producto '{$nombre}':", 1, [
                        'precio_base' => $precios['precio_base'],
                        'precio_oferta' => $precios['precio_oferta'] ? $precios['precio_oferta'] : 'No hay oferta'
                    ]);
                    
                    $batch_productos_con_precio++;
                } else {
                    log_message("❌ No se encontró precio para el producto '{$nombre}'");
                    $batch_productos_sin_precio++;
                }
                
                // Simular actualización en WooCommerce (sin hacer cambios reales)
                $wc_data = [
                    'sku' => $referencia,
                    'name' => $nombre,
                    'regular_price' => $precios['precio_base'],
                    'sale_price' => $precios['precio_oferta']
                ];
                
                log_message("Datos para WooCommerce:", 2, $wc_data);
                
                $batch_productos_procesados++;
                
                // Pequeña pausa para no sobrecargar la API
                usleep(100000); // 100ms
                
            } catch (\Exception $e) {
                log_message("EXCEPCIÓN procesando producto {$id_producto}: " . $e->getMessage());
                $batch_errores++;
            }
        }
        
        $batch_duration = microtime(true) - $batch_start_time;
        
        // Mostrar estadísticas del lote
        log_message("\n--- Estadísticas del lote #{$lotes_procesados} ---");
        log_message("Productos procesados: {$batch_productos_procesados}");
        log_message("Productos con precio: {$batch_productos_con_precio}");
        log_message("Productos sin precio: {$batch_productos_sin_precio}");
        log_message("Errores encontrados: {$batch_errores}");
        log_message("Tiempo de procesamiento: " . round($batch_duration, 2) . " segundos");
        
        // Actualizar estadísticas totales
        $productos_procesados += $batch_productos_procesados;
        $productos_con_precio += $batch_productos_con_precio;
        $productos_sin_precio += $batch_productos_sin_precio;
        $errores += $batch_errores;
        
        // Verificar memoria
        $memory_usage = memory_get_usage(true) / 1024 / 1024; // MB
        log_message("Uso de memoria después del lote: " . round($memory_usage, 2) . " MB");
        
        if ($memory_usage > 100) {
            log_message("⚠️ ADVERTENCIA: Alto uso de memoria detectado! Se recomienda reducir BATCH_SIZE.");
        }
        
        // Pequeña pausa entre lotes
        sleep(1);
    }
    
    // Estadísticas finales
    log_message("\n=== ESTADÍSTICAS FINALES DE SINCRONIZACIÓN ===");
    log_message("Lotes procesados: {$lotes_procesados}");
    log_message("Productos procesados: {$productos_procesados}");
    
    // Evitar división por cero
    if ($productos_procesados > 0) {
        log_message("Productos con precio: {$productos_con_precio} (" . round(($productos_con_precio/$productos_procesados)*100, 2) . "%)");
        log_message("Productos sin precio: {$productos_sin_precio} (" . round(($productos_sin_precio/$productos_procesados)*100, 2) . "%)");
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
        log_message("⚠️ El uso de memoria alcanzó niveles altos. Considerar reducir BATCH_SIZE para producción.");
    } else {
        log_message("✅ El uso de memoria se mantuvo en niveles aceptables.");
    }
    
} catch (\Exception $e) {
    log_message("ERROR CRÍTICO: " . $e->getMessage());
    log_message("Traza de la excepción: " . $e->getTraceAsString());
}

log_message("\nTest completado. Archivo de log guardado en: " . LOG_FILE);
echo "Finalización exitosa del test de sincronización de precios por lotes.\n";
