#!/usr/bin/env php
<?php
/**
 * Script para analizar archivos debug.log y extraer información relevante
 * 
 * Uso: php analizar-debug-log.php /ruta/al/debug.log [opciones]
 * 
 * Opciones:
 *  --limit=N            Limitar a N líneas (por defecto 100)
 *  --desde="YYYY-MM-DD" Filtrar desde una fecha específica
 *  --hasta="YYYY-MM-DD" Filtrar hasta una fecha específica
 *  --tipo=error,warning Filtrar por tipo de mensaje (error,warning,notice,deprecated)
 *  --plugin=nombre      Filtrar por nombre de plugin
 *  --endpoint=nombre    Filtrar por nombre de endpoint API (GetCategoriasWS, etc.)
 *  --buscar="texto"     Buscar un texto específico
 *  --json               Mostrar resultado en formato JSON
 *  --stats              Mostrar estadísticas
 */

// Configuración por defecto
$config = [
    'archivo'  => null,
    'limit'    => 100,
    'desde'    => null,
    'hasta'    => null,
    'tipo'     => [],
    'plugin'   => 'mi-integracion-api',
    'endpoint' => [],
    'buscar'   => [],
    'json'     => false,
    'stats'    => false,
];

// Endpoints API comunes en la aplicación
$endpointsComunes = [
    'GetCategoriasWS',
    'GetFabricantesWS',
    'GetArticulosWS',
    'GetNumArticulosWS',
    'GetStockArticuloWS',
    'GetPrecioArticuloWS'
];

// Patrones para identificar tipos de mensajes
$patronesMensajes = [
    'error'      => '/(?:PHP Fatal error|PHP Parse error|PHP Error|Fatal error|Error:|ERROR:)/i',
    'warning'    => '/(?:PHP Warning|Warning:|WARNING:)/i',
    'notice'     => '/(?:PHP Notice|Notice:|NOTICE:)/i',
    'deprecated' => '/(?:PHP Deprecated|Deprecated:|DEPRECATED:)/i',
];

// Procesar argumentos
$options = getopt('', ['limit::', 'desde::', 'hasta::', 'tipo::', 'plugin::', 'endpoint::', 'buscar::', 'json', 'stats']);

if ($argc < 2 || !file_exists($argv[1])) {
    echo "Error: Debe proporcionar una ruta válida al archivo debug.log\n";
    echo "Uso: php analizar-debug-log.php /ruta/al/debug.log [opciones]\n";
    exit(1);
}

$config['archivo'] = $argv[1];

// Procesar opciones
if (isset($options['limit'])) {
    $config['limit'] = (int)$options['limit'];
}

if (isset($options['desde'])) {
    $config['desde'] = $options['desde'];
}

if (isset($options['hasta'])) {
    $config['hasta'] = $options['hasta'];
}

if (isset($options['tipo'])) {
    $config['tipo'] = explode(',', $options['tipo']);
}

if (isset($options['plugin'])) {
    $config['plugin'] = $options['plugin'];
}

if (isset($options['endpoint'])) {
    $config['endpoint'] = explode(',', $options['endpoint']);
} else {
    $config['endpoint'] = $endpointsComunes;
}

if (isset($options['buscar'])) {
    $config['buscar'] = explode(',', $options['buscar']);
}

if (isset($options['json'])) {
    $config['json'] = true;
}

if (isset($options['stats'])) {
    $config['stats'] = true;
}

// Clase para analizar el archivo de log
class LogAnalyzer {
    private $config;
    private $patronesMensajes;
    private $resultados = [];
    private $stats = [
        'total_lineas' => 0,
        'errores' => 0,
        'warnings' => 0,
        'notices' => 0,
        'deprecated' => 0,
        'endpoints' => [],
        'archivos_afectados' => [],
    ];

    public function __construct($config, $patronesMensajes) {
        $this->config = $config;
        $this->patronesMensajes = $patronesMensajes;
    }

    public function analizar() {
        $archivo = $this->config['archivo'];
        $lineas = $this->leerUltimasLineas($archivo, 100000); // Leemos un número grande de líneas para procesarlas
        
        foreach ($lineas as $linea) {
            $this->stats['total_lineas']++;
            
            // Procesar la línea solo si cumple con los filtros
            if ($this->cumpleFiltros($linea)) {
                $tipo = $this->identificarTipo($linea);
                $fecha = $this->extraerFecha($linea);
                $archivo = $this->extraerArchivo($linea);
                $endpoints = $this->encontrarEndpoints($linea);
                
                // Incrementar estadísticas
                if ($tipo) {
                    // Asegurarnos que existe la clave antes de incrementar
                    if (!isset($this->stats[$tipo . 's'])) {
                        $this->stats[$tipo . 's'] = 0;
                    }
                    $this->stats[$tipo . 's']++;
                }
                
                if ($archivo && !in_array($archivo, $this->stats['archivos_afectados'])) {
                    $this->stats['archivos_afectados'][] = $archivo;
                }
                
                foreach ($endpoints as $endpoint) {
                    if (!isset($this->stats['endpoints'][$endpoint])) {
                        $this->stats['endpoints'][$endpoint] = 0;
                    }
                    $this->stats['endpoints'][$endpoint]++;
                }
                
                $this->resultados[] = [
                    'fecha' => $fecha,
                    'tipo' => $tipo,
                    'archivo' => $archivo,
                    'endpoints' => $endpoints,
                    'mensaje' => trim($linea),
                ];
            }
        }
        
        // Limitar resultados si es necesario
        if (count($this->resultados) > $this->config['limit']) {
            $this->resultados = array_slice($this->resultados, -$this->config['limit']);
        }
    }

