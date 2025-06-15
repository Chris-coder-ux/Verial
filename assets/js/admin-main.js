/**
 * Archivo JavaScript principal para el área de administración de Mi Integración API.
 * Consolida la funcionalidad de admin.js y admin-script.js, utilizando las utilidades de utils.js.
 */
jQuery(document).ready(function($) {
    'use strict';

    // Asegurarse de que miApiUtils esté disponible
    const miApiUtils = window.miApiUtils || {};

    // Selector compuesto para compatibilidad con las clases antiguas y nuevas
    const adminSelector = '.mi-integracion-api-admin, .verial-admin-wrap';
    const dashboardSelector = '.mi-integracion-api-dashboard, .verial-dashboard';
    const settingsSelector = '.mi-integracion-api-settings, .verial-settings';

    // Mostrar notificaciones automáticas existentes al cargar la página
    $(adminSelector + ' .notice').each(function() {
        const $notice = $(this);
        if ($notice.hasClass('notice-success') || $notice.hasClass('notice-info')) {
            setTimeout(function() { $notice.fadeOut(); }, 3500);
        }
    });

    // Manejador de envío de formularios principales
    $('.mi-integracion-api-form').on('submit', function(e) {
        e.preventDefault();
        const $form = $(this);
        const $submitButton = $form.find('button[type="submit"]');

        if (!miApiUtils.validateForm($form)) {
            miApiUtils.showNotification('Por favor, complete todos los campos requeridos y corrija los errores.', 'error');
            return;
        }

        miApiUtils.toggleLoading($submitButton, true);

        miApiUtils.ajaxRequest(
            $form.data('action'),
            {
                ...$form.serializeArray().reduce((obj, item) => {
                    obj[item.name] = item.value;
                    return obj;
                }, {})
            },
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
            miApiUtils.toggleLoading($submitButton, false);
        });
    });

    // Manejador de botones de acción genéricos
    $('.mi-integracion-api-action-button').on('click', function(e) {
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

        // Recopilar todos los data attributes como parámetros
        const dataToSend = { ...$button.data() };
        delete dataToSend.action; // No enviar la acción dos veces
        delete dataToSend.confirm; // No enviar el mensaje de confirmación

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

    // Toggle para mostrar/ocultar contraseña
    $(document).on('click', '.toggle-password', miApiUtils.togglePassword);

    // Manejador de tooltips
    $('.mi-integracion-api-tooltip').each(function() {
        const tooltip = $(this);
        const text = tooltip.data('tooltip');

        if (text) {
            tooltip.append('<span class="tooltip-text">' + text + '</span>');
        }
    });

    // Manejador de modales
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

    // Inicialización de componentes (si hay alguno específico que no sea de utilidades)
    function initComponents() {
        // Aquí puedes inicializar componentes adicionales específicos del admin
        // que no estén en utils.js, como datepickers, select2, etc.
    }

    initComponents();
}); 