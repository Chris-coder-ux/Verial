<?php
/**
 * Script de prueba para verificar la obtención de precios de productos desde Verial
 * 
 * Este script prueba la funcionalidad de obtención de condiciones de tarifa
 * y verifica que se están obteniendo correctamente los precios de los productos.
 */

// Configurar para mostrar errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Determinar la ruta base del plugin
$plugin_dir = dirname(__DIR__);

// Cargar Composer autoloader
if (file_exists($plugin_dir . '/vendor/autoload.php')) {
    require_once $plugin_dir . '/vendor/autoload.php';
} else {
    die("Error: No se pudo cargar el autoloader de Composer\n");
}

// Incluir el archivo principal del plugin que inicializa la mayoría de las clases
if (file_exists($plugin_dir . '/mi-integracion-api.php')) {
    require_once $plugin_dir . '/mi-integracion-api.php';
} else {
    die("Error: No se pudo cargar el archivo principal del plugin\n");
}

echo "=== TEST DE OBTENCIÓN DE PRECIOS DE PRODUCTOS ===\n\n";

// Crear una instancia del logger para seguimiento
$logger = new \MiIntegracionApi\Helpers\Logger('test-precio-producto');
$logger->info('Iniciando prueba de obtención de precios de productos');

// Obtener el conector API
function get_api_connector() {
    $logger = new \MiIntegracionApi\Helpers\Logger('test-precio-producto');
    $api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
    
    // Obtener la URL de API y sesión WCF desde la configuración
    $config = \MiIntegracionApi\Core\Config_Manager::get_instance();
    $api_url = $config->get('api_url');
    $sesion_wcf = $config->get('session_number');
    
    if (empty($api_url)) {
        echo "ERROR: URL de API no configurada en el plugin.\n";
        exit(1);
    }
    
    if (empty($sesion_wcf)) {
        echo "ERROR: Número de sesión no configurado en el plugin.\n";
        exit(1);
    }
    
    // Configurar el conector API
    $api_connector->set_api_url($api_url);
    $api_connector->set_sesion_wcf($sesion_wcf);
    
    return $api_connector;
}

// Obtener algunos productos para prueba
function get_test_products($api_connector, $limit = 5) {
    echo "Obteniendo productos de prueba...\n";
    
    $productos = $api_connector->get_articulos();
    
    if (is_wp_error($productos)) {
        echo "ERROR: No se pudieron obtener productos: " . $productos->get_error_message() . "\n";
        return [];
    }
    
    // Normalizar la estructura de productos basada en el formato de respuesta
    $articulos_normalizados = [];
    
    if (isset($productos['Articulos']) && is_array($productos['Articulos'])) {
        $articulos_normalizados = $productos['Articulos'];
    } elseif (isset($productos[0])) {
        $articulos_normalizados = $productos;
    } else {
        if (isset($productos['Id']) || isset($productos['ReferenciaBarras']) || isset($productos['Nombre'])) {
            $articulos_normalizados = [$productos];
        } else {
            foreach ($productos as $key => $value) {
                if (is_array($value) && !empty($value)) {
                    if (isset($value[0]) && (isset($value[0]['Id']) || isset($value[0]['ReferenciaBarras']))) {
                        $articulos_normalizados = $value;
                        break;
                    }
                }
            }
        }
    }
    
    // Limitar a los primeros N productos
    return array_slice($articulos_normalizados, 0, $limit);
}

