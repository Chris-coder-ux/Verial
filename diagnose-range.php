<?php
/**
 * Script para diagnosticar rangos problemáticos desde línea de comandos
 * Uso: php diagnose-range.php 3201 3210 [deep]
 */

// Cargar WordPress
$wp_path = dirname(__FILE__);
while (!file_exists($wp_path . '/wp-config.php') && $wp_path !== '/') {
    $wp_path = dirname($wp_path);
}

if (file_exists($wp_path . '/wp-config.php')) {
    require_once $wp_path . '/wp-config.php';
} else {
    die("No se pudo encontrar wp-config.php\n");
}

// Verificar argumentos
if ($argc < 3) {
    echo "Uso: php diagnose-range.php <inicio> <fin> [deep]\n";
    echo "Ejemplo: php diagnose-range.php 3201 3210\n";
    echo "Ejemplo: php diagnose-range.php 3201 3210 deep\n";
    exit(1);
}

$inicio = intval($argv[1]);
$fin = intval($argv[2]);
$deep_analysis = isset($argv[3]) && $argv[3] === 'deep';

if ($inicio <= 0 || $fin <= 0 || $inicio > $fin) {
    echo "Error: Rango inválido. inicio=$inicio, fin=$fin\n";
    exit(1);
}

echo "=== DIAGNÓSTICO DE RANGO PROBLEMÁTICO ===\n";
echo "Rango: {$inicio}-{$fin}\n";
echo "Análisis profundo: " . ($deep_analysis ? 'SÍ' : 'NO') . "\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "==========================================\n\n";

try {
    // Cargar la clase Sync_Manager
    require_once dirname(__FILE__) . '/includes/Core/Sync_Manager.php';
    
    $sync_manager = \MiIntegracionApi\Core\Sync_Manager::get_instance();
    $result = $sync_manager->diagnose_problematic_range($inicio, $fin, $deep_analysis);
    
    echo "PRUEBAS REALIZADAS:\n";
    echo "- " . implode("\n- ", $result['tests_performed']) . "\n\n";
    
    echo "PROBLEMAS ENCONTRADOS (" . count($result['issues_found']) . "):\n";
    if (empty($result['issues_found'])) {
        echo "- Ningún problema detectado\n";
    } else {
        foreach ($result['issues_found'] as $issue) {
            echo "- [{$issue['type']}] {$issue['description']}\n";
            if (!empty($issue['details'])) {
                echo "  Detalles: " . json_encode($issue['details']) . "\n";
            }
        }
    }
    echo "\n";
    
    echo "RECOMENDACIONES (" . count($result['recommendations']) . "):\n";
    if (empty($result['recommendations'])) {
        echo "- Sin recomendaciones específicas\n";
    } else {
        foreach ($result['recommendations'] as $rec) {
            echo "- {$rec}\n";
        }
    }
    echo "\n";
    
    echo "RESUMEN TÉCNICO:\n";
    echo "- Tiempo total: " . round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 2) . " segundos\n";
    echo "- Memoria usada: " . round(memory_get_usage(true) / 1024 / 1024, 2) . " MB\n";
    echo "- Memoria pico: " . round(memory_get_peak_usage(true) / 1024 / 1024, 2) . " MB\n";
    
    if ($deep_analysis) {
        echo "\nDETALLES COMPLETOS (JSON):\n";
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
    
    echo "\n=== DIAGNÓSTICO COMPLETADO ===\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Archivo: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}
