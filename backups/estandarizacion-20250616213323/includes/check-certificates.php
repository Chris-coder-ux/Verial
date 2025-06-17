<?php
/**
 * Script para comprobar y actualizar certificados SSL
 *
 * Este script puede ejecutarse desde la línea de comandos o programarse como tarea cron.
 * Proporciona funcionalidades para verificar, rotar y actualizar certificados SSL.
 *
 * Uso: php check-certificates.php [acción] [parámetros]
 * Acciones disponibles:
 *  - check: Verifica el estado de los certificados
 *  - rotate: Fuerza una rotación de certificados
 *  - clear-cache: Limpia la caché de certificados
 *  - fix-permissions: Corrige permisos de certificados
 *  - diagnose: Ejecuta un diagnóstico completo
 *
 * Ejemplos:
 *  php check-certificates.php check
 *  php check-certificates.php rotate --force
 *  php check-certificates.php diagnose --verbose
 */

// Cargar WordPress (si está disponible)
$wp_load_paths = [
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../wp-load.php'
];

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

// Cargar clases del plugin
if (!$wp_loaded) {
    // Modo standalone - cargar solo lo necesario
    require_once __DIR__ . '/Autoloader.php';
    \MiIntegracionApi\Autoloader::register();
}

use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\SSL\CertificateCache;
use MiIntegracionApi\SSL\CertificateRotation;
use MiIntegracionApi\SSL\SSLConfigManager;
use MiIntegracionApi\Core\ApiConnector;

// Configuración
$verbose = in_array('--verbose', $argv) || in_array('-v', $argv);
$force = in_array('--force', $argv) || in_array('-f', $argv);
$logger = new \MiIntegracionApi\Helpers\Logger('certificate_script');

// Configurar salida del logger
$logger->setOutput(new class() {
    public function write($level, $message, $context = []) {
        $level_prefix = strtoupper($level);
        $date = date('Y-m-d H:i:s');
        $message = sprintf("[%s] [%s] %s", $date, $level_prefix, $message);
        
        if (!empty($context)) {
            $context_str = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $message .= " - Contexto: " . $context_str;
        }
        
        echo $message . PHP_EOL;
    }
});

// Crear instancias de los componentes necesarios
$ssl_config = new SSLConfigManager($logger);
$cert_cache = new CertificateCache($logger);
$cert_rotation = new CertificateRotation($logger);
$api_connector = new ApiConnector(['logger' => $logger]);

/**
 * Verificar el estado de los certificados
 */
function check_certificates($cert_rotation, $ssl_config, $verbose = false) {
    echo "Verificando certificados SSL..." . PHP_EOL;
    
    $status = $cert_rotation->getStatus();
    
    echo "Certificado principal: " . ($status['certificado_principal']['existe'] ? "OK" : "NO ENCONTRADO") . PHP_EOL;
    
    if ($status['certificado_principal']['existe']) {
        echo "  Ruta: " . $status['certificado_principal']['path'] . PHP_EOL;
        echo "  Tamaño: " . format_bytes($status['certificado_principal']['tamaño']) . PHP_EOL;
        echo "  Fecha de modificación: " . $status['certificado_principal']['fecha_modificacion'] . PHP_EOL;
    }
    
    echo "Última rotación: " . $status['ultima_rotacion'] . PHP_EOL;
    echo "Próxima rotación: " . $status['proxima_rotacion'] . PHP_EOL;
    echo "¿Necesita rotación? " . ($status['necesita_rotacion'] ? "SÍ" : "NO") . PHP_EOL;
    echo "Fuentes disponibles: " . $status['fuentes_disponibles'] . PHP_EOL;
    
    if ($verbose && !empty($status['backups'])) {
        echo "Backups disponibles:" . PHP_EOL;
        foreach ($status['backups'] as $backup) {
            echo "  " . $backup['archivo'] . " (" . format_bytes($backup['tamaño']) . ") - " . $backup['fecha'] . PHP_EOL;
        }
    }
    
    // Verificar configuración SSL
    $ssl_options = $ssl_config->getAllOptions();
    echo PHP_EOL . "Configuración SSL:" . PHP_EOL;
    echo "  Verificar peer: " . ($ssl_options['verify_peer'] ? "SÍ" : "NO") . PHP_EOL;
    echo "  Verificar nombre peer: " . ($ssl_options['verify_peer_name'] ? "SÍ" : "NO") . PHP_EOL;
    echo "  Permitir autofirmados: " . ($ssl_options['allow_self_signed'] ? "SÍ" : "NO") . PHP_EOL;
    echo "  Verificar revocación: " . ($ssl_options['revocation_check'] ? "SÍ" : "NO") . PHP_EOL;
    
    if ($verbose) {
        echo "  Ruta bundle CA: " . ($ssl_options['ca_bundle_path'] ?: "No configurado") . PHP_EOL;
        echo "  Deshabilitar SSL en local: " . ($ssl_options['disable_ssl_local'] ? "SÍ" : "NO") . PHP_EOL;
        echo "  Versión SSL: " . ($ssl_options['ssl_version'] ?: "Por defecto del sistema") . PHP_EOL;
    }
}

