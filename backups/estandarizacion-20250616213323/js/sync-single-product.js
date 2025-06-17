/**
 * JavaScript para la página de sincronización individual de productos
 *
 * Este script maneja:
 * - Validación del formulario
 * - Autocompletado de productos
 * - Carga de categorías y fabricantes
 * - Envío del formulario de sincronización
 * - Soporte para búsqueda escalonada
 * - Sistema de reintentos automáticos
 */

// Verificar que jQuery esté disponible
(function(window) {
    if (typeof jQuery === 'undefined') {
        console.error('[ERROR CRÍTICO] jQuery no está disponible. Intentando cargar jQuery...');
        
        // Intentar cargar jQuery desde WordPress
        var script = document.createElement('script');
        script.src = '/wp-includes/js/jquery/jquery.min.js';
        script.onload = function() {
            console.log('jQuery cargado correctamente. Reiniciando script...');
            initSyncScript(window.jQuery);
        };
        script.onerror = function() {
            console.error('No se pudo cargar jQuery automáticamente. Por favor, recarga la página.');
            alert('Error: No se pudo cargar jQuery. Por favor, recarga la página o contacta al administrador.');
        };
        document.head.appendChild(script);
    } else {
        console.log('jQuery detectado correctamente. Inicializando script...');
        jQuery(document).ready(function($) {
            initSyncScript($);
        });
    }
})(window);

