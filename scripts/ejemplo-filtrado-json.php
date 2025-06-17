<?php
/**
 * Script de ejemplo para el uso de filtrar-json-datos.php
 * 
 * Este script crea un archivo JSON de ejemplo y luego lo filtra
 */

// Crear archivo JSON de ejemplo
$datos_ejemplo = [
    [
        "Alto" => 25,
        "Ancho" => 18,
        "Autores" => "Autor Ejemplo",
        "Descripcion" => "Descripción larga del producto...",
        "FechaInactivo" => "2025-06-01",
        "ID_Categoria" => 4,
        "ID_Fabricante" => 1,
        "Id" => 5,
        "Nombre" => "ARTURO",
        "PorcentajeIVA" => 21.0,
        "ReferenciaBarras" => "9788415250128",
        "Tipo" => 2,
        "DatoSensible1" => "información confidencial",
        "DatoSensible2" => "otra información confidencial",
        "CampoNoEnPlantilla" => "Este campo no aparecerá en la salida"
    ],
    [
        "Alto" => 30,
        "Ancho" => 22,
        "Autores" => "Otro Autor",
        "Descripcion" => "Otra descripción...",
        "FechaInactivo" => "2025-07-15",
        "ID_Categoria" => 3,
        "ID_Fabricante" => 2,
        "Id" => 6,
        "Nombre" => "PRODUCTO B",
        "PorcentajeIVA" => 10.0,
        "ReferenciaBarras" => "9788411150222",
        "Tipo" => 1,
        "DatoExtra" => "Este campo tampoco aparecerá"
    ]
];

$archivo_ejemplo = __DIR__ . '/datos-ejemplo.json';
$archivo_salida = __DIR__ . '/datos-filtrados.json';

// Guardar datos de ejemplo
file_put_contents($archivo_ejemplo, json_encode($datos_ejemplo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "Archivo de ejemplo creado: {$archivo_ejemplo}\n";
echo "Ejecutando filtrado...\n\n";

// Ejecutar el script de filtrado
system("php " . __DIR__ . "/filtrar-json-datos.php {$archivo_ejemplo} {$archivo_salida}");

echo "\nPuedes revisar el archivo filtrado en: {$archivo_salida}\n";
?>
