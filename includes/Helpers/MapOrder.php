<?php
/**
 * Helper para mapear datos de pedidos entre WooCommerce y Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\DTOs\OrderDTO;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase para mapear datos de pedidos entre WooCommerce y Verial
 * 
 * @since 1.0.0
 */
class MapOrder {
    private static $logger;
    private static $sanitizer;

    public static function init() {
        if (!self::$logger) {
            self::$logger = new Logger();
        }
        if (!self::$sanitizer) {
            self::$sanitizer = new DataSanitizer();
        }
    }

    /**
     * Mapea un pedido de Verial a un DTO de WooCommerce.
     *
     * @param array $verial_order Datos del pedido de Verial
     * @param array $extra_fields Campos extra opcionales
     * @return OrderDTO|null DTO del pedido mapeado o null si los datos son inválidos
     */
    public static function verial_to_wc(array $verial_order, array $extra_fields = []): ?OrderDTO {
        self::init();

        try {
            // Validar campos requeridos
            if (empty($verial_order['ID']) || empty($verial_order['Cliente'])) {
                self::$logger->error('Pedido Verial inválido: faltan campos requeridos', [
                    'order' => $verial_order
                ]);
                return null;
            }

            // Sanitizar datos
            $order_data = [
                'id' => self::$sanitizer->sanitize($verial_order['ID'], 'int'),
                'customer_id' => self::$sanitizer->sanitize($verial_order['Cliente']['ID'], 'int'),
                'status' => self::$sanitizer->sanitize(self::map_verial_status_to_wc($verial_order['Estado'] ?? ''), 'text'),
                'currency' => self::$sanitizer->sanitize($verial_order['Moneda'] ?? 'EUR', 'text'),
                'total' => self::$sanitizer->sanitize((float)($verial_order['Total'] ?? 0), 'price'),
                'subtotal' => self::$sanitizer->sanitize((float)($verial_order['Subtotal'] ?? 0), 'price'),
                'tax_total' => self::$sanitizer->sanitize((float)($verial_order['TotalIVA'] ?? 0), 'price'),
                'shipping_total' => self::$sanitizer->sanitize((float)($verial_order['GastosEnvio'] ?? 0), 'price'),
                'discount_total' => self::$sanitizer->sanitize((float)($verial_order['Descuento'] ?? 0), 'price'),
                'payment_method' => self::$sanitizer->sanitize($verial_order['FormaPago'] ?? '', 'text'),
                'payment_method_title' => self::$sanitizer->sanitize($verial_order['FormaPagoDescripcion'] ?? '', 'text'),
                'billing' => [
                    'first_name' => self::$sanitizer->sanitize($verial_order['Cliente']['Nombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_order['Cliente']['Apellidos'] ?? '', 'text'),
                    'email' => self::$sanitizer->sanitize($verial_order['Cliente']['Email'] ?? '', 'email'),
                    'phone' => self::$sanitizer->sanitize($verial_order['Cliente']['Telefono'] ?? '', 'phone'),
                    'address_1' => self::$sanitizer->sanitize($verial_order['Cliente']['Direccion'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_order['Cliente']['Ciudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_order['Cliente']['Provincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_order['Cliente']['CodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_order['Cliente']['Pais'] ?? '', 'text')
                ],
                'shipping' => [
                    'first_name' => self::$sanitizer->sanitize($verial_order['Envio']['Nombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_order['Envio']['Apellidos'] ?? '', 'text'),
                    'address_1' => self::$sanitizer->sanitize($verial_order['Envio']['Direccion'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_order['Envio']['Ciudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_order['Envio']['Provincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_order['Envio']['CodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_order['Envio']['Pais'] ?? '', 'text')
                ],
                'line_items' => self::sanitizeLineItems($verial_order['Lineas'] ?? []),
                'shipping_lines' => self::sanitizeShippingLines($verial_order['GastosEnvio'] ?? 0),
                'fee_lines' => self::sanitizeFeeLines($verial_order['GastosAdicionales'] ?? []),
                'coupon_lines' => self::sanitizeCouponLines($verial_order['Cupones'] ?? []),
                'date_created' => self::$sanitizer->sanitize($verial_order['Fecha'] ?? current_time('mysql'), 'datetime'),
                'date_modified' => self::$sanitizer->sanitize($verial_order['FechaModificacion'] ?? current_time('mysql'), 'datetime'),
                'date_completed' => self::$sanitizer->sanitize($verial_order['FechaCompletado'] ?? null, 'datetime'),
                'date_paid' => self::$sanitizer->sanitize($verial_order['FechaPago'] ?? null, 'datetime'),
                'customer_note' => self::$sanitizer->sanitize($verial_order['Nota'] ?? '', 'text'),
                'external_id' => self::$sanitizer->sanitize((string)($verial_order['ID'] ?? ''), 'text'),
                'sync_status' => self::$sanitizer->sanitize('synced', 'text'),
                'last_sync' => self::$sanitizer->sanitize(current_time('mysql'), 'datetime'),
                'meta_data' => self::$sanitizer->sanitize($verial_order['MetaDatos'] ?? [], 'text')
            ];

            // Validar datos críticos
            if (!self::$sanitizer->validate($order_data['id'], 'int')) {
                self::$logger->error('ID de pedido inválido', [
                    'id' => $order_data['id']
                ]);
                return null;
            }

            if (!self::$sanitizer->validate($order_data['billing']['email'], 'email')) {
                self::$logger->error('Email de facturación inválido', [
                    'email' => $order_data['billing']['email']
                ]);
                return null;
            }

            // Añadir campos extra si existen
            if (!empty($extra_fields)) {
                $order_data = array_merge($order_data, $extra_fields);
            }

            return new OrderDTO($order_data);
        } catch (\Exception $e) {
            self::$logger->error('Error al mapear pedido Verial a WooCommerce', [
                'error' => $e->getMessage(),
                'order' => $verial_order
            ]);
            return null;
        }
    }

    /**
     * Mapea un pedido de WooCommerce al formato de Verial.
     *
     * @param \WC_Order $wc_order Pedido de WooCommerce
     * @return array Datos del pedido en formato Verial
     */
    public static function wc_to_verial(\WC_Order $wc_order): array {
        self::init();

        try {
            if (!$wc_order instanceof \WC_Order) {
                self::$logger->error('Pedido WooCommerce inválido');
                return [];
            }

            // Sanitizar datos
            $verial_order = [
                'ID' => self::$sanitizer->sanitize($wc_order->get_id(), 'int'),
                'Cliente' => [
                    'ID' => self::$sanitizer->sanitize($wc_order->get_customer_id(), 'int'),
                    'Nombre' => self::$sanitizer->sanitize($wc_order->get_billing_first_name(), 'text'),
                    'Apellidos' => self::$sanitizer->sanitize($wc_order->get_billing_last_name(), 'text'),
                    'Email' => self::$sanitizer->sanitize($wc_order->get_billing_email(), 'email'),
                    'Telefono' => self::$sanitizer->sanitize($wc_order->get_billing_phone(), 'phone'),
                    'Direccion' => self::$sanitizer->sanitize($wc_order->get_billing_address_1(), 'text'),
                    'Direccion2' => self::$sanitizer->sanitize($wc_order->get_billing_address_2(), 'text'),
                    'Ciudad' => self::$sanitizer->sanitize($wc_order->get_billing_city(), 'text'),
                    'Provincia' => self::$sanitizer->sanitize($wc_order->get_billing_state(), 'text'),
                    'CodigoPostal' => self::$sanitizer->sanitize($wc_order->get_billing_postcode(), 'postcode'),
                    'Pais' => self::$sanitizer->sanitize($wc_order->get_billing_country(), 'text')
                ],
                'Envio' => [
                    'Nombre' => self::$sanitizer->sanitize($wc_order->get_shipping_first_name(), 'text'),
                    'Apellidos' => self::$sanitizer->sanitize($wc_order->get_shipping_last_name(), 'text'),
                    'Direccion' => self::$sanitizer->sanitize($wc_order->get_shipping_address_1(), 'text'),
                    'Direccion2' => self::$sanitizer->sanitize($wc_order->get_shipping_address_2(), 'text'),
                    'Ciudad' => self::$sanitizer->sanitize($wc_order->get_shipping_city(), 'text'),
                    'Provincia' => self::$sanitizer->sanitize($wc_order->get_shipping_state(), 'text'),
                    'CodigoPostal' => self::$sanitizer->sanitize($wc_order->get_shipping_postcode(), 'postcode'),
                    'Pais' => self::$sanitizer->sanitize($wc_order->get_shipping_country(), 'text')
                ],
                'Estado' => self::$sanitizer->sanitize(self::map_wc_status_to_verial($wc_order->get_status()), 'text'),
                'Moneda' => self::$sanitizer->sanitize($wc_order->get_currency(), 'text'),
                'Lineas' => self::sanitizeWcLineItems($wc_order->get_items()),
                'GastosEnvio' => self::$sanitizer->sanitize($wc_order->get_shipping_total(), 'price'),
                'GastosAdicionales' => self::$sanitizer->sanitize($wc_order->get_fees(), 'text'),
                'Cupones' => self::$sanitizer->sanitize($wc_order->get_coupons(), 'text'),
                'MetaDatos' => self::$sanitizer->sanitize($wc_order->get_meta_data(), 'text')
            ];

            // Validar datos críticos
            if (!self::$sanitizer->validate($verial_order['ID'], 'int')) {
                self::$logger->error('ID de pedido inválido', [
                    'id' => $verial_order['ID']
                ]);
                return [];
            }

            if (!self::$sanitizer->validate($verial_order['Cliente']['Email'], 'email')) {
                self::$logger->error('Email de facturación inválido', [
                    'email' => $verial_order['Cliente']['Email']
                ]);
                return [];
            }

            return $verial_order;
        } catch (\Exception $e) {
            self::$logger->error('Error al mapear pedido WooCommerce a Verial', [
                'error' => $e->getMessage(),
                'order_id' => $wc_order->get_id()
            ]);
            return [];
        }
    }

    /**
     * Mapea el estado de Verial a WooCommerce
     */
    private static function map_verial_status_to_wc(string $verial_status): string {
        $status_map = [
            'Pendiente' => 'pending',
            'Procesando' => 'processing',
            'Completado' => 'completed',
            'Cancelado' => 'cancelled',
            'Reembolsado' => 'refunded',
            'Fallido' => 'failed'
        ];

        return $status_map[$verial_status] ?? 'pending';
    }

    /**
     * Mapea el estado de WooCommerce a Verial
     */
    private static function map_wc_status_to_verial(string $wc_status): string {
        $status_map = [
            'pending' => 'Pendiente',
            'processing' => 'Procesando',
            'completed' => 'Completado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Reembolsado',
            'failed' => 'Fallido'
        ];

        return $status_map[$wc_status] ?? 'Pendiente';
    }

    /**
     * Mapea las líneas de pedido de Verial a WooCommerce
     */
    private static function sanitizeLineItems(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'product_id' => self::$sanitizer->sanitize($item['ID_Producto'] ?? 0, 'int'),
                'name' => self::$sanitizer->sanitize($item['Nombre'] ?? '', 'text'),
                'quantity' => self::$sanitizer->sanitize($item['Cantidad'] ?? 0, 'int'),
                'subtotal' => self::$sanitizer->sanitize($item['Subtotal'] ?? 0, 'price'),
                'total' => self::$sanitizer->sanitize($item['Total'] ?? 0, 'price'),
                'tax' => self::$sanitizer->sanitize($item['IVA'] ?? 0, 'price'),
                'sku' => self::$sanitizer->sanitize($item['SKU'] ?? '', 'sku'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Mapea las líneas de envío de Verial a WooCommerce
     */
    private static function sanitizeShippingLines(float $shipping_total): array {
        if ($shipping_total <= 0) {
            return [];
        }

        return [[
            'method_id' => 'flat_rate',
            'method_title' => 'Envío estándar',
            'total' => self::$sanitizer->sanitize($shipping_total, 'price'),
            'meta_data' => []
        ]];
    }

    /**
     * Mapea las líneas de gastos adicionales de Verial a WooCommerce
     */
    private static function sanitizeFeeLines(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'name' => self::$sanitizer->sanitize($item['Concepto'] ?? 'Gasto adicional', 'text'),
                'total' => self::$sanitizer->sanitize($item['Importe'] ?? 0, 'price'),
                'tax' => self::$sanitizer->sanitize($item['IVA'] ?? 0, 'price'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Mapea las líneas de cupones de Verial a WooCommerce
     */
    private static function sanitizeCouponLines(array $items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $sanitized[] = [
                'code' => self::$sanitizer->sanitize($item['Codigo'] ?? '', 'text'),
                'discount' => self::$sanitizer->sanitize($item['Descuento'] ?? 0, 'price'),
                'meta_data' => self::$sanitizer->sanitize($item['MetaDatos'] ?? [], 'text')
            ];
        }
        return $sanitized;
    }

    /**
     * Mapea las líneas de pedido de WooCommerce a Verial
     */
    private static function sanitizeWcLineItems($items): array {
        $sanitized = [];
        foreach ($items as $item) {
            $product = $item->get_product();
            $sanitized[] = [
                'ID_Producto' => self::$sanitizer->sanitize($product ? $product->get_id() : 0, 'int'),
                'Nombre' => self::$sanitizer->sanitize($item->get_name(), 'text'),
                'Cantidad' => self::$sanitizer->sanitize($item->get_quantity(), 'int'),
                'Subtotal' => self::$sanitizer->sanitize($item->get_subtotal(), 'price'),
                'Total' => self::$sanitizer->sanitize($item->get_total(), 'price'),
                'IVA' => self::$sanitizer->sanitize($item->get_total_tax(), 'price'),
                'SKU' => self::$sanitizer->sanitize($product ? $product->get_sku() : '', 'sku'),
                'MetaDatos' => self::$sanitizer->sanitize($item->get_meta_data(), 'text')
            ];
        }
        return $sanitized;
    }
}
