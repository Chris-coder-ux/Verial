<?php
/**
 * Manejador de endpoints REST API para Mi Integración API
 *
 * Proporciona endpoints REST para la interfaz del plugin.
 *
 * IMPORTANTE: Esta es la ubicación centralizada para todos los endpoints REST API del plugin.
 * Todos los nuevos endpoints DEBEN ser agregados aquí y NO directamente en mi-integracion-api.php
 * para mantener la organización y facilitar el mantenimiento.
 *
 * @package MiIntegracionApi\Core
 * @since 1.0.0
 */

namespace MiIntegracionApi\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente
}

/**
 * Clase para manejar los endpoints REST API del plugin
 */
class REST_API_Handler {
	/**
	 * Namespace para los endpoints REST API
	 */
	const API_NAMESPACE = 'mi-integracion-api/v1';

	/**
	 * Almacena una instancia de la clase
	 *
	 * @var REST_API_Handler
	 */
	private static $instance = null;

	/**
	 * Constructor
	 */
	private function __construct() {
		// Privado para implementar singleton
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Obtiene la instancia única de la clase
	 *
	 * @return REST_API_Handler
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Inicializa el manejador REST API
	 */
	public static function init() {
		self::get_instance();
	}

	/**
	 * Registra las rutas REST API
	 */
	public function register_routes() {
		// Inicializar el endpoint de logs (pero no registrarlo aquí)
		// La propia clase LogsEndpoint registra sus endpoints con su propio namespace
		if ( class_exists( '\MiIntegracionApi\Endpoints\LogsEndpoint' ) ) {
			new \MiIntegracionApi\Endpoints\LogsEndpoint();
		}

		// Endpoint para verificar estado de autenticación
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_auth_status' ),
				'permission_callback' => function () {
					return true; // Permitir acceso público a este endpoint
				},
			)
		);

