# Plan de mejora para sincronización por lotes de productos Verial-WooCommerce

## Resumen ejecutivo

Este documento detalla un plan para implementar mejoras significativas en el sistema de sincronización masiva por lotes entre Verial y WooCommerce. El objetivo es aumentar la eficiencia, robustez y escalabilidad del proceso, incorporando las mejores prácticas identificadas en implementaciones alternativas mientras se mantiene la compatibilidad con la arquitectura existente.

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

- `/includes/Core/Sync_Manager.php` - Clase principal de sincronización por lotes
- `/includes/Core/ApiConnector.php` - Métodos de conexión con API Verial
- `/includes/Sync/SyncProductos.php` - Implementación específica para productos
- `/assets/js/dashboard.js` - Frontend para sincronización masiva

### 1.2 Creación de backups

Antes de realizar cualquier modificación:

```bash
# Crear directorio de backups si no existe
mkdir -p /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch

# Realizar copias de seguridad de los archivos principales
cp /home/christian/Documentos/Poyectos/mi-integracion-api/includes/Core/Sync_Manager.php \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch/Sync_Manager.php.bak

cp /home/christian/Documentos/Poyectos/mi-integracion-api/includes/Core/ApiConnector.php \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch/ApiConnector.php.bak
   
cp /home/christian/Documentos/Poyectos/mi-integracion-api/includes/Sync/SyncProductos.php \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch/SyncProductos.php.bak
   
cp /home/christian/Documentos/Poyectos/mi-integracion-api/assets/js/dashboard.js \
   /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch/dashboard.js.bak
```

### 1.3 Respaldo de base de datos

Realizar una copia de seguridad de la base de datos antes de implementar cambios:

```bash
# Si se tiene acceso a WP-CLI
wp db export /home/christian/Documentos/Poyectos/mi-integracion-api/backups/$(date +%Y%m%d)_batch/db-backup.sql
```

---

## 2. Análisis de la implementación actual

### 2.1 Estructura de la sincronización por lotes actual

El sistema actual se basa en:

- `Sync_Manager`: Gestiona el proceso general de sincronización por lotes
- Sistema de lotes secuenciales con transients de WordPress
- Guardado de estado en opciones de WordPress
- Registro de historial de sincronización
- Sistema de bloqueos para evitar sincronizaciones simultáneas

> **Nota importante**: Los productos en Verial no tienen un campo "SKU" directo como en WooCommerce. En la implementación actual se transforma el campo "ReferenciaBarras" de Verial al campo "SKU" en WooCommerce. Esta transformación es crítica para la correspondencia entre sistemas y debe mantenerse en cualquier mejora al proceso por lotes.

### 2.2 Flujo de sincronización actual

1. Inicialización de sincronización (`start_sync`)
2. División en lotes (`batch_size`)
3. Procesamiento secuencial de lotes (`process_next_batch`)
4. Actualización de estado entre lotes
5. Finalización y limpieza

### 2.3 Puntos de mejora identificados

1. **Rendimiento y escalabilidad**:
   - Tamaño de lote fijo
   - No implementa pausas adaptativas entre lotes
   - Posibles problemas con conjuntos de datos grandes

2. **Recuperación y robustez**:
   - Mecanismo básico de recuperación ante errores
   - No hay reintentos automáticos para peticiones fallidas
   - Sistema de bloqueo simple sin gestión avanzada de timeouts

3. **Optimización de recursos**:
   - Alto consumo de memoria en lotes grandes
   - Sin compresión de datos para almacenamiento eficiente

4. **Monitorización y diagnóstico**:
   - Logging limitado del progreso de cada lote
   - Difícil identificación de productos problemáticos

---

## 3. Mejoras propuestas

### 3.1 Optimización del procesamiento por lotes

Implementar tamaño de lote adaptativo y pausas dinámicas:

