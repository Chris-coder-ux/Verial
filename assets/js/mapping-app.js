/**
 * Aplicación de mapeos complejos para Mi Integración API
 * Utiliza Grid.js para crear una interfaz interactiva
 *
 * @package MiIntegracionApi
 */
(function() {
  'use strict';

  // Esperar a que el DOM esté completamente cargado
  document.addEventListener('DOMContentLoaded', function() {
    // Verificar que estamos en la página correcta
    const appContainer = document.getElementById('mi-mapping-app');
    if (!appContainer) return;

    // Definir tipos de mapeo disponibles
    const mappingTypes = {
      'product': 'Producto',
      'category': 'Categoría',
      'attribute': 'Atributo',
      'tax': 'Impuesto',
      'custom': 'Personalizado'
    };

    // Crear el contenedor para la aplicación
    appContainer.innerHTML = `
            <div class="mi-mapping-container">
                <div class="mi-mapping-form card">
                    <h2>${wp.i18n.__('Añadir nuevo mapeo', 'mi-integracion-api')}</h2>
                    <form id="mi-new-mapping-form">
                        <div class="form-field">
                            <label for="mi-mapping-source">${wp.i18n.__('Fuente (API)', 'mi-integracion-api')}</label>
                            <input type="text" id="mi-mapping-source" name="source" required>
                        </div>
                        <div class="form-field">
                            <label for="mi-mapping-target">${wp.i18n.__('Destino (WooCommerce)', 'mi-integracion-api')}</label>
                            <input type="text" id="mi-mapping-target" name="target" required>
                        </div>
                        <div class="form-field">
                            <label for="mi-mapping-type">${wp.i18n.__('Tipo de mapeo', 'mi-integracion-api')}</label>
                            <select id="mi-mapping-type" name="type" required>
                                ${Object.entries(mappingTypes).map(([value, label]) => 
    `<option value="${value}">${label}</option>`
  ).join('')}
                            </select>
                        </div>
                        <div class="form-field">
                            <button type="submit" class="button button-primary">${wp.i18n.__('Guardar mapeo', 'mi-integracion-api')}</button>
                        </div>
                    </form>
                </div>

                <div class="mi-mapping-list card">
                    <h2>${wp.i18n.__('Mapeos existentes', 'mi-integracion-api')}</h2>
                    <div class="mi-mapping-search">
                        <input type="search" id="mi-mapping-search" placeholder="${wp.i18n.__('Buscar mapeos...', 'mi-integracion-api')}">
                    </div>
                    <div id="mi-mapping-grid"></div>
                </div>
            </div>
        `;

    // Inicializar Grid.js
    const grid = new gridjs.Grid({
      columns: [
        { id: 'source', name: wp.i18n.__('Fuente', 'mi-integracion-api') },
        { id: 'target', name: wp.i18n.__('Destino', 'mi-integracion-api') },
        { 
          id: 'type', 
          name: wp.i18n.__('Tipo', 'mi-integracion-api'),
          formatter: (cell) => mappingTypes[cell] || cell
        },
        {
          id: 'actions',
          name: wp.i18n.__('Acciones', 'mi-integracion-api'),
          formatter: (_, row) => {
            // Crear botón de eliminar
            return gridjs.h('button', {
              className: 'button button-small button-link-delete',
              onClick: () => confirmDelete(row.cells[3].data)
            }, wp.i18n.__('Eliminar', 'mi-integracion-api'));
          }
        },
        {
          id: 'id',
          name: 'ID',
          hidden: true
        }
      ],
      search: true,
      sort: true,
      pagination: {
        limit: 10,
        summary: true
      },
      language: {
        search: {
          placeholder: wp.i18n.__('Buscar...', 'mi-integracion-api')
        },
        pagination: {
          previous: wp.i18n.__('Anterior', 'mi-integracion-api'),
          next: wp.i18n.__('Siguiente', 'mi-integracion-api'),
          showing: wp.i18n.__('Mostrando', 'mi-integracion-api'),
          results: () => wp.i18n.__('registros', 'mi-integracion-api'),
          of: wp.i18n.__('de', 'mi-integracion-api')
        }
      },
      style: {
        table: {
          width: '100%'
        }
      },
      className: {
        table: 'wp-list-table widefat fixed striped',
        thead: 'thead-dark',
        search: 'gridjs-search',
        pagination: 'tablenav-pages'
      }
    }).render(document.getElementById('mi-mapping-grid'));

    // Cargar datos iniciales
    loadMappings();

    // Manejar el formulario de nuevo mapeo
    document.getElementById('mi-new-mapping-form').addEventListener('submit', function(e) {
      e.preventDefault();
      const form = e.target;
      const formData = new FormData(form);

      const mapping = {
        source: formData.get('source'),
        target: formData.get('target'),
        type: formData.get('type')
      };

      saveMapping(mapping, form);
    });

    // Vincular la búsqueda personalizada con Grid.js
    document.getElementById('mi-mapping-search').addEventListener('input', function(e) {
      grid.search(e.target.value);
    });

    /**
         * Carga los mapeos desde la API
         */
    function loadMappings() {
      // Mostrar indicador de carga
      grid.updateConfig({
        data: [],
        loading: true
      }).forceRender();

      // Realizar petición a la API
      fetch(`${window.ajaxurl}?action=mi_get_mappings`, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.mi_mapping_data && window.mi_mapping_data.nonce ? window.mi_mapping_data.nonce : ''
        }
      })
        .then(response => {
          if (!response.ok) {
            throw new Error(wp.i18n.__('Error al cargar los mapeos', 'mi-integracion-api'));
          }
          return response.json();
        })
        .then(response => {
          if (!response.success) {
            throw new Error(response.data || wp.i18n.__('Error al cargar los mapeos', 'mi-integracion-api'));
          }

          // Transformar datos para Grid.js
          const data = (response.data || []).map(item => [
            item.source,
            item.target,
            item.type,
            item.id,
            item.id
          ]);

          // Actualizar grid con los datos
          grid.updateConfig({
            data: data,
            loading: false
          }).forceRender();
        })
        .catch(error => {
          console.error('Error:', error);
          // Mostrar mensaje de error
          grid.updateConfig({
            data: [],
            loading: false
          }).forceRender();

          showNotice('error', error.message);
        });
    }

    /**
         * Guarda un nuevo mapeo
         */
    function saveMapping(mapping, form) {
      fetch(`${window.ajaxurl}?action=mi_save_mapping`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.mi_mapping_data && window.mi_mapping_data.nonce ? window.mi_mapping_data.nonce : ''
        },
        body: JSON.stringify(mapping)
      })
        .then(response => {
          if (!response.ok) {
            throw new Error(wp.i18n.__('Error al guardar el mapeo', 'mi-integracion-api'));
          }
          return response.json();
        })
        .then(response => {
          if (!response.success) {
            throw new Error(response.data || wp.i18n.__('Error al guardar el mapeo', 'mi-integracion-api'));
          }

          // Limpiar formulario
          form.reset();

          // Mostrar mensaje de éxito
          showNotice('success', wp.i18n.__('Mapeo guardado correctamente', 'mi-integracion-api'));

          // Recargar datos
          loadMappings();
        })
        .catch(error => {
          console.error('Error:', error);
          showNotice('error', error.message);
        });
    }

    /**
         * Confirma y elimina un mapeo
         */
    function confirmDelete(id) {
      if (confirm(wp.i18n.__('¿Estás seguro de que quieres eliminar este mapeo?', 'mi-integracion-api'))) {
        deleteMapping(id);
      }
    }

    /**
         * Elimina un mapeo
         */
    function deleteMapping(id) {
      fetch(`${window.ajaxurl}?action=mi_delete_mapping`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.mi_mapping_data && window.mi_mapping_data.nonce ? window.mi_mapping_data.nonce : ''
        },
        body: JSON.stringify({ id: id })
      })
        .then(response => {
          if (!response.ok) {
            throw new Error(wp.i18n.__('Error al eliminar el mapeo', 'mi-integracion-api'));
          }
          return response.json();
        })
        .then(response => {
          if (!response.success) {
            throw new Error(response.data || wp.i18n.__('Error al eliminar el mapeo', 'mi-integracion-api'));
          }

          // Mostrar mensaje de éxito
          showNotice('success', wp.i18n.__('Mapeo eliminado correctamente', 'mi-integracion-api'));

          // Recargar datos
          loadMappings();
        })
        .catch(error => {
          console.error('Error:', error);
          showNotice('error', error.message);
        });
    }

    /**
         * Muestra un aviso en la parte superior de la página
         */
    function showNotice(type, message) {
      const noticeDiv = document.createElement('div');
      noticeDiv.className = `notice notice-${type} is-dismissible`;
      noticeDiv.innerHTML = `<p>${message}</p>`;

      // Insertar al principio del contenedor
      const container = document.querySelector('.mi-mapping-container');
      container.insertBefore(noticeDiv, container.firstChild);

      // Auto-eliminar después de 5 segundos
      setTimeout(() => {
        if (noticeDiv.parentNode) {
          noticeDiv.parentNode.removeChild(noticeDiv);
        }
      }, 5000);
    }
  });
})();
