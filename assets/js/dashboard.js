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
        console.error(`[${timeStamp}] Error AJAX:`, status, error);
        console.log('Código de respuesta HTTP:', xhr.status);
        console.log('Texto de respuesta:', xhr.responseText);
        console.log('Headers:', xhr.getAllResponseHeaders());
        inactiveProgressCounter++;
        
        if (xhr.status === 403) {
          console.error('Error 403 Forbidden: Problema de acceso o nonce inválido');
          // Si es un error 403, detener inmediatamente para evitar bloqueos de seguridad
          clearInterval(syncInterval);
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $feedback.removeClass('in-progress').text('Error de permisos (403). Por favor, recarga la página o inicia sesión nuevamente.');
          $syncStatusContainer.hide();
        }
        
        if (inactiveProgressCounter > 3) {
          clearInterval(syncInterval);
          $syncBtn.prop('disabled', false);
          $batchSizeSelector.prop('disabled', false);
          $feedback.removeClass('in-progress').text('Error de conexión. Por favor, verifica tu conexión e inténtalo de nuevo.');
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
        console.error(`[${timeStamp}] Error AJAX al iniciar sincronización:`, status, error);
        console.log('Código de respuesta HTTP:', xhr.status);
        console.log('Texto de respuesta:', xhr.responseText);
        
        $feedback.removeClass('in-progress').text('Error de comunicación: ' + xhr.status + ' ' + error);
        $syncBtn.prop('disabled', false);
        $syncStatusContainer.hide();
      }
    });
  });

  $cancelBtn.on('click', function(e) {
    e.preventDefault();
    if (!confirm('¿Seguro que deseas cancelar la sincronización?')) return;
    
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
});
