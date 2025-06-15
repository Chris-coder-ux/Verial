# Plan de Refactorización: Sincronización de Productos

Este documento detalla el plan de acción para refactorizar la lógica de sincronización de productos del plugin, con el objetivo de mejorar la robustez, el rendimiento y la flexibilidad.

## 1. Principios Guía de Implementación

Todo el trabajo de desarrollo se regirá por los siguientes principios:

-   **Compatibilidad con PHP 8.1:**
    -   Se utilizará `declare(strict_types=1);` en todos los archivos PHP.
    -   Se aprovecharán características modernas como `enum`s (para estados de sincronización), propiedades `readonly` y la promoción de propiedades en el constructor donde sea apropiado.
    -   Se asegurará la compatibilidad completa con la versión 8.1 de PHP, evitando funciones obsoletas.

-   **Estándar PSR-4:**
    -   Todas las nuevas clases seguirán estrictamente el estándar de autocarga PSR-4.
    -   Se revisará la configuración en `composer.json` para asegurar que los `namespaces` se mapean correctamente a las rutas del directorio `includes/`.
    -   El código existente se irá adaptando a este estándar a medida que se modifique.

## 2. Flujo de Sincronización End-to-End

```mermaid
graph TD
    subgraph Panel de Administración (Frontend)
        A[Botón "Iniciar Sincronización"] -- 1. Clic --> B{Llamada AJAX};
    end

    subgraph WordPress (Backend)
        B -- 2. Petición a /sync/start --> C[REST_API_Handler];
        C -- 3. Delega a --> D[REST_Controller];
        D -- 4. Llama a start_sync() --> E[Sync_Manager];
        E -- 5. Inicia el proceso y devuelve estado --> D;
        D -- 6. Devuelve respuesta JSON --> B;
    end

    subgraph "Procesamiento por Lotes (AJAX repetido)"
        F{Frontend JS} -- 7. Llama a /sync/batch --> G[REST_API_Handler];
        G -- 8. Delega a --> H[REST_Controller];
        H -- 9. Llama a process_next_batch() --> I[Sync_Manager];
        I -- "10. Procesa 1 lote (N+1 queries)" --> J[MapProduct & DB];
        J -- 11. Devuelve resultado --> I;
        I -- 12. Devuelve progreso --> H;
        H -- 13. Devuelve JSON --> F;
        F -- "14. Si no ha terminado, vuelve al paso 7" --> F;
    end
```

## 3. Plan de Acción Detallado

### Paso 1: Sistema de Errores Robusto

-   **Objetivo:** Crear un sistema de registro de errores detallado y accionable para evitar la pérdida silenciosa de datos.
-   **Acciones:**
    1.  **Crear Tabla en BD:** Implementar en `includes/Core/Installer.php` la creación de una tabla `wp_mi_api_sync_errors` con las columnas: `id` (PK), `sync_run_id`, `item_sku`, `item_data` (JSON), `error_code`, `error_message`, `timestamp`.
    2.  **Modificar `Sync_Manager`:** En `includes/Core/Sync_Manager.php`, el método `sync_products_from_verial()` deberá capturar los errores de `create_or_update_wc_product()` y, en lugar de solo contar, registrará una fila detallada en la nueva tabla.

### Paso 2: Optimización de Rendimiento (Solución al N+1)

-   **Objetivo:** Eliminar las consultas repetitivas a la base de datos dentro de los bucles para acelerar drásticamente la sincronización.
-   **Acciones:**
    1.  **Precarga en `Sync_Manager`:** En `includes/Core/Sync_Manager.php`, dentro de `sync_products_from_verial()` y antes del bucle `foreach`, añadir lógica para:
        -   Extraer todos los SKUs y los IDs de categoría de Verial del lote de productos.
        -   Realizar **una única consulta** para obtener los IDs de productos de WooCommerce existentes para esos SKUs.
        -   Realizar **una única consulta** para obtener todos los mapeos de categorías (`_verial_category_id`) existentes.
    2.  **Utilizar Caché de Lote:** Pasar los arrays de datos precargados a `create_or_update_wc_product()` y a `MapProduct::verial_to_wc()` para que los usen como caché local, evitando consultas a la BD en cada iteración.

### Paso 3: Flexibilidad de Configuración

-   **Objetivo:** Permitir a los administradores ajustar parámetros clave desde el panel de administración.
-   **Acciones:**
    1.  **Añadir Campos a la UI:** En `includes/Admin/SettingsPage.php` y/o `includes/Admin/SettingsRegistration.php`, añadir campos para "Tamaño del Lote de Sincronización" y "Campos de SKU de Verial (separados por coma)".
    2.  **Leer Opciones:**
        -   En `includes/Core/Sync_Manager.php`, reemplazar la constante `BATCH_SIZE` por una llamada a `get_option()`.
        -   En `includes/Helpers/MapProduct.php`, reemplazar el array de claves de SKU hardcodeado por una llamada a `get_option()`.

### Paso 4: Interfaz de Usuario para Errores

-   **Objetivo:** Proporcionar visibilidad y control sobre los errores de sincronización.
-   **Acciones:**
    1.  **Añadir Submenú:** En `includes/Admin/AdminMenu.php`, añadir un nuevo submenú "Registro de Errores de Sincronización".
    2.  **Crear Página de Errores:** Crear una nueva clase `includes/Admin/SyncErrorsPage.php` que renderice una tabla (`WP_List_Table`) con los datos de la tabla `wp_mi_api_sync_errors`.
    3.  **Añadir Endpoint de Reintento:** En `includes/Endpoints/REST_Controller.php`, añadir un endpoint `POST /sync/retry_errors` que llame a un nuevo método en `Sync_Manager` para procesar únicamente los SKUs que se encuentran en la tabla de errores.