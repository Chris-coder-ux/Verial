/**
 * Script para el panel de administración de caché HTTP
 * 
 * @package Mi_Integracion_API
 */

(function($) {
  'use strict';
    
  // Elementos DOM
  const $document = $(document);
  const $toggleCacheBtn = $('#mi-api-toggle-cache');
  const $updateTtlBtn = $('#mi-api-update-ttl');
  const $flushAllBtn = $('#mi-api-flush-all-cache');
  const $refreshStatsBtn = $('#mi-api-refresh-stats');
  const $flushEntityBtns = $('.mi-api-flush-entity');
  const $modal = $('#mi-api-confirm-modal');
  const $modalTitle = $('#mi-api-modal-title');
  const $modalMessage = $('#mi-api-modal-message');
  const $modalConfirmBtn = $('#mi-api-modal-confirm');
  const $modalCancelBtn = $('#mi-api-modal-cancel');
  const $modalClose = $('.mi-api-modal-close');
    
  // Funciones auxiliares
  function showNotice(message, type = 'success') {
    const noticeClass = type === 'error' ? 'notice-error' : 'notice-success';
    const $notice = $(`<div class="notice ${noticeClass} is-dismissible"><p>${message}</p></div>`);
        
    // Añadir al principio del panel
    $('.wrap.mi-api-cache-admin').prepend($notice);
        
    // Añadir botón de cerrar
    const $button = $('<button type="button" class="notice-dismiss"></button>');
    $button.on('click', function() {
      $notice.fadeOut(200, function() {
        $(this).remove();
      });
    });
    $notice.append($button);
        
    // Auto-cerrar después de 5 segundos
    setTimeout(function() {
      $notice.fadeOut(500, function() {
        $(this).remove();
      });
    }, 5000);
  }
    
  function showModal(title, message, callback) {
    $modalTitle.text(title);
    $modalMessage.text(message);
        
    $modalConfirmBtn.off('click').on('click', function() {
      $modal.hide();
      if (typeof callback === 'function') {
        callback();
      }
    });
        
    $modal.show();
  }
    
  function refreshPage() {
    location.reload();
  }
    
  function handleApiError(response) {
    let errorMessage = miApiCacheAdmin.i18n.error;
        
    if (response.responseJSON && response.responseJSON.message) {
      errorMessage = response.responseJSON.message;
    }
        
    showNotice(errorMessage, 'error');
  }
    
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
        
    // Activar/desactivar caché
    $toggleCacheBtn.on('click', function() {
      const currentStatus = $(this).data('status');
      const newStatus = currentStatus === 'enabled' ? 'false' : 'true';
            
      $.ajax({
        url: miApiCacheAdmin.ajaxUrl,
        method: 'POST',
        data: {
          action: 'mi_api_toggle_cache',
          nonce: miApiCacheAdmin.nonce,
          enabled: newStatus
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
  }
    
  // Inicializar cuando el DOM esté listo
  $(function() {
    init();
  });
    
})(jQuery);
