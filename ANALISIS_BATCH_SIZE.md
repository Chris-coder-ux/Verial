# Análisis de Gestión de Tamaño de Lote (Batch Size)

Este documento analiza todas las ubicaciones donde se maneja el tamaño de lote en la aplicación Mi Integración API, identificando posibles inconsistencias y problemas de mantenimiento.

## Archivos de Configuración

### ConfigManager.php (Hecho)

La clase principal de configuración que ahora delega la gestión del tamaño de lote a BatchSizeHelper.

| Línea | Descripción |
|-------|-------------|
| 10-15 | Eliminados valores predeterminados de batch_size, ahora gestionados por BatchSizeHelper |
| 16-20 | Eliminados validadores de batch_size, ahora gestionados por BatchSizeHelper |
| 44-57 | Método `getBatchSize(string $entity)`: Delega la responsabilidad a BatchSizeHelper |
| 70-83 | Método `setBatchSize(string $entity, int $batch_size)`: Delega la responsabilidad a BatchSizeHelper |

## Controladores AJAX

### AjaxSync.php (Hecho)

Maneja las solicitudes AJAX del frontend y establece el tamaño de lote basado en la entrada del usuario.

| Línea | Descripción |
|-------|-------------|
| 1452-1453 | Extrae el tamaño de lote de la solicitud AJAX |
| 1455 | **Problema resuelto**: Utiliza `BatchSizeHelper::setBatchSize('productos', $batch_size)` que maneja internamente la consistencia |
| 1458 | Obtiene el valor efectivamente establecido con `BatchSizeHelper::getBatchSize('productos')` |
| 1460-1467 | Registra advertencia si el valor fue ajustado automáticamente por estar fuera de límites |
| 1472 | Si no se especifica, usa el valor actual desde BatchSizeHelper |

## Interfaz de Usuario

### DashboardPageView.php (Hecho)

Define la interfaz de usuario para seleccionar el tamaño de lote.

| Línea | Descripción |
|-------|-------------|
| 103-104 | Lee valor actual usando `$current_batch_size = \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('productos')` |
| 106-107 | Obtiene límites mínimos y máximos desde `BatchSizeHelper::BATCH_SIZE_LIMITS` |
| 110 | Define opciones predefinidas incluyendo valores más altos: 1, 5, 10, 20, 50, 100, 200 |
| 114-115 | Filtra opciones para mostrar solo valores dentro de los límites permitidos |

## Clases de Sincronización

### Sync_Manager.php (Core) (Hecho)

| Línea | Descripción |
|-------|-------------|
| 150 | Configuración inicial `'batch_size' => BatchSizeHelper::getBatchSize('productos')` |
| 280 | Uso correcto de BatchSizeHelper `'batch_size' => BatchSizeHelper::getBatchSize($entity)` |
| 904 | Logging del valor de BatchSizeHelper `'batch_size_helper_value' => BatchSizeHelper::getBatchSize('productos')` |
| 913-920 | **Problema resuelto**: Ahora compara valor de configuración con `BatchSizeHelper::getBatchSize('productos')` |
| 940 | Cálculo de tamaño efectivo con `BatchSizeHelper::calculateEffectiveBatchSize($inicio, $fin)` |
| 941 | Validación dinámica usando `BatchSizeHelper::BATCH_SIZE_LIMITS` para determinar umbrales de advertencia |
| 990 | Decisión de subdivisión basada en `BatchSizeHelper::DEFAULT_BATCH_SIZES['productos']` |

### SyncManager.php (Sync) (Hecho)

| Línea | Descripción |
|-------|-------------|
| 1897 | **Problema resuelto**: Ahora usa directamente `$batch_size = \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('productos')` |
| 1898 | Añade logging detallado de la fuente y valor del batch size |
| 1909 | Usa BatchSizeHelper para clientes: `$batch_size = \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('clientes')` |
| 1919 | Usa BatchSizeHelper para pedidos: `$batch_size = \MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('pedidos')` |

### SyncProductos.php (Hecho)

| Línea | Descripción |
|-------|-------------|
| 136 | **Mejorado**: Cambiado el parámetro a `$batch_size = null` para usar BatchSizeHelper por defecto |
| 162-168 | **Mejorado**: Agrega lógica para obtener el valor desde BatchSizeHelper cuando $batch_size es null |
| 378-380 | Define método `sync_batch()` con valor por defecto de null y obtiene el valor desde BatchSizeHelper |
| 386-394 | Logging mejorado que incluye comparación con BatchSizeHelper y ConfigManager |
| 775-779 | **Problema resuelto**: El valor hardcodeado de 100 ha sido reemplazado por una llamada a `\MiIntegracionApi\Helpers\BatchSizeHelper::getBatchSize('productos')` |
| 782 | Se mantiene el filtro para compatibilidad, pero ahora se aplica al valor obtenido de BatchSizeHelper |

### BatchProcessor.php (Hecho)

