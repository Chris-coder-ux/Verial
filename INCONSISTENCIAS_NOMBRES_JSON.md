# Inconsistencias en Nombres de Datos en los JSON de la API Verial

Este documento detalla las inconsistencias encontradas en los nombres de los campos JSON entre diferentes endpoints y su uso en el código.

## Inconsistencias en Identificadores de Artículos

| Endpoint | Campo JSON | Ejemplo | Descripción | Archivos afectados |
|----------|-----------|---------|-------------|-------------------|
| **GetArticulosWs** | `Id` | `5` | Identificador de artículo en minúscula inicial | <ul><li>`includes/Sync/Sync_Single_Product.php`</li><li>`includes/Core/ApiConnector.php`</li><li>`scripts/test-precio-producto.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWs** | `ID_Articulo` | `5` | Usa mayúsculas y separación con guión bajo | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li><li>`includes/Core/ApiConnector.php`</li></ul> |
| **GetStockArticulosWs** | `ID_Articulo` | `5` | Usa el mismo formato que GetCondicionesTarifaWs | <ul><li>`includes/Endpoints/StockArticulosWS.php`</li><li>`includes/Endpoints/GetStockArticulosWS.php`</li></ul> |

Esta inconsistencia obliga al código a manejar diferentes formatos para el mismo concepto, aumentando la complejidad y posibilidad de errores cuando se mapean datos entre sistemas.

### Identificadores correctos según documentación y estándares

| Endpoint | Identificador Actual | Identificador Correcto | Justificación |
|----------|---------------------|------------------------|---------------|
| **GetArticulosWs** | `Id` | `ID_Articulo` | Según el manual de Verial y el uso mayoritario en otros endpoints, el formato con mayúsculas y guión bajo (`ID_Articulo`) es el estándar oficial de la API. Además, los demás campos de identificación en este mismo endpoint (`ID_Categoria`, `ID_Fabricante`, etc.) utilizan este formato. |
| **GetCondicionesTarifaWs** | `ID_Articulo` | `ID_Articulo` | Este endpoint ya utiliza el formato correcto según la documentación oficial. |
| **GetStockArticulosWs** | `ID_Articulo` | `ID_Articulo` | Este endpoint ya utiliza el formato correcto según la documentación oficial. |

La implementación correcta debería estandarizar todos los identificadores de artículos al formato `ID_Articulo` en las respuestas procesadas por el sistema. Esto implica normalizar las respuestas de `GetArticulosWs` para que el campo `Id` se convierta o se mapee a `ID_Articulo` en las capas de abstracción.

## Inconsistencias en Nombres de Estructura Principal

| Endpoint | Estructura Principal | Descripción | Archivos afectados |
|----------|---------------------|------------|-------------------|
| **GetArticulosWs** | `Articulos` | Array de artículos | <ul><li>`includes/Core/ApiConnector.php`</li><li>`includes/WooCommerce/OrderManager.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWs** | `CondicionesTarifa` | Array de condiciones | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li><li>`includes/Core/ApiConnector.php`</li></ul> |
| **GetStockArticulosWs** | `Stock` o `StockArticulos` | Inconsistencia documentada | <ul><li>`includes/Endpoints/StockArticulosWS.php`: Líneas 138-142</li><li>`includes/Endpoints/GetStockArticulosWS.php`: Líneas 135-142</li></ul> |

El endpoint `GetStockArticulosWS` presenta una inconsistencia adicional documentada en el código, donde a veces devuelve `StockArticulos` en lugar de `Stock` como indica el manual:

```php
$this->logger->error(
    '[MI Integracion API] GetStockArticulosWS: Verial devolvió "StockArticulos" en lugar de "Stock" como indica el manual.',
    array( 'response' => $verial_response )
);
```

## Campo de Precio No Documentado

| Endpoint | Campo JSON | Descripción | Archivos afectados |
|----------|-----------|------------|-------------------|
| **GetArticulosWs** | `PVP` | Campo de precio no documentado en el manual | <ul><li>`includes/Sync/SyncManager.php`: Líneas 888-891</li><li>`scripts/test-precio-producto.php`: Líneas 107-109</li></ul> |
| **GetCondicionesTarifaWs** | `Precio` | Campo de precio documentado | <ul><li>`includes/Core/ApiConnector.php`: Líneas 1969-1976</li><li>`scripts/test-sync-prices.php`</li></ul> |

El endpoint `GetArticulosWS` parece incluir un campo `PVP` que no está documentado en el manual, mientras que en `GetCondicionesTarifaWS` se utiliza el campo `Precio` para el mismo propósito. Se encuentra código como:

