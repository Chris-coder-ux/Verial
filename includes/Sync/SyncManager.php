<?php
/**
 * Gestor de Sincronización para la integración con Verial
 *
 * Maneja la sincronización de productos y categorías entre WooCommerce y Verial
 *
 * @package    MiIntegracionApi
 * @subpackage Sync
 * @since 1.0.0
 */

namespace MiIntegracionApi\Sync;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Cache\Cache_Manager;

// Aseguramos que WooCommerce esté disponible
if (!function_exists('wc_create_product') || !function_exists('wc_update_product')) {
    /**
     * Verificamos que WooCommerce esté activo e incluimos las funciones
     * necesarias para manejar productos si no están ya disponibles
     */
    // Método 1: Usar WC_ABSPATH si está definido
    if (defined('WC_ABSPATH')) {
        // Primero cargar las funciones básicas que pueden ser necesarias
        if (file_exists(WC_ABSPATH . 'includes/wc-core-functions.php')) {
            require_once WC_ABSPATH . 'includes/wc-core-functions.php';
        }
        
        // Luego cargar las funciones de productos
        if (file_exists(WC_ABSPATH . 'includes/wc-product-functions.php')) {
            require_once WC_ABSPATH . 'includes/wc-product-functions.php';
        }
    }
    
    // Método 2: Usar WC() si está disponible
    if ((!function_exists('wc_create_product') || !function_exists('wc_update_product')) && function_exists('WC') && WC() !== null) {
        $plugin_path = WC()->plugin_path();
        
        // Cargar funciones core primero
        if (file_exists($plugin_path . '/includes/wc-core-functions.php')) {
            require_once $plugin_path . '/includes/wc-core-functions.php';
        }
        
        // Luego cargar funciones de producto
        if (file_exists($plugin_path . '/includes/wc-product-functions.php')) {
            require_once $plugin_path . '/includes/wc-product-functions.php';
        }
    }
    
    // Método 3: Intentar con la ruta predeterminada de plugins
    if (!function_exists('wc_create_product') || !function_exists('wc_update_product')) {
        // Asumimos que WP_PLUGIN_DIR está definido
        if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/includes/wc-product-functions.php')) {
            require_once WP_PLUGIN_DIR . '/woocommerce/includes/wc-product-functions.php';
        }
    }
}

/**
 * Clase SyncManager
 * 
 * Maneja la sincronización de productos y categorías entre WooCommerce y Verial
 */
class SyncManager {
    /**
     * Instancia única de esta clase (patrón Singleton)
     * 
     * @var SyncManager
     */
    private static $instance = null;
    
    /**
     * Conector de API para Verial
     * 
     * @var ApiConnector
     */
    private $api_connector;
    
    /**
     * Gestor de caché
     * 
     * @var Cache_Manager
     */
    private $cache_manager;
    
    /**
     * Logger para registrar errores y eventos
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Tipo de sincronización actual
     * 
     * @var string
     */
    private $current_sync_type;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->cache_manager = \MiIntegracionApi\CacheManager::get_instance();
        $this->logger = new \MiIntegracionApi\Helpers\Logger('sync-manager');
        
        // Inicializar ApiConnector con manejo de excepciones
        try {
            $this->api_connector = new \MiIntegracionApi\Core\ApiConnector($this->logger);
            $this->logger->log('[SUCCESS] SyncManager inicializado correctamente con ApiConnector', \MiIntegracionApi\Helpers\Logger::LEVEL_INFO);
        } catch (\Exception $e) {
            $this->logger->log('[ERROR] Error al inicializar ApiConnector en SyncManager: ' . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR);
            // Mantener referencia nula para detectar error en métodos que lo usen
            $this->api_connector = null;
        }
        
        // Asegurar que la tabla de mapeo exista
        $this->create_mapping_table();
        
        // Registrar hooks para la sincronización automática
        add_action('init', array($this, 'register_scheduled_sync'));
        add_action('mi_integracion_api_daily_sync', array($this, 'run_daily_sync'));
        
