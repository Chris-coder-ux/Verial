<?php
/**
 * Clase para manejar campos personalizados en WooCommerce
 *
 * @package MiIntegracionApi
 * @subpackage WooCommerce
 * @since 1.0.0
 */

namespace MiIntegracionApi\WooCommerce;

if (!defined('ABSPATH')) {
    exit; // Salir si se accede directamente
}

/**
 * Clase Custom_Fields
 * 
 * Maneja la creación y gestión de campos personalizados en WooCommerce
 */
class Custom_Fields {
    
    /**
     * Conector de API para Verial
     * 
     * @var \MiIntegracionApi\Core\ApiConnector
     */
    private $api_connector;
    
    /**
     * Constructor
     * 
     * @param \MiIntegracionApi\Core\ApiConnector $api_connector Instancia del conector API (opcional)
     */
    public function __construct(\MiIntegracionApi\Core\ApiConnector $api_connector = null) {
        // Si se proporciona un conector de API, lo guardamos
        if ($api_connector !== null) {
            $this->api_connector = $api_connector;
        } 
        // Si no, intentamos obtenerlo a través del helper si está disponible
        elseif (class_exists('\MiIntegracionApi\Helpers\ApiHelpers') && method_exists('\MiIntegracionApi\Helpers\ApiHelpers', 'get_connector')) {
            $this->api_connector = \MiIntegracionApi\Helpers\ApiHelpers::get_connector();
        }
    }
    
    /**
     * Inicializa los hooks para campos personalizados
     */
    public function init() {
        // Hooks para campos personalizados en productos
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_custom_fields'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_custom_fields'));
        
        // Hooks para campos personalizados en pedidos
        add_action('woocommerce_admin_order_data_after_billing_address', array($this, 'add_order_custom_fields'));
        add_action('woocommerce_process_shop_order_meta', array($this, 'save_order_custom_fields'));
        
        // Hooks para campos personalizados en categorías
        add_action('product_cat_add_form_fields', array($this, 'add_category_custom_fields'));
        add_action('product_cat_edit_form_fields', array($this, 'edit_category_custom_fields'), 10, 2);
        add_action('created_product_cat', array($this, 'save_category_custom_fields'));
        add_action('edited_product_cat', array($this, 'save_category_custom_fields'));
    }
    
    /**
     * Añade campos personalizados a los productos
     */
    public function add_product_custom_fields() {
        // Campo ID de Verial
        woocommerce_wp_text_input(
            array(
                'id'          => '_verial_product_id',
                'label'       => __('ID de Verial', 'mi-integracion-api'),
                'description' => __('Identificador del producto en Verial ERP', 'mi-integracion-api'),
                'desc_tip'    => true,
            )
        );
    }
    
    /**
     * Guarda los campos personalizados de productos
     * 
     * @param int $post_id ID del producto
     */
    public function save_product_custom_fields($post_id) {
        // Guardar ID de Verial
        $verial_id = isset($_POST['_verial_product_id']) ? sanitize_text_field($_POST['_verial_product_id']) : '';
        update_post_meta($post_id, '_verial_product_id', $verial_id);
    }
    
    /**
     * Añade campos personalizados a los pedidos
     * 
     * @param \WC_Order $order Objeto del pedido
     */
    public function add_order_custom_fields($order) {
        $order_id = $order->get_id();
        $verial_doc_id = get_post_meta($order_id, '_verial_documento_id', true);
        $verial_doc_num = get_post_meta($order_id, '_verial_documento_numero', true);
        
        echo '<div class="order_data_column">';
        echo '<h4>' . __('Datos de Verial', 'mi-integracion-api') . '</h4>';
        
        woocommerce_wp_text_input(
            array(
                'id'          => '_verial_documento_id',
                'label'       => __('ID de Documento en Verial', 'mi-integracion-api'),
                'value'       => $verial_doc_id,
                'wrapper_class' => 'form-field-wide',
            )
        );
        
        woocommerce_wp_text_input(
            array(
                'id'          => '_verial_documento_numero',
                'label'       => __('Número de Documento en Verial', 'mi-integracion-api'),
                'value'       => $verial_doc_num,
                'wrapper_class' => 'form-field-wide',
            )
        );
        
        echo '</div>';
    }
    
    /**
     * Guarda los campos personalizados de pedidos
     * 
     * @param int $order_id ID del pedido
     */
    public function save_order_custom_fields($order_id) {
        if (isset($_POST['_verial_documento_id'])) {
            update_post_meta($order_id, '_verial_documento_id', sanitize_text_field($_POST['_verial_documento_id']));
        }
        
        if (isset($_POST['_verial_documento_numero'])) {
            update_post_meta($order_id, '_verial_documento_numero', sanitize_text_field($_POST['_verial_documento_numero']));
        }
    }
    
    /**
     * Añade campos personalizados al formulario de nueva categoría
     */
    public function add_category_custom_fields() {
        ?>
        <div class="form-field">
            <label for="_verial_category_id"><?php _e('ID de Categoría en Verial', 'mi-integracion-api'); ?></label>
            <input type="text" name="_verial_category_id" id="_verial_category_id" value="" />
            <p class="description"><?php _e('Identificador de la categoría en Verial ERP', 'mi-integracion-api'); ?></p>
        </div>
        <?php
    }
    
    /**
     * Añade campos personalizados al formulario de edición de categoría
     * 
     * @param \WP_Term $term Término a editar
     */
    public function edit_category_custom_fields($term) {
        $verial_category_id = get_term_meta($term->term_id, '_verial_category_id', true);
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="_verial_category_id"><?php _e('ID de Categoría en Verial', 'mi-integracion-api'); ?></label>
            </th>
            <td>
                <input type="text" name="_verial_category_id" id="_verial_category_id" value="<?php echo esc_attr($verial_category_id); ?>" />
                <p class="description"><?php _e('Identificador de la categoría en Verial ERP', 'mi-integracion-api'); ?></p>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Guarda los campos personalizados de categorías
     * 
     * @param int $term_id ID del término
     */
    public function save_category_custom_fields($term_id) {
        if (isset($_POST['_verial_category_id'])) {
            update_term_meta($term_id, '_verial_category_id', sanitize_text_field($_POST['_verial_category_id']));
        }
    }
}
