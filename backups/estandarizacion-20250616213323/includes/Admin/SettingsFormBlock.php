<?php

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Helpers\SettingsHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsFormBlock {
	public static function render() {
		$options   = get_option( 'mi_integracion_api_ajustes', array() );
		$intervalo = isset( $options['mia_sync_interval_min'] ) ? intval( $options['mia_sync_interval_min'] ) : 15;
		$url_base = isset( $options['mia_url_base'] ) ? $options['mia_url_base'] : 'https://api.verialerp.com/v1';
		$numero_sesion = isset( $options['mia_numero_sesion'] ) ? $options['mia_numero_sesion'] : '18';
		$batch_size = isset( $options['mia_sync_batch_size'] ) ? intval( $options['mia_sync_batch_size'] ) : 100;
		$sku_fields = isset( $options['mia_sync_sku_fields'] ) ? $options['mia_sync_sku_fields'] : 'ReferenciaBarras,Id,CodigoArticulo';
		?>
		<div style="display: flex; gap: 32px; align-items: flex-start;">
			<div style="flex: 2;">
				<form method="post" action="options.php">
					<?php
					settings_fields( 'mi_integracion_api_settings_group' );
					do_settings_sections( 'mi-integracion-api' );
					?>
					<label for="mia_sync_interval_min"><strong><?php _e( 'Intervalo de sincronización automática (minutos)', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="number" min="1" max="1440" step="1" id="mia_sync_interval_min" name="mi_integracion_api_ajustes[mia_sync_interval_min]" value="<?php echo esc_attr( $intervalo ); ?>" style="width:80px;" />
					<span style="color:#666;font-size:12px;">(Ejemplo: 15 = cada 15 minutos)</span>
					<br><br>
					<label for="mia_url_base"><strong><?php _e( 'URL Base del Servidor Verial', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="text" id="mia_url_base" name="mi_integracion_api_ajustes[mia_url_base]" value="<?php echo esc_attr( $url_base ); ?>" style="width:400px;" placeholder="http://x.verial.org:8000" />
					<span style="color:#666;font-size:12px;">(Ejemplo: http://x.verial.org:8000)</span>
					<br><br>
					<label for="mia_numero_sesion"><strong><?php _e( 'Número de Sesión Verial', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="text" id="mia_numero_sesion" name="mi_integracion_api_ajustes[mia_numero_sesion]" value="<?php echo esc_attr( $numero_sesion ); ?>" style="width:80px;" />
					<span style="color:#666;font-size:12px;">(Por defecto: 18)</span>
					<br><br>
					<label for="api_key"><strong><?php _e( 'API Key de Verial', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="text" name="api_key" id="api_key" value="<?php echo esc_attr( SettingsHelper::get_api_key() ); ?>" />
					<p class="description"><?php _e('Clave de API para autenticar las solicitudes a Verial ERP', 'mi-integracion-api'); ?></p>
					<br><br>
					<hr>
					<h3 style="margin-top: 20px;"><?php _e( 'Configuración de Sincronización', 'mi-integracion-api' ); ?></h3>
					<label for="mia_sync_batch_size"><strong><?php _e( 'Tamaño del Lote de Sincronización', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="number" min="10" max="500" step="10" id="mia_sync_batch_size" name="mi_integracion_api_ajustes[mia_sync_batch_size]" value="<?php echo esc_attr( $batch_size ); ?>" style="width:80px;" />
					<p class="description"><?php _e('Número de productos a procesar en cada lote. Un valor más bajo puede prevenir timeouts en servidores lentos.', 'mi-integracion-api'); ?></p>
					<br>
					<label for="mia_sync_sku_fields"><strong><?php _e( 'Campos de SKU para Mapeo', 'mi-integracion-api' ); ?>:</strong></label>
					<input type="text" id="mia_sync_sku_fields" name="mi_integracion_api_ajustes[mia_sync_sku_fields]" value="<?php echo esc_attr( $sku_fields ); ?>" style="width:400px;" />
					<p class="description"><?php _e('Campos de la API de Verial a usar como SKU, en orden de prioridad y separados por comas.', 'mi-integracion-api'); ?></p>
					<br><br>
					<?php submit_button( __( 'Guardar configuración', 'mi-integracion-api' ), 'verial-button success' ); ?>
				</form>
			</div>
			<!-- Dock de ayuda visual -->
			<div style="flex: 1; background: #f8fafc; border: 1px solid #d0d7de; border-radius: 8px; padding: 18px 20px; min-width: 320px; max-width: 400px; box-shadow: 0 2px 8px #0001;">
				<h3 style="margin-top:0; color:#1a3a5d; font-size:1.1em;">🛈 Ayuda para la conexión con Verial ERP</h3>
				<ul style="font-size:14px; color:#333; padding-left:18px;">
					<li><b>URL Base:</b> Introduce solo el dominio y puerto, <b>sin</b> <code>/WcfServiceLibraryVerial/</code>.<br>
					Ejemplo: <code>http://x.verial.org:8000</code></li>
					<li><b>Número de Sesión:</b> Proporcionado por Verial. Usualmente <code>18</code> para pruebas.</li>
					<li><b>API Key:</b> Solo si tu servidor Verial la requiere.</li>
					<li>El plugin añadirá automáticamente <code>/WcfServiceLibraryVerial/</code> y el endpoint necesario.</li>
				</ul>
				<hr style="margin:10px 0;">
				<div style="font-size:13px; color:#1a3a5d;">
					<b>¿Problemas de conexión?</b>
					<ul style="margin:0 0 0 18px;">
						<li>Verifica que la URL y el puerto sean accesibles desde tu servidor WordPress.</li>
						<li>Solicita a Verial el número de sesión correcto.</li>
						<li>Consulta los <a href="https://verialerp.com/soporte" target="_blank">recursos de soporte Verial</a> o la <a href="<?php echo esc_url( admin_url('admin.php?page=mi-integracion-api-diagnostico') ); ?>">herramienta de diagnóstico</a>.</li>
					</ul>
				</div>
				<hr style="margin:10px 0;">
				<div style="font-size:12px; color:#888;">
					<b>Ejemplo de URL final generada:</b><br>
					<code>http://x.verial.org:8000/WcfServiceLibraryVerial/GetVersionWS?x=18</code>
				</div>
			</div>
		</div>
		<?php
	}
}