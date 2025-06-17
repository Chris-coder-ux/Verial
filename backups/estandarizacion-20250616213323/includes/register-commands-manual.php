<?php
/**
 * Archivo para registro manual de comandos WP-CLI
 * Este archivo asegura que los comandos SSL se registren correctamente
 * independientemente de cómo se cargue el plugin.
 *
 * @package MiIntegracionApi
 */

if (!defined('ABSPATH')) {
    return;
}

// Verificar que WP-CLI está disponible
if (!class_exists('WP_CLI')) {
    return;
}

// Registrar comandos SSL
add_action('plugins_loaded', function() {
    if (class_exists('MiIntegracionApi\\Tools\\SSLCommands') && class_exists('WP_CLI')) {
        // Instanciar la clase SSLCommands
        try {
            $ssl_commands = new MiIntegracionApi\Tools\SSLCommands();
            
            // Registrar todos los comandos disponibles
            WP_CLI::add_command('miapi ssl diagnose', [$ssl_commands, 'diagnose']);
            WP_CLI::add_command('miapi ssl cache-stats', [$ssl_commands, 'cache_stats']);
            WP_CLI::add_command('miapi ssl clear-cache', [$ssl_commands, 'clear_cache']);
            WP_CLI::add_command('miapi ssl rotate-certs', [$ssl_commands, 'rotate_certs']);
            WP_CLI::add_command('miapi ssl test-connection', [$ssl_commands, 'test_connection']);
            WP_CLI::add_command('miapi ssl timeout-stats', [$ssl_commands, 'timeout_stats']);
            WP_CLI::add_command('miapi ssl clear-latency', [$ssl_commands, 'clear_latency']);
            
            // Registrar un mensaje de estado
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::debug('Comandos SSL de Mi Integración API registrados correctamente');
            }
        } catch (Exception $e) {
            if (defined('WP_CLI') && WP_CLI) {
                WP_CLI::error('Error al registrar comandos SSL: ' . $e->getMessage());
            }
        }
    } else {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('Mi Integración API: No se pudo registrar comandos SSL - Clases no disponibles');
        }
    }
}, 20);

// No se detecta uso de Logger::log, solo WP_CLI::debug/error y error_log estándar.
