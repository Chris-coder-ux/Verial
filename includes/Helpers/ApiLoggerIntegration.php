<?php
/**
 * Ejemplo de integración del Logger en la API
 *
 * Este archivo muestra cómo utilizar el Logger en diferentes situaciones
 * y con las nuevas características implementadas.
 *
 * @package MiIntegracionApi\Helpers
 */

namespace MiIntegracionApi\Helpers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase de demostración para la integración del Logger
 */
class ApiLoggerIntegration {
    
    /**
     * Habilitar logs en archivos personalizados
     */
    public static function init_example() {
        // Habilitar logs en archivos personalizados
        // Esto podría agregarse en un archivo de configuración o en el constructor de clases principales
        Logger::enable_file_logging( 'api-calls', true );
        Logger::enable_file_logging( 'sync-articulos', true );
        Logger::enable_file_logging( 'sync-clientes', true );
        Logger::enable_file_logging( 'sync-pedidos', true );
        
        // Ejemplo de cómo registrar eventos en diferentes niveles
        self::demo_logging_levels();
        
        // Ejemplo de uso del contexto
        self::demo_context_usage();
        
        // Ejemplo de uso con excepciones
        self::demo_exception_handling();
        
        // Ejemplo de uso con hooks personalizados
        self::demo_custom_hooks();
    }
    
    /**
     * Ejemplo de los diferentes niveles de log
     */
    public static function demo_logging_levels() {
        // Registrar información general - visible en producción
        Logger::info(
            'Iniciando sincronización de artículos',
            'sync-articulos'
        );
        
        // Registrar advertencias - visible en producción
        Logger::warning(
            'Artículo no encontrado en Verial: SKU-12345',
            'sync-articulos'
        );
        
        // Registrar errores - visible en producción
        Logger::error(
            'Error al actualizar precio del artículo ID: 42',
            'sync-articulos'
        );
        
        // Debug - solo visible si debug_mode está activado
        $logger = new Logger('sync-articulos');
        $logger->debug(
            'Detalles del artículo recibido: ' . json_encode(['id' => 42, 'sku' => 'ABC-123']),
            'sync-articulos'
        );
    }
    
    /**
     * Ejemplo de uso del contexto en los logs
     */
    public static function demo_context_usage() {
        // Usar contexto para añadir datos estructurados al log
        $articulo_id = 42;
        $contexto = [
            'articulo_id' => $articulo_id,
            'sku' => 'ABC-123',
            'precio' => 29.99,
            'stock' => 15
        ];
        
        Logger::info(
            'Actualizando datos del artículo',
            'sync-articulos',
            $contexto
        );
        
        // Contexto con datos de respuesta de API
        $response_context = [
            'request_id' => 'REQ-123456',
            'response_code' => 200,
            'response_time' => 0.45,
            'payload' => json_encode(['status' => 'OK'])
        ];
        
        $logger = new Logger('api-calls');
        $logger->debug(
            'Respuesta recibida de API Verial',
            'api-calls',
            $response_context
        );
    }
    
    /**
     * Ejemplo de manejo de excepciones con el Logger
     */
    public static function demo_exception_handling() {
        try {
            // Simular una excepción
            throw new \Exception('Error de conexión con API externa');
        } catch (\Exception $e) {
            // Registrar la excepción completa
            Logger::exception(
                $e,
                'Error al procesar petición API',
                'api-calls'
            );
            
            // También podemos registrar solo un mensaje personalizado con el trace
            Logger::error(
                'Error en sincronización: ' . $e->getMessage(),
                'sync-articulos',
                ['exception_trace' => $e->getTraceAsString()]
            );
        }
    }
    
    /**
     * Ejemplo de uso con hooks personalizados
     */
    public static function demo_custom_hooks() {
        // Registrar un evento con hook personalizado
        do_action('mi_integracion_api_log', [
            'level' => 'info',
            'message' => 'Evento registrado desde hook personalizado',
            'context' => ['source' => 'hook_example'],
            'file' => 'sync-articulos'
        ]);
    }
}
