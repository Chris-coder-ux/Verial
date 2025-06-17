<?php
/**
 * Administración avanzada de certificados SSL
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 */

namespace MiIntegracionApi\Admin;

use MiIntegracionApi\Core\ApiConnector;
use MiIntegracionApi\Helpers\Logger;
use MiIntegracionApi\SSL\SSLConfigManager;
use MiIntegracionApi\SSL\CertificateRotation;
use MiIntegracionApi\SSL\CertificateCache;

// Si este archivo es llamado directamente, abortar.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase de administración de certificados SSL
 *
 * @since 1.0.0
 * @package    MiIntegracionApi
 * @subpackage MiIntegracionApi/Admin
 */
class SSLCertificatesManager {
    /**
     * ApiConnector
     *
     * @var ApiConnector
     */
    private $api_connector;

    /**
     * SSLConfigManager
     *
     * @var SSLConfigManager
     */
    private $ssl_config;

    /**
     * CertificateRotation
     *
     * @var CertificateRotation
     */
    private $cert_rotation;

    /**
     * CertificateCache
     *
     * @var CertificateCache
     */
    private $cert_cache;

    /**
     * Logger
     *
     * @var Logger
     */
    private $logger;

    /**
     * Constructor
     */
    public function __construct() {
        $this->logger = new \MiIntegracionApi\Helpers\Logger('ssl_admin');
        $this->api_connector = new ApiConnector($this->logger);
        
        $this->ssl_config = new SSLConfigManager($this->logger);
        $this->cert_rotation = new CertificateRotation($this->logger);
        $this->cert_cache = new CertificateCache($this->logger);
        
        // Registrar acciones admin
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_miapi_rotate_certificates', [$this, 'handle_certificate_rotation']);
        add_action('admin_post_miapi_clear_certificate_cache', [$this, 'handle_clear_cache']);
        add_action('admin_post_miapi_fix_certificate_permissions', [$this, 'handle_fix_permissions']);
        add_action('admin_post_miapi_update_ssl_config', [$this, 'handle_update_ssl_config']);
        
        // Añadir scripts y estilos
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    /**
     * Añadir menú de administración
     */
    public function add_admin_menu() {
        // add_submenu_page(
        //     'mi-integracion-api',
        //     'SSL & Certificados',
        //     'SSL & Certificados',
        //     'manage_options',
        //     'mi-integracion-api-ssl',
        //     [$this, 'render_admin_page']
        // );
    }

    /**
     * Enqueue scripts y estilos
     */
    public function enqueue_scripts($hook) {
        if ($hook !== 'mi-integracion-api_page_mi-integracion-api-ssl') {
            return;
        }

        wp_enqueue_style(
            'miapi-ssl-admin-css',
            plugin_dir_url(dirname(__FILE__)) . 'assets/css/ssl-admin.css',
            [],
            '2.0.0'
        );

        wp_enqueue_script(
            'miapi-ssl-admin-js',
            plugin_dir_url(dirname(__FILE__)) . 'assets/js/ssl-admin.js',
            ['jquery'],
            '2.0.0',
            true
        );
    }

    /**
     * Registrar configuraciones
     */
    public function register_settings() {
        register_setting('miapi_ssl_settings', 'miapi_ssl_config_options');
        register_setting('miapi_ssl_settings', 'miapi_ssl_rotation_config');
        register_setting('miapi_ssl_settings', 'miapi_ssl_timeout_config');
    }

    /**
     * Procesar rotación de certificados
     */
    public function handle_certificate_rotation() {
        // Verificar nonce y permisos
        if (!isset($_POST['_wpnonce']) || 
            !wp_verify_nonce($_POST['_wpnonce'], 'miapi_rotate_certificates') || 
            !current_user_can('manage_options')) {
            wp_die('Acceso denegado', 'Error', ['response' => 403]);
        }
        
        $force = isset($_POST['force_rotation']) && $_POST['force_rotation'] === '1';
        
        try {
            $result = $this->cert_rotation->rotateCertificates($force);
            
            if ($result) {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_certificates_rotated',
                    'Los certificados se han rotado exitosamente.',
                    'success'
                );
            } else {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_certificates_rotation_failed',
                    'Error al rotar los certificados. Consulte los registros del plugin.',
                    'error'
                );
            }
        } catch (\Exception $e) {
            add_settings_error(
                'miapi_ssl_messages',
                'miapi_certificates_rotation_error',
                'Error: ' . $e->getMessage(),
                'error'
            );
        }
        
