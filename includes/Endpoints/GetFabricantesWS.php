<?php

namespace MiIntegracionApi\Endpoints;

/**
 * Clase para el endpoint GetFabricantesWS de la API de Verial ERP.
 * Obtiene el listado de fabricantes y editores, según el manual v1.7.5.
 *
 * @package MiIntegracionApi
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Traits\EndpointLogger;

class GetFabricantesWS extends Base {

    use EndpointLogger;

    const ENDPOINT_NAME = 'GetFabricantesWS';
    const CACHE_KEY_PREFIX = 'mi_api_fabricantes_';
    const CACHE_EXPIRATION = 12 * HOUR_IN_SECONDS;

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_logger();
    }

    /**
     * Obtiene el listado de fabricantes
     *
     * @param array $args Argumentos para la petición
     * @return array Respuesta procesada con los fabricantes
     */
    public function get_fabricantes($args = []) {
        // Iniciar la medición del tiempo
        $start_time = microtime(true);
        
        // Ver si hay datos en caché
        $cache_key = self::CACHE_KEY_PREFIX . md5(serialize($args));
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            $this->log_debug("Datos recuperados de caché para fabricantes");
            return $cached_data;
        }
        
        // Preparar la solicitud a la API
        $api_connector = new ApiConnector();
        $response = $api_connector->make_api_call(self::ENDPOINT_NAME, $args);
        
        // Si hay error en la respuesta, registrarlo y retornar la respuesta tal cual
        if (!isset($response['success']) || $response['success'] !== true) {
            $error_message = isset($response['error']) ? $response['error'] : 'Error desconocido';
            $this->log_error("Error al obtener fabricantes: {$error_message}");
            return $response;
        }
        
        // Procesar los resultados
        $fabricantes = [];
        
        if (isset($response['data']['result'])) {
            $fabricantes = $this->process_fabricantes($response['data']['result']);
        }
        
        $result = $this->format_success_response($fabricantes);
        
        // Guardar en caché
        set_transient($cache_key, $result, self::CACHE_EXPIRATION);
        
        // Registrar tiempo de ejecución
        $execution_time = microtime(true) - $start_time;
        $this->log_debug("Fabricantes obtenidos en {$execution_time} segundos");
        
        return $result;
    }
    
    /**
     * Procesa los datos de fabricantes de la respuesta de la API
     *
     * @param array $data Datos de la API
     * @return array Datos procesados
     */
    private function process_fabricantes($data) {
        $fabricantes = [];
        
        if (is_array($data)) {
            foreach ($data as $item) {
                if (isset($item['Codigo']) && isset($item['Nombre'])) {
                    $fabricantes[] = [
                        'id' => sanitize_text_field($item['Codigo']),
                        'nombre' => sanitize_text_field($item['Nombre'])
                    ];
                }
            }
        }
        
        return $fabricantes;
    }
    
    /**
     * Registra la ruta REST WP para este endpoint
     *
     * @return void
     */
    public function register_route(): void {
        register_rest_route(
            'mi-integracion-api/v1',
            '/getfabricantesws',
            array(
                'methods'             => 'GET',
                'callback'            => array( $this, 'execute_restful' ),
                'permission_callback' => array( $this, 'permissions_check' ),
            )
        );
    }
}