function initSyncScript($) {
    console.log('[INFO] Script de sincronización individual cargado');
    console.log('[INFO] jQuery version:', $.fn.jquery);
    console.log('[INFO] ¿URL AJAX disponible?', typeof ajaxurl !== 'undefined');
    
    /**
     * Función para obtener el nonce de seguridad de múltiples fuentes posibles
     * Garantiza compatibilidad entre páginas y contextos diferentes
     */
    function getNonceValue() {
        // 0. Nuevo: Intentar obtener del objeto localizado
        if (typeof miSyncSingleProduct !== 'undefined' && miSyncSingleProduct.nonce) {
            console.log('[DEBUG] Usando nonce de objeto localizado miSyncSingleProduct');
            return miSyncSingleProduct.nonce;
        }
        
        // 1. Buscar en elementos con name="_ajax_nonce" (fuente principal)
        let ajaxNonce = $('input[name="_ajax_nonce"]').val();
        if (ajaxNonce) {
            console.log('[DEBUG] Usando nonce de input[name="_ajax_nonce"]');
            return ajaxNonce;
        }
        
        // 2. Intentar obtener del objeto global miIntegracionApiDashboard
        if (typeof miIntegracionApiDashboard !== 'undefined' && miIntegracionApiDashboard.nonce) {
            console.log('[DEBUG] Usando nonce de miIntegracionApiDashboard');
            return miIntegracionApiDashboard.nonce;
        }
        
        // 3. Buscar en otros campos de nonce comunes de WordPress
        const possibleNonceFields = ['_wpnonce', 'security', 'nonce'];
        for (let fieldName of possibleNonceFields) {
            let nonceValue = $(`input[name="${fieldName}"]`).val();
            if (nonceValue) {
                console.log(`[DEBUG] Usando nonce de input[name="${fieldName}"]`);
                return nonceValue;
            }
        }
        
        // 4. Buscar en datos de elementos con atributo data-nonce 
        let dataNonce = $('[data-nonce]').first().data('nonce');
        if (dataNonce) {
            console.log('[DEBUG] Usando nonce de elemento con data-nonce');
            return dataNonce;
        }
        
        console.error('[ERROR] No se pudo encontrar un nonce válido en ninguna fuente');
        return null;
    }
    
    // Referencias a los elementos del formulario
    const $form = $('#mi-sync-single-product-form');
    const $skuInput = $('#sku');
    const $nombreInput = $('#nombre');
    const $categoriaSelect = $('#categoria');
    const $fabricanteSelect = $('#fabricante');
    const $syncButton = $('#sync-button');
    const $unlockButton = $('#unlock-sync-button');
    const $spinner = $('.spinner');
    const $resultContainer = $('#sync-result');
    
    // Configuración de búsqueda avanzada
    const searchConfig = {
        maxRetries: 3,           // Número máximo de reintentos para peticiones fallidas
        retryDelay: 1500,        // Tiempo entre reintentos (ms)
        retryMultiplier: 1.5,    // Factor de multiplicación para backoff exponencial
        isRequestInProgress: false, // Indicador de petición en curso
        currentRetryCount: 0     // Contador de reintentos actual
    };
    
    // Cargar categorías y fabricantes al cargar la página
    initSelects();
    
    // Configurar autocompletado para SKU y nombre
    setupAutocomplete($skuInput, 'id'); // Buscar por SKU/ID
    setupAutocomplete($nombreInput, 'nombre'); // Buscar por nombre
    
    // Manejar envío del formulario
    $form.on('submit', function(e) {
        e.preventDefault();
        syncProduct();
    });
    
    // Manejar botón de desbloqueo
    $unlockButton.on('click', function(e) {
        e.preventDefault();
        unlockSync();
    });
    
    /**
     * Inicializa los selects de categoría y fabricante
     */
    function initSelects() {
        console.log('Inicializando selectores...');
        console.log('Nonce disponible:', $('input[name="_ajax_nonce"]').length > 0);
        console.log('Valor del nonce:', getNonceValue());
        console.log('URL AJAX:', ajaxurl);
        
        // Obtener categorías
        console.log('[DEBUG] Enviando petición para obtener categorías');
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'mi_sync_get_categorias',
                _ajax_nonce: getNonceValue(),
                nonce: getNonceValue(),
                _wpnonce: getNonceValue()
            },
            beforeSend: function(xhr) {
                console.log('[DEBUG] Antes de enviar petición de categorías:');
                console.log('- URL:', ajaxurl);
                console.log('- Action:', 'mi_sync_get_categorias');
                console.log('- Nonce:', getNonceValue());
            },
            success: function(response) {
                console.log('[DEBUG] Respuesta categorías completa:', response);
                try {
                    console.log('[DEBUG] Categorías recibidas:', response.data?.categories);
                    console.log('[DEBUG] Tipo de datos recibido:', typeof response.data?.categories);
                    console.log('[DEBUG] ¿Es objeto?', $.isPlainObject(response.data?.categories));
                    console.log('[DEBUG] ¿Es array?', Array.isArray(response.data?.categories));
                    
                    // Intentar poblar el select incluso si no hay datos completos
                    if (response.success && response.data) {
                        populateSelect($categoriaSelect, response.data.categories || {});
                    } else {
                        console.error('[ERROR] Error al cargar categorías:', response);
                        console.error('[ERROR] Success:', response.success);
                        console.error('[ERROR] Data exist:', !!response.data);
                        
                        // Crear categoría de emergencia para que la interfaz funcione
                        populateSelect($categoriaSelect, {'0': 'Categoría por defecto'});
                        
                        // Solo mostrar error si realmente falló la petición
                        if (!response.success) {
                            const mensaje = response.data?.message || 'Error al cargar categorías';
                            console.error('[ERROR] Mensaje de error:', mensaje);
                            
                            // Mostrar mensaje menos intrusivo
                            if ($('#categorias-error-message').length === 0) {
                                $categoriaSelect.after(
                                    $('<p id="categorias-error-message" class="description" style="color:red;">')
                                        .text('Error al cargar categorías: ' + mensaje)
                                );
                            }
                        }
                    }
                } catch (error) {
                    console.error('[ERROR] Excepción al procesar respuesta de categorías:', error);
                    // Asegurar que el select tenga al menos un valor
                    populateSelect($categoriaSelect, {'0': 'Categoría por defecto'});
                }
            },
            error: function(xhr, status, error) {
                console.error('[ERROR] Error AJAX al cargar categorías:', status, error);
                console.log('[ERROR] Status code:', xhr.status);
                console.log('[ERROR] Respuesta completa:', xhr.responseText);
                alert('Error de comunicación al cargar categorías: ' + status);
            }
        });
        
        // Obtener fabricantes
        console.log('[DEBUG] Enviando petición para obtener fabricantes');
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'mi_sync_get_fabricantes',
                _ajax_nonce: getNonceValue(),
                nonce: getNonceValue(),
                _wpnonce: getNonceValue()
            },
            beforeSend: function(xhr) {
                console.log('[DEBUG] Antes de enviar petición de fabricantes:');
                console.log('- URL:', ajaxurl);
                console.log('- Action:', 'mi_sync_get_fabricantes');
                console.log('- Nonce:', getNonceValue());
            },
            success: function(response) {
                console.log('[DEBUG] Respuesta fabricantes completa:', response);
                try {
                    // Intentar obtener datos de fabricantes de las diferentes posibles fuentes
                    let datos = null;
                    if (response.success && response.data) {
                        datos = response.data.manufacturers || response.data.fabricantes || null;
                    }
                    
                    console.log('[DEBUG] Fabricantes recibidos:', datos);
                    console.log('[DEBUG] Tipo de datos recibido:', typeof datos);
                    console.log('[DEBUG] ¿Es objeto?', $.isPlainObject(datos));
                    console.log('[DEBUG] ¿Es array?', Array.isArray(datos));
                    
                    // Intentar poblar el select incluso si no hay datos completos
                    if (datos) {
                        populateSelect($fabricanteSelect, datos);
                    } else {
                        console.error('[ERROR] Error al cargar fabricantes o datos vacíos:', response);
                        console.error('[ERROR] Success:', response.success);
                        console.error('[ERROR] Data exist:', !!response.data);
                        
                        // Crear fabricante de emergencia para que la interfaz funcione
                        populateSelect($fabricanteSelect, {'0': 'Fabricante por defecto'});
                        
                        // Solo mostrar error si realmente falló la petición
                        if (!response.success) {
                            const mensaje = response.data?.message || 'Error al cargar fabricantes';
                            console.error('[ERROR] Mensaje de error:', mensaje);
                            
                            // Mostrar mensaje menos intrusivo
                            if ($('#fabricantes-error-message').length === 0) {
                                $fabricanteSelect.after(
                                    $('<p id="fabricantes-error-message" class="description" style="color:red;">')
                                        .text('Error al cargar fabricantes: ' + mensaje)
                                );
                            }
                        }
                    }
                } catch (error) {
                    console.error('[ERROR] Excepción al procesar respuesta de fabricantes:', error);
                    // Asegurar que el select tenga al menos un valor
                    populateSelect($fabricanteSelect, {'0': 'Fabricante por defecto'});
                }
            },
            error: function(xhr, status, error) {
                console.error('[ERROR] Error AJAX al cargar fabricantes:', status, error);
                console.log('[ERROR] Status code:', xhr.status);
                console.log('[ERROR] Respuesta completa:', xhr.responseText);
            }
        });
    }
    
    /**
     * Rellena un select con opciones
     */
    function populateSelect($select, items) {
        console.log('[DEBUG] Rellenando select:', $select.attr('id'));
        console.log('[DEBUG] Items para poblar select (original):', items);
        
        // Verificar si items es válido
        if (!items) {
            console.error('[ERROR] Items es nulo o indefinido');
            // Mantener el valor por defecto sin mostrar error al usuario
            return;
        }
        
        // Mantener la opción por defecto
        const defaultOption = $select.find('option').first().clone();
        console.log('[DEBUG] Opción por defecto:', defaultOption.text());
        $select.empty().append(defaultOption);
        
        // Convertir diferentes formatos de datos a formato estándar clave-valor
        let itemsProcessed = {};
        
        try {
            // Caso 1: Ya es un objeto plano con format {id: nombre}
            if ($.isPlainObject(items) && !Array.isArray(items)) {
                console.log('[DEBUG] Usando items como objeto plano');
                itemsProcessed = items;
            }
            // Caso 2: Es un array de objetos con id/nombre
            else if (Array.isArray(items)) {
                console.log('[DEBUG] Convirtiendo array a objeto clave-valor');
                items.forEach(function(item) {
                    const id = item.id || item.Id || item.value || '';
                    const nombre = item.nombre || item.Nombre || item.name || item.label || '';
                    if (id && nombre) {
                        itemsProcessed[id] = nombre;
                    }
                });
            }
            // Caso 3: Es un solo objeto con campos numerados (0,1,2...)
            else if (typeof items === 'object') {
                console.log('[DEBUG] Procesando objeto con estructura desconocida');
                
                // Intentar encontrar arrays dentro del objeto
                let foundArray = false;
                $.each(items, function(key, value) {
                    if (Array.isArray(value)) {
                        console.log('[DEBUG] Encontró array en clave:', key);
                        foundArray = true;
                        value.forEach(function(item) {
                            const id = item.id || item.Id || '';
                            const nombre = item.nombre || item.Nombre || '';
                            if (id && nombre) {
                                itemsProcessed[id] = nombre;
                            }
                        });
                        return false; // Break del each
                    }
                });
                
                // Si no se encontró array, tratar como objeto plano
                if (!foundArray) {
                    itemsProcessed = items;
                }
            }
            
            console.log('[DEBUG] Items procesados:', itemsProcessed);
            
            // Añadir opciones al select
            let count = 0;
            $.each(itemsProcessed, function(id, name) {
                if (id && name) {
                    console.log('[DEBUG] Añadiendo opción:', id, name);
                    $select.append($('<option>', {
                        value: id,
                        text: name
                    }));
                    count++;
                }
            });
            
            console.log('[DEBUG] Total de opciones añadidas:', count);
            
            if (count === 0) {
                console.warn('[ADVERTENCIA] No se añadieron opciones al select');
            }
        } catch (error) {
            console.error('[ERROR] Error al añadir opciones al select:', error);
        }
    }
    
    /**
     * Configura el autocompletado para un campo
     * @param {jQuery} $input El campo de entrada
     * @param {string} field El tipo de campo ('id' o 'nombre')
     */
    function setupAutocomplete($input, field) {
        if (typeof $.ui === 'undefined' || typeof $.ui.autocomplete === 'undefined') {
            console.error('[ERROR] jQuery UI Autocomplete no está disponible');
            return;
        }
        
        $input.autocomplete({
            source: function(request, response) {
                // Si ya hay una petición en curso, cancelarla
                if (this.xhr) {
                    this.xhr.abort();
                }
                
                // Verificar si el término de búsqueda tiene contenido
                if (!request.term || request.term.trim().length < 2) {
                    return;
                }
                
                console.log(`[INFO] Buscando productos por ${field}: ${request.term}`);
                
                const searchData = {
                    action: 'mi_sync_search_product',
                    _ajax_nonce: getNonceValue(),
                    search: request.term,
                    field: field
                };
                
                // Nueva función recursiva para permitir reintentos automáticos
                function executeSearch(retryCount) {
                    // Si superamos el máximo de reintentos, mostrar error
                    if (retryCount > searchConfig.maxRetries) {
                        console.error(`[ERROR] Máximo de reintentos (${searchConfig.maxRetries}) alcanzado para búsqueda`);
                        response([]);
                        return;
                    }
                    
                    // Calcular tiempo de espera con backoff exponencial
                    const delayTime = retryCount > 0 
                        ? searchConfig.retryDelay * Math.pow(searchConfig.retryMultiplier, retryCount - 1)
                        : 0;
                    
                    setTimeout(function() {
                        // Mostrar mensaje de intento
                        if (retryCount > 0) {
                            console.log(`[INFO] Reintento #${retryCount} para búsqueda de "${request.term}"`);
                        }
                        
                        $.ajax({
                            url: ajaxurl,
                            method: 'POST',
                            data: searchData,
                            beforeSend: function(xhr) {
                                searchConfig.isRequestInProgress = true;
                            },
                            success: function(data) {
                                searchConfig.isRequestInProgress = false;
                                searchConfig.currentRetryCount = 0; // Resetear contador de reintentos
                                
                                if (data && Array.isArray(data)) {
                                    console.log(`[INFO] Productos encontrados: ${data.length}`);
                                    response(data);
                                } else {
                                    console.warn('[WARN] Respuesta no válida desde la búsqueda de productos');
                                    
                                    // Verificar si debemos reintentar
                                    if (retryCount < searchConfig.maxRetries) {
                                        console.log('[INFO] Reintentar debido a respuesta inválida');
                                        executeSearch(retryCount + 1);
                                    } else {
                                        response([]);
                                    }
                                }
                            },
                            error: function(xhr, status, error) {
                                searchConfig.isRequestInProgress = false;
                                
                                // Si fue abortada por otra petición, no mostrar error
                                if (status === 'abort') {
                                    console.log('[INFO] Búsqueda abortada por otra petición más reciente');
                                    return;
                                }
                                
                                console.error(`[ERROR] Error en búsqueda: ${error} (status: ${status})`);
                                
                                // Intentar de nuevo en caso de errores de red/servidor
                                if (['timeout', 'error', 'parsererror'].includes(status) && retryCount < searchConfig.maxRetries) {
                                    console.log(`[INFO] Reintentando después de error: ${status}`);
                                    executeSearch(retryCount + 1);
                                } else {
                                    response([]);
                                }
                            }
                        });
                    }, delayTime);
                }
                
                // Iniciar la búsqueda con contador de reintentos en 0
                executeSearch(0);
            },
            minLength: 2,
            delay: 300,
            autoFocus: true,
            select: function(event, ui) {
                if (ui.item) {
                    // Completar el campo con el valor del elemento seleccionado
                    $(this).val(ui.item.value);
                    
                    // Mostrar información adicional si existe
                    if (ui.item.meta) {
                        const $meta = $('<p class="description search-meta"></p>');
                        $meta.html(`<strong>Info:</strong> ${ui.item.meta}`);
                        $(this).after($meta);
                        
                        setTimeout(() => {
                            $meta.fadeOut(500, function() { $(this).remove(); });
                        }, 5000);
                    }
                }
                return true;
            }
        }).data('ui-autocomplete')._renderItem = function(ul, item) {
            // Personalizar el renderizado de ítems del autocompletado
            const $li = $('<li>');
            const $content = $('<div class="autocomplete-item">');
            
            // Texto principal (nombre del producto o código)
            $content.append(
                $('<span class="product-label">').text(item.label)
            );
            
            // Si hay descripción adicional, añadirla
            if (item.desc) {
                $content.append(
                    $('<span class="product-desc">').text(item.desc)
                );
            }
            
            $li.append($content).appendTo(ul);
            return $li;
        };
    }
    
    /**
     * Sincroniza un producto con reintentos automáticos
     * @param {number} retryCount Contador de reintentos actual
     */
    function syncProduct(retryCount = 0) {
        try {
            // Verificar si se ha alcanzado el máximo de reintentos
            if (retryCount > searchConfig.maxRetries) {
                console.error(`[ERROR] Se ha alcanzado el máximo de reintentos (${searchConfig.maxRetries}) para sincronización`);
                showResult('error', `Demasiados intentos fallidos. Por favor, intenta de nuevo más tarde.`);
                $syncButton.prop('disabled', false);
                $spinner.css('visibility', 'hidden');
                return;
            }
            
            // Verificar si hay una petición en curso
            if (searchConfig.isRequestInProgress) {
                console.warn('[WARN] Ya hay una petición de sincronización en curso');
                return;
            }
            
            // Obtener valores del formulario
            const sku = $skuInput.val().trim();
            const nombre = $nombreInput.val().trim();
            const categoria = $categoriaSelect.val();
            const fabricante = $fabricanteSelect.val();
            
            // Validar que al menos uno de los campos principales no esté vacío
            if (!sku && !nombre) {
                showResult('error', 'Debes proporcionar al menos el SKU o el nombre del producto.');
                return;
            }
            
            // Mostrar spinner y deshabilitar botón para evitar doble envío
            $syncButton.prop('disabled', true);
            $spinner.css('visibility', 'visible');
            
            // Calcular tiempo de espera para reintento (si aplica)
            const delayTime = retryCount > 0 
                ? searchConfig.retryDelay * Math.pow(searchConfig.retryMultiplier, retryCount - 1)
                : 0;
            
            // Si es un reintento, mostrar mensaje de espera
            if (retryCount > 0) {
                console.log(`[INFO] Reintento #${retryCount} para sincronización`);
                showResult('info', `Reintentando sincronización (intento ${retryCount} de ${searchConfig.maxRetries})...`);
            }
            
            // Esperar si es necesario (para reintentos)
            setTimeout(function() {
                console.log('[INFO] Enviando petición de sincronización con parámetros:');
                console.log('- SKU:', sku);
                console.log('- Nombre:', nombre);
                console.log('- Categoría:', categoria);
                console.log('- Fabricante:', fabricante);
                
                // Marcar que hay una petición en curso
                searchConfig.isRequestInProgress = true;
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'mi_sync_single_product',
                        _ajax_nonce: getNonceValue(),
                        sku: sku,
                        nombre: nombre,
                        categoria: categoria,
                        fabricante: fabricante
                    },
                    success: function(response) {
                        // Marcar que la petición ha terminado
                        searchConfig.isRequestInProgress = false;
                        searchConfig.currentRetryCount = 0; // Resetear contador
                        
                        console.log('[INFO] Respuesta de sincronización:', response);
                        
                        if (response.success) {
                            showResult('success', response.data.message || 'Producto sincronizado correctamente');
                            
                            // Si hay info adicional del producto, mostrarla
                            if (response.data.product_info) {
                                const productInfo = response.data.product_info;
                                
                                // Crear un resumen del producto sincronizado
                                let infoHTML = '<div class="product-sync-summary">';
                                
                                // Añadir título si tenemos nombre
                                if (productInfo.name) {
                                    infoHTML += `<h4>${productInfo.name}</h4>`;
                                }
                                
                                // Añadir detalles clave
                                infoHTML += '<ul class="product-details">';
                                
                                if (productInfo.id) {
                                    infoHTML += `<li><strong>ID:</strong> ${productInfo.id}</li>`;
                                }
                                
                                if (productInfo.sku) {
                                    infoHTML += `<li><strong>SKU:</strong> ${productInfo.sku}</li>`;
                                }
                                
                                if (productInfo.price) {
                                    infoHTML += `<li><strong>Precio:</strong> ${productInfo.price}€</li>`;
                                }
                                
                                if (productInfo.stock !== undefined) {
                                    infoHTML += `<li><strong>Stock:</strong> ${productInfo.stock}</li>`;
                                }
                                
                                infoHTML += '</ul>';
                                
                                // Si hay enlace al producto, añadirlo
                                if (productInfo.permalink) {
                                    infoHTML += `<a href="${productInfo.permalink}" class="button" target="_blank">Ver producto</a>`;
                                }
                                
                                // Si hay enlace de edición, añadirlo
                                if (productInfo.edit_link) {
                                    infoHTML += `<a href="${productInfo.edit_link}" class="button" target="_blank">Editar producto</a>`;
                                }
                                
                                infoHTML += '</div>';
                                
                                // Añadir después del mensaje de éxito
                                $resultContainer.find('.notice').append(infoHTML);
                            }
                        } else {
                            let errorMsg = 'Error al sincronizar el producto.';
                            
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                                
                                // Verificar si es un mensaje de bloqueo para mostrar el botón de desbloqueo
                                addEmergencyUnlockButton(errorMsg);
                            }
                            
                            showResult('error', errorMsg);
                        }
                    },
                    error: function(xhr, status, error) {
                        // Marcar que la petición ha terminado
                        searchConfig.isRequestInProgress = false;
                        
                        console.error(`[ERROR] Error AJAX: ${status} - ${error}`);
                        
                        // Determinar si debemos reintentar
                        if (['timeout', 'error', 'parsererror', 'abort'].includes(status) && retryCount < searchConfig.maxRetries) {
                            console.log(`[INFO] Preparando reintento #${retryCount+1} después de error: ${status}`);
                            // Llamar recursivamente con incremento del contador
                            syncProduct(retryCount + 1);
                        } else {
                            // No más reintentos o error que no justifica reintentos
                            let errorMsg = `Error de comunicación: ${status}`;
                            
                            try {
                                // Intentar extraer mensaje de error de la respuesta JSON
                                const jsonResponse = JSON.parse(xhr.responseText);
                                
                                if (jsonResponse && jsonResponse.data && jsonResponse.data.message) {
                                    errorMsg = jsonResponse.data.message;
                                    
                                    // Verificar si es un mensaje de bloqueo para mostrar el botón de desbloqueo
                                    addEmergencyUnlockButton(errorMsg);
                                }
                            } catch (e) {
                                // Ignorar error de parsing
                            }
                            
                            showResult('error', errorMsg);
                            $syncButton.prop('disabled', false);
                            $spinner.css('visibility', 'hidden');
                        }
                    },
                    complete: function() {
                        // No reseteamos isRequestInProgress aquí porque ya se hace en success/error
                        console.log('[INFO] Petición de sincronización completada');
                        
                        // Solo habilitamos el botón si no se va a reintentar
                        if (!searchConfig.isRequestInProgress) {
                            $syncButton.prop('disabled', false);
                            $spinner.css('visibility', 'hidden');
                        }
                    }
                });
            }, delayTime);
            
        } catch (error) {
            console.error('[ERROR] Error en función syncProduct:', error);
            searchConfig.isRequestInProgress = false;
            showResult('error', 'Error interno: ' + error.message);
            $syncButton.prop('disabled', false);
            $spinner.css('visibility', 'hidden');
        }
    }
    
    /**
     * Muestra el resultado de la operación
     */
    function showResult(type, message) {
        if (!$resultContainer.length) {
            $resultContainer = $('<div id="sync-result"></div>');
            $form.after($resultContainer);
        }
        
        // Mapear tipo a clase de notificación de WordPress
        let className;
        switch(type) {
            case 'success':
                className = 'notice-success';
                break;
            case 'error':
                className = 'notice-error';
                break;
            case 'warning':
                className = 'notice-warning';
                break;
            case 'info':
            default:
                className = 'notice-info';
                break;
        }
        
        // Crear HTML de la notificación
        $resultContainer.html(
            `<div class="notice ${className} is-dismissible"><p>${message}</p></div>`
        );
        
        // Scroll al resultado
        $('html, body').animate({
            scrollTop: $resultContainer.offset().top - 50
        }, 500);
        
        // Hacer que los notices sean descartables como en WP admin
        if (window.jQuery && window.jQuery.fn.on) {
            setTimeout(function() {
                window.wp?.notices?.init();
            }, 100);
        }
    }
    
    /**
     * Añade un botón de desbloqueo de emergencia si se detecta que hay una sincronización bloqueada
     */
    function addEmergencyUnlockButton(errorMessage) {
        // Solo mostramos el botón si el mensaje indica que hay un bloqueo
        if (errorMessage && (
            errorMessage.includes('sincronización en curso') || 
            errorMessage.includes('Ya hay una') ||
            errorMessage.includes('sincronización masiva') ||
            errorMessage.includes('bloqueada') ||
            errorMessage.includes('bloqueado')
        )) {
            console.log('[INFO] Detectado mensaje de bloqueo, mostrando botón de desbloqueo');
            
            // Mostrar el botón de desbloqueo existente y darle estilo de advertencia
            $unlockButton.show()
                .css({
                    'background': '#f44336', 
                    'color': 'white', 
                    'border-color': '#d32f2f',
                    'margin-top': '10px'
                })
                .text('Desbloquear sincronización');
                
            // Agregar una explicación
            if ($('#unlock-explanation').length === 0) {
                $unlockButton.after(
                    $('<p id="unlock-explanation" class="description">')
                        .text('Si la sincronización se ha quedado bloqueada, usa este botón para liberarla.')
                        .css('margin-top', '5px')
                );
            }
        } else {
            // Si no es un error de bloqueo, asegurarse de que el botón esté oculto
            $unlockButton.hide();
            $('#unlock-explanation').remove();
        }
    }
    
    /**
     * Desbloquea una sincronización que se ha quedado bloqueada
     * @param {number} retryCount Contador de reintentos actual
     */
    function unlockSync(retryCount = 0) {
        try {
            // Verificar si se ha alcanzado el máximo de reintentos
            if (retryCount > searchConfig.maxRetries) {
                console.error(`[ERROR] Se ha alcanzado el máximo de reintentos (${searchConfig.maxRetries}) para desbloqueo`);
                showResult('error', `No se pudo desbloquear después de ${searchConfig.maxRetries} intentos. Contacta al administrador.`);
                $unlockButton.prop('disabled', false);
                return;
            }
            
            // Verificar si hay una petición en curso
            if (searchConfig.isRequestInProgress) {
                console.warn('[WARN] Ya hay una petición en curso');
                return;
            }
            
            // Mostrar spinner y deshabilitar botón para evitar doble envío
            $unlockButton.prop('disabled', true);
            
            // Mostrar mensaje inicial
            if (retryCount === 0) {
                showResult('info', 'Intentando desbloquear la sincronización...');
            } else {
                console.log(`[INFO] Reintento #${retryCount} para desbloquear sincronización`);
            }
            
            // Calcular tiempo de espera para reintento (si aplica)
            const delayTime = retryCount > 0 
                ? searchConfig.retryDelay * Math.pow(searchConfig.retryMultiplier, retryCount - 1)
                : 0;
            
            // Esperar si es necesario (para reintentos)
            setTimeout(function() {
                console.log('[INFO] Enviando petición para desbloquear sincronización');
                
                // Marcar que hay una petición en curso
                searchConfig.isRequestInProgress = true;
                
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'mi_sync_unlock',
                        _ajax_nonce: getNonceValue(),
                        lock_type: 'all' // Intentar desbloquear todos los tipos
                    },
                    success: function(response) {
                        // Marcar que la petición ha terminado
                        searchConfig.isRequestInProgress = false;
                        searchConfig.currentRetryCount = 0; // Resetear contador
                        
                        console.log('[INFO] Respuesta de desbloqueo:', response);
                        
                        if (response.success) {
                            showResult('success', response.data.message || 'Sincronización desbloqueada correctamente');
                            
                            // Ocultar botón de desbloqueo
                            $unlockButton.hide();
                            
                            // Ocultar explicación si existe
                            $('#unlock-explanation').hide();
                            
                        } else {
                            let errorMsg = 'Error al desbloquear la sincronización.';
                            
                            if (response.data && response.data.message) {
                                errorMsg = response.data.message;
                            }
                            
                            showResult('error', errorMsg);
                        }
                        
                        $unlockButton.prop('disabled', false);
                    },
                    error: function(xhr, status, error) {
                        // Marcar que la petición ha terminado
                        searchConfig.isRequestInProgress = false;
                        
                        console.error(`[ERROR] Error AJAX durante desbloqueo: ${status} - ${error}`);
                        
                        // Determinar si debemos reintentar
                        if (['timeout', 'error', 'parsererror', 'abort'].includes(status) && retryCount < searchConfig.maxRetries) {
                            console.log(`[INFO] Preparando reintento #${retryCount+1} para desbloqueo después de error: ${status}`);
                            // Llamar recursivamente con incremento del contador
                            unlockSync(retryCount + 1);
                        } else {
                            // No más reintentos o error que no justifica reintentos
                            let errorMsg = `Error durante el desbloqueo: ${status}`;
                            
                            try {
                                // Intentar extraer mensaje de error de la respuesta JSON
                                const jsonResponse = JSON.parse(xhr.responseText);
                                
                                if (jsonResponse && jsonResponse.data && jsonResponse.data.message) {
                                    errorMsg = jsonResponse.data.message;
                                }
                            } catch (e) {
                                // Ignorar error de parsing
                            }
                            
                            showResult('error', errorMsg);
                            $unlockButton.prop('disabled', false);
                        }
                    }
                });
            }, delayTime);
            
        } catch (error) {
            console.error('[ERROR] Error en función unlockSync:', error);
            searchConfig.isRequestInProgress = false;
            showResult('error', 'Error interno: ' + error.message);
            $unlockButton.prop('disabled', false);
        }
    }
    
    // Verificar disponibilidad de ajaxurl con múltiples fuentes
    if (typeof ajaxurl === 'undefined') {
        console.warn('[ADVERTENCIA] Variable ajaxurl no disponible directamente.');
        
        // 1. Intentar obtener desde objeto localizado
        if (typeof miSyncSingleProduct !== 'undefined' && miSyncSingleProduct.ajaxurl) {
            window.ajaxurl = miSyncSingleProduct.ajaxurl;
            console.log('[INFO] ajaxurl obtenido de miSyncSingleProduct:', window.ajaxurl);
        } 
        // 2. Camino predeterminado como fallback
        else {
            window.ajaxurl = '/wp-admin/admin-ajax.php';
            console.log('[INFO] ajaxurl establecido por defecto a:', window.ajaxurl);
        }
    } else {
        console.log('[INFO] ajaxurl ya está disponible:', ajaxurl);
    }
}
