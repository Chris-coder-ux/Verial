# Plan de Acción para Corrección de Inconsistencias en API Verial

Este documento define un plan estructurado para abordar las inconsistencias identificadas en la integración con la API de Verial, minimizando el impacto en la lógica de sincronización por lotes existente.

## Fase 1: Preparación y Análisis ✓

- [ ] Crear ramas de desarrollo separadas para implementar cambios incrementales
- [ ] Revisar la documentación oficial de la API de Verial para confirmar los formatos correctos
- [✓] Analizar estructura de DTOs existentes (`BaseDTO`, `ProductDTO`, `CustomerDTO`, `OrderDTO`)
- [ ] Identificar tests automatizados existentes para la integración con la API
- [ ] Revisar tests unitarios para DTOs ya implementados
- [ ] Crear casos de prueba específicos para las inconsistencias identificadas
- [ ] Documentar el comportamiento actual de la sincronización por lotes (rendimiento, patrones, dependencias)

## Fase 2: Implementación de Capa de Abstracción ✓

- [ ] **Extender los DTOs existentes**
  - [✓] Utilizar `BaseDTO` existente como base para nuevos DTOs
  - [✓] Aprovechar `ProductDTO` ya implementado para normalizar campos de artículos Verial
  - [ ] Extender `ProductDTO` para incluir mapeo específico de campos `Id`/`ID_Articulo`
  - [ ] Crear `CondicionTarifaDTO` que extienda de `BaseDTO` para estandarizar condiciones
  - [ ] Crear `StockArticuloDTO` que extienda de `BaseDTO` para normalizar datos de stock
  - [ ] Ampliar la validación de datos existente para manejar diferentes formatos de entrada

- [ ] **Implementar capa de adaptadores**
  - [ ] Crear adaptador para respuestas de `GetArticulosWS` → `ProductDTO` extendido
  - [ ] Crear adaptador para respuestas de `GetCondicionesTarifaWS` → `CondicionTarifaDTO`
  - [ ] Crear adaptador para respuestas de `GetStockArticulosWS` → `StockArticuloDTO`
  - [ ] Añadir manejo de errores y logging de inconsistencias
  - [ ] Asegurar compatibilidad con `CustomerDTO` y `OrderDTO` ya existentes

## Fase 3: Refactorización de Endpoints (Sin Modificar Respuestas) ✓

- [ ] **Consolidar clases duplicadas**
  - [ ] Decidir estándar de nomenclatura uniforme (con o sin prefijo "Get")
  - [ ] Mantener clases con prefijo "Get" y añadir capa de compatibilidad
  - [ ] Implementar sistema de alias para prevenir errores en código existente
  - [ ] Añadir phpDoc con referencias cruzadas en los archivos afectados

- [ ] **Actualizar manejo de respuestas en endpoints**
  - [ ] Implementar detección automática de formato en respuestas
  - [ ] Centralizar la lógica de transformación en clases de servicio
  - [ ] Extender método `format_verial_response` para usar los DTOs existentes
  - [ ] Mantener compatibilidad con el formato de respuesta actual
  - [ ] Documentar los formatos de respuesta en comentarios de código
  - [ ] Añadir transformación bidireccional ID_Articulo ↔ Id con manejo de errores

## Fase 4: Actualización de Código Cliente (Sin Afectar Sincronización) ✓

- [ ] **Ampliar servicios existentes y añadir fachada**
  - [ ] Revisar clases de servicio existentes en `includes/Helpers/` que pudieran aprovecharse
  - [ ] Reforzar `ProductApiService` existente para usar DTOs extendidos
  - [ ] Crear adaptador `VerialApiAdapter` que normalice respuestas entre formatos inconsistentes
  - [ ] Implementar métodos específicos para cada operación de negocio
  - [ ] Utilizar DTOs ya creados como interfaz entre la API y la lógica de negocio

