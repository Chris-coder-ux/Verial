/**
 * Manejador de pestañas para Mi Integración API
 * Versión mejorada 2025-06-05
 * Este script maneja la navegación entre pestañas en las páginas del plugin
 */
jQuery(document).ready(function($) {
  'use strict';
    
  // Variable para evitar inicializaciones múltiples
  if (window.miIntegracionApiTabsInitialized) {
    console.log('Sistema de pestañas ya inicializado. Abortando inicialización duplicada.');
    return;
  }
    
  window.miIntegracionApiTabsInitialized = true;
  console.log('Inicializando sistema de pestañas (tabs-manager.js)');
    
  /**
     * Maneja los clics en las pestañas y cambia la visibilidad del contenido 
     * Esta función utiliza delegación de eventos para manejar pestañas agregadas dinámicamente
     */
  function initializeTabs() {
    // Remover cualquier controlador previo para evitar duplicados
    $(document).off('click', '.verial-tab-link, .mi-integracion-api-tab-link');
        
    // Añadir controlador de eventos usando delegación
    $(document).on('click', '.verial-tab-link, .mi-integracion-api-tab-link', function(e) {
      e.preventDefault();
            
      // Identificar el tab a mostrar
      var targetTab = $(this).data('tab');
      if (!targetTab) {
        console.warn('Pestaña sin atributo data-tab');
        return;
      }
            
      // Actualizar clases activas en los enlaces dentro del mismo grupo de pestañas
      var $tabGroup = $(this).closest('.verial-tabs, .mi-integracion-api-tabs');
      if ($tabGroup.length) {
        // Si la pestaña está en un grupo, solo actualizar ese grupo
        $tabGroup.find('.verial-tab-link, .mi-integracion-api-tab-link').removeClass('active');
      } else {
        // Si no, actualizar todas las pestañas (comportamiento heredado)
        $('.verial-tab-link, .mi-integracion-api-tab-link').removeClass('active');
      }
      $(this).addClass('active');
            
      // Ocultar todos los contenidos de pestañas y mostrar el seleccionado
      $('.verial-tab-content, .mi-integracion-api-tab-content').hide();
      var $targetContent = $('#' + targetTab);
            
      if ($targetContent.length) {
        $targetContent.show();
        // Disparar un evento personalizado que otros scripts pueden escuchar
        $(document).trigger('mi_integracion_api_tab_changed', [targetTab, $targetContent]);
      } else {
        console.warn('No se encontró contenido para la pestaña: ' + targetTab);
      }
            
      // Guardar el estado activo en localStorage para persistencia
      try {
        localStorage.setItem('mi_integracion_api_active_tab', targetTab);
        localStorage.setItem('mi_integracion_api_active_tab_page', window.location.pathname);
      } catch (e) {
        console.log('No se pudo guardar el estado de la pestaña');
      }
    });
  }
    
  /**
     * Restaura el estado de la última pestaña activa al cargar la página
     * Solo restaura estados de la misma página
     */
  function restoreTabState() {
    try {
      var lastActiveTab = localStorage.getItem('mi_integracion_api_active_tab');
      var lastActivePage = localStorage.getItem('mi_integracion_api_active_tab_page');
            
      // Solo restaurar si estamos en la misma página
      if (lastActiveTab && lastActivePage === window.location.pathname) {
        // Activar la pestaña guardada si existe
        var $tabLink = $('[data-tab="' + lastActiveTab + '"]');
        if ($tabLink.length) {
          console.log('Restaurando estado de pestaña: ' + lastActiveTab);
          $tabLink.click();
          return true;
        }
      }
      return false;
    } catch (e) {
      console.log('No se pudo restaurar el estado de la pestaña');
      return false;
    }
  }
    
  /**
     * Asegura que al menos una pestaña esté activa
     */
  function ensureActiveTab() {
    // Ver si ya hay alguna pestaña activa
    var $activeTab = $('.verial-tab-link.active, .mi-integracion-api-tab-link.active');
    if ($activeTab.length === 0) {
      console.log('No hay pestañas activas, activando la primera');
      // Obtener grupos de pestañas
      var $tabGroups = $('.verial-tabs, .mi-integracion-api-tabs');
            
      if ($tabGroups.length) {
        // Para cada grupo, activar la primera pestaña
        $tabGroups.each(function() {
          var $firstTab = $(this).find('.verial-tab-link, .mi-integracion-api-tab-link').first();
          if ($firstTab.length && !$firstTab.hasClass('active')) {
            $firstTab.click();
          }
        });
      } else {
        // Si no hay grupos definidos, simplemente activar la primera pestaña
        var $firstTab = $('.verial-tab-link, .mi-integracion-api-tab-link').first();
        if ($firstTab.length) {
          $firstTab.click();
        }
      }
      return true;
    }
    return false;
  }
    
  /**
     * Verifica que el contenido de las pestañas sea visible correctamente
     */
  function verifyTabContentVisibility() {
    // Si no hay pestañas de contenido visibles, mostrar la que corresponda a la pestaña activa
    var $visibleTabContent = $('.verial-tab-content:visible, .mi-integracion-api-tab-content:visible');
        
    if ($visibleTabContent.length === 0) {
      console.log('No hay contenido de pestañas visible, intentando mostrar el correspondiente');
      var $activeTab = $('.verial-tab-link.active, .mi-integracion-api-tab-link.active');
            
      if ($activeTab.length) {
        // Mostrar el contenido de la pestaña activa
        $activeTab.each(function() {
          var activeTabId = $(this).data('tab');
          if (activeTabId) {
            var $content = $('#' + activeTabId);
            if ($content.length) {
              $content.show();
              console.log('Mostrando contenido para pestaña: ' + activeTabId);
            }
          }
        });
      } else {
        // Si no hay pestaña activa, mostrar el primer contenido
        var $firstContent = $('.verial-tab-content, .mi-integracion-api-tab-content').first();
        if ($firstContent.length) {
          $firstContent.show();
          console.log('Mostrando primer contenido de pestaña disponible');
        }
      }
      return true;
    }
    return false;
  }
    
  // Inicializar el sistema de pestañas
  initializeTabs();
    
  // Primero intentar restaurar el estado, si no funciona asegurar que haya una pestaña activa
  if (!restoreTabState()) {
    ensureActiveTab();
  }
    
  // Finalmente, verificar que el contenido sea visible
  verifyTabContentVisibility();
    
  // Dar retroalimentación sobre pestañas encontradas
  var tabCount = $('.verial-tab-link, .mi-integracion-api-tab-link').length;
  var contentCount = $('.verial-tab-content, .mi-integracion-api-tab-content').length;
  console.log('Sistema de pestañas inicializado. Pestañas: ' + tabCount + ', Contenidos: ' + contentCount);
});
