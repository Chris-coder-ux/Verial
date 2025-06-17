<?php
/**
 * Gestiona el menú de administración y las páginas del plugin.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 */

namespace MiIntegracionApi\Admin;

// Si este archivo es llamado directamente, abortar.
if ( ! defined( "ABSPATH" ) ) {
    exit;
}

use MiIntegracionApi\Core\Module_Loader;
use MiIntegracionApi\Helpers\Logger;

/**
 * Clase para gestionar el menú de administración y las páginas del plugin.
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 */
class AdminMenu {

    /**
     * ID del menú principal del plugin.
     *
     * @since 1.0.0
     * @access   private
     * @var      string    $menu_id    ID del menú principal.
     */
    private string $menu_id;

    /**
     * URL de los assets del plugin.
     *
     * @since 1.0.0
     * @access   private
     * @var      string    $assets_url    URL base de los assets.
     */
    private string $assets_url;

    /**
     * Directorio de plantillas del plugin.
     *
     * @since 1.0.0
     * @access   private
     * @var      string    $templates_dir    Ruta al directorio de plantillas.
     */
    private string $templates_dir;

    /**
     * Instancia del Logger.
     *
     * @since 1.0.0
     * @access   private
     * @var      Logger    $logger    Instancia del logger.
     */
    private Logger $logger;

    /**
     * Módulos disponibles para el plugin.
     *
     * @since 1.0.0
     * @access   private
     * @var      array    $modules    Lista de módulos disponibles.
     */
    private array $modules = [];

    /**
     * Constructor.
     *
     * @since 1.0.0
     * @param    string    $assets_url     URL base de los assets.
     * @param    string    $templates_dir  Ruta al directorio de plantillas.
     */
    public function __construct( string $menu_id = "", string $assets_url = "", string $templates_dir = "" ) {
        $this->menu_id      = $menu_id ? $menu_id : "mi-integracion-api";
        $this->assets_url   = $assets_url ? $assets_url : MiIntegracionApi_PLUGIN_URL . "assets/";
        $this->templates_dir = $templates_dir ? $templates_dir : MiIntegracionApi_PLUGIN_DIR . "templates/admin/";
        $this->logger       = new Logger("admin_menu");
        $this->modules      = Module_Loader::get_available_modules();
    }

    /**
     * Inicializa la clase y registra los hooks con WordPress.
     *
     * @since 1.0.0
     */
    public function init(): void {
        add_action( "admin_menu", array( $this, "add_menu_pages" ) );
        add_action( "admin_enqueue_scripts", array( $this, "enqueue_admin_scripts" ) );
        add_filter( "plugin_action_links_mi-integracion-api/mi-integracion-api.php", array( $this, "add_plugin_links" ) );
    }

    /**
     * Añade enlaces adicionales en la página de plugins.
     *
     * @since 1.0.0
     * @param    array    $links    Enlaces actuales.
     * @return   array              Enlaces modificados.
     */
    public function add_plugin_links( array $links ): array {
        $plugin_links = array(
            "<a href=\"" . admin_url( "admin.php?page={$this->menu_id}" ) . "\">" . __( "Configuración", "mi-integracion-api" ) . "</a>",
        );
        return array_merge( $plugin_links, $links );
    }

