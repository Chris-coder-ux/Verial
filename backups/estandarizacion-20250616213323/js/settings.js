/**
 * Archivo JavaScript para Mi Integración API
 * Compatible con clases antiguas (verial-) y nuevas (mi-integracion-api-)
 */
jQuery(document).ready(function($) {
  // Selector compuesto para compatibilidad
  var settingsSelector = '.mi-integracion-api-settings, .verial-settings, .verial-card';
  
  // Validación de formulario de ajustes
  $(settingsSelector + ' form').on('submit', function(e) {
    var valid = true;
    $(this).find('input[required],select[required]').each(function() {
      if (!$(this).val()) {
        $(this).addClass('input-error');
        valid = false;
      } else {
        $(this).removeClass('input-error');
      }
    });
    if (!valid) {
      alert('Por favor, completa todos los campos obligatorios.');
      e.preventDefault();
    }
  });

  // Feedback visual para guardado (compatible con ambos sistemas de clases)
  $(settingsSelector + ' .button-primary, ' + settingsSelector + ' .verial-button, ' + settingsSelector + ' .mi-integracion-api-button').on('click', function() {
    $(this).addClass('saving');
    setTimeout(() => { $(this).removeClass('saving'); }, 1200);
  });
});
