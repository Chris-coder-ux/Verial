# Inconsistencias en Formatos JSON de la API Verial

Este documento detalla las inconsistencias encontradas en los nombres de campos JSON entre los diferentes endpoints de la API de Verial.

## Formatos JSON de ejemplo

### GetArticulosWs
```json
{
  "Articulos": [
    {
      "Alto": 0,
      "Ancho": 0,
      "Autores": null,
      "Aux1": "",
      "Aux2": "",
      "Aux3": "",
      "Aux4": "",
      "Aux5": "",
      "Aux6": "",
      "CamposConfigurables": null,
      "DecPrecioVentas": 4,
      "DecUdsVentas": 0,
      "Descripcion": "",
      "Edicion": "",
      "FechaDisponibilidad": null,
      "FechaEdicion": null,
      "FechaInactivo": null,
      "FechaInicioVenta": null,
      "Grueso": 0,
      "ID_ArticuloEcotasas": 0,
      "ID_Asignatura": 0,
      "ID_Categoria": 4,
      "ID_CategoriaWeb1": 0,
      "ID_CategoriaWeb2": 0,
      "ID_CategoriaWeb3": 0,
      "ID_CategoriaWeb4": 0,
      "ID_Coleccion": 0,
      "ID_Curso": 0,
      "ID_Fabricante": 1,
      "ID_PaisPublicacion": 0,
      "Id": 5,
      "IdiomaOriginal": null,
      "IdiomaPublicacion": null,
      "Indice": "",
      "Menciones": "",
      "Nexo": "",
      "Nombre": "ARTURO",
      "NombreUds": "",
      "NombreUdsAux": "",
      "NombreUdsOCU": "",
      "NumDimensiones": 0,
      "NumeroColeccion": "",
      "NumeroVolumen": "",
      "ObraCompleta": "",
      "Paginas": 0,
      "Peso": 0,
      "PorcentajeIVA": 4,
      "PorcentajeRE": 0,
      "PrecioEcotasas": 0,
      "ReferenciaBarras": "9788415250128",
      "RelacionUdsAux": 0,
      "RelacionUdsOCU": 0,
      "Resumen": "",
      "Subtitulo": "",
      "Tipo": 2,
      "VenderUdsAux": false,
      "Volumenes": 0
    }
  ]
}
```

### GetCondicionesTarifaWs
```json
{
  "CondicionesTarifa": [
    {
      "Dto": 0,
      "DtoEurosXUd": 0,
      "ID_Articulo": 5,
      "Precio": 9.62,
      "UdsMin": 0,
      "UdsRegalo": 0
    }
  ]
}
```

## Inconsistencias principales

### 1. Identificador de artículo

| Endpoint | Campo JSON | Ejemplo | Archivos afectados |
|----------|-----------|---------|-------------------|
| **GetArticulosWS** | `Id` | `5` | <ul><li>`includes/Sync/Sync_Single_Product.php`</li><li>`scripts/test-precio-producto.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWS** | `ID_Articulo` | `5` | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li><li>`includes/Core/ApiConnector.php`</li></ul> |

Esta diferencia en la nomenclatura de los identificadores genera inconsistencia en el código, ya que en algunos lugares se busca `Id` mientras en otros se utiliza `ID_Articulo`. Esto puede causar errores cuando se mapean datos entre sistemas o cuando se realizan búsquedas cruzadas.

### 2. Estructura de respuesta

| Endpoint | Estructura de respuesta | Archivos afectados |
|----------|------------------------|-------------------|
| **GetArticulosWS** | `{ "Articulos": [ ... ] }` | <ul><li>`includes/Core/ApiConnector.php`</li><li>`includes/WooCommerce/OrderManager.php`</li></ul> |
| **GetCondicionesTarifaWS** | `{ "CondicionesTarifa": [ ... ] }` | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |

Ambos endpoints utilizan una estructura diferente para contener sus arrays de datos, lo que requiere un manejo específico para cada uno cuando se procesan las respuestas.

### 3. Campos de condiciones de tarifa

| Endpoint | Campo JSON | Formato | Archivos afectados |
|----------|-----------|---------|-------------------|
| **GetCondicionesTarifaWS** | `Precio` | Numérico (8,6) | <ul><li>`includes/Core/ApiConnector.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWS** | `Dto` | Numérico (3,4) | <ul><li>`includes/Core/ApiConnector.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWS** | `DtoEurosXUd` | Numérico (10,2) | <ul><li>`includes/Core/ApiConnector.php`</li><li>`scripts/test-sync-prices.php`</li></ul> |
| **GetCondicionesTarifaWS** | `UdsMin` | Numérico (10,4) | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li></ul> |
| **GetCondicionesTarifaWS** | `UdsRegalo` | Numérico (10,4) | <ul><li>`includes/Endpoints/CondicionesTarifaWS.php`</li><li>`includes/Endpoints/GetCondicionesTarifaWS.php`</li></ul> |
| **GetArticulosWS** | `PVP` | No documentado | <ul><li>`includes/Sync/SyncManager.php`</li><li>`scripts/test-precio-producto.php`</li></ul> |

El endpoint `GetArticulosWS` parece incluir un campo `PVP` que no está claramente documentado en el manual, mientras que en `GetCondicionesTarifaWS` se utiliza el campo `Precio` para el mismo propósito.

## Manejo actual

El código actual maneja estas inconsistencias de varias formas:

1. **Adaptadores y mapeos**: En `includes/Sync/Sync_Single_Product.php` se detecta la estructura de la respuesta y se adapta:
   ```php
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

2. **Transformación de formatos**: En `includes/Endpoints/CondicionesTarifaWS.php` se normaliza el formato:
   ```php
   $condiciones[] = array(
       'id_articulo'              => isset($condicion_verial['ID_Articulo']) ? intval($condicion_verial['ID_Articulo']) : null,
       'precio_sin_impuestos'     => isset($condicion_verial['Precio']) ? floatval($condicion_verial['Precio']) : null,
       'descuento_porcentaje'     => isset($condicion_verial['Dto']) ? floatval($condicion_verial['Dto']) : null,
       'descuento_euros_x_unidad' => isset($condicion_verial['DtoEurosXUd']) ? floatval($condicion_verial['DtoEurosXUd']) : null,
       'unidades_minimas'         => isset($condicion_verial['UdsMin']) ? floatval($condicion_verial['UdsMin']) : null,
       'unidades_regalo'          => isset($condicion_verial['UdsRegalo']) ? floatval($condicion_verial['UdsRegalo']) : null,
   );
   ```

## Impacto de las inconsistencias

Estas inconsistencias tienen varios impactos en el código:

1. **Complejidad de mantenimiento**: Se requiere código adicional para manejar estas diferencias.
2. **Riesgo de errores**: Al modificar el código, es fácil olvidar manejar una de las variantes.
3. **Dificultad para nuevos desarrolladores**: La curva de aprendizaje es más empinada debido a estas inconsistencias.
4. **Posibles errores en tiempo de ejecución**: Si no se manejan correctamente, pueden aparecer errores cuando se intenta acceder a campos con nombres incorrectos.

## Recomendaciones

1. **Estandarización de DTO**: Crear un conjunto consistente de DTOs (Data Transfer Objects) que normalicen las respuestas de la API.
2. **Capa de abstracción**: Implementar adaptadores que consuman la API y expongan una interfaz coherente.
3. **Documentación clara**: Documentar explícitamente estas inconsistencias y cómo manejarlas.
4. **Pruebas automatizadas**: Asegurar que las pruebas cubran los diferentes formatos de respuesta.