    /**
     * Registra los menús y submenús en la administración.
     *
     * @since 1.0.0
     */
    public function add_menu_pages(): void {
        if ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
            error_log('[MIAPI-DEBUG] add_menu_pages ejecutado en ' . date('c') . ' desde ' . debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2]['file'] . "\n", 3, WP_CONTENT_DIR . '/miapi_menu_debug.log');
        } else {
            error_log('[MIAPI-DEBUG] add_menu_pages ejecutado en ' . date('c') . "\n", 3, WP_CONTENT_DIR . '/miapi_menu_debug.log');
        }
        // Menú principal
        add_menu_page(
            __( "Mi Integración API", "mi-integracion-api" ),
            __( "Mi Integración API", "mi-integracion-api" ),
            "manage_options",
            $this->menu_id,
            array( $this, "display_dashboard_page" ),
            "dashicons-rest-api",
            100
        );

        // Submenús (evitar duplicar el dashboard)
        /*
        add_submenu_page(
            $this->menu_id,
            __( "Dashboard", "mi-integracion-api" ),
            __( "Dashboard", "mi-integracion-api" ),
            "manage_options",
            $this->menu_id,
            array( $this, "display_dashboard_page" )
        );
        */

        add_submenu_page(
            $this->menu_id,
            __( "Configuración", "mi-integracion-api" ),
            __( "Configuración", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-settings",
            array( $this, "display_settings_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Logs", "mi-integracion-api" ),
            __( "Logs", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-logs",
            array( $this, "display_logs_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Diagnóstico API", "mi-integracion-api" ),
            __( "Diagnóstico API", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-api-diagnostic",
            array( $this, "display_api_diagnostic_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Endpoints", "mi-integracion-api" ),
            __( "Endpoints", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-endpoints",
            array( $this, "display_endpoints_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Caché", "mi-integracion-api" ),
            __( "Caché", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-cache",
            array( $this, "display_cache_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Historial de Sincronización", "mi-integracion-api" ),
            __( "Historial de Sincronización", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-sync-history",
            array( $this, "display_sync_history_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Informe de Compatibilidad", "mi-integracion-api" ),
            __( "Informe de Compatibilidad", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-compatibility-report",
            array( $this, "display_compatibility_report_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Test de Conexión", "mi-integracion-api" ),
            __( "Test de Conexión", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-connection-check",
            array( $this, "display_connection_check_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Prueba HPOS", "mi-integracion-api" ),
            __( "Prueba HPOS", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-hpos-test",
            array( $this, "display_hpos_test_page" )
        );

        // Agregar página de sincronización individual
        add_submenu_page(
            $this->menu_id,
            __( "Sincronización Individual", "mi-integracion-api" ),
            __( "Sincronización Individual", "mi-integracion-api" ),
            "manage_options",
            "mi-sync-single-product",
            array( $this, "display_sync_single_product_page" )
        );

        add_submenu_page(
            $this->menu_id,
            __( "Mapeo", "mi-integracion-api" ),
            __( "Mapeo", "mi-integracion-api" ),
            "manage_options",
            "{$this->menu_id}-mapping",
            array( $this, "display_mapping_page" )
           );
         
           add_submenu_page(
            $this->menu_id,
            __( 'Registro de Errores', 'mi-integracion-api' ),
            __( 'Registro de Errores', 'mi-integracion-api' ),
            'manage_options',
            "{$this->menu_id}-sync-errors",
            array( $this, 'display_sync_errors_page' )
           );
          }

    /**
     * Muestra la página de dashboard.
     *
     * @since 1.0.0
     */
    public function display_dashboard_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\DashboardPageView" ) ) {
            \MiIntegracionApi\Admin\DashboardPageView::render_dashboard();
        } elseif ( class_exists( "MiIntegracionApi\\Admin\\DashboardPage" ) ) {
            $dashboard = new \MiIntegracionApi\Admin\DashboardPage();
            $dashboard->render();
        } else {
            $this->render_page( "dashboard" );
        }
    }

    /**
     * Muestra la página de configuración.
     *
     * @since 1.0.0
     */
    public function display_settings_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\SettingsPageView" ) ) {
            \MiIntegracionApi\Admin\SettingsPageView::render_settings();
        } else {
            $this->render_page( "settings" );
        }
    }

    /**
     * Muestra la página de logs.
     *
     * @since 1.0.0
     */
    public function display_logs_page() {
    	if ( class_exists( "MiIntegracionApi\\Admin\\LogsPage" ) ) {
    		\MiIntegracionApi\Admin\LogsPage::render();
    	} else {
    		// Fallback por si la clase no existe, aunque no debería ocurrir.
    		$this->render_page( "logs" );
    	}
    }

    /**
     * Muestra la página de diagnóstico API.
     *
     * @since 1.0.0
     */
    public function display_api_diagnostic_page() {
        include_once MIA_PLUGIN_DIR . 'admin/api-connection-test.php';
    }

    /**
     * Muestra la página de endpoints.
     *
     * @since 1.0.0
     */
    public function display_endpoints_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\EndpointsPageView" ) ) {
            \MiIntegracionApi\Admin\EndpointsPageView::render_endpoints();
        } else {
            $this->render_page( "endpoints" );
        }
    }

    /**
     * Muestra la página de caché.
     *
     * @since 1.0.0
     */
    public function display_cache_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\CachePageView" ) ) {
            \MiIntegracionApi\Admin\CachePageView::render_cache();
        } else {
            $this->render_page( "cache" );
        }
    }

    /**
     * Muestra la página de historial de sincronización.
     *
     * @since 1.0.0
     */
    public function display_sync_history_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\SyncHistoryPageView" ) ) {
            \MiIntegracionApi\Admin\SyncHistoryPageView::render_sync_history();
        } else {
            $this->render_page( "sync-history" );
        }
    }

    /**
     * Muestra la página de informe de compatibilidad.
     *
     * @since 1.0.0
     */
    public function display_compatibility_report_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\CompatibilityReportPageView" ) ) {
            \MiIntegracionApi\Admin\CompatibilityReportPageView::render_report();
        } else {
            $this->render_page( "compatibility-report" );
        }
    }

    /**
     * Muestra la página de test de conexión.
     *
     * @since 1.0.0
     */
    public function display_connection_check_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\ConnectionCheckPageView" ) ) {
            \MiIntegracionApi\Admin\ConnectionCheckPageView::render_check();
        } else {
            $this->render_page( "connection-check" );
        }
    }

    /**
     * Muestra la página de prueba HPOS.
     *
     * @since 1.0.0
     */
    public function display_hpos_test_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\HposTestPageView" ) ) {
            \MiIntegracionApi\Admin\HposTestPageView::render_test();
        } else {
            $this->render_page( "hpos-test" );
        }
    }

    /**
     * Muestra la página de mapeo.
     *
     * @since 1.0.0
     */
    public function display_mapping_page() {
        if ( class_exists( "MiIntegracionApi\\Admin\\MappingPageView" ) ) {
            \MiIntegracionApi\Admin\MappingPageView::render_mapping();
        } else {
            $this->render_page( "mapping" );
        }
    }
   
    /**
     * Muestra la página de registro de errores de sincronización.
     *
     * @since 1.1.0
     */
    public function display_sync_errors_page() {
    	if ( class_exists( "MiIntegracionApi\\Admin\\SyncErrorsPage" ) ) {
    		\MiIntegracionApi\Admin\SyncErrorsPage::render();
    	} else {
    		$this->render_page( "sync-errors" );
    	}
    }
    
    /**
     * Muestra la página de sincronización individual de productos.
     * 
     * @since 1.1.0
     */
    public function display_sync_single_product_page() {
        // Verificar que WooCommerce esté activo
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . 
                __('Esta funcionalidad requiere que WooCommerce esté activo.', 'mi-integracion-api') . 
                '</p></div>';
            return;
        }
        
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Sincronización Individual de Productos', 'mi-integracion-api'); ?></h1>
            
            <div class="card" style="max-width: 800px;">
                <h2><?php esc_html_e('Sincronizar un solo producto', 'mi-integracion-api'); ?></h2>
                
                <div>
                    <p><?php esc_html_e('Busca un producto por SKU o nombre para sincronizarlo individualmente desde Verial a WooCommerce.', 'mi-integracion-api'); ?></p>
                    
                    <form id="mi-sync-single-product-form" method="post">
                        <?php wp_nonce_field('mi_sync_single_product', '_ajax_nonce'); ?>
                        <input type="hidden" name="nonce" value="<?php echo esc_attr(wp_create_nonce('mi_sync_single_product')); ?>" />
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="sku"><?php esc_html_e('ID o Código de Barras', 'mi-integracion-api'); ?></label></th>
                                <td>
                                    <input type="text" id="sku" name="sku" class="regular-text" placeholder="<?php esc_attr_e('ID numérico o ReferenciaBarras', 'mi-integracion-api'); ?>">
                                    <p class="description"><?php esc_html_e('Introduce el ID numérico o el código de barras (ReferenciaBarras) del producto en Verial', 'mi-integracion-api'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="nombre"><?php esc_html_e('Nombre del producto', 'mi-integracion-api'); ?></label></th>
                                <td>
                                    <input type="text" id="nombre" name="nombre" class="regular-text" placeholder="<?php esc_attr_e('O introduce el nombre para búsqueda parcial', 'mi-integracion-api'); ?>">
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="categoria"><?php esc_html_e('Categoría (opcional)', 'mi-integracion-api'); ?></label></th>
                                <td>
                                    <select id="categoria" name="categoria" class="regular-text">
                                        <option value=""><?php esc_html_e('-- Seleccionar categoría --', 'mi-integracion-api'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="fabricante"><?php esc_html_e('Fabricante (opcional)', 'mi-integracion-api'); ?></label></th>
                                <td>
                                    <select id="fabricante" name="fabricante" class="regular-text">
                                        <option value=""><?php esc_html_e('-- Seleccionar fabricante --', 'mi-integracion-api'); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" id="sync-button" class="button button-primary">
                                <?php esc_html_e('Sincronizar producto', 'mi-integracion-api'); ?>
                            </button>
                            <button type="button" id="unlock-sync-button" class="button" style="margin-left: 10px; display: none;">
                                <?php esc_html_e('Desbloquear sincronización', 'mi-integracion-api'); ?>
                            </button>
                            <span class="spinner" style="float:none;"></span>
                        </p>
                    </form>
                    
                    <div id="sync-result" class="hidden notice">
                        <p></p>
                    </div>
                    
                    <p class="description">
                        <?php esc_html_e('Puedes buscar por ID numérico, código de barras (ReferenciaBarras) o por nombre (búsqueda parcial). Si ambos campos se rellenan, se prioriza el ID/ReferenciaBarras.', 'mi-integracion-api'); ?>
                    </p>
                </div>
            </div>
        </div>
        
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Cargar categorías y fabricantes al cargar la página
                $.ajax({
                    url: ajaxurl,
                    data: {
                        action: 'mi_sync_get_categorias',
                        _ajax_nonce: '<?php echo wp_create_nonce('mi_sync_get_categorias'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Llenar select de categorías
                            if (response.data && response.data.categories) {
                                $.each(response.data.categories, function(i, cat) {
                                    $('#categoria').append($('<option>', {
                                        value: cat.id,
                                        text: cat.nombre
                                    }));
                                });
                            }
                            
                            // Llenar select de fabricantes
                            if (response.data && response.data.manufacturers) {
                                $.each(response.data.manufacturers, function(i, fab) {
                                    $('#fabricante').append($('<option>', {
                                        value: fab.id,
                                        text: fab.nombre
                                    }));
                                });
                            }
                        }
                    }
                });
                
                // Autocompletar SKU
                $('#sku').autocomplete({
                    source: function(request, response) {
                        $.ajax({
                            url: ajaxurl,
                            dataType: "json",
                            data: {
                                action: 'mi_sync_autocomplete_sku',
                                term: request.term
                            },
                            success: function(data) {
                                response(data.data);
                            }
                        });
                    },
                    minLength: 2
                });
                
                // Manejar envío del formulario
                $('#mi-sync-single-product-form').on('submit', function(e) {
                    e.preventDefault();
                    
                    var $button = $('#sync-button');
                    var $spinner = $('.spinner');
                    var $result = $('#sync-result');
                    
                    // Validar que al menos un campo esté lleno
                    var sku = $('#sku').val().trim();
                    var nombre = $('#nombre').val().trim();
                    
                    if (sku === '' && nombre === '') {
                        $result.removeClass('hidden notice-success').addClass('notice-error').show();
                        $result.find('p').html('<?php esc_html_e('Debes introducir al menos un SKU o nombre de producto.', 'mi-integracion-api'); ?>');
                        return false;
                    }
                    
                    // Mostrar spinner y desactivar botón
                    $button.prop('disabled', true);
                    $spinner.addClass('is-active');
                    $result.hide();
                    
                    // Enviar solicitud AJAX
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'mi_sync_single_product',
                            _ajax_nonce: $('#_ajax_nonce').val(),
                            sku: sku,
                            nombre: nombre,
                            categoria: $('#categoria').val(),
                            fabricante: $('#fabricante').val()
                        },
                        success: function(response) {
                            if (response.success) {
                                $result.removeClass('hidden notice-error').addClass('notice-success');
                                $result.find('p').html(response.data.message);
                            } else {
                                $result.removeClass('hidden notice-success').addClass('notice-error');
                                $result.find('p').html(response.data.message || '<?php esc_html_e('Error al sincronizar el producto.', 'mi-integracion-api'); ?>');
                            }
                        },
                        error: function() {
                            $result.removeClass('hidden notice-success').addClass('notice-error');
                            $result.find('p').html('<?php esc_html_e('Error de comunicación con el servidor.', 'mi-integracion-api'); ?>');
                        },
                        complete: function() {
                            $button.prop('disabled', false);
                            $spinner.removeClass('is-active');
                            $result.show();
                        }
                    });
                    
                    return false;
                });
            });
        </script>
        <?php
    }
   
    /**
     * Renderiza una plantilla de página de administración.
     *
     * @since 1.0.0
     * @param    string    $template_name    Nombre de la plantilla a renderizar.
     */
    public function render_page( string $template_name = "dashboard" ): void {
        $template_path = $this->templates_dir . $template_name . '.php';
        if ( file_exists( $template_path ) ) {
            include $template_path;
        } else {
            $this->logger->error( 'Template not found: ' . $template_path );
            echo '<div class="notice notice-error"><p>' . esc_html__( 'Error: No se encontró la plantilla de la página.', 'mi-integracion-api' ) . '</p></div>';
        }
    }

    /**
     * Renderiza la cabecera de la página de administración.
     *
     * @since 1.0.0
     */
    private function render_header(): void {
        include $this->templates_dir . 'header.php';
    }

    /**
     * Renderiza el pie de página de la administración.
     *
     * @since 1.0.0
     */
    private function render_footer(): void {
        include $this->templates_dir . 'footer.php';
    }

    /**
     * Obtiene el título de la página actual.
     *
     * @since 1.0.0
     * @return   string    Título de la página.
     */
    private function get_current_page_title(): string {
        $page_titles = array(
            $this->menu_id                                => __( 'Dashboard', 'mi-integracion-api' ),
            "{$this->menu_id}-settings"                  => __( 'Configuración', 'mi-integracion-api' ),
            "{$this->menu_id}-logs"                      => __( 'Logs', 'mi-integracion-api' ),
            "{$this->menu_id}-api-diagnostic"            => __( 'Diagnóstico API', 'mi-integracion-api' ),
            "{$this->menu_id}-endpoints"                 => __( 'Endpoints', 'mi-integracion-api' ),
            "{$this->menu_id}-cache"                     => __( 'Caché', 'mi-integracion-api' ),
            "{$this->menu_id}-sync-history"              => __( 'Historial de Sincronización', 'mi-integracion-api' ),
            "{$this->menu_id}-compatibility-report"      => __( 'Informe de Compatibilidad', 'mi-integracion-api' ),
            "{$this->menu_id}-connection-check"          => __( 'Test de Conexión', 'mi-integracion-api' ),
            "{$this->menu_id}-hpos-test"                 => __( 'Prueba HPOS', 'mi-integracion-api' ),
            "{$this->menu_id}-mapping"                   => __( 'Mapeo', 'mi-integracion-api' ),
        );

        $current_page = $_GET['page'] ?? $this->menu_id;
        return $page_titles[ $current_page ] ?? __( 'Mi Integración API', 'mi-integracion-api' );
    }

    /**
     * Encola los scripts y estilos de administración condicionalmente.
     *
     * @since 1.0.0
     * @param    string    $hook_suffix    El hook de la página actual.
     */
    public function enqueue_admin_scripts( string $hook_suffix ): void {
        $this->logger->info("Enqueueing admin scripts for hook: " . $hook_suffix);

        // Script para el dashboard
        if ( $hook_suffix === 'toplevel_page_mi-integracion-api' || $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-dashboard' ) {
            wp_enqueue_script(
                'mi-integracion-api-dashboard',
                $this->assets_url . 'js/dashboard.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued dashboard.js');
        }

        // Script para la página de configuración
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-settings' ) {
            wp_enqueue_script(
                'mi-integracion-api-settings',
                $this->assets_url . 'js/settings.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued settings.js');
        }

        // Script para la página de logs
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-logs' ) {
            wp_enqueue_script(
                'mi-integracion-api-logs-viewer-main',
                $this->assets_url . 'js/logs-viewer-main.js',
                array( 'jquery', 'mi-integracion-api-utils' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued logs-viewer-main.js');
        }

        // Script para la página de diagnóstico API
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-api-diagnostic' ) {
            wp_enqueue_script(
                'mi-integracion-api-api-diagnostic',
                $this->assets_url . 'js/api-diagnostic.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued api-diagnostic.js');
        }

        // Script para la página de endpoints
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-endpoints' ) {
            wp_enqueue_script(
                'mi-integracion-api-endpoints',
                $this->assets_url . 'js/endpoints-page.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued endpoints-page.js');
        }

        // Script para la página de caché
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-cache' ) {
            wp_enqueue_script(
                'mi-integracion-api-cache-admin',
                $this->assets_url . 'js/cache-admin.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued cache-admin.js');
        }

        // Script para la página de informe de compatibilidad
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-compatibility-report' ) {
            wp_enqueue_script(
                'mi-integracion-api-compatibility-report',
                $this->assets_url . 'js/compatibility-report.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued compatibility-report.js');
        }

        // Script para la página de test de conexión
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-connection-check' ) {
            wp_enqueue_script(
                'mi-integracion-api-connection-check-page',
                $this->assets_url . 'js/connection-check-page.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued connection-check-page.js');
        }

        // Script para la página de prueba HPOS
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-hpos-test' ) {
            wp_enqueue_script(
                'mi-integracion-api-hpos-test',
                $this->assets_url . 'js/hpos-test.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued hpos-test.js');
        }

        // Script para la página de sincronización individual
        if ( $hook_suffix === 'mi-integracion-api_page_mi-sync-single-product' ) {
            wp_enqueue_script('jquery-ui-autocomplete');
            wp_enqueue_style(
                'jquery-ui-style',
                '//code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css',
                array(),
                '1.12.1'
            );
            
            wp_register_script(
                'mi-integracion-api-sync-single-product',
                $this->assets_url . 'js/sync-single-product.js',
                array('jquery', 'jquery-ui-autocomplete'),
                MiIntegracionApi_VERSION,
                true
            );
            
            // Asegurarnos que ajaxurl y nonce estén disponibles
            wp_localize_script(
                'mi-integracion-api-sync-single-product',
                'miSyncSingleProduct',
                array(
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('mi_sync_single_product'),
                    'debug' => defined('WP_DEBUG') && WP_DEBUG,
                    'i18n' => array(
                        'errorLoadingCategories' => __('Error al cargar categorías', 'mi-integracion-api'),
                        'errorLoadingManufacturers' => __('Error al cargar fabricantes', 'mi-integracion-api'),
                        'noResults' => __('No se encontraron resultados', 'mi-integracion-api'),
                        'searchMinLength' => __('Escribe al menos 2 caracteres', 'mi-integracion-api')
                    )
                )
            );
            
            wp_enqueue_script('mi-integracion-api-sync-single-product');
            $this->logger->info('Enqueued sync-single-product.js with localized data');
        }

        // Script para la página de mapeo
        if ( $hook_suffix === 'mi-integracion-api_page_mi-integracion-api-mapping' ) {
            wp_enqueue_script(
                'mi-integracion-api-mapping-app',
                $this->assets_url . 'js/mapping-app.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued mapping-app.js');
        }

        // Scripts que se aplican a todas las páginas de administración del plugin
        if ( strpos( $hook_suffix, 'mi-integracion-api' ) !== false ) {
            // Script para pestañas (tabs-manager.js)
            wp_enqueue_script(
                'mi-integracion-api-tabs-manager',
                $this->assets_url . 'js/tabs-manager.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued tabs-manager.js');

            // Script para mobile-optimizations.js
            wp_enqueue_script(
                'mi-integracion-api-mobile-optimizations',
                $this->assets_url . 'js/mobile-optimizations.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued mobile-optimizations.js');

            // Script para lazy-components.js
            wp_enqueue_script(
                'mi-integracion-api-lazy-components',
                $this->assets_url . 'js/lazy-components.js',
                array( 'jquery', 'mi-integracion-api-admin-main' ),
                MiIntegracionApi_VERSION,
                true
            );
            $this->logger->info('Enqueued lazy-components.js');
        }
    }
}
