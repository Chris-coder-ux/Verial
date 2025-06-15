<?php

namespace MiIntegracionApi\WooCommerce;

/**
 * Clase para manejar los hooks de WooCommerce y la interacción con la API de Verial.
 *
 * @author Christian (con mejoras propuestas)
 * @package mi-integracion-api
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Salir si se accede directamente.
}

// Asegurarse de que las clases Helper y el ApiConnector estén disponibles.
// Se asume que se cargan en el archivo principal del plugin.
// Ejemplo de 'use' statements si las clases estuvieran en namespaces:
// use MiIntegracionApi\Helpers\Logger;
// use MiIntegracionApi\Core\ApiConnector;
// use MiIntegracionApi\Helpers\Map_Customer;
// use MiIntegracionApi\Helpers\Map_Order;
// use MiIntegracionApi\Endpoints\MI_Endpoint_NuevoDocClienteWS; // Si está en el namespace global


    class WooCommerceHooks {

        private $api_connector; // Debería ser tipado como \MiIntegracionApi\Core\ApiConnector

        /**
         * Constructor.
         *
         * @param \MiIntegracionApi\Core\ApiConnector $api_connector Instancia del conector de la API.
         * @param bool $auto_init Si se debe inicializar automáticamente (por defecto true).
         */
        public function __construct( \MiIntegracionApi\Core\ApiConnector $api_connector, $auto_init = true ) {
            $this->api_connector = $api_connector;
            
            // Verificar WooCommerce antes de inicializar
            if (!$this->is_woocommerce_active()) {
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>';
                    echo '<strong>' . esc_html__('Mi Integración API:', 'mi-integracion-api') . '</strong> ';
                    echo esc_html__('Requiere WooCommerce activo para funcionar correctamente.', 'mi-integracion-api');
                    echo '</p></div>';
                });
                return;
            }
            
            // Solo inicializar hooks automáticamente si se solicita y WooCommerce está activo
            if ( $auto_init ) {
                $this->init_hooks();
            }
        }

        /**
         * Verifica si WooCommerce está realmente activo y disponible
         * 
         * @return bool True si WooCommerce está activo y funcional
         */
        private function is_woocommerce_active(): bool {
            // Verificación básica de clase
            if (!class_exists('WooCommerce')) {
                return false;
            }
            
            // Verificar que la función WC() esté disponible
            if (!function_exists('WC')) {
                return false;
            }
            
            // Verificar que la instancia principal de WooCommerce esté disponible
            $wc_instance = WC();
            if (!$wc_instance || !is_object($wc_instance)) {
                return false;
            }
            
            // Verificar que las funciones básicas de WooCommerce estén disponibles
            if (!function_exists('wc_get_order') || !function_exists('wc_get_product')) {
                return false;
            }
            
            // Verificar que el plugin esté realmente activado
            if (!is_plugin_active('woocommerce/woocommerce.php')) {
                // Si is_plugin_active no está disponible, intentar verificación alternativa
                if (!function_exists('is_plugin_active')) {
                    include_once(ABSPATH . 'wp-admin/includes/plugin.php');
                }
                
                // Si aún no está disponible o WooCommerce no está activo
                if (!function_exists('is_plugin_active') || !is_plugin_active('woocommerce/woocommerce.php')) {
                    return false;
                }
            }
            
            return true;
        }

        /**
         * Método alias para init_hooks() para mantener compatibilidad con código que lo llame directamente
         *
         * @return void
         */
        public function init() {
            $this->init_hooks();
        }
        
        /**
         * Inicializa los hooks de WooCommerce con verificaciones adicionales de seguridad.
         */
        public function init_hooks() {
            // Verificación adicional de seguridad antes de registrar hooks
            if (!$this->is_woocommerce_active()) {
                error_log('Mi Integración API: Intento de inicializar hooks sin WooCommerce activo');
                return;
            }
            
            // Verificar que el ApiConnector esté disponible
            if (!$this->api_connector || !is_object($this->api_connector)) {
                error_log('Mi Integración API: ApiConnector no está disponible para los hooks de WooCommerce');
                return;
            }
            
            // Verificar que el ApiConnector tenga configuración válida
            if (method_exists($this->api_connector, 'get_api_base_url')) {
                $api_url = $this->api_connector->get_api_base_url();
                if (empty($api_url)) {
                    error_log('Mi Integración API: URL base de la API no configurada');
                    return;
                }
            }
            
            // Sincronizar nuevo cliente (usuario de WordPress) con Verial al registrarse
            add_action( 'user_register', array( $this, 'enqueue_user_sync_action' ), 10, 1 );

            // Sincronizar nuevo pedido con Verial cuando se procesa el checkout
            add_action( 'woocommerce_checkout_order_processed', array( $this, 'enqueue_order_sync_action' ), 10, 2 );

            // Hooks adicionales con verificaciones de dependencias
            if (function_exists('wc_get_order') && function_exists('wc_get_product')) {
                // Solo agregar hooks que requieren funciones específicas de WooCommerce
                
                // Futuras expansiones (descomentar y desarrollar si es necesario):
                // add_action('profile_update', array($this, 'handle_user_profile_update'), 10, 2);
                // add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_changed'), 10, 4);
                // add_action('save_post_product', array($this, 'handle_product_save'), 10, 3);
            }
            
            // Log de inicialización exitosa
            if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
                \MiIntegracionApi\Helpers\Logger::info('Hooks de WooCommerce inicializados correctamente', 'woocommerce_hooks');
            }
        }

        /**
         * Maneja el registro de un nuevo usuario de WordPress.
         * Envía los datos del nuevo usuario a Verial para crear un cliente.
         *
         * @param int $user_id ID del usuario recién registrado.
         */
        public function handle_new_user_registration( int $user_id ) {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }
            if ( ! $user_id ) {
                return;
            }

            $user_data = get_userdata( $user_id );
            if ( ! $user_data instanceof \WP_User ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] No se pudieron obtener datos válidos para el usuario ID: %d', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
                return;
            }

            $sesion_verial = $this->api_connector->get_numero_sesion();
            if ( is_wp_error( $sesion_verial ) ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Error al obtener número de sesión de Verial: %s', 'mi-integracion-api' ), $sesion_verial->get_error_message() ), 'mia-hooks' );
                return;
            }
            if ( empty( $sesion_verial ) && $sesion_verial !== '0' ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Sincronización de nuevo usuario (%d) fallida: Número de sesión de Verial no configurado.', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
                return;
            }

            $cliente_payload_base = array();
            // Intentar usar el helper de mapeo centralizado
            if ( class_exists( '\MiIntegracionApi\Helpers\Map_Customer' ) && method_exists( '\MiIntegracionApi\Helpers\Map_Customer', 'wc_user_to_verial_payload' ) ) {
                $cliente_payload_base = \MiIntegracionApi\Helpers\Map_Customer::wc_user_to_verial_payload( $user_data, $this ); // Pasar $this si Map_Customer necesita llamar a get_verial_country_id_from_wc_code
            } else {
                // Fallback a mapeo manual si Map_Customer no está disponible
                \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Usando mapeo manual para NuevoClienteWS (user_register). Considerar implementar \MiIntegracionApi\Helpers\Map_Customer::wc_user_to_verial_payload.', 'mia-hooks' );
                $cliente_payload_base = array(
                    'Tipo'           => 1,
                    'NIF'            => get_meta_safe( $user_id, 'billing_vat_id', 'user', '' ) ?: get_meta_safe( $user_id, 'vat_number', 'user', '' ),
                    'Nombre'         => $user_data->first_name ?: $user_data->display_name,
                    'Apellido1'      => $user_data->last_name ?: '',
                    'Apellido2'      => '', // WP no tiene segundo apellido por defecto
                    'RazonSocial'    => '', // Vacío si es particular
                    'RegFiscal'      => 1, // Asumir IVA general, ajustar si es necesario
                    'ID_Pais'        => $this->get_verial_country_id_from_wc_code( get_meta_safe( $user_id, 'billing_country', 'user', '' ) ),
                    'Provincia'      => get_meta_safe( $user_id, 'billing_state', 'user', '' ) ?: '',
                    'Localidad'      => get_meta_safe( $user_id, 'billing_city', 'user', '' ) ?: '',
                    'CPostal'        => get_meta_safe( $user_id, 'billing_postcode', 'user', '' ) ?: '',
                    'Direccion'      => trim( get_meta_safe( $user_id, 'billing_address_1', 'user', '' ) . ' ' . get_meta_safe( $user_id, 'billing_address_2', 'user', '' ) ),
                    'Telefono'       => get_meta_safe( $user_id, 'billing_phone', 'user', '' ) ?: '', // O Telefono1 según Verial
                    'Email'          => $user_data->user_email,
                    'WebUser'        => $user_data->user_login, // O user_email
                    'EnviarAnuncios' => false, // Ajustar según la lógica de consentimiento
                    // 'Sexo' => 0, // Si se recopila
                );
            }

            // Validaciones críticas antes de enviar
            if ( empty( $cliente_payload_base['Email'] ) ) {
                \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Sincronización de nuevo usuario (' . $user_id . ') fallida: Email es requerido.', 'mia-hooks' );
                return;
            }
            // Añadir más validaciones aquí si son necesarias para Verial (ej. NIF para particulares)

            $cliente_payload = array_merge(
                array(
                    'sesionwcf' => intval( $sesion_verial ),
                    'Id'        => 0,
                ), // Id 0 para nuevo cliente
                $cliente_payload_base
            );

            // Llamada directa al conector para enviar los datos a Verial
            $verial_response = $this->api_connector->post( 'NuevoClienteWS', $cliente_payload );

            // Procesar respuesta de Verial
            if ( is_wp_error( $verial_response ) ) {
                \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Error al sincronizar nuevo usuario ID ' . $user_id . ' con Verial (ApiConnector): ' . $verial_response->get_error_message(), 'mia-hooks' );
            } elseif ( isset( $verial_response['InfoError']['Codigo'] ) && intval( $verial_response['InfoError']['Codigo'] ) === \MiIntegracionApi\Endpoints\MI_Endpoint_NuevoClienteWS::VERIAL_ERROR_SUCCESS ) { // Usar constante de la clase endpoint
                $id_cliente_verial = $verial_response['Id'] ?? null;
                if ( $id_cliente_verial ) {
                    update_user_meta( $user_id, '_verial_cliente_id', intval( $id_cliente_verial ) );
                    \MiIntegracionApi\Helpers\Logger::info( sprintf( __( '[MI Hooks] Nuevo usuario ID %d sincronizado con Verial. ID Verial: %d', 'mi-integracion-api' ), $user_id, $id_cliente_verial ), 'mia-hooks' );
                } else {
                    \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Nuevo usuario ID %d sincronizado con Verial pero no se recibió ID de cliente de Verial.', 'mi-integracion-api' ), $user_id ), 'mia-hooks' );
                }
            } else {
                $error_msg = $verial_response['InfoError']['Descripcion'] ?? __( 'Error desconocido de Verial', 'mi-integracion-api' );
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Fallo al sincronizar nuevo usuario ID %d con Verial: %s. Payload: %s', 'mi-integracion-api' ), $user_id, $error_msg, wp_json_encode( $cliente_payload ) ), 'mia-hooks' );
            }
        }

        /**
         * Maneja la creación de un nuevo pedido procesado.
         * Envía los datos del pedido a Verial.
         *
         * @param int   $order_id ID del pedido.
         * @param array $posted_data Datos del formulario de checkout (puede no ser necesario si se usa el objeto $order).
         */
        public function handle_new_order_processed( int $order_id, array $posted_data ) {
            if ( ! class_exists( 'WooCommerce' ) ) {
                return;
            }
            if ( ! $order_id ) {
                return;
            }

            $order = wc_get_order( $order_id );
            if ( ! $order instanceof \WC_Order ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] No se pudo obtener el objeto WC_Order para el ID de pedido: %d', 'mi-integracion-api' ), $order_id ), 'mia-hooks' );
                return;
            }

            $sesion_verial = $this->api_connector->get_numero_sesion();
            if ( is_wp_error( $sesion_verial ) ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Error al obtener número de sesión de Verial: %s', 'mi-integracion-api' ), $sesion_verial->get_error_message() ), 'mia-hooks' );
                $order->add_order_note( __( 'Error: No se pudo sincronizar el pedido con Verial (error de sesión).', 'mi-integracion-api' ) );
                return;
            }
            if ( empty( $sesion_verial ) && $sesion_verial !== '0' ) {
                \MiIntegracionApi\Helpers\Logger::error( sprintf( __( '[MI Hooks] Sincronización de pedido (%d) fallida: Número de sesión de Verial no configurado.', 'mi-integracion-api' ), $order_id ), 'mia-hooks' );
                $order->add_order_note( __( 'Error: No se pudo sincronizar el pedido con Verial (sesión no configurada).', 'mi-integracion-api' ) );
                wp_mail(
                    get_option( 'admin_email' ),
                    __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                    /* translators: %1$d: ID del pedido */
                    sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial porque la sesión no está configurada.', 'mi-integracion-api' ), $order_id )
                );
                return;
            }

            $documento_payload_base = array();
            // Intentar usar el helper de mapeo centralizado
            if ( class_exists( '\MiIntegracionApi\Helpers\Map_Order' ) && method_exists( '\MiIntegracionApi\Helpers\Map_Order', 'wc_order_to_verial_nuevo_doc_payload' ) ) {
                $documento_payload_base = \MiIntegracionApi\Helpers\Map_Order::wc_order_to_verial_nuevo_doc_payload( $order, $this ); // Pasar $this para acceso a helpers como get_verial_country_id_from_wc_code
            } else {
                \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Usando mapeo manual para NuevoDocClienteWS (pedido ' . $order_id . '). Considerar implementar \\MiIntegracionApi\\Helpers\\Map_Order::wc_order_to_verial_nuevo_doc_payload.', 'mia-hooks' );
                wp_mail(
                    get_option( 'admin_email' ),
                    __( '[Alerta] Fallback de mapeo manual en sincronización de pedido', 'mi-integracion-api' ),
                    /* translators: %1$d: ID del pedido */
                    sprintf( __( 'Se ha utilizado el mapeo manual para el pedido #%1$d porque la clase Map_Order o su método no están disponibles. Revisa la instalación del plugin o el autoloader.', 'mi-integracion-api' ), $order_id )
                );

                // --- Inicio Fallback Mapeo Manual Mejorado ---
                $user_id                     = $order->get_user_id();
                $id_cliente_verial           = $user_id ? get_user_meta( $user_id, '_verial_cliente_id', true ) : null;
                $cliente_para_verial_payload = array();

                if ( $id_cliente_verial ) {
                    $cliente_para_verial_payload['ID_Cliente'] = intval( $id_cliente_verial );
                } else { // Cliente invitado o no sincronizado previamente
                    $billing_email = $order->get_billing_email();
                    if ( class_exists( 'MiIntegracionApi\\Helpers\\Map_Customer' ) && method_exists( 'MiIntegracionApi\\Helpers\\Map_Customer', 'wc_order_guest_to_verial_cliente_object' ) ) {
                        $cliente_para_verial_payload['Cliente'] = \MiIntegracionApi\Helpers\Map_Customer::wc_order_guest_to_verial_cliente_object( $order, $this );
                    } else {
                        \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Usando mapeo manual para cliente invitado en pedido ' . $order_id . '. Considerar implementar \\MiIntegracionApi\\Helpers\\Map_Customer::wc_order_guest_to_verial_cliente_object.', 'mia-hooks' );
                        wp_mail(
                            get_option( 'admin_email' ),
                            __( '[Alerta] Fallback de mapeo manual en mapeo de cliente', 'mi-integracion-api' ),
                            /* translators: %1$d: ID del pedido */
                            sprintf( __( 'Se ha utilizado el mapeo manual para el cliente invitado en el pedido #%1$d porque la clase Map_Customer o su método no están disponibles. Revisa la instalación del plugin o el autoloader.', 'mi-integracion-api' ), $order_id )
                        );
                        $cliente_para_verial_payload['Cliente'] = array(
                            'Id'          => 0,
                            'Tipo'        => 1,
                            'NIF'         => sanitize_text_field(
                                $order->get_meta( '_billing_vat_id' ) ?: (
                                    $order->get_billing_company() ? ( $order->get_meta( '_billing_company_vat_id' ) ?: '' ) : 'X9999999R'
                                )
                            ),
                            'Nombre'      => sanitize_text_field( $order->get_billing_first_name() ?: $billing_email ),
                            'Apellido1'   => sanitize_text_field( $order->get_billing_last_name() ?: '' ),
                            'RazonSocial' => sanitize_text_field( $order->get_billing_company() ?: '' ),
                            'RegFiscal'   => 1,
                            'ID_Pais'     => $this->get_verial_country_id_from_wc_code( sanitize_text_field( $order->get_billing_country() ) ),
                            'Provincia'   => sanitize_text_field( $order->get_billing_state() ),
                            'Localidad'   => sanitize_text_field( $order->get_billing_city() ),
                            'CPostal'     => sanitize_text_field( $order->get_billing_postcode() ),
                            'Direccion'   => trim(
                                sanitize_text_field( $order->get_billing_address_1() ) . ' ' .
                                sanitize_text_field( $order->get_billing_address_2() )
                            ),
                            'Telefono'    => sanitize_text_field( $order->get_billing_phone() ),
                            'Email'       => sanitize_email( $billing_email ),
                            'WebUser'     => sanitize_email( $billing_email ),
                        );
                        // Validaciones y asignaciones por defecto para el cliente invitado (fallback)
                        if ( empty( $cliente_para_verial_payload['Cliente']['Email'] ) || ! is_email( $cliente_para_verial_payload['Cliente']['Email'] ) ) {
                            $cliente_para_verial_payload['Cliente']['Email'] = 'no-reply@tudominio.com';
                            \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Fallback: Email de cliente invitado no válido en pedido ' . $order_id, 'mia-hooks' );
                        }
                        if ( empty( $cliente_para_verial_payload['Cliente']['NIF'] ) ) {
                            $cliente_para_verial_payload['Cliente']['NIF'] = 'X9999999R';
                            \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Fallback: NIF vacío en pedido ' . $order_id, 'mia-hooks' );
                        }
                        foreach ( array( 'Nombre', 'Apellido1', 'NIF' ) as $campo ) {
                            if ( empty( $cliente_para_verial_payload['Cliente'][ $campo ] ) ) {
                                \MiIntegracionApi\Helpers\Logger::warning( "[MI Hooks] Fallback: Campo $campo vacío en pedido $order_id", 'mia-hooks' );
                            }
                        }
                    }
                }

                // Mapeo robusto de líneas de pedido
                $omitidas         = array();
                $lineas_contenido = array();
                foreach ( $order->get_items() as $item_id => $item ) {
                    if ( ! $item instanceof \WC_Order_Item_Product ) {
                        continue;
                    }
                    $product            = $item->get_product();
                    $id_articulo_verial = $product ? get_post_meta( $product->get_id(), '_verial_articulo_id', true ) : null;
                    if ( empty( $id_articulo_verial ) && $product ) {
                        \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Producto ID WC:' . $product->get_id() . ' (SKU: ' . $product->get_sku() . ') en pedido ' . $order_id . ' no tiene ID de Verial (_verial_articulo_id). Línea no sincronizada.', 'mia-hooks' );
                        $omitidas[] = $item->get_name();
                        continue;
                    } elseif ( ! $product ) {
                        \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Item ID ' . $item_id . ' en pedido ' . $order_id . ' no es un producto válido o no se pudo obtener. Línea no sincronizada.', 'mia-hooks' );
                        $omitidas[] = $item->get_name();
                        continue;
                    }
                    $linea = array(
                        'TipoRegistro'  => 1,
                        'ID_Articulo'   => intval( $id_articulo_verial ),
                        'Comentario'    => '',
                        'Uds'           => floatval( $item->get_quantity() ),
                        'Precio'        => floatval( $order->get_item_subtotal( $item, false, false ) ),
                        'Dto'           => 0,
                        'ImporteLinea'  => floatval( $order->get_line_subtotal( $item, false, false ) ),
                        'PorcentajeIVA' => $this->get_item_tax_rate( $item ),
                        'Concepto'      => sanitize_text_field( $item->get_name() ),
                    );
                    // Validación de línea
                    foreach ( array( 'ID_Articulo', 'Uds', 'Precio', 'ImporteLinea', 'Concepto' ) as $campo ) {
                        if ( empty( $linea[ $campo ] ) && $linea[ $campo ] !== 0 && $linea[ $campo ] !== '0' ) {
                            \MiIntegracionApi\Helpers\Logger::warning( "[MI Hooks] Fallback: Campo $campo vacío en línea de pedido $order_id", 'mia-hooks' );
                        }
                    }
                    $lineas_contenido[] = $linea;
                }
                if ( ! empty( $omitidas ) ) {
                    $nota = 'Líneas de producto omitidas en la sincronización con Verial (sin ID Verial): ' . implode( ', ', $omitidas );
                    wp_mail(
                        get_option( 'admin_email' ),
                        __( 'Líneas de producto omitidas en pedido WooCommerce', 'mi-integracion-api' ),
                        $msg
                    );
                }

                // Mapeo robusto de pagos
                $pagos_payload = array();
                if ( $order->is_paid() && $order->get_payment_method() ) {
                    $id_metodo_pago_verial = $this->get_verial_payment_method_id( $order->get_payment_method() );
                    if ( $id_metodo_pago_verial > 0 ) {
                        $pagos_payload[] = array(
                            'ID_MetodoPago' => $id_metodo_pago_verial,
                            'Fecha'         => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d' ) : current_time( 'Y-m-d' ),
                            'Importe'       => floatval( $order->get_total() ),
                        );
                    } else {
                        \MiIntegracionApi\Helpers\Logger::warning( '[MI Hooks] Pedido ID ' . $order_id . ': Método de pago WC "' . $order->get_payment_method() . '" no mapeado a Verial. El pago no se registrará en Verial.', 'mia-hooks' );
                        $order->add_order_note(/* translators: %1$s: método de pago */
                            sprintf( __( 'Advertencia: Método de pago "%1$s" no mapeado a Verial. El pago no se registrará automáticamente en Verial.', 'mi-integracion-api' ), $order->get_payment_method_title() )
                        );
                    }
                }

                // Construcción final del payload del documento
                $documento_payload_base = array_merge(
                    array(
                        'Id'                  => 0,
                        'Tipo'                => \MiIntegracionApi\Endpoints\MI_Endpoint_NuevoDocClienteWS::TIPO_PEDIDO,
                        'Fecha'               => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d' ) : current_time( 'Y-m-d' ),
                        'Referencia'          => $order->get_order_number(),
                        'Contenido'           => $lineas_contenido,
                        'PreciosImpIncluidos' => wc_prices_include_tax(),
                        'BaseImponible'       => floatval( $order->get_subtotal() ),
                        'TotalImporte'        => floatval( $order->get_total() ),
                        'Portes'              => floatval( $order->get_shipping_total() ),
                    ),
                    $cliente_para_verial_payload
                );
                if ( ! empty( $pagos_payload ) ) {
                    $documento_payload_base['Pagos'] = $pagos_payload;
                }
                // --- Fin Fallback Mapeo Manual Mejorado ---
            }

            // Validar que después del mapeo (centralizado o fallback) haya contenido
            if ( empty( $documento_payload_base['Contenido'] ) ) {
                \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Pedido ID ' . $order_id . ' procesado sin líneas de contenido válidas para Verial (después del mapeo).', 'mia-hooks' );
                $order->add_order_note( __( 'Error: Pedido sin líneas válidas para sincronizar con Verial (después del mapeo).', 'mi-integracion-api' ) );
                return;
            }

            $documento_payload_final = array_merge(
                array( 'sesionwcf' => intval( $sesion_verial ) ),
                $documento_payload_base
            );

            // Llamada directa al conector
            $verial_response = $this->api_connector->nuevo_doc_cliente( $documento_payload_final );
            if ( is_wp_error( $verial_response ) ) {
                $order->add_order_note(/* translators: %s: mensaje de error */
                    sprintf( __( 'Error al sincronizar pedido con Verial: %s', 'mi-integracion-api' ), $verial_response->get_error_message() )
                );
                wp_mail(
                    get_option( 'admin_email' ),
                    __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                    /* translators: %1$d: ID del pedido, %2$s: mensaje de error */
                    sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial. Mensaje: %2$s', 'mi-integracion-api' ), $order_id, $verial_response->get_error_message() )
                );
                // Usar métodos compatibles con HPOS para actualizar metadatos
                MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_sync_status', 'incompleto' );
                return;
            } elseif ( isset( $verial_response['InfoError']['Codigo'] ) && intval( $verial_response['InfoError']['Codigo'] ) === \MiIntegracionApi\Endpoints\MI_Endpoint_NuevoDocClienteWS::VERIAL_ERROR_SUCCESS ) { // Usar constante de la clase endpoint
                $id_documento_verial     = $verial_response['Id'] ?? null;
                $numero_documento_verial = $verial_response['Numero'] ?? null;
                if ( $id_documento_verial ) {
                    MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_documento_id', intval( $id_documento_verial ) );
                    if ( $numero_documento_verial ) {
                        MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_documento_numero', sanitize_text_field( $numero_documento_verial ) );
                    }
                    $order->add_order_note(/* translators: %1$s: ID documento Verial, %2$s: número Verial */
                        sprintf( __( 'Pedido sincronizado con Verial. ID Documento Verial: %1$s, Número Verial: %2$s', 'mi-integracion-api' ), $id_documento_verial, ( $numero_documento_verial ?? 'N/A' ) )
                    );
                    \MiIntegracionApi\Helpers\Logger::info( '[MI Hooks] Pedido ID ' . $order_id . ' sincronizado con Verial. ID Verial: ' . $id_documento_verial, 'mia-hooks' );
                } else {
                    \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Pedido ID ' . $order_id . ' sincronizado con Verial pero no se recibió ID de documento de Verial.', 'mia-hooks' );
                }
            } else {
                $error_msg = $verial_response['InfoError']['Descripcion'] ?? __( 'Error desconocido de Verial', 'mi-integracion-api' );
                \MiIntegracionApi\Helpers\Logger::error( '[MI Hooks] Fallo al sincronizar pedido ID ' . $order_id . ' con Verial: ' . $error_msg . '. Payload Keys: ' . implode( ', ', array_keys( $documento_payload_final ) ), 'mia-hooks' );
                $order->add_order_note(/* translators: %s: mensaje de error */
                    sprintf( __( 'Error al sincronizar pedido con Verial: %s', 'mi-integracion-api' ), $error_msg )
                );
                wp_mail(
                    get_option( 'admin_email' ),
                    __( 'Error de sincronización con Verial', 'mi-integracion-api' ),
                    /* translators: %1$d: ID del pedido, %2$s: mensaje de error */
                    sprintf( __( 'El pedido #%1$d no se pudo sincronizar con Verial. Mensaje: %2$s', 'mi-integracion-api' ), $order_id, $error_msg )
                );
                // Usar métodos compatibles con HPOS
                MiIntegracionApi\WooCommerce\HposCompatibility::manage_order_meta( 'update', $order_id, '_verial_sync_status', 'incompleto' );
            }
        }

        /**
         * Obtiene el ID de país de Verial a partir del código ISO de país de WooCommerce.
         *
         * @param string|null $billing_country_code Código ISO del país (ej. 'ES').
         * @return int ID del país en Verial (ej. 1 para España).
         */
        private function get_verial_country_id_from_wc_code( ?string $billing_country_code ): int {
            if ( empty( $billing_country_code ) ) {
                return 1; // Default a España (ID 1 en Verial, ajustar si es diferente)
            }

            // Priorizar mapeo configurado por el administrador
            $country_mapping_options = get_option( 'mia_country_mapping_options', array() ); // Suponiendo una opción para mapeos de país
            $country_code_upper      = strtoupper( $billing_country_code );

            if ( ! empty( $country_mapping_options[ $country_code_upper ] ) ) {
                return intval( $country_mapping_options[ $country_code_upper ] );
            }

            // Fallback: intentar buscar por código ISO2 en la lista de países de Verial (si está cacheada)
            $api_connector           = new \MiIntegracionApi\Core\ApiConnector();
            $num_sesion              = $api_connector->get_numero_sesion();
            $paises_verial_cache_key = defined( 'MiIntegracionApi\\Endpoints\\MI_Endpoint_GetPaisesWS::CACHE_KEY_PREFIX' ) ? \MiIntegracionApi\Endpoints\MI_Endpoint_GetPaisesWS::CACHE_KEY_PREFIX . md5( $num_sesion ) : 'mia_paises_cached_list';
            $paises_verial           = get_transient( $paises_verial_cache_key );

            if ( $paises_verial && is_array( $paises_verial ) ) {
                foreach ( $paises_verial as $pais_verial ) {
                    if ( isset( $pais_verial['iso2'] ) && strtoupper( $pais_verial['iso2'] ) === $country_code_upper && isset( $pais_verial['id'] ) ) {
                        return intval( $pais_verial['id'] );
                    }
                }
            }

            // Si no se encuentra, loguear y devolver un default
            \MiIntegracionApi\Helpers\Logger::warning( "[MI Hooks] No se encontró mapeo de país para el código WC '{$billing_country_code}'. Usando ID por defecto 1 (España). Considera configurar el mapeo de países.", 'mia-hooks' );
            return 1; // Default si no se encuentra mapeo
        }

        /**
         * Obtiene el ID de método de pago de Verial a partir del slug de WooCommerce.
         * Lee el mapeo desde las opciones del plugin.
         *
         * @param string $wc_payment_method_slug Slug del método de pago de WooCommerce.
         * @return int ID del método de pago en Verial, o 0 si no hay mapeo.
         */
        private function get_verial_payment_method_id( string $wc_payment_method_slug ): int {
            $mapping = get_option( 'mia_payment_method_mapping', array() ); // Usar el prefijo 'mia_' consistente
            if ( isset( $mapping[ $wc_payment_method_slug ] ) && $mapping[ $wc_payment_method_slug ] !== '' ) { // Asegurar que no sea una cadena vacía
                return intval( $mapping[ $wc_payment_method_slug ] );
            }
            return 0; // Devuelve 0 si no hay mapeo o si el mapeo es explícitamente vacío
        }

        /**
         * Obtiene la tasa de impuesto de un ítem de pedido.
         *
         * @param \WC_Order_Item_Product $item Ítem del pedido.
         * @return float Tasa total de impuesto (ej: 21.00 para 21%).
         */
        public function get_item_tax_rate( \WC_Order_Item_Product $item ): float {
            $taxes          = $item->get_taxes(); // Esto devuelve un array de arrays de impuestos, ej. ['total' => [tax_rate_id => amount]]
            $total_tax_rate = 0.0;

            if ( ! empty( $taxes['total'] ) && is_array( $taxes['total'] ) ) {
                foreach ( array_keys( $taxes['total'] ) as $tax_rate_id ) {
                    // WC_Tax::get_rate_percent_value() devuelve la tasa como un string ej. "21.0000"
                    // Es mejor usar WC_Tax::get_rate_percent() que devuelve un float.
                    $rate_details = \WC_Tax::get_rate( $tax_rate_id ); // Obtener detalles de la tasa
                    if ( $rate_details && isset( $rate_details['tax_rate'] ) ) {
                        $total_tax_rate += floatval( $rate_details['tax_rate'] );
                    }
                }
            }
            return round( $total_tax_rate, 4 ); // Devolver con 4 decimales como lo hace WC internamente
        }
    } // Fin de la clase WooCommerceHooks

