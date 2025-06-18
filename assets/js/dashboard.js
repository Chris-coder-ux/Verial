/**
 * Verificador de compatibilidad y diagnóstico
 * Este código se ejecuta automáticamente al cargar la página para verificar
 * la compatibilidad del navegador y detectar problemas comunes
 */
(function() {
  // Registrar la carga correcta del script
  console.info('Inicializando Dashboard Mi Integración API - ' + new Date().toISOString());
  
  const browserInfo = {
    userAgent: navigator.userAgent,
    vendor: navigator.vendor,
    platform: navigator.platform,
    jQueryVersion: jQuery ? jQuery.fn.jquery : 'No disponible',
    online: navigator.onLine ? 'Conectado' : 'Sin conexión',
    ajaxAvailable: typeof jQuery !== 'undefined' && typeof jQuery.ajax === 'function',
    cookiesEnabled: navigator.cookieEnabled,
    localStorage: typeof localStorage !== 'undefined',
    screenSize: window.innerWidth + 'x' + window.innerHeight
  };
  
  console.info('Información del navegador:', browserInfo);
  
  // Verificar compatibilidad
  const errors = [];
  
  // Verificar si jQuery está disponible
  if (typeof jQuery === 'undefined') {
    errors.push('jQuery no está cargado. La sincronización no funcionará correctamente.');
  } else {
    // Verificar si AJAX está disponible
    if (typeof jQuery.ajax !== 'function') {
      errors.push('jQuery.ajax no está disponible. La sincronización fallará.');
    }
  }
  
  // Verificar si estamos online
  if (!navigator.onLine) {
    errors.push('El navegador está en modo offline. La sincronización requiere conexión a internet.');
  }
  
  // Verificar si las cookies están habilitadas
  if (!navigator.cookieEnabled) {
    errors.push('Las cookies están deshabilitadas. WordPress requiere cookies para las sesiones AJAX.');
  }
  
  // Verificar si tenemos acceso al objeto miIntegracionApiDashboard
  if (typeof miIntegracionApiDashboard === 'undefined') {
    errors.push('No se ha podido cargar la configuración del plugin. Falta el objeto miIntegracionApiDashboard.');
  } else {
    if (!miIntegracionApiDashboard.nonce) {
      errors.push('No se ha encontrado el token de seguridad (nonce). Las peticiones AJAX fallaran.');
    }
    if (!miIntegracionApiDashboard.rest_url) {
      console.warn('No se ha encontrado la URL de REST API. Algunas funciones avanzadas podrían no estar disponibles.');
    }
  }
  
  // Si hay errores, mostrarlos en la consola y posiblemente en la UI
  if (errors.length > 0) {
    console.error('Problemas detectados que pueden afectar el funcionamiento:');
    errors.forEach(error => console.error('- ' + error));
    
    // Si jQuery está disponible, mostrar alerta en la UI
    if (typeof jQuery === 'function') {
      jQuery(function($) {
        if ($('#mi-dashboard-messages').length) {
          const $alert = $('<div class="notice notice-error mi-dashboard-diagnostic"></div>');
          $alert.html('<p><strong>Problemas detectados:</strong></p><ul></ul>');
          errors.forEach(error => {
            $alert.find('ul').append('<li>' + error + '</li>');
          });
          $('#mi-dashboard-messages').append($alert);
        }
      });
    }
  } else {
    console.info('✓ Todos los requisitos del sistema están correctos.');
  }
  
  // Verificar configuración de AJAX
  if (jQuery.ajaxSettings) {
    console.info('Configuración AJAX global:', {
      timeout: jQuery.ajaxSettings.timeout,
      async: jQuery.ajaxSettings.async,
      cache: jQuery.ajaxSettings.cache,
      contentType: jQuery.ajaxSettings.contentType,
      headers: jQuery.ajaxSettings.headers || 'No configurados'
    });
  }
  
  // Verificar que ajaxurl está definido
  if (typeof ajaxurl === 'undefined') {
    console.error('¡ALERTA! Variable ajaxurl no está definida. La sincronización AJAX fallará.');
  } else {
    console.info('URL AJAX:', ajaxurl);
  }
  
  // Verificar que existen las variables necesarias
  if (typeof miIntegracionApiDashboard === 'undefined' || !miIntegracionApiDashboard.nonce) {
    console.error('¡ALERTA! Variables de configuración incompletas. La sincronización fallará.');
  } else {
    console.info('Variables de configuración OK');
  }
  
  // Prueba de CORS solo para registro (no bloquear)
  try {
    const testXhr = new XMLHttpRequest();
    testXhr.open('GET', ajaxurl, true);
    // Añadir listener para registrar errores CORS
    testXhr.onerror = function() {
      console.error('Posible error CORS detectado. Esto puede causar problemas con AJAX.');
    };
    // No es necesario enviar realmente la solicitud
    // testXhr.send();
  } catch(e) {
    console.error('Error al inicializar XMLHttpRequest:', e);
  }
})();