| Línea | Descripción |
|-------|-------------|
| 175 | **Mejorado**: Define método `process()` con valor por defecto `null` para usar BatchSizeHelper |
| 177-179 | Obtiene el tamaño de lote desde BatchSizeHelper si no se proporciona uno específico |
| 182 | Valida el tamaño de lote usando `BatchSizeHelper::validateBatchSize('productos', $batch_size)` |
| 186-193 | Logging mejorado que incluye valores de BatchSizeHelper para diagnóstico |
| 200 | Usa el método centralizado `BatchSizeHelper::chunkItems($productos, $batch_size)` en vez de array_chunk |

## API y Conectores

### ApiConnector.php (Hecho)

| Línea | Descripción |
|-------|-------------|
| 1229 | Validación de parámetros inicio y fin con BatchSizeHelper |
| 1251 | Cálculo de tamaño de lote efectivo con `BatchSizeHelper::calculateEffectiveBatchSize()` |
| 1280 | Ajuste dinámico de timeout basado en tamaño de lote |
| 1312 | Configuración consistente para método POST |

## Problemas identificados

1. **Multiplicidad de fuentes de configuración**: Se usa tanto `ConfigManager` como `get_option()` directamente.
2. **Duplicación de opciones**: Existen dos opciones diferentes (`mi_integracion_api_batch_size_productos` y `mi_integracion_api_batch_size_products`).
3. **Valor hardcodeado crítico**: En `SyncProductos.php` línea ~767 hay un valor fijo de `100` que ignora la configuración del usuario.
4. **Inconsistencia en valores predeterminados**: Diferentes archivos usan diferentes valores por defecto (5, 20, 50, 100).
5. **Cálculo inconsistente de rangos**: El cálculo de `inicio` y `fin` varía entre diferentes partes del código.

## Recomendaciones

1. **Centralizar toda la gestión de configuración** en `ConfigManager`.
2. **Eliminar el uso directo** de `get_option()` para opciones de tamaño de lote.
3. **Estandarizar los nombres** de las opciones (elegir entre `productos` o `products`).
4. **Mantener una única fuente de verdad** para los valores por defecto.
5. **Documentar claramente** cómo se calcula el rango (inicio, fin) y el tamaño efectivo del lote.
6. **Agregar pruebas unitarias** que validen la correcta aplicación del tamaño de lote configurado.

## Plan de acción

1. ✅ Corregir el valor hardcodeado en `SyncProductos.php` (línea ~767).
2. ✅ Refactorizar `Sync_Manager.php` (Core) para usar `BatchSizeHelper`.
3. ✅ Refactorizar `ApiConnector.php` para usar `BatchSizeHelper`.
4. ✅ Crear funciones de utilidad para estandarizar el cálculo de rangos para la API (implementado en `BatchSizeHelper`).
5. Documentar la estandarización de nombres de opciones para referencia futura.

## Estandarización de Nombres de Opciones

Como parte de la refactorización, se ha establecido una clara estandarización para los nombres relacionados con el tamaño de lote:

### Nombres de opciones en WordPress

El helper utiliza un prefijo consistente para todas las opciones de tamaño de lote:

```php
const OPTION_PREFIX = 'mi_integracion_api_batch_size_';
```

Por lo tanto, las opciones utilizadas son:
- `mi_integracion_api_batch_size_productos`
- `mi_integracion_api_batch_size_clientes`
- `mi_integracion_api_batch_size_pedidos`
- `mi_integracion_api_batch_size_precios`

### Mapeo de entidades

Para garantizar la coherencia entre diferentes partes del código, se estableció un mapeo claro entre nombres en inglés y español:

```php
const ENTITY_MAPPINGS = [
    'products' => 'productos',
    'customers' => 'clientes',
    'orders' => 'pedidos',
    'prices' => 'precios',
];
```

Este mapeo permite que se pueda usar indistintamente `getBatchSize('products')` o `getBatchSize('productos')` y siempre se acceda a la misma configuración.

### Valores predeterminados y límites

Se han centralizado los valores predeterminados y límites para cada entidad:

| Entidad | Valor por defecto | Mínimo | Máximo |
|---------|------------------|--------|--------|
| productos/products | 20 | 1 | 200 |
| clientes/customers | 50 | 1 | 200 |
| pedidos/orders | 50 | 1 | 100 |
| precios/prices | 20 | 1 | 500 |

Esta estandarización ha eliminado inconsistencias y valores hardcodeados dispersos por la aplicación.

## Conclusión

La refactorización para centralizar la gestión del tamaño de lote (batch size) ha sido completada exitosamente. Todos los archivos que gestionaban el tamaño de lote ahora utilizan el helper centralizado, lo que ha eliminado inconsistencias, valores hardcodeados y comportamientos inesperados.

Los beneficios principales de esta refactorización son:

1. **Única fuente de verdad**: BatchSizeHelper es ahora el único punto donde se definen y obtienen los valores de tamaño de lote.
2. **Validación consistente**: Todos los valores son validados con los mismos límites, evitando problemas con la API.
3. **Facilidad de mantenimiento**: Para modificar límites o comportamientos, solo es necesario editar un archivo.
4. **Mejor logging y depuración**: Se ha añadido información de logging detallada para facilitar la identificación de problemas.
5. **Cálculo estandarizado de rangos**: Se utilizan métodos centralizados para calcular rangos y tamaños efectivos de lote.

La arquitectura resultante es más robusta, mantenible y fácil de extender en el futuro.