// Probar la obtención de condiciones de tarifa para un producto
function test_condiciones_tarifa($api_connector, $producto) {
    if (!isset($producto['Id']) || empty($producto['Id'])) {
        echo "ERROR: Producto sin ID válido\n";
        return false;
    }
    
    $id_articulo = $producto['Id'];
    $nombre = $producto['Nombre'] ?? 'Sin nombre';
    $precio_original = $producto['PVP'] ?? 'No definido';
    
    echo "\n=== PRODUCTO: {$nombre} (ID: {$id_articulo}) ===\n";
    echo "Precio original: {$precio_original}\n";
    
    echo "Obteniendo condiciones de tarifa...\n";
    $condiciones = $api_connector->get_condiciones_tarifa($id_articulo);
    
    if (is_wp_error($condiciones)) {
        echo "ERROR al obtener condiciones de tarifa: " . $condiciones->get_error_message() . "\n";
        return false;
    }
    
    echo "Respuesta de condiciones de tarifa recibida:\n";
    print_r($condiciones);
    
    // Analizar si hay información de precios
    $precio_encontrado = false;
    $condicion_precio = null;
    
    if (is_array($condiciones)) {
        if (isset($condiciones[0])) {
            // Array de condiciones
            foreach ($condiciones as $idx => $cond) {
                if (isset($cond['Precio']) && is_numeric($cond['Precio'])) {
                    $precio_encontrado = true;
                    $condicion_precio = $cond;
                    echo "\nPrecio encontrado en condición #{$idx}: {$cond['Precio']}\n";
                    
                    if (isset($cond['Dto']) && $cond['Dto'] > 0) {
                        $descuento = ($cond['Precio'] * $cond['Dto']) / 100;
                        $precio_final = $cond['Precio'] - $descuento;
                        echo "Descuento: {$cond['Dto']}% ({$descuento}) => Precio final: {$precio_final}\n";
                    }
                    
                    if (isset($cond['DtoEurosXUd']) && $cond['DtoEurosXUd'] > 0) {
                        $precio_final = $cond['Precio'] - $cond['DtoEurosXUd'];
                        echo "Descuento en euros por unidad: {$cond['DtoEurosXUd']} => Precio final: {$precio_final}\n";
                    }
                }
            }
        } else if (isset($condiciones['Precio']) && is_numeric($condiciones['Precio'])) {
            // Condición única
            $precio_encontrado = true;
            $condicion_precio = $condiciones;
            echo "\nPrecio encontrado: {$condiciones['Precio']}\n";
            
            if (isset($condiciones['Dto']) && $condiciones['Dto'] > 0) {
                $descuento = ($condiciones['Precio'] * $condiciones['Dto']) / 100;
                $precio_final = $condiciones['Precio'] - $descuento;
                echo "Descuento: {$condiciones['Dto']}% ({$descuento}) => Precio final: {$precio_final}\n";
            }
            
            if (isset($condiciones['DtoEurosXUd']) && $condiciones['DtoEurosXUd'] > 0) {
                $precio_final = $condiciones['Precio'] - $condiciones['DtoEurosXUd'];
                echo "Descuento en euros por unidad: {$condiciones['DtoEurosXUd']} => Precio final: {$precio_final}\n";
            }
        }
    }
    
    if (!$precio_encontrado) {
        echo "⚠️ NO SE ENCONTRÓ INFORMACIÓN DE PRECIO EN LAS CONDICIONES DE TARIFA\n";
    } else {
        echo "\n✅ PRECIO ENCONTRADO CORRECTAMENTE\n";
        
        // Comparación con precio original
        if (is_numeric($precio_original)) {
            $precio_condicion = $condicion_precio['Precio'];
            if ($precio_original != $precio_condicion) {
                echo "⚠️ NOTA: El precio en las condiciones ({$precio_condicion}) es diferente del precio original del producto ({$precio_original})\n";
            } else {
                echo "✅ El precio coincide con el original del producto\n";
            }
        }
    }
    
    return $precio_encontrado;
}

try {
    // Obtener el conector API
    $api_connector = get_api_connector();
    echo "Conector API inicializado correctamente.\n";
    
    // Obtener productos de prueba
    $productos = get_test_products($api_connector, 3);
    
    if (empty($productos)) {
        echo "No se encontraron productos para probar.\n";
        exit(1);
    }
    
    echo "Se encontraron " . count($productos) . " productos para la prueba.\n";
    
    // Intentar obtener precios para cada producto
    $resultados = [];
    foreach ($productos as $i => $producto) {
        $success = test_condiciones_tarifa($api_connector, $producto);
        $resultados[] = [
            'id' => $producto['Id'] ?? "Producto #{$i}",
            'nombre' => $producto['Nombre'] ?? 'Sin nombre',
            'exito' => $success
        ];
    }
    
    // Resumen final
    echo "\n\n=== RESUMEN DE RESULTADOS ===\n";
    $exitos = 0;
    foreach ($resultados as $resultado) {
        $status = $resultado['exito'] ? '✅' : '❌';
        echo "{$status} {$resultado['nombre']} (ID: {$resultado['id']})\n";
        if ($resultado['exito']) $exitos++;
    }
    
    echo "\nPrueba completada: {$exitos} de " . count($resultados) . " productos obtuvieron condiciones de tarifa exitosamente.\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Traza: " . $e->getTraceAsString() . "\n";
    exit(1);
}
