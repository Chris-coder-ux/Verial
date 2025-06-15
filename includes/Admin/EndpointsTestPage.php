<?php

namespace MiIntegracionApi\Admin;

/**
 * Página de pruebas de endpoints Verial.
 * Permite lanzar pruebas AJAX a los endpoints y mostrar feedback detallado.
 *
 * @package mi-integracion-api
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EndpointsTestPage {
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'No tienes permisos suficientes para ver esta página.', 'mi-integracion-api' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pruebas de Endpoints Verial', 'mi-integracion-api' ); ?></h1>
			<p><?php esc_html_e( 'Lanza pruebas a los endpoints principales de Verial y revisa la respuesta.', 'mi-integracion-api' ); ?></p>
			<button id="mia-test-endpoint" class="button button-primary"><?php esc_html_e( 'Probar conexión y endpoints', 'mi-integracion-api' ); ?></button>
			<div id="mia-endpoint-result" style="margin-top:20px;max-width:800px;overflow:auto;background:#f9f9f9;padding:12px;font-size:13px;"></div>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			$('#mia-test-endpoint').on('click', function(){
				$('#mia-endpoint-result').html('<span class="spinner is-active"></span> <?php echo esc_js( __( 'Probando...', 'mi-integracion-api' ) ); ?>');
				$.post(ajaxurl, {
					action: 'mia_test_endpoint',
					nonce: '<?php echo esc_js( wp_create_nonce( 'mia_sync_nonce' ) ); ?>'
				}, function(response){
					if(response.success && response.data && response.data.result){
						var html = '<pre style="white-space:pre-wrap;word-break:break-all;">'+JSON.stringify(response.data.result, null, 2)+'</pre>';
						$('#mia-endpoint-result').html(html);
					} else {
						$('#mia-endpoint-result').html('<span style="color:red">'+(response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error en la prueba.', 'mi-integracion-api' ) ); ?>')+'</span>');
					}
				}).fail(function(){
					$('#mia-endpoint-result').html('<span style="color:red"><?php echo esc_js( __( 'Error en la prueba.', 'mi-integracion-api' ) ); ?></span>');
				});
			});
		});
		</script>
		<?php
	}
}