    private function cumpleFiltros($linea) {
        // Verificar plugin
        if (!empty($this->config['plugin']) && stripos($linea, $this->config['plugin']) === false) {
            return false;
        }
        
        // Verificar tipo
        if (!empty($this->config['tipo'])) {
            $coincideTipo = false;
            foreach ($this->config['tipo'] as $tipo) {
                if (preg_match($this->patronesMensajes[$tipo], $linea)) {
                    $coincideTipo = true;
                    break;
                }
            }
            if (!$coincideTipo) {
                return false;
            }
        }
        
        // Verificar endpoints
        if (!empty($this->config['endpoint'])) {
            $coincideEndpoint = false;
            foreach ($this->config['endpoint'] as $endpoint) {
                if (stripos($linea, $endpoint) !== false) {
                    $coincideEndpoint = true;
                    break;
                }
            }
            if (!$coincideEndpoint && !$this->tieneErrorFatal($linea)) {
                return false;
            }
        }
        
        // Verificar texto de búsqueda
        if (!empty($this->config['buscar'])) {
            $coincideBusqueda = false;
            foreach ($this->config['buscar'] as $buscar) {
                if (stripos($linea, $buscar) !== false) {
                    $coincideBusqueda = true;
                    break;
                }
            }
            if (!$coincideBusqueda) {
                return false;
            }
        }
        
        return true;
    }

    private function tieneErrorFatal($linea) {
        return preg_match('/(?:PHP Fatal error|Error:)/i', $linea);
    }

    private function identificarTipo($linea) {
        foreach ($this->patronesMensajes as $tipo => $patron) {
            if (preg_match($patron, $linea)) {
                return $tipo;
            }
        }
        return null;
    }

    private function extraerFecha($linea) {
        // Formato común: [16-Jun-2025 10:15:30 UTC] 
        if (preg_match('/\[(\d{2}-\w{3}-\d{4} \d{2}:\d{2}:\d{2} \w+)\]/', $linea, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function extraerArchivo($linea) {
        // Formato común: in /path/to/file.php on line 123
        if (preg_match('/in (\/[^ ]+) on line (\d+)/', $linea, $matches)) {
            return $matches[1] . ':' . $matches[2];
        }
        return null;
    }

    private function encontrarEndpoints($linea) {
        $endpoints = [];
        foreach ($this->config['endpoint'] as $endpoint) {
            if (stripos($linea, $endpoint) !== false) {
                $endpoints[] = $endpoint;
            }
        }
        return $endpoints;
    }

    private function leerUltimasLineas($archivo, $numLineas) {
        $filesize = filesize($archivo);
        $maxTamanio = 100 * 1024 * 1024; // 100 MB máximo
        
        if ($filesize > $maxTamanio) {
            // Solo leer la última parte del archivo si es muy grande
            $handle = fopen($archivo, 'r');
            fseek($handle, -min($maxTamanio, $filesize), SEEK_END);
            $contenido = fread($handle, min($maxTamanio, $filesize));
            fclose($handle);
        } else {
            $contenido = file_get_contents($archivo);
        }
        
        $lineas = explode("\n", $contenido);
        
        // Eliminar líneas vacías
        $lineas = array_filter($lineas, function($linea) {
            return !empty(trim($linea));
        });
        
        // Si hay más líneas que el límite, tomar las últimas
        if (count($lineas) > $numLineas) {
            $lineas = array_slice($lineas, -$numLineas);
        }
        
        return $lineas;
    }

    public function getResultados() {
        return $this->resultados;
    }

    public function getStats() {
        return $this->stats;
    }
}

// Ejecutar análisis
$analizador = new LogAnalyzer($config, $patronesMensajes);
$analizador->analizar();
$resultados = $analizador->getResultados();
$stats = $analizador->getStats();

// Mostrar resultados
if ($config['json']) {
    $output = [
        'config' => $config,
        'resultados' => $resultados
    ];
    
    if ($config['stats']) {
        $output['stats'] = $stats;
    }
    
    echo json_encode($output, JSON_PRETTY_PRINT);
} else {
    echo "=== Análisis de debug.log ===\n";
    echo "Archivo: {$config['archivo']}\n";
    echo "Líneas analizadas: {$stats['total_lineas']}\n";
    echo "Líneas encontradas: " . count($resultados) . "\n\n";
    
    if ($config['stats']) {
        echo "=== Estadísticas ===\n";
        echo "Errores: {$stats['errores']}\n";
        echo "Warnings: {$stats['warnings']}\n";
        echo "Notices: {$stats['notices']}\n";
        echo "Deprecated: {$stats['deprecated']}\n";
        
        if (!empty($stats['endpoints'])) {
            echo "\nEndpoints encontrados:\n";
            foreach ($stats['endpoints'] as $endpoint => $count) {
                echo "- $endpoint: $count veces\n";
            }
        }
        
        if (!empty($stats['archivos_afectados'])) {
            echo "\nArchivos afectados:\n";
            foreach ($stats['archivos_afectados'] as $archivo) {
                echo "- $archivo\n";
            }
        }
        
        echo "\n";
    }
    
    echo "=== Mensajes ===\n";
    foreach ($resultados as $i => $resultado) {
        echo "[" . ($i + 1) . "] ";
        if ($resultado['fecha']) {
            echo "[{$resultado['fecha']}] ";
        }
        
        if ($resultado['tipo']) {
            echo "[" . strtoupper($resultado['tipo']) . "] ";
        }
        
        echo $resultado['mensaje'] . "\n";
        
        if ($resultado['archivo']) {
            echo "    - Archivo: {$resultado['archivo']}\n";
        }
        
        if (!empty($resultado['endpoints'])) {
            echo "    - Endpoints: " . implode(", ", $resultado['endpoints']) . "\n";
        }
        
        echo "\n";
    }
}
