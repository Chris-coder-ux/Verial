# Plan de mejora para sincronización individual de productos Verial-WooCommerce

## Resumen ejecutivo

Este documento detalla un plan para implementar mejoras significativas en el sistema de sincronización individual de productos entre Verial y WooCommerce, basado en el análisis de implementaciones alternativas. El objetivo es aumentar la robustez, eficiencia y capacidad de recuperación del proceso, manteniendo total compatibilidad con la arquitectura existente.

## Índice
1. [Preparación y backups](#1-preparación-y-backups)
2. [Análisis de la implementación actual](#2-análisis-de-la-implementación-actual)
3. [Mejoras propuestas](#3-mejoras-propuestas)
4. [Plan de implementación](#4-plan-de-implementación)
5. [Pruebas y verificación](#5-pruebas-y-verificación)
6. [Despliegue](#6-despliegue)
7. [Consideraciones de compatibilidad](#7-consideraciones-de-compatibilidad)

---

## 1. Preparación y backups

### 1.1 Archivos a modificar

Principalmente nos centraremos en estos archivos:

- `/includes/Sync/Sync_Single_Product.php` - Clase principal de sincronización individual
- `/includes/Core/ApiConnector.php` - Métodos de conexión con API Verial
- `/assets/js/sync-single-product.js` - Frontend para sincronización individual

### 1.2 Creación de backups

Antes de realizar cualquier modificación:

```bash
# Crear directorio de backups si no existe
mkdir -p /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)

# Realizar copias de seguridad de los archivos principales
cp /home/christian/Documentos/Poyectos/mi-integracion-api/includes/Sync/Sync_Single_Product.php \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)/Sync_Single_Product.php.bak

cp /home/christian/Documentos/Poyectos/mi-integracion-api/includes/Core/ApiConnector.php \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)/ApiConnector.php.bak
   
cp /home/christian/Documentos/Poyectos/mi-integracion-api/assets/js/sync-single-product.js \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)/sync-single-product.js.bak
```

### 1.3 Respaldo de base de datos

Realizar una copia de seguridad de la base de datos antes de implementar cambios:

```bash
# Si se tiene acceso a WP-CLI
wp db export /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)/db-backup.sql
```

---

## 2. Análisis de la implementación actual

### 2.1 Estructura de la sincronización individual actual

La clase `Sync_Single_Product` implementa:
- Verificación de bloqueos (`SyncLock`)
- Búsqueda de productos en Verial por SKU o nombre
- Filtrado manual de resultados
- Conversión de datos Verial → WooCommerce
- Gestión de productos simples y variables

> **Nota importante**: Los productos en Verial no tienen un campo "SKU" directo como en WooCommerce. La implementación actual transforma el campo "ReferenciaBarras" de Verial al campo "SKU" en WooCommerce. Esta transformación es crítica para la correspondencia entre sistemas y debe mantenerse en cualquier mejora.

### 2.2 Puntos de mejora identificados

1. **Estrategias de búsqueda limitadas**:
   - Búsqueda principal basada en filtrado manual de resultados
   - No implementa búsquedas específicas por endpoints o parámetros optimizados

2. **Manejo de errores básico**:
   - Retorna arrays con 'success' y 'message'
   - Logging limitado de problemas y errores

3. **Optimización de rendimiento**:
   - No implementa pausas entre peticiones
   - No hay estrategia de almacenamiento en caché para búsquedas comunes

4. **Recuperación ante fallos**:
   - Liberación de bloqueos limitada
   - No reintenta solicitudes fallidas

---

## 3. Mejoras propuestas

### 3.1 Estrategias múltiples de búsqueda

Implementar una cascada de estrategias de búsqueda en `Sync_Single_Product`:

```php
// Estrategia 1: Búsqueda directa por ReferenciaBarras (equivalente a SKU en WooCommerce)
// Estrategia 2: Búsqueda por Código/Barcode
// Estrategia 3: Búsqueda por nombre exacto
// Estrategia 4: Búsqueda con filtros combinados (categoría + fabricante + nombre parcial)
```

**Beneficio**: Mayor probabilidad de encontrar el producto correcto, independientemente de los datos proporcionados, manteniendo la transformación clave de ReferenciaBarras → SKU.

### 3.2 Optimización del manejo de errores

Implementar un sistema de excepciones internas para identificar mejor los problemas:

```php
try {
    // Intentar estrategia 1
} catch (ProductNotFoundException $e) {
    // Registrar fallo y pasar a estrategia 2
    $logger->info('Estrategia 1 falló: ' . $e->getMessage());
    try {
        // Intentar estrategia 2
    } catch (...) {
        // etc.
    }
}
```

**Beneficio**: Identificación clara del punto de fallo y mejor capacidad de diagnóstico.

### 3.3 Enriquecimiento de datos de productos

Añadir cálculos automáticos de totales y métricas útiles:

```php
// Calcular stock total sumando las ubicaciones
$stockTotal = 0;
foreach ($stockData as $ubicacion) {
    $stockTotal += (float)($ubicacion['Stock'] ?? 0);
}
$productData['stock_total'] = $stockTotal;

// Añadir metadatos de sincronización
$productData['sync_timestamp'] = current_time('mysql');
$productData['sync_source'] = 'individual';
```

**Beneficio**: Datos más ricos para análisis y toma de decisiones.

### 3.4 Sistema de reintentos

Implementar reintentos automáticos para peticiones fallidas:

```php
$max_retries = 3;
$retry_delay = 1; // segundos

for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
    try {
        // Intentar petición a la API
        break; // Si tiene éxito, salir del bucle
    } catch (Exception $e) {
        if ($attempt >= $max_retries) {
            throw $e; // Re-lanzar la última excepción
        }
        $logger->warning("Intento $attempt fallido, reintentando en $retry_delay segundos.");
        sleep($retry_delay);
        $retry_delay *= 2; // Backoff exponencial
    }
}
```

**Beneficio**: Mayor robustez ante fallos temporales de red o API.

---

## 4. Plan de implementación

### 4.1 Fase 1: Mejorar ApiConnector

1. Reforzar los métodos de búsqueda en `ApiConnector.php`:
   - Implementar método `searchProductByReferenciaBarras` (para búsqueda por lo que será el SKU en WooCommerce)
   - Implementar método `searchProductByCodigo` (búsqueda alternativa por código interno)
   - Implementar método `searchProductByName`
   - Implementar método `searchProductByFilters`

2. Asegurar que la transformación de "ReferenciaBarras" a "SKU" se mantiene consistente en todos los métodos.

3. Añadir sistema de reintentos en llamadas críticas.

### 4.2 Fase 2: Refactorizar Sync_Single_Product

1. Implementar estrategias de búsqueda escalonadas:

```php
public function findProduct($sku = '', $nombre = '', $categoria = '', $fabricante = ''): array {
    $logger = new \MiIntegracionApi\helpers\Logger('single-product-search');
    $producto = null;
    
    // Estrategia 1: Búsqueda por SKU exacto si está disponible
    if (!empty($sku)) {
        $logger->info('Intentando búsqueda por SKU exacto: ' . $sku);
        $producto = $this->api_connector->searchProductByExactSku($sku);
        if ($producto) {
            $logger->info('Producto encontrado por SKU.');
            return $producto;
        }
    }
    
    // Estrategia 2: Búsqueda por nombre exacto
    // ...continuar con estrategias...
}
```

2. Mejorar manejo de datos y errores.

3. Implementar cálculo de totales y metadatos.

### 4.3 Fase 3: Optimización de frontend

1. Mostrar información más detallada del proceso de sincronización.
2. Implementar indicadores de progreso para cada fase.
3. Mejorar manejo de errores en UI.

---

## 5. Pruebas y verificación

### 5.1 Pruebas unitarias

Implementar pruebas para cada estrategia de búsqueda:

```php
function test_search_by_referencia_barras() {
    // Test para búsqueda por ReferenciaBarras (SKU en WooCommerce)
}

function test_search_by_codigo() {
    // Test para búsqueda por código
}

function test_search_by_name() {
    // Test para búsqueda por nombre
}

function test_sku_transformation() {
    // Test específico para verificar la correcta transformación de ReferenciaBarras a SKU
}

// etc.
```

### 5.2 Pruebas de integración

1. Verificar que la sincronización individual funciona con:
   - Solo SKU
   - Solo nombre
   - Combinaciones de parámetros
   - Datos parciales o ambiguos

2. Comparar resultados con la implementación anterior.

### 5.3 Pruebas de rendimiento

1. Medir tiempo de respuesta para diferentes estrategias de búsqueda.
2. Verificar comportamiento bajo carga (múltiples sincronizaciones).
3. Comprobar uso de memoria y recursos.

---

## 6. Despliegue

### 6.1 Plan de despliegue

1. Implementar cambios en ambiente de desarrollo.
2. Probar exhaustivamente todas las funcionalidades.
3. Desplegar en ambiente de staging para pruebas finales.
4. Despliegue a producción en horario de baja actividad.

### 6.2 Plan de reversión

En caso de problemas:
1. Restaurar los archivos de backup.
2. Restaurar la base de datos si es necesario.
3. Verificar que el sistema vuelve a su estado original.

---

## 7. Consideraciones de compatibilidad

### 7.1 Compatibilidad con PHP 8.1

- No utilizar funciones o características deprecadas en PHP 8.1
- Evitar el uso de `null` como valor predeterminado para parámetros tipados
- Utilizar sintaxis de retorno de tipo moderna
- Implementar tipado estricto donde sea apropiado

### 7.2 Cumplimiento PSR-4

- Mantener estructura de namespaces correcta
- Seguir convenciones de nomenclatura
- Un solo namespace por archivo
- Nombres de clases deben coincidir con nombres de archivo

### 7.3 Compatibilidad con hooks existentes

- Mantener puntos de acción (do_action) existentes
- Mantener filtros (apply_filters) existentes
- No alterar la estructura de datos retornados en hooks existentes

---

## Anexo: Diferencias entre implementaciones

### Archivos de referencia externos analizados

- `/home/christian/Descargas/manu/verial_individual_sync.php`: Clase principal para sincronización individual de productos con múltiples estrategias de búsqueda.
- `/home/christian/Descargas/manu/verial_sync_api.php`: Implementación de API REST para sincronización individual con soporte CORS y autenticación.
- `/home/christian/Descargas/manu/verial_sync_client.php`: Cliente de ejemplo para consumir la API de sincronización desde cualquier aplicación.

| Característica | Implementación actual | Implementación mejorada |
|----------------|----------------------|------------------------|
| Estrategias de búsqueda | Principalmente por filtrado | Cascada de búsquedas específicas |
| Manejo de errores | Arrays de resultado | Sistema de excepciones + arrays de resultado |
| Reintentos | No implementados | Implementados con backoff exponencial |
| Metadatos | Básicos | Enriquecidos con timestamps y cálculos |
| Logs | Básicos | Detallados por fase y estrategia |
| Bloqueos | Simple acquire/release | Sistema avanzado con timeout y liberación automática |

---

## Próximos pasos

1. Revisión del plan por el equipo
2. Ajustes según feedback
3. Calendarización de implementación
4. Asignación de responsabilidades
5. Inicio de desarrollo

---

**Autor:** GitHub Copilot  
**Fecha:** 16/06/2025  
**Versión:** 1.0