        // Hooks para sincronización por evento
        add_action('woocommerce_update_product', array($this, 'on_product_updated'), 10, 1);
        add_action('woocommerce_new_product', array($this, 'on_product_created'), 10, 1);
    }
    
    /**
     * Obtener la instancia única de esta clase (patrón Singleton)
     * 
     * @return SyncManager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Registra el evento programado para sincronización diaria
     */
    public function register_scheduled_sync() {
        if (!wp_next_scheduled('mi_integracion_api_daily_sync')) {
            wp_schedule_event(time(), 'daily', 'mi_integracion_api_daily_sync');
        }
    }
    
    /**
     * Ejecuta la sincronización diaria programada
     */
    public function run_daily_sync() {
        $this->logger->log("Iniciando sincronización diaria programada");
        
        // Verificar que ApiConnector esté correctamente configurado
        $api_status = $this->is_api_connector_valid();
        if (!$api_status['is_valid']) {
            $this->logger->log('[ERROR] Sincronización diaria cancelada: ' . $api_status['error'], 'error');
            return;
        }
        
        try {
            // Sincronizar categorías primero
            $this->sync_categories();
            
            // Luego sincronizar productos
            $this->sync_products();
            
            $this->logger->log("Sincronización diaria completada");
        } catch (\Exception $e) {
            $this->logger->log("Error en sincronización diaria: " . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Maneja la actualización de productos en WooCommerce
     * 
     * @param int $product_id ID del producto
     */
    public function on_product_updated($product_id) {
        $this->logger->log("Producto #$product_id actualizado en WooCommerce");
        
        // No sincronizamos cada actualización para evitar sobrecarga
        // Si se necesita sincronización inmediata, descomentar la siguiente línea:
        $this->sync_single_product($product_id);
    }
    
    /**
     * Maneja la creación de productos en WooCommerce
     * 
     * @param int $product_id ID del producto
     */
    public function on_product_created($product_id) {
        $this->logger->log("Producto #$product_id creado en WooCommerce");
        
        // Sincronización inmediata de productos nuevos
        // Descomentar la siguiente línea para activar la sincronización automática:
        $this->sync_single_product($product_id);
    }
    
    /**
     * Ejecuta la sincronización de categorías entre Verial y WooCommerce
     * 
     * @param bool $force Forzar sincronización completa ignorando caché
     * @return array Resultado de la sincronización
     */
    public function sync_categories($force = false) {
        $this->current_sync_type = 'categories';
        $this->logger->log("Iniciando sincronización de categorías" . ($force ? " (forzada)" : ""));
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'total_processed' => 0,
            'start_time' => microtime(true)
        ];
        
        try {
            // Verificar cache si no es forzada
            if (!$force && ($cached = $this->cache_manager->get('categories_last_sync'))) {
                if ((time() - $cached['time']) < 3600) { // 1 hora de caché
                    $this->logger->log("Usando datos de categorías en caché (actualizado hace " . human_time_diff($cached['time']) . ")");
                    return [
                        'status' => 'success',
                        'message' => 'Usando datos en caché',
                        'stats' => $cached['stats']
                    ];
                }
            }
            
            // Obtener categorías de Verial
            $verial_categories = $this->get_verial_categories();
            
            if (!$verial_categories) {
                throw new \Exception("No se pudieron obtener categorías de Verial");
            }
            
            $this->logger->log("Se obtuvieron " . count($verial_categories) . " categorías de Verial");
            
            // Procesar cada categoría
            foreach ($verial_categories as $category) {
                $stats['total_processed']++;
                
                $result = $this->process_category($category);
                
                if ($result['status'] === 'created') {
                    $stats['created']++;
                } elseif ($result['status'] === 'updated') {
                    $stats['updated']++;
                } elseif ($result['status'] === 'error') {
                    $stats['errors']++;
                }
            }
            
            // Actualizar caché
            $stats['duration'] = microtime(true) - $stats['start_time'];
            $this->cache_manager->set('categories_last_sync', [
                'time' => time(),
                'stats' => $stats
            ], 3600); // 1 hora de caché
            
            $this->logger->log("Sincronización de categorías completada: " . 
                               "{$stats['created']} creadas, {$stats['updated']} actualizadas, {$stats['errors']} errores");
            
            return [
                'status' => 'success',
                'message' => 'Sincronización de categorías completada',
                'stats' => $stats
            ];
        } catch (\Exception $e) {
            $this->logger->log("Error en sincronización de categorías: " . $e->getMessage(), 'error');
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'stats' => $stats
            ];
        }
    }
    
    /**
     * Ejecuta la sincronización de productos entre Verial y WooCommerce
     * 
     * @param array $filters Filtros opcionales para la sincronización
     * @param bool $force Forzar sincronización completa ignorando caché
     * @return array Resultado de la sincronización
     */
    public function sync_products($filters = [], $force = false) {
        $this->current_sync_type = 'products';
        $this->logger->log("Iniciando sincronización de productos" . ($force ? " (forzada)" : ""));
        
        $stats = [
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'total_processed' => 0,
            'start_time' => microtime(true)
        ];
        
        try {
            // Configuración de paginación para obtener todos los productos desde Verial
            $page_size = 50; // Tamaño máximo recomendado por página
            $current_page = 1;
            $has_more = true;
            $total_processed = 0;
            $last_sync_date = null;
            
            // Si no es forzada, usar la fecha de última sincronización para obtener solo cambios
            if (!$force) {
                $last_sync = get_option('mi_integracion_api_last_product_sync', null);
                if ($last_sync) {
                    $last_sync_date = date('Y-m-d', $last_sync);
                    $this->logger->log("Sincronizando productos modificados desde: $last_sync_date");
                }
            }
            
            // Procesamiento por lotes utilizando la paginación de la API
            while ($has_more) {
                $inicio = (($current_page - 1) * $page_size) + 1;
                $fin = $inicio + $page_size - 1;
                
                $this->logger->log("Obteniendo productos de Verial (página $current_page, rango $inicio-$fin)");
                
                // Llamar a la API con los parámetros de paginación correctos
                $params = [
                    'inicio' => $inicio,
                    'fin' => $fin
                ];
                
                // Añadir fecha de última sincronización si existe
                if ($last_sync_date) {
                    $params['fecha'] = $last_sync_date;
                }
                
                $productos = $this->api_connector->get_articulos($params);
                
                // Verificar si recibimos datos válidos
                if (!$productos || is_wp_error($productos)) {
                    if (is_wp_error($productos)) {
                        $error_message = $productos->get_error_message();
                        $this->logger->log("Error al obtener productos de Verial: $error_message", 'error');
                    } else {
                        $this->logger->log("No se recibieron productos válidos de Verial", 'error');
                    }
                    break;
                }
                
                // La API puede devolver productos en una estructura anidada
                if (is_array($productos) && isset($productos['Articulos']) && is_array($productos['Articulos'])) {
                    $this->logger->log("Detectada estructura anidada en respuesta API. Extrayendo artículos de la clave 'Articulos'");
                    $productos = $productos['Articulos'];
                }
                
                // Asegurarse de que la estructura sea un array ahora
                if (!is_array($productos)) {
                    $this->logger->log("Formato de respuesta inesperado: " . gettype($productos), 'error');
                    $this->logger->log("Contenido de respuesta: " . print_r($productos, true), 'error');
                    break;
                }
                
                // Si no hay productos o hay menos que el tamaño de página, no hay más para procesar
                if (empty($productos) || count($productos) < $page_size) {
                    $has_more = false;
                }
                
                $this->logger->log("Recibidos " . count($productos) . " productos de Verial");
                
                // Procesar cada producto
                foreach ($productos as $producto) {
                    $total_processed++;
                    
                    try {
                        // Verificar filtros si existen
                        if (!$this->product_passes_filters($producto, $filters)) {
                            continue;
                        }
                        
                        $result = $this->process_product($producto);
                        
                        if ($result['status'] === 'created') {
                            $stats['created']++;
                        } elseif ($result['status'] === 'updated') {
                            $stats['updated']++;
                        } else {
                            $stats['errors']++;
                        }
                    } catch (\Exception $e) {
                        $stats['errors']++;
                        $this->logger->log("Error al procesar producto: " . $e->getMessage(), 'error');
                    }
                }
                
                // Pasar a la siguiente página
                $current_page++;
            }
            
            $stats['total_processed'] = $total_processed;
            $stats['execution_time'] = microtime(true) - $stats['start_time'];
            
            // Guardar la fecha de sincronización
            update_option('mi_integracion_api_last_product_sync', time());
            
            $this->logger->log("Sincronización de productos completada. Procesados: {$stats['total_processed']}, Creados: {$stats['created']}, Actualizados: {$stats['updated']}, Errores: {$stats['errors']}");
            
            return [
                'status' => 'success',
                'message' => "Sincronización completada con éxito",
                'stats' => $stats
            ];
            
        } catch (\Exception $e) {
            $this->logger->log("Error en sincronización de productos: " . $e->getMessage(), 'error');
            
            return [
                'status' => 'error',
                'message' => $e->getMessage(),
                'stats' => $stats
            ];
        }
    }
    
    /**
     * Sincroniza un solo producto de WooCommerce con Verial
     * 
     * @param int $product_id ID del producto en WooCommerce
     * @return array Resultado de la sincronización
     */
    public function sync_single_product($product_id) {
        $this->current_sync_type = 'single_product';
        $this->logger->log("Iniciando sincronización de producto #$product_id");
        
        try {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                throw new \Exception("No se pudo obtener el producto #$product_id");
            }
            
            // Obtener el SKU del producto
            $sku = $product->get_sku();
            
            if (empty($sku)) {
                // En lugar de lanzar una excepción, intentamos usar un SKU automático
                $auto_sku = 'AUTO-' . $product_id;
                $this->logger->log("Producto #$product_id no tiene SKU. Asignando SKU automático: $auto_sku", 'warning');
                
                // Actualizar el producto con el nuevo SKU
                $product->set_sku($auto_sku);
                $product->save();
                
                // Usar el nuevo SKU para la sincronización
                $sku = $auto_sku;
            }
            
            // Buscar el producto en Verial por SKU/referencia
            $verial_product = $this->find_verial_product_by_sku($sku);
            
            if (!$verial_product) {
                throw new \Exception("No se encontró el producto en Verial con SKU: $sku");
            }
            
            // Actualizar el mapeo
            $this->update_product_mapping($product_id, $verial_product['Id'], $sku);
            
            $this->logger->log("Producto #$product_id sincronizado con ID de Verial: {$verial_product['Id']}");
            
            return [
                'status' => 'success',
                'message' => "Producto #$product_id sincronizado correctamente",
                'verial_id' => $verial_product['Id']
            ];
        } catch (\Exception $e) {
            $this->logger->log("Error al sincronizar producto #$product_id: " . $e->getMessage(), 'error');
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene las categorías de Verial
     * 
     * @return array|bool Lista de categorías o false si hay error
     */
    private function get_verial_categories() {
        try {
            // Obtener categorías específicas para web si están disponibles
            $sessionWcf = $this->api_connector->getSesionWcf();
            $this->logger->log("Obteniendo categorías web desde Verial (sesión: $sessionWcf)");
            $result = $this->api_connector->get('GetCategoriasWebWS&x=' . $sessionWcf);
            
            if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                // Si hay error, intentar con categorías normales
                $this->logger->log("No se pudieron obtener categorías web, usando categorías estándar", 'warning');
                $result = $this->api_connector->get('GetCategoriasWS&x=' . $sessionWcf);
            }
            
            if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                throw new \Exception("Error al obtener categorías: " . $result['InfoError']['Descripcion']);
            }
            
            if (isset($result['Categorias']) && is_array($result['Categorias'])) {
                return $result['Categorias'];
            } elseif (isset($result['CategoriasWeb']) && is_array($result['CategoriasWeb'])) {
                return $result['CategoriasWeb'];
            }
            
            return [];
        } catch (\Exception $e) {
            $this->logger->log("Error al obtener categorías de Verial: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Obtiene los productos de Verial
     * 
     * @param string $endpoint_with_filters Endpoint con filtros aplicados
     * @return array|bool Lista de productos o false si hay error
     */
    private function get_verial_products($endpoint_with_filters) {
        try {
            $this->logger->log("Obteniendo productos desde Verial con endpoint: $endpoint_with_filters");
            $result = $this->api_connector->get($endpoint_with_filters);
            
            if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                throw new \Exception("Error al obtener productos: " . $result['InfoError']['Descripcion']);
            }
            
            if (isset($result['Articulos']) && is_array($result['Articulos'])) {
                return $result['Articulos'];
            }
            
            return [];
        } catch (\Exception $e) {
            $this->logger->log("Error al obtener productos de Verial: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Busca un producto en Verial por SKU
     * 
     * @param string $sku SKU del producto
     * @return array|bool Datos del producto o false si no se encuentra
     */
    private function find_verial_product_by_sku($sku) {
        $this->logger->log("Buscando producto en Verial con SKU: $sku");
        
        // Verificar que api_connector esté inicializado
        if (!$this->api_connector) {
            $this->logger->log("Error: ApiConnector no está inicializado correctamente", 'error');
            return false;
        }
        
        try {
            // Comprobar si el método call existe en el api_connector
            if (!method_exists($this->api_connector, 'get')) {
                $this->logger->log("Error: El método 'get' no existe en ApiConnector", 'error');
                return false;
            }
            
            // Usar el filtro por referencia/código de barras
            $sessionWcf = $this->api_connector->getSesionWcf();
            $endpoint = 'GetArticulosWS&referenciaBarras=' . urlencode($sku) . '&x=' . $sessionWcf;
            $this->logger->log("Llamando a API endpoint: $endpoint (sesión: $sessionWcf)");
            
            // Llamar al método correcto según la implementación de ApiConnector
            $result = $this->api_connector->get($endpoint);
            
            $this->logger->log("Respuesta API recibida: " . print_r($result, true));
            
            if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                throw new \Exception("Error al buscar producto por SKU: " . $result['InfoError']['Descripcion']);
            }
            
            if (isset($result['Articulos']) && is_array($result['Articulos']) && count($result['Articulos']) > 0) {
                $this->logger->log("Encontrado producto en Verial con ID: " . $result['Articulos'][0]['Id']);
                return $result['Articulos'][0];
            }
            
            $this->logger->log("No se encontraron productos en Verial con SKU: $sku", 'warning');
            return false;
        } catch (\Throwable $e) {
            $this->logger->log("Error al buscar producto por SKU '$sku': " . $e->getMessage(), 'error');
            $this->logger->log("Detalle del error: " . $e->getTraceAsString(), 'error');
            return false;
        }
    }
    
    /**
     * Procesa una categoría de Verial (creación o actualización en WooCommerce)
     * 
     * @param array $category Datos de la categoría de Verial
     * @return array Resultado del procesamiento
     */
    private function process_category($category) {
        try {
            $category_id = $category['Id'];
            $category_name = $category['Nombre'];
            
            $this->logger->log("Procesando categoría #$category_id: $category_name");
            
            // Buscar si esta categoría ya existe en WooCommerce
            $existing_term_id = $this->get_wc_category_by_verial_id($category_id);
            
            if ($existing_term_id) {
                // Actualizar categoría existente
                wp_update_term($existing_term_id, 'product_cat', [
                    'name' => $category_name,
                    'slug' => sanitize_title($category_name)
                ]);
                // Refuerza la actualización del metadato _verial_category_id en actualización
                update_term_meta($existing_term_id, '_verial_category_id', $category_id);
                
                $this->logger->log("Categoría #$category_id actualizada en WooCommerce ($existing_term_id)");
                
                return [
                    'status' => 'updated',
                    'wc_id' => $existing_term_id,
                    'verial_id' => $category_id
                ];
            } else {
                // Crear nueva categoría
                $term = wp_insert_term($category_name, 'product_cat', [
                    'slug' => sanitize_title($category_name)
                ]);
                
                if (is_wp_error($term)) {
                    throw new \Exception("Error al crear categoría: " . $term->get_error_message());
                }
                
                $term_id = $term['term_id'];
                
                // Guardar el ID de Verial como metadato
                update_term_meta($term_id, '_verial_category_id', $category_id);
                
                $this->logger->log("Categoría #$category_id creada en WooCommerce ($term_id)");
                
                return [
                    'status' => 'created',
                    'wc_id' => $term_id,
                    'verial_id' => $category_id
                ];
            }
        } catch (\Exception $e) {
            $this->logger->log("Error al procesar categoría #{$category['Id']}: " . $e->getMessage(), 'error');
            
            return [
                'status' => 'error',
                'verial_id' => $category['Id'],
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Procesa un producto de Verial (creación o actualización en WooCommerce)
     * 
     * @param array $product Datos del producto de Verial
     * @return array Resultado del procesamiento
     */
    private function process_product($product) {
        try {
            // Intentar cargar las funciones de WooCommerce explícitamente primero
            $this->load_woocommerce_functions();
            
            // Verificar que WooCommerce esté disponible
            if (!$this->is_woocommerce_available()) {
                $this->logger->log("Fallo al intentar utilizar WooCommerce", 'error');
                throw new \Exception("WooCommerce no está disponible. No se pueden procesar productos.");
            }
            
            // Verificación adicional de que las funciones de WooCommerce están disponibles
            if (!function_exists('wc_create_product') || !function_exists('wc_update_product')) {
                $this->logger->log("Las funciones de WooCommerce aún no están disponibles después de intentar cargarlas", 'error');
                throw new \Exception("Imposible procesar productos. Funciones de WooCommerce no disponibles.");
            }
            
            // Verificar que tengamos los datos mínimos necesarios
            if (!isset($product['Id']) || !isset($product['Nombre'])) {
                $this->logger->log("Error en datos de producto: Falta Id o Nombre", 'error');
                $this->logger->log("Datos de producto recibidos: " . print_r($product, true), 'error');
                throw new \Exception("Datos de producto incompletos desde Verial");
            }
            
            $verial_id = $product['Id'];
            $product_name = $product['Nombre'];
            
            // Registrar información detallada sobre SKUs disponibles
            $debug_info = [
                'ReferenciaBarras' => isset($product['ReferenciaBarras']) ? $product['ReferenciaBarras'] : 'N/A',
                'ReferenciaInterna' => isset($product['ReferenciaInterna']) ? $product['ReferenciaInterna'] : 'N/A',
                'ID_Referencia' => isset($product['ID_Referencia']) ? $product['ID_Referencia'] : 'N/A'
            ];
            $this->logger->log("Datos de referencia para SKU del producto #$verial_id: " . json_encode($debug_info), 'debug');
            
            // Generar SKU inteligente utilizando múltiples fuentes de información
            $sku = $this->generate_unique_sku($product);
            
            $this->logger->log("Procesando producto #$verial_id: $product_name (SKU generado: $sku)");
            
            // Buscar si este producto ya existe en WooCommerce
            $existing_product_id = $this->get_wc_product_by_verial_id($verial_id);
            $this->logger->log("Búsqueda por ID Verial: " . ($existing_product_id ? "Encontrado #$existing_product_id" : "No encontrado"));
            
            // Si no se encontró por ID de Verial pero tiene SKU, buscar por SKU
            if (!$existing_product_id && !empty($sku)) {
                $existing_product_id = wc_get_product_id_by_sku($sku);
                $this->logger->log("Búsqueda por SKU '$sku': " . ($existing_product_id ? "Encontrado #$existing_product_id" : "No encontrado"));
            }
            
            // Preparar los datos del producto
            $precio = $this->get_product_price($verial_id, $product);
            
            // WooCommerce requiere que el precio sea un string
            $precio_formatted = (string) number_format($precio, 2, '.', '');
            $this->logger->log("Precio formateado para WooCommerce: $precio_formatted");
            
            $product_data = [
                'name' => $product_name,
                'sku' => $sku,
                'regular_price' => $precio_formatted, // Usar el precio formateado
                'status' => 'publish',
                'catalog_visibility' => 'visible',
                'type' => 'simple' // Asegurarnos de especificar el tipo de producto
            ];
            
            $this->logger->log("Datos de producto preparados: " . print_r($product_data, true));
            
            // Añadir descripción si está disponible
            if (isset($product['Descripcion']) && !empty($product['Descripcion'])) {
                $product_data['description'] = $product['Descripcion'];
            }
            
            // Determinar la categoría
            $category_ids = [];
            if (isset($product['ID_Categoria']) && $product['ID_Categoria'] > 0) {
                $wc_cat_id = $this->get_wc_category_by_verial_id($product['ID_Categoria']);
                if ($wc_cat_id) {
                    $category_ids[] = $wc_cat_id;
                }
            }
            
            // También verificar categorías web si están disponibles
            foreach (['ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'] as $cat_key) {
                if (isset($product[$cat_key]) && $product[$cat_key] > 0) {
                    $wc_cat_id = $this->get_wc_category_by_verial_id($product[$cat_key]);
                    if ($wc_cat_id && !in_array($wc_cat_id, $category_ids)) {
                        $category_ids[] = $wc_cat_id;
                    }
                }
            }
            
            if (!empty($category_ids)) {
                $product_data['category_ids'] = $category_ids;
            }
            
            if ($existing_product_id) {
                // Actualizar producto existente
                $product_data['id'] = $existing_product_id;
                $this->logger->log("Actualizando producto WooCommerce #$existing_product_id con datos: " . print_r($product_data, true));
                
                try {
                    // Verificar que WooCommerce esté disponible antes de actualizar
                    if (!$this->is_woocommerce_available()) {
                        throw new \Exception("Las funciones de WooCommerce no están disponibles para actualizar");
                    }
                    
                    // Verificamos si la función existe directamente o en el espacio global
                    if (function_exists('wc_update_product')) {
                        $wc_product = wc_update_product($product_data);
                    } elseif (function_exists('\wc_update_product')) {
                        $wc_product = \wc_update_product($product_data);
                    } else {
                        throw new \Exception("La función wc_update_product no está disponible. Asegúrate de que WooCommerce está correctamente instalado y activado.");
                    }
                    
                    if (is_wp_error($wc_product)) {
                        $error_msg = $wc_product->get_error_message();
                        $this->logger->log("Error al actualizar producto: $error_msg", 'error');
                        throw new \Exception("Error al actualizar producto: $error_msg");
                    }
                    
                    // Asegurarse de que el mapeo está actualizado
                    $this->update_product_mapping($existing_product_id, $verial_id, $sku);
                    
                    $this->logger->log("Producto #$verial_id actualizado en WooCommerce ($existing_product_id)");
                    
                    return [
                        'status' => 'updated',
                        'wc_id' => $existing_product_id,
                        'verial_id' => $verial_id
                    ];
                } catch (\Exception $e) {
                    $this->logger->log("Error en wc_update_product: " . $e->getMessage(), 'error');
                    throw $e;
                }
            } else {
                // Crear nuevo producto
                $this->logger->log("Creando nuevo producto: " . $producto['SKU'], 'debug');
                
                try {
                    // Verificar que WooCommerce esté disponible antes de crear
                    if (!$this->is_woocommerce_available()) {
                        throw new \Exception("Las funciones de WooCommerce no están disponibles para crear productos.");
                    }
                    
                    // Verificamos si la función existe directamente o en el espacio global
                    if (function_exists('wc_create_product')) {
                        $wc_product = wc_create_product($product_data);
                    } elseif (function_exists('\wc_create_product')) {
                        $wc_product = \wc_create_product($product_data);
                    } else {
                        throw new \Exception("La función wc_create_product no está disponible. Asegúrate de que WooCommerce está correctamente instalado y activado.");
                    }
                    
                    if (isset($producto['Descripcion'])) {
                        $wc_product->set_description($producto['Descripcion']);
                    }
                    // Guardar el ID de Verial como meta
                    $wc_product->update_meta_data('_verial_product_id', $verial_id);
                    
                    // Guardar el producto
                    $product_id = $wc_product->save();
                    
                    if (!$product_id) {
                        throw new \Exception("No se pudo guardar el producto en la base de datos");
                    }
                    
                    $this->logger->log("Producto creado exitosamente con ID: $product_id");
                    
                    // Guardar el ID de Verial como metadato y en la tabla de mapeo
                    update_post_meta($product_id, '_verial_product_id', $verial_id);
                    $this->update_product_mapping($product_id, $verial_id, $sku);
                    
                    $this->logger->log("Producto #$verial_id creado en WooCommerce ($product_id)");
                    
                    return [
                        'status' => 'created',
                        'wc_id' => $product_id,
                        'verial_id' => $verial_id
                    ];
                } catch (\Exception $e) {
                    $this->logger->log("Error en wc_create_product: " . $e->getMessage(), 'error');
                    throw $e;
                }
            }
        } catch (\Exception $e) {
            $this->logger->log("Error al procesar producto #{$product['Id']}: " . $e->getMessage(), 'error');
            
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtiene el precio de un producto de Verial
     * 
     * @param int $verial_id ID del producto en Verial
     * @param array $product_data Datos del producto (opcional)
     * @return float Precio del producto
     */
    private function get_product_price($verial_id, $product_data = null) {
        try {
            // Si ya tenemos los datos del producto con el precio, usarlos
            if ($product_data) {
                // Verificar diferentes posibles nombres de campo para precio
                if (isset($product_data['Precio']) && is_numeric($product_data['Precio'])) {
                    $this->logger->log("Usando Precio ya disponible para #$verial_id: " . $product_data['Precio'], 'debug');
                    return floatval($product_data['Precio']);
                }
                
                if (isset($product_data['PVP']) && is_numeric($product_data['PVP'])) {
                    $this->logger->log("Usando PVP ya disponible para #$verial_id: " . $product_data['PVP'], 'debug');
                    return floatval($product_data['PVP']);
                }
            }
            
            // Si no hay datos o no tienen precio, obtenerlo de la API
            $result = $this->api_connector->get_condiciones_tarifa($verial_id, 0); // id_cliente=0 para tarifa general
            
            // Verificar si la respuesta es válida y contiene precios
            if (isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] == 0 && 
                isset($result['Condiciones']) && is_array($result['Condiciones']) && count($result['Condiciones']) > 0) {
                
                // Obtener el primer precio disponible
                $precio = floatval($result['Condiciones'][0]['Precio']);
                
                // Verificar que sea un precio válido
                if ($precio > 0) {
                    $this->logger->log("Precio #$verial_id obtenido desde API: $precio", 'debug');
                    return $precio;
                }
            }
            
            // Si no se pudo obtener un precio válido
            $this->logger->log("No se pudo obtener precio válido para #$verial_id, usando 0", 'debug');
            return 0;
        } catch (\Exception $e) {
            $this->logger->log("Error al obtener precio #$verial_id: " . $e->getMessage(), 'error');
            return 0;
        }
    }
    
    /**
     * Actualiza o crea un mapeo entre un producto de WooCommerce y Verial
     * 
     * @param int $wc_id ID del producto en WooCommerce
     * @param int $verial_id ID del producto en Verial
     * @param string $sku SKU del producto
     * @return bool true si fue exitoso, false si falló
     */
    public function update_product_mapping($wc_id, $verial_id, $sku) {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'verial_product_mapping';
            
            $this->logger->log("Actualizando mapeo de producto: WC ID #$wc_id - Verial ID #$verial_id - SKU: $sku");
            
            // Verificar si la tabla existe, si no, crearla
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
                $this->logger->log("Tabla de mapeo no encontrada, intentando crear: $table_name");
                $this->create_mapping_table();
                $this->logger->log("Tabla de mapeo creada: $table_name");
            }
            
            // Actualizar metadatos en WordPress
            update_post_meta($wc_id, '_verial_product_id', $verial_id);
            $this->logger->log("Metadata _verial_product_id actualizado para producto #$wc_id");
            
            // Verificar si ya existe un mapeo
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM $table_name WHERE wc_id = %d OR verial_id = %d",
                $wc_id, $verial_id
            ));
            
            if ($existing) {
                // Actualizar mapeo existente
                $wpdb->update(
                    $table_name,
                    [
                        'wc_id' => $wc_id,
                        'verial_id' => $verial_id,
                        'sku' => $sku,
                        'updated_at' => current_time('mysql')
                    ],
                    ['id' => $existing],
                    ['%d', '%d', '%s', '%s'],
                    ['%d']
                );
                $this->logger->log("Mapeo existente actualizado: ID #$existing");
            } else {
                // Crear nuevo mapeo
                $result = $wpdb->insert(
                    $table_name,
                    [
                        'wc_id' => $wc_id,
                        'verial_id' => $verial_id,
                        'sku' => $sku,
                        'created_at' => current_time('mysql'),
                        'updated_at' => current_time('mysql')
                    ],
                    ['%d', '%d', '%s', '%s', '%s']
                );
                if ($result) {
                    $new_id = $wpdb->insert_id;
                    $this->logger->log("Nuevo mapeo creado: ID #$new_id");
                } else {
                    $this->logger->log("Error al insertar nuevo mapeo: " . $wpdb->last_error, 'error');
                }
            }
            
            return true;
        } catch (\Exception $e) {
            $this->logger->log("Error al actualizar mapeo de producto: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Crea la tabla de mapeo de productos entre WooCommerce y Verial
     * 
     * @return bool true si fue exitoso, false si falló
     */
    private function create_mapping_table() {
        try {
            global $wpdb;
            $table_name = $wpdb->prefix . 'verial_product_mapping';
            
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                wc_id bigint(20) NOT NULL,
                verial_id bigint(20) NOT NULL,
                sku varchar(100) DEFAULT '',
                created_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                updated_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
                PRIMARY KEY  (id),
                KEY wc_id (wc_id),
                KEY verial_id (verial_id),
                KEY sku (sku)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            dbDelta($sql);
            
            return true;
        } catch (\Exception $e) {
            $this->logger->log("Error al crear tabla de mapeo: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Obtiene el ID de categoría de WooCommerce asociada con el ID de categoría de Verial
     * 
     * @param int $verial_category_id ID de la categoría en Verial
     * @return int|bool ID de la categoría en WooCommerce o false si no se encuentra
     */
    private function get_wc_category_by_verial_id($verial_category_id) {
        $terms = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'meta_query' => [
                [
                    'key' => '_verial_category_id',
                    'value' => $verial_category_id,
                    'compare' => '='
                ]
            ]
        ]);
        
        if (!is_wp_error($terms) && !empty($terms)) {
            return $terms[0]->term_id;
        }
        
        return false;
    }
    
    /**
     * Obtiene el ID de producto de WooCommerce asociado con el ID de producto de Verial
     * 
     * @param int $verial_product_id ID del producto en Verial
     * @return int|bool ID del producto en WooCommerce o false si no se encuentra
     */
    private function get_wc_product_by_verial_id($verial_product_id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'verial_product_mapping';
        
        // Primero, verificar en la tabla de mapeo
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $wc_id = $wpdb->get_var($wpdb->prepare(
                "SELECT wc_id FROM $table_name WHERE verial_id = %d",
                $verial_product_id
            ));
            
            if ($wc_id) {
                return $wc_id;
            }
        }
        
        // Si no se encontró en la tabla de mapeo, buscar en los metadatos
        $products = wc_get_products([
            'meta_key' => '_verial_product_id',
            'meta_value' => $verial_product_id,
            'limit' => 1
        ]);
        
        if (!empty($products)) {
            return $products[0]->get_id();
        }
        
        return false;
    }
    
    /**
     * Sincroniza productos por lotes (batch)
     * 
     * Procesa un lote de productos desde Verial a WooCommerce.
     * Este método es llamado por AjaxSync::sync_products_batch()
     *
     * @param int $batch_size Tamaño del lote a procesar
     * @param int $offset Desplazamiento para la paginación
     * @return array Resultado de la sincronización con información de progreso
     */
    public function sync_products_batch($batch_size = 20, $offset = 0) {
        $this->logger->log("Iniciando sincronización de productos en lote (batch_size: $batch_size, offset: $offset)", 'info');
        
        // Verificamos primero si hay una solicitud de cancelación
        if (get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada')) {
            $this->logger->log("Sincronización cancelada por el usuario", 'info');
            
            // Limpiamos los transients relacionados con la sincronización
            delete_transient('mi_integracion_api_sync_products_in_progress');
            delete_transient('mi_integracion_api_sync_products_offset');
            delete_transient('mi_integracion_api_sync_products_batch_count');
            
            // Limpiamos la flag de cancelación para futuras sincronizaciones
            delete_option('mia_sync_cancelada');
            delete_transient('mia_sync_cancelada');
            
            return [
                'success' => true,
                'message' => __('Sincronización de productos cancelada por el usuario.', 'mi-integracion-api'),
                'data' => [
                    'processed' => 0,
                    'errors' => 0,
                    'offset' => $offset,
                    'batch_size' => $batch_size,
                    'complete' => true,
                    'cancelled' => true
                ]
            ];
        }
        
        // Verificar que WooCommerce esté disponible, pero de forma más simple
        if (!class_exists('WooCommerce') || !class_exists('WC_Product')) {
            $this->logger->log("WooCommerce no está disponible para la sincronización de productos", 'error');
            return [
                'success' => false,
                'message' => __('WooCommerce no está disponible. Verifique que el plugin esté activo.', 'mi-integracion-api'),
                'data' => [
                    'processed' => 0,
                    'errors' => 1,
                    'offset' => $offset,
                    'batch_size' => $batch_size,
                    'complete' => true
                ]
            ];
        }
        
        // Verificar que el conector API sea válido
        $api_status = $this->is_api_connector_valid();
        if (!$api_status['is_valid']) {
            $this->logger->log("API Connector no válido: " . $api_status['error'], 'error');
            return [
                'success' => false,
                'message' => __('Conector API no válido: ', 'mi-integracion-api') . $api_status['error'],
                'data' => [
                    'processed' => 0,
                    'errors' => 1,
                    'offset' => $offset,
                    'batch_size' => $batch_size,
                    'complete' => true
                ]
            ];
        }
        
        // Verificación adicional de propiedades y métodos del API Connector
        if (!isset($this->api_connector) || !method_exists($this->api_connector, 'get_articulos')) {
            $this->logger->log("El conector API no tiene el método get_articulos()", 'error');
            
            // Intentar reinicializar el conector API
            try {
                if (class_exists('MiIntegracionApi\\Core\\ApiConnector')) {
                    $this->logger->log("Intentando reinicializar el conector API", 'info');
                    $this->api_connector = new \MiIntegracionApi\Core\ApiConnector($this->logger);
                    
                    if (!method_exists($this->api_connector, 'get_articulos')) {
                        throw new \Exception("Método get_articulos() no disponible después de la reinicialización");
                    }
                } else {
                    throw new \Exception("La clase ApiConnector no está disponible");
                }
            } catch (\Exception $e) {
                $this->logger->log("Error al reinicializar el conector API: " . $e->getMessage(), 'error');
                return [
                    'success' => false,
                    'message' => __('Error al inicializar el conector API: ', 'mi-integracion-api') . $e->getMessage(),
                    'data' => [
                        'processed' => 0,
                        'errors' => 1,
                        'offset' => $offset,
                        'batch_size' => $batch_size,
                        'complete' => true
                    ]
                ];
            }
        }
        
        // Comprobar si hay una sincronización en progreso
        $sync_in_progress = get_transient('mi_integracion_api_sync_products_in_progress');
        if (!$sync_in_progress) {
            // Iniciar una nueva sincronización
            set_transient('mi_integracion_api_sync_products_in_progress', true, 3600); // 1 hora de timeout
            set_transient('mi_integracion_api_sync_products_offset', $offset, 3600);
            set_transient('mi_integracion_api_sync_products_batch_count', 0, 3600);
            $this->logger->log("Nueva sincronización de productos iniciada", 'info');
        } else {
            // Continuar sincronización existente
            $offset = get_transient('mi_integracion_api_sync_products_offset');
            if ($offset === false) {
                $offset = 0;
                set_transient('mi_integracion_api_sync_products_offset', $offset, 3600);
            }
            $this->logger->log("Continuando sincronización de productos existente (offset: $offset)", 'info');
        }
        
        try {
            // Establecer un tiempo de ejecución más largo para evitar timeouts
            $max_execution_time = ini_get('max_execution_time');
            if ($max_execution_time < 300 && $max_execution_time !== '0') {
                @set_time_limit(300); // 5 minutos
                $this->logger->log("Tiempo de ejecución extendido a 300 segundos", 'info');
            }
            
            // Aumentar límite de memoria si es necesario
            $memory_limit = ini_get('memory_limit');
            $memory_limit_bytes = $this->return_bytes($memory_limit);
            if ($memory_limit_bytes < 256 * 1024 * 1024) { // 256 MB
                @ini_set('memory_limit', '256M');
                $this->logger->log("Límite de memoria aumentado a 256M", 'info');
            }
            
            // Verificar existencia de WC_Data_Store
            if (!class_exists('WC_Data_Store')) {
                $this->logger->log("La clase WC_Data_Store no está disponible, intentando cargar core WooCommerce", 'warning');
                
                // Intentar cargar la clase manualmente si es posible
                $data_store_path = WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-data-store.php';
                if (file_exists($data_store_path)) {
                    require_once $data_store_path;
                    $this->logger->log("Cargado class-wc-data-store.php manualmente", 'info');
                }
            }
            
            // Obtener productos de Verial
            $this->logger->log("Intentando obtener productos de Verial con parámetros: inicio={$offset}, fin={$batch_size}", 'debug');
            
            try {
                $productos = $this->api_connector->get_articulos([
                    'inicio' => $offset,
                    'fin' => $batch_size
                ]);
                
                // Registrar la respuesta para depuración
                $this->logger->log("Respuesta de get_articulos recibida con " . (is_array($productos) ? count($productos) : "0") . " productos", 'debug');
                
                if (is_wp_error($productos)) {
                    $error_message = $productos->get_error_message();
                    $this->logger->log("Error en respuesta de API: " . $error_message, 'error');
                    throw new \Exception("Error en la API de Verial: " . $error_message);
                }
                
                // Verificar la estructura de la respuesta
                if (is_array($productos) && isset($productos['Articulos']) && is_array($productos['Articulos'])) {
                    $this->logger->log("Respuesta de API tiene estructura anidada, extrayendo 'Articulos'", 'info');
                    $productos = $productos['Articulos'];
                    $this->logger->log("Articulos extraídos: " . count($productos), 'info');
                }
                
                // Verificar si hay error en la respuesta
                if (is_array($productos) && isset($productos['InfoError']) && isset($productos['InfoError']['Codigo']) && $productos['InfoError']['Codigo'] != 0) {
                    $error_desc = isset($productos['InfoError']['Descripcion']) ? $productos['InfoError']['Descripcion'] : 'Error desconocido';
                    $this->logger->log("Error reportado por API: " . $error_desc, 'error');
                    throw new \Exception("Error en la API de Verial: " . $error_desc);
                }
            } catch (\Exception $api_ex) {
                $this->logger->log("Excepción al llamar a get_articulos: " . $api_ex->getMessage(), 'error');
                throw $api_ex; // Re-lanzar para ser capturada en el bloque try/catch principal
            }
            
            if (empty($productos) || !is_array($productos)) {
                // No hay más productos o la respuesta es inválida
                $this->logger->log("No se obtuvieron productos de Verial o fin de sincronización. Tipo de respuesta: " . gettype($productos), 'info');
                
                // Limpiar transients de sincronización
                delete_transient('mi_integracion_api_sync_products_in_progress');
                delete_transient('mi_integracion_api_sync_products_offset');
                delete_transient('mi_integracion_api_sync_products_batch_count');
                
                return [
                    'success' => true,
                    'message' => __('Sincronización de productos completada. No hay más productos para procesar.', 'mi-integracion-api'),
                    'data' => [
                        'processed' => 0,
                        'errors' => 0,
                        'offset' => $offset,
                        'batch_size' => $batch_size,
                        'complete' => true
                    ]
                ];
            }
            
            // Verificar disponibilidad de clases y funciones críticas de WooCommerce
            if (!class_exists('WC_Product')) {
                throw new \Exception("La clase WC_Product no está disponible - WooCommerce puede no estar completamente cargado");
            }
            
            // Verificar nuevamente si hay una solicitud de cancelación antes de procesar los productos
            if (get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada')) {
                $this->logger->log("Sincronización cancelada por el usuario antes de procesar productos", 'info');
                
                // Limpiar transients de sincronización
                delete_transient('mi_integracion_api_sync_products_in_progress');
                delete_transient('mi_integracion_api_sync_products_offset');
                delete_transient('mi_integracion_api_sync_products_batch_count');
                
                // Limpiar flag de cancelación
                delete_option('mia_sync_cancelada');
                delete_transient('mia_sync_cancelada');
                
                return [
                    'success' => true,
                    'message' => __('Sincronización de productos cancelada por el usuario.', 'mi-integracion-api'),
                    'data' => [
                        'processed' => 0,
                        'errors' => 0,
                        'offset' => $offset,
                        'batch_size' => $batch_size,
                        'complete' => true,
                        'cancelled' => true
                    ]
                ];
            }
            
            // Inicializar contadores
            $processed = 0;
            $errors = 0;
            $batch_count = get_transient('mi_integracion_api_sync_products_batch_count') ?: 0;
            $check_cancel = 0; // Contador para verificar cancelación periódicamente
            
            // Procesar cada producto
            foreach ($productos as $producto) {
                try {
                    // Verificar cancelación cada 5 productos procesados
                    if (($check_cancel % 5) === 0) {
                        if (get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada')) {
                            $this->logger->log("Sincronización cancelada por el usuario durante procesamiento", 'info');
                            
                            // Limpiar transients de sincronización
                            delete_transient('mi_integracion_api_sync_products_in_progress');
                            delete_transient('mi_integracion_api_sync_products_offset');
                            delete_transient('mi_integracion_api_sync_products_batch_count');
                            
                            // Limpiar flag de cancelación
                            delete_option('mia_sync_cancelada');
                            delete_transient('mia_sync_cancelada');
                            
                            return [
                                'success' => true,
                                'message' => __('Sincronización de productos cancelada por el usuario.', 'mi-integracion-api'),
                                'data' => [
                                    'processed' => $processed,
                                    'errors' => $errors,
                                    'offset' => $offset,
                                    'batch_size' => $batch_size,
                                    'complete' => true,
                                    'cancelled' => true
                                ]
                            ];
                        }
                    }
                    $check_cancel++;
                    
                    // Verificar estructura de datos básica
                    if (!is_array($producto)) {
                        $this->logger->log("Elemento no válido en respuesta de API, no es un array", 'error');
                        $errors++;
                        continue;
                    }
                    
                    // Registrar datos mínimos de identificación del producto (sin volcar todo el objeto)
                    $this->logger->log("Procesando producto SKU: " . (isset($producto['SKU']) ? $producto['SKU'] : 'sin SKU') . 
                        " ID: " . (isset($producto['Id']) ? $producto['Id'] : (isset($producto['ID_Articulo']) ? $producto['ID_Articulo'] : 'sin ID')), 'debug');
                    
                    // Verificar que tenga ID de Verial (campo obligatorio)
                    // Dependiendo de la API, el ID puede estar en el campo 'Id' o 'ID_Articulo'
                    $verial_id = null;
                    if (!empty($producto['Id'])) {
                        $verial_id = $producto['Id'];
                    } elseif (!empty($producto['ID_Articulo'])) {
                        $verial_id = $producto['ID_Articulo'];
                    }
                    
                    if (empty($verial_id)) {
                        $this->logger->log("Producto sin ID identificable, omitido: SKU=" . (isset($producto['SKU']) ? $producto['SKU'] : 'sin SKU'), 'warning');
                        $errors++;
                        continue;
                    }
                    
                    // Si no tiene SKU, usamos nuestra función de generación de SKU
                    if (empty($producto['SKU'])) {
                        $temp_sku = '';
                        
                        try {
                            $temp_sku = $this->generate_unique_sku($producto);
                            $this->logger->log("SKU generado para producto #{$verial_id}: $temp_sku", 'info');
                        } catch (\Exception $e) {
                            // Fallback si nuestro método falla
                            if (!empty($producto['Nombre'])) {
                                $temp_sku = 'TEMP-' . substr(sanitize_title($producto['Nombre']), 0, 10) . '-' . $verial_id;
                            } else {
                                $temp_sku = 'VERIAL-' . $verial_id;
                            }
                            $this->logger->log("Error al generar SKU, usando fallback: $temp_sku", 'warning');
                        }
                        
                        $this->logger->log("Producto sin SKU, generando SKU temporal: " . $temp_sku, 'warning');
                        $producto['SKU'] = $temp_sku;
                    }
                    
                    // Verificar si el producto ya existe
                    $wc_product_id = $this->get_wc_product_by_verial_id($verial_id);
                    
                    if ($wc_product_id) {
                        // Actualizar producto existente
                        $this->logger->log("Actualizando producto: " . $producto['SKU'], 'debug');
                        
                        // Cargar el producto directamente usando WC_Product en lugar de wc_get_product
                        try {
                            $wc_product = new \WC_Product($wc_product_id);
                            
                            // Actualizar datos básicos
                            $wc_product->set_name($producto['Nombre'] ?? '');
                            $wc_product->set_sku($producto['SKU']);
                            
                            // Obtener el precio desde la API de Verial (GetCondicionesTarifaWS)
                            try {
                                $precio = $this->get_product_price($verial_id);
                                if ($precio > 0) {
                                    $wc_product->set_regular_price(number_format($precio, 2, '.', ''));
                                    $this->logger->log("Precio actualizado para producto #{$verial_id}: " . number_format($precio, 2, '.', ''), 'info');
                                } else if (isset($producto['PVP']) && is_numeric($producto['PVP'])) {
                                    // Fallback: usar PVP si está disponible en la respuesta actual
                                    $wc_product->set_regular_price($producto['PVP']);
                                    $this->logger->log("Usando PVP de la respuesta para producto #{$verial_id}: " . $producto['PVP'], 'info');
                                }
                            } catch (\Exception $price_ex) {
                                $this->logger->log("Error al obtener precio para producto #{$verial_id}: " . $price_ex->getMessage(), 'warning');
                                // Intentar usar PVP si está disponible en la respuesta actual
                                if (isset($producto['PVP']) && is_numeric($producto['PVP'])) {
                                    $wc_product->set_regular_price($producto['PVP']);
                                }
                            }
                            
                            if (isset($producto['Descripcion'])) {
                                $wc_product->set_description($producto['Descripcion']);
                            }
                            // Guarda el ID de Verial como meta
                            $wc_product->update_meta_data('_verial_product_id', $verial_id);
                            
                            // Guardar el producto
                            $wc_product->save();
                            
                            $this->logger->log("Producto actualizado: " . $producto['SKU'], 'info');
                        } catch (\Exception $ex) {
                            throw new \Exception("Error al actualizar producto WooCommerce: " . $ex->getMessage());
                        }
                    } else {
                        // Crear nuevo producto
                        $this->logger->log("Creando nuevo producto: " . $producto['SKU'], 'debug');
                        
                        try {
                            // Crear producto usando WC_Product directamente
                            $wc_product = new \WC_Product();
                            
                            // Configurar datos básicos
                            $wc_product->set_name($producto['Nombre'] ?? '');
                            $wc_product->set_sku($producto['SKU']);
                            
                            // Obtener el precio desde la API de Verial (GetCondicionesTarifaWS)
                            try {
                                $precio = $this->get_product_price($verial_id);
                                if ($precio > 0) {
                                    $wc_product->set_regular_price(number_format($precio, 2, '.', ''));
                                    $this->logger->log("Precio establecido para nuevo producto #{$verial_id}: " . number_format($precio, 2, '.', ''), 'info');
                                } else if (isset($producto['PVP']) && is_numeric($producto['PVP'])) {
                                    // Fallback: usar PVP si está disponible en la respuesta actual
                                    $wc_product->set_regular_price($producto['PVP']);
                                    $this->logger->log("Usando PVP de la respuesta para nuevo producto #{$verial_id}: " . $producto['PVP'], 'info');
                                }
                            } catch (\Exception $price_ex) {
                                $this->logger->log("Error al obtener precio para nuevo producto #{$verial_id}: " . $price_ex->getMessage(), 'warning');
                                // Intentar usar PVP si está disponible en la respuesta actual
                                if (isset($producto['PVP']) && is_numeric($producto['PVP'])) {
                                    $wc_product->set_regular_price($producto['PVP']);
                                }
                            }
                            
                            if (isset($producto['Descripcion'])) {
                                $wc_product->set_description($producto['Descripcion']);
                            }
                            // Guardar el ID de Verial como meta
                            $wc_product->update_meta_data('_verial_product_id', $verial_id);
                            
                            // Guardar el producto
                            $product_id = $wc_product->save();
                            
                            if (!$product_id) {
                                throw new \Exception("No se pudo guardar el producto en la base de datos");
                            }
                            
                            $this->logger->log("Producto creado exitosamente con ID: $product_id");
                            
                            // Guardar el ID de Verial como metadato y en la tabla de mapeo
                            update_post_meta($product_id, '_verial_product_id', $verial_id);
                            $this->update_product_mapping($product_id, $verial_id, $sku);
                            
                            $this->logger->log("Producto #$verial_id creado en WooCommerce ($product_id)");
                            
                            return [
                                'status' => 'created',
                                'wc_id' => $product_id,
                                'verial_id' => $verial_id
                            ];
                        } catch (\Exception $e) {
                            $this->logger->log("Error en wc_create_product: " . $e->getMessage(), 'error');
                            throw $e;
                        }
                    }
                    
                    $processed++;
                } catch (\Exception $e) {
                    $this->logger->log("Error al procesar producto " . ($producto['SKU'] ?? 'desconocido') . ": " . $e->getMessage(), 'error');
                    $errors++;
                }
            }
            
            // Actualizar offset y contador de lotes para la próxima ejecución
            $new_offset = $offset + $batch_size;
            set_transient('mi_integracion_api_sync_products_offset', $new_offset, 3600);
            set_transient('mi_integracion_api_sync_products_batch_count', $batch_count + 1, 3600);
            
            // Verificar una última vez si hay solicitud de cancelación
            if (get_option('mia_sync_cancelada', false) || get_transient('mia_sync_cancelada')) {
                $this->logger->log("Sincronización cancelada por el usuario después de procesar el lote", 'info');
                
                // Limpiar transients de sincronización
                delete_transient('mi_integracion_api_sync_products_in_progress');
                delete_transient('mi_integracion_api_sync_products_offset');
                delete_transient('mi_integracion_api_sync_products_batch_count');
                
                // Limpiar flag de cancelación
                delete_option('mia_sync_cancelada');
                delete_transient('mia_sync_cancelada');
                
                return [
                    'success' => true,
                    'message' => __('Sincronización de productos cancelada por el usuario después de procesar lote.', 'mi-integracion-api'),
                    'data' => [
                        'processed' => $processed,
                        'errors' => $errors,
                        'offset' => $offset,
                        'batch_size' => $batch_size,
                        'complete' => true,
                        'cancelled' => true
                    ]
                ];
            }
            
            $this->logger->log("Lote de productos procesado. Procesados: $processed, Errores: $errors, Nuevo offset: $new_offset", 'info');
            
            // Devolver resultado del lote procesado
            return [
                'success' => true,
                'message' => sprintf(__('Lote de productos procesado. Procesados: %d, Errores: %d', 'mi-integracion-api'), $processed, $errors),
                'data' => [
                    'processed' => $processed,
                    'errors' => $errors,
                    'offset' => $new_offset,
                    'batch_size' => $batch_size,
                    'complete' => false,
                    'batch_count' => $batch_count + 1
                ]
            ];
            
        } catch (\Throwable $e) {
            $this->logger->log("Excepción en sync_products_batch: " . $e->getMessage(), 'error');
            $trace = $e->getTraceAsString();
            $file = $e->getFile();
            $line = $e->getLine();
            
            // Registrar todos los detalles del error para una depuración exhaustiva
            $this->logger->log("Detalle de error: Archivo: {$file}, Línea: {$line}", 'error');
            $this->logger->log("Traza de la excepción: {$trace}", 'debug');
            
            // Si el error está relacionado con API, registrar más información de diagnóstico
            if (strpos($e->getMessage(), 'API') !== false || 
                strpos($e->getMessage(), 'Verial') !== false ||
                strpos($file, 'ApiConnector.php') !== false) {
                
                $api_info = "No hay información disponible";
                if (isset($this->api_connector)) {
                    if (method_exists($this->api_connector, 'get_last_request_info')) {
                        $api_info = $this->api_connector->get_last_request_info();
                    } else if (isset($this->api_connector->api_url)) {
                        $api_info = "API URL: " . $this->api_connector->api_url;
                    }
                }
                $this->logger->log("Información API: " . print_r($api_info, true), 'debug');
            }
            
            // Registrar diagnóstico de WooCommerce para ayudar en la solución del problema
            $wc_diagnostics = [
                'wc_class_exists' => class_exists('WooCommerce'),
                'wc_product_class_exists' => class_exists('WC_Product'),
                'wc_data_store_class_exists' => class_exists('WC_Data_Store'),
                'wc_abspath_defined' => defined('WC_ABSPATH'),
                'wp_plugin_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : 'no definido',
                'wc_file_exists' => defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php'),
                'plugin_active' => function_exists('is_plugin_active') ? is_plugin_active('woocommerce/woocommerce.php') : 'función no disponible',
                'api_connector_class' => isset($this->api_connector) ? get_class($this->api_connector) : 'no disponible',
                'error_file' => $file,
                'error_line' => $line,
                'php_version' => PHP_VERSION,
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
            ];
            $this->logger->log("Diagnóstico de sincronización: " . json_encode($wc_diagnostics), 'error');
            
            // Intentar limpiar el estado de sincronización para evitar bloqueos
            $retry_allowed = true;
            if (strpos($e->getMessage(), "WC_Data_Store") !== false || 
                strpos($e->getMessage(), "WC_Product") !== false) {
                // Si el problema es con las clases de WooCommerce, limpiar transients
                delete_transient('mi_integracion_api_sync_products_in_progress');
                $retry_allowed = false; // No permitas reintentos si es un problema fundamental
            }
            
            // Mantener el estado de sincronización para posible recuperación
            return [
                'success' => false,
                'message' => __('Error durante la sincronización de productos: ', 'mi-integracion-api') . $e->getMessage(),
                'data' => [
                    'processed' => 0,
                    'errors' => 1,
                    'offset' => $offset,
                    'batch_size' => $batch_size,
                    'complete' => !$retry_allowed, // Marcar como completo si no se permiten reintentos
                    'exception' => $e->getMessage(),
                    'diagnostic' => $wc_diagnostics
                ]
            ];
        }
    }
    
    /**
     * Verifica y establece el estado de la sincronización
     * 
     * @param string $status Estado actual (running, completed, error)
     * @param string $message Mensaje de estado
     * @param array $data Datos adicionales
     * @return bool true si fue exitoso, false si falló
     */
    public function set_sync_status($status, $message = '', $data = []) {
        try {
            $current_time = current_time('mysql');
            
            $sync_status = [
                'status' => $status,
                'message' => $message,
                'type' => $this->current_sync_type,
                'timestamp' => $current_time,
                'data' => $data
            ];
            
            update_option('mi_integracion_api_last_sync_status', $sync_status);
            
            if ($status === 'completed' || $status === 'error') {
                // Registrar el historial de sincronización
                $sync_history = get_option('mi_integracion_api_sync_history', []);
                array_unshift($sync_history, $sync_status);
                
                // Limitar a 10 entradas
                if (count($sync_history) > 10) {
                    array_pop($sync_history);
                }
                
                update_option('mi_integracion_api_sync_history', $sync_history);
            }
            
            return true;
        } catch (\Exception $e) {

            $this->logger->log("Error al establecer estado de sincronización: " . $e->getMessage(), 'error');
            return false;
        }
    }
    
    /**
     * Obtiene el último estado de sincronización
     * 
     * @return array Estado actual de sincronización
     */
    public function get_sync_status() {
        $sync_status = get_option('mi_integracion_api_last_sync_status', [
            'status' => 'none',
            'message' => 'No se ha realizado ninguna sincronización',
            'type' => '',
            'timestamp' => null,
            'data' => []
        ]);
        
        return $sync_status;
    }
    
    /**
     * Obtiene el historial de sincronizaciones
     * 
     * @param int $limit Límite de entradas a devolver
     * @return array Historial de sincronizaciones
     */
    public function get_sync_history($limit = 10) {
        $sync_history = get_option('mi_integracion_api_sync_history', []);
        
        if ($limit && count($sync_history) > $limit) {
            $sync_history = array_slice($sync_history, 0, $limit);
        }
        
        return $sync_history;
    }
    
    /**
     * Verifica si un producto pasa los filtros aplicados
     * 
     * @param array $producto Datos del producto de Verial
     * @param array $filters Filtros a aplicar
     * @return bool True si el producto pasa los filtros
     */
    private function product_passes_filters($producto, $filters) {
        // Si no hay filtros, el producto pasa automáticamente
        if (empty($filters)) {
            return true;
        }
        
        // Filtro por categoría
        if (isset($filters['categoria']) && !empty($filters['categoria'])) {
            $categoria_id = intval($filters['categoria']);
            
            // Buscar en todos los campos de categoría posibles
            $categoria_fields = ['ID_Categoria', 'ID_CategoriaWeb1', 'ID_CategoriaWeb2', 'ID_CategoriaWeb3', 'ID_CategoriaWeb4'];
            $match = false;
            
            foreach ($categoria_fields as $field) {
                if (isset($producto[$field]) && intval($producto[$field]) === $categoria_id) {
                    $match = true;
                    break;
                }
            }
            
            if (!$match) {
                return false;
            }
        }
        
        // Filtro por fabricante
        if (isset($filters['fabricante']) && !empty($filters['fabricante'])) {
            $fabricante_id = intval($filters['fabricante']);
            
            if (!isset($producto['ID_Fabricante']) || intval($producto['ID_Fabricante']) !== $fabricante_id) {
                return false;
            }
        }
        
        // Filtro por precio mínimo
        if (isset($filters['precio_min']) && is_numeric($filters['precio_min'])) {
            $precio_min = floatval($filters['precio_min']);
            
            if (!isset($producto['PVP']) || floatval($producto['PVP']) < $precio_min) {
                return false;
            }
        }
        
        // Filtro por precio máximo
        if (isset($filters['precio_max']) && is_numeric($filters['precio_max'])) {
            $precio_max = floatval($filters['precio_max']);
            
            if (!isset($producto['PVP']) || floatval($producto['PVP']) > $precio_max) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Verifica si hay alguna sincronización pendiente de reanudar
     * Devuelve siempre una estructura estándar con 'success', 'entity', 'progress', 'message'.
     *
     * @return array Información sobre la sincronización pendiente o estructura estándar si no hay
     */
    public function check_pending_sync() {
        // Verificar si hay algún punto de recuperación para productos
        $recovery_products = SyncRecovery::get_recovery_state('productos');
        if ($recovery_products) {
            return [
                'success' => true,
                'entity' => 'productos',
                'progress' => SyncRecovery::get_recovery_progress('productos'),
                'message' => SyncRecovery::get_recovery_message('productos'),
            ];
        }

        // Verificar si hay algún punto de recuperación para clientes

        $recovery_customers = SyncRecovery::get_recovery_state('clientes');
        if ($recovery_customers) {
            return [
                'success' => true,
                'entity' => 'clientes',
                'progress' => SyncRecovery::get_recovery_progress('clientes'),
                'message' => SyncRecovery::get_recovery_message('clientes'),
            ];
        }

        // Verificar si hay algún punto de recuperación para pedidos
        $recovery_orders = SyncRecovery::get_recovery_state('pedidos');
        if ($recovery_orders) {
            return [
                'success' => true,
                'entity' => 'pedidos',
                'progress' => SyncRecovery::get_recovery_progress('pedidos'),
                'message' => SyncRecovery::get_recovery_message('pedidos'),
            ];
        }

        // Si no hay recuperación pendiente, devolver estructura estándar
        return [
            'success' => false,
            'entity' => null,
            'progress' => null,
            'message' => __('No hay sincronización pendiente de recuperación', 'mi-integracion-api'),
        ];
    }
    
    /**
     * Reanuda una sincronización previamente interrumpida
     * 
     * @param string $entity Nombre de la entidad a reanudar (productos, clientes, pedidos)
     * @param bool $force_restart Si es verdadero, fuerza un reinicio en lugar de reanudar
     * @return array Resultado de la operación
     */
    public function resume_sync($entity, $force_restart = false) {
        // Verificar que el ApiConnector esté correctamente configurado
        $api_status = $this->is_api_connector_valid();
        if (!$api_status['is_valid']) {
            $this->logger->log('[ERROR] Resume sync cancelado: ' . $api_status['error'], 'error');
            return [
                'success' => false,
                'message' => __('No se puede reanudar sincronización: ', 'mi-integracion-api') . $api_status['error'],
                'data' => ['entity' => $entity, 'api_error' => $api_status['error']]
            ];
        }
        
        if (!in_array($entity, ['productos', 'clientes', 'pedidos'])) {
            $this->logger->log("Intento de reanudar sincronización con entidad no válida: $entity", 'error');
            return [
                'success' => false,
                'message' => __('Entidad no válida para sincronización', 'mi-integracion-api'),
                'data' => ['entity' => $entity]
            ];
        }
        // Verificar si hay un punto de recuperación disponible
        if (!$force_restart && !SyncRecovery::has_recovery_state($entity)) {
            $this->logger->log("No hay punto de recuperación disponible para $entity", 'warning');
            return [
                'success' => false,
                'message' => sprintf(__('No hay punto de recuperación disponible para %s', 'mi-integracion-api'), $entity),
                'data' => ['entity' => $entity]
            ];
        }
        // Si estamos forzando un reinicio, limpiar el estado actual
        if ($force_restart) {
            $this->logger->log("Limpiando estado de recuperación para $entity por reinicio forzado", 'info');
            SyncRecovery::clear_recovery_state($entity);
        }
        // Según la entidad, llamar al método de sincronización correspondiente
        switch ($entity) {
            case 'productos':
                if (!class_exists('MiIntegracionApi\\Sync\\SyncProductos')) {
                    $this->logger->log('Clase SyncProductos no encontrada', 'error');
                    return [
                        'success' => false,
                        'message' => __('No se encontró la clase SyncProductos', 'mi-integracion-api'),
                        'data' => []
                    ];
                }
                $batch_size = get_option('mi_integracion_api_batch_size_productos', 100);
                $this->logger->log("Reanudando sincronización de productos (batch_size: $batch_size, force_restart: $force_restart)", 'info');
                return SyncProductos::sync($this->api_connector, null, $batch_size, ['force_restart' => $force_restart]);
            case 'clientes':
                if (!class_exists('MiIntegracionApi\\Sync\\SyncClientes')) {
                    $this->logger->log('Clase SyncClientes no encontrada', 'error');
                    return [
                        'success' => false,
                        'message' => __('No se encontró la clase SyncClientes', 'mi-integracion-api'),
                        'data' => []
                    ];
                }
                $batch_size = get_option('mi_integracion_api_batch_size_clientes', 50);
                $this->logger->log("Reanudando sincronización de clientes (batch_size: $batch_size, force_restart: $force_restart)", 'info');
                // Unificamos la firma para clientes también
                return SyncClientes::sync($this->api_connector, $batch_size, 0, ['force_restart' => $force_restart]);
            case 'pedidos':
                if (!class_exists('MiIntegracionApi\\Sync\\SyncPedidos')) {
                    $this->logger->log('Clase SyncPedidos no encontrada', 'error');
                    return [
                        'success' => false,
                        'message' => __('No se encontró la clase SyncPedidos', 'mi-integracion-api'),
                        'data' => []
                    ];
                }
                $batch_size = get_option('mi_integracion_api_batch_size_pedidos', 25);
                $this->logger->log("Reanudando sincronización de pedidos (batch_size: $batch_size, force_restart: $force_restart)", 'info');
                return SyncPedidos::sync($this->api_connector, null, $batch_size, [
                    'use_batch_processor' => true,
                    'force_restart' => $force_restart
                ]);
        }
        // Fallback por si el switch no retorna
        $this->logger->log("Entidad no reconocida en resume_sync: $entity", 'error');
        return [
            'success' => false,
            'message' => __('Entidad no reconocida para sincronización', 'mi-integracion-api'),
            'data' => ['entity' => $entity]
        ];
    }
    
    /**
     * Cancela la sincronización en progreso
     * 
     * @return array Detalles del resultado de la cancelación
     */
    public function cancel_sync() {
        // Obtener el estado actual
        $sync_status = $this->get_sync_status();
        
        // Verificar si hay una sincronización en curso (adaptado al formato de este SyncManager)
        if ($sync_status['status'] !== 'running') {
            return [
                'status' => 'no_sync',
                'message' => 'No hay una sincronización en progreso.',
            ];
        }
        
        // Crear un registro para el historial
        $history_entry = [
            'type' => $sync_status['type'],
            'timestamp' => current_time('mysql'),
            'status' => 'cancelled',
            'message' => 'Sincronización cancelada manualmente',
            'data' => $sync_status['data'] ?? []
        ];
        
        // Actualizar el estado para indicar que fue cancelado
        $this->set_sync_status('cancelled', 'Sincronización cancelada por el usuario', $sync_status['data'] ?? []);
        
        // Limpiamos cualquier posible transient que se esté usando para la sincronización
        delete_transient('mi_integracion_api_sync_products_in_progress');
        delete_transient('mi_integracion_api_sync_products_offset');
        delete_transient('mi_integracion_api_sync_products_batch_count');
        
        $this->logger->log("Sincronización cancelada por el usuario", 'info');
        
        return [
            'status' => 'cancelled',
            'message' => 'Sincronización cancelada exitosamente.',
            'summary' => $history_entry
        ];
    }
    
    /**
     * Diagnostica problemas comunes de sincronización
     * Útil para depurar errores de WooCommerce
     * 
     * @return array Información diagnóstica
     */
    public function diagnose_sync_issues() {
        $diagnosis = array(
            'woocommerce_active' => false,
            'wc_class_exists' => false,
            'product_functions_available' => false,
            'wc_abspath_defined' => false,
            'wc_function_exists' => false,
            'product_files_exist' => false,
            'suggestions' => array()
        );
        
        // Verificar si WooCommerce está activo
        $diagnosis['woocommerce_active'] = class_exists('WooCommerce');
        $diagnosis['wc_class_exists'] = class_exists('WC');
        
        if (!$diagnosis['woocommerce_active'] && !$diagnosis['wc_class_exists']) {
            $diagnosis['suggestions'][] = "WooCommerce no está activo. Activa el plugin WooCommerce.";
        }
        
        // Verificar funciones de productos
        $diagnosis['product_functions_available'] = 
            function_exists('wc_create_product') && 
            function_exists('wc_update_product');
            
        if (!$diagnosis['product_functions_available']) {
            $diagnosis['suggestions'][] = "Las funciones de producto de WooCommerce no están disponibles.";
        }
        
        // Verificar constantes y rutas
        $diagnosis['wc_abspath_defined'] = defined('WC_ABSPATH');
        $diagnosis['wc_function_exists'] = function_exists('WC');
        
        // Verificar archivos
        if ($diagnosis['wc_abspath_defined']) {
            $diagnosis['product_files_exist'] = 
                file_exists(WC_ABSPATH . 'includes/wc-product-functions.php') && 
                file_exists(WC_ABSPATH . 'includes/wc-core-functions.php');
                
            if (!$diagnosis['product_files_exist']) {
                $diagnosis['suggestions'][] = "Los archivos de funciones de producto de WooCommerce no existen en la ruta esperada.";
            }
        } elseif ($diagnosis['wc_function_exists'] && WC() !== null) {
            $diagnosis['product_files_exist'] = 
                file_exists(WC()->plugin_path() . '/includes/wc-product-functions.php') && 
                file_exists(WC()->plugin_path() . '/includes/wc-core-functions.php');
                
            if (!$diagnosis['product_files_exist']) {
                $diagnosis['suggestions'][] = "Los archivos de funciones de producto de WooCommerce no existen en la ruta de WC().";
            }
        }
        
        // Intentar cargar las funciones si no están disponibles
        if (!$diagnosis['product_functions_available']) {
            $loaded = $this->load_woocommerce_functions();
            $diagnosis['functions_loaded_by_method'] = $loaded;
            
            // Verificar nuevamente después de intentar cargar
            $diagnosis['product_functions_available_after_load'] = 
                function_exists('wc_create_product') && 
                function_exists('wc_update_product');
                
            if ($diagnosis['product_functions_available_after_load']) {
                $diagnosis['suggestions'][] = "Las funciones se pudieron cargar manualmente. Hay un problema con la carga automática de WooCommerce.";
            } else {
                $diagnosis['suggestions'][] = "No se pudieron cargar las funciones ni siquiera manualmente. Verifica la instalación de WooCommerce.";
            }
        }
        
        return $diagnosis;
    }

    /**
     * Verifica si las funciones de WooCommerce necesarias para trabajar con productos están disponibles
     *
     * @return bool True si las funciones de WooCommerce están disponibles, false en caso contrario
     */
    private function is_woocommerce_available() {
        // Verificar si WooCommerce está activo comprobando la existencia de la clase WC
        if (!class_exists('WooCommerce') && !class_exists('WC')) {
            $this->logger->log("WooCommerce no está activado", 'error');
            return false;
        }
        
        // Comprobar si el plugin está activo usando las funciones de WordPress si están disponibles
        if (!function_exists('is_plugin_active')) {
            if (defined('ABSPATH') && file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
        }
        
        if (function_exists('is_plugin_active') && !is_plugin_active('woocommerce/woocommerce.php')) {
            $this->logger->log("El plugin de WooCommerce no está activo según WordPress", 'error');
            return false;
        }
        
        // Verificar si las funciones básicas de WooCommerce están disponibles
        $wc_basic_functions = function_exists('wc_get_product') && 
 
                              function_exists('wc_get_products') && 
                              function_exists('wc_get_page_id');
        
        // Verificar si las funciones específicas de productos están disponibles
        $wc_product_functions = function_exists('wc_create_product') && function_exists('wc_update_product');
        
        if (!$wc_product_functions) {
            $this->logger->log("Intentando cargar funciones de WooCommerce...", 'info');
            
            // Primer intento: cargar las funciones con nuestro método optimizado
            if (!$this->load_woocommerce_functions()) {
                $this->logger->log("Primer intento fallido: No se pudieron cargar las funciones de WooCommerce", 'warning');
                
                // Segundo intento: Intenta incluir directamente el archivo de bootstrap de WooCommerce
                if (defined('WP_PLUGIN_DIR') && file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                    try {
                        $this->logger->log("Segundo intento: Cargando woocommerce.php directamente", 'info');
                        include_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
                        
                        // Intentar cargar las funciones nuevamente
                        if ($this->load_woocommerce_functions()) {
                            $this->logger->log("Segundo intento exitoso: Funciones de WooCommerce cargadas tras incluir woocommerce.php", 'info');
                        } else {
                            $this->logger->log("Segundo intento fallido: Las funciones no están disponibles después de incluir woocommerce.php", 'error');
                        }
                    } catch (\Throwable $e) {
                        $this->logger->log("Error al cargar woocommerce.php directamente: " . $e->getMessage(), 'error');
                    }
                }
            }
            
            // Verificación final después de todos los intentos
            if (!function_exists('wc_create_product') || !function_exists('wc_update_product')) {
                // Intentemos un enfoque más básico: crear nuestras propias implementaciones temporales
                if (!function_exists('wc_create_product')) {
                    $this->logger->log("La función wc_create_product no está disponible. Intentando usar WC_Product directamente.", 'warning');
                    
                    // Verificamos si al menos la clase WC_Product existe
                    if (!class_exists('WC_Product') && defined('WP_PLUGIN_DIR')) {
                        // Intentar cargar la clase WC_Product directamente
                        $abstract_product_path = WP_PLUGIN_DIR . '/woocommerce/includes/abstracts/abstract-wc-product.php';
                        if (file_exists($abstract_product_path)) {
                            require_once $abstract_product_path;
                            $this->logger->log("Cargando abstract-wc-product.php directamente", 'debug');
                        }
                    }
                    
                    if (class_exists('WC_Product')) {
                        // No pudimos hacer esto - requeriría mucho más código para implementar adecuadamente
                        // estas funciones. Lo mejor es reportar que WooCommerce no está disponible.
                        $this->logger->log("WC_Product existe pero no podemos implementar wc_create_product adecuadamente", 'error');
                    } else {
                        $this->logger->log("WC_Product no existe. No se puede proceder con la sincronización de productos", 'error');
                    }
                }
                
                $this->logger->log("Las funciones de producto de WooCommerce no están disponibles después de múltiples intentos", 'error');
                return false;
            } else {
                $this->logger->log("Funciones de producto de WooCommerce cargadas exitosamente", 'info');
            }
        }
        
        return true;
    }
    
    /**
     * Carga explícitamente las funciones de WooCommerce necesarias para trabajar con productos
     * 
     * @return bool True si se pudieron cargar las funciones, false en caso contrario
     */
    private function load_woocommerce_functions() {
        // Paso 1: Verificar si WooCommerce está activo
        if (!class_exists('WooCommerce') && !class_exists('WC')) {
            $this->logger->log("WooCommerce no está activado, no se pueden cargar las funciones", 'error');
            return false;
        }
        
        // Paso 2: Verificar si las funciones ya están disponibles
        if (function_exists('wc_create_product') && function_exists('wc_update_product')) {
            $this->logger->log("Las funciones de WooCommerce ya están disponibles", 'info');
            return true;
        }

        // Paso 3: Intentar cargar las funciones usando WordPress APIs
        $this->logger->log("Intentando cargar las funciones de WooCommerce a través de las APIs de WordPress", 'info');
        
        // Verificar si el plugin de WooCommerce está activo
        if (!function_exists('is_plugin_active')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        if (function_exists('is_plugin_active') && !is_plugin_active('woocommerce/woocommerce.php')) {
            $this->logger->log("El plugin de WooCommerce no está activo según WordPress", 'error');
            return false;
        }
        
        // Obtener la ruta correcta del plugin WooCommerce
        $wc_plugin_path = WP_PLUGIN_DIR . '/woocommerce';
        if (function_exists('WC')) {
            try {
                $wc_instance = WC();
                if (method_exists($wc_instance, 'plugin_path')) {
                    $wc_plugin_path = $wc_instance->plugin_path();
                    $this->logger->log("Usando WC()->plugin_path(): " . $wc_plugin_path, 'debug');
                }
            } catch (\Throwable $e) {
                $this->logger->log("Error al obtener WC()->plugin_path(): " . $e->getMessage(), 'warning');
            }
        } elseif (defined('WC_PLUGIN_FILE')) {
            $wc_plugin_path = plugin_dir_path(WC_PLUGIN_FILE);
            $this->logger->log("Usando plugin_dir_path(WC_PLUGIN_FILE): " . $wc_plugin_path, 'debug');
        } elseif (defined('WC_ABSPATH')) {
            $wc_plugin_path = WC_ABSPATH;
            $this->logger->log("Usando WC_ABSPATH: " . $wc_plugin_path, 'debug');
        }
        
        // Paso 4: Cargar los archivos de funciones principales en orden específico
        $files_to_load = [
            // Archivos de funciones fundamentales primero
            $wc_plugin_path . '/includes/wc-core-functions.php',
            $wc_plugin_path . '/includes/class-wc-product-factory.php',
            $wc_plugin_path . '/includes/abstracts/abstract-wc-product.php',
            $wc_plugin_path . '/includes/class-wc-product-simple.php',
            $wc_plugin_path . '/includes/class-wc-product-variable.php',
            
            // Luego las funciones de productos que necesitamos
            $wc_plugin_path . '/includes/wc-product-functions.php',
            $wc_plugin_path . '/includes/data-stores/class-wc-product-data-store-cpt.php',
            $wc_plugin_path . '/includes/wc-term-functions.php',
            
            // Archivos adicionales que podrían ser necesarios
            $wc_plugin_path . '/includes/class-wc-post-types.php',
            $wc_plugin_path . '/includes/class-wc-install.php'
        ];
        
        $loaded_files = 0;
        foreach ($files_to_load as $file) {
            if (file_exists($file) && is_readable($file)) {
                try {
                    require_once $file;
                    $loaded_files++;
                    $this->logger->log("Cargado archivo: " . basename($file), 'debug');
                } catch (\Throwable $e) {
                    $this->logger->log("Error al cargar " . basename($file) . ": " . $e->getMessage(), 'warning');
                }
            } else {
                $this->logger->log("No se encontró o no es legible: " . basename($file), 'debug');
            }
        }
        
        // Paso 5: Comprobar si ahora las funciones están disponibles
        if (function_exists('wc_create_product') && function_exists('wc_update_product')) {
            $this->logger->log("Funciones de WooCommerce cargadas exitosamente después de cargar {$loaded_files} archivos", 'info');
            return true;
        }
        
        // Paso 6: Método alternativo - intentar usar require_once directamente para las funciones principales
        if (!function_exists('wc_get_product')) {
            $core_functions = $wc_plugin_path . '/includes/wc-core-functions.php';
            if (file_exists($core_functions)) {
                require_once $core_functions;
                $this->logger->log("Intentando cargar wc-core-functions.php directamente", 'debug');
            }
        }
        
        if (!function_exists('wc_create_product')) {
            $product_functions = $wc_plugin_path . '/includes/wc-product-functions.php';
            if (file_exists($product_functions)) {
                require_once $product_functions;
                $this->logger->log("Intentando cargar wc-product-functions.php directamente", 'debug');
            }
        }
        
        // Paso 7: Verificación final
        if (function_exists('wc_create_product') && function_exists('wc_update_product')) {
            $this->logger->log("Funciones de WooCommerce cargadas exitosamente después de intentos adicionales", 'info');
            return true;
        }
        
        // Paso 8: Diagnóstico en caso de fallo
        $this->logger->log("No se pudieron cargar las funciones de WooCommerce después de múltiples intentos", 'error');
        
        // Registrar información de diagnóstico para ayudar a solucionar el problema
        $diagnostic_info = [
            'wc_class_exists' => class_exists('WooCommerce'),
            'wc_singleton_exists' => function_exists('WC'),
            'wc_singleton_valid' => function_exists('WC') && WC() !== null,
            'wc_product_class_exists' => class_exists('WC_Product'),
            'wc_abspath_defined' => defined('WC_ABSPATH'),
            'wp_plugin_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : 'no definido',
            'files_loaded' => $loaded_files,
            'wc_plugin_path' => $wc_plugin_path,
            'core_functions_exists' => file_exists($wc_plugin_path . '/includes/wc-core-functions.php'),
            'product_functions_exists' => file_exists($wc_plugin_path . '/includes/wc-product-functions.php'),
        ];
        
        $this->logger->log("Diagnóstico de WooCommerce: " . json_encode($diagnostic_info), 'error');
        
        return false;
    }
    
    /**
     * Función de diagnóstico específica para WooCommerce
     * Puede llamarse desde el panel de administración para diagnóstico
     * 
     * @return array Información detallada sobre el estado de WooCommerce
     */
    public function diagnose_woocommerce_integration() {
        // Paso 1: Información básica de WordPress
        $diagnosis = [
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'abspath' => defined('ABSPATH') ? ABSPATH : 'no definido',
                'wp_plugin_dir' => defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : 'no definido',
                'is_multisite' => is_multisite(),
            ],
            'woocommerce' => [
                'wc_class_exists' => class_exists('WooCommerce'),
                'wc_version' => class_exists('WooCommerce') && defined('WC_VERSION') ? WC_VERSION : 'no detectado',
                'wc_active' => false,
                'wc_path' => 'no detectado',
                'wc_plugin_file' => defined('WC_PLUGIN_FILE') ? WC_PLUGIN_FILE : 'no definido',
                'wc_abspath' => defined('WC_ABSPATH') ? WC_ABSPATH : 'no definido',
            ],
            'product_functions' => [
                'wc_create_product' => function_exists('wc_create_product'),
                'wc_update_product' => function_exists('wc_update_product'),
                'wc_get_product' => function_exists('wc_get_product'),
                'wc_get_products' => function_exists('wc_get_products'),
            ],
            'classes' => [
                'WC_Product' => class_exists('WC_Product'),
                'WC_Product_Simple' => class_exists('WC_Product_Simple'),
                'WC_Product_Variable' => class_exists('WC_Product_Variable'),
                'WC_Product_Data_Store_CPT' => class_exists('WC_Product_Data_Store_CPT'),
            ],
            'load_attempts' => [],
            'suggestions' => []
        ];

        // Paso 2: Verificar si el plugin está activo
        if (!function_exists('is_plugin_active')) {
            if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
                $diagnosis['load_attempts'][] = "Cargado wp-admin/includes/plugin.php para verificar plugins activos";
            }
        }

        if (function_exists('is_plugin_active')) {
            $diagnosis['woocommerce']['wc_active'] = is_plugin_active('woocommerce/woocommerce.php');
            
            // Verificar si está inactivo pero instalado
            if (!$diagnosis['woocommerce']['wc_active']) {
                if (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                    $diagnosis['suggestions'][] = "WooCommerce está instalado pero no activado. Activa el plugin desde el panel de administración.";
                } else {
                    $diagnosis['suggestions'][] = "WooCommerce no está instalado. Instala y activa WooCommerce antes de intentar sincronizar productos.";
                }
            }
        }

        // Paso 3: Intentar determinar la ruta de WooCommerce
        if (function_exists('WC')) {
            try {
                $wc = WC();
                if (method_exists($wc, 'plugin_path')) {
                    $diagnosis['woocommerce']['wc_path'] = $wc->plugin_path();
                }
            } catch (\Throwable $e) {
                $diagnosis['load_attempts'][] = "Error al obtener WC()->plugin_path(): " . $e->getMessage();
            }
        }

        // Si no pudimos obtener la ruta vía WC(), usar alternativas
        if ($diagnosis['woocommerce']['wc_path'] === 'no detectado') {
            if (defined('WC_PLUGIN_FILE')) {
                $diagnosis['woocommerce']['wc_path'] = plugin_dir_path(WC_PLUGIN_FILE);
            } elseif (defined('WC_ABSPATH')) {
                $diagnosis['woocommerce']['wc_path'] = WC_ABSPATH;
            } elseif (file_exists(WP_PLUGIN_DIR . '/woocommerce/woocommerce.php')) {
                $diagnosis['woocommerce']['wc_path'] = WP_PLUGIN_DIR . '/woocommerce/';
            }
        }

        // Paso 4: Verificar archivos críticos de WooCommerce
        if ($diagnosis['woocommerce']['wc_path'] !== 'no detectado') {
            $critical_files = [
                'woocommerce.php',
                'includes/wc-core-functions.php',
                'includes/wc-product-functions.php',
                'includes/abstracts/abstract-wc-product.php',
                'includes/class-wc-product-factory.php'
            ];

            $diagnosis['files'] = [];
            foreach ($critical_files as $file) {
                $full_path = $diagnosis['woocommerce']['wc_path'] . '/' . $file;
                $diagnosis['files'][$file] = [
                    'exists' => file_exists($full_path),
                    'readable' => is_readable($full_path),
                    'size' => file_exists($full_path) ? filesize($full_path) : 0,
                ];
                
                if (!file_exists($full_path)) {
                    $diagnosis['suggestions'][] = "Archivo crítico de WooCommerce no encontrado: $file. La instalación podría estar corrupta.";
                } elseif (!is_readable($full_path)) {
                    $diagnosis['suggestions'][] = "Archivo crítico de WooCommerce no es legible: $file. Verifica los permisos.";
                }
            }
        }

        // Paso 5: Si las funciones de productos no existen, intentar cargarlas
        if (!$diagnosis['product_functions']['wc_create_product'] || !$diagnosis['product_functions']['wc_update_product']) {
            $diagnosis['load_attempts'][] = "Intentando cargar las funciones de WooCommerce con load_woocommerce_functions()";
            $load_success = $this->load_woocommerce_functions();
            $diagnosis['load_attempts'][] = $load_success ? "Carga exitosa" : "Carga fallida";
            
            // Actualizar el estado después de intentar la carga
            $diagnosis['product_functions'] = [
                'wc_create_product' => function_exists('wc_create_product'),
                'wc_update_product' => function_exists('wc_update_product'),
                'wc_get_product' => function_exists('wc_get_product'),
                'wc_get_products' => function_exists('wc_get_products'),
            ];
        }

        // Paso 6: Conclusión y recomendaciones
        if (!$diagnosis['woocommerce']['wc_active']) {
            $diagnosis['status'] = 'error';
            $diagnosis['message'] = 'WooCommerce no está activo. Activa el plugin antes de sincronizar productos.';
        } elseif (!$diagnosis['product_functions']['wc_create_product'] || !$diagnosis['product_functions']['wc_update_product']) {
            $diagnosis['status'] = 'error';
            $diagnosis['message'] = 'Las funciones de WooCommerce para crear/actualizar productos no están disponibles.';
            $diagnosis['suggestions'][] = "Reinstala WooCommerce o contacta al soporte técnico del hosting para verificar la configuración de PHP.";
        } elseif (!$diagnosis['classes']['WC_Product']) {
            $diagnosis['status'] = 'error';
            $diagnosis['message'] = 'La clase WC_Product no está disponible, lo que indica un problema con la instalación de WooCommerce.';
        } else {
            $diagnosis['status'] = 'ok';
            $diagnosis['message'] = 'WooCommerce parece estar configurado correctamente para la sincronización de productos.';
        }

        return $diagnosis;
    }
    
    /**
     * Verifica si el ApiConnector está correctamente inicializado y válido
     * 
     * @return array Resultado con claves 'is_valid' y 'error' si hay un problema
     */
    private function is_api_connector_valid() {
        // Verificar que la propiedad api_connector esté definida
        if (!isset($this->api_connector)) {
            return [
                'is_valid' => false,
                'error' => __('El conector API no está inicializado.', 'mi_integracion-api')
            ];
        }
        
        // Verificar que sea una instancia válida de ApiConnector
        if (!($this->api_connector instanceof \MiIntegracionApi\Core\ApiConnector)) {
            return [
                'is_valid' => false,
                'error' => __('El conector API no es una instancia válida.', 'mi_integracion-api')
            ];
        }
        
        // Verificar que tenga los métodos necesarios
        if (!method_exists($this->api_connector, 'get_articulos')) {
            return [
                'is_valid' => false,
                'error' => __('El conector API no tiene los métodos requeridos.', 'mi_integracion-api')
            ];
        }
        
        // Todo en orden
        return [
            'is_valid' => true,
            'error' => ''
        ];
    }
    
    /**
     * Convierte una cadena de memoria (como '128M') a bytes
     * 
     * @param string $val La cadena de memoria
     * @return int El valor en bytes
     */
    private function return_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = (int)$val;
        
        switch($last) {
            case 'g':
                $val *= 1024;
            case 'm':
                $val *= 1024;
            case 'k':
                $val *= 1024;
        }
        
        return $val;
    }
    
    /**
     * Genera un SKU único para un producto de Verial
     * 
     * Intenta crear un SKU significativo utilizando varias fuentes de información
     * en orden de prioridad:
     * 1. ReferenciaBarras (código de barras)
     * 2. ReferenciaInterna
     * 3. ID_Referencia (si existe)
     * 4. Combinación de nombre simplificado + ID
     * 5. AUTO-VERIAL-{ID} como último recurso
     *
     * @param array $product Datos del producto de Verial
     * @return string SKU generado
     */
    private function generate_unique_sku($product) {
        // Determinar el ID de Verial (puede estar en 'Id' o 'ID_Articulo')
        $verial_id = null;
        if (!empty($product['Id'])) {
            $verial_id = $product['Id'];
        } elseif (!empty($product['ID_Articulo'])) {
            $verial_id = $product['ID_Articulo'];
        }
        
        if (!$verial_id) {
            throw new \Exception("No se pudo determinar el ID de Verial para generar SKU");
        }
        
        $candidates = [];
        
        // 1. Usar código de barras si existe
        if (!empty($product['ReferenciaBarras'])) {
            $candidates[] = $product['ReferenciaBarras'];
        }
        
        // 2. Usar referencia interna si existe
        if (!empty($product['ReferenciaInterna'])) {
            $candidates[] = $product['ReferenciaInterna'];
        }
        
        // 3. Usar ID_Referencia si existe (puede ser un código interno útil)
        if (!empty($product['ID_Referencia'])) {
            $candidates[] = $product['ID_Referencia'];
        }
        
        // 4. Generar basado en el nombre + ID
        if (!empty($product['Nombre'])) {
            // Extraer primeras palabras significativas (hasta 3)
            $name_parts = explode(' ', trim($product['Nombre']));
            $name_parts = array_slice($name_parts, 0, min(3, count($name_parts)));
            $name_simplified = implode('-', $name_parts);
            
            // Limpiar caracteres especiales para SKU
            $name_simplified = preg_replace('/[^A-Za-z0-9\-]/', '', $name_simplified);
            $name_simplified = strtoupper(substr($name_simplified, 0, 15));
            
            $candidates[] = $name_simplified . '-' . $verial_id;
        }
        
        // 5. Último recurso: usar el formato predeterminado
        $candidates[] = 'AUTO-VERIAL-' . $verial_id;
        
        // Evaluar cada candidato y verificar que no exista ya en WooCommerce
        foreach ($candidates as $candidate_sku) {
            // Asegurar que el SKU tenga un formato válido para WooCommerce
            $candidate_sku = $this->sanitize_sku($candidate_sku);
            
            // Si este SKU ya existe pero pertenece a otro producto de Verial, añadirle un sufijo
            $existing_product_id = wc_get_product_id_by_sku($candidate_sku);
            if ($existing_product_id) {
                $existing_verial_id = $this->get_verial_id_by_wc_product_id($existing_product_id);
                if ($existing_verial_id && $existing_verial_id != $verial_id) {
                    // Este SKU ya está en uso por otro producto de Verial, intentar con el siguiente
                    $this->logger->log("SKU candidato '$candidate_sku' ya en uso por producto #$existing_verial_id, probando siguiente opción");
                    continue;
                } else {
                    // Este SKU ya corresponde a este producto Verial, así que lo usamos
                    return $candidate_sku;
                }
            }
            
            // Si llegamos aquí, tenemos un SKU válido y único
            $this->logger->log("SKU generado para producto #$verial_id: $candidate_sku");
            return $candidate_sku;
        }
        
        // Medida de seguridad (nunca debería llegar aquí)
        $final_sku = 'AUTO-VERIAL-' . $verial_id . '-' . uniqid();
        $this->logger->log("Generación fallback de SKU para producto #$verial_id: $final_sku");
        return $final_sku;
    }
    
    /**
     * Sanitiza un SKU para asegurar que tenga formato válido para WooCommerce
     * 
     * @param string $sku SKU a sanitizar
     * @return string SKU sanitizado
     */
    private function sanitize_sku($sku) {
        // Eliminar espacios y caracteres no permitidos
        $sku = trim($sku);
        $sku = str_replace(' ', '-', $sku);
        $sku = preg_replace('/[^A-Za-z0-9\-_.]/', '', $sku);
        
        // Asegurar longitud razonable (máx 64 caracteres para evitar problemas de BD)
        if (strlen($sku) > 64) {
            $sku = substr($sku, 0, 64);
        }
        
        return $sku;
    }
}
