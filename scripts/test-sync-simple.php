<?php
/**
 * Script simplificado para probar la lógica de sincronización por lotes
 * sin dependencia directa de WordPress
 */

// Configurar para mostrar errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Definir constantes
define('BATCH_SIZE', 5);
define('LOG_FILE', dirname(__FILE__) . '/sync-test-simple.log');

// Limpiar archivo de log
file_put_contents(LOG_FILE, "=== TEST SIMPLE DE SINCRONIZACIÓN POR LOTES ===\n" . date('Y-m-d H:i:s') . "\n\n");

// Función para escribir en el log
function log_message($message, $data = null) {
    $timestamp = date('Y-m-d H:i:s');
    $formatted = "[{$timestamp}] {$message}";
    
    if ($data !== null) {
        $formatted .= "\n" . print_r($data, true);
    }
    
    echo $formatted . "\n";
    file_put_contents(LOG_FILE, $formatted . "\n", FILE_APPEND);
}

// Clase simplificada de cliente API
class VerialApiClient {
    private $api_url;
    private $session_id;
    
    public function __construct($api_url, $session_id) {
        $this->api_url = rtrim($api_url, '/');
        $this->session_id = $session_id;
    }
    
    public function get_articulos($params = []) {
        // Construir la URL
        $url = $this->api_url . '/GetArticulosWS?x=' . $this->session_id;
        
        // Añadir parámetros adicionales
        if (!empty($params)) {
            foreach ($params as $key => $value) {
                $url .= "&{$key}={$value}";
            }
        }
        
        log_message("Obteniendo artículos desde: {$url}");
        
        // Realizar la solicitud
        $response = $this->hacer_peticion($url);
        return $response;
    }
    
    public function get_condiciones_tarifa($id_articulo, $id_cliente = 0) {
        // Construir la URL
        $url = $this->api_url . '/GetCondicionesTarifaWS?x=' . $this->session_id . 
               '&id_articulo=' . $id_articulo . '&id_cliente=' . $id_cliente;
        
        log_message("Obteniendo condiciones de tarifa desde: {$url}");
        
        // Realizar la solicitud
        $response = $this->hacer_peticion($url);
        return $response;
    }
    
    private function hacer_peticion($url) {
        // Realizar petición HTTP
        $response = file_get_contents($url);
        
        if ($response === false) {
            log_message("Error al conectar con {$url}");
            return null;
        }
        
        // Decodificar respuesta JSON
        $data = json_decode($response, true);
        
        // Si falló la decodificación
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_message("Error al decodificar respuesta JSON: " . json_last_error_msg());
            return null;
        }
        
        return $data;
    }
}

// Clase básica para procesar lotes
class BatchProcessor {
    private $api_client;
    private $batch_size;
    private $total_processed;
    private $errors;
    
    public function __construct($api_client, $batch_size = 10) {
        $this->api_client = $api_client;
        $this->batch_size = $batch_size;
        $this->total_processed = 0;
        $this->errors = 0;
    }
    