```php
/**
 * Calcula el tamaño óptimo de lote basado en el rendimiento anterior
 */
private function calculate_optimal_batch_size($last_batch_metrics) {
    $default_size = (int) $this->config_manager->get('mia_sync_batch_size', 100);
    
    // Si no hay métricas previas, usar valor por defecto
    if (empty($last_batch_metrics)) {
        return $default_size;
    }
    
    $last_execution_time = $last_batch_metrics['execution_time'] ?? 0;
    $last_memory_usage = $last_batch_metrics['memory_peak'] ?? 0;
    $target_execution_time = 10; // segundos objetivo por lote
    
    // Ajustar tamaño basado en tiempo de ejecución
    if ($last_execution_time > 0) {
        $time_ratio = $target_execution_time / $last_execution_time;
        $adjusted_size = (int) ($default_size * $time_ratio);
        
        // Limitar entre 10 y 500 items por lote
        return max(10, min(500, $adjusted_size));
    }
    
    return $default_size;
}

/**
 * Calcula la pausa adaptativa entre lotes
 */
private function calculate_adaptive_pause($last_batch_metrics) {
    $base_pause = 100000; // 0.1 segundos (microsegundos)
    
    // Si el último lote fue muy pesado, aumentar la pausa
    if (!empty($last_batch_metrics['memory_peak'])) {
        $memory_ratio = $last_batch_metrics['memory_peak'] / 50000000; // 50MB como referencia
        if ($memory_ratio > 1) {
            $base_pause *= $memory_ratio;
        }
    }
    
    // Pausa máxima de 2 segundos
    return min(2000000, (int) $base_pause);
}
```

### 3.2 Sistema avanzado de reintentos

Implementar reintentos con backoff exponencial para peticiones fallidas:

```php
/**
 * Ejecuta una llamada API con reintentos y backoff exponencial
 */
private function execute_with_retry($callback, $max_retries = 3, $initial_delay = 1) {
    $logger = new \MiIntegracionApi\helpers\Logger('sync-retries');
    $attempt = 1;
    $delay = $initial_delay;
    
    while (true) {
        try {
            $logger->debug("Intento $attempt de $max_retries");
            $result = call_user_func($callback);
            
            // Si llegamos aquí, la operación tuvo éxito
            if ($attempt > 1) {
                $logger->info("Operación recuperada en el intento $attempt");
            }
            
            return $result;
            
        } catch (\Exception $e) {
            $logger->warning("Fallo en intento $attempt: " . $e->getMessage());
            
            // Si es el último intento, propagar error
            if ($attempt >= $max_retries) {
                $logger->error("Máximo de reintentos alcanzado ($max_retries)");
                throw $e;
            }
            
            // Esperar con backoff exponencial
            $sleep_time = $delay * pow(2, $attempt - 1);
            $logger->debug("Esperando $sleep_time segundos antes del siguiente intento");
            sleep($sleep_time);
            
            $attempt++;
        }
    }
}
```

### 3.3 Monitorización y métricas avanzadas

Implementar un sistema de métricas detalladas para cada lote:

```php
/**
 * Recolecta métricas detalladas de un lote
 */
private function collect_batch_metrics($batch_number, $start_time, $items) {
    $execution_time = microtime(true) - $start_time;
    $memory_usage = memory_get_usage(true);
    $memory_peak = memory_get_peak_usage(true);
    
    $metrics = [
        'batch_number' => $batch_number,
        'execution_time' => $execution_time,
        'memory_usage' => $memory_usage,
        'memory_peak' => $memory_peak,
        'items_processed' => count($items),
        'timestamp' => time(),
    ];
    
    // Almacenar métricas para análisis
    $this->store_batch_metrics($metrics);
    
    // Registrar en log
    $logger = new \MiIntegracionApi\helpers\Logger('sync-metrics');
    $logger->info("Métricas lote #$batch_number: " . json_encode($metrics));
    
    return $metrics;
}
```

### 3.4 Compresión de datos para almacenamiento eficiente

Implementar compresión para grandes conjuntos de datos intermedios:

```php
/**
 * Almacena datos de sincronización con compresión opcional
 */
private function store_sync_data($key, $data, $use_compression = true) {
    if ($use_compression && function_exists('gzcompress')) {
        $serialized = serialize($data);
        $compressed = gzcompress($serialized, 6); // Nivel 6 de compresión
        set_transient($key, $compressed, DAY_IN_SECONDS);
        set_transient($key . '_compressed', true, DAY_IN_SECONDS);
        return strlen($compressed);
    } else {
        set_transient($key, $data, DAY_IN_SECONDS);
        set_transient($key . '_compressed', false, DAY_IN_SECONDS);
        return is_array($data) ? count($data) : strlen(serialize($data));
    }
}

/**
 * Recupera datos de sincronización (descomprime si es necesario)
 */
private function retrieve_sync_data($key) {
    $is_compressed = get_transient($key . '_compressed');
    $data = get_transient($key);
    
    if ($is_compressed && function_exists('gzuncompress')) {
        return unserialize(gzuncompress($data));
    }
    
    return $data;
}
```

