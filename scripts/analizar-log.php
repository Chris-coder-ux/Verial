<?php
/**
 * Script de análisis de logs para Mi-Integracion-API
 * 
 * Este script analiza el archivo de log y extrae las entradas relevantes
 * sobre errores de sincronización y llamadas API
 */

// Configuración
$archivo_log = '/home/christian/Descargas/mi-integracion-2025-06-14.log';
$archivo_salida = '/home/christian/Escritorio/mi-integracion-api/log-analisis-'.date('Y-m-d-His').'.txt';

// Opciones de análisis
$buscar_solo_errores = false;       // Si es true, solo busca errores; si es false, incluye warnings e info relevantes
$max_lineas_contexto = 5;           // Número de líneas de contexto antes y después
$excluir_debug_comun = true;        // Excluir mensajes de debug comunes
$incluir_respuestas_api = true;     // Incluir todas las respuestas API
$agrupar_por_transaccion = true;    // Agrupar mensajes por transaction_id

// Términos de búsqueda para errores críticos
$terminos_error = [
    'error',
    'excepción',
    'exception',
    'warning',
    'alert',
    'sync_products_batch',
    'ApiConnector',
    'get_articulos',
    'Error en la API',
    'Error al inicializar',
    'MakeRequestWithRetry',
    'No se obtuvieron productos',
    'respuesta de API',
    'Respuesta recibida',
    'sin SKU',
    'sincronización',
    'Articulo',
    'InfoError'
];

// Verificar si el archivo existe
if (!file_exists($archivo_log)) {
    die("Error: El archivo de log no existe en la ruta especificada.\n");
}

// Abrir archivos
$log = fopen($archivo_log, 'r');
$salida = fopen($archivo_salida, 'w');

if (!$log || !$salida) {
    die("Error: No se pudieron abrir los archivos.\n");
}

// Encabezado
fwrite($salida, "=== ANÁLISIS DE LOG: ERRORES DE SINCRONIZACIÓN ===\n");
fwrite($salida, "Fecha de análisis: " . date('Y-m-d H:i:s') . "\n");
fwrite($salida, "Archivo analizado: " . $archivo_log . "\n\n");

// Estadísticas
$total_lineas = 0;
$lineas_error = 0;
$ultima_linea_filtrada = '';
$context_lines = $max_lineas_contexto;
$context_buffer = [];
$in_context = false;
$context_count = 0;
$transacciones = [];
$errores_por_tipo = [];
$endpoints_llamados = [];
$ultimas_transacciones = [];

// Extraer transaction_id de una línea
function extraer_transaction_id($linea) {
    if (preg_match('/transaction_id":"([^"]+)/', $linea, $matches)) {
        return $matches[1];
    }
    return null;
}

// Extraer timestamp de la línea
function extraer_timestamp($linea) {
    if (preg_match('/\[(\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2})\]/', $linea, $matches)) {
        return $matches[1];
    }
    return null;
}

// Extraer nivel de log (info, error, warning, etc.)
function extraer_nivel($linea) {
    if (preg_match('/\[\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\]\s\[(\w+)\]/', $linea, $matches)) {
        return strtolower($matches[1]);
    }
    return null;
}

// Extraer endpoint API si está presente
function extraer_endpoint($linea) {
    if (preg_match('/"endpoint(?:_final)?":"([^"]+)"/', $linea, $matches)) {
        return $matches[1];
    }
    return null;
}

// Determinar si una línea debe ser ignorada
function debe_ignorar($linea, $nivel) {
    global $excluir_debug_comun;
    
    if (!$excluir_debug_comun) {
        return false;
    }
    
    // Ignorar mensajes de debug comunes
    $ignorar_patrones = [
        'CertificateCache',
        'validateCache',
        'Assets hooks registered',
        'Directorio de certificados creado',
        'validateCache\(\) ejecutado'
    ];
    
    if ($nivel === 'debug') {
        foreach ($ignorar_patrones as $patron) {
            if (stripos($linea, $patron) !== false) {
                return true;
            }
        }
    }
    
    return false;
}