/**
 * Archivo JavaScript para Mi Integración API
 * Compatible con clases antiguas (verial-) y nuevas (mi-integracion-api-)
 */
jQuery(document).ready(function($) {
  // Selector compuesto para compatibilidad
  var dashboardSelector = '.mi-integracion-api-dashboard, .verial-dashboard';
  
  // Recarga de métricas vía AJAX
  $(dashboardSelector + ' .reload-metrics').on('click', function(e) {
    e.preventDefault();
    var $btn = $(this);
    $btn.prop('disabled', true);
    $.post(ajaxurl, { action: 'mi_integracion_api_reload_metrics' }, function(response) {
      if (response.success) {
        // Actualizar ambos tipos de selectores
        $('.dashboard-metric, .verial-stat-value, .mi-integracion-api-stat-value').text(response.data.metric);
      }
      $btn.prop('disabled', false);
    });
  });

  // Tooltip para tarjetas (compatibilidad con ambos sistemas de clases)
  $(dashboardSelector + ' .dashboard-card, ' + dashboardSelector + ' .verial-stat-card, ' + dashboardSelector + ' .mi-integracion-api-stat-card').hover(function() {
    $(this).addClass('hovered');
  }, function() {
    $(this).removeClass('hovered');
  });
  
  // --- Sincronización masiva de productos ---
  var $syncBtn = $('#mi-batch-sync-products');
  var $feedback = $('#mi-batch-sync-feedback');
  var $progressBar = $('.sync-progress-bar');
  var $progressInfo = $('.sync-status-info p');
  var $cancelBtn = $('#mi-cancel-sync');
  var $syncStatusContainer = $('#mi-sync-status-details');
  var syncInterval = null;

  // Log de depuración para verificar objeto localizado
  if (typeof miIntegracionApiDashboard === 'undefined') {
    console.error('miIntegracionApiDashboard no está definido');
  } else {
    console.log('miIntegracionApiDashboard:', miIntegracionApiDashboard);
  }

  // Contador para verificar actividad
  var inactiveProgressCounter = 0;
  var lastProgressValue = 0;
  
  function checkSyncProgress() {
    const timeStamp = new Date().toISOString();
    console.log(`[${timeStamp}] Llamando a mia_sync_progress...`);
    
    // Debug información para diagnóstico
    console.log('URL AJAX:', ajaxurl);
    console.log('miIntegracionApiDashboard:', miIntegracionApiDashboard);
    
    // Verificar si tenemos un nonce válido
    if (!miIntegracionApiDashboard || !miIntegracionApiDashboard.nonce) {
      console.error('Error: nonce no disponible');
      clearInterval(syncInterval);
      $syncBtn.prop('disabled', false);
      $feedback.removeClass('in-progress').text('Error: falta el token de seguridad. Por favor, recarga la página.');
      $syncStatusContainer.hide();
      return;
    }
    
    $.ajax({
      url: ajaxurl,
      type: 'POST',
      data: { 
        action: 'mia_sync_progress', 
        nonce: miIntegracionApiDashboard.nonce 
      },
      success: function(response) {
        const timeStamp = new Date().toISOString();
        console.log(`[${timeStamp}] Respuesta de mia_sync_progress:`, response);
        // Mostrar headers y status completo para diagnóstico
        console.log('Respuesta completa:', this);
        if (response.success && response.data) {
          var porcentaje = response.data.porcentaje || 0;
          var mensaje = response.data.mensaje || '';
          var estadisticas = response.data.estadisticas || {};
          
          // Asegurar que el contenedor de progreso es visible
          $syncStatusContainer.css('display', 'block');
          
          // Actualizar la barra de progreso con animación suave
          const anchoActual = Math.max(5, porcentaje) + '%';
          console.log(`Actualizando barra de progreso a ${anchoActual}`, {
            contenedorVisible: $syncStatusContainer.is(':visible'),
            contenedorDisplay: $syncStatusContainer.css('display'),
            barraAncho: $progressBar.css('width')
          });
          $progressBar.css('width', anchoActual);
          $progressInfo.text(mensaje + (porcentaje ? ' (' + porcentaje + '%)' : ''));
          
          // Comprobar si el progreso está estancado
          if (porcentaje === lastProgressValue) {
            inactiveProgressCounter++;
          } else {
            inactiveProgressCounter = 0;
            lastProgressValue = porcentaje;
          }
          
          // Si el progreso se ha estancado durante más de 10 intentos (20 segundos), detenemos el intervalo
          if (inactiveProgressCounter > 10) {
            clearInterval(syncInterval);
            $syncBtn.prop('disabled', false);
            $batchSizeSelector.prop('disabled', false);
            $feedback.removeClass('in-progress').text('La sincronización se ha detenido debido a inactividad.');
            $syncStatusContainer.hide();
            console.warn('Sincronización detenida por inactividad después de 10 intentos sin cambios en el progreso.');
            return;
          }
          
          if (porcentaje >= 100) {
            clearInterval(syncInterval);
            $syncBtn.prop('disabled', false);
            $batchSizeSelector.prop('disabled', false);
            $feedback.removeClass('in-progress').text('¡Sincronización completada!');
            $syncStatusContainer.hide();
          }
        } else {
          // En caso de error en la respuesta AJAX
          inactiveProgressCounter++;
          if (inactiveProgressCounter > 5) {
            clearInterval(syncInterval);
            $syncBtn.prop('disabled', false);
            $batchSizeSelector.prop('disabled', false);
            $feedback.removeClass('in-progress').text('Error en la sincronización. Por favor, inténtalo de nuevo.');
            $syncStatusContainer.hide();
            console.error('Sincronización detenida tras 5 errores consecutivos en la respuesta AJAX.');
          }
        }
      },
      error: function(xhr, status, error) {
        const timeStamp = new Date().toISOString();
        console.error(`[${timeStamp}] Error AJAX al verificar progreso:`, status, error);
        console.log('Código de respuesta HTTP:', xhr.status);
        console.log('Texto de respuesta:', xhr.responseText || 'No hay respuesta');
        console.log('Headers:', xhr.getAllResponseHeaders() || 'No hay headers');
        console.log('readyState:', xhr.readyState);
        inactiveProgressCounter++;
        
        // Verificar si el navegador está offline
        if (!navigator.onLine) {
          clearInterval(syncInterval);
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $feedback.removeClass('in-progress').html('<strong>Error:</strong> No hay conexión a internet. Por favor, verifique su conexión y vuelva a intentarlo.');
          $syncStatusContainer.hide();
          return;
        }
        
        if (xhr.status === 403) {
          console.error('Error 403 Forbidden: Problema de acceso o nonce inválido');
          // Si es un error 403, detener inmediatamente para evitar bloqueos de seguridad
          clearInterval(syncInterval);
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $feedback.removeClass('in-progress').html('<div class="mi-api-error"><strong>Error de permisos (403):</strong> Por favor, recarga la página o inicia sesión nuevamente.</div>');
          $syncStatusContainer.hide();
          return;
        }
        
        // Si es un error de timeout (readyState 0 o status 0 con error vacío), dar un mensaje específico
        if ((xhr.readyState === 0 && xhr.status === 0) || (xhr.status === 0 && !error)) {
          if (inactiveProgressCounter > 2) {
            console.warn(`Posible timeout o servidor sobrecargado (intento ${inactiveProgressCounter})`);
            
            // Solo después del tercer intento, mostrar mensaje de servicio ocupado
            if (inactiveProgressCounter === 3) {
              $feedback.removeClass('in-progress').addClass('warning').html(
                '<div class="mi-api-warning"><strong>El servidor está tardando en responder</strong><p>La sincronización podría estar funcionando en segundo plano. ' + 
                'Espere unos minutos o verifique los registros para confirmar el estado.</p></div>'
              );
            }
            
            // Si hay demasiados errores consecutivos, detener la sincronización
            if (inactiveProgressCounter > 5) {
              clearInterval(syncInterval);
              $syncBtn.prop('disabled', false);
              $batchSizeSelector.prop('disabled', false);
              $feedback.removeClass('in-progress warning').html(
                '<div class="mi-api-error"><strong>Error de comunicación:</strong> El servidor no responde después de varios intentos. ' +
                '<p>La sincronización podría estar funcionando en segundo plano o haberse detenido. Verifique los registros del sistema.</p>' + 
                '<button id="mi-api-retry-sync" class="button">Reintentar verificación</button></div>'
              );
              
              // Añadir manejador para el botón de reintento
              $('#mi-api-retry-sync').on('click', function() {
                checkSyncProgress();
                $feedback.addClass('in-progress').text('Verificando estado de la sincronización...');
              });
              
              $syncStatusContainer.hide();
            }
          }
        } else if (inactiveProgressCounter > 3) {
          // Para otros tipos de errores, después de 3 intentos fallidos
          clearInterval(syncInterval);
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $feedback.removeClass('in-progress').html(
            '<div class="mi-api-error"><strong>Error de conexión:</strong> No se puede verificar el progreso. ' + 
            (xhr.status ? `Código HTTP: ${xhr.status}` : 'Verifique la conexión al servidor.') +
            '<p>Intente recargar la página o esperar unos minutos.</p></div>'
          );
          $syncStatusContainer.hide();
        }
      }
    });
  }

  // También capturamos el selector de tamaño de lote
  var $batchSizeSelector = $('#mi-batch-size');

  $syncBtn.on('click', function(e) {
    e.preventDefault();
    const batchSize = parseInt($batchSizeSelector.val()) || 20; // Valor por defecto: 20
    console.log('Click en sincronizar productos en lote. Tamaño de lote:', batchSize);
    
    // Verificar si hay mensaje de confirmación y mostrar un diálogo
    if (miIntegracionApiDashboard && miIntegracionApiDashboard.confirmSync) {
      if (!confirm(miIntegracionApiDashboard.confirmSync)) {
        console.log('Sincronización cancelada por el usuario');
        return;
      }
    } else {
      // Si no hay mensaje específico, usar uno genérico
      if (!confirm('¿Estás seguro de que deseas iniciar una sincronización manual ahora?')) {
        console.log('Sincronización cancelada por el usuario');
        return;
      }
    }
    
    $syncBtn.prop('disabled', true);
    $batchSizeSelector.prop('disabled', true); // También deshabilitamos el selector
    $feedback.addClass('in-progress').text('Sincronización iniciada...');
    
    // Asegurar que la barra de progreso esté visible y funcionando
    $syncStatusContainer.css('display', 'block');
    $progressBar.css('width', '5%'); // Comenzar con un 5% para que se vea algo
    $progressInfo.text('Preparando sincronización...');
    
    // Lanzar AJAX para iniciar la sincronización batch
    const timeStamp = new Date().toISOString();
    console.log(`[${timeStamp}] Iniciando sincronización batch...`);
    console.log('URL AJAX:', ajaxurl);
    console.log('Datos a enviar:', { 
      action: 'mi_integracion_api_sync_products_batch', 
      nonce: miIntegracionApiDashboard.nonce,
      batch_size: batchSize
    });
    
    $.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'json',
      // Añadir headers para evitar problemas de caché
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      },
      // Añadir timeout para evitar esperas infinitas
      timeout: 60000, // 60 segundos
      data: { 
        action: 'mi_integracion_api_sync_products_batch', 
        nonce: miIntegracionApiDashboard.nonce,
        batch_size: batchSize
      },
      success: function(response) {
        const timeStamp = new Date().toISOString();
        console.log(`[${timeStamp}] Respuesta de mi_integracion_api_sync_products_batch:`, response);
        if (response.success) {
          $feedback.text('Sincronización en progreso...');
          syncInterval = setInterval(checkSyncProgress, 2000);
        } else {
          $feedback.removeClass('in-progress').text('Error al iniciar la sincronización: ' + (response.data?.message || 'Error desconocido'));
          $syncBtn.prop('disabled', false);
          $syncStatusContainer.hide();
        }
      },
      error: function(xhr, status, error) {
        const timeStamp = new Date().toISOString();
        
        // Información detallada sobre el error en la consola
        console.warn(`[${timeStamp}] Error AJAX al iniciar sincronización:`);
        console.warn('- Estado:', status || 'No disponible');
        console.warn('- Mensaje de error:', error || 'No disponible');
        console.warn('- Código de respuesta HTTP:', xhr.status);
        console.warn('- Texto de respuesta:', xhr.responseText || 'Vacío');
        console.warn('- Headers de respuesta:', xhr.getAllResponseHeaders());
        console.warn('- readyState:', xhr.readyState);
        console.warn('- URL de solicitud:', ajaxurl);
        
        // Verificar si el navegador está offline
        if (!navigator.onLine) {
          $feedback.removeClass('in-progress').html('<strong>Error:</strong> No hay conexión a internet. Por favor, verifique su conexión y vuelva a intentarlo.');
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $syncStatusContainer.hide();
          return;
        }
        
        // Si el error es vacío, intentemos proporcionar más contexto
        let errorMessage = 'Error de comunicación';
        let errorDetails = '';
        let suggestionText = '';
        
        if (xhr.status === 0) {
            if (error === '') {
                errorMessage = 'Error de red o timeout de la solicitud';
                errorDetails = 'La conexión con el servidor falló o tardó demasiado en responder.';
                suggestionText = '<p class="mi-api-suggestion">Sugerencias: Verifique su conexión a internet, que el servidor no esté sobrecargado o si las credenciales de API son correctas.</p>';
                console.warn('Posible problema: Timeout o conexión rechazada');
            } else {
                errorMessage = 'Error: ' + error;
            }
        } else if (xhr.status == 404) {
            errorMessage = 'URL no encontrada (404)';
            errorDetails = 'La dirección del endpoint AJAX no existe.';
            suggestionText = '<p class="mi-api-suggestion">El plugin podría estar mal instalado o un conflicto con otro plugin está bloqueando los endpoints AJAX.</p>';
        } else if (xhr.status == 500) {
            errorMessage = 'Error interno del servidor (500)';
            errorDetails = 'Se produjo un error en el servidor. Revise los logs de PHP para más detalles.';
            suggestionText = '<p class="mi-api-suggestion">Verifique los archivos de registro en la carpeta logs/ del plugin o el error_log de WordPress.</p>';
        } else if (xhr.status == 403) {
            errorMessage = 'Acceso denegado (403)';
            errorDetails = 'No tienes permiso para realizar esta acción o el nonce de seguridad ha expirado.';
            suggestionText = '<p class="mi-api-suggestion">Intente recargar la página para renovar el token de seguridad.</p>';
        }
        
        // Intentar analizar la respuesta si es JSON
        try {
          if (xhr.responseText && xhr.responseText.trim() !== '') {
            const jsonResponse = JSON.parse(xhr.responseText);
            if (jsonResponse.data && jsonResponse.data.message) {
              errorDetails = jsonResponse.data.message;
            } else if (jsonResponse.message) {
              errorDetails = jsonResponse.message;
            }
            
            // Mostrar detalles técnicos en consola para depuración
            console.log('Detalles JSON del error:', jsonResponse);
            
            // Mostrar información técnica para administradores
            if (jsonResponse.technical_details) {
              const technicalInfo = '<details class="mi-api-technical-details">' + 
                '<summary>Detalles técnicos</summary>' +
                '<code>' + jsonResponse.technical_details + '</code>' +
                (jsonResponse.file && jsonResponse.line ? '<p>Archivo: ' + jsonResponse.file + ', Línea: ' + jsonResponse.line + '</p>' : '') +
              '</details>';
              suggestionText += technicalInfo;
            }
          }
        } catch(e) {
          console.log('Error al parsear la respuesta JSON:', e);
          // Si el error es de sintaxis JSON, mostrar parte del texto crudo
          if (xhr.responseText) {
            console.log('Primeros 100 caracteres de respuesta:', xhr.responseText.substring(0, 100));
          }
        }
        
        const displayMessage = errorDetails ? `${errorMessage}: ${errorDetails}` : errorMessage;
        $feedback.removeClass('in-progress').html('<div class="mi-api-error"><strong>Error:</strong> ' + displayMessage + suggestionText + '</div>');
        $syncBtn.prop('disabled', false);
        $batchSizeSelector.prop('disabled', false);
        $syncStatusContainer.hide();
      }
    });
  });

  $cancelBtn.on('click', function(e) {
    e.preventDefault();
    
    // Usar el mensaje localizado si está disponible
    const confirmMessage = miIntegracionApiDashboard && miIntegracionApiDashboard.confirmCancel 
        ? miIntegracionApiDashboard.confirmCancel 
        : '¿Seguro que deseas cancelar la sincronización?';
    
    if (!confirm(confirmMessage)) return;
    
    const timeStamp = new Date().toISOString();
    console.log(`[${timeStamp}] Click en cancelar sincronización`);
    console.log('Datos a enviar:', { action: 'mia_sync_cancel', nonce: miIntegracionApiDashboard.nonce });
    
    $.ajax({
      url: ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: { 
        action: 'mia_sync_cancel', 
        nonce: miIntegracionApiDashboard.nonce 
      },
      success: function(response) {
        const timeStamp = new Date().toISOString();
        console.log(`[${timeStamp}] Respuesta de mia_sync_cancel:`, response);
        if (response.success) {
          clearInterval(syncInterval);
          $feedback.removeClass('in-progress').text('Sincronización cancelada.');
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $syncStatusContainer.hide();
        } else {
          $feedback.removeClass('in-progress').text('Error al cancelar: ' + (response.data?.mensaje || 'Error desconocido'));
          console.error('Error al cancelar:', response);
        }
      },
      error: function(xhr, status, error) {
        const timeStamp = new Date().toISOString();
        console.error(`[${timeStamp}] Error al cancelar la sincronización:`, status, error);
        console.log('Código de respuesta HTTP:', xhr.status);
        console.log('Texto de respuesta:', xhr.responseText);
        
        $feedback.removeClass('in-progress').text('Error al comunicarse con el servidor. Código: ' + xhr.status);
        // No deshabilitamos el botón para permitir intentar de nuevo
      }
    });
  });

  // Si la página carga y hay una sync en curso, mostrar el bloque de estado
  if ($feedback.hasClass('in-progress')) {
    $syncStatusContainer.show();
  } else {
    $syncStatusContainer.hide();
  }
  
  // Función de diagnóstico para ayudar a detectar problemas con llamadas AJAX
  function diagnosticAjaxCall() {
    console.log('Ejecutando diagnóstico AJAX...');
    
    // Información del sistema
    const diagnosticInfo = {
      browser: navigator.userAgent,
      timestamp: new Date().toISOString(),
      jqueryVersion: jQuery.fn.jquery,
      ajaxSettings: { ...jQuery.ajaxSettings },
      miApiConfig: { ...miIntegracionApiDashboard },
      screenSize: window.innerWidth + 'x' + window.innerHeight,
      memory: window.performance && window.performance.memory ? 
        {
          usedHeapSize: Math.round(window.performance.memory.usedJSHeapSize / (1024*1024)) + 'MB',
          totalHeapSize: Math.round(window.performance.memory.totalJSHeapSize / (1024*1024)) + 'MB'
        } : 'No disponible'
    };
    
    // Eliminar información sensible
    if (diagnosticInfo.miApiConfig && diagnosticInfo.miApiConfig.nonce) {
      diagnosticInfo.miApiConfig.nonce = 'REDACTED';
    }
    
    console.log('Información de diagnóstico:', diagnosticInfo);
    
    // Realizar una llamada AJAX simple para verificar la conexión
    console.log('Realizando prueba de conexión AJAX...');
    
    jQuery.ajax({
      url: ajaxurl,
      type: 'POST',
      data: { 
        action: 'mi_integracion_api_test_api',
        nonce: miIntegracionApiDashboard.nonce,
        diagnostic: true
      },
      success: function(response) {
        console.log('✓ Prueba AJAX completada con éxito:', response);
        console.log('La conexión AJAX funciona correctamente.');
      },
      error: function(xhr, status, error) {
        console.error('✗ Error en la prueba AJAX:', {
          status: status,
          error: error,
          responseText: xhr.responseText,
          readyState: xhr.readyState
        });
        console.log('Hay un problema con la conexión AJAX. Verifique las configuraciones del servidor o posibles plugins que estén bloqueando AJAX.');
      }
    });
  }

  // Añadir diagnóstico al contexto global para poder ejecutarlo desde la consola
  if (typeof window !== 'undefined') {
    window.miApiDiagnostico = {
      testAjax: diagnosticAjaxCall,
      info: function() {
        console.log('Mi Integración API - Diagnóstico de sistema');
        console.log('-----------------------------------------');
        console.log('Versión de la página: ' + (miIntegracionApiDashboard ? miIntegracionApiDashboard.version : 'No disponible'));
        console.log('jQuery cargado: ' + (typeof jQuery !== 'undefined'));
        console.log('jQuery versión: ' + (jQuery ? jQuery.fn.jquery : 'No disponible'));
        console.log('Nonce disponible: ' + (miIntegracionApiDashboard && miIntegracionApiDashboard.nonce ? 'Sí' : 'No'));
        console.log('Estado de conexión: ' + (navigator.onLine ? 'Online' : 'Offline'));
        console.log('-----------------------------------------');
        console.log('Para probar conexión AJAX: miApiDiagnostico.testAjax()');
      }
    };
    
    // Registrar instrucciones en la consola
    console.log('💡 Para diagnóstico: ejecute miApiDiagnostico.info() en la consola');
  }
});
