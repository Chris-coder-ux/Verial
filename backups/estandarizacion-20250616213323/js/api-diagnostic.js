jQuery(document).ready(function($) {
    'use strict';

    // Función para mostrar notificaciones
    function showNotification(message, type = 'info') {
        const notification = $('<div>', {
            class: `api-diagnostic-notification ${type}`,
            text: message
        });

        $('.api-diagnostic-container').prepend(notification);

        // Animar entrada
        notification.hide().slideDown(300);

        // Auto-eliminar después de 5 segundos
        setTimeout(() => {
            notification.slideUp(300, function() {
                $(this).remove();
            });
        }, 5000);
    }

    // Función para actualizar el estado de conexión
    function updateConnectionStatus(isConnected) {
        const statusElement = $('.api-diagnostic-status');
        statusElement.removeClass('connected disconnected')
            .addClass(isConnected ? 'connected' : 'disconnected')
            .html(`
                <span class="dashicons dashicons-${isConnected ? 'yes' : 'no'}"></span>
                ${isConnected ? 'Conectado' : 'Desconectado'}
            `);
    }

    // Función para manejar errores de AJAX
    function handleAjaxError(jqXHR, textStatus, errorThrown) {
        console.error('Error AJAX:', textStatus, errorThrown);
        showNotification('Error al procesar la solicitud: ' + errorThrown, 'error');
    }

    // Probar conexión
    $('#mi-check-connection').on('click', function() {
        const button = $(this);
        const originalText = button.html();
        
        // Deshabilitar botón y mostrar loading
        button.prop('disabled', true)
            .html('<span class="dashicons dashicons-update spinning"></span> Probando conexión...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mi_check_api_connection',
                nonce: mia_diagnostic.nonce
            },
            success: function(response) {
                if (response.success) {
                    updateConnectionStatus(true);
                    showNotification('Conexión exitosa con la API', 'success');
                } else {
                    updateConnectionStatus(false);
                    showNotification(response.data.message || 'Error al conectar con la API', 'error');
                }
            },
            error: handleAjaxError,
            complete: function() {
                // Restaurar botón
                button.prop('disabled', false).html(originalText);
            }
        });
    });

    // Verificar autenticación API Key
    $('#mi-check-api-auth').on('click', function() {
        const button = $(this);
        const originalText = button.html();
        
        button.prop('disabled', true)
            .html('<span class="dashicons dashicons-update spinning"></span> Verificando autenticación...');

        $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'mi_check_api_auth',
                nonce: mia_diagnostic.nonce
            },
            success: function(response) {
                if (response.success) {
                    showNotification('Autenticación exitosa', 'success');
                } else {
                    showNotification(response.data.message || 'Error en la autenticación', 'error');
                }
            },
            error: handleAjaxError,
            complete: function() {
                button.prop('disabled', false).html(originalText);
            }
        });
    });

    // Añadir estilos para la animación de spinning
    $('<style>')
        .text(`
            @keyframes spin {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            .dashicons.spinning {
                animation: spin 1s linear infinite;
            }
            .api-diagnostic-notification {
                padding: 12px 16px;
                margin-bottom: 16px;
                border-radius: 4px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            .api-diagnostic-notification.success {
                background: #dcfce7;
                color: #166534;
                border: 1px solid #bbf7d0;
            }
            .api-diagnostic-notification.error {
                background: #fee2e2;
                color: #991b1b;
                border: 1px solid #fecaca;
            }
            .api-diagnostic-notification.info {
                background: #dbeafe;
                color: #1e40af;
                border: 1px solid #bfdbfe;
            }
        `)
        .appendTo('head');
});
