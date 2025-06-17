<?php
/**
 * Script para corregir permisos de certificados SSL
 * 
 * Este script puede ser ejecutado directamente o incluido desde otro archivo.
 * Puede recibir rutas específicas como parámetros o usar las predeterminadas.
 * 
 * Uso: php fix-certificates-permissions.php [ruta/al/certificado.pem] [ruta/otro/certificado.pem] ...
 */

// Si no se proporciona una ruta como argumento, usar rutas por defecto
$default_cert_paths = [
    __DIR__ . '/../certs/ca-bundle.pem',
    __DIR__ . '/../certs/verial-ca.pem',
    plugin_dir_path(__FILE__) . '../certs/ca-bundle.pem',
    dirname(__FILE__) . '/../certs/ca-bundle.pem',
    dirname(dirname(__FILE__)) . '/certs/ca-bundle.pem'
];

/**
 * Función para verificar y corregir permisos de certificados
 * 
 * @param string $path Ruta al archivo de certificado
 * @param bool $verbose Mostrar mensajes detallados
 * @return bool True si se corrigieron los permisos correctamente
 */
function fixCertificatePermissions($path, $verbose = true) {
    if (!file_exists($path)) {
        if ($verbose) echo "Certificado no encontrado: $path\n";
        return false;
    }

    // Obtener permisos actuales
    $current_perms = fileperms($path);
    $current_perms_octal = substr(sprintf('%o', $current_perms), -4);
    $target_perms = '0644';
    
    if ($verbose) echo "Permisos actuales de $path: $current_perms_octal\n";
    
    // Verificar si los permisos ya son correctos
    if (($current_perms & 0777) === 0644 || ($current_perms & 0777) === 0444) {
        if ($verbose) echo "Los permisos ya son correctos para $path\n";
        return true;
    }

    // Probar diferentes métodos para establecer permisos (644)
    $methods = [
        // Método 1: PHP chmod directo
        function($path) use ($verbose) {
            if (@chmod($path, 0644)) {
                if ($verbose) echo "Permisos corregidos con PHP chmod para $path\n";
                return true;
            }
            return false;
        },
        
        // Método 2: Comando chmod del sistema
        function($path) use ($verbose) {
            @exec("chmod 644 " . escapeshellarg($path), $output, $return_var);
            if ($return_var === 0) {
                if ($verbose) echo "Permisos corregidos con comando chmod para $path\n";
                return true;
            }
            return false;
        },
        
        // Método 3: Comando chmod con sudo (para sistemas Unix)
        function($path) use ($verbose) {
            if (stripos(PHP_OS, 'win') !== false) return false;
            @exec("sudo chmod 644 " . escapeshellarg($path), $output, $return_var);
            if ($return_var === 0) {
                if ($verbose) echo "Permisos corregidos con sudo chmod para $path\n";
                return true;
            }
            return false;
        }
    ];

    // Probar cada método hasta que uno funcione
    foreach ($methods as $method) {
        if ($method($path)) {
            clearstatcache(true, $path);
            $new_perms = fileperms($path) & 0777;
            $new_perms_octal = decoct($new_perms);
            
            if ($new_perms === 0644 || $new_perms === 0444) {
                if ($verbose) echo "Verificación: nuevos permisos $new_perms_octal para $path\n";
                return true;
            }
        }
    }
    
    if ($verbose) echo "Error: No se pudieron corregir los permisos para $path\n";
    return false;
}

/**
 * Función para verificar y corregir permisos de directorios de certificados
 * 
 * @param string $path Ruta al directorio
 * @param bool $verbose Mostrar mensajes detallados
 * @return bool True si se corrigieron los permisos correctamente
 */
function fixCertificateDirectoryPermissions($path, $verbose = true) {
    if (!is_dir($path)) {
        if ($verbose) echo "Directorio no encontrado: $path\n";
        return false;
    }
    
    // Permisos para el directorio (755)
    $success = @chmod($path, 0755);
    
    if (!$success) {
        @exec("chmod 755 " . escapeshellarg($path), $output, $return_var);
        $success = ($return_var === 0);
        
        if (!$success && stripos(PHP_OS, 'win') === false) {
            @exec("sudo chmod 755 " . escapeshellarg($path), $output, $return_var);
            $success = ($return_var === 0);
        }
    }
    
    if ($success) {
        if ($verbose) echo "Permisos de directorio corregidos para $path\n";
        return true;
    } else {
        if ($verbose) echo "Error al corregir permisos de directorio para $path\n";
        return false;
    }
}

// Determinar las rutas a procesar
$cert_paths = [];

// Si se proporcionaron argumentos en línea de comandos
if (!empty($argv) && count($argv) > 1) {
    // Ignorar el primer argumento (nombre del script)
    for ($i = 1; $i < count($argv); $i++) {
        $cert_paths[] = $argv[$i];
    }
} else {
    // Usar las rutas predeterminadas
    $cert_paths = $default_cert_paths;
    
    // Intentar encontrar rutas adicionales en el directorio de certificados
    foreach ($default_cert_paths as $path) {
        $cert_dir = dirname($path);
        if (is_dir($cert_dir)) {
            // Verificar permisos del directorio
            fixCertificateDirectoryPermissions($cert_dir);
            
            // Buscar archivos .pem en el directorio
            $pem_files = glob($cert_dir . '/*.pem');
            if (is_array($pem_files)) {
                foreach ($pem_files as $pem_file) {
                    if (!in_array($pem_file, $cert_paths)) {
                        $cert_paths[] = $pem_file;
                    }
                }
            }
        }
    }
}

// Procesar cada certificado
$success_count = 0;
$total_count = 0;

echo "=== Iniciando verificación de permisos de certificados ===\n";

foreach ($cert_paths as $path) {
    if (file_exists($path)) {
        echo "\nProcesando: $path\n";
        $total_count++;
        if (fixCertificatePermissions($path)) {
            $success_count++;
        }
    }
}

echo "\n=== Resultados de la corrección de permisos ===\n";
echo "Certificados encontrados: $total_count\n";
echo "Certificados corregidos correctamente: $success_count\n";

if ($success_count === 0 && $total_count === 0) {
    echo "\nADVERTENCIA: No se encontraron certificados para procesar.\n";
    echo "Verifica las rutas e intenta nuevamente.\n";
    exit(1);
} elseif ($success_count < $total_count) {
    echo "\nALGUNOS CERTIFICADOS NO PUDIERON SER CORREGIDOS.\n";
    exit(2);
} else {
    echo "\nTodos los certificados fueron procesados correctamente.\n";
    exit(0);
}