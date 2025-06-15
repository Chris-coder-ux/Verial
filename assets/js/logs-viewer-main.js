/**
 * Script principal para la visualización de logs en el área de administración de Mi Integración API.
 * Consolida la funcionalidad de logs-viewer.js y logs-viewer-secure.js, utilizando las utilidades de utils.js.
 */
(function($) {
    'use strict';

    // Asegurarse de que miIntegracionApi y miApiUtils estén disponibles
    if (typeof miIntegracionApi === 'undefined' || !miIntegracionApi.restUrl || !miIntegracionApi.restNonce || typeof miApiUtils === 'undefined') {
        console.error('Error: Variables de configuración o miApiUtils no disponibles para el visor de logs.');
        return;
    }

    const miApiUtils = window.miApiUtils; // Referencia al objeto de utilidades
    let currentPage = 1;
    let currentFilters = {};
    let isLoading = false; // Estado de carga para evitar peticiones duplicadas

    $(document).ready(function() {
        console.log('Visor de logs: Inicializado correctamente');

        initFilters();
        initButtons();
        loadLogs(); // Cargar logs iniciales
    });

    /**
     * Inicializa los eventos de los filtros.
     */
    function initFilters() {
        $('#log-filter-form').on('submit', function(e) {
            e.preventDefault();
            currentPage = 1; // Resetear página al aplicar filtros
            currentFilters = $(this).serializeArray().reduce((obj, item) => {
                obj[item.name] = item.value;
                return obj;
            }, {});
            loadLogs();
        });

        // Búsqueda con timeout para no hacer muchas peticiones
        let searchTimeout = null;
        $('#log-search').on('keyup', function() {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(function() {
                currentPage = 1;
                currentFilters.search = $('#log-search').val();
                loadLogs();
            }, 500); // Esperar 500ms después de que el usuario deje de escribir
        });
    }

    /**
     * Inicializa los eventos de los botones de acción.
     */
    function initButtons() {
        $('#refresh-logs').on('click', function(e) {
            e.preventDefault();
            loadLogs();
        });

        $('#clear-logs').on('click', function(e) {
            e.preventDefault();
            if (miApiUtils.confirmAction(e)) { // Usar la utilidad de confirmación
                clearLogs();
            }
        });

        // Paginación dinámica (delegación de eventos para enlaces futuros)
        $(document).on('click', '.pagination-link', function(e) {
            e.preventDefault();
            const page = $(this).data('page');
            if (page && !isLoading) {
                currentPage = page;
                loadLogs();
                // Scroll hacia arriba para mejor UX
                $('html, body').animate({ scrollTop: $('#log-container').offset().top - 50 }, 200);
            }
        });
    }

    /**
     * Carga los logs desde la API REST con filtros y paginación.
     */
    function loadLogs() {
        if (isLoading) return;

        isLoading = true;
        miApiUtils.toggleLoading($('#refresh-logs'), true); // Mostrar indicador de carga

        const params = {
            page: currentPage,
            per_page: 20, // O el número de elementos por página deseado
            ...currentFilters
        };

        miApiUtils.ajaxRequest(
            'mi_integracion_api_get_logs', // Acción AJAX de WordPress
            params,
            function(response) {
                renderLogs(response.logs, response.pagination);
                miApiUtils.showNotification('Logs cargados correctamente.', 'success', 2000);
            },
            function(errorData) {
                miApiUtils.showNotification(errorData.message || 'Error al cargar los logs.', 'error');
                $('#log-container').html(
                    `<div class="notice notice-error"><p>${errorData.message || 'No se pudieron cargar los logs. Por favor, inténtalo de nuevo.'}</p></div>`
                );
            }
        ).always(function() {
            isLoading = false;
            miApiUtils.toggleLoading($('#refresh-logs'), false);
        });
    }

    /**
     * Renderiza los logs en la tabla y actualiza la paginación.
     * @param {Array} logs - Array de objetos de log.
     * @param {Object} pagination - Objeto de paginación con total_pages, current_page, etc.
     */
    function renderLogs(logs, pagination) {
        const $tbody = $('#logs-table tbody');
        $tbody.empty();

        if (logs.length === 0) {
            $tbody.append('<tr><td colspan="5" class="no-logs">No hay logs disponibles con los filtros actuales.</td></tr>');
            $('#log-pagination').empty();
            return;
        }

        logs.forEach(function(log) {
            const logClass = getLogClass(log.level);
            const row = `
                <tr class="log-level-${logClass}">
                    <td>${log.id}</td>
                    <td>${log.timestamp}</td>
                    <td>${log.level}</td>
                    <td>${log.message}</td>
                    <td>
                        <button class="button view-log-details" data-log-id="${log.id}">Ver detalles</button>
                    </td>
                </tr>
            `;
            $tbody.append(row);
        });

        // Manejar evento de ver detalles (delegación de eventos)
        $('.view-log-details').off('click').on('click', function() {
            const logId = $(this).data('log-id');
            viewLogDetails(logId);
        });

        renderPagination(pagination.current_page, pagination.total_pages);
    }

    /**
     * Renderiza los controles de paginación.
     * @param {number} currentPage - Página actual.
     * @param {number} totalPages - Número total de páginas.
     */
    function renderPagination(currentPage, totalPages) {
        const $pagination = $('.logs-pagination');
        $pagination.empty();

        if (totalPages <= 1) {
            return;
        }

        let paginationHtml = '';

        // Botón Anterior
        if (currentPage > 1) {
            paginationHtml += `<a href="#" class="pagination-link button" data-page="${currentPage - 1}">« Anterior</a>`;
        } else {
            paginationHtml += '<span class="pagination-disabled button">« Anterior</span>';
        }

        // Números de página
        // Lógica para mostrar un rango de páginas (ej: 1 2 ... 5 6 7 ... 10 11)
        const maxPagesToShow = 7; // Total de páginas a mostrar
        const halfMaxPages = Math.floor(maxPagesToShow / 2);
        let startPage = Math.max(1, currentPage - halfMaxPages);
        let endPage = Math.min(totalPages, currentPage + halfMaxPages);

        if (endPage - startPage + 1 < maxPagesToShow) {
            if (startPage === 1) {
                endPage = Math.min(totalPages, startPage + maxPagesToShow - 1);
            } else if (endPage === totalPages) {
                startPage = Math.max(1, totalPages - maxPagesToShow + 1);
            }
        }

        if (startPage > 1) {
            paginationHtml += '<span class="pagination-dots">...</span>';
        }

        for (let i = startPage; i <= endPage; i++) {
            if (i === currentPage) {
                paginationHtml += `<span class="pagination-current button button-primary">${i}</span>`;
            } else {
                paginationHtml += `<a href="#" class="pagination-link button" data-page="${i}">${i}</a>`;
            }
        }

        if (endPage < totalPages) {
            paginationHtml += '<span class="pagination-dots">...</span>';
        }

        // Botón Siguiente
        if (currentPage < totalPages) {
            paginationHtml += `<a href="#" class="pagination-link button" data-page="${currentPage + 1}">Siguiente »</a>`;
        } else {
            paginationHtml += '<span class="pagination-disabled button">Siguiente »</span>';
        }

        $pagination.html(paginationHtml);
    }

    /**
     * Limpia todos los logs a través de la API REST.
     */
    function clearLogs() {
        if (isLoading) return;

        isLoading = true;
        miApiUtils.toggleLoading($('#clear-logs'), true);

        miApiUtils.ajaxRequest(
            'mi_integracion_api_clear_logs', // Acción AJAX de WordPress
            {},
            function(response) {
                miApiUtils.showNotification(response.message || 'Logs borrados correctamente.', 'success');
                currentPage = 1; // Volver a la primera página después de limpiar
                loadLogs(); // Recargar logs
            },
            function(errorData) {
                miApiUtils.showNotification(errorData.message || 'Error al borrar los logs.', 'error');
            }
        ).always(function() {
            isLoading = false;
            miApiUtils.toggleLoading($('#clear-logs'), false);
        });
    }

    /**
     * Obtiene los detalles de un log específico y los muestra en un modal.
     * @param {string} logId - ID del log a ver.
     */
    function viewLogDetails(logId) {
        miApiUtils.toggleLoading($('.view-log-details[data-log-id="' + logId + '"]'), true);

        miApiUtils.ajaxRequest(
            'mi_integracion_api_get_log_details', // Acción AJAX de WordPress
            { log_id: logId },
            function(log) {
                showLogDetailsModal(log);
            },
            function(errorData) {
                miApiUtils.showNotification(errorData.message || 'Error al cargar los detalles del log.', 'error');
            }
        ).always(function() {
            miApiUtils.toggleLoading($('.view-log-details[data-log-id="' + logId + '"]'), false);
        });
    }

    /**
     * Muestra el modal con los detalles de un log.
     * @param {Object} log - Objeto de log con detalles.
     */
    function showLogDetailsModal(log) {
        // Si ya existe un modal, eliminarlo
        $('#log-details-modal').remove();

        // Formatear el contexto para una mejor visualización
        let formattedContext = '';
        if (log.context) {
            try {
                formattedContext = JSON.stringify(log.context, null, 2);
            } catch (e) {
                formattedContext = String(log.context); // En caso de que no sea un JSON válido
            }
        }

        const modalHtml = `
            <div id="log-details-modal" class="mi-api-modal mi-integracion-api-modal">
                <div class="mi-api-modal-content mi-integracion-api-modal-content">
                    <span class="mi-api-modal-close mi-integracion-api-modal-close">&times;</span>
                    <h3>Detalles del Log #${log.id}</h3>
                    <div class="log-details">
                        <p><strong>Fecha:</strong> ${log.timestamp}</p>
                        <p><strong>Nivel:</strong> <span class="log-level-${getLogClass(log.level)}">${log.level}</span></p>
                        ${log.category ? `<p><strong>Categoría:</strong> ${log.category}</p>` : ''}
                        <p><strong>Mensaje:</strong> ${log.message}</p>
                        ${formattedContext ? `
                            <div class="log-context">
                                <h4>Contexto:</h4>
                                <pre>${formattedContext}</pre>
                            </div>
                        ` : ''}
                        ${log.trace ? `<p><strong>Origen:</strong> ${log.trace}</p>` : ''}
                        ${log.memory_usage ? `<p><strong>Uso de Memoria:</strong> ${log.memory_usage}</p>` : ''}
                        ${log.transaction_id ? `<p><strong>ID Transacción:</strong> ${log.transaction_id}</p>` : ''}
                    </div>
                </div>
            </div>
        `;

        $('body').append(modalHtml);
        $('#log-details-modal').fadeIn(300);

        // Cerrar modal al hacer clic en la X o fuera del contenido
        $('#log-details-modal .mi-api-modal-close, #log-details-modal').on('click', function(e) {
            if ($(e.target).hasClass('mi-api-modal') || $(e.target).hasClass('mi-api-modal-close')) {
                $('#log-details-modal').fadeOut(300, function() {
                    $(this).remove();
                });
            }
        });
    }

    /**
     * Devuelve la clase CSS correspondiente al nivel de log.
     * @param {string} type - Nivel del log (info, warning, error, etc.).
     * @returns {string} Clase CSS.
     */
    function getLogClass(type) {
        switch (type.toLowerCase()) {
            case 'info':
                return 'info';
            case 'warning':
                return 'warning';
            case 'error':
            case 'critical':
            case 'alert':
            case 'emergency':
                return 'error';
            case 'debug':
                return 'debug';
            default:
                return '';
        }
    }

})(jQuery); 