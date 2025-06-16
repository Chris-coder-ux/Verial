<?php
/**
 * Script de prueba para verificar que get_fabricantes funciona
 */

// Simular el entorno de WordPress
define('ABSPATH', dirname(__FILE__) . '/');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Incluir el autoloader
require_once __DIR__ . '/includes/Autoloader.php';

// Inicializar el autoloader
\MiIntegracionApi\Autoloader::init();

// Probar ApiConnector
try {
    $api_connector = new \MiIntegracionApi\Core\ApiConnector();
    
    echo "✓ ApiConnector creado correctamente\n";
    
    // Verificar que el método existe
    if (method_exists($api_connector, 'get_fabricantes')) {
        echo "✓ Método get_fabricantes existe\n";
        
        // Intentar llamar al método (aunque puede fallar por falta de configuración)
        try {
            $result = $api_connector->get_fabricantes();
            echo "✓ Método get_fabricantes se ejecutó sin errores fatales\n";
            echo "Resultado: " . print_r($result, true) . "\n";
        } catch (Exception $e) {
            echo "⚠ El método se ejecutó pero con error: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✗ Método get_fabricantes NO existe\n";
    }
    
} catch (Exception $e) {
    echo "✗ Error al crear ApiConnector: " . $e->getMessage() . "\n";
}