/**
 * Realizar una rotación de certificados
 */
function rotate_certificates($cert_rotation, $force = false) {
    echo "Iniciando rotación de certificados" . ($force ? " (forzada)" : "") . "..." . PHP_EOL;
    
    $start_time = microtime(true);
    $result = $cert_rotation->rotateCertificates($force);
    $execution_time = microtime(true) - $start_time;
    
    if ($result) {
        echo "¡Rotación completada exitosamente en " . number_format($execution_time, 2) . " segundos!" . PHP_EOL;
        
        $status = $cert_rotation->getStatus();
        if (!empty($status['certificado_principal']) && $status['certificado_principal']['existe']) {
            echo "Nuevo certificado: " . $status['certificado_principal']['path'] . PHP_EOL;
            echo "Tamaño: " . format_bytes($status['certificado_principal']['tamaño']) . PHP_EOL;
            echo "Fecha: " . $status['certificado_principal']['fecha_modificacion'] . PHP_EOL;
        }
    } else {
        echo "Error: No se pudo completar la rotación de certificados." . PHP_EOL;
        exit(1);
    }
}

/**
 * Limpiar caché de certificados
 */
function clear_certificate_cache($cert_cache) {
    echo "Limpiando caché de certificados..." . PHP_EOL;
    
    $stats_before = $cert_cache->getCacheStats();
    $result = $cert_cache->clearCache();
    
    if ($result) {
        echo "Caché limpiada exitosamente." . PHP_EOL;
        echo "Se eliminaron " . $stats_before['count'] . " archivos de caché (" . format_bytes($stats_before['total_size']) . ")" . PHP_EOL;
    } else {
        echo "Error: No se pudo limpiar la caché de certificados." . PHP_EOL;
        exit(1);
    }
}

/**
 * Corregir permisos de certificados
 */
function fix_certificate_permissions($api_connector) {
    echo "Corrigiendo permisos de certificados SSL..." . PHP_EOL;
    
    $results = $api_connector->fixCertificatePermissions();
    
    if (!empty($results['success'])) {
        echo "Certificados corregidos exitosamente: " . count($results['success']) . PHP_EOL;
        foreach ($results['success'] as $cert) {
            echo "  ✓ " . $cert . PHP_EOL;
        }
    }
    
    if (!empty($results['failed'])) {
        echo "Certificados con errores: " . count($results['failed']) . PHP_EOL;
        foreach ($results['failed'] as $cert => $error) {
            echo "  ✗ " . $cert . ": " . $error . PHP_EOL;
        }
        exit(1);
    }
    
    if (empty($results['success']) && empty($results['failed'])) {
        echo "No se encontraron certificados para procesar." . PHP_EOL;
    }
}

/**
 * Ejecutar diagnóstico completo
 */
