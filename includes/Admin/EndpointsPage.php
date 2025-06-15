<?php

namespace MiIntegracionApi\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EndpointsPage {
	public static function render() {
		?>
		<div class="mi-integracion-api-admin">
			<div class="mi-integracion-api-header">
				<span class="mi-integracion-api-section-title"><?php _e( 'Pruebas de Endpoints Verial', 'mi-integracion-api' ); ?></span>
			</div>
			<div class="mi-integracion-api-card">
				<div class="mi-integracion-api-tabs">
					<a href="#" class="mi-integracion-api-tab-link active" data-tab="tab-endpoints"><?php _e( 'Llamadas a Endpoints', 'mi-integracion-api' ); ?></a>
					<a href="#" class="mi-integracion-api-tab-link" data-tab="tab-docs"><?php _e( 'Documentación', 'mi-integracion-api' ); ?></a>
				</div>
				<div id="tab-endpoints" class="mi-integracion-api-tab-content">
					<form id="mi-endpoint-form" class="mi-integracion-api-form-row" autocomplete="off">
						<label for="mi_endpoint_select"><?php _e( 'Endpoint', 'mi-integracion-api' ); ?></label>
						<select id="mi_endpoint_select" name="endpoint" class="mi-integracion-api-select2">
							<option value="get_articulos">get_articulos</option>
							<option value="get_clientes">get_clientes</option>
							<option value="get_pedidos">get_pedidos</option>
							<option value="get_stock">get_stock</option>
							<option value="get_condiciones_tarifa">get_condiciones_tarifa</option>
						</select>
						<input type="text" id="mi_endpoint_param" name="param" placeholder="<?php _e( 'Parámetro (opcional)', 'mi-integracion-api' ); ?>" />
						<button type="submit" class="mi-integracion-api-button"><?php _e( 'Probar', 'mi-integracion-api' ); ?></button>
					</form>
					<div id="mi-endpoint-feedback"></div>
					<div class="mi-integracion-api-table-responsive" style="margin-top:1em;">
						<table class="mi-integracion-api-table" id="mi-endpoint-result-table" style="display:none;"></table>
					</div>
				</div>
				<div id="tab-docs" class="mi-integracion-api-tab-content" style="display:none;">
					<div class="mi-integracion-api-card">
						<h3><?php _e( 'Descripción rápida de endpoints', 'mi-integracion-api' ); ?></h3>
						<ul>
							<li><b>get_articulos</b>: <?php _e( 'Devuelve la lista de artículos/productos.', 'mi-integracion-api' ); ?></li>
							<li><b>get_clientes</b>: <?php _e( 'Devuelve la lista de clientes.', 'mi-integracion-api' ); ?></li>
							<li><b>get_pedidos</b>: <?php _e( 'Devuelve la lista de pedidos.', 'mi-integracion-api' ); ?></li>
							<li><b>get_stock</b>: <?php _e( 'Consulta el stock de un artículo.', 'mi-integracion-api' ); ?></li>
							<li><b>get_condiciones_tarifa</b>: <?php _e( 'Consulta condiciones de tarifa/precio.', 'mi-integracion-api' ); ?></li>
						</ul>
						<p><?php _e( 'Consulta la documentación técnica para detalles de parámetros y estructura.', 'mi-integracion-api' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($){
			if(typeof $.fn.select2 === 'function') {
				$('#mi_endpoint_select').select2({width:'resolve'});
			}
			$('#mi-endpoint-form').on('submit', function(e){
				e.preventDefault();
				var endpoint = $('#mi_endpoint_select').val();
				var param = $('#mi_endpoint_param').val();
				$('#mi-endpoint-feedback').html('<span class="spinner is-active"></span> <?php echo esc_js( __( 'Consultando endpoint...', 'mi-integracion-api' ) ); ?>');
				$.post(miEndpointsPage.ajaxurl, {
					action: 'mi_test_endpoint',
					endpoint: endpoint,
					param: param,
					nonce: '<?php echo esc_js( wp_create_nonce( 'mi_endpoint_nonce' ) ); ?>'
				}, function(response){
					if(response.success && response.data) {
						$('#mi-endpoint-feedback').html('');
						if(window.verialToast) window.verialToast.show({type:'success',message:'<?php echo esc_js( __( 'Consulta exitosa.', 'mi-integracion-api' ) ); ?>'});
						// Mostrar resultados en tabla
						if(response.data.table_html) {
							$('#mi-endpoint-result-table').html(response.data.table_html).show();
						} else {
							$('#mi-endpoint-result-table').hide();
						}
					} else {
						$('#mi-endpoint-feedback').html('<div class="notice notice-error">'+ (response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error desconocido.', 'mi-integracion-api' ) ); ?>')+'</div>');
						if(window.verialToast) window.verialToast.show({type:'error',message:response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Error desconocido.', 'mi-integracion-api' ) ); ?>'});
						$('#mi-endpoint-result-table').hide();
					}
				}).fail(function(){
					$('#mi-endpoint-feedback').html('<div class="notice notice-error"><?php echo esc_js( __( 'Error de red o servidor.', 'mi-integracion-api' ) ); ?></div>');
					if(window.verialToast) window.verialToast.show({type:'error',message:'<?php echo esc_js( __( 'Error de red o servidor.', 'mi-integracion-api' ) ); ?>'});
					$('#mi-endpoint-result-table').hide();
				});
			});
		});
		</script>
		<?php
	}
}
