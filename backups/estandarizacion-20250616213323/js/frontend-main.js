/**
 * Archivo JavaScript principal para el frontend de Mi Integración API.
 * Consolida la funcionalidad de frontend.js y public.js, utilizando las utilidades de utils.js.
 */
(function($) {
    'use strict';

    // Asegurarse de que miIntegracionApi y miApiUtils estén disponibles
    if (typeof miIntegracionApi === 'undefined' || !miIntegracionApi.ajaxUrl || !miIntegracionApi.nonce || typeof miApiUtils === 'undefined') {
        console.error('Error: Variables de configuración o miApiUtils no disponibles para el frontend.');
        return;
    }

    const miApiUtils = window.miApiUtils; // Referencia al objeto de utilidades

    $(document).ready(function() {
        console.log('Frontend Scripts: Inicializado correctamente');

        // Manejador de envío de formularios públicos
        $('.mi-integracion-api-form, .mi-integracion-api-public form').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submitButton = $form.find('button[type="submit"], input[type="submit"]');

            // Utilizar la validación de formularios de miApiUtils
            if (!miApiUtils.validateForm($form)) {
                miApiUtils.showNotification('Por favor, complete todos los campos requeridos y corrija los errores.', 'error');
                return;
            }

            miApiUtils.toggleLoading($submitButton, true);

            // Recopilar datos del formulario, incluyendo el action para AJAX
            const formData = $form.serializeArray().reduce((obj, item) => {
                obj[item.name] = item.value;
                return obj;
            }, {});
            const action = $form.data('action') || 'mi_integracion_api_public_form_submit'; // Acción por defecto si no se especifica

            miApiUtils.ajaxRequest(
                action,
                formData,
                function(response) {
                    miApiUtils.showNotification(response.message || 'Operación realizada con éxito');
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else if (response.reload) {
                        window.location.reload();
                    } else {
                        $form[0].reset(); // Resetear el formulario solo si no hay redirección/recarga
                    }
                },
                function(errorData) {
                    miApiUtils.showNotification(errorData.message || 'Error al procesar la solicitud', 'error');
                }
            ).always(function() {
                miApiUtils.toggleLoading($submitButton, false);
            });
        });

        // Manejador de botones de acción genéricos para el frontend
        $('.mi-integracion-api-button, .mi-integracion-api-action-button').on('click', function(e) {
            const $button = $(this);
            if (!miApiUtils.confirmAction(e)) {
                return;
            }

            const action = $button.data('action');
            if (!action) {
                console.error('Botón de acción sin atributo data-action:', $button);
                return;
            }

            miApiUtils.toggleLoading($button, true);

            const dataToSend = { ...$button.data() };
            delete dataToSend.action;
            delete dataToSend.confirm;

            miApiUtils.ajaxRequest(
                action,
                dataToSend,
                function(response) {
                    miApiUtils.showNotification(response.message || 'Operación realizada con éxito');
                    if (response.redirect) {
                        window.location.href = response.redirect;
                    } else if (response.reload) {
                        window.location.reload();
                    }
                },
                function(errorData) {
                    miApiUtils.showNotification(errorData.message || 'Error al procesar la solicitud', 'error');
                }
            ).always(function() {
                miApiUtils.toggleLoading($button, false);
            });
        });

        // Manejador de tooltips (si son aplicables al frontend)
        $('.mi-integracion-api-tooltip').each(function() {
            const $tooltip = $(this);
            const text = $tooltip.data('tooltip');
            if (text) {
                $tooltip.append('<span class="tooltip-text">' + text + '</span>');
            }
        });

        // Manejador de modales (si son aplicables al frontend)
        $('.mi-integracion-api-modal-trigger').on('click', function(e) {
            e.preventDefault();
            const modalId = $(this).data('modal');
            $('#' + modalId).fadeIn(300);
        });

        $('.mi-integracion-api-modal-close').on('click', function() {
            $(this).closest('.mi-integracion-api-modal').fadeOut(300);
        });

        $(window).on('click', function(e) {
            if ($(e.target).hasClass('mi-integracion-api-modal')) {
                $(e.target).fadeOut(300);
            }
        });

        // Inicialización de componentes adicionales específicos del frontend
        function initComponents() {
            // Por ejemplo, lógica para carruseles, acordeones, etc., si los hay.
        }

        initComponents();
    });
})(jQuery); 