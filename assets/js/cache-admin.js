/**
 * Script para el panel de administración de caché HTTP
 * 
 * @package Mi_Integracion_API
 */

jQuery(document).ready(function($) {
  'use strict';
    
  // Referencias a elementos del DOM
  const $toggleBtn = $('#mi-api-toggle-cache');
  const $updateTtlBtn = $('#mi-api-update-ttl');
  const $flushAllBtn = $('#mi-api-flush-all');
  const $refreshStatsBtn = $('#mi-api-refresh-stats');
  const $updateStorageBtn = $('#mi-api-update-storage');
  const $storageMethod = $('#mi-api-storage-method');
  const $flushEntityBtns = $('.mi-api-flush-entity');
  const $modal = $('#mi-api-confirm-modal');
  const $modalTitle = $('#mi-api-modal-title');
  const $modalMessage = $('#mi-api-modal-message');
  const $modalConfirmBtn = $('#mi-api-modal-confirm');
  const $modalCancelBtn = $('#mi-api-modal-cancel');
  const $modalClose = $('.mi-api-modal-close');
    
  // Función para mostrar notificaciones
  function showNotice(message, type = 'success') {
    const notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
    $('.wrap h1').after(notice);
    setTimeout(() => notice.fadeOut(400, function() { $(this).remove(); }), 3000);
  }
    
  // Función para manejar errores de API
  function handleApiError(xhr, status, error) {
    console.error('Error en solicitud AJAX:', error);
    showNotice(miApiCacheAdmin.i18n.error || 'Error en la solicitud', 'error');
  }
    
  // Función para refrescar la página
  function refreshPage() {
    window.location.reload();
  }
    
  // Función para mostrar modal de confirmación
  function showModal(title, message, callback) {
    if (confirm(message)) {
      callback();
    }
  }
    
  // Toggle caché
  $toggleBtn.on('click', function() {
    const currentStatus = $(this).data('status');
    const newStatus = currentStatus === 'enabled' ? 'disabled' : 'enabled';
    
    showModal(
      'Confirmar acción',
      miApiCacheAdmin.i18n.confirmToggle,
      function() {
        $.ajax({
          url: miApiCacheAdmin.ajaxUrl,
          method: 'POST',
          data: {
            action: 'mi_api_toggle_cache',
            nonce: miApiCacheAdmin.nonce,
            enabled: newStatus === 'enabled'
          },
          success: function(response) {
            if (response.success) {
              showNotice(response.data.message);
              setTimeout(refreshPage, 1500);
            } else {
              showNotice(response.data, 'error');
            }
          },
          error: handleApiError
        });
      }
    );
  });
    
  // Actualizar TTL
  $updateTtlBtn.on('click', function() {
    const ttl = $('#mi-api-cache-ttl').val();
    
    if (!ttl || ttl < 60) {
      showNotice('El tiempo de vida debe ser al menos 60 segundos.', 'error');
      return;
    }
    
    $.ajax({
      url: miApiCacheAdmin.ajaxUrl,
      method: 'POST',
      data: {
        action: 'mi_api_update_cache_ttl',
        nonce: miApiCacheAdmin.nonce,
        ttl: ttl
      },
      success: function(response) {
        if (response.success) {
          showNotice(response.data.message);
        } else {
          showNotice(response.data, 'error');
        }
      },
      error: handleApiError
    });
  });
    
  // Actualizar método de almacenamiento
  $updateStorageBtn.on('click', function() {
    const method = $storageMethod.val();
    
    $.ajax({
      url: miApiCacheAdmin.ajaxUrl,
      method: 'POST',
      data: {
        action: 'mi_api_update_storage_method',
        nonce: miApiCacheAdmin.nonce,
        method: method
      },
      success: function(response) {
        if (response.success) {
          showNotice(response.data.message);
          setTimeout(refreshPage, 1500);
        } else {
          showNotice(response.data, 'error');
        }
      },
      error: handleApiError
    });
  });
    
  // Limpiar toda la caché
  $flushAllBtn.on('click', function() {
    showModal(
      'Confirmar acción',
      miApiCacheAdmin.i18n.confirmFlush,
      function() {
        $.ajax({
          url: miApiCacheAdmin.ajaxUrl,
          method: 'POST',
          data: {
            action: 'mi_api_flush_cache',
            nonce: miApiCacheAdmin.nonce
          },
          success: function(response) {
            if (response.success) {
              showNotice(response.data.message || response.data);
              setTimeout(refreshPage, 1500);
            } else {
              showNotice(response.data, 'error');
            }
          },
          error: handleApiError
        });
      }
    );
  });
    
  // Actualizar estadísticas
  $refreshStatsBtn.on('click', refreshPage);
    
  // Limpiar caché por entidad
  $flushEntityBtns.on('click', function() {
    const entity = $(this).data('entity');
    
    showModal(
      'Confirmar acción',
      miApiCacheAdmin.i18n.confirmEntityFlush,
      function() {
        $.ajax({
          url: miApiCacheAdmin.ajaxUrl,
          method: 'POST',
          data: {
            action: 'mi_api_invalidate_entity_cache',
            nonce: miApiCacheAdmin.nonce,
            entity: entity
          },
          success: function(response) {
            if (response.success) {
              showNotice(response.data.message);
              setTimeout(refreshPage, 1500);
            } else {
              showNotice(response.data, 'error');
            }
          },
          error: handleApiError
        });
      }
    );
  });
    
  // Configuración avanzada
  $('#mi-api-cache-advanced-settings').on('submit', function() {
    // No necesitamos hacer nada especial, el formulario se envía normalmente
  });
    
  // Inicializar eventos
  function init() {
    // Cerrar modal
    $modalCancelBtn.on('click', function() {
      $modal.hide();
    });
        
    $modalClose.on('click', function() {
      $modal.hide();
    });
        
    $(window).on('click', function(e) {
      if ($(e.target).is($modal)) {
        $modal.hide();
      }
    });
  }
    
  // Inicializar cuando el DOM esté listo
  $(function() {
    init();
  });
    
})(jQuery);