        // Redirigir de vuelta a la página de administración
        wp_safe_redirect(add_query_arg(
            ['page' => 'mi-integracion-api-ssl', 'settings-updated' => 'true'], 
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Procesar limpieza de caché
     */
    public function handle_clear_cache() {
        // Verificar nonce y permisos
        if (!isset($_POST['_wpnonce']) || 
            !wp_verify_nonce($_POST['_wpnonce'], 'miapi_clear_certificate_cache') || 
            !current_user_can('manage_options')) {
            wp_die('Acceso denegado', 'Error', ['response' => 403]);
        }
        
        try {
            $result = $this->cert_cache->clearCache();
            
            if ($result) {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_cache_cleared',
                    'La caché de certificados se ha limpiado exitosamente.',
                    'success'
                );
            } else {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_cache_clear_failed',
                    'Error al limpiar la caché de certificados. Consulte los registros del plugin.',
                    'error'
                );
            }
        } catch (\Exception $e) {
            add_settings_error(
                'miapi_ssl_messages',
                'miapi_cache_clear_error',
                'Error: ' . $e->getMessage(),
                'error'
            );
        }
        
        // Redirigir de vuelta a la página de administración
        wp_safe_redirect(add_query_arg(
            ['page' => 'mi-integracion-api-ssl', 'settings-updated' => 'true'], 
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Procesar corrección de permisos
     */
    public function handle_fix_permissions() {
        // Verificar nonce y permisos
        if (!isset($_POST['_wpnonce']) || 
            !wp_verify_nonce($_POST['_wpnonce'], 'miapi_fix_certificate_permissions') || 
            !current_user_can('manage_options')) {
            wp_die('Acceso denegado', 'Error', ['response' => 403]);
        }
        
        try {
            $results = $this->api_connector->fixCertificatePermissions();
            
            if (!empty($results['success'])) {
                $message = sprintf(
                    'Permisos corregidos exitosamente para %d certificado(s).',
                    count($results['success'])
                );
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_permissions_fixed',
                    $message,
                    'success'
                );
            } else if (!empty($results['failed'])) {
                $message = sprintf(
                    'Error al corregir permisos para %d certificado(s).',
                    count($results['failed'])
                );
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_permissions_fix_failed',
                    $message,
                    'error'
                );
            } else {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_no_certificates',
                    'No se encontraron certificados para procesar.',
                    'info'
                );
            }
        } catch (\Exception $e) {
            add_settings_error(
                'miapi_ssl_messages',
                'miapi_permissions_fix_error',
                'Error: ' . $e->getMessage(),
                'error'
            );
        }
        
        // Redirigir de vuelta a la página de administración
        wp_safe_redirect(add_query_arg(
            ['page' => 'mi-integracion-api-ssl', 'settings-updated' => 'true'], 
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Procesar actualización de configuración SSL
     */
    public function handle_update_ssl_config() {
        // Verificar nonce y permisos
        if (!isset($_POST['_wpnonce']) || 
            !wp_verify_nonce($_POST['_wpnonce'], 'miapi_update_ssl_config') || 
            !current_user_can('manage_options')) {
            wp_die('Acceso denegado', 'Error', ['response' => 403]);
        }
        
        // Actualizar opciones de configuración SSL
        if (isset($_POST['ssl_options'])) {
            $ssl_options = $_POST['ssl_options'];
            
            // Procesar booleanos
            $boolean_options = [
                'verify_peer', 'verify_peer_name', 'allow_self_signed', 
                'revocation_check', 'disable_ssl_local', 'debug_ssl'
            ];
            
            foreach ($boolean_options as $option) {
                $ssl_options[$option] = isset($ssl_options[$option]) && $ssl_options[$option] === '1';
            }
            
            // Actualizar configuración
            foreach ($ssl_options as $key => $value) {
                $this->ssl_config->setOption($key, $value);
            }
            
            $result = $this->ssl_config->saveConfig();
            
            if ($result) {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_ssl_config_updated',
                    'La configuración SSL se ha actualizado exitosamente.',
                    'success'
                );
            } else {
                add_settings_error(
                    'miapi_ssl_messages',
                    'miapi_ssl_config_update_failed',
                    'Error al actualizar la configuración SSL.',
                    'error'
                );
            }
        }
        
        // Actualizar configuración de rotación
        if (isset($_POST['rotation_config'])) {
            $rotation_config = $_POST['rotation_config'];
            
            // Convertir a tipos adecuados
            if (isset($rotation_config['rotation_interval'])) {
                $rotation_config['rotation_interval'] = (int) $rotation_config['rotation_interval'];
            }
            
            if (isset($rotation_config['expiration_threshold'])) {
                $rotation_config['expiration_threshold'] = (int) $rotation_config['expiration_threshold'];
            }
            
            if (isset($rotation_config['retention_count'])) {
                $rotation_config['retention_count'] = (int) $rotation_config['retention_count'];
            }
            
            // Booleanos
            $rotation_config['backup_enabled'] = isset($rotation_config['backup_enabled']) && $rotation_config['backup_enabled'] === '1';
            
            update_option('miapi_ssl_rotation_config', $rotation_config);
            
            add_settings_error(
                'miapi_ssl_messages',
                'miapi_rotation_config_updated',
                'La configuración de rotación de certificados se ha actualizado exitosamente.',
                'success'
            );
        }
        
        // Actualizar configuración de timeouts
        if (isset($_POST['timeout_config'])) {
            $timeout_config = $_POST['timeout_config'];
            
            // Convertir a tipos adecuados
            $numeric_options = [
                'default_timeout', 'connect_timeout', 'ssl_handshake_timeout',
                'max_retries', 'backoff_factor', 'jitter'
            ];
            
            foreach ($numeric_options as $option) {
                if (isset($timeout_config[$option])) {
                    $timeout_config[$option] = (float) $timeout_config[$option];
                }
            }
            
            update_option('miapi_ssl_timeout_config', $timeout_config);
            
            add_settings_error(
                'miapi_ssl_messages',
                'miapi_timeout_config_updated',
                'La configuración de timeouts se ha actualizado exitosamente.',
                'success'
            );
        }
        
        // Redirigir de vuelta a la página de administración
        wp_safe_redirect(add_query_arg(
            ['page' => 'mi-integracion-api-ssl', 'settings-updated' => 'true'], 
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Renderizar página de administración
     */
    public function render_admin_page() {
        // Obtener información actual para mostrar en la interfaz
        $ssl_options = $this->ssl_config->getAllOptions();
        $rotation_status = $this->cert_rotation->getStatus();
        $cache_stats = $this->cert_cache->getCacheStats();
        $ssl_diagnosis = $this->api_connector->diagnoseSSL();

        // --- RELAJAR SSL SOLO EN ADMIN SI HAY ERROR ---
        $ssl_error_detected = false;
        if (!empty($ssl_diagnosis['test_connection_success']) && !$ssl_diagnosis['test_connection_success']) {
            $ssl_error_detected = true;
        }
        // Si el usuario es admin y hay error SSL, deshabilitar verificación solo para la UI
        if (current_user_can('manage_options') && $ssl_error_detected) {
            $this->ssl_config->setOption('verify_peer', false);
            $ssl_options['verify_peer'] = false;
        }
        // Mostrar mensajes si hay
        settings_errors('miapi_ssl_messages');
        ?>
        <div class="wrap">
            <h1>Administración SSL & Certificados</h1>
            <?php if (current_user_can('manage_options') && $ssl_error_detected): ?>
                <div class="notice notice-warning">
                    <p><strong>Advertencia:</strong> La verificación de certificados SSL está temporalmente deshabilitada en la administración debido a un error de conexión SSL. Revise la configuración de certificados para restaurar la seguridad completa.</p>
                </div>
            <?php endif; ?>
            
            <h2 class="nav-tab-wrapper">
                <a href="#tab-status" class="nav-tab nav-tab-active">Estado</a>
                <a href="#tab-certificates" class="nav-tab">Certificados</a>
                <a href="#tab-settings" class="nav-tab">Configuración</a>
                <a href="#tab-advanced" class="nav-tab">Avanzado</a>
                <a href="#tab-tools" class="nav-tab">Herramientas</a>
            </h2>
            
            <div id="tab-status" class="tab-content active">
                <h2>Estado del Sistema SSL</h2>
                
                <div class="miapi-ssl-status-grid">
                    <div class="miapi-ssl-status-card">
                        <h3>Certificado Principal</h3>
                        <p>
                            <strong>Estado:</strong> 
                            <?php if ($rotation_status['certificado_principal']['existe']): ?>
                                <span class="miapi-ssl-status-ok">Disponible</span>
                            <?php else: ?>
                                <span class="miapi-ssl-status-error">No encontrado</span>
                            <?php endif; ?>
                        </p>
                        
                        <?php if ($rotation_status['certificado_principal']['existe']): ?>
                            <p><strong>Ruta:</strong> <?php echo esc_html($rotation_status['certificado_principal']['path']); ?></p>
                            <p><strong>Tamaño:</strong> <?php echo esc_html($this->format_bytes($rotation_status['certificado_principal']['tamaño'])); ?></p>
                            <p><strong>Modificado:</strong> <?php echo esc_html($rotation_status['certificado_principal']['fecha_modificacion']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="miapi-ssl-status-card">
                        <h3>Rotación de Certificados</h3>
                        <p><strong>Última rotación:</strong> <?php echo esc_html($rotation_status['ultima_rotacion']); ?></p>
                        <p><strong>Próxima rotación:</strong> <?php echo esc_html($rotation_status['proxima_rotacion']); ?></p>
                        <p>
                            <strong>Estado:</strong> 
                            <?php if ($rotation_status['necesita_rotacion']): ?>
                                <span class="miapi-ssl-status-warning">Requiere actualización</span>
                            <?php else: ?>
                                <span class="miapi-ssl-status-ok">Al día</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Fuentes disponibles:</strong> <?php echo esc_html($rotation_status['fuentes_disponibles']); ?></p>
                        <p><strong>Backups:</strong> <?php echo esc_html(count($rotation_status['backups'])); ?></p>
                    </div>
                    
                    <div class="miapi-ssl-status-card">
                        <h3>Caché de Certificados</h3>
                        <p><strong>Certificados en caché:</strong> <?php echo esc_html($cache_stats['count']); ?></p>
                        <p><strong>Tamaño total:</strong> <?php echo esc_html($this->format_bytes($cache_stats['total_size'])); ?></p>
                        <p><strong>Directorio:</strong> <?php echo esc_html($cache_stats['directory']); ?></p>
                        <?php if (!empty($cache_stats['newest'])): ?>
                            <p><strong>Más reciente:</strong> <?php echo esc_html($cache_stats['newest']); ?></p>
                        <?php endif; ?>
                    </div>
                    
                    <div class="miapi-ssl-status-card">
                        <h3>Diagnóstico SSL</h3>
                        <p>
                            <strong>OpenSSL:</strong> 
                            <?php if ($ssl_diagnosis['openssl_installed']): ?>
                                <span class="miapi-ssl-status-ok">Instalado</span>
                            <?php else: ?>
                                <span class="miapi-ssl-status-error">No disponible</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>Versión:</strong> <?php echo esc_html($ssl_diagnosis['openssl_version']); ?></p>
                        <p>
                            <strong>CURL SSL:</strong> 
                            <?php if ($ssl_diagnosis['curl_ssl_supported']): ?>
                                <span class="miapi-ssl-status-ok">Soportado</span>
                            <?php else: ?>
                                <span class="miapi-ssl-status-error">No soportado</span>
                            <?php endif; ?>
                        </p>
                        <p><strong>CURL Versión:</strong> <?php echo esc_html($ssl_diagnosis['curl_version']); ?></p>
                        <p>
                            <strong>Prueba de conexión:</strong> 
                            <?php if ($ssl_diagnosis['test_connection_success']): ?>
                                <span class="miapi-ssl-status-ok">Éxito</span>
                            <?php else: ?>
                                <span class="miapi-ssl-status-error">Fallida</span>
                            <?php endif; ?>
                        </p>
                    </div>
                </div>
                
                <div class="miapi-ssl-actions">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="miapi_rotate_certificates">
                        <?php wp_nonce_field('miapi_rotate_certificates'); ?>
                        <button type="submit" class="button button-primary">Actualizar Certificados Ahora</button>
                        <label>
                            <input type="checkbox" name="force_rotation" value="1">
                            Forzar actualización aunque no sea necesaria
                        </label>
                    </form>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="miapi_clear_certificate_cache">
                        <?php wp_nonce_field('miapi_clear_certificate_cache'); ?>
                        <button type="submit" class="button">Limpiar Caché de Certificados</button>
                    </form>
                    
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="miapi_fix_certificate_permissions">
                        <?php wp_nonce_field('miapi_fix_certificate_permissions'); ?>
                        <button type="submit" class="button">Corregir Permisos de Certificados</button>
                    </form>
                </div>
            </div>
            
            <div id="tab-certificates" class="tab-content">
                <h2>Administración de Certificados</h2>
                
                <h3>Certificado Principal</h3>
                <?php if ($rotation_status['certificado_principal']['existe']): ?>
                    <table class="form-table">
                        <tr>
                            <th>Ruta</th>
                            <td><?php echo esc_html($rotation_status['certificado_principal']['path']); ?></td>
                        </tr>
                        <tr>
                            <th>Tamaño</th>
                            <td><?php echo esc_html($this->format_bytes($rotation_status['certificado_principal']['tamaño'])); ?></td>
                        </tr>
                        <tr>
                            <th>Fecha de Modificación</th>
                            <td><?php echo esc_html($rotation_status['certificado_principal']['fecha_modificacion']); ?></td>
                        </tr>
                        <tr>
                            <th>Permisos</th>
                            <td>
                                <?php 
                                $path = $rotation_status['certificado_principal']['path'];
                                if (file_exists($path)) {
                                    echo esc_html(substr(sprintf('%o', fileperms($path)), -4));
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                        </tr>
                    </table>
                <?php else: ?>
                    <p>No se encontró un certificado principal. Use el botón "Actualizar Certificados Ahora" para crear uno.</p>
                <?php endif; ?>
                
                <h3>Backups de Certificados</h3>
                <?php if (!empty($rotation_status['backups'])): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Archivo</th>
                                <th>Tamaño</th>
                                <th>Fecha</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rotation_status['backups'] as $backup): ?>
                                <tr>
                                    <td><?php echo esc_html($backup['archivo']); ?></td>
                                    <td><?php echo esc_html($this->format_bytes($backup['tamaño'])); ?></td>
                                    <td><?php echo esc_html($backup['fecha']); ?></td>
                                    <td>
                                        <a href="#" class="miapi-ssl-restore-backup" data-backup="<?php echo esc_attr($backup['archivo']); ?>">Restaurar</a> | 
                                        <a href="#" class="miapi-ssl-download-backup" data-backup="<?php echo esc_attr($backup['archivo']); ?>">Descargar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No hay backups de certificados disponibles.</p>
                <?php endif; ?>
                
                <h3>Fuentes de Certificados</h3>
                <?php 
                $sources = $this->cert_rotation->getSources();
                if (!empty($sources)):
                ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>URL</th>
                                <th>Prioridad</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sources as $id => $source): ?>
                                <tr>
                                    <td><?php echo esc_html($source['name']); ?></td>
                                    <td><?php echo esc_html($source['url']); ?></td>
                                    <td><?php echo esc_html($source['priority']); ?></td>
                                    <td>
                                        <a href="#" class="miapi-ssl-test-source" data-source-id="<?php echo esc_attr($id); ?>">Probar</a> | 
                                        <a href="#" class="miapi-ssl-edit-source" data-source-id="<?php echo esc_attr($id); ?>">Editar</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <p>No hay fuentes de certificados configuradas.</p>
                <?php endif; ?>
                
                <p><a href="#" class="button miapi-ssl-add-source">Añadir Nueva Fuente</a></p>
            </div>
            
            <div id="tab-settings" class="tab-content">
                <h2>Configuración SSL</h2>
                
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="miapi_update_ssl_config">
                    <?php wp_nonce_field('miapi_update_ssl_config'); ?>
                    
                    <h3>Opciones Básicas</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="ssl_options_verify_peer">Verificar Certificados del Servidor</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_verify_peer" name="ssl_options[verify_peer]" value="1" <?php checked($ssl_options['verify_peer'], true); ?>>
                                    Habilitar verificación de certificados SSL del servidor
                                </label>
                                <p class="description">Verifica que el servidor remoto presente un certificado SSL válido y de confianza.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_verify_peer_name">Verificar Nombre del Host</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_verify_peer_name" name="ssl_options[verify_peer_name]" value="1" <?php checked($ssl_options['verify_peer_name'], true); ?>>
                                    Verificar que el nombre del certificado coincida con el host
                                </label>
                                <p class="description">Verifica que el nombre en el certificado del servidor coincida con el nombre del dominio.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_disable_ssl_local">SSL en Entornos Locales</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_disable_ssl_local" name="ssl_options[disable_ssl_local]" value="1" <?php checked($ssl_options['disable_ssl_local'], true); ?>>
                                    Deshabilitar verificación SSL en entornos de desarrollo local
                                </label>
                                <p class="description">Útil para desarrollo local donde se pueden usar certificados autofirmados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_ca_bundle_path">Ruta al Bundle CA</label></th>
                            <td>
                                <input type="text" id="ssl_options_ca_bundle_path" name="ssl_options[ca_bundle_path]" value="<?php echo esc_attr($ssl_options['ca_bundle_path']); ?>" class="regular-text">
                                <p class="description">Ruta completa al archivo de certificados CA. Dejar en blanco para detección automática.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>Opciones Avanzadas</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="ssl_options_allow_self_signed">Certificados Autofirmados</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_allow_self_signed" name="ssl_options[allow_self_signed]" value="1" <?php checked($ssl_options['allow_self_signed'], true); ?>>
                                    Permitir certificados autofirmados
                                </label>
                                <p class="description"><strong>¡Advertencia!</strong> Esto reduce la seguridad. Solo use en entornos controlados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_verify_depth">Profundidad de Verificación</label></th>
                            <td>
                                <select id="ssl_options_verify_depth" name="ssl_options[verify_depth]">
                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                        <option value="<?php echo $i; ?>" <?php selected($ssl_options['verify_depth'], $i); ?>><?php echo $i; ?></option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">Controla cuántos niveles de certificados se verifican en la cadena de certificación.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_ssl_version">Versión SSL/TLS</label></th>
                            <td>
                                <select id="ssl_options_ssl_version" name="ssl_options[ssl_version]">
                                    <option value="" <?php selected($ssl_options['ssl_version'], ''); ?>>Automático (recomendado)</option>
                                    <option value="TLSv1.2" <?php selected($ssl_options['ssl_version'], 'TLSv1.2'); ?>>TLS 1.2</option>
                                    <option value="TLSv1.1" <?php selected($ssl_options['ssl_version'], 'TLSv1.1'); ?>>TLS 1.1</option>
                                    <option value="TLSv1.0" <?php selected($ssl_options['ssl_version'], 'TLSv1.0'); ?>>TLS 1.0</option>
                                    <?php if (defined('CURL_SSLVERSION_TLSv1_3')): ?>
                                        <option value="TLSv1.3" <?php selected($ssl_options['ssl_version'], 'TLSv1.3'); ?>>TLS 1.3</option>
                                    <?php endif; ?>
                                </select>
                                <p class="description">Especifica la versión de SSL/TLS a utilizar. Se recomienda dejar en automático.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_revocation_check">Verificación de Revocación</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_revocation_check" name="ssl_options[revocation_check]" value="1" <?php checked($ssl_options['revocation_check'], true); ?>>
                                    Verificar si los certificados han sido revocados
                                </label>
                                <p class="description">Verifica el estado de revocación de los certificados mediante OCSP o CRL.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_cipher_list">Lista de Cifrados</label></th>
                            <td>
                                <input type="text" id="ssl_options_cipher_list" name="ssl_options[cipher_list]" value="<?php echo esc_attr($ssl_options['cipher_list'] ?? ''); ?>" class="regular-text">
                                <p class="description">Lista personalizada de cifrados SSL para uso avanzado. Dejar en blanco para valores predeterminados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_options_debug_ssl">Depuración SSL</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="ssl_options_debug_ssl" name="ssl_options[debug_ssl]" value="1" <?php checked($ssl_options['debug_ssl'] ?? false, true); ?>>
                                    Habilitar registro detallado de comunicación SSL
                                </label>
                                <p class="description">Registra información detallada sobre conexiones SSL para solución de problemas.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>Configuración de Rotación</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="rotation_interval">Intervalo de Rotación</label></th>
                            <td>
                                <input type="number" id="rotation_interval" name="rotation_config[rotation_interval]" value="<?php echo esc_attr($rotation_status['rotation_interval'] ?? 30); ?>" min="1" max="365">
                                <span> días</span>
                                <p class="description">Días entre rotaciones automáticas de certificados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="expiration_threshold">Umbral de Expiración</label></th>
                            <td>
                                <input type="number" id="expiration_threshold" name="rotation_config[expiration_threshold]" value="<?php echo esc_attr($rotation_status['expiration_threshold'] ?? 30); ?>" min="1" max="365">
                                <span> días</span>
                                <p class="description">Días antes de la expiración para renovar certificados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="retention_count">Retención de Backups</label></th>
                            <td>
                                <input type="number" id="retention_count" name="rotation_config[retention_count]" value="<?php echo esc_attr($rotation_status['retention_count'] ?? 3); ?>" min="0" max="100">
                                <p class="description">Número de versiones anteriores a mantener. Use 0 para conservar todos.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="backup_enabled">Backups Automáticos</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" id="backup_enabled" name="rotation_config[backup_enabled]" value="1" <?php checked($rotation_status['backup_enabled'] ?? true, true); ?>>
                                    Habilitar creación de backups antes de la rotación
                                </label>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="rotation_schedule">Programación de Rotación</label></th>
                            <td>
                                <select id="rotation_schedule" name="rotation_config[rotation_schedule]">
                                    <option value="daily" <?php selected($rotation_status['rotation_schedule'] ?? 'daily', 'daily'); ?>>Diario</option>
                                    <option value="weekly" <?php selected($rotation_status['rotation_schedule'] ?? 'daily', 'weekly'); ?>>Semanal</option>
                                    <option value="monthly" <?php selected($rotation_status['rotation_schedule'] ?? 'daily', 'monthly'); ?>>Mensual</option>
                                </select>
                                <p class="description">Frecuencia con la que se comprueba si se necesita una rotación.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <h3>Configuración de Timeouts</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="default_timeout">Timeout Predeterminado</label></th>
                            <td>
                                <input type="number" id="default_timeout" name="timeout_config[default_timeout]" value="<?php echo esc_attr(get_option('miapi_ssl_timeout_config')['default_timeout'] ?? 30); ?>" min="1" max="300">
                                <span> segundos</span>
                                <p class="description">Timeout general para solicitudes HTTP.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="connect_timeout">Timeout de Conexión</label></th>
                            <td>
                                <input type="number" id="connect_timeout" name="timeout_config[connect_timeout]" value="<?php echo esc_attr(get_option('miapi_ssl_timeout_config')['connect_timeout'] ?? 10); ?>" min="1" max="60">
                                <span> segundos</span>
                                <p class="description">Tiempo máximo para establecer una conexión.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="ssl_handshake_timeout">Timeout de Handshake SSL</label></th>
                            <td>
                                <input type="number" id="ssl_handshake_timeout" name="timeout_config[ssl_handshake_timeout]" value="<?php echo esc_attr(get_option('miapi_ssl_timeout_config')['ssl_handshake_timeout'] ?? 15); ?>" min="1" max="60">
                                <span> segundos</span>
                                <p class="description">Tiempo máximo para el intercambio inicial SSL.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="max_retries">Máximo de Reintentos</label></th>
                            <td>
                                <input type="number" id="max_retries" name="timeout_config[max_retries]" value="<?php echo esc_attr(get_option('miapi_ssl_timeout_config')['max_retries'] ?? 3); ?>" min="0" max="10">
                                <p class="description">Número máximo de reintentos para solicitudes fallidas.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="backoff_factor">Factor de Backoff</label></th>
                            <td>
                                <input type="number" id="backoff_factor" name="timeout_config[backoff_factor]" value="<?php echo esc_attr(get_option('miapi_ssl_timeout_config')['backoff_factor'] ?? 1.5); ?>" min="1" max="5" step="0.1">
                                <p class="description">Factor para cálculo de espera exponencial entre reintentos.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Guardar Cambios">
                    </p>
                </form>
            </div>
            
            <div id="tab-advanced" class="tab-content">
                <h2>Opciones Avanzadas</h2>
                
                <h3>Autenticación Mutua (mTLS)</h3>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="miapi_update_mtls_config">
                    <?php wp_nonce_field('miapi_update_mtls_config'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="client_cert_path">Certificado Cliente</label></th>
                            <td>
                                <input type="text" id="client_cert_path" name="ssl_options[client_cert_path]" value="<?php echo esc_attr($ssl_options['client_cert_path'] ?? ''); ?>" class="regular-text">
                                <input type="file" id="client_cert_file" name="client_cert_file">
                                <p class="description">Certificado cliente para autenticación mutua (mTLS).</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="client_key_path">Clave Privada Cliente</label></th>
                            <td>
                                <input type="text" id="client_key_path" name="ssl_options[client_key_path]" value="<?php echo esc_attr($ssl_options['client_key_path'] ?? ''); ?>" class="regular-text">
                                <input type="file" id="client_key_file" name="client_key_file">
                                <p class="description">Clave privada para el certificado cliente.</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Guardar Configuración mTLS">
                    </p>
                </form>
                
                <h3>Fuentes de Certificados Personalizadas</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="miapi_add_cert_source">
                    <?php wp_nonce_field('miapi_add_cert_source'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="source_id">ID de la Fuente</label></th>
                            <td>
                                <input type="text" id="source_id" name="source[id]" class="regular-text" required>
                                <p class="description">Identificador único para esta fuente.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="source_name">Nombre</label></th>
                            <td>
                                <input type="text" id="source_name" name="source[name]" class="regular-text" required>
                                <p class="description">Nombre descriptivo de la fuente.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="source_url">URL</label></th>
                            <td>
                                <input type="url" id="source_url" name="source[url]" class="regular-text" required>
                                <p class="description">URL de la fuente de certificados.</p>
                            </td>
                        </tr>
                        
                        <tr>
                            <th><label for="source_priority">Prioridad</label></th>
                            <td>
                                <input type="number" id="source_priority" name="source[priority]" value="100" min="1" max="1000" required>
                                <p class="description">Prioridad (valores menores = mayor prioridad).</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Agregar Fuente de Certificados">
                    </p>
                </form>
                
                <h3>Configuración de Proxy</h3>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="miapi_update_ssl_proxy">
                    <?php wp_nonce_field('miapi_update_ssl_proxy'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="ssl_proxy">Proxy para Conexiones SSL</label></th>
                            <td>
                                <input type="text" id="ssl_proxy" name="ssl_options[proxy]" value="<?php echo esc_attr($ssl_options['proxy'] ?? ''); ?>" class="regular-text">
                                <p class="description">URL del proxy (formato: http://usuario:contraseña@host:puerto).</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Guardar Configuración de Proxy">
                    </p>
                </form>
            </div>
            
            <div id="tab-tools" class="tab-content">
                <h2>Herramientas SSL</h2>
                
                <div class="card">
                    <h3>Prueba de Conexión SSL</h3>
                    <p>Verifique si puede establecer conexiones SSL seguras con un servidor remoto.</p>
                    <form method="post" action="" id="miapi-ssl-test-connection-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="test_url">URL para Probar</label></th>
                                <td>
                                    <input type="url" id="test_url" name="test_url" value="https://www.google.com" class="regular-text" required>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">Probar Conexión</button>
                        </p>
                    </form>
                    
                    <div id="miapi-ssl-test-connection-result" class="hidden">
                        <h4>Resultado:</h4>
                        <pre id="miapi-ssl-test-connection-output"></pre>
                    </div>
                </div>
                
                <div class="card">
                    <h3>Verificar Certificado SSL</h3>
                    <p>Analizar un certificado SSL para verificar su validez, fecha de expiración y otros detalles.</p>
                    <form method="post" action="" id="miapi-ssl-verify-cert-form">
                        <table class="form-table">
                            <tr>
                                <th><label for="verify_domain">Dominio a Verificar</label></th>
                                <td>
                                    <input type="text" id="verify_domain" name="verify_domain" class="regular-text" required>
                                    <p class="description">Ingrese un dominio (ej: www.example.com).</p>
                                </td>
                            </tr>
                            <tr>
                                <th><label>Opciones</label></th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="verify_options[show_chain]" value="1">
                                        Mostrar cadena completa de certificados
                                    </label>
                                    <br>
                                    <label>
                                        <input type="checkbox" name="verify_options[check_revocation]" value="1">
                                        Verificar estado de revocación
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <button type="submit" class="button button-primary">Verificar Certificado</button>
                        </p>
                    </form>
                    
                    <div id="miapi-ssl-verify-cert-result" class="hidden">
                        <h4>Resultado:</h4>
                        <pre id="miapi-ssl-verify-cert-output"></pre>
                    </div>
                </div>
                
                <div class="card">
                    <h3>Herramientas por línea de comandos</h3>
                    <p>Este plugin incluye un script para gestionar certificados desde la línea de comandos:</p>
                    <pre>php <?php echo esc_html(plugin_dir_path(dirname(__FILE__)) . 'includes/check-certificates.php'); ?> [acción]</pre>
                    
                    <p><strong>Acciones disponibles:</strong></p>
                    <ul>
                        <li>check: Verificar estado de certificados</li>
                        <li>rotate: Forzar rotación de certificados</li>
                        <li>clear-cache: Limpiar caché de certificados</li>
                        <li>fix-permissions: Corregir permisos de certificados</li>
                        <li>diagnose: Ejecutar diagnóstico completo</li>
                        <li>help: Mostrar ayuda</li>
                    </ul>
                    
                    <p><strong>Opciones:</strong></p>
                    <ul>
                        <li>--verbose, -v: Mostrar información detallada</li>
                        <li>--force, -f: Forzar operaciones (para rotate)</li>
                    </ul>
                    
                    <p>Ejemplo: <code>php <?php echo esc_html(plugin_dir_path(dirname(__FILE__)) . 'includes/check-certificates.php'); ?> diagnose --verbose</code></p>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Gestión de pestañas
            $('.nav-tab').on('click', function(e) {
                e.preventDefault();
                
                // Ocultar todas las pestañas y mostrar la seleccionada
                $('.tab-content').removeClass('active');
                $('.nav-tab').removeClass('nav-tab-active');
                
                $(this).addClass('nav-tab-active');
                $($(this).attr('href')).addClass('active');
            });
            
            // Formulario de prueba de conexión
            $('#miapi-ssl-test-connection-form').on('submit', function(e) {
                e.preventDefault();
                
                var url = $('#test_url').val();
                var resultDiv = $('#miapi-ssl-test-connection-result');
                var output = $('#miapi-ssl-test-connection-output');
                
                resultDiv.removeClass('hidden');
                output.html('Probando conexión a ' + url + '...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'miapi_test_ssl_connection',
                        url: url,
                        _ajax_nonce: '<?php echo wp_create_nonce('miapi_test_ssl_connection'); ?>'
                    },
                    success: function(response) {
                        output.html(response.data);
                    },
                    error: function() {
                        output.html('Error al realizar la prueba de conexión.');
                    }
                });
            });
            
            // Formulario de verificación de certificado
            $('#miapi-ssl-verify-cert-form').on('submit', function(e) {
                e.preventDefault();
                
                var domain = $('#verify_domain').val();
                var options = {};
                
                $('input[name^="verify_options"]:checked').each(function() {
                    var name = $(this).attr('name').replace('verify_options[', '').replace(']', '');
                    options[name] = true;
                });
                
                var resultDiv = $('#miapi-ssl-verify-cert-result');
                var output = $('#miapi-ssl-verify-cert-output');
                
                resultDiv.removeClass('hidden');
                output.html('Verificando certificado de ' + domain + '...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'miapi_verify_ssl_cert',
                        domain: domain,
                        options: options,
                        _ajax_nonce: '<?php echo wp_create_nonce('miapi_verify_ssl_cert'); ?>'
                    },
                    success: function(response) {
                        output.html(response.data);
                    },
                    error: function() {
                        output.html('Error al verificar el certificado.');
                    }
                });
            });
        });
        </script>
        
        <style>
        .miapi-ssl-status-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            grid-gap: 20px;
            margin: 20px 0;
        }
        
        .miapi-ssl-status-card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 15px;
        }
        
        .miapi-ssl-status-card h3 {
            margin-top: 0;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .miapi-ssl-status-ok {
            color: #46b450;
            font-weight: bold;
        }
        
        .miapi-ssl-status-warning {
            color: #ffb900;
            font-weight: bold;
        }
        
        .miapi-ssl-status-error {
            color: #dc3232;
            font-weight: bold;
        }
        
        .miapi-ssl-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin: 20px 0;
        }
        
        .miapi-ssl-actions form {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .tab-content {
            display: none;
            padding: 20px 0;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .card {
            background: #fff;
            border: 1px solid #ccd0d4;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .hidden {
            display: none;
        }
        
        pre {
            background: #f1f1f1;
            padding: 15px;
            border-radius: 3px;
            overflow: auto;
            max-height: 300px;
        }
        </style>
        <?php
    }

    /**
     * Formatea un tamaño en bytes a una representación legible
     */
    private function format_bytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        
        $bytes /= pow(1024, $pow);
        
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}

// --- Eliminado para evitar menús duplicados ---
// new SSLCertificatesManager();
