<?php

namespace MiIntegracionApi\Admin;


/**
 * Página de historial de sincronizaciones
 *
 * @package MiIntegracionApi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Salir si se accede directamente.
}

class SyncHistoryPage {
	public static function render() {
		// Crear la tabla si no existe
		if ( ! function_exists( 'mia_crear_tabla_historial' ) ) {
			require_once __DIR__ . '/ajax-sync.php';
			mia_crear_tabla_historial();
		}
		// Procesar acciones
		if ( isset( $_GET['action'] ) && $_GET['action'] === 'view' && isset( $_GET['id'] ) ) {
			$sync_id = intval( $_GET['id'] );
			mia_mostrar_detalles_sincronizacion( $sync_id );
			return;
		}
		global $wpdb;
		$tabla = $wpdb->prefix . 'mi_integracion_api_logs';
		$paged    = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$offset   = ( $paged - 1 ) * $per_page;
		$total_items = $wpdb->get_var( "SELECT COUNT(*) FROM $tabla" );
		$total_pages = ceil( $total_items / $per_page );
		$registros = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, tipo, usuario_id, fecha, duracion, status FROM $tabla ORDER BY fecha DESC LIMIT %d, %d",
				$offset,
				$per_page
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Historial de Sincronizaciones', 'mi-integracion-api' ); ?></h1>
			<?php if ( empty( $registros ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No hay registros de sincronización disponibles.', 'mi-integracion-api' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'ID', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Tipo', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Usuario', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Fecha', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Duración', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Estado', 'mi-integracion-api' ); ?></th>
							<th><?php esc_html_e( 'Acciones', 'mi-integracion-api' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $registros as $registro ) :
							$usuario        = get_userdata( $registro->usuario_id );
							$nombre_usuario = $usuario ? $usuario->display_name : __( 'Usuario desconocido', 'mi-integracion-api' );
							$clase_estado   = '';
							switch ( $registro->status ) {
								case 'success':
									$clase_estado = 'status-success';
									$texto_estado = __( 'Éxito', 'mi-integracion-api' );
									break;
								case 'error':
									$clase_estado = 'status-error';
									$texto_estado = __( 'Error', 'mi-integracion-api' );
									break;
								case 'canceled':
									$clase_estado = 'status-warning';
									$texto_estado = __( 'Cancelado', 'mi-integracion-api' );
									break;
								default:
									$clase_estado = 'status-pending';
									$texto_estado = __( 'Pendiente', 'mi-integracion-api' );
							}
							?>
						<tr>
							<td><?php echo esc_html( $registro->id ); ?></td>
							<td><?php echo esc_html( ucfirst( $registro->tipo ) ); ?></td>
							<td><?php echo esc_html( $nombre_usuario ); ?></td>
							<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $registro->fecha ) ) ); ?></td>
							<td><?php echo esc_html( number_format( $registro->duracion, 2 ) . ' ' . __( 'segundos', 'mi-integracion-api' ) ); ?></td>
							<td><span class="mia-status-badge <?php echo esc_attr( $clase_estado ); ?>"><?php echo esc_html( $texto_estado ); ?></span></td>
							<td>
								<a href="<?php echo esc_url( add_query_arg( array( 'action' => 'view', 'id' => $registro->id ) ) ); ?>" class="button button-small">
									<?php esc_html_e( 'Ver detalles', 'mi-integracion-api' ); ?>
								</a>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								_n( '%s elemento', '%s elementos', $total_items, 'mi-integracion-api' ),
								number_format_i18n( $total_items )
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							echo paginate_links(
								array(
									'base'      => add_query_arg( 'paged', '%#%' ),
									'format'    => '',
									'prev_text' => '&laquo;',
									'next_text' => '&raquo;',
									'total'     => $total_pages,
									'current'   => $paged,
								)
							);
							?>
						</span>
					</div>
				</div>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}


/**
 * Muestra los detalles completos de una sincronización
 *
 * @param int $sync_id ID de la sincronización
 */
