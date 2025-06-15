<?php
/**
 * Comandos WP-CLI para diagnóstico y gestión del sistema SSL
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Tools
 */

namespace MiIntegracionApi\Tools;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use WP_CLI;
use WP_CLI\Utils;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

// Verificar que WP-CLI está disponible
if (!class_exists('WP_CLI')) {
    return;
}

/**
 * Gestiona comandos WP-CLI para el sistema SSL avanzado.
 *
 * ## EJEMPLOS
 *
 *     # Ejecutar un diagnóstico completo del sistema SSL
 *     $ wp miapi ssl diagnose
 *
 *     # Mostrar estadísticas del sistema de caché de certificados
 *     $ wp miapi ssl cache-stats
 *
 *     # Limpiar la caché de certificados
 *     $ wp miapi ssl clear-cache
 *
 *     # Forzar una rotación de certificados
 *     $ wp miapi ssl rotate-certs
 *
 *     # Verificar conectividad con un host específico
 *     $ wp miapi ssl test-connection example.com
 *
 * @package MiIntegracionApi
 */
class SSLCommands {

    /**
     * Instancia del conector API
     * 
     * @var ApiConnector
     */
    private $api_connector;

    /**
     * Logger para registrar eventos
     * 
     * @var Logger
     */
    private $logger;

    /**
     * Herramienta de diagnósticos SSL
     * 
     * @var SSLDiagnosticsTool
     */
    private $diagnostics_tool;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new \MiIntegracionApi\Helpers\Logger('ssl_commands');
        
        // Inicializar el conector API
        $this->api_connector = new ApiConnector();
        
