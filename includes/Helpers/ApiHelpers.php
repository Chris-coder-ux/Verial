<?php
namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Core\Auth_Manager;
use MiIntegracionApi\Core\CryptoService;

class ApiHelpers {
	/**
	 * Obtiene una instancia del conector API.
	 *
	 * @return ApiConnector|null
	 * @throws \Exception Si hay errores de configuración
	 */
	public static function get_connector() {
		if ( class_exists( ApiConnector::class ) ) {
			// Primero intentamos obtener del array de opciones
			$options = get_option( 'mi_integracion_api_ajustes', array() );
			
			// Si no están en el array, intentamos obtener de opciones individuales
			$api_url = $options['mia_url_base'] ?? '';
			$sesionwcf = $options['mia_numero_sesion'] ?? '18';
			
			// Si aún no hay valores, intentamos usar los de la definición en SettingsManager
			if (empty($api_url)) {
				$api_url = get_option('mi_integracion_api_api_url', 'https://api.verialerp.com/v1');
			}
			
			$config = array(
				'api_url' => $api_url,
				'sesionwcf' => $sesionwcf
			);
			
			// Obtener la API key de Verial
			if (class_exists('\\MiIntegracionApi\\Helpers\\SettingsHelper')) {
				$api_key = \MiIntegracionApi\Helpers\SettingsHelper::get_api_key();
				if (!empty($api_key)) {
					$config['api_key'] = $api_key;
				}
			}
			
			// Registrar información del conector en debug
			if (class_exists('\\MiIntegracionApi\\Helpers\\Logger') && defined('WP_DEBUG') && WP_DEBUG) {
				$logger = new \MiIntegracionApi\Helpers\Logger('apihelpers');
				$logger->debug('Inicializando conector API', [
					'api_url' => $api_url,
					'sesion' => $sesionwcf,
					'tiene_api_key' => !empty($config['api_key'])
				]);
			}
			
			try {
				// Crear un logger o usar el ya definido
				if (class_exists('\\MiIntegracionApi\\Helpers\\Logger') && !isset($logger)) {
					$logger = new \MiIntegracionApi\Helpers\Logger('apihelpers');
				}
				
				// Crear el ApiConnector con el logger
				$api_connector = new ApiConnector($logger);
				
				// Configurar API URL y sesión WCF
				if (!empty($config['api_url'])) {
					$api_connector->set_api_url($config['api_url']);
				}
				if (!empty($config['sesionwcf'])) {
					$api_connector->set_sesion_wcf($config['sesionwcf']);
				}
				
				return $api_connector;
			} catch (\Exception $e) {
				// Log del error si el logger está disponible
				if (class_exists('\\MiIntegracionApi\\Helpers\\Logger')) {
					\MiIntegracionApi\Helpers\Logger::error('Error al crear ApiConnector en ApiHelpers: ' . $e->getMessage());
				}
				
				// Re-lanzar la excepción para que el código llamador la maneje
				throw $e;
			}
		}
		return null;
	}

	/**
	 * Obtiene una instancia del gestor de configuración.
	 *
	 * @return \MI_Settings_Manager|null
	 */
	public static function get_settings() {
		if ( class_exists( 'MI_Settings_Manager' ) ) {
			return \MI_Settings_Manager::get_instance();
		}
		return null;
	}

	/**
	 * Obtiene una instancia del gestor de autenticación.
	 *
	 * @return Auth_Manager|null
	 */
	public static function get_auth_manager() {
		if ( class_exists( Auth_Manager::class ) ) {
			return Auth_Manager::get_instance();
		}
		return null;
	}

	/**
	 * Registra un error en el log del plugin.
	 *
	 * @param string $message
	 * @param string $context
	 */
	public static function log_error( $message, $context = 'general' ) {
		if ( class_exists( Logger::class ) ) {
			Logger::error( $message, $context );
		}
	}

	/**
	 * Registra información en el log del plugin.
	 *
	 * @param string $message
	 * @param string $context
	 */
	public static function log_info( $message, $context = 'general' ) {
		if ( class_exists( Logger::class ) ) {
			Logger::info( $message, $context );
		}
	}

	/**
	 * Obtiene una instancia del servicio de criptografía.
	 *
	 * @return CryptoService|null
	 */
	public static function get_crypto() {
		if ( class_exists( CryptoService::class ) ) {
			return CryptoService::get_instance();
		}
		return null;
	}
}