function mia_mostrar_detalles_sincronizacion( $sync_id ) {
	global $wpdb;
	$tabla = $wpdb->prefix . 'mi_integracion_api_logs';

	$registro = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tabla WHERE id = %d", $sync_id ) );

	if ( ! $registro ) {
		echo '<div class="notice notice-error"><p>';
		esc_html_e( 'El registro de sincronización solicitado no existe.', 'mi-integracion-api' );
		echo '</p></div>';
		return;
	}

	$usuario        = get_userdata( $registro->usuario_id );
	$nombre_usuario = $usuario ? $usuario->display_name : __( 'Usuario desconocido', 'mi-integracion-api' );

	$datos     = json_decode( $registro->datos, true );
	$resultado = json_decode( $registro->resultado, true );

	// Determinar clase de estado
	$clase_estado = '';
	switch ( $registro->status ) {
		case 'success':
			$clase_estado = 'notice-success';
			$texto_estado = __( 'Éxito', 'mi-integracion-api' );
			break;
		case 'error':
			$clase_estado = 'notice-error';
			$texto_estado = __( 'Error', 'mi-integracion-api' );
			break;
		case 'canceled':
			$clase_estado = 'notice-warning';
			$texto_estado = __( 'Cancelado', 'mi-integracion-api' );
			break;
		default:
			$clase_estado = 'notice-info';
			$texto_estado = __( 'Pendiente', 'mi-integracion-api' );
	}
	?>
<div class="wrap">
	<h1><?php printf( esc_html__( 'Detalles de Sincronización #%d', 'mi-integracion-api' ), $sync_id ); ?></h1>
	
	<p>
		<a href="
		<?php
		echo esc_url(
			add_query_arg(
				array(
					'page' => 'mia-sync',
					'tab'  => 'history',
				),
				admin_url( 'admin.php' )
			)
		);
		?>
					" class="button">
			&laquo; <?php esc_html_e( 'Volver al historial', 'mi-integracion-api' ); ?>
		</a>
	</p>

	<div class="notice <?php echo esc_attr( $clase_estado ); ?>">
		<p><strong><?php echo esc_html( $texto_estado ); ?>:</strong> 
			<?php
			if ( isset( $resultado['mensaje'] ) ) {
				echo esc_html( $resultado['mensaje'] );
			} elseif ( $registro->status === 'success' ) {
				esc_html_e( 'La sincronización se completó correctamente.', 'mi-integracion-api' );
			} elseif ( $registro->status === 'error' ) {
				esc_html_e( 'La sincronización encontró errores. Revise los detalles a continuación.', 'mi-integracion-api' );
			}
			?>
		</p>
	</div>

	<div class="mia-sync-details-card">
		<h3><?php esc_html_e( 'Información General', 'mi-integracion-api' ); ?></h3>
		<table class="widefat">
			<tr>
				<th><?php esc_html_e( 'ID de Sincronización', 'mi-integracion-api' ); ?></th>
				<td><?php echo esc_html( $registro->id ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tipo', 'mi-integracion-api' ); ?></th>
				<td><?php echo esc_html( ucfirst( $registro->tipo ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Fecha y Hora', 'mi-integracion-api' ); ?></th>
				<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $registro->fecha ) ) ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Usuario', 'mi-integracion-api' ); ?></th>
				<td><?php echo esc_html( $nombre_usuario ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Estado', 'mi-integracion-api' ); ?></th>
				<td><span class="mia-status-badge <?php echo esc_attr( $clase_estado ); ?>"><?php echo esc_html( $texto_estado ); ?></span></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Duración', 'mi-integracion-api' ); ?></th>
				<td><?php echo esc_html( number_format( $registro->duracion, 2 ) . ' ' . __( 'segundos', 'mi-integracion-api' ) ); ?></td>
			</tr>
		</table>
	</div>

	<div class="mia-sync-details-card">
		<h3><?php esc_html_e( 'Datos de Entrada', 'mi-integracion-api' ); ?></h3>
		<?php if ( empty( $datos ) ) : ?>
			<p class="description"><?php esc_html_e( 'No hay datos de entrada disponibles.', 'mi-integracion-api' ); ?></p>
		<?php else : ?>
			<div class="mia-datos-container">
				<table class="widefat">
					<?php foreach ( $datos as $clave => $valor ) : ?>
					<tr>
						<th><?php echo esc_html( $clave ); ?></th>
						<td>
							<?php
							if ( is_array( $valor ) ) {
								echo '<pre>' . esc_html( json_encode( $valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
							} else {
								echo esc_html( $valor );
							}
							?>
						</td>
					</tr>
					<?php endforeach; ?>
				</table>
			</div>
		<?php endif; ?>
	</div>

	<div class="mia-sync-details-card">
		<h3><?php esc_html_e( 'Resultados de la Sincronización', 'mi-integracion-api' ); ?></h3>
		<?php if ( empty( $resultado ) ) : ?>
			<p class="description"><?php esc_html_e( 'No hay resultados disponibles.', 'mi-integracion-api' ); ?></p>
		<?php else : ?>
			<table class="widefat">
				<?php
				// Mostrar estadísticas resumen primero
				$estadisticas = array(
					'total'           => __( 'Total elementos', 'mi-integracion-api' ),
					'exitos'          => __( 'Elementos exitosos', 'mi-integracion-api' ),
					'errores'         => __( 'Elementos con error', 'mi-integracion-api' ),
					'errores_detalle' => __( 'Detalle de errores', 'mi-integracion-api' ),
				);

				foreach ( $estadisticas as $clave => $etiqueta ) :
					if ( isset( $resultado[ $clave ] ) ) :
						?>
				<tr>
					<th><?php echo esc_html( $etiqueta ); ?></th>
					<td>
						<?php
						if ( $clave === 'errores_detalle' && is_array( $resultado[ $clave ] ) ) {
							echo '<pre class="mia-error-detail">' . esc_html( json_encode( $resultado[ $clave ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
						} else {
							echo esc_html( $resultado[ $clave ] );
						}
						?>
					</td>
				</tr>
						<?php
					endif;
				endforeach;

				// Mostrar el resto de datos
				foreach ( $resultado as $clave => $valor ) :
					if ( ! in_array( $clave, array_keys( $estadisticas ) ) ) :
						?>
				<tr>
					<th><?php echo esc_html( $clave ); ?></th>
					<td>
						<?php
						if ( is_array( $valor ) ) {
							echo '<pre>' . esc_html( json_encode( $valor, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre>';
						} else {
							echo esc_html( $valor );
						}
						?>
					</td>
				</tr>
						<?php
					endif;
				endforeach;
				?>
			</table>
		<?php endif; ?>
	</div>

	<div class="mia-sync-details-card">
		<h3><?php esc_html_e( 'Acciones', 'mi-integracion-api' ); ?></h3>
		<p>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mia-sync' ) ); ?>" class="button">
				<?php esc_html_e( 'Ir a Sincronización', 'mi-integracion-api' ); ?>
			</a>
			<a href="#" class="button mia-export-json" data-id="<?php echo esc_attr( $registro->id ); ?>">
				<?php esc_html_e( 'Exportar como JSON', 'mi-integracion-api' ); ?>
			</a>
			<?php if ( $registro->status === 'error' ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mia-sync&retry=' . $registro->id ) ); ?>" class="button button-primary">
				<?php esc_html_e( 'Reintentar esta sincronización', 'mi-integracion-api' ); ?>
			</a>
			<?php endif; ?>
		</p>
	</div>
</div>

<style type="text/css">
	.mia-sync-details-card {
		margin: 20px 0;
		padding: 15px;
		background: #fff;
		border: 1px solid #ccd0d4;
		box-shadow: 0 1px 1px rgba(0,0,0,0.04);
	}
	.mia-sync-details-card h3 {
		margin-top: 0;
		border-bottom: 1px solid #eee;
		padding-bottom: 10px;
	}
	.mia-status-badge {
		display: inline-block;
		padding: 3px 8px;
		border-radius: 3px;
		font-size: 12px;
		font-weight: 600;
	}
	.status-success {
		background: #46b450;
		color: #fff;
	}
	.status-error {
		background: #dc3232;
		color: #fff;
	}
	.status-warning {
		background: #ffb900;
		color: #fff;
	}
	.status-pending {
		background: #00a0d2;
		color: #fff;
	}
	.mia-error-detail {
		background: #f9f9f9;
		padding: 10px;
		overflow: auto;
		max-height: 200px;
	}
	.mia-datos-container {
		max-height: 300px;
		overflow-y: auto;
	}
</style>

<script type="text/javascript">
var ajaxurl = "<?php echo admin_url( 'admin-ajax.php' ); ?>";

jQuery(document).ready(function($) {
	// Funcionalidad para exportar como JSON
	$('.mia-export-json').on('click', function(e) {
		e.preventDefault();
		
		var syncId = $(this).data('id');
		var data = {
			'action': 'mia_export_sync_json',
			'sync_id': syncId,
			'nonce': '<?php echo wp_create_nonce( 'mia_export_sync_json' ); ?>'
		};
		
		$.ajax({
			url: ajaxurl,
			data: data,
			type: 'POST',
			success: function(response) {
				if (response.success) {
					// Crear un elemento temporal para descargar
					var dataStr = "data:text/json;charset=utf-8," + encodeURIComponent(JSON.stringify(response.data, null, 2));
					var downloadAnchorNode = document.createElement('a');
					downloadAnchorNode.setAttribute("href", dataStr);
					downloadAnchorNode.setAttribute("download", "sync-" + syncId + ".json");
					document.body.appendChild(downloadAnchorNode);
					downloadAnchorNode.click();
					downloadAnchorNode.remove();
				} else {
					alert('Error al exportar: ' + response.data.mensaje);
				}
			}
		});
	});
});
</script>

	<?php
}
?>