```php
$this->logger->log("Usando PVP ya disponible para #$verial_id: " . $product_data['PVP'], 'debug');
return floatval($product_data['PVP']);
```

## Inconsistencias en Formateo y Validación

| Endpoint | Campo JSON | Formato Esperado | Manejo en Código | Archivos afectados |
|----------|-----------|-----------------|----------------|-------------------|
| **GetCondicionesTarifaWs** | `Precio` | Numérico (8,6) | `floatval($condicion_verial['Precio'])` | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`: Línea 172</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`: Línea 157</li></ul> |
| **GetCondicionesTarifaWs** | `Dto` | Numérico (3,4) | `floatval($condicion_verial['Dto'])` | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`: Línea 173</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`: Línea 158</li></ul> |
| **GetCondicionesTarifaWs** | `DtoEurosXUd` | Numérico (10,2) | `floatval($condicion_verial['DtoEurosXUd'])` | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`: Línea 174</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`: Línea 159</li></ul> |

## Inconsistencias en Estructura de Respuesta

El código muestra múltiples formas de manejar las respuestas, sugiriendo que la API podría estar devolviendo estructuras inconsistentes:

```php
// En includes/Sync/Sync_Single_Product.php
if (isset($condiciones_tarifa['CondicionesTarifa']) && is_array($condiciones_tarifa['CondicionesTarifa'])) {
    $logger->info('Detectada estructura CondicionesTarifa en la respuesta');
    $condiciones_lista = $condiciones_tarifa['CondicionesTarifa'];
} elseif (isset($condiciones_tarifa[0])) {
    // Array de condiciones directo
    $condiciones_lista = $condiciones_tarifa;
} elseif (isset($condiciones_tarifa['Precio'])) {
    // Condición única
    $condiciones_lista = [$condiciones_tarifa];
}
```

Esta adaptación de código muestra que las respuestas de los endpoints no siguen un formato consistente, lo que aumenta la complejidad del manejo de datos.

## Inconsistencias en Campos de Stock

| Endpoint | Campo JSON | Descripción | Manejo en Código | Archivos afectados |
|----------|-----------|------------|----------------|-------------------|
| **GetStockArticulosWs** | `Stock` | Unidades de stock | `floatval($stock_item_verial['Stock'])` | <ul><li>`includes/Endpoints/StockArticulosWS.php`: Línea 159</li><li>`includes/Endpoints/GetStockArticulosWS.php`: Línea 162</li></ul> |
| **GetStockArticulosWs** | `StockAux` | Unidades auxiliares | `floatval($stock_item_verial['StockAux'])` | <ul><li>`includes/Endpoints/StockArticulosWS.php`: Línea 160</li><li>`includes/Endpoints/GetStockArticulosWS.php`: Línea 163</li></ul> |

## Impacto de las Inconsistencias 

### Código Duplicado y Adaptadores

Debido a estas inconsistencias, el código contiene múltiples adaptadores y transformaciones para manejar los diferentes formatos:

1. **Adaptaciones en tiempo de ejecución**: El código debe verificar diferentes estructuras posibles.
2. **Transformación de formatos**: Normalización constante para un formato interno consistente.
3. **Manejo defensivo**: Verificación exhaustiva de la existencia de campos para prevenir errores.

### Complejidad de Mantenimiento

Estas inconsistencias afectan directamente la mantenibilidad del código:

- **Mayor cantidad de código**: Se necesita código adicional para manejar los diferentes formatos.
- **Mayor riesgo de errores**: Cada cambio en el código debe considerar todas las variantes de formato.
- **Documentación insuficiente**: Las diferencias no están completamente documentadas, lo que aumenta la curva de aprendizaje.

## Recomendaciones para Solucionar las Inconsistencias

1. **Estandarización mediante DTOs**: Crear una capa de objetos de transferencia de datos (DTOs) que normalicen todas las respuestas de la API.

2. **Adapters centralizados**: Implementar un patrón adaptador centralizado para cada endpoint, en lugar de manejar adaptaciones dispersas por todo el código.

3. **Tests automáticos para diferentes estructuras**: Asegurar que todas las variantes de respuesta sean probadas mediante tests automatizados.

4. **Documentación completa**: Crear una documentación que detalle todas las posibles estructuras de respuesta y cómo manejarlas.

5. **Unificación de clases duplicadas**: Eliminar la duplicación de clases como `ArticulosWS`/`GetArticulosWS` y `CondicionesTarifaWS`/`GetCondicionesTarifaWS` que manejan los mismos endpoints pero con implementaciones ligeramente diferentes.
