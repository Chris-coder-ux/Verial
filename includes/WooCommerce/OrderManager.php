<?php
/**
 * Gestor de Pedidos para la integración con Verial
 *
 * Maneja la sincronización y gestión de pedidos entre WooCommerce y Verial
 *
 * @package    MiIntegracionApi
 * @subpackage WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\Helpers\MapOrder;
use MiIntegracionApi\Core\RetryManager;
use MiIntegracionApi\Core\DataSanitizer;

/**
 * Clase OrderManager
 * 
 * Maneja la sincronización y gestión de pedidos entre WooCommerce y Verial
 */
class OrderManager {
    /**
     * Instancia única de esta clase (patrón Singleton)
     * 
     * @var OrderManager
     */
    private static $instance = null;
    
    /**
     * Conector de API para Verial
     * 
     * @var ApiConnector
     */
    private $api_connector;
    
    /**
     * Logger para registrar errores y eventos
     * 
     * @var Logger
     */
    private $logger;
    
    /**
     * Gestor de reintentos para la sincronización
     * 
     * @var RetryManager
     */
    private $retry_manager;
    
    /**
     * Sanitizador para datos
     * 
     * @var DataSanitizer
     */
    private $sanitizer;
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->api_connector = ApiConnector::get_instance();
        $this->logger = new \MiIntegracionApi\Helpers\Logger('order-manager');
        $this->retry_manager = new RetryManager();
        $this->sanitizer = new DataSanitizer();
        
        // Verificar que WooCommerce esté activo antes de registrar los hooks
        if (!\MiIntegracionApi\Hooks\HooksManager::is_woocommerce_active()) {
            $this->logger->warning('WooCommerce no está activo. No se registrarán los hooks de gestión de pedidos.');
            return;
        }
        