### 3.5 Sistema avanzado de gestión de bloqueos

Mejora del sistema de bloqueo con verificación de validez:

```php
/**
 * Sistema avanzado de adquisición de bloqueo
 */
public static function acquire_lock($lock_name, $timeout = 300, $force = false) {
    $lock_key = 'mia_sync_lock_' . $lock_name;
    $lock_info = get_transient($lock_key);
    
    // Verificar si hay un bloqueo existente
    if ($lock_info && !$force) {
        $lock_time = $lock_info['time'] ?? 0;
        $lock_pid = $lock_info['pid'] ?? 0;
        
        // Si el bloqueo es reciente y no expirado
        if (time() - $lock_time < $timeout) {
            // Verificar si el proceso que adquirió el bloqueo sigue activo
            if (self::is_process_running($lock_pid)) {
                return false; // Bloqueo válido, otro proceso está activo
            }
            // Si el proceso ya no existe pero el bloqueo no ha expirado
            // lo liberamos automáticamente (zombie lock)
        }
    }
    
    // Adquirir el bloqueo con información extendida
    $lock_info = [
        'time' => time(),
        'pid' => function_exists('getmypid') ? getmypid() : 0,
        'server' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'requester' => wp_get_current_user()->user_login ?? 'system',
    ];
    
    // Establecer el bloqueo
    set_transient($lock_key, $lock_info, $timeout);
    return true;
}

/**
 * Verifica si un proceso está en ejecución
 */
private static function is_process_running($pid) {
    if (!function_exists('getmypid') || !$pid) {
        return false;
    }
    
    // En sistemas tipo Unix
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }
    
    // En Windows intentamos con otra técnica
    if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
        $output = [];
        exec("tasklist /FI \"PID eq $pid\"", $output);
        return count($output) > 1;
    }
    
    return false; // No podemos verificar
}
```

---

## 4. Plan de implementación

### 4.1 Fase 1: Refactorización del core de Sync_Manager

1. Implementar sistema adaptativo de tamaño de lote y pausas
2. Agregar sistema de reintentos con backoff exponencial
3. Refactorizar almacenamiento de datos con soporte para compresión
4. Mejorar sistema de bloqueos con detección de procesos

### 4.2 Fase 2: Mejoras en el procesamiento de datos

1. Optimizar método `process_batch_items` para mejor rendimiento
2. Implementar colas de prioridad para elementos críticos
3. Añadir mecanismo de tracking individual por artículo
4. Mejorar la validación y filtrado de datos antes del procesamiento

### 4.3 Fase 3: Optimización del frontend

1. Añadir visualización en tiempo real del progreso detallado
2. Implementar dashboard con métricas de rendimiento
3. Agregar controles para ajustar parámetros de sincronización en tiempo real
4. Mejorar notificación de errores con detalles específicos

### 4.4 Fase 4: Implementación del sistema de métricas

1. Crear sistema de recolección de métricas
2. Implementar almacenamiento optimizado para análisis
3. Desarrollar visualización de métricas históricas
4. Añadir sistema de alertas basado en umbrales de rendimiento

---

## 5. Pruebas y verificación

### 5.1 Pruebas unitarias

Implementar tests para componentes clave:

```php
function test_adaptive_batch_sizing() {
    // Test para verificar que el tamaño de lote se ajusta correctamente
}

function test_batch_processing_performance() {
    // Test para medir rendimiento del procesamiento de lotes
}

function test_data_compression() {
    // Test para verificar compresión y descompresión de datos
}
```

### 5.2 Pruebas de integración

1. Verificar sincronización completa end-to-end:
   - Prueba con 10 productos
   - Prueba con 100 productos
   - Prueba con 1000+ productos