// Leer línea por línea
while (($linea = fgets($log)) !== false) {
    $total_lineas++;
    $es_relevante = false;
    $nivel = extraer_nivel($linea);
    
    // Si debe ignorarse esta línea
    if (debe_ignorar($linea, $nivel)) {
        continue;
    }
    
    // Extraer datos útiles
    $transaction_id = extraer_transaction_id($linea);
    $timestamp = extraer_timestamp($linea);
    $endpoint = extraer_endpoint($linea);
    
    // Registrar endpoints llamados
    if ($endpoint && !in_array($endpoint, $endpoints_llamados)) {
        $endpoints_llamados[] = $endpoint;
    }
    
    // Registrar transacciones vistas
    if ($transaction_id) {
        if (!isset($transacciones[$transaction_id])) {
            $transacciones[$transaction_id] = [
                'timestamp' => $timestamp,
                'errores' => 0,
                'warnings' => 0
            ];
        }
        
        if ($nivel == 'error') {
            $transacciones[$transaction_id]['errores']++;
        } elseif ($nivel == 'warning') {
            $transacciones[$transaction_id]['warnings']++;
        }
        
        // Mantener las últimas 10 transacciones
        if (!in_array($transaction_id, $ultimas_transacciones)) {
            array_unshift($ultimas_transacciones, $transaction_id);
            if (count($ultimas_transacciones) > 10) {
                array_pop($ultimas_transacciones);
            }
        }
    }
    
    // Determinar si esta línea contiene algún término de error o es un nivel de log relevante
    if ($nivel == 'error' || ($nivel == 'warning' && !$buscar_solo_errores)) {
        $es_relevante = true;
    } else {
        foreach ($terminos_error as $termino) {
            if (stripos($linea, $termino) !== false) {
                $es_relevante = true;
                break;
            }
        }
        
        // Si incluir_respuestas_api es true, incluir todas las respuestas API
        if (!$es_relevante && $incluir_respuestas_api && 
            (stripos($linea, 'respuesta') !== false || stripos($linea, 'response') !== false)) {
            $es_relevante = true;
        }
    }
    
    // Registrar errores por tipo
    if ($nivel == 'error') {
        $error_tipo = "Otro error";
        
        if (stripos($linea, 'API') !== false) {
            $error_tipo = "Error de API";
        } elseif (stripos($linea, 'SKU') !== false) {
            $error_tipo = "Error de SKU";
        } elseif (stripos($linea, 'sincronización') !== false) {
            $error_tipo = "Error de sincronización";
        } elseif (stripos($linea, 'WooCommerce') !== false) {
            $error_tipo = "Error de WooCommerce";
        } elseif (stripos($linea, 'HTTP') !== false || stripos($linea, 'CURL') !== false) {
            $error_tipo = "Error de HTTP/conexión";
        }
        
        if (!isset($errores_por_tipo[$error_tipo])) {
            $errores_por_tipo[$error_tipo] = 1;
        } else {
            $errores_por_tipo[$error_tipo]++;
        }
    }
    
    // Manejar el buffer de contexto
    if ($es_relevante) {
        // Si es un error o mensaje relevante, escribe el buffer de contexto previo
        if (!$in_context) {
            fwrite($salida, "\n--- NUEVO MENSAJE RELEVANTE " . ($transaction_id ? "(Transacción: $transaction_id)" : "") . " ---\n");
            foreach ($context_buffer as $ctx_line) {
                fwrite($salida, "CONTEXTO: " . $ctx_line);
            }
        }
        
        // Escribe la línea relevante
        $prefix = ($nivel == 'error') ? "ERROR: " : (($nivel == 'warning') ? "AVISO: " : "INFO: ");
        fwrite($salida, $prefix . $linea);
        $lineas_error++;
        $ultima_linea_filtrada = $linea;
        
        // Establece el modo de contexto
        $in_context = true;
        $context_count = 0;
        $context_buffer = []; // Limpiar el buffer para el contexto posterior
    } else if ($in_context && $context_count < $context_lines) {
        // Si estamos en modo contexto después de un mensaje relevante
        fwrite($salida, "CONTEXTO: " . $linea);
        $context_count++;
        
        if ($context_count >= $context_lines) {
            $in_context = false; // Finaliza el modo contexto
            fwrite($salida, "--- FIN DE CONTEXTO ---\n\n");
        }
    } else {
        // Añade la línea al buffer de contexto circular
        $context_buffer[] = $linea;
        if (count($context_buffer) > $context_lines) {
            array_shift($context_buffer); // Mantener solo las últimas N líneas
        }
        $in_context = false;
    }
}

