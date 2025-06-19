# Plan de Implementación

## Notas
- El usuario está experimentando un problema con la sincronización de precios en el proceso por lotes.
- La clase `SyncProductos.php` maneja la sincronización por lotes de productos. Su lógica se basa en el método `MapProduct::verial_to_wc` para el mapeo de datos.
- `MapProduct::verial_to_wc` utiliza los campos `PVP` y `PVPOferta` de los datos principales del producto para establecer los precios.
- Existe un endpoint separado, `GetCondicionesTarifaWS`, que proporciona precios especiales en el campo `Precio`.
- La clase `ApiConnector.php` tiene un método `sync_product` que obtiene correctamente el precio de `GetCondicionesTarifaWS` y lo aplica a los datos del producto antes de guardar.
- **El problema principal es que el proceso por lotes en `SyncProductos.php` no llama a `ApiConnector::sync_product` ni a `ApiConnector::get_condiciones_tarifa`. Procesa los productos de una manera que omite la lógica de precios especiales.**

## Código Propuesto

### 1. Método para obtener el precio especial

```php
/**
 * Obtiene el precio especial de un producto desde GetCondicionesTarifaWS
 * 
 * @param object $api_connector Instancia del conector API
 * @param string $product_id ID del producto
 * @return array|null Datos del precio especial o null si no se encuentra
 */
private static function get_special_price($api_connector, $product_id) {
    try {
        $price_conditions = $api_connector->get_condiciones_tarifa($product_id);
        if (!empty($price_conditions) && isset($price_conditions['Precio']) && 
            is_numeric($price_conditions['Precio']) && $price_conditions['Precio'] > 0) {
            return [
                'price' => floatval($price_conditions['Precio']),
                'discount' => $price_conditions['Dto'] ?? 0,
                'min_qty' => $price_conditions['UdsMin'] ?? 1
            ];
        }
    } catch (\Exception $e) {
        \MiIntegracionApi\Helpers\Logger::error(
            'Error al obtener precio especial',
            [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]
        );
    }
    return null;
}
```

### 2. Modificación de process_products

```php
private static function process_products($productos) {
    $processed = 0;
    $errors    = 0;
    $log       = array();
    $api_connector = new \MiIntegracionApi\Core\ApiConnector();

    foreach ($productos as $producto) {
        // Obtener precio especial si está disponible
        $special_price = self::get_special_price($api_connector, $producto['Id']);
        
        // Si tenemos un precio especial, actualizar los datos del producto
        if ($special_price) {
            $producto['PVPOferta'] = $special_price['price'];
            // Almacenar información de descuento en metadatos
            if (!isset($producto['meta_data'])) {
                $producto['meta_data'] = [];
            }
            $producto['meta_data']['_verial_special_price'] = $special_price;
        }
        
        // Resto del código existente...
        $wc_data_dto = \MiIntegracionApi\Helpers\MapProduct::verial_to_wc($producto);
        // ...
    }
    // ...
}
```

## Lista de Tareas
- [x] Investigar `SyncProductos.php` y `MapProduct.php`.
- [x] Encontrar dónde se llama al endpoint `GetCondicionesTarifaWS` (`ApiConnector::get_condiciones_tarifa`).
- [x] Determinar que el método `ApiConnector::sync_product` maneja correctamente las actualizaciones de precios usando `GetCondicionesTarifaWS`, pero el proceso por lotes en `SyncProductos.php` no usa este método.
- [x] Proponer modificaciones al código para incluir la lógica de precios especiales en la sincronización por lotes.
- [x] Implementar los cambios propuestos en `SyncProductos.php`.
- [ ] Probar la sincronización con productos que tengan precios especiales.
- [ ] Verificar que los cambios funcionen tanto para la sincronización individual como por lotes.
- [ ] Documentar los cambios realizados.

## Objetivo Actual
Probar y verificar los cambios implementados en `SyncProductos.php` para obtener y aplicar correctamente los precios especiales durante la sincronización por lotes.