        // Registrar hooks para la gestión de pedidos de forma segura con prioridades estandarizadas
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_order_status_changed', 
            array($this, 'handle_order_status_changed'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'ORDER_STATUS_CHANGED'),
            3
        );
        
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_checkout_order_processed', 
            array($this, 'handle_new_order'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'CHECKOUT_PROCESSED'),
            3
        );
        
        \MiIntegracionApi\Hooks\HooksManager::add_wc_action(
            'woocommerce_api_create_order', 
            array($this, 'handle_api_order_creation'),
            \MiIntegracionApi\Hooks\HookPriorities::get('WOOCOMMERCE', 'API_CREATE_ORDER'),
            1
        );
        
        // Registrar cualquier error que pueda haber ocurrido
        $errors = \MiIntegracionApi\Hooks\HooksManager::get_errors();
        if (!empty($errors)) {
            foreach ($errors as $error) {
                $this->logger->error($error);
            }
            \MiIntegracionApi\Hooks\HooksManager::clear_errors();
        }
    }
    
    /**
     * Obtener la instancia única de esta clase (patrón Singleton)
     * 
     * @return OrderManager
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }
    
    /**
     * Maneja el cambio de estado de un pedido en WooCommerce
     * 
     * @param int    $order_id     ID del pedido
     * @param string $old_status   Estado anterior del pedido
     * @param string $new_status   Nuevo estado del pedido
     */
    public function handle_order_status_changed($order_id, $old_status, $new_status) {
        $this->logger->log( sprintf( __( "Cambio de estado del pedido #%d: %s -> %s", 'mi-integracion-api' ), $order_id, $old_status, $new_status ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            $order = wc_get_order($order_id);
            
            if (!$order) {
                $this->logger->log( sprintf( __( "No se pudo obtener el pedido #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return;
            }
            
            // Verificar si el pedido ya está sincronizado con Verial
            $verial_doc_id = $this->get_verial_doc_id($order);
            
            if (!$verial_doc_id && $new_status === 'processing') {
                // Si el pedido no está sincronizado y pasa a 'processing', lo sincronizamos
                $this->sync_order_to_verial($order);
            } elseif ($verial_doc_id) {
                // Si ya está sincronizado, actualizamos su estado en Verial
                $this->update_order_status_in_verial($order, $verial_doc_id, $new_status);
            }
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar cambio de estado del pedido #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Maneja la creación de un nuevo pedido en WooCommerce
     * 
     * @param int   $order_id ID del pedido
     * @param array $posted_data Datos del formulario de checkout
     * @param object $order Objeto del pedido
     */
    public function handle_new_order($order_id, $posted_data, $order) {
        $this->logger->log( sprintf( __( "Nuevo pedido creado #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Por defecto, no sincronizamos automáticamente los nuevos pedidos
            // Esperamos a que pasen al estado 'processing'
            // Para cambiar este comportamiento, descomentar la siguiente línea:
            // $this->sync_order_to_verial($order);
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar nuevo pedido #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Maneja la creación de pedidos a través de la API de WooCommerce
     * 
     * @param object $order Objeto del pedido
     */
    public function handle_api_order_creation($order) {
        $order_id = $order->get_id();
        $this->logger->log( sprintf( __( "Nuevo pedido creado a través de API #%d", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Sincronización automática para pedidos creados por API
            $this->sync_order_to_verial($order);
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al manejar pedido de API #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
    }
    
    /**
     * Sincroniza un pedido de WooCommerce a Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return bool|int ID del documento en Verial si fue exitoso, false si falló
     */
    public function sync_order_to_verial($order) {
        $order_id = $order->get_id();
        $this->logger->log( sprintf( __( "Sincronizando pedido #%d a Verial", 'mi-integracion-api' ), $order_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Preparar datos del cliente
            $customer_data = $this->prepare_customer_data($order);
            
            // Primero necesitamos verificar/crear el cliente en Verial
            $result = $this->api_connector->call('NuevoClienteWS', 'POST', $customer_data);
            
            if (!$result || isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                $error_msg = isset($result['InfoError']['Descripcion']) ? $result['InfoError']['Descripcion'] : __( 'Error desconocido', 'mi-integracion-api' );
                $this->logger->log( sprintf( __( "Error al crear/actualizar cliente en Verial: %s", 'mi-integracion-api' ), $error_msg ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            $client_id = $result['Id'];
            $this->logger->log( sprintf( __( "Cliente sincronizado con ID: %s", 'mi-integracion-api' ), $client_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
            
            // Preparar datos del pedido
            $order_data = $this->prepare_order_data($order, $client_id);
            
            // Crear el pedido en Verial
            $result = $this->api_connector->call('NuevoDocClienteWS', 'POST', $order_data);
            
            if (!$result || isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                $error_msg = isset($result['InfoError']['Descripcion']) ? $result['InfoError']['Descripcion'] : __( 'Error desconocido', 'mi-integracion-api' );
                $this->logger->log( sprintf( __( "Error al crear pedido en Verial: %s", 'mi-integracion-api' ), $error_msg ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            $verial_doc_id = $result['Id'];
            $this->logger->log( sprintf( __( "Pedido sincronizado con ID de Verial: %s", 'mi-integracion-api' ), $verial_doc_id ), \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
            
            // Guardar el ID de documento de Verial en el pedido de WooCommerce
            $order->update_meta_data('_verial_doc_id', $verial_doc_id);
            $order->save();
            
            return $verial_doc_id;
        } catch (\Exception $e) {
            $this->logger->log( sprintf( __( "Error al sincronizar pedido #%d: %s", 'mi-integracion-api' ), $order_id, $e->getMessage() ), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
            return false;
        }
    }
    
    /**
     * Actualiza el estado de un pedido en Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @param int $verial_doc_id ID del documento en Verial
     * @param string $new_status Nuevo estado del pedido
     * @return bool true si fue exitoso, false si falló
     */
    public function update_order_status_in_verial($order, $verial_doc_id, $new_status) {
        $order_id = $order->get_id();
        $this->logger->log("Actualizando estado del pedido #$order_id (Verial ID: $verial_doc_id) a: $new_status", \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
        
        try {
            // Mapeo de estados de WooCommerce a Verial
            $verial_status = $this->map_order_status_to_verial($new_status);
            
            // Preparar datos para actualizar el pedido
            $update_data = [
                'sesionwcf' => $this->api_connector->get_session_id(),
                'Id' => $verial_doc_id,
                'Estado' => $verial_status
            ];
            
            // Actualizar el pedido en Verial utilizando UpdateDocClienteWS
            $result = $this->api_connector->call('UpdateDocClienteWS', 'POST', $update_data);
            
            if (!$result || isset($result['InfoError']['Codigo']) && $result['InfoError']['Codigo'] != 0) {
                $error_msg = isset($result['InfoError']['Descripcion']) ? $result['InfoError']['Descripcion'] : 'Error desconocido';
                $this->logger->log("Error al actualizar pedido en Verial: $error_msg", \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
                return false;
            }
            
            $this->logger->log("Estado del pedido actualizado correctamente en Verial", \MiIntegracionApi\Helpers\Logger::LEVEL_INFO );
            return true;
        } catch (\Exception $e) {
            $this->logger->log("Error al actualizar estado del pedido #$order_id: " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
            return false;
        }
    }
    
    /**
     * Obtiene el ID de documento de Verial para un pedido de WooCommerce
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return int|bool ID del documento en Verial o false si no existe
     */
    public function get_verial_doc_id($order) {
        $verial_doc_id = $order->get_meta('_verial_doc_id', true);
        
        return $verial_doc_id ? $verial_doc_id : false;
    }
    
    /**
     * Prepara los datos del cliente para Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return array Datos del cliente formateados para Verial
     */
    private function prepare_customer_data($order) {
        // Datos del cliente
        $customer_data = [
            'sesionwcf' => $this->api_connector->get_session_id(),
            'Tipo' => 1, // 1 = Particular
            'Nombre' => $order->get_billing_first_name(),
            'Apellido1' => $order->get_billing_last_name(),
            'NIF' => $order->get_meta('_billing_nif', true) ?: $order->get_billing_company(),
            'Email' => $order->get_billing_email(),
            'Telefono1' => $order->get_billing_phone(),
            'CPostal' => $order->get_billing_postcode(),
            'Direccion' => trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2()),
            'Localidad' => $order->get_billing_city(),
            'Provincia' => $order->get_billing_state(),
        ];
        
        // Si es una empresa, cambiamos el tipo
        if ($order->get_billing_company()) {
            $customer_data['Tipo'] = 2; // 2 = Empresa
            $customer_data['RazonSocial'] = $order->get_billing_company();
        }
        
        return $customer_data;
    }
    
    /**
     * Prepara los datos del pedido para Verial
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @param int $client_id ID del cliente en Verial
     * @return array Datos del pedido formateados para Verial
     */
    private function prepare_order_data($order, $client_id) {
        // Datos básicos del documento
        $order_data = [
            'sesionwcf' => $this->api_connector->get_session_id(),
            'ID_Cliente' => $client_id,
            'Tipo' => 7, // 7 = Pedido Web
            'Descripcion' => sprintf(__('Pedido web #%s', 'mi-integracion-api'), $order->get_order_number()),
            'Fecha' => $order->get_date_created()->format('Y-m-d'),
            'Hora' => $order->get_date_created()->format('H:i:s'),
            'ID_FormaDeEnvio' => 1, // Valor por defecto, ajustar según corresponda
            'Contenido' => []
        ];
        
        // Método de pago
        $payment_method = $order->get_payment_method();
        $verial_payment_id = $this->map_payment_method_to_verial($payment_method);
        $order_data['ID_FormaDePago'] = $verial_payment_id;
        
        // Agregar líneas de pedido
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $sku = $product ? $product->get_sku() : '';
            
            // Buscar el ID del artículo en Verial
            $article_id = $this->get_verial_article_id($sku);
            
            $order_data['Contenido'][] = [
                'ID_Articulo' => $article_id,
                'Referencia' => $sku,
                'Descripcion' => $item->get_name(),
                'Cantidad' => $item->get_quantity(),
                'Precio' => $order->get_item_total($item, false),
                'PorcentajeDescuento' => 0, // Por defecto sin descuento
                'PorcentajeIVA' => $this->get_product_tax_rate($product, $order)
            ];
        }
        
        // Agregar gastos de envío si existen
        if ($order->get_shipping_total() > 0) {
            $order_data['Contenido'][] = [
                'ID_Articulo' => 0, // No es un artículo de inventario
                'Referencia' => 'ENVIO',
                'Descripcion' => __('Gastos de envío', 'mi-integracion-api'),
                'Cantidad' => 1,
                'Precio' => $order->get_shipping_total(),
                'PorcentajeDescuento' => 0,
                'PorcentajeIVA' => $this->get_shipping_tax_rate($order)
            ];
        }
        
        return $order_data;
    }
    
    /**
     * Mapea un estado de pedido de WooCommerce a un estado de Verial
     * 
     * @param string $wc_status Estado del pedido en WooCommerce
     * @return int Estado equivalente en Verial
     */
    private function map_order_status_to_verial($wc_status) {
        $status_map = [
            'pending' => 1,    // Pendiente
            'processing' => 2, // En proceso
            'on-hold' => 3,    // En espera
            'completed' => 4,  // Completado
            'cancelled' => 5,  // Cancelado
            'refunded' => 6,   // Reembolsado
            'failed' => 7      // Fallido
        ];
        
        return isset($status_map[$wc_status]) ? $status_map[$wc_status] : 1;
    }
    
    /**
     * Mapea un método de pago de WooCommerce a un ID de forma de pago en Verial
     * 
     * @param string $payment_method Método de pago en WooCommerce
     * @return int ID del método de pago en Verial
     */
    private function map_payment_method_to_verial($payment_method) {
        $payment_map = [
            'bacs' => 1,        // Transferencia bancaria
            'cheque' => 2,      // Cheque
            'cod' => 3,         // Contra reembolso
            'stripe' => 4,      // Tarjeta de crédito
            'paypal' => 5,      // PayPal
            'redsys' => 6,      // Redsys
            'bizum' => 7,       // Bizum
            'default' => 1      // Por defecto
        ];
        
        return isset($payment_map[$payment_method]) ? $payment_map[$payment_method] : $payment_map['default'];
    }
    
    /**
     * Obtiene el ID de artículo en Verial a partir del SKU
     * 
     * @param string $sku SKU del producto
     * @return int|null ID del artículo en Verial o null si no se encuentra
     */
    private function get_verial_article_id($sku) {
        if (empty($sku)) {
            return null;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'verial_product_mapping';
        
        // Intentar obtener el ID desde la tabla de mapeo
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name) {
            $article_id = $wpdb->get_var($wpdb->prepare(
                "SELECT verial_id FROM $table_name WHERE sku = %s LIMIT 1",
                $sku
            ));
            
            if ($article_id) {
                return $article_id;
            }
        }
        
        // Si no se encontró en la tabla de mapeo, intentar buscarlo en Verial por referencia
        try {
            $result = $this->api_connector->call('GetArticulosWS?inicio=1&fin=10&referenciaBarras=' . urlencode($sku), 'GET');
            
            if ($result && isset($result['Articulos']) && is_array($result['Articulos']) && count($result['Articulos']) > 0) {
                return $result['Articulos'][0]['Id'];
            }
        } catch (\Exception $e) {
            $this->logger->log("Error al buscar artículo por SKU '$sku': " . $e->getMessage(), \MiIntegracionApi\Helpers\Logger::LEVEL_ERROR );
        }
        
        return null;
    }
    
    /**
     * Obtiene el porcentaje de IVA para un producto
     * 
     * @param object $product Objeto del producto WooCommerce
     * @param object $order Objeto del pedido WooCommerce
     * @return float Porcentaje de IVA
     */
    private function get_product_tax_rate($product, $order) {
        if (!$product || !$order->get_item_tax_status($product)) {
            return 0;
        }
        
        // Intentar obtener la tasa de impuestos del pedido
        $tax_items = $order->get_items('tax');
        
        if (!empty($tax_items)) {
            $tax_item = reset($tax_items);
            return $tax_item->get_rate_percent();
        }
        
        // Si no se puede obtener del pedido, usar un valor predeterminado
        return 21.0; // 21% es el IVA estándar en España
    }
    
    /**
     * Obtiene el porcentaje de IVA para los gastos de envío
     * 
     * @param object $order Objeto del pedido WooCommerce
     * @return float Porcentaje de IVA
     */
    private function get_shipping_tax_rate($order) {
        if ($order->get_shipping_tax() > 0) {
            // Calcular el porcentaje de impuesto
            $shipping_total = $order->get_shipping_total();
            $shipping_tax = $order->get_shipping_tax();
            
            if ($shipping_total > 0) {
                return ($shipping_tax / $shipping_total) * 100;
            }
        }
        
        // Valor por defecto
        return 21.0; // 21% es el IVA estándar en España
    }

    /**
     * Sincroniza un pedido de WooCommerce a Verial
     *
     * @param \WC_Order $order Pedido de WooCommerce
     * @return bool|int Resultado de la sincronización
     */
    public function sync_order($order): bool {
        try {
            if (!$order instanceof \WC_Order) {
                $this->logger->error('Pedido no encontrado');
                return false;
            }

            $order_data = $this->prepare_order_data($order);
            $order_data = $this->sanitizer->sanitize($order_data, 'text');

            $order_dto = MapOrder::wc_to_verial($order_data);
            if (!$order_dto) {
                $this->logger->error('Error al mapear pedido', [
                    'pedido_id' => $order->get_id()
                ]);
                return false;
            }

            $result = $this->api_connector->sync_order($order_dto);
            if ($result) {
                $this->logger->info('Pedido sincronizado exitosamente', [
                    'pedido_id' => $order->get_id()
                ]);
                return true;
            }

            $this->logger->error('Error al sincronizar pedido', [
                'pedido_id' => $order->get_id()
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error al sincronizar pedido', [
                'error' => $e->getMessage(),
                'pedido_id' => $order->get_id()
            ]);
            return false;
        }
    }

    /**
     * Verifica el estado de sincronización de un pedido
     *
     * @param int $order_id ID del pedido
     * @return string|null Estado de sincronización
     */
    public function check_sync_status(int $order_id): ?string {
        try {
            // Sanitizar ID del pedido
            $order_id = $this->sanitizer->sanitize($order_id, 'int');

            // Verificar estado de sincronización
            $status = $this->api_connector->get_order_sync_status($order_id);
            if ($status) {
                return $this->sanitizer->sanitize($status, 'text');
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error al verificar estado de sincronización', [
                'error' => $e->getMessage(),
                'pedido_id' => $order_id
            ]);
            return null;
        }
    }

    /**
     * Reintenta la sincronización de un pedido fallido
     *
     * @param int $order_id ID del pedido
     * @return bool Resultado del reintento
     */
    public function retry_sync(int $order_id): bool {
        try {
            // Sanitizar ID del pedido
            $order_id = $this->sanitizer->sanitize($order_id, 'int');

            // Verificar si se puede reintentar
            if (!$this->retry_manager->can_retry('order', $order_id)) {
                $this->logger->warning('No se puede reintentar la sincronización', [
                    'pedido_id' => $order_id
                ]);
                return false;
            }

            // Obtener datos del pedido
            $order = wc_get_order($order_id);
            if (!$order) {
                $this->logger->error('No se pudieron obtener los datos del pedido', [
                    'pedido_id' => $order_id
                ]);
                return false;
            }

            // Intentar sincronización
            $result = $this->sync_order($order);
            if ($result) {
                $this->retry_manager->mark_success('order', $order_id);
                return true;
            }

            $this->retry_manager->mark_failure('order', $order_id);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error al reintentar sincronización', [
                'error' => $e->getMessage(),
                'pedido_id' => $order_id
            ]);
            return false;
        }
    }
}
// Todas las llamadas a log usan Logger::LEVEL_INFO o Logger::LEVEL_ERROR (corregido previamente).
