/**
 * Script para la página de verificación de conexión
 * Compatible con clases antiguas (verial-) y nuevas (mi-integracion-api-)
 */
jQuery(document).ready(function($) {
  function getRestUrlVerial() {
    // Compatibilidad: usar la nueva ruta si existe
    if (typeof mia_admin_ajax.rest_url_verial === 'string' && mia_admin_ajax.rest_url_verial.includes('/verial/check')) {
      return mia_admin_ajax.rest_url_verial;
    }
    // Fallback: construir la ruta correcta
    return '/wp-json/mi-integracion-api/v1/verial/check';
  }
  function getRestUrlWC() {
    if (typeof mia_admin_ajax.rest_url_wc === 'string' && mia_admin_ajax.rest_url_wc.includes('/woocommerce/check')) {
      return mia_admin_ajax.rest_url_wc;
    }
    return '/wp-json/mi-integracion-api/v1/woocommerce/check';
  }
  function showSpinner($button, show) {
    var $spinner = $button.find('.verial-spinner');
    if (show) {
      $spinner.show();
      $button.data('original-text', $button.contents().filter(function(){return this.nodeType===3;}).text());
      $button.contents().filter(function(){return this.nodeType===3;}).first().replaceWith('Probando... ');
    } else {
      $spinner.hide();
      var original = $button.data('original-text');
      if (original) {
        $button.contents().filter(function(){return this.nodeType===3;}).first().replaceWith(original);
      }
    }
  }
  function showPill($target, message, type) {
    var pill = $('<span></span>').addClass('verial-pill').addClass(type).text(message);
    $target.html(pill);
  }
  function showToast(type, title, message) {
    var $container = $('#verial-toast-container');
    if ($container.length === 0) return;
    var toast = $('<div></div>').addClass('verial-toast').addClass(type);
    toast.append($('<strong></strong>').text(title));
    toast.append($('<div></div>').text(message));
    $container.append(toast);
    setTimeout(function(){ toast.fadeOut(400, function(){ toast.remove(); }); }, 4000);
  }
  function bindConnectionTestHandlers() {
    // Log para depuración
    console.log('[Mi Integración API] Asociando handlers de prueba de conexión...');
    $('#mia-btn-test-connection-verial').off('click').on('click', function() {
      var button = $(this);
      var $result = $('#mia-test-connection-verial-result');
      button.prop('disabled', true);
      showSpinner(button, true);
      $result.html('');
      $.ajax({
        url: getRestUrlVerial(),
        method: 'GET',
        beforeSend: function(xhr) {
          xhr.setRequestHeader('X-WP-Nonce', mia_admin_ajax.nonce);
        },
        success: function(response) {
          showPill($result, response.message, response.success ? 'success' : 'error');
          showToast(response.success ? 'success' : 'error', response.success ? 'Conexión exitosa' : 'Error de conexión', response.message);
        },
        error: function(xhr, status, error) {
          showPill($result, mia_admin_ajax.i18n.error + ' ' + error, 'error');
          showToast('error', 'Error', mia_admin_ajax.i18n.error + ' ' + error);
        },
        complete: function() {
          button.prop('disabled', false);
          showSpinner(button, false);
        }
      });
    });
    $('#mia-btn-test-connection-woocommerce').off('click').on('click', function() {
      var button = $(this);
      var $result = $('#mia-test-connection-woocommerce-result');
      button.prop('disabled', true);
      showSpinner(button, true);
      $result.html('');
      $.ajax({
        url: getRestUrlWC(),
        method: 'GET',
        beforeSend: function(xhr) {
          xhr.setRequestHeader('X-WP-Nonce', mia_admin_ajax.nonce);
        },
        success: function(response) {
          showPill($result, response.message, response.success ? 'success' : 'error');
          showToast(response.success ? 'success' : 'error', response.success ? 'Conexión exitosa' : 'Error de conexión', response.message);
        },
        error: function(xhr, status, error) {
          var errorMessage = mia_admin_ajax.i18n.error + ': ' + error; // Mensaje genérico de fallback

          // Intentar obtener el mensaje de error de la respuesta JSON del servidor
          if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMessage = xhr.responseJSON.message;
          } else if (xhr.responseText) {
            // Si no es JSON directamente, intentar parsear como JSON
            try {
              var parsedResponse = JSON.parse(xhr.responseText);
              if (parsedResponse.message) {
                errorMessage = parsedResponse.message;
              }
            } catch (e) {
              // No es un JSON válido, se mantiene el mensaje genérico
            }
          }

          showPill($result, errorMessage, 'error');
          showToast('error', 'Error', errorMessage);
        },
        complete: function() {
          button.prop('disabled', false);
          showSpinner(button, false);
        }
      });
    });
  }

  // Asociar handlers al cargar
  bindConnectionTestHandlers();

  // Si hay pestañas dinámicas, volver a asociar handlers al mostrar la pestaña de test
  $(document).on('click', '.verial-tab-link', function() {
    var tab = $(this).data('tab');
    if (tab === 'verial-test-tab') {
      setTimeout(bindConnectionTestHandlers, 100); // Esperar a que el DOM esté visible
    }
  });
});