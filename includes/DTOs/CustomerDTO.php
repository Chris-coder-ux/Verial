<?php
/**
 * DTO para clientes
 *
 * @package MiIntegracionApi
 * @subpackage DTOs
 */

namespace MiIntegracionApi\DTOs;

class CustomerDTO extends BaseDTO {
    /**
     * @var array Esquema de validación
     */
    protected static $schema = [
        'id' => [
            'type' => 'integer',
            'required' => false
        ],
        'email' => [
            'type' => 'email',
            'required' => true
        ],
        'first_name' => [
            'type' => 'string',
            'required' => true
        ],
        'last_name' => [
            'type' => 'string',
            'required' => true
        ],
        'username' => [
            'type' => 'string',
            'required' => false,
            'pattern' => '/^[a-zA-Z0-9_-]+$/'
        ],
        'password' => [
            'type' => 'string',
            'required' => false,
            'min' => 8
        ],
        'billing' => [
            'type' => 'object',
            'required' => false
        ],
        'shipping' => [
            'type' => 'object',
            'required' => false
        ],
        'phone' => [
            'type' => 'string',
            'required' => false
        ],
        'role' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['customer', 'subscriber']
        ],
        'is_paying_customer' => [
            'type' => 'boolean',
            'required' => false
        ],
        'orders_count' => [
            'type' => 'integer',
            'required' => false,
            'min' => 0
        ],
        'total_spent' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'date_created' => [
            'type' => 'string',
            'required' => false
        ],
        'date_modified' => [
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
     * Obtiene el ID del cliente
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->get('id');
    }

    /**
     * Obtiene el email del cliente
     *
     * @return string
     */
    public function getEmail(): string {
        return $this->get('email');
    }

    /**
     * Obtiene el nombre del cliente
     *
     * @return string
     */
    public function getFirstName(): string {
        return $this->get('first_name');
    }

    /**
     * Obtiene el apellido del cliente
     *
     * @return string
     */
    public function getLastName(): string {
        return $this->get('last_name');
    }

    /**
     * Obtiene el nombre de usuario del cliente
     *
     * @return string|null
     */
    public function getUsername(): ?string {
        return $this->get('username');
    }

    /**
     * Obtiene la contraseña del cliente
     *
     * @return string|null
     */
    public function getPassword(): ?string {
        return $this->get('password');
    }

    /**
     * Obtiene los datos de facturación del cliente
     *
     * @return object|null
     */
    public function getBilling(): ?object {
        return $this->get('billing');
    }

    /**
     * Obtiene los datos de envío del cliente
     *
     * @return object|null
     */
    public function getShipping(): ?object {
        return $this->get('shipping');
    }

    /**
     * Obtiene el teléfono del cliente
     *
     * @return string|null
     */
    public function getPhone(): ?string {
        return $this->get('phone');
    }

    /**
     * Obtiene el rol del cliente
     *
     * @return string|null
     */
    public function getRole(): ?string {
        return $this->get('role');
    }

    /**
     * Obtiene si el cliente es pagador
     *
     * @return bool|null
     */
    public function getIsPayingCustomer(): ?bool {
        return $this->get('is_paying_customer');
    }

    /**
     * Obtiene el número de pedidos del cliente
     *
     * @return int|null
     */
    public function getOrdersCount(): ?int {
        return $this->get('orders_count');
    }

    /**
     * Obtiene el total gastado por el cliente
     *
     * @return float|null
     */
    public function getTotalSpent(): ?float {
        return $this->get('total_spent');
    }

    /**
     * Obtiene la fecha de creación del cliente
     *
     * @return string|null
     */
    public function getDateCreated(): ?string {
        return $this->get('date_created');
    }

    /**
     * Obtiene la fecha de modificación del cliente
     *
     * @return string|null
     */
    public function getDateModified(): ?string {
        return $this->get('date_modified');
    }

    /**
     * Obtiene el ID externo del cliente
     *
     * @return string|null
     */
    public function getExternalId(): ?string {
        return $this->get('external_id');
    }

    /**
     * Obtiene el estado de sincronización del cliente
     *
     * @return string|null
     */
    public function getSyncStatus(): ?string {
        return $this->get('sync_status');
    }

    /**
     * Obtiene la última sincronización del cliente
     *
     * @return string|null
     */
    public function getLastSync(): ?string {
        return $this->get('last_sync');
    }
} 