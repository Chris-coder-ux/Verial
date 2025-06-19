# Informe de Inconsistencias en Nomenclatura de Endpoints

Este documento detalla las inconsistencias encontradas en la nomenclatura de los endpoints en el plugin mi-integracion-api.

## Inconsistencias en GetArticulosWS / ArticulosWS

| Archivo | Inconsistencia |
|---------|---------------|
| `/includes/Endpoints/ArticulosWS.php` | La clase se llama `ArticulosWS` pero el ENDPOINT_NAME es `GetArticulosWS` |
| `/includes/Endpoints/GetArticulosWS.php` | Clase duplicada: define la misma funcionalidad que `ArticulosWS` |
| `/includes/Endpoints/NumArticulosWS.php` | La clase se llama `NumArticulosWS` pero el ENDPOINT_NAME es `GetNumArticulosWS` |
| `/includes/Endpoints/GetNumArticulosWS.php` | Clase duplicada: define la misma funcionalidad que `NumArticulosWS` |
| `/includes/WooCommerce/OrderManager.php` | Usa llamada directa a `GetArticulosWS` con el método `call` en lugar de usar la clase correspondiente |
| `/includes/Sync/SyncManager.php` | Usa `GetArticulosWS` como parte de un endpoint directo en vez de usar el cliente API abstraído |
| `/includes/Sync/Sync_Single_Product.php` | Hace referencia a `GetArticulosWS` pero usa el método `get_articulos` del conector API |
| `/includes/Core/Sync_Manager.php` | Comentario menciona que `GetArticulosWS requiere POST con body JSON, no GET con parámetros` pero hay varias implementaciones que usan GET |

## Inconsistencias en GetCondicionesTarifaWS / CondicionesTarifaWS

| Archivo | Inconsistencia |
|---------|---------------|
| `/includes/Endpoints/CondicionesTarifaWS.php` | La clase se llama `CondicionesTarifaWS` pero el ENDPOINT_NAME es `GetCondicionesTarifaWS` |
| `/includes/Endpoints/GetCondicionesTarifaWS.php` | Clase duplicada: define la misma funcionalidad que `CondicionesTarifaWS` |
| `/includes/Core/REST_API_Handler.php` | Instancia `CondicionesTarifaWS` pero verifica `class_exists('MiIntegracionApi\\Endpoints\\CondicionesTarifaWS')` |
| `/scripts/sync-test-simple.log` | El endpoint se accede como `GetCondicionesTarifaWS` |

## Estructura de respuesta de endpoints

| Endpoint | Clave JSON esperada |
|----------|---------------------|
| `GetArticulosWS` | `Articulos` |
| `GetCondicionesTarifaWS` | `CondicionesTarifa` |

## Patrones de inconsistencia general

1. **Duplicación de clases**: Existen pares de clases como `ArticulosWS`/`GetArticulosWS`, `CondicionesTarifaWS`/`GetCondicionesTarifaWS`, etc. donde ambas definen la misma funcionalidad pero con diferentes nombres de clase.

2. **Inconsistencia en nomenclatura**: Las clases no siguen un estándar consistente. Algunas tienen el prefijo "Get" en el nombre de la clase y otras no, aunque todas usan el prefijo "Get" en la constante ENDPOINT_NAME.

3. **Mixtura de métodos de acceso**: En algunos lugares se usa `get_articulos()` o métodos específicos mientras que en otros se hace una llamada directa al endpoint usando `call('GetArticulosWS',...)`.

4. **Inconsistencia en el método HTTP**: Hay contradicciones sobre si los endpoints deben ser accedidos via GET o POST. Por ejemplo, en `Sync_Manager.php` se indica que `GetArticulosWS` requiere POST con body JSON, pero en otros lugares se usa GET con parámetros.

## Recomendaciones

1. **Estandarizar la nomenclatura de clases**: Decidir si las clases deberían tener el prefijo "Get" para coincidir con los nombres de endpoint reales, o eliminar el prefijo y mantener solo el sufijo "WS".

2. **Eliminar duplicaciones**: Consolidar las clases duplicadas (`ArticulosWS`/`GetArticulosWS`, etc.) en una sola implementación.

3. **Abstraer el acceso a la API**: Utilizar siempre los métodos abstraídos como `get_articulos()` en vez de llamadas directas al endpoint para centralizar la lógica y facilitar cambios futuros.

4. **Documentar claramente los métodos HTTP requeridos**: Aclarar en la documentación y comentarios si los endpoints deben ser accesibles vía GET, POST, o ambos, y asegurar que el código sea consistente con esta documentación.
