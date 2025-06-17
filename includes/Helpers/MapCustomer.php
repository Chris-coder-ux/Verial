<?php
/**
 * Helper para mapear datos de clientes entre WooCommerce y Verial.
 * Prepara el payload para el endpoint NuevoClienteWS de Verial.
 *
 * @package MiIntegracionApi\Helpers
 */
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\DTOs\CustomerDTO;
use MiIntegracionApi\Core\DataSanitizer;
use MiIntegracionApi\Helpers\Logger;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente al archivo
}

/**
 * Clase para mapear datos de clientes entre WooCommerce y Verial
 * 
 * @since 1.0.0
 */
class MapCustomer {
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
     * Mapea un cliente de Verial a un DTO de WooCommerce.
     *
     * @param array $verial_customer Datos del cliente de Verial
     * @return CustomerDTO|null DTO del cliente mapeado o null si los datos son inválidos
     */
    public static function verial_to_wc(array $verial_customer): ?CustomerDTO {
        self::init();

        try {
            // Validar campos requeridos
            if (empty($verial_customer['ID']) || empty($verial_customer['Email'])) {
                self::$logger->error('Cliente Verial inválido: faltan campos requeridos', [
                    'customer' => $verial_customer
                ]);
                return null;
            }

            // Sanitizar datos
            $customer_data = [
                'id' => self::$sanitizer->sanitize($verial_customer['ID'], 'int'),
                'email' => self::$sanitizer->sanitize($verial_customer['Email'], 'email'),
                'first_name' => self::$sanitizer->sanitize($verial_customer['Nombre'] ?? '', 'text'),
                'last_name' => self::$sanitizer->sanitize($verial_customer['Apellidos'] ?? '', 'text'),
                'phone' => self::$sanitizer->sanitize($verial_customer['Telefono'] ?? '', 'phone'),
                'billing' => [
                    'first_name' => self::$sanitizer->sanitize($verial_customer['Nombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_customer['Apellidos'] ?? '', 'text'),
                    'email' => self::$sanitizer->sanitize($verial_customer['Email'], 'email'),
                    'phone' => self::$sanitizer->sanitize($verial_customer['Telefono'] ?? '', 'phone'),
                    'address_1' => self::$sanitizer->sanitize($verial_customer['Direccion'] ?? '', 'text'),
                    'address_2' => self::$sanitizer->sanitize($verial_customer['Direccion2'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_customer['Ciudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_customer['Provincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_customer['CodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_customer['Pais'] ?? '', 'text')
                ],
                'shipping' => [
                    'first_name' => self::$sanitizer->sanitize($verial_customer['EnvioNombre'] ?? '', 'text'),
                    'last_name' => self::$sanitizer->sanitize($verial_customer['EnvioApellidos'] ?? '', 'text'),
                    'address_1' => self::$sanitizer->sanitize($verial_customer['EnvioDireccion'] ?? '', 'text'),
                    'address_2' => self::$sanitizer->sanitize($verial_customer['EnvioDireccion2'] ?? '', 'text'),
                    'city' => self::$sanitizer->sanitize($verial_customer['EnvioCiudad'] ?? '', 'text'),
                    'state' => self::$sanitizer->sanitize($verial_customer['EnvioProvincia'] ?? '', 'text'),
                    'postcode' => self::$sanitizer->sanitize($verial_customer['EnvioCodigoPostal'] ?? '', 'postcode'),
                    'country' => self::$sanitizer->sanitize($verial_customer['EnvioPais'] ?? '', 'text')
                ],
                'meta_data' => self::$sanitizer->sanitize($verial_customer['MetaDatos'] ?? [], 'text')
            ];

            // Validar datos críticos
            if (!self::$sanitizer->validate($customer_data['email'], 'email')) {
                self::$logger->error('Email de cliente inválido', [
                    'email' => $customer_data['email']
                ]);
                return null;
            }

            return new CustomerDTO($customer_data);

        } catch (\Exception $e) {
            self::$logger->error('Error al mapear cliente Verial a WooCommerce', [
                'error' => $e->getMessage(),
                'customer' => $verial_customer
            ]);
            return null;
        }
    }

    /**
     * Mapea un cliente de WooCommerce al formato de Verial.
     *
     * @param \WP_User $wc_customer Cliente de WooCommerce
     * @return array Datos del cliente en formato Verial
     */
    public static function wc_to_verial($wc_customer): array {
        self::init();

        try {
            if (!$wc_customer instanceof \WC_Customer) {
                self::$logger->error('Cliente WooCommerce inválido');
                return [];
            }

            // Sanitizar datos
            $verial_customer = [
                'ID' => self::$sanitizer->sanitize($wc_customer->get_id(), 'int'),
                'Email' => self::$sanitizer->sanitize($wc_customer->get_email(), 'email'),
                'Nombre' => self::$sanitizer->sanitize($wc_customer->get_first_name(), 'text'),
                'Apellidos' => self::$sanitizer->sanitize($wc_customer->get_last_name(), 'text'),
                'Telefono' => self::$sanitizer->sanitize($wc_customer->get_billing_phone(), 'phone'),
                'Direccion' => self::$sanitizer->sanitize($wc_customer->get_billing_address_1(), 'text'),
                'Direccion2' => self::$sanitizer->sanitize($wc_customer->get_billing_address_2(), 'text'),
                'Ciudad' => self::$sanitizer->sanitize($wc_customer->get_billing_city(), 'text'),
                'Provincia' => self::$sanitizer->sanitize($wc_customer->get_billing_state(), 'text'),
                'CodigoPostal' => self::$sanitizer->sanitize($wc_customer->get_billing_postcode(), 'postcode'),
                'Pais' => self::$sanitizer->sanitize($wc_customer->get_billing_country(), 'text'),
                'EnvioNombre' => self::$sanitizer->sanitize($wc_customer->get_shipping_first_name(), 'text'),
                'EnvioApellidos' => self::$sanitizer->sanitize($wc_customer->get_shipping_last_name(), 'text'),
                'EnvioDireccion' => self::$sanitizer->sanitize($wc_customer->get_shipping_address_1(), 'text'),
                'EnvioDireccion2' => self::$sanitizer->sanitize($wc_customer->get_shipping_address_2(), 'text'),
                'EnvioCiudad' => self::$sanitizer->sanitize($wc_customer->get_shipping_city(), 'text'),
                'EnvioProvincia' => self::$sanitizer->sanitize($wc_customer->get_shipping_state(), 'text'),
                'EnvioCodigoPostal' => self::$sanitizer->sanitize($wc_customer->get_shipping_postcode(), 'postcode'),
                'EnvioPais' => self::$sanitizer->sanitize($wc_customer->get_shipping_country(), 'text'),
                'MetaDatos' => self::$sanitizer->sanitize($wc_customer->get_meta_data(), 'text')
            ];

            // Validar datos críticos
            if (!self::$sanitizer->validate($verial_customer['Email'], 'email')) {
                self::$logger->error('Email de cliente inválido', [
                    'email' => $verial_customer['Email']
                ]);
                return [];
            }

            return $verial_customer;

        } catch (\Exception $e) {
            self::$logger->error('Error al mapear cliente WooCommerce a Verial', [
                'error' => $e->getMessage(),
                'customer_id' => $wc_customer->get_id()
            ]);
            return [];
        }
    }
    
    /**
     * Función de compatibilidad para el código antiguo
     * 
     * @deprecated Use MapCustomer::wc_to_verial() en su lugar
     */
    public static function map_customer_to_verial($customer, $additional_data = []) {
        return self::wc_to_verial($customer, $additional_data);
    }
}
