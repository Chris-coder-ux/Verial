<?php
namespace MiIntegracionApi\Core;

use MiIntegracionApi\DTOs\ProductDTO;
use MiIntegracionApi\Helpers\MapProduct;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Core\RetryManager;

class Sync_Single_Product {
    private $logger;
    private $sanitizer;
    private $retry_manager;
    private $api_connector;

    public function __construct() {
        $this->logger = new Logger('sync-single-product');
        $this->sanitizer = new DataSanitizer();
        $this->retry_manager = new RetryManager();
        // Usamos ApiConnector en lugar de API_Connector
        $this->api_connector = new ApiConnector($this->logger);
    }

    /**
     * Sincroniza un producto individual
     *
     * @param array $product_data Datos del producto
     * @return bool Resultado de la sincronización
     */
    public function sync(array $product_data): bool {
        try {
            // Sanitizar datos del producto
            $product_data = $this->sanitizer->sanitize($product_data, 'text');

            // Mapear a DTO
            $product_dto = MapProduct::verial_to_wc($product_data);
            if (!$product_dto) {
                $this->logger->error('Error al mapear producto', [
                    'product' => $product_data
                ]);
                return false;
            }

            // Sincronizar con WooCommerce
            $result = $this->api_connector->sync_product($product_dto);
            if ($result) {
                $this->logger->info('Producto sincronizado exitosamente', [
                    'product_id' => $product_dto->id
                ]);
                return true;
            }

            $this->logger->error('Error al sincronizar producto', [
                'product_id' => $product_dto->id
            ]);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error al sincronizar producto', [
                'error' => $e->getMessage(),
                'product' => $product_data
            ]);
            return false;
        }
    }

    /**
     * Verifica el estado de sincronización de un producto
     *
     * @param int $product_id ID del producto
     * @return ?string Estado de sincronización
     */
    public function check_sync_status(int $product_id): ?string {
        try {
            // Sanitizar ID del producto
            $product_id = $this->sanitizer->sanitize($product_id, 'int');

            // Verificar estado de sincronización
            $status = $this->api_connector->get_product_sync_status($product_id);
            if ($status) {
                return $this->sanitizer->sanitize($status, 'text');
            }

            return null;

        } catch (\Exception $e) {
            $this->logger->error('Error al verificar estado de sincronización', [
                'error' => $e->getMessage(),
                'product_id' => $product_id
            ]);
            return null;
        }
    }

    /**
     * Reintenta la sincronización de un producto fallido
     *
     * @param int $product_id ID del producto
     * @return bool Resultado del reintento
     */
    public function retry_sync(int $product_id): bool {
        try {
            // Sanitizar ID del producto
            $product_id = $this->sanitizer->sanitize($product_id, 'int');

            // Verificar si se puede reintentar
            if (!$this->retry_manager->can_retry('product', $product_id)) {
                $this->logger->warning('No se puede reintentar la sincronización', [
                    'product_id' => $product_id
                ]);
                return false;
            }

            // Obtener datos del producto
            $product_data = $this->api_connector->get_product($product_id);
            if (!$product_data) {
                $this->logger->error('No se pudieron obtener los datos del producto', [
                    'product_id' => $product_id
                ]);
                return false;
            }

            // Intentar sincronización
            $result = $this->sync($product_data);
            if ($result) {
                $this->retry_manager->mark_success('product', $product_id);
                return true;
            }

            $this->retry_manager->mark_failure('product', $product_id);
            return false;

        } catch (\Exception $e) {
            $this->logger->error('Error al reintentar sincronización', [
                'error' => $e->getMessage(),
                'product_id' => $product_id
            ]);
            return false;
        }
    }
} 