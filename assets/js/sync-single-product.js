/**
 * JavaScript para la página de sincronización individual de productos
 *
 * Este script maneja:
 * - Validación del formulario
 * - Autocompletado de productos
 * - Carga de categorías y fabricantes
 * - Envío del formulario de sincronización
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
    const $spinner = $('.spinner');
    const $resultContainer = $('#sync-result');
    
    // Cargar categorías y fabricantes al cargar la página
    initSelects();
    
    // Configurar autocompletado para SKU y nombre
    setupAutocomplete();
    
    // Manejar envío del formulario
    $form.on('submit', function(e) {
        e.preventDefault();
        syncProduct();
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
                _ajax_nonce: getNonceValue()
            },
            beforeSend: function(xhr) {
                console.log('[DEBUG] Antes de enviar petición de categorías:');
                console.log('- URL:', ajaxurl);
                console.log('- Action:', 'mi_sync_get_categorias');
                console.log('- Nonce:', getNonceValue());
            },
            success: function(response) {
                console.log('[DEBUG] Respuesta categorías completa:', response);
                if (response.success && response.data && response.data.categories) {
                    console.log('[DEBUG] Categorías recibidas:', response.data.categories);
                    console.log('[DEBUG] Tipo de datos recibido:', typeof response.data.categories);
                    console.log('[DEBUG] ¿Es objeto?', $.isPlainObject(response.data.categories));
                    console.log('[DEBUG] ¿Es array?', Array.isArray(response.data.categories));
                    populateSelect($categoriaSelect, response.data.categories);
                } else {
                    console.error('[ERROR] Error al cargar categorías:', response);
                    console.error('[ERROR] Success:', response.success);
                    console.error('[ERROR] Data exist:', !!response.data);
                    console.error('[ERROR] Categories exist:', response.data && !!response.data.categories);
                    alert('Error al cargar categorías. Revisa la consola para más detalles.');
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
                _ajax_nonce: getNonceValue()
            },
            beforeSend: function(xhr) {
                console.log('[DEBUG] Antes de enviar petición de fabricantes:');
                console.log('- URL:', ajaxurl);
                console.log('- Action:', 'mi_sync_get_fabricantes');
                console.log('- Nonce:', getNonceValue());
            },
            success: function(response) {
                console.log('[DEBUG] Respuesta fabricantes completa:', response);
                if (response.success && (response.data.manufacturers || response.data.fabricantes)) {
                    const datos = response.data.manufacturers || response.data.fabricantes;
                    console.log('[DEBUG] Fabricantes recibidos:', datos);
                    console.log('[DEBUG] Tipo de datos recibido:', typeof datos);
                    console.log('[DEBUG] ¿Es objeto?', $.isPlainObject(datos));
                    console.log('[DEBUG] ¿Es array?', Array.isArray(datos));
                    populateSelect($fabricanteSelect, datos);
                } else {
                    console.error('[ERROR] Error al cargar fabricantes:', response);
                    console.error('[ERROR] Success:', response.success);
                    console.error('[ERROR] Data exist:', !!response.data);
                    console.error('[ERROR] Manufacturers exist:', response.data && (!!response.data.manufacturers || !!response.data.fabricantes));
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
        console.log('[DEBUG] Items para poblar select:', items);
        
        // Verificar si items es válido
        if (!items) {
            console.error('[ERROR] Items es nulo o indefinido');
            return;
        }
        
        if (typeof items !== 'object') {
            console.error('[ERROR] Items no es un objeto:', typeof items);
            return;
        }
        
        // Mantener la opción por defecto
        const defaultOption = $select.find('option').first().clone();
        console.log('[DEBUG] Opción por defecto:', defaultOption.text());
        $select.empty().append(defaultOption);
        
        // Añadir nuevas opciones
        let count = 0;
        try {
            $.each(items, function(id, name) {
                console.log('[DEBUG] Añadiendo opción:', id, name);
                $select.append($('<option>', {
                    value: id,
                    text: name
                }));
                count++;
            });
            console.log('[DEBUG] Total de opciones añadidas:', count);
        } catch (error) {
            console.error('[ERROR] Error al añadir opciones al select:', error);
        }
    }
    
    /**
     * Configura el autocompletado para SKU y nombre
     */
    function setupAutocomplete() {
        // Autocompletado para ID/Código de barras
        $skuInput.autocomplete({
            minLength: 2,
            source: function(request, response) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'mi_search_product',
                        _ajax_nonce: getNonceValue(),
                        search: request.term,
                        field: 'id' // Buscar por ID o código de barras
                    },
                    success: function(data) {
                        if (data.success && data.data.products) {
                            response(data.data.products);
                        } else {
                            response([]);
                        }
                    },
                    error: function() {
                        response([]);
                    }
                });
            },
            select: function(event, ui) {
                $skuInput.val(ui.item.value);
                // Si es seleccionado por ID o código, limpiar nombre
                $nombreInput.val('');
                return false;
            }
        }).autocomplete("instance")._renderItem = function(ul, item) {
            return $("<li>")
                .append("<div><strong>" + item.value + "</strong> - " + item.label + "</div>")
                .appendTo(ul);
        };
        
        // Autocompletado para nombre
        $nombreInput.autocomplete({
            minLength: 3,
            source: function(request, response) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'mi_search_product',
                        _ajax_nonce: getNonceValue(),
                        search: request.term,
                        field: 'name'
                    },
                    success: function(data) {
                        if (data.success && data.data.products) {
                            response(data.data.products);
                        } else {
                            response([]);
                        }
                    },
                    error: function() {
                        response([]);
                    }
                });
            },
            select: function(event, ui) {
                $nombreInput.val(ui.item.label);
                // Si se selecciona por nombre, poner el ID/código en el otro campo
                if (ui.item.id) {
                    $skuInput.val(ui.item.id);
                }
                return false;
            }
        });
    }
    
    /**
     * Sincroniza el producto con la información del formulario
     */
    function syncProduct() {
        // Validación básica
        if ($skuInput.val() === '' && $nombreInput.val() === '' && 
            $categoriaSelect.val() === '' && $fabricanteSelect.val() === '') {
            showResult('error', 'Debes especificar al menos un campo de búsqueda');
            return;
        }
        
        // Mostrar indicador de carga
        $syncButton.prop('disabled', true);
        $spinner.css('visibility', 'visible');
        
        // Enviar solicitud
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'mi_sync_single_product',
                _ajax_nonce: getNonceValue(),
                sku: $skuInput.val(),
                nombre: $nombreInput.val(),
                categoria: $categoriaSelect.val(),
                fabricante: $fabricanteSelect.val()
            },
            success: function(response) {
                console.log('Respuesta de sincronización:', response);
                if (response.success) {
                    showResult('success', response.data.message);
                } else {
                    showResult('error', response.data.message || 'Error al sincronizar el producto');
                }
            },
            error: function(xhr, status, error) {
                console.error('Error AJAX al sincronizar:', status, error);
                showResult('error', 'Error de comunicación: ' + status);
            },
            complete: function() {
                $syncButton.prop('disabled', false);
                $spinner.css('visibility', 'hidden');
            }
        });
    }
    
    /**
     * Muestra el resultado de la operación
     */
    function showResult(type, message) {
        if (!$resultContainer.length) {
            $resultContainer = $('<div id="sync-result"></div>');
            $form.after($resultContainer);
        }
        
        const className = type === 'success' ? 'notice-success' : 'notice-error';
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