    public function process_batch($offset = 0) {
        log_message("Procesando lote con offset {$offset}, tamaño {$this->batch_size}");
        
        // Obtener datos para este lote
        $params = [
            'offset' => $offset,
            'limit' => $this->batch_size
        ];
        
        $productos = $this->api_client->get_articulos($params);
        
        if ($productos === null) {
            log_message("Error al obtener productos para el lote");
            return [
                'success' => false,
                'processed' => 0,
                'errors' => 1,
                'message' => 'Error al obtener productos'
            ];
        }
        
        // Normalizar estructura de productos
        $articulos_normalizados = [];
        
        if (isset($productos['Articulos']) && is_array($productos['Articulos'])) {
            log_message("Formato detectado: Array con clave Articulos");
            $articulos_normalizados = $productos['Articulos'];
        } elseif (isset($productos[0])) {
            log_message("Formato detectado: Array simple de productos");
            $articulos_normalizados = $productos;
        } else {
            if (isset($productos['Id']) || isset($productos['ReferenciaBarras'])) {
                log_message("Formato detectado: Producto único");
                $articulos_normalizados = [$productos];
            } else {
                log_message("Formato desconocido de respuesta");
                return [
                    'success' => false,
                    'processed' => 0,
                    'errors' => 1,
                    'message' => 'Formato desconocido de respuesta'
                ];
            }
        }
        
        $count = count($articulos_normalizados);
        log_message("Se encontraron {$count} productos en el lote actual");
        
        // Para cada producto, obtener precios y simular sincronización
        $processed = 0;
        $errors = 0;
        
        foreach ($articulos_normalizados as $producto) {
            // Verificar si el producto tiene ID
            $id_producto = $producto['Id'] ?? null;
            $nombre = $producto['Nombre'] ?? "Desconocido";
            $referencia = $producto['Referencia'] ?? ($producto['ReferenciaBarras'] ?? "Sin referencia");
            
            log_message("Procesando producto: {$nombre} (ID: {$id_producto}, REF: {$referencia})");
            
            if (empty($id_producto)) {
                log_message("Producto sin ID, saltando...");
                $errors++;
                continue;
            }
            
            try {
                // Obtener condiciones de tarifa para precio
                $condiciones = $this->api_client->get_condiciones_tarifa($id_producto);
                
                if ($condiciones === null) {
                    log_message("Error al obtener condiciones de tarifa para producto ID: {$id_producto}");
                    $errors++;
                    continue;
                }
                
                // Verificar la estructura de condiciones
                $precio_encontrado = $this->extraer_precio($condiciones, $producto);
                
                if ($precio_encontrado) {
                    log_message("✅ Precio actualizado correctamente para producto {$nombre}: " . 
                                ($producto['PVP'] ?? 'N/A') . 
                                (isset($producto['PVPOferta']) ? " (Oferta: " . $producto['PVPOferta'] . ")" : ""));
                } else {
                    log_message("❌ No se encontró precio para el producto {$nombre}");
                    $errors++;
                }
                
                // Simular mapeo a WooCommerce (simplificado)
                $wc_product = $this->map_to_wc($producto);
                
                log_message("✅ Producto mapeado a formato WooCommerce", $wc_product);
                
                // Incrementar contador de procesados
                $processed++;
                
                // Simular retardo para no sobrecargar la API
                usleep(100000); // 100ms
            } catch (Exception $e) {
                log_message("Error procesando producto {$id_producto}: " . $e->getMessage());
                $errors++;
            }
        }
        
        // Actualizar contadores
        $this->total_processed += $processed;
        $this->errors += $errors;
        
        // Retornar resultado del lote
        $result = [
            'success' => true,
            'processed' => $processed,
            'errors' => $errors,
            'total_processed' => $this->total_processed,
            'total_errors' => $this->errors,
            'done' => ($count < $this->batch_size) // Si recibimos menos productos que el tamaño del lote, hemos terminado
        ];
        
        log_message("Resultados del lote:", $result);
        
        return $result;
    }
    
