<?php
/**
 * DTO para pedidos
 *
 * @package MiIntegracionApi
 * @subpackage DTOs
 */

namespace MiIntegracionApi\DTOs;

class OrderDTO extends BaseDTO {
    /**
     * @var array Esquema de validación
     */
    protected static $schema = [
        'id' => [
            'type' => 'integer',
            'required' => false
        ],
        'customer_id' => [
            'type' => 'integer',
            'required' => true
        ],
        'status' => [
            'type' => 'string',
            'required' => true,
            'enum' => ['pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed']
        ],
        'currency' => [
            'type' => 'string',
            'required' => true,
            'pattern' => '/^[A-Z]{3}$/'
        ],
        'total' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'subtotal' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'tax_total' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'shipping_total' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'discount_total' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'payment_method' => [
            'type' => 'string',
            'required' => true
        ],
        'payment_method_title' => [
            'type' => 'string',
            'required' => true
        ],
        'billing' => [
            'type' => 'object',
            'required' => true
        ],
        'shipping' => [
            'type' => 'object',
            'required' => true
        ],
        'line_items' => [
            'type' => 'array',
            'required' => true
        ],
        'shipping_lines' => [
            'type' => 'array',
            'required' => false
        ],
        'fee_lines' => [
            'type' => 'array',
            'required' => false
        ],
        'coupon_lines' => [
            'type' => 'array',
            'required' => false
        ],
        'date_created' => [
            'type' => 'string',
            'required' => true
        ],
        'date_modified' => [
            'type' => 'string',
            'required' => true
        ],
        'date_completed' => [
            'type' => 'string',
            'required' => false
        ],
        'date_paid' => [
            'type' => 'string',
            'required' => false
        ],
        'customer_note' => [
            'type' => 'string',
            'required' => false
        ],
        'external_id' => [
            'type' => 'string',
            'required' => false
        ],
        'sync_status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['pending', 'synced', 'failed']
        ],
        'last_sync' => [
            'type' => 'string',
            'required' => false
        ]
    ];

    /**
     * Obtiene el ID del pedido
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->get('id');
    }

    /**
     * Obtiene el ID del cliente
     *
     * @return int
     */
    public function getCustomerId(): int {
        return $this->get('customer_id');
    }

    /**
     * Obtiene el estado del pedido
     *
     * @return string
     */
    public function getStatus(): string {
        return $this->get('status');
    }

    /**
     * Obtiene la moneda del pedido
     *
     * @return string
     */
    public function getCurrency(): string {
        return $this->get('currency');
    }

    /**
     * Obtiene el total del pedido
     *
     * @return float
     */
    public function getTotal(): float {
        return $this->get('total');
    }

    /**
     * Obtiene el subtotal del pedido
     *
     * @return float
     */
    public function getSubtotal(): float {
        return $this->get('subtotal');
    }

    /**
     * Obtiene el total de impuestos del pedido
     *
     * @return float
     */
    public function getTaxTotal(): float {
        return $this->get('tax_total');
    }

    /**
     * Obtiene el total de envío del pedido
     *
     * @return float
     */
    public function getShippingTotal(): float {
        return $this->get('shipping_total');
    }

    /**
     * Obtiene el total de descuentos del pedido
     *
     * @return float
     */
    public function getDiscountTotal(): float {
        return $this->get('discount_total');
    }

    /**
     * Obtiene el método de pago del pedido
     *
     * @return string
     */
    public function getPaymentMethod(): string {
        return $this->get('payment_method');
    }

    /**
     * Obtiene el título del método de pago del pedido
     *
     * @return string
     */
    public function getPaymentMethodTitle(): string {
        return $this->get('payment_method_title');
    }

    /**
     * Obtiene los datos de facturación del pedido
     *
     * @return object
     */
    public function getBilling(): object {
        return $this->get('billing');
    }

    /**
     * Obtiene los datos de envío del pedido
     *
     * @return object
     */
    public function getShipping(): object {
        return $this->get('shipping');
    }

    /**
     * Obtiene los items del pedido
     *
     * @return array
     */
    public function getLineItems(): array {
        return $this->get('line_items');
    }

    /**
     * Obtiene las líneas de envío del pedido
     *
     * @return array|null
     */
    public function getShippingLines(): ?array {
        return $this->get('shipping_lines');
    }

    /**
     * Obtiene las líneas de cargo del pedido
     *
     * @return array|null
     */
    public function getFeeLines(): ?array {
        return $this->get('fee_lines');
    }

    /**
     * Obtiene las líneas de cupón del pedido
     *
     * @return array|null
     */
    public function getCouponLines(): ?array {
        return $this->get('coupon_lines');
    }

    /**
     * Obtiene la fecha de creación del pedido
     *
     * @return string
     */
    public function getDateCreated(): string {
        return $this->get('date_created');
    }

    /**
     * Obtiene la fecha de modificación del pedido
     *
     * @return string
     */
    public function getDateModified(): string {
        return $this->get('date_modified');
    }

    /**
     * Obtiene la fecha de completado del pedido
     *
     * @return string|null
     */
    public function getDateCompleted(): ?string {
        return $this->get('date_completed');
    }

    /**
     * Obtiene la fecha de pago del pedido
     *
     * @return string|null
     */
    public function getDatePaid(): ?string {
        return $this->get('date_paid');
    }

    /**
     * Obtiene la nota del cliente del pedido
     *
     * @return string|null
     */
    public function getCustomerNote(): ?string {
        return $this->get('customer_note');
    }

    /**
     * Obtiene el ID externo del pedido
     *
     * @return string|null
     */
    public function getExternalId(): ?string {
        return $this->get('external_id');
    }

    /**
     * Obtiene el estado de sincronización del pedido
     *
     * @return string|null
     */
    public function getSyncStatus(): ?string {
        return $this->get('sync_status');
    }

    /**
     * Obtiene la última sincronización del pedido
     *
     * @return string|null
     */
    public function getLastSync(): ?string {
        return $this->get('last_sync');
    }
} 