<?php

declare(strict_types=1);

namespace MiIntegracionApi\Core\Validation;

/**
 * Validador para clientes
 */
class CustomerValidator extends SyncValidator
{
    private const REQUIRED_FIELDS = [
        'email',
        'first_name',
        'last_name'
    ];

    private const FIELD_TYPES = [
        'email' => 'string',
        'first_name' => 'string',
        'last_name' => 'string',
        'username' => 'string',
        'password' => 'string',
        'billing' => 'array',
        'shipping' => 'array',
        'meta_data' => 'array'
    ];

    private const FIELD_LIMITS = [
        'first_name' => ['min' => 2, 'max' => 50],
        'last_name' => ['min' => 2, 'max' => 50],
        'username' => ['min' => 3, 'max' => 60],
        'password' => ['min' => 8, 'max' => 100]
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
            ['meta_data']
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
        // Validar email
        if (isset($data['email'])) {
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $this->addError(
                    'email',
                    "Email inválido",
                    ['value' => $data['email']]
                );
            }
        }

        // Validar username
        if (isset($data['username'])) {
            if (!preg_match('/^[a-zA-Z0-9._-]+$/', $data['username'])) {
                $this->addError(
                    'username',
                    "Username inválido",
                    ['value' => $data['username']]
                );
            }
        }

        // Validar password
        if (isset($data['password'])) {
            if (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).+$/', $data['password'])) {
                $this->addError(
                    'password',
                    "La contraseña debe contener al menos una letra mayúscula, una minúscula y un número"
                );
            }
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
    }

    /**
     * Valida relaciones entre datos
     * 
     * @param array<string, mixed> $data Datos a validar
     * @return void
     */
    protected function validateRelationships(array $data): void
    {
        // Validar que el email de facturación coincida con el email principal
        if (isset($data['email']) && isset($data['billing']['email']) && 
            $data['email'] !== $data['billing']['email']) {
            $this->addError(
                'billing.email',
                "El email de facturación debe coincidir con el email principal",
                [
                    'main_email' => $data['email'],
                    'billing_email' => $data['billing']['email']
                ]
            );
        }

        // Validar que el nombre de facturación coincida con el nombre principal
        if (isset($data['first_name']) && isset($data['billing']['first_name']) && 
            $data['first_name'] !== $data['billing']['first_name']) {
            $this->addWarning(
                'billing.first_name',
                "El nombre de facturación no coincide con el nombre principal",
                [
                    'main_name' => $data['first_name'],
                    'billing_name' => $data['billing']['first_name']
                ]
            );
        }

        if (isset($data['last_name']) && isset($data['billing']['last_name']) && 
            $data['last_name'] !== $data['billing']['last_name']) {
            $this->addWarning(
                'billing.last_name',
                "El apellido de facturación no coincide con el apellido principal",
                [
                    'main_lastname' => $data['last_name'],
                    'billing_lastname' => $data['billing']['last_name']
                ]
            );
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
        foreach (self::FIELD_LIMITS as $field => $limits) {
            if (isset($data[$field])) {
                $this->validateRange(
                    strlen($data[$field]),
                    $limits['min'],
                    $limits['max'],
                    $field
                );
            }
        }

        // Validar longitud de campos de dirección
        $addressFields = [
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

        foreach ($addressFields as $field => $limits) {
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
    }
} 