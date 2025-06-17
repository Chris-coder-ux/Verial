<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Validador para pedidos
 */
class OrderValidator extends SyncValidator
{
    private const REQUIRED_FIELDS = [
        'customer_id',
        'status',
        'billing',
        'shipping',
        'line_items'
    ];

    private const FIELD_TYPES = [
        'customer_id' => 'int',
        'status' => 'string',
        'billing' => 'array',
        'shipping' => 'array',
        'line_items' => 'array',
        'payment_method' => 'string',
        'payment_method_title' => 'string',
        'shipping_method' => 'string',
        'shipping_total' => 'float',
        'total' => 'float',
        'meta_data' => 'array'
    ];

    private const STATUS_VALUES = [
        'pending',
        'processing',
        'on-hold',
        'completed',
        'cancelled',
        'refunded',
        'failed'
    ];

    private const REQUIRED_BILLING_FIELDS = [
        'first_name',
        'last_name',
        'address_1',
        'city',
        'state',
        'postcode',
        'country',
        'email',
        'phone'
    ];

    private const REQUIRED_SHIPPING_FIELDS = [
        'first_name',
        'last_name',
        'address_1',
        'city',
        'state',
        'postcode',
        'country'
    ];

    /**
     * Valida la estructura básica de los datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateStructure(array $data): void
    {
        if (!is_array($data)) {
            $this->addError('structure', 'Los datos deben ser un array');
            return;
        }

        // Validar que no haya campos desconocidos
        $allowedFields = array_merge(
            array_keys(self::FIELD_TYPES),
            ['meta_data', 'tax_data', 'coupon_lines', 'fee_lines']
        );

        foreach ($data as $field => $value) {
            if (!in_array($field, $allowedFields)) {
                $this->addWarning(
                    $field,
                    "Campo desconocido",
                    ['value' => $value]
                );
            }
        }
    }

    /**
     * Valida los campos requeridos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRequiredFields(array $data): void
    {
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                $this->addError(
                    $field,
                    "El campo es requerido"
                );
            }
        }

        // Validar campos requeridos de facturación
        if (isset($data['billing']) && is_array($data['billing'])) {
            foreach (self::REQUIRED_BILLING_FIELDS as $field) {
                if (!isset($data['billing'][$field]) || $data['billing'][$field] === '') {
                    $this->addError(
                        "billing.{$field}",
                        "El campo de facturación es requerido"
                    );
                }
            }
        }

        // Validar campos requeridos de envío
        if (isset($data['shipping']) && is_array($data['shipping'])) {
            foreach (self::REQUIRED_SHIPPING_FIELDS as $field) {
                if (!isset($data['shipping'][$field]) || $data['shipping'][$field] === '') {
                    $this->addError(
                        "shipping.{$field}",
                        "El campo de envío es requerido"
                    );
                }
            }
        }
    }

    /**
     * Valida los tipos de datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateDataTypes(array $data): void
    {
        foreach (self::FIELD_TYPES as $field => $type) {
            if (isset($data[$field])) {
                $this->validateType($data[$field], $type, $field);
            }
        }
    }

    /**
     * Valida reglas específicas
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateSpecificRules(array $data): void
    {
        // Validar estado
        if (isset($data['status']) && !in_array($data['status'], self::STATUS_VALUES)) {
            $this->addError(
                'status',
                "Estado no válido",
                ['value' => $data['status'], 'allowed' => self::STATUS_VALUES]
            );
        }

        // Validar email de facturación
        if (isset($data['billing']['email'])) {
            if (!filter_var($data['billing']['email'], FILTER_VALIDATE_EMAIL)) {
                $this->addError(
                    'billing.email',
                    "Email de facturación inválido",
                    ['value' => $data['billing']['email']]
                );
            }
        }

        // Validar teléfono de facturación
        if (isset($data['billing']['phone'])) {
            if (!preg_match('/^[0-9+\-\s()]{6,20}$/', $data['billing']['phone'])) {
                $this->addError(
                    'billing.phone',
                    "Teléfono de facturación inválido",
                    ['value' => $data['billing']['phone']]
                );
            }
        }

        // Validar items del pedido
        if (isset($data['line_items']) && is_array($data['line_items'])) {
            foreach ($data['line_items'] as $index => $item) {
                if (!is_array($item)) {
                    $this->addError(
                        "line_items.{$index}",
                        "Item inválido",
                        ['value' => $item]
                    );
                    continue;
                }

                if (!isset($item['product_id']) || !isset($item['quantity'])) {
                    $this->addError(
                        "line_items.{$index}",
                        "Item incompleto",
                        ['value' => $item]
                    );
                }

                if (isset($item['quantity']) && $item['quantity'] <= 0) {
                    $this->addError(
                        "line_items.{$index}.quantity",
                        "La cantidad debe ser mayor que 0",
                        ['value' => $item['quantity']]
                    );
                }
            }
        }
    }

    /**
     * Valida relaciones entre datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRelationships(array $data): void
    {
        // Validar que haya al menos un item en el pedido
        if (isset($data['line_items']) && empty($data['line_items'])) {
            $this->addError(
                'line_items',
                "El pedido debe tener al menos un item"
            );
        }

        // Validar que el total coincida con la suma de los items
        if (isset($data['total']) && isset($data['line_items'])) {
            $calculatedTotal = 0;
            foreach ($data['line_items'] as $item) {
                if (isset($item['total'])) {
                    $calculatedTotal += (float)$item['total'];
                }
            }

            if (isset($data['shipping_total'])) {
                $calculatedTotal += (float)$data['shipping_total'];
            }

            if (abs($calculatedTotal - (float)$data['total']) > 0.01) {
                $this->addError(
                    'total',
                    "El total no coincide con la suma de los items",
                    [
                        'calculated' => $calculatedTotal,
                        'provided' => $data['total']
                    ]
                );
            }
        }
    }

    /**
     * Valida límites y restricciones
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateLimits(array $data): void
    {
        // Validar longitud de campos de texto
        $textFields = [
            'billing.first_name' => ['min' => 2, 'max' => 50],
            'billing.last_name' => ['min' => 2, 'max' => 50],
            'billing.address_1' => ['min' => 5, 'max' => 100],
            'billing.city' => ['min' => 2, 'max' => 50],
            'billing.state' => ['min' => 2, 'max' => 50],
            'billing.postcode' => ['min' => 3, 'max' => 20],
            'billing.country' => ['min' => 2, 'max' => 2],
            'shipping.first_name' => ['min' => 2, 'max' => 50],
            'shipping.last_name' => ['min' => 2, 'max' => 50],
            'shipping.address_1' => ['min' => 5, 'max' => 100],
            'shipping.city' => ['min' => 2, 'max' => 50],
            'shipping.state' => ['min' => 2, 'max' => 50],
            'shipping.postcode' => ['min' => 3, 'max' => 20],
            'shipping.country' => ['min' => 2, 'max' => 2]
        ];

        foreach ($textFields as $field => $limits) {
            $parts = explode('.', $field);
            if (isset($data[$parts[0]][$parts[1]])) {
                $this->validateRange(
                    strlen($data[$parts[0]][$parts[1]]),
                    $limits['min'],
                    $limits['max'],
                    $field
                );
            }
        }

        // Validar rangos numéricos
        if (isset($data['total'])) {
            $this->validateRange($data['total'], 0, 999999.99, 'total');
        }

        if (isset($data['shipping_total'])) {
            $this->validateRange($data['shipping_total'], 0, 999999.99, 'shipping_total');
        }
    }
} 