2. Pruebas de recuperación:
   - Simular fallos de red durante sincronización
   - Forzar timeouts en peticiones API
   - Verificar recuperación automática

### 5.3 Pruebas de rendimiento

1. Medir tiempo total de sincronización vs. implementación anterior
2. Evaluar consumo de memoria y CPU durante el proceso
3. Verificar eficiencia de almacenamiento con datos comprimidos
4. Medir escalabilidad con conjuntos de datos grandes (10,000+ productos)

---

## 6. Despliegue

### 6.1 Plan de despliegue

1. Implementar en ambiente de desarrollo
2. Ejecutar pruebas exhaustivas
3. Implementar en ambiente de staging y realizar pruebas con datos reales
4. Planificar ventana de mantenimiento para despliegue en producción
5. Desplegar cambios y verificar funcionamiento

### 6.2 Plan de reversión

En caso de problemas:
1. Restaurar archivos de backup
2. Restaurar base de datos si es necesario
3. Limpiar transients y opciones relacionados con la sincronización
4. Verificar que el sistema vuelve a su estado original

---

## 7. Consideraciones de compatibilidad

### 7.1 Compatibilidad con PHP 8.1

- Implementar tipado estricto en parámetros y retornos
- Evitar uso de funciones deprecadas
- Preferir expresiones de comparación estrictas (`===` vs `==`)
- Utilizar nombres de constantes en mayúsculas
- Aprovechar características de PHP 8.1 como named arguments y readonly properties donde sea apropiado

### 7.2 Cumplimiento PSR-4

- Mantener estructura de namespaces consistente
- Un solo namespace por archivo
- Nombres de clases deben coincidir con nombres de archivo
- Seguir convenciones de nombres:
  - PascalCase para clases y traits
  - SCREAMING_SNAKE_CASE para constantes
  - camelCase para métodos y propiedades

### 7.3 Compatibilidad con extensiones

- Mantener hooks existentes
- No cambiar formato de datos en acciones y filtros
- Documentar nuevos hooks para desarrolladores de extensiones

---

## Anexo: Características de la implementación ejemplo de Verial

### Archivos de referencia externos analizados

- `/home/christian/Descargas/advanced_sync_example.php`: Implementación avanzada de uso del sincronizador por lotes con configuraciones personalizadas y callbacks.
- `/home/christian/Descargas/verial_product_sync.php`: Clase principal para la sincronización de productos por lotes con el servicio web Verial.
- `/home/christian/Descargas/config.php`: Archivo de configuración centralizada para la sincronización.

De estos archivos ejemplo, podemos extraer estas características valiosas:

1. **Sistema de callbacks por lote**:
   ```php
   $callback = function($articulos, $inicio, $fin) {
       $this->processBatch($articulos, $inicio, $fin);
   };
   
   $resultado = $this->sync->batchSyncProducts(
       $this->config['filters']['fecha_desde'],
       $this->config['filters']['hora_desde'],
       $this->config['sync']['include_stock'],
       $this->config['sync']['include_images'],
       $callback
   );
   ```

2. **Pausa configurable entre lotes**:
   ```php
   // Pausa opcional entre lotes para no sobrecargar el servidor
   usleep(100000); // 0.1 segundos
   ```

3. **Sistema de configuración centralizado**:
   ```php
   // Configuración de sincronización
   'sync' => [
       'batch_size' => 100, // Número de productos por lote
       'include_stock' => true,
       'include_images' => false,
       'pause_between_batches' => 100000,
       'max_retries' => 3,
   ],
   ```

4. **Procesamiento completo de artículos**:
   ```php
   private function processArticle($articulo, $includeStock = true, $includeImages = false) {
       $articuloProcesado = $articulo;
       
       if ($includeStock && isset($articulo['Id'])) {
           $stock = $this->getStockArticulos($articulo['Id']);
           $articuloProcesado['Stock'] = $stock;
       }
       
       if ($includeImages && isset($articulo['Id'])) {
           $imagenes = $this->getImagenesArticulos($articulo['Id']);
           $articuloProcesado['Imagenes'] = $imagenes;
       }
       
       return $articuloProcesado;
   }
   ```

5. **Log detallado del proceso**:
   ```php
   $this->log("Procesado artículo: $nombre (ID: $id)");
   ```

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
