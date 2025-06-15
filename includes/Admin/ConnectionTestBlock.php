<?php

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConnectionTestBlock {
	public static function render() {
		?>
		<div class="verial-connection-test-block">
			<h3><?php _e( 'Prueba de conexión', 'mi-integracion-api' ); ?></h3>
			<p><?php _e( 'Comprueba la conexión con la API de Verial y WooCommerce. Haz clic en los botones para probar cada conexión.', 'mi-integracion-api' ); ?></p>
			<button id="mia-btn-test-connection-verial" class="verial-button info" type="button">
				<?php _e( 'Probar conexión Verial', 'mi-integracion-api' ); ?>
			</button>
			<span id="mia-test-connection-verial-result" class="verial-connection-result"></span>
			<br><br>
			<button id="mia-btn-test-connection-woocommerce" class="verial-button info" type="button">
				<?php _e( 'Probar conexión WooCommerce', 'mi-integracion-api' ); ?>
			</button>
			<span id="mia-test-connection-woocommerce-result" class="verial-connection-result"></span>
		</div>
		<?php
	}
}