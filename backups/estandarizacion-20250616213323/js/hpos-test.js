/**
 * Scripts para la página de prueba de compatibilidad HPOS
 *
 * @package Mi_Integracion_API
 * @since 1.0.0
 */

(function($) {
  'use strict';
    
  // Inicializar cuando el DOM esté listo
  $(document).ready(function() {
    // Referencias a elementos
    const $validationButton = $('#mi-run-validation');
    const $validationResults = $('#mi-validation-results');
    const $checkMetaButton = $('#mi-check-meta');
    const $migrateMetaButton = $('#mi-migrate-meta');
    const $migrationResults = $('#mi-migration-results');
        
    // Handler para el botón de validación
    $validationButton.on('click', function() {
      runValidation();
    });
        
    // Handler para el botón de comprobación de metadatos
    $checkMetaButton.on('click', function() {
      checkMetadata();
    });
        
    // Handler para el botón de migración de metadatos
    $migrateMetaButton.on('click', function() {
      migrateMetadata();
    });
        
    /**
         * Ejecuta la validación de compatibilidad con HPOS
         */
    function runValidation() {
      $validationButton.prop('disabled', true).text(mi_hpos_test.i18n.validating);
      $validationResults.html('').show();
            
      $.ajax({
        url: mi_hpos_test.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'mi_hpos_run_validation',
          nonce: mi_hpos_test.nonce
        },
        success: function(response) {
          $validationButton.prop('disabled', false).text('Ejecutar validación');
                    
          if (response.success) {
            renderValidationResults(response.data);
          } else {
            showError(response.data.message || 'Error al ejecutar la validación.');
          }
        },
        error: function() {
          $validationButton.prop('disabled', false).text('Ejecutar validación');
          showError('Error de comunicación con el servidor.');
        }
      });
    }
        
    /**
         * Comprueba metadatos que podrían necesitar migración
         */
    function checkMetadata() {
      $checkMetaButton.prop('disabled', true).text(mi_hpos_test.i18n.validating);
      $migrationResults.html('').show();
            
      $.ajax({
        url: mi_hpos_test.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'mi_hpos_migrate_meta',
          nonce: mi_hpos_test.nonce,
          dry_run: true
        },
        success: function(response) {
          $checkMetaButton.prop('disabled', false).text('Comprobar metadatos');
                    
          if (response.success) {
            renderMigrationResults(response.data, true);
                        
            // Habilitar el botón de migración si hay metadatos para migrar
            if (response.data.total > 0) {
              $migrateMetaButton.prop('disabled', false);
            } else {
              $migrateMetaButton.prop('disabled', true);
            }
          } else {
            showError(response.data.message || 'Error al comprobar metadatos.');
          }
        },
        error: function() {
          $checkMetaButton.prop('disabled', false).text('Comprobar metadatos');
          showError('Error de comunicación con el servidor.');
        }
      });
    }
        
    /**
         * Migra metadatos al formato HPOS
         */
    function migrateMetadata() {
      if (!confirm('¿Estás seguro de que deseas migrar los metadatos? Es recomendable hacer una copia de seguridad antes de continuar.')) {
        return;
      }
            
      $migrateMetaButton.prop('disabled', true).text(mi_hpos_test.i18n.migrating);
      $migrationResults.html('').show();
            
      $.ajax({
        url: mi_hpos_test.ajax_url,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'mi_hpos_migrate_meta',
          nonce: mi_hpos_test.nonce,
          dry_run: false
        },
        success: function(response) {
          $migrateMetaButton.prop('disabled', false).text('Migrar metadatos');
                    
          if (response.success) {
            renderMigrationResults(response.data, false);
          } else {
            showError(response.data.message || 'Error al migrar metadatos.');
          }
        },
        error: function() {
          $migrateMetaButton.prop('disabled', false).text('Migrar metadatos');
          showError('Error de comunicación con el servidor.');
        }
      });
    }
        
    /**
         * Renderiza los resultados de validación en la interfaz
         * 
         * @param {Object} data Datos de la validación
         */
    function renderValidationResults(data) {
      let html = '';
            
      // Resultado general
      const statusClass = data.status === 'success' ? 'mi-status-success' : 'mi-status-error';
      html += `<div class="mi-result-heading">
                <h3 class="mi-result-title">Resultado general</h3>
                <span class="mi-result-status ${statusClass}">${data.status === 'success' ? mi_hpos_test.i18n.success : mi_hpos_test.i18n.error}</span>
            </div>
            <p>${data.message}</p>`;
            
      // Resultados específicos
      html += '<div class="mi-validation-sections">';
            
      for (const [key, validation] of Object.entries(data.validations)) {
        const sectionStatusClass = validation.status === 'success' ? 'mi-status-success' : 'mi-status-error';
                
        html += `<div class="mi-result-item">
                    <div class="mi-result-heading">
                        <h4 class="mi-result-title">${getValidationTitle(key)}</h4>
                        <span class="mi-result-status ${sectionStatusClass}">${validation.status === 'success' ? mi_hpos_test.i18n.success : mi_hpos_test.i18n.error}</span>
                    </div>
                    <div class="mi-result-content">
                        <p>${validation.message}</p>`;
                
        // Si hay issues, mostrarlos
        if (validation.issues && validation.issues.length > 0) {
          html += '<ul class="mi-issues-list">';
          for (const issue of validation.issues) {
            const issueClass = issue.type === 'error' ? 'mi-issue-error' : (issue.type === 'warning' ? 'mi-issue-warning' : '');
            html += `<li class="mi-issue-item ${issueClass}">
                            ${issue.message}
                            ${issue.solution ? `<div class="mi-issue-solution">${issue.solution}</div>` : ''}
                        </li>`;
          }
          html += '</ul>';
        }
                
        html += '</div></div>';
      }
            
      html += '</div>';
            
      $validationResults.html(html);
    }
        
    /**
         * Renderiza los resultados de migración en la interfaz
         * 
         * @param {Object} data Datos de la migración
         * @param {boolean} isDryRun Si es una simulación o una ejecución real
         */
    function renderMigrationResults(data, isDryRun) {
      let html = '';
            
      // Resultado general
      const statusClass = data.status === 'success' ? 'mi-status-success' : 'mi-status-error';
      html += `<div class="mi-result-heading">
                <h3 class="mi-result-title">${isDryRun ? 'Comprobación de metadatos' : 'Resultado de migración'}</h3>
                <span class="mi-result-status ${statusClass}">${data.status === 'success' ? mi_hpos_test.i18n.success : mi_hpos_test.i18n.error}</span>
            </div>
            <p>${data.message}</p>`;
            
      if (data.status === 'success' && data.total > 0) {
        html += `<div class="mi-result-details">
                    <p><strong>Total de metadatos encontrados:</strong> ${data.total}</p>
                    ${!isDryRun ? `
                    <p><strong>Metadatos migrados:</strong> ${data.migrated}</p>
                    <p><strong>Errores:</strong> ${data.errors}</p>
                    ` : ''}
                </div>`;
      }
            
      $migrationResults.html(html);
    }
        
    /**
         * Muestra un mensaje de error en la interfaz
         * 
         * @param {string} message Mensaje de error
         */
    function showError(message) {
      const html = `<div class="mi-result-heading">
                <h3 class="mi-result-title">Error</h3>
                <span class="mi-result-status mi-status-error">${mi_hpos_test.i18n.error}</span>
            </div>
            <p>${message}</p>`;
            
      $validationResults.html(html).show();
    }
        
    /**
         * Obtiene un título legible para cada tipo de validación
         * 
         * @param {string} key Clave de la validación
         * @return {string} Título legible
         */
    function getValidationTitle(key) {
      const titles = {
        'meta_wrappers': 'Wrappers para metadatos',
        'direct_queries': 'Consultas SQL directas',
        'order_hooks': 'Hooks de pedidos'
      };
            
      return titles[key] || key;
    }
  });
})(jQuery);
