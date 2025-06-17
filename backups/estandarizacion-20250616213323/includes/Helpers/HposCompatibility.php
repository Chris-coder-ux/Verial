<?php
/**
 * Compatibilidad con HPOS (High Performance Order Storage) de WooCommerce - Wrapper Legacy
 *
 * @package MiIntegracionApi\Helpers
 * @deprecated 2.0.0 Use MiIntegracionApi\WooCommerce\HposCompatibility instead
 */

namespace MiIntegracionApi\Helpers;

use MiIntegracionApi\WooCommerce\HposCompatibility as WCHposCompatibility;

/**
 * Clase wrapper para mantener compatibilidad con código antiguo
 * 
 * @deprecated 2.0.0 Use MiIntegracionApi\WooCommerce\HposCompatibility instead
 */
class HposCompatibility {
	/**
	 * Verifica si el plugin es compatible con la configuración actual de WooCommerce
	 *
	 * @return bool True si es compatible
	 */
	public static function check_woocommerce_compatibility() {
		// Cargar la clase de compatibilidad con HPOS
		require_once MiIntegracionApi_PLUGIN_DIR . 'includes/WooCommerce/class-wc-hpos-compatibility.php';

		// Verificar si HPOS está habilitado
		if ( class_exists( 'MI_WC_HPOS_Compatibility' ) && \MiIntegracionApi\WooCommerce\HposCompatibility::is_hpos_active() ) {
			// Solo mostrar un aviso informativo, el plugin es compatible con HPOS
			add_action( 'admin_notices', [ self::class, 'hpos_notice' ] );

			// Registrar uso de HPOS para diagnóstico futuro
			update_option( 'mi_integracion_api_hpos_detected', 'yes' );
		} else {
			update_option( 'mi_integracion_api_hpos_detected', 'no' );
		}

		// El plugin siempre es compatible independientemente del modo de almacenamiento de pedidos
		return true;
	}

	/**
	 * Muestra un aviso cuando HPOS está habilitado
	 */
	public static function hpos_notice() {
		$message = sprintf(
			/* translators: 1: Plugin name, 2: WooCommerce features URL, 3: Documentation URL */
			__( 'ℹ️ %1$s está operando con la característica de WooCommerce «Almacenamiento de pedidos de alto rendimiento» (HPOS) activada. El plugin es compatible pero se recomienda revisar el funcionamiento de todas las operaciones relacionadas con pedidos. %2$s | %3$s', 'mi-integracion-api' ),
			'<strong>' . esc_html__( 'Integración WooCommerce con Verial ERP', 'mi-integracion-api' ) . '</strong>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced&section=features' ) ) . '">' .
			esc_html__( 'Gestionar características de WooCommerce', 'mi-integracion-api' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=mi-integracion-api-endpoint-test' ) ) . '">' .
			esc_html__( 'Probar la integración', 'mi-integracion-api' ) . '</a>'
		);

		echo '<div class="notice notice-info is-dismissible"><p>' . $message . '</p></div>';
	}

	/**
	 * Verifica si HPOS está activo en WooCommerce
	 *
	 * @return bool True si HPOS está activado, False en caso contrario
	 */
	public static function is_hpos_active() {
		// Usar la clase oficial de compatibilidad con HPOS
		if ( class_exists( 'MI_WC_HPOS_Compatibility' ) ) {
			return MiIntegracionApi\WooCommerce\HposCompatibility::is_hpos_active();
		}

		// Verificar directamente como respaldo
		if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		// Verificar usando el valor almacenado como último recurso
		return get_option( 'mi_integracion_api_hpos_detected' ) === 'yes';
	}
}
