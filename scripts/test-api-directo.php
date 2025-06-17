<?php
/**
 * Script de prueba para acceder directamente a la API de Verial y verificar los precios de productos
 */

// Configurar para mostrar errores
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "=== TEST DE CONEXIÓN DIRECTA A API VERIAL ===\n\n";

// Configuración
$api_url = 'http://x.verial.org:8000/WcfServiceLibraryVerial/';  // Reemplazar con la URL real
$sesion_wcf = 18;  // Reemplazar con el número de sesión correcto

// URL para obtener lista de productos
$get_articulos_url = $api_url . 'GetArticulosWS?x=' . $sesion_wcf;

echo "1. Obteniendo lista de artículos desde: {$get_articulos_url}\n";

// Función para hacer peticiones HTTP
function hacer_peticion($url) {
    $response = file_get_contents($url);
    if ($response === false) {
        echo "Error al conectar con {$url}\n";
        return null;
    }
    return json_decode($response, true);
}

// Obtener productos
$articulos = hacer_peticion($get_articulos_url);

if ($articulos === null) {
    die("No se pudieron obtener los artículos.\n");
}

// Determinar la estructura de la respuesta
$productos = [];
if (isset($articulos['Articulos']) && is_array($articulos['Articulos'])) {
    $productos = $articulos['Articulos'];
    echo "Formato detectado: Array con clave Articulos\n";
} elseif (isset($articulos[0])) {
    $productos = $articulos;
    echo "Formato detectado: Array simple de productos\n";
} else {
    // Si es un único objeto de producto (no en array)
    if (isset($articulos['Id']) || isset($articulos['ReferenciaBarras']) || isset($articulos['Nombre'])) {
        $productos = [$articulos];
        echo "Formato detectado: Producto único\n";
    } else {
        echo "Formato desconocido de respuesta, intentando buscar productos...\n";
        foreach ($articulos as $key => $value) {
            if (is_array($value) && !empty($value)) {
                if (isset($value[0]) && (isset($value[0]['Id']) || isset($value[0]['ReferenciaBarras']))) {
                    $productos = $value;
                    echo "Productos encontrados en clave: {$key}\n";
                    break;
                }
            }
        }
    }
}

if (empty($productos)) {
    die("No se encontraron productos en la respuesta.\n");
}

echo "Se encontraron " . count($productos) . " productos.\n";

// Seleccionar solo algunos productos para probar
$productos_test = array_slice($productos, 0, 3);

foreach ($productos_test as $i => $producto) {
    $id_producto = $producto['Id'] ?? "Desconocido";
    $nombre = $producto['Nombre'] ?? "Producto #{$i}";
    $precio_original = $producto['PVP'] ?? "No definido";
    
    echo "\n============================================\n";
    echo "PRODUCTO: {$nombre} (ID: {$id_producto})\n";
    echo "Precio original: {$precio_original}\n";
    
    // Obtener condiciones de tarifa para este producto
    $condiciones_url = $api_url . 'GetCondicionesTarifaWS?x=' . $sesion_wcf . '&id_articulo=' . $id_producto . '&id_cliente=0';
    echo "\nObteniendo condiciones de tarifa desde: {$condiciones_url}\n";
    
    $condiciones = hacer_peticion($condiciones_url);
    
    if ($condiciones === null) {
        echo "ERROR al obtener condiciones de tarifa.\n";
        continue;
    }
    
    echo "Respuesta de condiciones de tarifa recibida:\n";
    print_r($condiciones);
    
    // Analizar si hay información de precios
    $precio_encontrado = false;
    
    if (is_array($condiciones)) {
        // Verificar la estructura específica que devuelve la API de Verial
        if (isset($condiciones['CondicionesTarifa']) && is_array($condiciones['CondicionesTarifa'])) {
            echo "\nDetectada estructura CondicionesTarifa en la respuesta.\n";
            $condiciones_lista = $condiciones['CondicionesTarifa'];
            
            // Si es un único objeto y no un array de objetos (para prevenir error de índice)
            if (isset($condiciones_lista['Precio'])) {
                $precio_encontrado = true;
                echo "\nPrecio encontrado: {$condiciones_lista['Precio']}\n";
                
                if (isset($condiciones_lista['Dto']) && $condiciones_lista['Dto'] > 0) {
                    $descuento = ($condiciones_lista['Precio'] * $condiciones_lista['Dto']) / 100;
                    $precio_final = $condiciones_lista['Precio'] - $descuento;
                    echo "Descuento: {$condiciones_lista['Dto']}% ({$descuento}) => Precio final: {$precio_final}\n";
                }
                
                if (isset($condiciones_lista['DtoEurosXUd']) && $condiciones_lista['DtoEurosXUd'] > 0) {
                    $precio_final = $condiciones_lista['Precio'] - $condiciones_lista['DtoEurosXUd'];
                    echo "Descuento en euros por unidad: {$condiciones_lista['DtoEurosXUd']} => Precio final: {$precio_final}\n";
                }
            } else {
                // Procesar las condiciones de tarifa como array
                foreach ($condiciones_lista as $idx => $cond) {
                    if (isset($cond['Precio']) && is_numeric($cond['Precio'])) {
                        $precio_encontrado = true;
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
            }
        } else if (isset($condiciones[0])) {
            // Array de condiciones directo
            foreach ($condiciones as $idx => $cond) {
                if (isset($cond['Precio']) && is_numeric($cond['Precio'])) {
                    $precio_encontrado = true;
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
        echo "✅ PRECIO ENCONTRADO CORRECTAMENTE\n";
    }
}

echo "\n\nPrueba completada.\n";
