/**
 * Funcionalidades especiales para dispositivos móviles
 * 
 * Este script proporciona mejoras para la experiencia de usuario en dispositivos
 * móviles y táctiles en la interfaz de administración del plugin.
 */

(function($, window, document) {
  'use strict';

  // Detector de capacidades táctiles
  const isTouchDevice = () => {
    return (('ontouchstart' in window) || 
                (navigator.maxTouchPoints > 0) || 
                (navigator.msMaxTouchPoints > 0));
  };

  // Detectar orientación
  const isLandscape = () => {
    return window.innerWidth > window.innerHeight;
  };

  // Clase principal
  const MobileOptimizer = {
    init: function() {
      // Marcar el documento como táctil si corresponde
      if (isTouchDevice()) {
        document.body.classList.add('mi-api-touch-device');
      }
            
      // Configurar la orientación
      this.handleOrientationChange();
            
      // Inicializar componentes específicos
      this.initTouchComponents();
      this.makeTablesTouch();
      this.enhanceTabNavigation();
      this.setupForms();
            
      // Eventos
      this.bindEvents();

      // Meta viewport verificación (asegura que la escala sea correcta)
      this.checkMetaViewport();
    },
        
    checkMetaViewport: function() {
      // Verificar que exista el meta viewport adecuado
      let hasProperViewport = false;
            
      document.querySelectorAll('meta[name="viewport"]').forEach(meta => {
        if (meta.getAttribute('content').indexOf('width=device-width') !== -1) {
          hasProperViewport = true;
        }
      });
            
      // Si no tiene viewport adecuado, agregarlo
      if (!hasProperViewport) {
        const meta = document.createElement('meta');
        meta.name = 'viewport';
        meta.content = 'width=device-width, initial-scale=1, maximum-scale=1';
        document.head.appendChild(meta);
        console.log('Meta viewport añadido para optimización móvil');
      }
    },
        
    bindEvents: function() {
      // Manejar cambio de orientación
      window.addEventListener('resize', this.handleOrientationChange.bind(this));
      window.addEventListener('orientationchange', this.handleOrientationChange.bind(this));
            
      // Si se necesita pull-to-refresh personalizado
      if (this.isAppleDevice() && isTouchDevice()) {
        this.setupPullToRefresh();
      }
    },
        
    handleOrientationChange: function() {
      document.body.classList.toggle('mi-api-landscape', isLandscape());
      document.body.classList.toggle('mi-api-portrait', !isLandscape());
            
      // Ajustes específicos para el modo horizontal
      if (isLandscape() && window.innerHeight < 500) {
        document.body.classList.add('mi-api-small-landscape');
      } else {
        document.body.classList.remove('mi-api-small-landscape');
      }
            
      // Evento personalizado para componentes
      document.dispatchEvent(new CustomEvent('mi-api-orientation-change', {
        detail: { isLandscape: isLandscape() }
      }));
    },
        
    isAppleDevice: function() {
      return /iPhone|iPad|iPod|Mac/.test(navigator.userAgent);
    },
        
    initTouchComponents: function() {
      // Hacer que los elementos de navegación sean más grandes para táctil
      if (isTouchDevice()) {
        document.querySelectorAll('.nav-tab, .button, select').forEach(el => {
          el.classList.add('mi-api-touch-target');
        });
      }
            
      // Convertir las tablas normales en responsivas
      document.querySelectorAll('table:not(.mi-api-responsive-table)').forEach(table => {
        const wrapper = document.createElement('div');
        wrapper.classList.add('mi-api-responsive-table', 'mi-api-touch-scrollable');
        table.parentNode.insertBefore(wrapper, table);
        wrapper.appendChild(table);
                
        // Detectar cuando el scroll llegue al final para UI
        wrapper.addEventListener('scroll', function() {
          const isEnd = wrapper.scrollLeft + wrapper.clientWidth >= wrapper.scrollWidth - 5;
          wrapper.classList.toggle('mi-api-table-end', isEnd);
        });
      });
    },
        
    makeTablesTouch: function() {
      // Para pantallas pequeñas, convertir tablas en tarjetas
      if (window.innerWidth <= 600) {
        document.querySelectorAll('.mi-table').forEach(table => {
          // Solo transformar si no se ha hecho antes
          if (table.getAttribute('data-mobile-transformed') === 'true') {
            return;
          }
                    
          // Crear vista de tarjetas
          const headers = Array.from(table.querySelectorAll('thead th'))
            .map(th => th.textContent.trim());
                    
          const cardView = document.createElement('div');
          cardView.classList.add('mi-api-mobile-card-view');
                    
          // Convertir filas en tarjetas
          table.querySelectorAll('tbody tr').forEach(row => {
            const card = document.createElement('div');
            card.classList.add('mi-api-mobile-card');
                        
            Array.from(row.querySelectorAll('td')).forEach((cell, idx) => {
              const cardRow = document.createElement('div');
              cardRow.classList.add('mi-api-mobile-card-row');
                            
              const label = document.createElement('div');
              label.classList.add('mi-api-mobile-card-label');
              label.textContent = headers[idx] || '';
                            
              const value = document.createElement('div');
              value.classList.add('mi-api-mobile-card-value');
              value.innerHTML = cell.innerHTML;
                            
              cardRow.appendChild(label);
              cardRow.appendChild(value);
              card.appendChild(cardRow);
            });
                        
            cardView.appendChild(card);
          });
                    
          // Insertar después de la tabla (mantener la tabla para pantallas grandes)
          table.parentNode.insertBefore(cardView, table.nextSibling);
          table.setAttribute('data-mobile-transformed', 'true');
                    
          // Mostrar/ocultar según el tamaño
          if (window.innerWidth <= 600) {
            table.style.display = 'none';
            cardView.style.display = 'block';
          } else {
            table.style.display = 'table';
            cardView.style.display = 'none';
          }
                    
          // Agregar evento de resize
          window.addEventListener('resize', function() {
            if (window.innerWidth <= 600) {
              table.style.display = 'none';
              cardView.style.display = 'block';
            } else {
              table.style.display = 'table';
              cardView.style.display = 'none';
            }
          });
        });
      }
    },
        
    enhanceTabNavigation: function() {
      // Convertir pestañas en menú desplegable en pantallas pequeñas
      if (window.innerWidth <= 480) {
        document.querySelectorAll('.nav-tab-wrapper').forEach(tabWrapper => {
          // Solo convertir si no se ha hecho antes
          if (tabWrapper.getAttribute('data-mobile-transformed') === 'true') {
            return;
          }
                    
          const tabs = Array.from(tabWrapper.querySelectorAll('.nav-tab'));
          const activeTab = tabWrapper.querySelector('.nav-tab-active');
                    
          // Crear selector desplegable
          const select = document.createElement('select');
          select.classList.add('mi-api-mobile-tabs-dropdown');
                    
          tabs.forEach(tab => {
            const option = document.createElement('option');
            option.value = tab.href || '#';
            option.textContent = tab.textContent.trim();
            option.selected = tab.classList.contains('nav-tab-active');
            select.appendChild(option);
          });
                    
          // Agregar evento de cambio
          select.addEventListener('change', function() {
            window.location.href = this.value;
          });
                    
          // Insertar antes del contenedor de tabs
          tabWrapper.parentNode.insertBefore(select, tabWrapper);
          tabWrapper.setAttribute('data-mobile-transformed', 'true');
                    
          // Mostrar/ocultar según el tamaño
          if (window.innerWidth <= 480) {
            tabWrapper.style.display = 'none';
            select.style.display = 'block';
          } else {
            tabWrapper.style.display = 'flex';
            select.style.display = 'none';
          }
                    
          // Agregar evento de resize
          window.addEventListener('resize', function() {
            if (window.innerWidth <= 480) {
              tabWrapper.style.display = 'none';
              select.style.display = 'block';
            } else {
              tabWrapper.style.display = 'flex';
              select.style.display = 'none';
            }
          });
        });
      }
    },
        
    setupForms: function() {
      // Mejorar formularios para móviles
      if (isTouchDevice()) {
        document.querySelectorAll('form').forEach(form => {
          form.classList.add('mi-api-mobile-form');
                    
          // Para prevenir el zoom automático en iOS
          form.querySelectorAll('input, select, textarea').forEach(input => {
            if (input.type !== 'checkbox' && input.type !== 'radio') {
              input.style.fontSize = '16px';
            }
          });
        });
                
        // Mostrar mensajes de error cerca del campo al que hacen referencia
        document.addEventListener('invalid', function(e) {
          const field = e.target;
          field.classList.add('mi-api-mobile-scroll-to-error');
                    
          // Scroll al primer campo con error
          setTimeout(() => {
            const firstError = document.querySelector('.mi-api-mobile-scroll-to-error');
            if (firstError) {
              firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
          }, 100);
        }, true);
      }
    },
        
    setupPullToRefresh: function() {
      // Implementación simple de pull-to-refresh
      let startY = 0;
      let pullDistance = 0;
      let isPulling = false;
      const threshold = 120;
            
      // Crear indicador
      const indicator = document.createElement('div');
      indicator.classList.add('mi-api-pull-indicator');
      indicator.innerHTML = '<span>&#8595; Suelta para actualizar</span>';
      document.body.appendChild(indicator);
            
      // Eventos táctiles
      document.addEventListener('touchstart', function(e) {
        // Solo activar si estamos al principio de la página
        if (window.scrollY === 0) {
          startY = e.touches[0].clientY;
          isPulling = true;
        }
      }, { passive: true });
            
      document.addEventListener('touchmove', function(e) {
        if (!isPulling) return;
                
        pullDistance = e.touches[0].clientY - startY;
                
        // Solo mostrar si tiramos hacia abajo y estamos al principio de la página
        if (pullDistance > 0 && window.scrollY === 0) {
          // Resistencia para que no se mueva demasiado
          const adjustedDistance = Math.min(pullDistance / 2, threshold);
          indicator.style.transform = `translateY(${adjustedDistance}px)`;
          indicator.style.opacity = adjustedDistance / threshold;
                    
          if (adjustedDistance >= threshold) {
            indicator.innerHTML = '<span>&#8593; Suelta para actualizar</span>';
          } else {
            indicator.innerHTML = '<span>&#8595; Desliza para actualizar</span>';
          }
                    
          // Prevenir scroll
          if (pullDistance > threshold) {
            return false;
          }
        }
      }, { passive: false });
            
      document.addEventListener('touchend', function(e) {
        if (!isPulling) return;
                
        if (pullDistance >= threshold && window.scrollY === 0) {
          // Mostrar indicador de carga
          indicator.innerHTML = '<span>Actualizando...</span>';
          indicator.classList.add('visible');
                    
          // Actualizar página
          setTimeout(function() {
            window.location.reload();
          }, 500);
        } else {
          // Ocultar indicador
          indicator.style.transform = '';
          indicator.style.opacity = '';
        }
                
        isPulling = false;
      }, { passive: true });
    }
  };

  // Inicializar cuando el DOM esté listo
  $(document).ready(function() {
    MobileOptimizer.init();
  });

})(jQuery, window, document);