		// Endpoint para verificar estado de conexión
		register_rest_route(
			self::API_NAMESPACE,
			'/connection/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_connection_status' ),
				'permission_callback' => function ( $request ) {
					// Permitir acceso público a este endpoint
					return true;
				},
			)
		);

		// Endpoint para probar la conexión
		register_rest_route(
			self::API_NAMESPACE,
			'/connection/test',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Endpoint para obtener la configuración
		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// Endpoint para actualizar la configuración
		register_rest_route(
			self::API_NAMESPACE,
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update_settings' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
				'args'                => array(
					'api_url'    => array(
						'required'          => false,
						'sanitize_callback' => 'esc_url_raw',
					),
					'api_key'    => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'debug_mode' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		// --- Registro centralizado de endpoints de negocio ---
		// Obtener las opciones de configuración
		$options = get_option('mi_integracion_api_ajustes', array());
		// Instanciar el conector API con la configuración
		$logger = new \MiIntegracionApi\Helpers\Logger('rest-api-handler');
		$api_connector = new \MiIntegracionApi\Core\ApiConnector($logger);
		
		// Configurar la URL de la API y el número de sesión
		$api_url = isset($options['mia_url_base']) ? $options['mia_url_base'] : '';
		$sesion_wcf = isset($options['mia_numero_sesion']) ? $options['mia_numero_sesion'] : '18';
		$api_connector->set_api_url($api_url);
		$api_connector->set_sesion_wcf($sesion_wcf);

		// Endpoint: Clientes
		$clientes_endpoint = new \MiIntegracionApi\Endpoints\ClientesWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $clientes_endpoint, 'execute_restful' ),
				'permission_callback' => array( $clientes_endpoint, 'permissions_check' ),
				'args'                => $clientes_endpoint->get_endpoint_args( false ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes/(?P<id_cliente_verial>[\\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $clientes_endpoint, 'execute_restful' ),
				'permission_callback' => array( $clientes_endpoint, 'permissions_check' ),
				'args'                => $clientes_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Mascotas
		$mascotas_endpoint = new \MiIntegracionApi\Endpoints\MascotasWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/mascotas',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $mascotas_endpoint, 'execute_restful' ),
				'permission_callback' => array( $mascotas_endpoint, 'permissions_check' ),
				'args'                => $mascotas_endpoint->get_endpoint_args( false ),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/clientes/(?P<id_cliente_param>[\\d]+)/mascotas',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $mascotas_endpoint, 'execute_restful' ),
				'permission_callback' => array( $mascotas_endpoint, 'permissions_check_cliente_mascotas' ),
				'args'                => $mascotas_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Artículos
		$articulos_endpoint = new \MiIntegracionApi\Endpoints\ArticulosWS( $api_connector );
		register_rest_route(
			self::API_NAMESPACE,
			'/articulos',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $articulos_endpoint, 'execute_restful' ),
				'permission_callback' => array( $articulos_endpoint, 'permissions_check' ),
				'args'                => $articulos_endpoint->get_endpoint_args(),
			)
		);
		register_rest_route(
			self::API_NAMESPACE,
			'/articulos/(?P<id_articulo_verial>[\\d]+)',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $articulos_endpoint, 'execute_restful' ),
				'permission_callback' => array( $articulos_endpoint, 'permissions_check' ),
				'args'                => $articulos_endpoint->get_endpoint_args( true ),
			)
		);

		// Endpoint: Provincias
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ProvinciasWS' ) ) {
			$provincias_endpoint = new \MiIntegracionApi\Endpoints\ProvinciasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/provincias',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $provincias_endpoint, 'execute_restful' ),
					'permission_callback' => array( $provincias_endpoint, 'permissions_check' ),
					'args'                => $provincias_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Países
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\PaisesWS' ) ) {
			$paises_endpoint = new \MiIntegracionApi\Endpoints\PaisesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/paises',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $paises_endpoint, 'execute_restful' ),
					'permission_callback' => array( $paises_endpoint, 'permissions_check' ),
					'args'                => $paises_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Agentes
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\AgentesWS' ) ) {
			$agentes_endpoint = new \MiIntegracionApi\Endpoints\AgentesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/agentes',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $agentes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $agentes_endpoint, 'permissions_check' ),
					'args'                => $agentes_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Categorías
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CategoriasWS' ) ) {
			$categorias_endpoint = new \MiIntegracionApi\Endpoints\CategoriasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/categorias-articulos',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $categorias_endpoint, 'execute_restful' ),
					'permission_callback' => array( $categorias_endpoint, 'permissions_check' ),
					'args'                => $categorias_endpoint->get_endpoint_args(),
				)
			);
		}

		// Endpoint: Asignaturas
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\AsignaturasWS' ) ) {
			$asignaturas_endpoint = new \MiIntegracionApi\Endpoints\AsignaturasWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/asignaturas',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $asignaturas_endpoint, 'execute_restful' ),
					'permission_callback' => array( $asignaturas_endpoint, 'permissions_check' ),
					'args'                => $asignaturas_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Historial Pedidos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\HistorialPedidosWS' ) ) {
			$hist_pedidos_endpoint = new \MiIntegracionApi\Endpoints\HistorialPedidosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedidos/historial',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $hist_pedidos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $hist_pedidos_endpoint, 'permissions_check' ),
					'args'                => $hist_pedidos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Colecciones
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ColeccionesWS' ) ) {
			$colecciones_endpoint = new \MiIntegracionApi\Endpoints\ColeccionesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/colecciones',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $colecciones_endpoint, 'execute_restful' ),
					'permission_callback' => array( $colecciones_endpoint, 'permissions_check' ),
					'args'                => $colecciones_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Fabricantes
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\FabricantesWS' ) ) {
			$fabricantes_endpoint = new \MiIntegracionApi\Endpoints\FabricantesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/fabricantes',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $fabricantes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $fabricantes_endpoint, 'permissions_check' ),
					'args'                => $fabricantes_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Imágenes Artículos
		if ( class_exists( 'MI_Endpoint_GetImagenesArticulosWS' ) ) {
			$imagenes_endpoint = new \MI_Endpoint_GetImagenesArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/imagenes',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $imagenes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $imagenes_endpoint, 'permissions_check' ),
					'args'                => $imagenes_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/(?P<id_articulo_verial>[\\d]+)/imagenes',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $imagenes_endpoint, 'execute_restful' ),
					'permission_callback' => array( $imagenes_endpoint, 'permissions_check' ),
					'args'                => $imagenes_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Endpoint: Localidades
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\LocalidadesWS' ) ) {
			$localidades_endpoint = new \MiIntegracionApi\Endpoints\LocalidadesWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/localidades',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $localidades_endpoint, 'execute_restful' ),
					'permission_callback' => array( $localidades_endpoint, 'permissions_check' ),
					'args'                => $localidades_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Árbol Campos Configurables Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ArbolCamposConfigurablesArticulosWS' ) ) {
			$arbol_campos_endpoint = new \MiIntegracionApi\Endpoints\ArbolCamposConfigurablesArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/campos-configurables/arbol',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $arbol_campos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $arbol_campos_endpoint, 'permissions_check' ),
					'args'                => $arbol_campos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Stock Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\GetStockArticulosWS' ) ) {
			$stock_endpoint = new \MiIntegracionApi\Endpoints\GetStockArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/stock',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $stock_endpoint, 'execute_restful' ),
					'permission_callback' => array( $stock_endpoint, 'permissions_check' ),
					'args'                => $stock_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/(?P<id_articulo_verial>[\\d]+)/stock',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $stock_endpoint, 'execute_restful' ),
					'permission_callback' => array( $stock_endpoint, 'permissions_check' ),
					'args'                => $stock_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Endpoint: Valores Validados Campo Configurable Artículos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\ValoresValidadosCampoConfigurableArticulosWS' ) ) {
			$valores_endpoint = new \MiIntegracionApi\Endpoints\ValoresValidadosCampoConfigurableArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/campos-configurables/valores-validados',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $valores_endpoint, 'execute_restful' ),
					'permission_callback' => array( $valores_endpoint, 'permissions_check' ),
					'args'                => $valores_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Siguiente Número de Documento
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NextNumDocsWS' ) ) {
			$nextnumdocs_endpoint = new \MiIntegracionApi\Endpoints\NextNumDocsWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documentos/siguiente-numero',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $nextnumdocs_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nextnumdocs_endpoint, 'permissions_check' ),
					'args'                => $nextnumdocs_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Número de Artículos
		if ( class_exists( 'MiIntegracionApi\Endpoints\GetNumArticulosWS' ) ) {
			$num_articulos_endpoint = new \MiIntegracionApi\Endpoints\GetNumArticulosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/articulos/num',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $num_articulos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $num_articulos_endpoint, 'permissions_check' ),
					'args'                => $num_articulos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Versión del Servicio
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\VersionWS' ) ) {
			$version_endpoint = new \MiIntegracionApi\Endpoints\VersionWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/verial-service/version',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $version_endpoint, 'execute_restful' ),
					'permission_callback' => array( $version_endpoint, 'permissions_check' ),
					'args'                => $version_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Cursos
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CursosWS' ) ) {
			$cursos_endpoint = new \MiIntegracionApi\Endpoints\CursosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cursos',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $cursos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $cursos_endpoint, 'permissions_check' ),
					'args'                => $cursos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Formas de Envío
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\FormasEnvioWS' ) ) {
			$formas_envio_endpoint = new \MiIntegracionApi\Endpoints\FormasEnvioWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/formas-envio',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $formas_envio_endpoint, 'execute_restful' ),
					'permission_callback' => array( $formas_envio_endpoint, 'permissions_check' ),
					'args'                => $formas_envio_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Categorías Web
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CategoriasWebWS' ) ) {
			$categorias_web_endpoint = new \MiIntegracionApi\Endpoints\CategoriasWebWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/categorias-web',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $categorias_web_endpoint, 'execute_restful' ),
					'permission_callback' => array( $categorias_web_endpoint, 'permissions_check' ),
					'args'                => $categorias_web_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Condiciones Tarifa
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\CondicionesTarifaWS' ) ) {
			$condiciones_tarifa_endpoint = new \MiIntegracionApi\Endpoints\CondicionesTarifaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/condiciones-tarifa',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $condiciones_tarifa_endpoint, 'execute_restful' ),
					'permission_callback' => array( $condiciones_tarifa_endpoint, 'permissions_check' ),
					'args'                => $condiciones_tarifa_endpoint->get_endpoint_args(),
				)
			);
		}
		// Endpoint: Métodos de Pago
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\MetodosPagoWS' ) ) {
			$metodospagos_endpoint = new \MiIntegracionApi\Endpoints\MetodosPagoWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/metodos-pago',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $metodospagos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $metodospagos_endpoint, 'permissions_check' ),
					'args'                => $metodospagos_endpoint->get_endpoint_args(),
				)
			);
		}
		// --- Endpoints POST/PUT centralizados ---
		// Nuevo Pago
		if ( class_exists( 'MI_Endpoint_NuevoPagoWS' ) ) {
			$nuevo_pago_endpoint = new \MI_Endpoint_NuevoPagoWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)/pago',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nuevo_pago_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_pago_endpoint, 'permissions_check' ),
					'args'                => $nuevo_pago_endpoint->get_endpoint_args(),
				)
			);
		}
		// Nuevo Cliente
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevoClienteWS' ) ) {
			$nuevo_cliente_endpoint = new \MiIntegracionApi\Endpoints\NuevoClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nuevo_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_cliente_endpoint->get_endpoint_args( false ),
				)
			);
		}
		// Nueva Dirección de Envío
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevaDireccionEnvioWS' ) ) {
			$nueva_direccion_endpoint = new \MiIntegracionApi\Endpoints\NuevaDireccionEnvioWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/direccion-envio',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nueva_direccion_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_direccion_endpoint, 'permissions_check' ),
					'args'                => $nueva_direccion_endpoint->get_endpoint_args( false ),
				)
			);
		}
		// Nueva Mascota (POST y PUT)
		if ( class_exists( 'MI_Endpoint_NuevaMascotaWS' ) ) {
			$nueva_mascota_endpoint = new \MI_Endpoint_NuevaMascotaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nueva_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_mascota_endpoint, 'permissions_check' ),
					'args'                => $nueva_mascota_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota/(?P<id_mascota_verial>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $nueva_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_mascota_endpoint, 'permissions_check' ),
					'args'                => $nueva_mascota_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Nueva Localidad
		if ( class_exists( 'MI_Endpoint_NuevaLocalidadWS' ) ) {
			$nueva_localidad_endpoint = new \MI_Endpoint_NuevaLocalidadWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/localidad',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nueva_localidad_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_localidad_endpoint, 'permissions_check' ),
					'args'                => $nueva_localidad_endpoint->get_endpoint_args(),
				)
			);
		}
		// Nueva Provincia
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevaProvinciaWS' ) ) {
			$nueva_provincia_endpoint = new \MiIntegracionApi\Endpoints\NuevaProvinciaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/provincia',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nueva_provincia_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nueva_provincia_endpoint, 'permissions_check' ),
					'args'                => $nueva_provincia_endpoint->get_endpoint_args(),
				)
			);
		}
		// Update Doc Cliente
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\UpdateDocClienteWS' ) ) {
			$update_doc_endpoint = new \MiIntegracionApi\Endpoints\UpdateDocClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $update_doc_endpoint, 'execute_restful' ),
					'permission_callback' => array( $update_doc_endpoint, 'permissions_check' ),
					'args'                => $update_doc_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Nuevo Documento Cliente (POST y PUT)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\NuevoDocClienteWS' ) ) {
			$nuevo_doc_cliente_endpoint = new \MiIntegracionApi\Endpoints\NuevoDocClienteWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/documento',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $nuevo_doc_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_doc_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_doc_cliente_endpoint->get_endpoint_args( false ),
				)
			);
			register_rest_route(
				self::API_NAMESPACE,
				'/documento/(?P<id_documento_verial>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $nuevo_doc_cliente_endpoint, 'execute_restful' ),
					'permission_callback' => array( $nuevo_doc_cliente_endpoint, 'permissions_check' ),
					'args'                => $nuevo_doc_cliente_endpoint->get_endpoint_args( true ),
				)
			);
		}
		// Borrar Mascota (DELETE)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\BorrarMascotaWS' ) ) {
			$borrar_mascota_endpoint = new \MiIntegracionApi\Endpoints\BorrarMascotaWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/cliente/(?P<id_cliente_verial>[\d]+)/mascota/(?P<id_mascota_verial>[\d]+)',
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $borrar_mascota_endpoint, 'execute_restful' ),
					'permission_callback' => array( $borrar_mascota_endpoint, 'permissions_check' ),
					'args'                => $borrar_mascota_endpoint->get_endpoint_args(),
				)
			);
		}
		// Estado Pedidos (POST)
		if ( class_exists( 'MiIntegracionApi\\Endpoints\\EstadoPedidosWS' ) ) {
			$estado_pedidos_endpoint = new \MiIntegracionApi\Endpoints\EstadoPedidosWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedidos/estados',
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $estado_pedidos_endpoint, 'execute_restful' ),
					'permission_callback' => array( $estado_pedidos_endpoint, 'permissions_check' ),
					'args'                => $estado_pedidos_endpoint->get_endpoint_args(),
				)
			);
		}
		// Pedido Modificable (GET)
		if ( class_exists( 'MI_Endpoint_PedidoModificableWS' ) ) {
			$pedido_modificable_endpoint = new \MI_Endpoint_PedidoModificableWS( $api_connector );
			register_rest_route(
				self::API_NAMESPACE,
				'/pedido/(?P<id_pedido_verial>[\d]+)/modificable',
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $pedido_modificable_endpoint, 'execute_restful' ),
					'permission_callback' => array( $pedido_modificable_endpoint, 'permissions_check' ),
					'args'                => $pedido_modificable_endpoint->get_endpoint_args(),
				)
			);
		}
		// --- Rutas de autenticación y sincronización ---
		// Estas rutas fueron migradas desde REST_Controller.php para centralizar todas las rutas REST

		// Rutas de autenticación JWT
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/token',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'generate_token' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_auth_permissions' ),
				'args'                => array(
					'username' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => function ( $param ) {
							return \MiIntegracionApi\Core\InputValidation::sanitize( $param, 'text' );
						},
					),
					'password' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/validate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'validate_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/refresh',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'refresh_token' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/auth/revoke',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'revoke_token' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_auth_or_admin_permissions' ),
			)
		);

		// Rutas de autenticación para credenciales
		register_rest_route(
			self::API_NAMESPACE,
			'/auth/credentials',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'get_credentials' ),
					'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'save_credentials' ),
					'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
				),
			)
		);

		// Rutas de sincronización
		register_rest_route(
			self::API_NAMESPACE,
			'/sync/start',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'start_sync' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/batch',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'process_next_batch' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/cancel',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'cancel_sync' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/status',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'get_sync_status' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'get_sync_history' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		register_rest_route(
			self::API_NAMESPACE,
			'/sync/retry',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'retry_sync_errors' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		// Rutas para pruebas de API
		register_rest_route(
			self::API_NAMESPACE,
			'/api/test',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'test_api' ),
				'permission_callback' => array( '\MiIntegracionApi\Endpoints\REST_Controller', 'check_admin_permissions' ),
			)
		);

		// --- ENDPOINT: Comprobar conexión Verial ---
		register_rest_route(
			self::API_NAMESPACE,
			'/verial/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_verial_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);

		// --- ENDPOINT: Comprobar conexión WooCommerce ---
		register_rest_route(
			self::API_NAMESPACE,
			'/woocommerce/check',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'check_woocommerce_connection' ),
				'permission_callback' => array( $this, 'check_admin_permissions' ),
			)
		);
	}

	/**
	 * Verifica permisos de administrador
	 *
	 * @return bool
	 */
	public function check_admin_permissions() {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Obtiene el estado de autenticación
	 *
	 * @param \WP_REST_Request $request Objeto de petición REST
	 * @return \WP_REST_Response
	 */
	public function get_auth_status( $request ) {
		// Si tenemos Auth_Manager, usar sus métodos
		if ( class_exists( '\MiIntegracionApi\Core\Auth_Manager' ) ) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			$credentials  = $auth_manager->get_credentials();

			return rest_ensure_response(
				array(
					'authenticated' => ! empty( $credentials['api_url'] ) && ! empty( $credentials['api_key'] ),
					'timestamp'     => time(),
				)
			);
		}

		// Alternativa: Verificar las opciones unificadas de WordPress
		$options = get_option( 'mi_integracion_api_ajustes', array() );
		$has_url = !empty($options['mia_url_base']);
		
		// También verificar opciones individuales para compatibilidad
		if (!$has_url) {
			$has_url = !empty(get_option('mia_url_base', ''));
		}
		
		return rest_ensure_response(
			array(
				'authenticated' => $has_url,
				'timestamp'     => time(),
			)
		);
	}

	/**
	 * Obtiene el estado de conexión con Verial ERP
	 *
	 * @param \WP_REST_Request $request Objeto de petición REST
	 * @return \WP_REST_Response
	 */
	public function get_connection_status( $request ) {
		// Verificar si hay credenciales guardadas
		$auth_connected = false;
		if (class_exists('\MiIntegracionApi\Core\Auth_Manager')) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			if (method_exists($auth_manager, 'has_credentials')) {
				$auth_connected = $auth_manager->has_credentials();
			} elseif (method_exists($auth_manager, 'get_credentials')) {
				$credentials = $auth_manager->get_credentials();
				$auth_connected = !empty($credentials['api_url']) && !empty($credentials['api_key']);
			}
		}

		// Si no pudimos verificar con Auth_Manager, intentar con Config_Manager
		if (!$auth_connected) {
			$config_manager = \MiIntegracionApi\Core\Config_Manager::get_instance();
			$api_url = $config_manager->get('mia_url_base');
			$auth_connected = !empty($api_url);
		}

		// Obtener el último status de conexión guardado
		$status = get_option(
			'mi_integracion_api_connection_status',
			[
				'status' => 'unknown',
				'timestamp' => 0,
				'message' => 'No se ha probado la conexión',
			]
		);

		// Obtener datos de sincronización si están disponibles
		$sync_status = get_option('mi_integracion_api_sync_status', null);

		// Asegurarse de que sync_status tiene la estructura esperada por el frontend
		if (empty($sync_status)) {
			$sync_status = [
				'last_sync' => null,
				'current_sync' => [
					'in_progress' => false,
					'entity' => null,
					'direction' => null,
					'total_items' => 0,
					'processed_items' => 0,
					'started_at' => null,
				],
			];
		}

		// Para el endpoint público, sólo devolver información básica
		$is_admin = current_user_can('manage_options');

		return rest_ensure_response([
			'connected' => $auth_connected,
			'status' => $status,
			'sync_status' => $sync_status,
			'is_admin' => $is_admin,
		]);
	}

	/**
	 * Prueba la conexión con Verial ERP
	 *
	 * @param \WP_REST_Request $request Objeto de petición REST
	 * @return \WP_REST_Response
	 */
	public function test_connection( $request ) {
		try {
			// Obtener configuración desde Config_Manager
			$config_manager = \MiIntegracionApi\Core\Config_Manager::get_instance();
			$config = [
				'api_url' => $config_manager->get('mia_url_base'),
				'sesionwcf' => $config_manager->get('mia_numero_sesion')
			];

			// Instanciar ApiConnector con la configuración
			$api = new \MiIntegracionApi\Core\ApiConnector($config);
			$test_result = $api->test_connection();

			if (is_wp_error($test_result)) {
				$status = [
					'status' => 'error',
					'timestamp' => time(),
					'message' => $test_result->get_error_message(),
				];
			} else {
				$status = [
					'status' => 'success',
					'timestamp' => time(),
					'message' => 'Conexión establecida correctamente',
					'data' => $test_result,
				];
			}

			// Guardar el estado para futuras referencias
			update_option('mi_integracion_api_connection_status', $status);

			return rest_ensure_response($status);
		} catch (\Exception $e) {
			$status = [
				'status' => 'error',
				'timestamp' => time(),
				'message' => $e->getMessage(),
			];

			// Guardar el estado para futuras referencias
			update_option('mi_integracion_api_connection_status', $status);

			return rest_ensure_response($status);
		}
	}

	/**
	 * Obtiene la configuración actual
	 *
	 * @param \WP_REST_Request $request Objeto de petición REST
	 * @return \WP_REST_Response
	 */
	public function get_settings( $request ) {
		// Usar Config_Manager para obtener la configuración
		$config_manager = \MiIntegracionApi\Core\Config_Manager::get_instance();
		
		$api_url = $config_manager->get('mia_url_base');
		$api_key = $config_manager->get('mia_clave_api');
		$debug_mode = $config_manager->get('mia_debug_mode');

		// Maskear la clave API por seguridad
		if (!empty($api_key)) {
			$api_key = '••••••••' . substr($api_key, -4);
		}

		return rest_ensure_response([
			'api_url' => $api_url,
			'api_key' => $api_key,
			'debug_mode' => $debug_mode === 'yes',
			'notification_settings' => [
				'enableToasts' => true,
				'enableSoundEffects' => false,
				'autoDismiss' => true,
				'autoDismissTimeout' => 5000,
				'position' => 'top-right',
			],
		]);
	}

	/**
	 * Actualiza la configuración
	 *
	 * @param \WP_REST_Request $request Objeto de petición REST
	 * @return \WP_REST_Response
	 */
	public function update_settings( $request ) {
		// Usar Config_Manager para actualizar la configuración
		$config_manager = \MiIntegracionApi\Core\Config_Manager::get_instance();
		$params = $request->get_params();

		// Actualizar configuraciones
		if (isset($params['api_url'])) {
			$config_manager->update('mia_url_base', $params['api_url']);
		}
		if (isset($params['api_key'])) {
			$config_manager->update('mia_clave_api', $params['api_key']);
		}
		if (isset($params['debug_mode'])) {
			$config_manager->update('mia_debug_mode', $params['debug_mode'] ? 'yes' : 'no');
		}

		// Limpiar cualquier caché de autenticación
		if (class_exists('\MiIntegracionApi\Core\Auth_Manager')) {
			$auth_manager = \MiIntegracionApi\Core\Auth_Manager::get_instance();
			if (method_exists($auth_manager, 'refresh_credentials')) {
				$auth_manager->refresh_credentials();
			}
		}

		return rest_ensure_response([
			'success' => true,
			'message' => 'Configuración actualizada correctamente',
		]);
	}

	/**
	 * Callback para comprobar la conexión con Verial
	 */
	public function check_verial_connection( $request ) {
		// Obtener las opciones de configuración
		$options = get_option('mi_integracion_api_ajustes', array());
		$api_url = isset($options['mia_url_base']) ? $options['mia_url_base'] : '';
		$sesionwcf = isset($options['mia_numero_sesion']) ? $options['mia_numero_sesion'] : '18';
		$logger = class_exists('MiIntegracionApi\\Helpers\\Logger') ? new \MiIntegracionApi\Helpers\Logger('api_connector') : null;
		if ($logger) {
			$logger->info('[REST][verial/check] Intentando prueba de conexión', [
				'api_url' => $api_url,
				'sesionwcf' => $sesionwcf
			]);
		}
		if (empty($api_url)) {
			if ($logger) {
				$logger->error('[REST][verial/check] URL base de la API de Verial no configurada');
			}
			return rest_ensure_response([
				'success' => false,
				'message' => 'No se ha configurado la URL base de la API de Verial.',
				'api_url' => $api_url,
				'step' => 'config',
			]);
		}
		$config = [
			'api_url' => $api_url,
			'sesionwcf' => $sesionwcf
		];
		$api = new \MiIntegracionApi\Core\ApiConnector($config);
		$result = $api->test_connectivity();
		$last_url = method_exists($api, 'get_last_request_url') ? $api->get_last_request_url() : null;
		if ($result === true) {
			if ($logger) {
				$logger->info('[REST][verial/check] Conexión exitosa', ['url' => $last_url]);
			}
			return rest_ensure_response([
				'success' => true,
				'message' => 'Conexión exitosa con Verial.',
				'url' => $last_url,
			]);
		} else {
			if ($logger) {
				$logger->error('[REST][verial/check] Error de conexión', ['url' => $last_url, 'error' => $result]);
			}
			return rest_ensure_response([
				'success' => false,
				'message' => 'Error de conexión: ' . $result,
				'url' => $last_url,
			]);
		}
	}

	/**
	 * Callback para comprobar la conexión con WooCommerce
	 */
	public function check_woocommerce_connection( $request ) {
		if (!class_exists('WooCommerce')) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => __( 'WooCommerce no está activo.', 'mi-integracion-api' )
			], 500);
		}
		// Prueba básica: obtener número de productos
		try {
			$count = \wc_get_product_count();
			return [
				'success' => true,
				'message' => sprintf( __( 'WooCommerce activo. Productos encontrados: %d', 'mi-integracion-api' ), $count )
			];
		} catch (\Throwable $e) {
			return new \WP_REST_Response([
				'success' => false,
				'message' => __( 'Error al consultar WooCommerce: ', 'mi-integracion-api' ) . $e->getMessage(),
			], 500);
		}
	}
}
