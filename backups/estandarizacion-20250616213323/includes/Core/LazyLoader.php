<?php
/**
 * Gestión de carga diferida (lazy loading) de componentes
 *
 * @package MiIntegracionApi\Core
 */

namespace MiIntegracionApi\Core;

// Evitar acceso directo
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clase que proporciona funcionalidad de carga diferida para componentes
 */
class LazyLoader {

	/**
	 * Almacena los observadores registrados para los componentes
	 *
	 * @var array
	 */
	private static $observers = array();

	/**
	 * Scripts registrados para carga diferida
	 *
	 * @var array
	 */
	private static $lazy_scripts = array();

	/**
	 * Inicializa los hooks necesarios
	 */
	public static function init() {
		// Añadir soporte para atributos de carga diferida en imágenes
		add_filter( 'wp_get_attachment_image_attributes', array( __CLASS__, 'add_lazyload_to_images' ), 10, 3 );

		// Añadir script auxiliar para carga diferida
		add_action( 'admin_footer', array( __CLASS__, 'add_lazyload_script' ) );
		add_action( 'wp_footer', array( __CLASS__, 'add_lazyload_script' ) );
	}

	/**
	 * Registra un observador para un componente
	 *
	 * @param string   $component_id Identificador único del componente
	 * @param callable $callback Función a ejecutar cuando el componente debe ser cargado
	 * @return bool True si el observador se registra correctamente
	 */
	public static function register_observer( $component_id, $callback ) {
		if ( ! is_callable( $callback ) ) {
			return false;
		}

		self::$observers[ $component_id ] = $callback;
		return true;
	}

	/**
	 * Añade atributos de carga diferida a las imágenes
	 *
	 * @param array        $attr Atributos de la imagen
	 * @param WP_Post      $attachment Objeto de adjunto
	 * @param string|array $size Tamaño de imagen solicitado
	 * @return array Atributos modificados
	 */
	public static function add_lazyload_to_images( $attr, $attachment, $size ) {
		// No aplicar en admin o si es un RSS feed
		if ( is_admin() || is_feed() ) {
			return $attr;
		}

		// Guardar la URL original en data-src y usar un placeholder
		if ( isset( $attr['src'] ) ) {
			// Obtener dimensiones seguras
			$width  = isset( $attr['width'] ) ? $attr['width'] : '100';
			$height = isset( $attr['height'] ) ? $attr['height'] : '100';

			$attr['data-src'] = $attr['src'];
			$attr['src']      = 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"%3E%3C/svg%3E';
			$attr['class']    = isset( $attr['class'] ) ? $attr['class'] . ' mi-lazy-image' : 'mi-lazy-image';
			$attr['loading']  = 'lazy';
		}

		return $attr;
	}

	/**
	 * Añade script para manejar la carga diferida con IntersectionObserver
	 */
	public static function add_lazyload_script() {
		if ( defined( 'MI_DISABLE_LAZYLOAD' ) && MI_DISABLE_LAZYLOAD ) {
			return;
		}

		?>
<script>
(function() {
	// Función para cargar imágenes diferidas
	function handleLazyImages() {
		if (!('IntersectionObserver' in window)) {
			// Fallback para navegadores que no soportan IntersectionObserver
			const lazyImages = document.querySelectorAll('.mi-lazy-image');
			lazyImages.forEach(img => {
				if (img.dataset.src) {
					img.src = img.dataset.src;
				}
			});
			return;
		}
		
		const imageObserver = new IntersectionObserver(function(entries, observer) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					const img = entry.target;
					if (img.dataset.src) {
						img.src = img.dataset.src;
						img.classList.remove('mi-lazy-image');
						observer.unobserve(img);
					}
				}
			});
		}, { rootMargin: '200px 0px' });
		
