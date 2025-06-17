<?php
/**
 * Servicio para llamadas a la API de productos de Verial.
 * Inspirado en ProductApiService.php de woocommerce-api-connector.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\ApiConnector;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ProductApiService {
	/**
	 * @var ApiConnector Instancia del conector API
	 */
	private ApiConnector $api_connector;

	/**
	 * @param ApiConnector $api_connector Instancia del conector API
	 */
	public function __construct( ApiConnector $api_connector ) {
		$this->api_connector = $api_connector;
	}

	/**
	 * Obtener artículos desde la API de Verial.
	 *
	 * @param array<string, mixed> $params
	 * @return array<string, mixed>
	 */
	public function get_articulos( array $params = array() ): array {
		$result = $this->api_connector->get_articulos( $params );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return $result;
	}

	/**
	 * Obtener stock de un artículo desde la API de Verial.
	 *
	 * @param int $id_articulo
	 * @return array<string, mixed>
	 */
	public function get_stock_articulos( int $id_articulo ): array {
		$result = $this->api_connector->get_stock_articulos( $id_articulo );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return $result;
	}

	/**
	 * Obtener condiciones de tarifa de un artículo.
	 *
	 * @param int         $id_articulo
	 * @param int         $id_cliente
	 * @param int|null    $id_tarifa
	 * @param string|null $fecha
	 * @return array<string, mixed>
	 */
	public function get_condiciones_tarifa( int $id_articulo, int $id_cliente = 0, ?int $id_tarifa = null, ?string $fecha = null ): array {
		$result = $this->api_connector->get_condiciones_tarifa( $id_articulo, $id_cliente, $id_tarifa, $fecha );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return $result;
	}

	/**
	 * Obtener imágenes de artículos por SKU.
	 *
	 * @param array<int, string> $skus
	 * @return array<string, mixed>
	 */
	public function get_imagenes_articulos( array $skus ): array {
		$result = $this->api_connector->get_imagenes_articulos( $skus );
		if ( is_wp_error( $result ) ) {
			return array();
		}
		return $result;
	}
}