- [ ] **Actualizaciones graduales del código cliente**
  - [ ] Identificar puntos de acceso más simples para actualización inicial
  - [ ] Actualizar `includes/Core/ApiConnector.php` sin modificar firmas de métodos
  - [ ] Implementar métodos de compatibilidad que traduzcan entre formatos
  - [ ] Desarrollar pruebas unitarias para validar transformaciones de DTOs
  - [ ] Actualizar el código cliente para que use los DTOs mejorados

## Fase 5: Optimización de Sincronización ✓

- [ ] **Implementar mejoras sin modificar la lógica de sincronización**
  - [ ] Añadir caché de transformación para respuestas frecuentes
  - [ ] Implementar "error recovery" para inconsistencias en respuestas
  - [ ] Integrar con sistema de caché existente para minimizar transformaciones repetitivas
  - [ ] Mejorar reportes de errores relacionados con inconsistencias
  - [ ] Añadir métricas de rendimiento para cada tipo de adaptación

- [ ] **Pruebas de rendimiento**
  - [ ] Comprobar que la capa de abstracción y DTOs no impactan significativamente el rendimiento
  - [ ] Verificar que la sincronización por lotes funciona correctamente
  - [ ] Comparar tiempos de respuesta antes y después de los cambios
  - [ ] Optimizar adaptadores si se detectan problemas de rendimiento
  - [ ] Implementar lazy-loading de propiedades en DTOs para casos de uso masivos

## Fase 6: Documentación y Finalización ✓

- [ ] **Actualizar documentación**
  - [ ] Documentar los patrones implementados para solucionar inconsistencias
  - [ ] Extender la documentación de los DTOs existentes para incluir nuevos campos
  - [ ] Actualizar el manual de desarrollo del plugin
  - [ ] Proporcionar ejemplos de uso de los DTOs extendidos y servicios
  - [ ] Crear guía de migración para futuras refactorizaciones

- [ ] **Pruebas finales**
  - [ ] Verificar que todas las funcionalidades existentes siguen funcionando
  - [ ] Ejecutar pruebas unitarias para los nuevos DTOs y adaptadores
  - [ ] Ejecutar pruebas de integración completas
  - [ ] Validar la sincronización por lotes a gran escala
  - [ ] Comprobar que el manejo de errores con campos inconsistentes es adecuado

## Principios Clave

1. **No modificar la estructura de respuesta de la API**
   - Los cambios deben ser transparentes para el código existente
   - Mantener compatibilidad con APIs externas

2. **Implementar cambios incrementales**
   - Cada cambio debe ser pequeño y fácil de verificar
   - Evitar reescrituras masivas de código

3. **Mantener compatibilidad hacia atrás**
   - Asegurar que el código existente siga funcionando sin modificaciones
   - Utilizar patrones de adaptador y fachada para aislar cambios

4. **Priorizar la estabilidad de la sincronización por lotes**
   - No modificar la lógica principal del procesamiento por lotes
   - Optimizar adaptadores para evitar sobrecarga en procesamiento masivo

## Estimación de Esfuerzo

| Fase | Esfuerzo Estimado | Riesgo | Impacto en Sincronización |
|------|-------------------|--------|---------------------------|
| Preparación y Análisis | Bajo | Bajo | Ninguno |
| Implementación de Capa de Abstracción | Medio-Bajo | Bajo | Ninguno |
| Refactorización de Endpoints | Medio | Medio | Bajo |
| Actualización de Código Cliente | Medio-Alto | Medio | Medio |
| Optimización de Sincronización | Bajo | Bajo | Bajo |
| Documentación y Finalización | Bajo | Bajo | Ninguno |

*Nota: El esfuerzo estimado para la Fase 2 se reduce de "Medio" a "Medio-Bajo" gracias a la existencia de DTOs ya implementados.*

## Criterios de Éxito

- La sincronización por lotes mantiene el mismo rendimiento o mejora
- No se requieren cambios en la lógica de negocio existente
- Se eliminan los adaptadores ad-hoc dispersos por el código
- El código es más mantenible y consistente
- Las pruebas automatizadas verifican el manejo correcto de todas las variantes de respuesta
- Los DTOs ya existentes se integran perfectamente con los nuevos componentes
- Se establece un estándar de nomenclatura consistente a través de todo el sistema
