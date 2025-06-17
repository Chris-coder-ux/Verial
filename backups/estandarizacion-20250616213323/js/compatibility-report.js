/**
 * Script para el informe de compatibilidad
 *
 * Maneja las pruebas de compatibilidad dinámicas para temas y plugins,
 * así como la visualización de resultados detallados.
 *
 * @package Mi_Integracion_API\Compatibility
 * @since 1.0.0
 */

(function($) {
  'use strict';
    
  // Cuando el DOM esté listo
  $(document).ready(function() {
    // Referencias a elementos del DOM
    const resultsContainer = $('#mi-compatibility-test-results');
    const resultsContent = resultsContainer.find('.results-content');
        
    // Configuración de mensajes de estado
    const statusMessages = {
      'compatible': miApiCompat.success,
      'partial': miApiCompat.warning,
      'incompatible': miApiCompat.error,
      'unknown': miApiCompat.unknown,
      'testing': miApiCompat.testing
    };
        
    // Handler para pruebas de compatibilidad
    $('.test-compatibility').on('click', function() {
      const $button = $(this);
      const type = $button.data('type');
      const slug = $button.data('slug');
            
      // Evitar pruebas múltiples simultáneas
      if ($button.hasClass('loading')) {
        return;
      }
            
      // Establecer estado de carga
      $button.addClass('loading').prop('disabled', true);
      $button.html(miApiCompat.testing);
            
      // Limpiar resultados anteriores
      resultsContainer.addClass('hidden');
      resultsContent.empty();
            
      // Preparar datos para la solicitud AJAX
      var data = {
        action: type === 'theme' ? 'mi_integracion_test_theme_compatibility' : 'mi_integracion_test_plugin_compatibility',
        nonce: miApiCompat.nonce,
        slug: slug
      };
            
      // Realizar solicitud AJAX
      $.post(ajaxurl, data, function(response) {
        // Restaurar estado del botón
        $button.removeClass('loading').prop('disabled', false);
        $button.html('Probar Compatibilidad');
                
        // Manejar respuesta
        if (response.success) {
          displayTestResults(response.data, type);
        } else {
          displayError(response.data.message);
        }
      }).fail(function() {
        // Restaurar estado del botón en caso de error
        $button.removeClass('loading').prop('disabled', false);
        $button.html('Probar Compatibilidad');
                
        // Mostrar mensaje de error
        displayError('Error al realizar la prueba de compatibilidad.');
      });
    });
        
    /**
         * Muestra los resultados de la prueba
         * 
         * @param {Object} data Datos del resultado
         * @param {string} type Tipo de prueba ('theme' o 'plugin')
         */
    function displayTestResults(data, type) {
      var html = '<div class="mi-test-results">';
            
      // Información básica
      html += '<h4>' + (type === 'theme' ? 'Tema: ' : 'Plugin: ') + data.name + '</h4>';
            
      // Estado de compatibilidad
      html += '<p><strong>Estado: </strong>';
      switch (data.status) {
      case 'compatible':
        html += '<span class="mi-status-badge mi-status-success">Compatible</span>';
        break;
      case 'partial':
        html += '<span class="mi-status-badge mi-status-warning">Parcialmente Compatible</span>';
        break;
      case 'incompatible':
        html += '<span class="mi-status-badge mi-status-error">Incompatible</span>';
        break;
      default:
        html += '<span class="mi-status-badge mi-status-unknown">No Probado</span>';
        break;
      }
      html += '</p>';
            
      // Versión probada (si existe)
      if (data.version_tested) {
        html += '<p><strong>Versión Probada: </strong>' + data.version_tested + '</p>';
      }
            
      // Fecha de prueba (si existe)
      if (data.test_date) {
        html += '<p><strong>Fecha de Prueba: </strong>' + data.test_date + '</p>';
      }
            
      // Equipo de prueba (si existe)
      if (data.tested_by) {
        html += '<p><strong>Probado Por: </strong>' + data.tested_by + '</p>';
      }
            
      // Notas (si existen)
      if (data.notes) {
        html += '<p><strong>Notas: </strong>' + data.notes + '</p>';
      }
            
      // Problemas detectados (si existen)
      if (data.issues && data.issues.length > 0) {
        html += '<div class="mi-compatibility-details">';
        html += '<p><strong>Problemas Detectados:</strong></p>';
        html += '<ul>';
                
        for (var i = 0; i < data.issues.length; i++) {
          html += '<li>' + data.issues[i] + '</li>';
        }
                
        html += '</ul>';
        html += '</div>';
      }
            
      // Soluciones implementadas (si existen)
      if (data.solutions && data.solutions.length > 0) {
        html += '<div class="mi-compatibility-details">';
        html += '<p><strong>Soluciones Implementadas:</strong></p>';
        html += '<ul>';
                
        for (var j = 0; j < data.solutions.length; j++) {
          html += '<li>' + data.solutions[j] + '</li>';
        }
                
        html += '</ul>';
        html += '</div>';
      }
            
      html += '</div>';
            
      // Establecer contenido y mostrar resultados
      resultsContent.html(html);
      resultsContainer.removeClass('hidden');
            
      // Desplazar a resultados
      $('html, body').animate({
        scrollTop: resultsContainer.offset().top - 50
      }, 500);
    }
        
    /**
         * Muestra un mensaje de error
         * 
         * @param {string} message Mensaje de error
         */
    function displayError(message) {
      var html = '<div class="notice notice-error">';
      html += '<p>' + message + '</p>';
      html += '</div>';
            
      resultsContent.html(html);
      resultsContainer.removeClass('hidden');
    }
        
    /**
         * Muestra los resultados de la prueba en el contenedor
         * 
         * @param {Object} results - Resultados de la prueba
         * @param {jQuery} container - Contenedor para mostrar resultados
         */
    function displayDetailedTestResults(results, container) {
      container.empty();
            
      // Crear resumen general
      const overallStatus = results.overall;
      const $summary = $('<div class="mi-api-test-summary"></div>');
            
      $summary.append(
        $('<div class="mi-api-test-header"></div>')
          .append('<h3>Resultados de la prueba</h3>')
          .append(
            $('<span class="mi-api-compatibility-badge ' + overallStatus + '"></span>')
              .text(statusMessages[overallStatus])
          )
      );
            
      // Crear tabla de resultados detallados
      const $table = $('<table class="mi-api-table mi-api-results-table"></table>');
      const $thead = $('<thead><tr><th>Prueba</th><th>Resultado</th><th>Detalles</th></tr></thead>');
      const $tbody = $('<tbody></tbody>');
            
      // Añadir filas para cada prueba
      Object.keys(results.tests).forEach(function(testKey) {
        const test = results.tests[testKey];
        const $row = $('<tr></tr>');
                
        $row.append($('<td></td>').text(test.name));
        $row.append(
          $('<td></td>').append(
            $('<span class="mi-api-compatibility-badge ' + test.status + '"></span>')
              .text(statusMessages[test.status])
          )
        );
        $row.append($('<td></td>').text(test.message));
                
        $tbody.append($row);
      });
            
      $table.append($thead).append($tbody);
      $summary.append($table);
            
      container.append($summary);
    }
        
    /**
         * Muestra un mensaje de error en el contenedor
         * 
         * @param {jQuery} container - Contenedor para mostrar el error
         * @param {string} message - Mensaje de error
         */
    function displayDetailedError(container, message) {
      container.empty().append(
        $('<div class="mi-api-error-message"></div>').text(message)
      );
    }
        
    /**
         * Crea un elemento de carga
         * 
         * @return {jQuery} Elemento de carga
         */
    function createLoadingElement() {
      return $('<div class="mi-api-loading"><span class="spinner is-active"></span> Realizando pruebas...</div>');
    }
        
    /**
         * Actualiza el estado del botón según el resultado
         * 
         * @param {jQuery} button - Botón a actualizar
         * @param {string} status - Estado de la prueba
         */
    function updateButtonState(button, status) {
      button.removeClass('testing');
            
      // Texto del botón basado en el estado
      if (status === 'success') {
        button.text('Prueba superada');
        button.addClass('button-success');
      } else if (status === 'warning') {
        button.text('Probar de nuevo');
      } else {
        button.text('Reintentar prueba');
        button.addClass('button-error');
      }
            
      // Restaurar el texto original después de un tiempo
      setTimeout(function() {
        button.removeClass('button-success button-error');
        button.text('Probar compatibilidad');
      }, 5000);
    }
        
    /**
         * Actualiza la insignia de compatibilidad del tema
         * 
         * @param {string} themeSlug - Slug del tema
         * @param {string} status - Nuevo estado
         */
    function updateThemeCompatibilityBadge(themeSlug, status) {
      const $badge = $('.mi-api-theme-details .mi-api-compatibility-badge');
            
      $badge.removeClass('success warning error unknown')
        .addClass(status)
        .text(statusMessages[status]);
    }
        
    /**
         * Actualiza la insignia de compatibilidad del plugin
         * 
         * @param {string} pluginSlug - Slug del plugin
         * @param {string} status - Nuevo estado
         */
    function updatePluginCompatibilityBadge(pluginSlug, status) {
      const $row = $('tr').find('[data-slug="' + pluginSlug + '"]').closest('tr');
      const $badge = $row.find('.mi-api-compatibility-badge');
            
      $badge.removeClass('success warning error unknown')
        .addClass(status)
        .text(statusMessages[status]);
    }
  });

})(jQuery);
