<?php
/**
 * Script para limpiar el archivo debug.log eliminando líneas específicas
 * 
 * Uso: php limpiar-debug-log.php /ruta/al/debug.log "texto a filtrar"
 */

if ($argc < 3) {
    echo "Uso: php limpiar-debug-log.php /ruta/al/debug.log \"texto a filtrar\"\n";
    exit(1);
}

$archivo_origen = $argv[1];
$texto_filtrar = $argv[2];
$archivo_temp = $archivo_origen . '.temp';

if (!file_exists($archivo_origen)) {
    echo "Error: El archivo {$archivo_origen} no existe.\n";
    exit(1);
}

// Abrir archivos
$in = fopen($archivo_origen, 'r');
$out = fopen($archivo_temp, 'w');

if (!$in || !$out) {
    echo "Error: No se pudieron abrir los archivos para lectura/escritura.\n";
    exit(1);
}

$lineas_totales = 0;
$lineas_eliminadas = 0;

// Procesar archivo línea por línea
while (($linea = fgets($in)) !== false) {
    $lineas_totales++;
    
    // Si la línea no contiene el texto a filtrar, la escribimos en el archivo temporal
    if (strpos($linea, $texto_filtrar) === false) {
        fwrite($out, $linea);
    } else {
        $lineas_eliminadas++;
    }
}

// Cerrar archivos
fclose($in);
fclose($out);

// Reemplazar el archivo original con el temporal
if (!rename($archivo_temp, $archivo_origen)) {
    echo "Error: No se pudo reemplazar el archivo original.\n";
    echo "El archivo filtrado está disponible en {$archivo_temp}\n";
    exit(1);
}

echo "Proceso completado:\n";
echo "- Líneas procesadas: {$lineas_totales}\n";
echo "- Líneas eliminadas: {$lineas_eliminadas}\n";
echo "- Líneas restantes: " . ($lineas_totales - $lineas_eliminadas) . "\n";
?>