		document.querySelectorAll('.mi-lazy-image').forEach(function(img) {
			imageObserver.observe(img);
		});
	}
	
	// Función para cargar componentes diferidos
	function handleLazyComponents() {
		if (!('IntersectionObserver' in window)) {
			// Fallback para navegadores que no soportan IntersectionObserver
			const lazyComponents = document.querySelectorAll('.mi-lazy-component');
			lazyComponents.forEach(component => {
				component.classList.remove('mi-lazy-component');
				component.classList.add('mi-component-loaded');
				
				if (component.dataset.callback) {
					try {
						const callback = new Function('return ' + component.dataset.callback)();
						if (typeof callback === 'function') {
							callback(component);
						}
					} catch (e) {
						console.error('Error al ejecutar callback para lazy component:', e);
					}
				}
			});
			return;
		}
		
		const componentObserver = new IntersectionObserver(function(entries, observer) {
			entries.forEach(function(entry) {
				if (entry.isIntersecting) {
					const component = entry.target;
					component.classList.remove('mi-lazy-component');
					component.classList.add('mi-component-loaded');
					
					// Ejecutar callback si existe
					if (component.dataset.callback) {
						try {
							const callback = new Function('return ' + component.dataset.callback)();
							if (typeof callback === 'function') {
								callback(component);
							}
						} catch (e) {
							console.error('Error al ejecutar callback para lazy component:', e);
						}
					}
					
					observer.unobserve(component);
				}
			});
		}, { rootMargin: '100px 0px' });
		
		document.querySelectorAll('.mi-lazy-component').forEach(function(component) {
			componentObserver.observe(component);
		});
	}
	
	// Inicializar carga diferida cuando el DOM esté listo
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function() {
			handleLazyImages();
			handleLazyComponents();
		});
	} else {
		handleLazyImages();
		handleLazyComponents();
	}
	
	// Exportar utilidades para uso global
	window.miIntegracionApiLazyLoader = {
		/**
		 * Observadores registrados para componentes
		 */
		observers: {},
		
		/**
		 * Registra un observador para un componente
		 * 
		 * @param {string} componentId Identificador único del componente
		 * @param {Function} callback Función a ejecutar cuando el componente debe cargarse
		 */
		registerObserver: function(componentId, callback) {
			this.observers[componentId] = callback;
		},
		
		/**
		 * Activa un observador para un componente
		 * 
		 * @param {string} componentId Identificador único del componente
		 */
		triggerObserver: function(componentId) {
			if (this.observers[componentId] && typeof this.observers[componentId] === 'function') {
				this.observers[componentId]();
			}
		},
		/**
		 * Carga un script JavaScript de forma diferida
		 * 
		 * @param {string} url URL del script a cargar
		 * @param {Function} callback Función a ejecutar cuando el script esté cargado
		 */
		loadScript: function(url, callback) {
			const script = document.createElement('script');
			script.src = url;
			script.async = true;
			
			if (callback && typeof callback === 'function') {
				script.onload = callback;
			}
			
			document.body.appendChild(script);
		},
		
		/**
		 * Carga un CSS de forma diferida
		 * 
		 * @param {string} url URL del CSS a cargar
		 */
		loadCSS: function(url) {
			const link = document.createElement('link');
			link.rel = 'stylesheet';
			link.href = url;
			document.head.appendChild(link);
		},
		
		/**
		 * Inicializa un componente cuando sea necesario
		 * 
		 * @param {string} selector Selector CSS para el contenedor
		 * @param {Function} initCallback Función para inicializar el componente
		 */
		initComponent: function(selector, initCallback) {
			const containers = document.querySelectorAll(selector);
			
			if (!containers.length) return;
			
			if (!('IntersectionObserver' in window)) {
				// Inicializar inmediatamente si no hay IntersectionObserver
				containers.forEach(function(container) {
					initCallback(container);
				});
				return;
			}
			
			const observer = new IntersectionObserver(function(entries, observer) {
				entries.forEach(function(entry) {
					if (entry.isIntersecting) {
						initCallback(entry.target);
						observer.unobserve(entry.target);
					}
				});
			}, { rootMargin: '100px 0px' });
			
			containers.forEach(function(container) {
				observer.observe(container);
			});
		}
	};
})();
</script>
		<?php
	}

	/**
	 * Genera el HTML para un contenedor de carga diferida
	 *
	 * @param string $content Contenido a mostrar
	 * @param string $callback Nombre de la función JavaScript a llamar cuando el componente sea visible
	 * @param array  $attributes Atributos adicionales para el contenedor
	 * @return string HTML para el contenedor de carga diferida
	 */
	public static function lazy_component( $content, $callback = '', $attributes = array() ) {
		$attributes['class'] = isset( $attributes['class'] )
			? $attributes['class'] . ' mi-lazy-component'
			: 'mi-lazy-component';

		if ( ! empty( $callback ) ) {
			$attributes['data-callback'] = $callback;
		}

		$attr_html = '';
		foreach ( $attributes as $key => $value ) {
			$attr_html .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}

		return '<div' . $attr_html . '>' . $content . '</div>';
	}

	/**
	 * Ejecuta un observador para un componente específico
	 *
	 * @param string $component_id Identificador único del componente
	 * @return bool True si se ejecuta correctamente el observador
	 */
	public static function execute_observer( $component_id ) {
		if ( ! isset( self::$observers[ $component_id ] ) ) {
			return false;
		}

		call_user_func( self::$observers[ $component_id ] );
		return true;
	}

	/**
	 * Registra un script para carga diferida usando el sistema estándar de WordPress
	 *
	 * @param string      $handle
	 * @param string      $src
	 * @param array       $deps
	 * @param string|bool $ver
	 * @param bool        $in_footer
	 */
	public static function register_lazy_script( $handle, $src, $deps = array(), $ver = false, $in_footer = true ) {
		wp_register_script( $handle, $src, $deps, $ver, $in_footer );
		self::$lazy_scripts[] = $handle;
		// Inyectar función JS para carga bajo demanda solo una vez
		add_action( 'admin_footer', array( __CLASS__, 'inject_lazy_loader_js' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'inject_lazy_loader_js' ), 1 );
	}

	/**
	 * Inyecta la función JS global para cargar scripts bajo demanda
	 */
	public static function inject_lazy_loader_js() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
<script>
window.miApiLoadScript = function(handle, callback) {
	if (typeof handle !== 'string') return;
	if (window.miApiLoadedScripts === undefined) window.miApiLoadedScripts = {};
	if (window.miApiLoadedScripts[handle]) {
		if (typeof callback === 'function') callback();
		return;
	}
	var script = document.createElement('script');
	script.src = window.miApiLazyScriptSrcs && window.miApiLazyScriptSrcs[handle] ? window.miApiLazyScriptSrcs[handle] : '';
	script.async = true;
	script.onload = function() {
		window.miApiLoadedScripts[handle] = true;
		if (typeof callback === 'function') callback();
	};
	document.body.appendChild(script);
};
</script>
		<?php
		// Pasar los src de los scripts registrados a JS
		$srcs = array();
		foreach ( self::$lazy_scripts as $handle ) {
			$srcs[ $handle ] = wp_scripts()->registered[ $handle ]->src;
		}
		echo '<script>window.miApiLazyScriptSrcs = ' . json_encode( $srcs ) . ';</script>';
	}
}