// Estadísticas
fwrite($salida, "\n=== RESUMEN DE ANÁLISIS ===\n");
fwrite($salida, "Total de líneas analizadas: " . $total_lineas . "\n");
fwrite($salida, "Líneas relevantes encontradas: " . $lineas_error . "\n\n");

// Estadísticas de errores por tipo
if (!empty($errores_por_tipo)) {
    fwrite($salida, "=== ERRORES POR TIPO ===\n");
    foreach ($errores_por_tipo as $tipo => $cantidad) {
        fwrite($salida, "$tipo: $cantidad\n");
    }
    fwrite($salida, "\n");
}

// Estadísticas de endpoints llamados
if (!empty($endpoints_llamados)) {
    fwrite($salida, "=== ENDPOINTS API LLAMADOS ===\n");
    foreach ($endpoints_llamados as $endpoint) {
        fwrite($salida, "- $endpoint\n");
    }
    fwrite($salida, "\n");
}

// Ultimas 10 transacciones con estadísticas
if (!empty($ultimas_transacciones)) {
    fwrite($salida, "=== ÚLTIMAS 10 TRANSACCIONES ===\n");
    foreach ($ultimas_transacciones as $trans_id) {
        if (isset($transacciones[$trans_id])) {
            $trans = $transacciones[$trans_id];
            fwrite($salida, "$trans_id (Hora: {$trans['timestamp']}) - Errores: {$trans['errores']}, Warnings: {$trans['warnings']}\n");
        }
    }
    fwrite($salida, "\n");
}

// Identificar principales problemas
fwrite($salida, "=== DIAGNÓSTICO ===\n");
if (!empty($errores_por_tipo)) {
    arsort($errores_por_tipo);
    $principal_error = key($errores_por_tipo);
    fwrite($salida, "Principal tipo de error: $principal_error ({$errores_por_tipo[$principal_error]} ocurrencias)\n");
}

// Si hay errores de API o conexión, mostrar un diagnóstico específico
if (isset($errores_por_tipo["Error de API"]) || isset($errores_por_tipo["Error de HTTP/conexión"])) {
    fwrite($salida, "Posible problema de conexión con la API de Verial. Verificar credenciales y conectividad.\n");
}

// Si hay errores de SKU
if (isset($errores_por_tipo["Error de SKU"])) {
    fwrite($salida, "Problemas con SKUs de productos. Es posible que los productos de Verial no incluyan SKU.\n");
    fwrite($salida, "RECOMENDACIÓN: Utilizar la generación automática de SKUs implementada.\n");
}

// Si hay errores de sincronización
if (isset($errores_por_tipo["Error de sincronización"])) {
    fwrite($salida, "Problemas durante el proceso de sincronización. Verificar estructura de datos de respuesta API.\n");
}

// Si hay errores de WooCommerce
if (isset($errores_por_tipo["Error de WooCommerce"])) {
    fwrite($salida, "Problemas con WooCommerce. Verificar que WooCommerce esté correctamente configurado.\n");
}

// Cerrar archivos
fclose($log);
fclose($salida);

echo "Análisis completo. Resultados guardados en: " . $archivo_salida . "\n";
echo "Se encontraron " . $lineas_error . " líneas relevantes de " . $total_lineas . " líneas totales.\n";

// Si el archivo de salida existe y tiene un tamaño razonable, mostrarlo
if (file_exists($archivo_salida) && filesize($archivo_salida) < 10 * 1024 * 1024) {  // Limitar a 10MB
    echo "\n=== RESUMEN DEL ANÁLISIS ===\n";
    passthru("tail -n 20 " . escapeshellarg($archivo_salida));
}