function diagnose_ssl($api_connector, $cert_rotation, $cert_cache, $ssl_config, $verbose = false) {
    echo "Ejecutando diagnóstico completo del sistema SSL..." . PHP_EOL . PHP_EOL;
    
    // 1. Verificar estado de certificados
    check_certificates($cert_rotation, $ssl_config, $verbose);
    
    // 2. Realizar diagnóstico SSL
    echo PHP_EOL . "Diagnóstico de conexiones SSL:" . PHP_EOL;
    $ssl_diagnosis = $api_connector->diagnoseSSL('https://www.google.com');
    
    echo "  OpenSSL instalado: " . ($ssl_diagnosis['openssl_installed'] ? "SÍ" : "NO") . PHP_EOL;
    echo "  Versión OpenSSL: " . $ssl_diagnosis['openssl_version'] . PHP_EOL;
    echo "  CURL con soporte SSL: " . ($ssl_diagnosis['curl_ssl_supported'] ? "SÍ" : "NO") . PHP_EOL;
    echo "  Versión cURL: " . $ssl_diagnosis['curl_version'] . PHP_EOL;
    echo "  Bundle CA encontrado: " . ($ssl_diagnosis['ca_bundle_found'] ? "SÍ" : "NO") . PHP_EOL;
    
    if ($ssl_diagnosis['ca_bundle_found']) {
        echo "  Ruta Bundle CA: " . $ssl_diagnosis['ca_bundle_path'] . PHP_EOL;
    }
    
    echo "  Resultado conexión de prueba: " . ($ssl_diagnosis['test_connection_success'] ? "ÉXITO" : "FALLO") . PHP_EOL;
    
    if (!$ssl_diagnosis['test_connection_success'] && !empty($ssl_diagnosis['test_connection_error'])) {
        echo "  Error de conexión: " . $ssl_diagnosis['test_connection_error'] . PHP_EOL;
    }
    
    // 3. Verificar caché de certificados
    $cache_stats = $cert_cache->getCacheStats();
    echo PHP_EOL . "Estadísticas de caché de certificados:" . PHP_EOL;
    echo "  Total certificados en caché: " . $cache_stats['count'] . PHP_EOL;
    echo "  Tamaño total de caché: " . format_bytes($cache_stats['total_size']) . PHP_EOL;
    echo "  Directorio de caché: " . $cache_stats['directory'] . PHP_EOL;
    
    if ($verbose) {
        echo "  Certificado más antiguo: " . ($cache_stats['oldest'] ?: "N/A") . PHP_EOL;
        echo "  Certificado más reciente: " . ($cache_stats['newest'] ?: "N/A") . PHP_EOL;
    }
    
    // 4. Verificar permisos de directorio de certificados
    $cert_dir = dirname($ssl_config->getOption('ca_bundle_path', ''));
    if (!empty($cert_dir) && is_dir($cert_dir)) {
        $dir_perms = substr(sprintf('%o', fileperms($cert_dir)), -4);
        $dir_writeable = is_writable($cert_dir);
        
        echo PHP_EOL . "Permisos de directorio de certificados:" . PHP_EOL;
        echo "  Directorio: " . $cert_dir . PHP_EOL;
        echo "  Permisos: " . $dir_perms . PHP_EOL;
        echo "  Escribible: " . ($dir_writeable ? "SÍ" : "NO") . PHP_EOL;
        
        if ($verbose) {
            $dir_owner = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($cert_dir))['name'] : "N/A";
            $dir_group = function_exists('posix_getgrgid') ? posix_getgrgid(filegroup($cert_dir))['name'] : "N/A";
            echo "  Propietario: " . $dir_owner . PHP_EOL;
            echo "  Grupo: " . $dir_group . PHP_EOL;
        }
    }
}

/**
 * Formatea un tamaño en bytes a una representación legible
 */
function format_bytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Procesar comandos
$action = isset($argv[1]) ? $argv[1] : 'help';

switch ($action) {
    case 'check':
        check_certificates($cert_rotation, $ssl_config, $verbose);
        break;
        
    case 'rotate':
        rotate_certificates($cert_rotation, $force);
        break;
        
    case 'clear-cache':
        clear_certificate_cache($cert_cache);
        break;
        
    case 'fix-permissions':
        fix_certificate_permissions($api_connector);
        break;
        
    case 'diagnose':
        diagnose_ssl($api_connector, $cert_rotation, $cert_cache, $ssl_config, $verbose);
        break;
        
    case 'help':
    default:
        echo "Herramienta de gestión de certificados SSL" . PHP_EOL;
        echo "Uso: php " . basename(__FILE__) . " [acción] [opciones]" . PHP_EOL . PHP_EOL;
        echo "Acciones disponibles:" . PHP_EOL;
        echo "  check              Verificar el estado de los certificados" . PHP_EOL;
        echo "  rotate             Forzar una rotación de certificados" . PHP_EOL;
        echo "  clear-cache        Limpiar la caché de certificados" . PHP_EOL;
        echo "  fix-permissions    Corregir permisos de certificados" . PHP_EOL;
        echo "  diagnose           Ejecutar un diagnóstico completo del sistema SSL" . PHP_EOL;
        echo "  help               Mostrar esta ayuda" . PHP_EOL . PHP_EOL;
        echo "Opciones:" . PHP_EOL;
        echo "  --verbose, -v      Mostrar información detallada" . PHP_EOL;
        echo "  --force, -f        Forzar la operación (para rotate)" . PHP_EOL;
        break;
}

exit(0);
