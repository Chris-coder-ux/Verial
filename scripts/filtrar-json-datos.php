<?php
/**
 * Script para filtrar datos JSON según una plantilla
 * 
 * Uso: php filtrar-json-datos.php /ruta/al/archivo-datos.json /ruta/al/archivo-salida.json
 */

if ($argc < 3) {
    echo "Uso: php filtrar-json-datos.php /ruta/al/archivo-datos.json /ruta/al/archivo-salida.json\n";
    exit(1);
}

$archivo_origen = $argv[1];
$archivo_salida = $argv[2];

if (!file_exists($archivo_origen)) {
    echo "Error: El archivo {$archivo_origen} no existe.\n";
    exit(1);
}

// Plantilla para filtrado - estructura modelo de los datos a mantener
$plantilla = [
    "Alto" => 0,
    "Ancho" => 0,
    "Autores" => null,
    "Aux1" => "",
    "Aux2" => "",
    "Aux3" => "",
    "Aux4" => "",
    "Aux5" => "",
    "Aux6" => "",
    "CamposConfigurables" => null,
    "DecPrecioVentas" => 4,
    "DecUdsVentas" => 0,
    "Descripcion" => "",
    "Edicion" => "",
    "FechaDisponibilidad" => null,
    "FechaEdicion" => null,
    "FechaInactivo" => "[DATO_SENSIBLE]",
    "FechaInicioVenta" => null,
    "Grueso" => 0,
    "ID_ArticuloEcotasas" => 0,
    "ID_Asignatura" => 0,
    "ID_Categoria" => 4,
    "ID_CategoriaWeb1" => 0,
    "ID_CategoriaWeb2" => 0,
    "ID_CategoriaWeb3" => 0,
    "ID_CategoriaWeb4" => 0,
    "ID_Coleccion" => 0,
    "ID_Curso" => 0,
    "ID_Fabricante" => 1,
    "ID_PaisPublicacion" => 0,
    "Id" => 5,
    "IdiomaOriginal" => null,
    "IdiomaPublicacion" => null,
    "Indice" => "",
    "Menciones" => "",
    "Nexo" => "",
    "Nombre" => "ARTURO",
    "NombreUds" => "",
    "NombreUdsAux" => "",
    "NombreUdsOCU" => "",
    "NumDimensiones" => 0,
    "NumeroColeccion" => "",
    "NumeroVolumen" => "",
    "ObraCompleta" => "",
    "Paginas" => 0,
    "Peso" => 0,
    "PorcentajeIVA" => "[DATO_SENSIBLE]",
    "PorcentajeRE" => 0,
    "PrecioEcotasas" => 0,
    "ReferenciaBarras" => "9788415250128",
    "RelacionUdsAux" => 0,
    "RelacionUdsOCU" => 0,
    "Resumen" => "",
    "Subtitulo" => "",
    "Tipo" => 2,
    "VenderUdsAux" => false,
    "Volumenes" => 0
];

// Cargar los datos a filtrar
$contenido = file_get_contents($archivo_origen);
$datos = json_decode($contenido, true);

// Verificar si es un objeto JSON o un array de objetos JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "Error: El archivo no contiene un JSON válido.\n";
    exit(1);
}

$es_array = is_array($datos) && isset($datos[0]);
$datos_filtrados = [];

// Función para filtrar un objeto según la plantilla
function filtrar_objeto($objeto, $plantilla) {
    $resultado = [];
    
    // Solo mantenemos campos que existen en la plantilla
    foreach ($plantilla as $campo => $valor_plantilla) {
        if (isset($objeto[$campo])) {
            // Si el valor en la plantilla es [DATO_SENSIBLE], ocultamos el valor real
            if ($valor_plantilla === "[DATO_SENSIBLE]") {
                $resultado[$campo] = "[DATO_SENSIBLE]";
            } else {
                $resultado[$campo] = $objeto[$campo];
            }
        } else {
            // Si el campo no existe en el objeto original, usamos el valor de la plantilla
            $resultado[$campo] = $valor_plantilla;
        }
    }
    
    return $resultado;
}

// Procesar los datos
if ($es_array) {
    // Es un array de objetos
    foreach ($datos as $objeto) {
        $datos_filtrados[] = filtrar_objeto($objeto, $plantilla);
    }
} else {
    // Es un único objeto
    $datos_filtrados = filtrar_objeto($datos, $plantilla);
}

// Guardar los datos filtrados
if (file_put_contents($archivo_salida, json_encode($datos_filtrados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
    echo "Error: No se pudieron guardar los datos filtrados.\n";
    exit(1);
}

$count = $es_array ? count($datos_filtrados) : 1;

echo "Proceso completado:\n";
echo "- Objetos procesados: " . ($es_array ? count($datos) : 1) . "\n";
echo "- Objetos filtrados: {$count}\n";
echo "- Archivo de salida: {$archivo_salida}\n";
?>
