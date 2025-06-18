<?php
/**
 * Clase para mapear los productos de Verial a WooCommerce
 * 
 * Esta clase se encarga de convertir la estructura de datos de la API de Verial
 * a una estructura compatible con WooCommerce y viceversa.
 * 
 * @package MiIntegracionApi\WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

use MiIntegracionApi\Helpers\Logger;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

class VerialProductMapper {
    
    /**
     * Instancia del logger
     *
     * @var \MiIntegracionApi\Helpers\Logger
     */
    private static $logger;
    
    /**
     * Inicializa la instancia de logger si no existe
     */
    private static function get_logger() {
        if (!self::$logger) {
            self::$logger = new Logger('VerialProductMapper');
        }
        return self::$logger;
    }
    
    /**
     * Convierte un producto de la estructura JSON de Verial a la estructura de WooCommerce
     *
     * @param array $verial_product Datos del producto de Verial
     * @return array Datos del producto formateados para WooCommerce
     */
    public static function to_woocommerce($verial_product) {
        // Registrar información detallada para debugging
        self::get_logger()->debug('Mapeando producto Verial a WooCommerce', [
            'source' => 'VerialProductMapper', 
            'verial_product' => $verial_product
        ]);
        
        // Verificar que tenemos datos válidos
        if (!is_array($verial_product) || empty($verial_product)) {
            self::get_logger()->error('Datos de producto Verial inválidos', [
                'source' => 'VerialProductMapper', 
                'verial_product' => $verial_product
            ]);
            return [];
        }
        
        // Extraer SKU del campo ReferenciaBarras
        $sku = isset($verial_product['ReferenciaBarras']) ? $verial_product['ReferenciaBarras'] : '';
        
        // Mapear datos básicos del producto
        $wc_product = [
            'name' => isset($verial_product['Nombre']) ? $verial_product['Nombre'] : '',
            'description' => isset($verial_product['Descripcion']) ? $verial_product['Descripcion'] : '',
            'sku' => $sku,
            'regular_price' => isset($verial_product['Precio']) ? (string)$verial_product['Precio'] : '',
            'manage_stock' => true,
            'stock_quantity' => isset($verial_product['Stock']) ? (int)$verial_product['Stock'] : 0,
            'weight' => isset($verial_product['Peso']) ? (string)$verial_product['Peso'] : '',
            'dimensions' => [
                'length' => '',
                'width' => isset($verial_product['Ancho']) ? (string)$verial_product['Ancho'] : '',
                'height' => isset($verial_product['Alto']) ? (string)$verial_product['Alto'] : ''
            ],
            'meta_data' => [
                [
                    'key' => '_verial_id',
                    'value' => isset($verial_product['Id']) ? $verial_product['Id'] : ''
                ],
                [
                    'key' => '_verial_categoria_id',
                    'value' => isset($verial_product['ID_Categoria']) ? $verial_product['ID_Categoria'] : ''
                ],
                [
                    'key' => '_verial_fabricante_id',
                    'value' => isset($verial_product['ID_Fabricante']) ? $verial_product['ID_Fabricante'] : ''
                ],
                [
                    'key' => '_verial_iva',
                    'value' => isset($verial_product['PorcentajeIVA']) ? $verial_product['PorcentajeIVA'] : ''
                ],
                [
                    'key' => '_verial_tipo',
                    'value' => isset($verial_product['Tipo']) ? $verial_product['Tipo'] : ''
                ],
                [
                    'key' => '_verial_last_sync',
                    'value' => current_time('mysql')
                ]
            ]
        ];
        
        // Registrar el producto mapeado
        self::get_logger()->debug('Producto mapeado de Verial a WooCommerce', [
            'source' => 'VerialProductMapper', 
            'wc_product' => $wc_product
        ]);
        
        return $wc_product;
    }
    
    /**
     * Normaliza los datos del producto de Verial para asegurar consistencia
     *
     * @param array $verial_product Datos del producto de Verial
     * @return array Datos del producto normalizados
     */
    public static function normalize_verial_product($verial_product) {
        // Asegurar que tenemos un array
        if (!is_array($verial_product)) {
            self::get_logger()->error('Producto Verial no es un array', [
                'source' => 'VerialProductMapper', 
                'verial_product' => $verial_product
            ]);
            return [];
        }
        
        // Asegurar que los campos críticos existen
        $normalized = [
            'Id' => isset($verial_product['Id']) ? (int)$verial_product['Id'] : 0,
            'ReferenciaBarras' => isset($verial_product['ReferenciaBarras']) ? $verial_product['ReferenciaBarras'] : '',
            'Nombre' => isset($verial_product['Nombre']) ? $verial_product['Nombre'] : '',
            'Descripcion' => isset($verial_product['Descripcion']) ? $verial_product['Descripcion'] : '',
            'ID_Categoria' => isset($verial_product['ID_Categoria']) ? (int)$verial_product['ID_Categoria'] : 0,
            'ID_Fabricante' => isset($verial_product['ID_Fabricante']) ? (int)$verial_product['ID_Fabricante'] : 0,
            'PorcentajeIVA' => isset($verial_product['PorcentajeIVA']) ? (float)$verial_product['PorcentajeIVA'] : 0,
            'Peso' => isset($verial_product['Peso']) ? (float)$verial_product['Peso'] : 0,
            'Alto' => isset($verial_product['Alto']) ? (float)$verial_product['Alto'] : 0,
            'Ancho' => isset($verial_product['Ancho']) ? (float)$verial_product['Ancho'] : 0,
            'Grueso' => isset($verial_product['Grueso']) ? (float)$verial_product['Grueso'] : 0,
            'Tipo' => isset($verial_product['Tipo']) ? (int)$verial_product['Tipo'] : 0,
        ];
        
        // Agregar campos adicionales si existen
        if (isset($verial_product['Autores'])) {
            $normalized['Autores'] = $verial_product['Autores'];
        }
        
        if (isset($verial_product['Edicion'])) {
            $normalized['Edicion'] = $verial_product['Edicion'];
        }
        
        if (isset($verial_product['Paginas'])) {
            $normalized['Paginas'] = (int)$verial_product['Paginas'];
        }
        
        if (isset($verial_product['Subtitulo'])) {
            $normalized['Subtitulo'] = $verial_product['Subtitulo'];
        }
        
        // Registrar el producto normalizado
        self::get_logger()->debug('Producto Verial normalizado', [
            'source' => 'VerialProductMapper', 
            'normalized_product' => $normalized
        ]);
        
        return $normalized;
    }
    
    /**
     * Determina el tipo de producto WooCommerce según los datos de Verial
     *
     * @param array $verial_product Datos del producto de Verial
     * @return string Tipo de producto WooCommerce
     */
    public static function determine_product_type($verial_product) {
        // Por defecto, usar producto simple
        $product_type = 'simple';
        
        // Implementar lógica para determinar otros tipos (variable, agrupado, etc.)
        // según los datos específicos de Verial
        
        return $product_type;
    }
    
    /**
     * Verifica si el producto necesita actualización basado en los datos existentes
     *
     * @param int $wc_product_id ID del producto en WooCommerce
     * @param array $verial_product Datos del producto de Verial
     * @return bool True si el producto necesita actualización
     */
    public static function needs_update($wc_product_id, $verial_product) {
        $product = wc_get_product($wc_product_id);
        if (!$product) {
            return true; // Si no existe, necesita ser creado
        }
        
        // Obtener la fecha de última sincronización
        $last_sync = $product->get_meta('_verial_last_sync', true);
        if (empty($last_sync)) {
            return true; // Si nunca ha sido sincronizado, actualizar
        }
        
        // Verificar si el SKU ha cambiado
        $current_sku = $product->get_sku();
        $verial_sku = isset($verial_product['ReferenciaBarras']) ? $verial_product['ReferenciaBarras'] : '';
        if ($current_sku !== $verial_sku) {
            return true;
        }
        
        // Verificar si el nombre ha cambiado
        $current_name = $product->get_name();
        $verial_name = isset($verial_product['Nombre']) ? $verial_product['Nombre'] : '';
        if ($current_name !== $verial_name) {
            return true;
        }
        
        // Se pueden agregar más verificaciones según sea necesario
        
        return false; // No necesita actualización
    }
}
