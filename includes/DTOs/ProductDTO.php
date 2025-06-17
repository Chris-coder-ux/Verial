<?php
/**
 * DTO para productos
 *
 * @package MiIntegracionApi
 * @subpackage DTOs
 */

namespace MiIntegracionApi\DTOs;

class ProductDTO extends BaseDTO {
    /**
     * @var array Esquema de validación
     */
    protected static $schema = [
        'id' => [
            'type' => 'integer',
            'required' => false
        ],
        'name' => [
            'type' => 'string',
            'required' => true,
            'min' => 1
        ],
        'sku' => [
            'type' => 'string',
            'required' => true,
            'pattern' => '/^[A-Za-z0-9-_]+$/'
        ],
        'price' => [
            'type' => 'float',
            'required' => true,
            'min' => 0
        ],
        'regular_price' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'sale_price' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'description' => [
            'type' => 'string',
            'required' => false
        ],
        'short_description' => [
            'type' => 'string',
            'required' => false
        ],
        'categories' => [
            'type' => 'array',
            'required' => false
        ],
        'tags' => [
            'type' => 'array',
            'required' => false
        ],
        'images' => [
            'type' => 'array',
            'required' => false
        ],
        'stock_quantity' => [
            'type' => 'integer',
            'required' => false,
            'min' => 0
        ],
        'stock_status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['instock', 'outofstock', 'onbackorder']
        ],
        'weight' => [
            'type' => 'float',
            'required' => false,
            'min' => 0
        ],
        'dimensions' => [
            'type' => 'object',
            'required' => false
        ],
        'attributes' => [
            'type' => 'array',
            'required' => false
        ],
        'status' => [
            'type' => 'string',
            'required' => false,
            'enum' => ['draft', 'pending', 'private', 'publish']
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
     * Obtiene el ID del producto
     *
     * @return int|null
     */
    public function getId(): ?int {
        return $this->get('id');
    }

    /**
     * Obtiene el nombre del producto
     *
     * @return string
     */
    public function getName(): string {
        return $this->get('name');
    }

    /**
     * Obtiene el SKU del producto
     *
     * @return string
     */
    public function getSku(): string {
        return $this->get('sku');
    }

    /**
     * Obtiene el precio del producto
     *
     * @return float
     */
    public function getPrice(): float {
        return $this->get('price');
    }

    /**
     * Obtiene el precio regular del producto
     *
     * @return float|null
     */
    public function getRegularPrice(): ?float {
        return $this->get('regular_price');
    }

    /**
     * Obtiene el precio de oferta del producto
     *
     * @return float|null
     */
    public function getSalePrice(): ?float {
        return $this->get('sale_price');
    }

    /**
     * Obtiene la descripción del producto
     *
     * @return string|null
     */
    public function getDescription(): ?string {
        return $this->get('description');
    }

    /**
     * Obtiene la descripción corta del producto
     *
     * @return string|null
     */
    public function getShortDescription(): ?string {
        return $this->get('short_description');
    }

    /**
     * Obtiene las categorías del producto
     *
     * @return array|null
     */
    public function getCategories(): ?array {
        return $this->get('categories');
    }

    /**
     * Obtiene las etiquetas del producto
     *
     * @return array|null
     */
    public function getTags(): ?array {
        return $this->get('tags');
    }

    /**
     * Obtiene las imágenes del producto
     *
     * @return array|null
     */
    public function getImages(): ?array {
        return $this->get('images');
    }

    /**
     * Obtiene la cantidad en stock del producto
     *
     * @return int|null
     */
    public function getStockQuantity(): ?int {
        return $this->get('stock_quantity');
    }

    /**
     * Obtiene el estado del stock del producto
     *
     * @return string|null
     */
    public function getStockStatus(): ?string {
        return $this->get('stock_status');
    }

    /**
     * Obtiene el peso del producto
     *
     * @return float|null
     */
    public function getWeight(): ?float {
        return $this->get('weight');
    }

    /**
     * Obtiene las dimensiones del producto
     *
     * @return object|null
     */
    public function getDimensions(): ?object {
        return $this->get('dimensions');
    }

    /**
     * Obtiene los atributos del producto
     *
     * @return array|null
     */
    public function getAttributes(): ?array {
        return $this->get('attributes');
    }

    /**
     * Obtiene el estado del producto
     *
     * @return string|null
     */
    public function getStatus(): ?string {
        return $this->get('status');
    }

    /**
     * Obtiene el ID externo del producto
     *
     * @return string|null
     */
    public function getExternalId(): ?string {
        return $this->get('external_id');
    }

    /**
     * Obtiene el estado de sincronización del producto
     *
     * @return string|null
     */
    public function getSyncStatus(): ?string {
        return $this->get('sync_status');
    }

    /**
     * Obtiene la última sincronización del producto
     *
     * @return string|null
     */
    public function getLastSync(): ?string {
        return $this->get('last_sync');
    }
} 