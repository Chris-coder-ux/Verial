/**
 * Script para la página de endpoints
 * Compatible con clases antiguas (verial-) y nuevas (mi-integracion-api-)
 */
jQuery(document).ready(function($) {
  // Endpoint AJAX
  $('#mi-endpoint-form').on('submit', function(e) {
    e.preventDefault();
    var endpoint = $('#mi_endpoint_select').val();
    var param = $('#mi_endpoint_param').val();
    var $feedback = $('#mi-endpoint-feedback');
    var $table = $('#mi-endpoint-result-table');
    $feedback.html('<div class="notice notice-info"><p>' + miEndpointsPage.loading + '</p></div>');
    $table.hide().empty();
    $.ajax({
      url: miEndpointsPage.ajaxurl,
      type: 'POST',
      dataType: 'json',
      data: {
        action: 'mi_test_endpoint',
        endpoint: endpoint,
        param: param,
        nonce: miEndpointsPage.nonce // Aquí se añade el nonce
      },
      success: function(response) {
        if (response.success && response.data && response.data.length) {
          $feedback.html('<div class="notice notice-success is-dismissible"><p>OK</p></div>');
          var keys = Object.keys(response.data[0]);
          var html = '<thead><tr>';
          keys.forEach(function(k) { html += '<th>' + k + '</th>'; });
          html += '</tr></thead><tbody>';
          response.data.forEach(function(row) {
            html += '<tr>';
            keys.forEach(function(k) { html += '<td>' + (row[k] !== undefined ? row[k] : '') + '</td>'; });
            html += '</tr>';
          });
          html += '</tbody>';
          $table.html(html).show();
        } else if (response.success && response.data) {
          $feedback.html('<div class="notice notice-success is-dismissible"><p>' + JSON.stringify(response.data) + '</p></div>');
        } else {
          $feedback.html('<div class="notice notice-error is-dismissible"><p>' + (response.data && response.data.message ? response.data.message : miEndpointsPage.error) + '</p></div>');
        }
      },
      error: function() {
        $feedback.html('<div class="notice notice-error is-dismissible"><p>' + miEndpointsPage.error + '</p></div>');
      }
    });
  });
});