// Registrar los handlers de Action Scheduler fuera de la clase:
if ( function_exists( 'add_action' ) ) {
    add_action(
        'mi_integracion_api_sync_user_to_verial',
        function ( $user_id ) {
            $api_connector = function_exists( 'mi_integracion_api_get_connector' ) ? \MiIntegracionApi\Helpers\ApiHelpers::get_connector() : null;
            if ( $api_connector && class_exists( 'MiIntegracionApi\WooCommerce\WooCommerceHooks' ) ) {
                $hooks = new \MiIntegracionApi\WooCommerce\WooCommerceHooks( $api_connector );
                $hooks->enqueue_user_sync_action( $user_id );
            }
        }
    );
    add_action(
        'mi_integracion_api_sync_order_to_verial',
        function ( $order_id ) {
            $api_connector = function_exists( 'mi_integracion_api_get_connector' ) ? \MiIntegracionApi\Helpers\ApiHelpers::get_connector() : null;
            if ( $api_connector && class_exists( 'MiIntegracionApi\WooCommerce\WooCommerceHooks' ) ) {
                $hooks = new \MiIntegracionApi\WooCommerce\WooCommerceHooks( $api_connector );
                $hooks->enqueue_order_sync_action( $order_id );
            }
        }
    );
}

// No se detecta uso de Logger::log, solo error_log estándar.
