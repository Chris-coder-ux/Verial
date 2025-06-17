/**
 * Archivo de utilidades JavaScript para el plugin Mi Integración API.
 * Contiene funciones genéricas para ser reutilizadas en el frontend y backend.
 */
(function($) {
    'use strict';

    // Función para mostrar notificaciones consistentes en el admin de WordPress
    function showNotification(message, type = 'info', duration = 5000) {
        const $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');

        // Insertar después del primer h1 o al inicio del contenedor principal
        const $h1 = $('h1').first();
        if ($h1.length) {
            $h1.after($notice);
        } else {
            // Fallback si no hay h1, intentar con un contenedor conocido
            $('.wrap, .mi-integracion-api-wrap').first().prepend($notice);
        }

        // Auto-cerrar después de la duración especificada
        if (duration) {
            setTimeout(function() {
                $notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, duration);
        }

        // Cerrar al hacer clic en el botón de cerrar
        $notice.on('click', '.notice-dismiss', function() {
            $notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
    }

    // Función para mostrar/ocultar el indicador de carga en un botón
    function toggleLoading(button, show) {
        const $button = $(button);
        if (show) {
            $button.prop('disabled', true);
            $button.prepend('<span class="mi-integracion-api-loading spinner is-active"></span>');
        } else {
            $button.prop('disabled', false);
            $button.find('.mi-integracion-api-loading').remove();
        }
    }

    // Función para manejar errores AJAX de forma consistente
    function handleAjaxError(jqXHR, textStatus, errorThrown) {
        console.error('Error AJAX:', textStatus, errorThrown, jqXHR);
        let errorMessage = 'Ha ocurrido un error inesperado al procesar la solicitud.';

        try {
            const response = JSON.parse(jqXHR.responseText);
            if (response.data && response.data.message) {
                errorMessage = response.data.message;
            } else if (response.message) { // Compatibilidad con otros formatos de error
                errorMessage = response.message;
            }
        } catch (e) {
            console.error('Error al parsear respuesta del servidor:', e);
        }
        showNotification(errorMessage, 'error');
    }

    // Función para validar formularios genéricos
    function validateForm($form) {
        let isValid = true;

        // Limpiar mensajes de error previos
        $form.find('.error-message').remove();
        $form.find('.error').removeClass('error');

        // Validar campos requeridos
        $form.find('[required]').each(function() {
            const $field = $(this);
            if (!$field.val().trim()) {
                isValid = false;
                $field.addClass('error');
                $field.after('<span class="error-message">' + ($field.data('required-message') || 'Este campo es obligatorio.') + '</span>');
            }
        });

        // Validar formato de correo electrónico
        $form.find('input[type="email"]').each(function() {
            const $field = $(this);
            const email = $field.val().trim();
            if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                isValid = false;
                $field.addClass('error');
                $field.after('<span class="error-message">Por favor, introduce una dirección de correo electrónico válida.</span>');
            }
        });

        // Desplazarse al primer error si lo hay
        if (!isValid) {
            const $firstError = $form.find('.error').first();
            if ($firstError.length) {
                $('html, body').animate({
                    scrollTop: $firstError.offset().top - 100
                }, 500);
            }
        }

        return isValid;
    }

    // Función para realizar peticiones AJAX de forma estandarizada
    function ajaxRequest(action, data, successCallback, errorCallback) {
        const requestData = {
            action: action,
            nonce: miIntegracionApi.nonce, // Asumiendo que miIntegracionApi está globalmente disponible
            ...data
        };

        $.ajax({
            url: miIntegracionApi.ajaxUrl, // Asumiendo que miIntegracionApi está globalmente disponible
            type: 'POST',
            data: requestData,
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    if (typeof successCallback === 'function') {
                        successCallback(response.data);
                    }
                } else {
                    if (typeof errorCallback === 'function') {
                        errorCallback(response.data);
                    } else {
                        showNotification(response.data.message || 'Ha ocurrido un error inesperado al procesar la solicitud.', 'error');
                    }
                }
            },
            error: function(xhr, status, error) {
                if (typeof errorCallback === 'function') {
                    errorCallback({ message: 'Error de conexión: ' + error, xhr: xhr });
                } else {
                    handleAjaxError(xhr, status, error);
                }
            }
        });
    }

    // Muestra un diálogo de confirmación antes de una acción
    function confirmAction(e) {
        const $button = $(this);
        const message = $button.data('confirm') || '¿Estás seguro de que deseas realizar esta acción?';
        
        if (!confirm(message)) {
            e.preventDefault();
            return false;
        }
        return true;
    }

    // Alterna la visibilidad de la contraseña
    function togglePassword(e) {
        e.preventDefault();
        const $button = $(this);
        const $input = $($button.data('target') || $button.siblings('input[type="password"], input[type="text"]'));

        if ($input.length) {
            const type = $input.attr('type') === 'password' ? 'text' : 'password';
            $input.attr('type', type);
            $button.toggleClass('dashicons-visibility dashicons-hidden');
            $button.attr('aria-label', type === 'password' ? 'Mostrar contraseña' : 'Ocultar contraseña');
        }
    }

    // Exponer funciones en un objeto global para fácil acceso
    window.miApiUtils = {
        showNotification: showNotification,
        toggleLoading: toggleLoading,
        handleAjaxError: handleAjaxError,
        validateForm: validateForm,
        ajaxRequest: ajaxRequest,
        confirmAction: confirmAction,
        togglePassword: togglePassword
    };

})(jQuery); 