        // Inicializar la herramienta de diagnóstico
        $this->diagnostics_tool = new SSLDiagnosticsTool($this->api_connector);
    }

    /**
     * Ejecuta un diagnóstico completo del sistema SSL
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Formato de salida: text, json, html
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     *   - html
     * ---
     *
     * ## EXAMPLES
     *
     *     # Ejecutar un diagnóstico en formato texto
     *     $ wp miapi ssl diagnose
     *
     *     # Exportar diagnóstico a un archivo JSON
     *     $ wp miapi ssl diagnose --format=json > diagnostico-ssl.json
     *
     * @when after_wp_load
     */
    public function diagnose($args, $assoc_args) {
        WP_CLI::log("Ejecutando diagnóstico completo del sistema SSL...");
        
        $format = $assoc_args['format'] ?? 'text';
        $results = $this->diagnostics_tool->runDiagnostics();
        
        if ($format === 'json') {
            WP_CLI::log(json_encode($results, JSON_PRETTY_PRINT));
            return;
        } elseif ($format === 'html') {
            $html_report = $this->diagnostics_tool->generateHTMLReport($results);
            WP_CLI::log($html_report);
            return;
        }
        
        // Formato de texto por defecto
        WP_CLI::log("\n" . str_repeat('=', 70));
        WP_CLI::log("DIAGNÓSTICO SSL - " . $results['date']);
        WP_CLI::log(str_repeat('=', 70));
        
        WP_CLI::log("\nRESUMEN:");
        WP_CLI::log("- Pruebas correctas: " . $results['summary']['passed']);
        WP_CLI::log("- Advertencias: " . $results['summary']['warnings']);
        WP_CLI::log("- Errores: " . $results['summary']['failed']);
        WP_CLI::log("- Tasa de éxito: " . $results['summary']['success_rate'] . "%");
        
        WP_CLI::log("\nRESULTADOS DE LAS PRUEBAS:");
        foreach ($results['tests'] as $test_id => $test) {
            $status_icon = $test['status'] === 'passed' ? '✅' : ($test['status'] === 'warning' ? '⚠️' : '❌');
            WP_CLI::log("\n" . $status_icon . " " . strtoupper($test['name']));
            WP_CLI::log("   " . $test['description']);
            
            foreach ($test['details'] as $detail) {
                WP_CLI::log("   - " . $detail);
            }
        }
        
        if ($results['summary']['failed'] > 0) {
            WP_CLI::warning("Se encontraron " . $results['summary']['failed'] . " errores críticos que deben ser corregidos.");
        } elseif ($results['summary']['warnings'] > 0) {
            WP_CLI::warning("Se encontraron " . $results['summary']['warnings'] . " advertencias que deberían ser revisadas.");
        } else {
            WP_CLI::success("¡El sistema SSL está correctamente configurado!");
        }
    }

    /**
     * Muestra estadísticas del sistema de caché de certificados
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Formato de salida: text, json
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * @when after_wp_load
     */
    public function cache_stats($args, $assoc_args) {
        WP_CLI::log("Obteniendo estadísticas del sistema de caché de certificados...");
        
        $format = $assoc_args['format'] ?? 'text';
        $stats = $this->api_connector->getCertificateCacheStats();
        
        if ($format === 'json') {
            WP_CLI::log(json_encode($stats, JSON_PRETTY_PRINT));
            return;
        }
        
        if (!$stats['enabled']) {
            WP_CLI::warning("El sistema de caché de certificados está desactivado.");
            return;
        }
        
        WP_CLI::log("\nESTADÍSTICAS DE CACHÉ DE CERTIFICADOS:");
        WP_CLI::log("- Aciertos: " . ($stats['cache_hits'] ?? 0));
        WP_CLI::log("- Fallos: " . ($stats['cache_misses'] ?? 0));
        WP_CLI::log("- Elementos en caché: " . ($stats['cached_items'] ?? 0));
        
        if (isset($stats['cache_size'])) {
            WP_CLI::log("- Tamaño de caché: " . round($stats['cache_size'] / 1024, 2) . " KB");
        }
        
        if (isset($stats['cache_hits']) && isset($stats['cache_misses']) && 
            ($stats['cache_hits'] + $stats['cache_misses']) > 0) {
            $hit_ratio = $stats['cache_hits'] / ($stats['cache_hits'] + $stats['cache_misses']) * 100;
            WP_CLI::log("- Ratio de aciertos: " . round($hit_ratio, 2) . "%");
        }
    }

    /**
     * Limpia la caché de certificados
     *
     * ## OPTIONS
     *
     * [<cert-url>]
     * : URL o ruta específica de certificado a limpiar
     *
     * ## EXAMPLES
     *
     *     # Limpiar toda la caché de certificados
     *     $ wp miapi ssl clear-cache
     *
     *     # Limpiar un certificado específico
     *     $ wp miapi ssl clear-cache /path/to/cert.pem
     *
     * @when after_wp_load
     */
    public function clear_cache($args, $assoc_args) {
        $cert_url = $args[0] ?? null;
        
        if ($cert_url) {
            WP_CLI::log("Limpiando caché para el certificado: $cert_url");
        } else {
            WP_CLI::log("Limpiando toda la caché de certificados...");
        }
        
        $result = $this->api_connector->clearCertificateCache($cert_url);
        
        if ($result) {
            WP_CLI::success("Caché de certificados limpiada correctamente.");
        } else {
            WP_CLI::error("Error al limpiar la caché de certificados.");
        }
    }

    /**
     * Fuerza una rotación de certificados
     *
     * ## OPTIONS
     *
     * [--verify]
     * : Solo verificar si es necesaria una rotación, sin ejecutarla
     *
     * @when after_wp_load
     */
    public function rotate_certs($args, $assoc_args) {
        $verify_only = isset($assoc_args['verify']);
        
        if ($verify_only) {
            WP_CLI::log("Verificando si es necesaria una rotación de certificados...");
            
            $rotation_status = $this->api_connector->getCertificateRotationStatus();
            
            if (!$rotation_status['enabled']) {
                WP_CLI::warning("El sistema de rotación de certificados está desactivado.");
                return;
            }
            
            if (isset($rotation_status['needs_rotation'])) {
                if ($rotation_status['needs_rotation']) {
                    WP_CLI::warning("Es necesaria una rotación de certificados.");
                    
                    if (isset($rotation_status['next_rotation'])) {
                        $days_until = round(($rotation_status['next_rotation'] - time()) / 86400, 1);
                        WP_CLI::log("Próxima rotación programada: en " . $days_until . " días");
                    }
                } else {
                    WP_CLI::success("No es necesaria una rotación de certificados en este momento.");
                    
                    if (isset($rotation_status['last_rotation'])) {
                        $days_ago = round((time() - $rotation_status['last_rotation']) / 86400, 1);
                        WP_CLI::log("Última rotación: hace " . $days_ago . " días");
                    }
                }
            } else {
                WP_CLI::warning("No se pudo determinar si es necesaria una rotación.");
            }
        } else {
            WP_CLI::log("Forzando rotación de certificados...");
            
            $result = $this->api_connector->checkCertificateRotation(true);
            
            if ($result) {
                WP_CLI::success("Rotación de certificados completada correctamente.");
            } else {
                WP_CLI::error("Error al realizar la rotación de certificados.");
            }
        }
    }

    /**
     * Prueba la conexión SSL con un host específico
     *
     * ## OPTIONS
     *
     * <host>
     * : Hostname a probar (sin protocolo)
     *
     * [--port=<port>]
     * : Puerto a utilizar para la conexión
     * ---
     * default: 443
     * ---
     *
     * [--timeout=<timeout>]
     * : Timeout en segundos
     * ---
     * default: 10
     * ---
     *
     * [--detailed]
     * : Mostrar información detallada del certificado
     *
     * ## EXAMPLES
     *
     *     # Probar conexión con example.com
     *     $ wp miapi ssl test-connection example.com
     *
     *     # Probar conexión con detalles del certificado
     *     $ wp miapi ssl test-connection example.com --detailed
     *
     * @when after_wp_load
     */
    public function test_connection($args, $assoc_args) {
        if (empty($args[0])) {
            WP_CLI::error("Debe especificar un host para probar la conexión.");
        }
        
        $host = $args[0];
        $port = $assoc_args['port'] ?? 443;
        $timeout = $assoc_args['timeout'] ?? 10;
        $detailed = isset($assoc_args['detailed']);
        
        WP_CLI::log("Probando conexión SSL con $host:$port (timeout: {$timeout}s)...");
        
        $start_time = microtime(true);
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => $detailed,
                'SNI_enabled' => true,
                'peer_name' => $host,
                'timeout' => $timeout,
            ]
        ]);
        
        $errno = 0;
        $errstr = '';
        $conn = @stream_socket_client("ssl://$host:$port", $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);
        $elapsed = microtime(true) - $start_time;
        
        if ($conn === false) {
            WP_CLI::error("No se pudo conectar: $errstr ($errno)");
            return;
        }
        
        WP_CLI::success("Conexión establecida en " . round($elapsed, 3) . " segundos.");
        
        // Obtener información del certificado
        $cert_info = stream_context_get_params($context);
        
        if (isset($cert_info['options']['ssl']['peer_certificate'])) {
            $cert = $cert_info['options']['ssl']['peer_certificate'];
            $certinfo = openssl_x509_parse($cert);
            
            WP_CLI::log("\nINFORMACIÓN DEL CERTIFICADO:");
            WP_CLI::log("- Emitido para: " . $certinfo['subject']['CN'] ?? 'Desconocido');
            WP_CLI::log("- Emitido por: " . $certinfo['issuer']['CN'] ?? 'Desconocido');
            WP_CLI::log("- Válido desde: " . date('Y-m-d H:i:s', $certinfo['validFrom_time_t']));
            WP_CLI::log("- Válido hasta: " . date('Y-m-d H:i:s', $certinfo['validTo_time_t']));
            
            $now = time();
            $days_left = round(($certinfo['validTo_time_t'] - $now) / 86400);
            WP_CLI::log("- Días restantes: $days_left");
            
            if ($detailed && isset($cert_info['options']['ssl']['peer_certificate_chain'])) {
                $chain = $cert_info['options']['ssl']['peer_certificate_chain'];
                WP_CLI::log("\nCADENA DE CERTIFICADOS:");
                foreach ($chain as $i => $chain_cert) {
                    $chain_info = openssl_x509_parse($chain_cert);
                    WP_CLI::log("- Certificado #" . ($i + 1) . ": " . ($chain_info['subject']['CN'] ?? 'Desconocido'));
                }
            }
            
            // Verificar la validez del certificado
            $valid_time = $certinfo['validTo_time_t'] > $now;
            $valid_host = isset($certinfo['subject']['CN']) && ($certinfo['subject']['CN'] === $host || 
                                                              preg_match('/^\*\.' . preg_quote(preg_replace('/^www\./', '', $host)) . '$/', $certinfo['subject']['CN']));
            
            if (!$valid_time) {
                WP_CLI::warning("¡El certificado ha expirado!");
            }
            
            if (!$valid_host) {
                WP_CLI::warning("¡El nombre del host no coincide con el certificado!");
            }
        }
        
        fclose($conn);
    }

    /**
     * Muestra estadísticas y configuración del sistema de timeouts
     *
     * ## OPTIONS
     *
     * [<host>]
     * : Host específico para mostrar estadísticas
     *
     * [--format=<format>]
     * : Formato de salida: text, json
     * ---
     * default: text
     * options:
     *   - text
     *   - json
     * ---
     *
     * @when after_wp_load
     */
    public function timeout_stats($args, $assoc_args) {
        $host = $args[0] ?? null;
        $format = $assoc_args['format'] ?? 'text';
        
        if ($host) {
            WP_CLI::log("Obteniendo estadísticas de timeouts para el host: $host");
        } else {
            WP_CLI::log("Obteniendo estadísticas generales del sistema de timeouts...");
        }
        
        $stats = $this->api_connector->getTimeoutStats($host);
        
        if ($format === 'json') {
            WP_CLI::log(json_encode($stats, JSON_PRETTY_PRINT));
            return;
        }
        
        if (!$stats['enabled']) {
            WP_CLI::warning("El sistema avanzado de timeouts está desactivado.");
            return;
        }
        
        WP_CLI::log("\nESTADÍSTICAS DE LATENCIA:");
        
        if (isset($stats['latency'])) {
            $latency = $stats['latency'];
            
            if ($host && isset($latency['average_latency'])) {
                WP_CLI::log("- Host: $host");
                WP_CLI::log("- Solicitudes: " . $latency['requests']);
                WP_CLI::log("- Latencia promedio: " . round($latency['average_latency'], 3) . "s");
                WP_CLI::log("- Latencia mínima: " . round($latency['min_latency'], 3) . "s");
                WP_CLI::log("- Latencia máxima: " . round($latency['max_latency'], 3) . "s");
            } else if (isset($latency['global_average_latency'])) {
                WP_CLI::log("- Total solicitudes: " . $latency['total_requests']);
                WP_CLI::log("- Latencia promedio global: " . round($latency['global_average_latency'], 3) . "s");
                WP_CLI::log("- Latencia máxima global: " . round($latency['global_max_latency'], 3) . "s");
                
                WP_CLI::log("\nHOSTS CON MÁS SOLICITUDES:");
                $hosts = $latency['hosts'] ?? [];
                
                // Ordenar hosts por número de solicitudes
                uasort($hosts, function($a, $b) {
                    return $b['requests'] - $a['requests'];
                });
                
                $i = 0;
                foreach ($hosts as $hostname => $host_stats) {
                    if ($i++ >= 5) break; // Mostrar solo los 5 principales
                    WP_CLI::log("- $hostname: " . $host_stats['requests'] . " solicitudes, " . 
                               round($host_stats['average_latency'], 3) . "s promedio");
                }
            }
        }
        
        if (isset($stats['configuration'])) {
            WP_CLI::log("\nCONFIGURACIÓN DE TIMEOUTS:");
            WP_CLI::log("- Timeout general: " . $stats['configuration']['method_timeouts']['timeout'] . "s");
            WP_CLI::log("- Timeout de conexión: " . $stats['configuration']['method_timeouts']['connect_timeout'] . "s");
            
            WP_CLI::log("\nPOLÍTICAS DE ERROR:");
            foreach ($stats['configuration']['error_policies'] as $error_type => $policy) {
                WP_CLI::log("- $error_type: " . $policy['max_retries'] . " reintentos (factor: " . $policy['backoff_factor'] . ")");
            }
        }
        
        if (isset($stats['history']) && $stats['history']['days'] > 0) {
            WP_CLI::log("\nHISTORIAL DE ESTADÍSTICAS:");
            WP_CLI::log("- Días registrados: " . $stats['history']['days']);
            WP_CLI::log("- Total solicitudes históricas: " . $stats['history']['summary']['total_requests']);
            WP_CLI::log("- Última fecha registrada: " . $stats['history']['last_recorded']);
        }
    }

    /**
     * Limpia el historial de latencias
     *
     * ## OPTIONS
     *
     * [<host>]
     * : Host específico para limpiar estadísticas
     *
     * @when after_wp_load
     */
    public function clear_latency($args, $assoc_args) {
        $host = $args[0] ?? null;
        
        if ($host) {
            WP_CLI::log("Limpiando historial de latencias para el host: $host");
        } else {
            WP_CLI::log("Limpiando todo el historial de latencias...");
        }
        
        $result = $this->api_connector->clearLatencyHistory($host);
        
        if ($result) {
            WP_CLI::success("Historial de latencias limpiado correctamente.");
        } else {
            WP_CLI::error("Error al limpiar el historial de latencias.");
        }
    }
}

// Registrar los comandos WP-CLI
$ssl_commands = new SSLCommands();

WP_CLI::add_command('miapi ssl diagnose', [$ssl_commands, 'diagnose']);
WP_CLI::add_command('miapi ssl cache-stats', [$ssl_commands, 'cache_stats']);
WP_CLI::add_command('miapi ssl clear-cache', [$ssl_commands, 'clear_cache']);
WP_CLI::add_command('miapi ssl rotate-certs', [$ssl_commands, 'rotate_certs']);
WP_CLI::add_command('miapi ssl test-connection', [$ssl_commands, 'test_connection']);
WP_CLI::add_command('miapi ssl timeout-stats', [$ssl_commands, 'timeout_stats']);
WP_CLI::add_command('miapi ssl clear-latency', [$ssl_commands, 'clear_latency']);
