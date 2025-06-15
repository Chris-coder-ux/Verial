<?php
namespace MiIntegracionApi\Endpoints;

use MiIntegracionApi\Traits\EndpointLogger;
use MiIntegracionApi\Helpers\EndpointArgs;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

class CategoriaWS extends Base {
    use EndpointLogger;

    public const ENDPOINT_NAME = 'GetCategoriasWS';
    public const CACHE_KEY_PREFIX = 'mi_api_categorias_';
    public const CACHE_EXPIRATION = 12 * HOUR_IN_SECONDS;

    /**
     * Define los argumentos del endpoint REST.
     *
     * @param bool $is_update Si es una actualización o no
     * @return array<string, mixed> Argumentos del endpoint
     */
    public function get_endpoint_args( bool $is_update = false ): array {
        return [
            'id_categoria' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'ID de la categoría',
                'validate_callback' => function($param) {
                    return is_numeric($param) && $param > 0;
                }
            ],
            'context' => EndpointArgs::context(),
        ];
    }

    /**
     * Ejecuta la lógica del endpoint.
     *
     * @param \WP_REST_Request $request La solicitud REST
     * @return \WP_REST_Response|\WP_Error Respuesta REST o error
     */
    public function execute_restful( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
        try {
            return new \WP_REST_Response($this->format_success_response(), 200);
        } catch (\Exception $e) {
            return new \WP_Error(
                'rest_error',
                $e->getMessage(),
                ['status' => 500]
            );
        }
    }

    /**
     * Comprueba los permisos para acceder al endpoint.
     *
     * Cambio: A partir del 4 de junio de 2025, se permite acceso a cualquier usuario autenticado ('read') ya que el listado de categorías no es información sensible.
     */
    public function permissions_check( \WP_REST_Request $request ): bool|\WP_Error {
        if ( ! current_user_can( 'read' ) ) {
            return new \WP_Error(
                'rest_forbidden',
                esc_html__( 'Debes iniciar sesión para ver esta información.', 'mi-integracion-api' ),
                array( 'status' => 401 )
            );
        }
        return true;
    }

    /**
     * Registra la ruta del endpoint.
     */
    public function register_route(): void {
        register_rest_route(
            'mi-integracion-api/v1',
            '/categorias',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'execute_restful'),
                'permission_callback' => array($this, 'permissions_check'),
                'args'               => $this->get_endpoint_args(),
            )
        );
    }
}