    // Función para extraer precio de las condiciones de tarifa
    private function extraer_precio($condiciones, &$producto) {
        $precio_encontrado = false;
        
        if (is_array($condiciones)) {
            // Manejar la estructura específica que devuelve la API de Verial
            $condiciones_lista = [];
            
            // Verificar si las condiciones vienen en formato CondicionesTarifa
            if (isset($condiciones['CondicionesTarifa']) && is_array($condiciones['CondicionesTarifa'])) {
                log_message("Detectada estructura CondicionesTarifa");
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
                        // Actualizar precio en el producto
                        $producto['PVP'] = $condicion['Precio'];
                        $precio_encontrado = true;
                        
                        // Si hay descuento, calcular el precio final
                        if (isset($condicion['Dto']) && is_numeric($condicion['Dto']) && $condicion['Dto'] > 0) {
                            $descuento = ($condicion['Precio'] * $condicion['Dto']) / 100;
                            $precio_final = $condicion['Precio'] - $descuento;
                            $producto['PVPOferta'] = $precio_final;
                        }
                        
                        // Si hay descuento en euros por unidad
                        if (isset($condicion['DtoEurosXUd']) && is_numeric($condicion['DtoEurosXUd']) && $condicion['DtoEurosXUd'] > 0) {
                            $precio_final = $condicion['Precio'] - $condicion['DtoEurosXUd'];
                            $producto['PVPOferta'] = $precio_final;
                        }
                        
                        break; // Solo procesamos la primera condición válida
                    }
                }
            }
        }
        
        return $precio_encontrado;
    }
    
    // Función simplificada para mapear a formato WooCommerce
    private function map_to_wc($producto) {
        // Crear un array simplificado similar al que generaría MapProduct::verial_to_wc()
        $wc_data = [
            'sku' => $producto['Referencia'] ?? ($producto['ReferenciaBarras'] ?? ''),
            'name' => $producto['Nombre'] ?? '',
            'description' => $producto['Descripcion'] ?? '',
            'short_description' => $producto['DescripcionCorta'] ?? '',
            'regular_price' => $producto['PVP'] ?? '',
            'sale_price' => $producto['PVPOferta'] ?? '',
            'stock_quantity' => $producto['Stock'] ?? 0,
            'stock_status' => (isset($producto['Stock']) && $producto['Stock'] > 0) ? 'instock' : 'outofstock'
        ];
        
        return $wc_data;
    }
    
    // Obtener estadísticas
    public function get_stats() {
        return [
            'total_processed' => $this->total_processed,
            'total_errors' => $this->errors
        ];
    }
}

// Script principal
try {
    log_message("Iniciando test simplificado de sincronización por lotes");
    
    // Configurar cliente API (reemplazar con valores reales para la prueba)
    $api_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial';
    $session_id = 18; // Reemplazar con la sesión correcta
    
    log_message("Conectando a API en: {$api_url}");
    
    // Crear cliente API
    $api_client = new VerialApiClient($api_url, $session_id);
    
    // Crear procesador de lotes
    $batch_processor = new BatchProcessor($api_client, BATCH_SIZE);
    
    // Procesar varios lotes
    $offset = 0;
    $max_batches = 3;
    $batch_number = 1;
    $done = false;
    
    while (!$done && $batch_number <= $max_batches) {
        log_message("\n=== PROCESANDO LOTE #{$batch_number} (offset: {$offset}) ===");
        
        // Procesar lote
        $start_time = microtime(true);
        $result = $batch_processor->process_batch($offset);
        $duration = microtime(true) - $start_time;
        
        log_message("Tiempo de procesamiento: " . round($duration, 2) . " segundos");
        
        // Verificar si terminamos
        if (!$result['success']) {
            log_message("Error en el procesamiento del lote. Abortando.");
            break;
        }
        
        if (isset($result['done']) && $result['done']) {
            log_message("No hay más productos para procesar. Sincronización completada.");
            $done = true;
        }
        
        // Incrementar offset para siguiente lote
        $offset += BATCH_SIZE;
        $batch_number++;
        
        // Pequeña pausa entre lotes
        sleep(1);
    }
    
    // Mostrar estadísticas finales
    $stats = $batch_processor->get_stats();
    log_message("\n=== ESTADÍSTICAS FINALES ===");
    log_message("Total lotes procesados: " . ($batch_number - 1));
    log_message("Total productos procesados: " . $stats['total_processed']);
    log_message("Total errores: " . $stats['total_errors']);
    
} catch (Exception $e) {
    log_message("ERROR CRÍTICO: " . $e->getMessage());
}

log_message("\nTest completado. Archivo de log guardado en: " . LOG_FILE);
