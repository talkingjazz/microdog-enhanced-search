<?php
/**
 * Plugin Name: Enhanced Search for WooCommerce
 * Plugin URI: https://microdog.co.uk/wp/enhanced-search
 * Description: Extends WooCommerce product search to include attributes, SKU, custom fields, variations, and more.
 * Version: 1.0.2
 * Author: Microdog
 * Author URI: https://microdog.co.uk/wp
 * Text Domain: microdog-enhanced-search
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

// Plugin constants
define('MDWES_VERSION', '1.0.2');
define('MDWES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MDWES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('MDWES_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')), true)) {
    add_action('admin_notices', function() {
        // translators: %s: Link to plugins page
        $message = __('Enhanced Search for WooCommerce requires WooCommerce to be installed and active. %s', 'microdog-enhanced-search');
        echo '<div class="notice notice-error"><p>';
        echo wp_kses_post(sprintf($message, '<a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Return to plugins page', 'microdog-enhanced-search') . '</a>'));
        echo '</p></div>';
    });
    return;
}

/**
 * Plugin activation hook
 */
function microdog_wces_activate() {
    // Set default settings
    $default_settings = array(
        'search_sku' => 1,
        'exact_match_sku' => 0,
        'search_attributes' => 1,
        'exact_match_attributes' => 0,
        'search_custom_fields' => 0,
        'exact_match_custom_fields' => 0,
        'search_categories' => 1,
        'exact_match_categories' => 0,
        'search_tags' => 1,
        'exact_match_tags' => 0,
        'search_excerpt' => 1,
        'exact_match_excerpt' => 0,
        'search_variations' => 1,
        'exact_match_variations' => 0,
        'enable_caching' => 0,
        'title_weight' => 10,
        'content_weight' => 5,
        'excerpt_weight' => 3,
        'sku_weight' => 8,
        'attribute_weight' => 6
    );
    
    // Only set defaults if settings don't exist
    if (!get_option('microdog_wc_enhanced_search_settings')) {
        update_option('microdog_wc_enhanced_search_settings', $default_settings);
    }
    
    // Create transients folder if it doesn't exist
    if (!file_exists(WP_CONTENT_DIR . '/cache/microdog-wces')) {
        wp_mkdir_p(WP_CONTENT_DIR . '/cache/microdog-wces');
    }
    
    // Add capabilities
    $admin = get_role('administrator');
    if ($admin) {
        $admin->add_cap('manage_wces_settings');
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Plugin deactivation hook
 */
function microdog_wces_deactivate() {
    // Clear cached data
    $cache_cleared = wp_cache_flush();
    
    // Delete transients
    $transient_keys = array('mdwes_');
    foreach ($transient_keys as $key_prefix) {
        // Get all transients with this prefix
        $transients = get_option('_transient_keys_' . $key_prefix, array());
        
        // Delete each transient
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
        
        // Clean up the option storing the keys
        delete_option('_transient_keys_' . $key_prefix);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'microdog_wces_activate');
register_deactivation_hook(__FILE__, 'microdog_wces_deactivate');

class Microdog_WC_Enhanced_Search {

    /**
     * Singleton instance
     * 
     * @var Microdog_WC_Enhanced_Search
     */
    private static $instance = null;

    /**
     * Plugin settings
     * 
     * @var array
     */
    private $settings;
    
    /**
     * Get the singleton instance
     * 
     * @return Microdog_WC_Enhanced_Search
     */
    public static function get_instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Initialize settings
        $this->settings = get_option('microdog_wc_enhanced_search_settings', array());
        
        // Setup plugin hooks
        add_action('plugins_loaded', array($this, 'init'));
        add_action('init', array($this, 'load_textdomain'));
        add_filter('plugin_action_links_' . MDWES_PLUGIN_BASENAME, array($this, 'add_settings_link'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        
        // Load assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        add_action('admin_menu', array($this, 'add_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_filter('posts_clauses', array($this, 'modify_search_query'), 10, 2);
        add_action('pre_get_posts', array($this, 'handle_variation_search'));
        
        // Add search form filters
        add_filter('get_product_search_form', array($this, 'modify_search_form'));
        add_filter('woocommerce_product_search_form', array($this, 'modify_search_form'));
        
        // Register action and filter hooks
        if (!is_admin()) {
            add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        }
        
        // Add admin AJAX actions
        add_action('wp_ajax_microdog_wces_clear_cache', array($this, 'ajax_clear_cache'));
    }
    
    /**
     * AJAX handler to clear search cache
     */
    public function ajax_clear_cache() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['nonce'])), 'microdog_wces_nonce')) {
            wp_send_json_error(array('message' => esc_html__('Security check failed', 'microdog-enhanced-search')));
            return;
        }
        
        // Check user capability
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => esc_html__('You do not have permission to do this', 'microdog-enhanced-search')));
            return;
        }
        
        // Clear transients using WordPress API
        $transient_keys = get_option('_transient_keys_mdwes_', array());
        $deleted = 0;
        
        foreach ($transient_keys as $transient) {
            if (delete_transient($transient)) {
                $deleted++;
            }
        }
        
        // Reset the keys array
        update_option('_transient_keys_mdwes_', array());
        
        // translators: %d: Number of cache items removed
        $message = sprintf(__('Cache cleared successfully. %d items removed.', 'microdog-enhanced-search'), $deleted);
        wp_send_json_success(array('message' => esc_html($message)));
    }

    // ========================
    // CORE FUNCTIONALITY
    // ========================
    
    /**
     * Declare compatibility with WooCommerce HPOS
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables', 
                __FILE__, 
                true
            );
        }
    }

    /**
     * Add settings link to the plugins page
     * 
     * @param array $links Plugin links
     * @return array Modified plugin links
     */
    public function add_settings_link($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=microdog-wc-enhanced-search')),
            esc_html__('Settings', 'microdog-enhanced-search')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'microdog-enhanced-search',
            false,
            dirname(MDWES_PLUGIN_BASENAME) . '/languages/'
        );
    }

    /**
     * Enqueue admin assets
     * 
     * @param string $hook Current admin page
     */
    public function enqueue_admin_assets($hook) {
        if ('woocommerce_page_microdog-wc-enhanced-search' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'microdog-wc-enhanced-search-admin',
            MDWES_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            MDWES_VERSION
        );
        
        wp_enqueue_script(
            'microdog-wc-enhanced-search-admin',
            MDWES_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            MDWES_VERSION,
            true
        );
        
        wp_localize_script(
            'microdog-wc-enhanced-search-admin',
            'microdogWCES',
            array(
                'ajaxUrl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('microdog_wces_nonce')
            )
        );
    }
    
    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        if (is_search() && is_woocommerce()) {
            wp_enqueue_style(
                'microdog-wc-enhanced-search-frontend',
                MDWES_PLUGIN_URL . 'assets/css/frontend.css',
                array(),
                MDWES_VERSION
            );
        }
    }

    // ========================
    // SETTINGS PAGE
    // ========================

    /**
     * Add settings page to WooCommerce menu
     */
    public function add_settings_page() {
        add_submenu_page(
            'woocommerce',
            esc_html__('Enhanced Search Settings', 'microdog-enhanced-search'),
            esc_html__('Enhanced Search', 'microdog-enhanced-search'),
            'manage_woocommerce',
            'microdog-wc-enhanced-search',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'microdog_wc_enhanced_search_group',
            'microdog_wc_enhanced_search_settings',
            'microdog_wces_sanitize_settings'
        );

        // Register the sanitization callback separately
        add_filter('sanitize_option_microdog_wc_enhanced_search_settings', 'microdog_wces_sanitize_settings');

        add_settings_section(
            'search_fields_section',
            esc_html__('Search Fields', 'microdog-enhanced-search'),
            null,
            'microdog-wc-enhanced-search'
        );

        // Field settings with exact match options
        $search_fields = array(
            'sku' => esc_html__('Search in SKU', 'microdog-enhanced-search'),
            'attributes' => esc_html__('Search in Attributes', 'microdog-enhanced-search'),
            'custom_fields' => esc_html__('Search in Custom Fields', 'microdog-enhanced-search'),
            'categories' => esc_html__('Search in Categories', 'microdog-enhanced-search'),
            'tags' => esc_html__('Search in Tags', 'microdog-enhanced-search'),
            'excerpt' => esc_html__('Search in Excerpt', 'microdog-enhanced-search'),
            'variations' => esc_html__('Search in Variations', 'microdog-enhanced-search')
        );

        foreach ($search_fields as $field => $label) {
            add_settings_field(
                'search_' . $field,
                $label,
                array($this, 'render_field_with_exact_match'),
                'microdog-wc-enhanced-search',
                'search_fields_section',
                array(
                    'field' => $field,
                    'label' => $label,
                )
            );
        }
        
        // Additional settings section
        add_settings_section(
            'additional_settings_section',
            esc_html__('Additional Settings', 'microdog-enhanced-search'),
            null,
            'microdog-wc-enhanced-search'
        );
        
        // Custom fields list
        add_settings_field(
            'custom_fields_list',
            esc_html__('Custom Fields to Include (comma separated, leave blank for all)', 'microdog-enhanced-search'),
            array($this, 'render_text_field'),
            'microdog-wc-enhanced-search',
            'additional_settings_section',
            array('id' => 'custom_fields_list')
        );
        
        // Cache setting
        add_settings_field(
            'enable_caching',
            esc_html__('Enable Search Results Caching', 'microdog-enhanced-search'),
            array($this, 'render_checkbox'),
            'microdog-wc-enhanced-search',
            'additional_settings_section',
            array('id' => 'enable_caching')
        );
        
        // Search weights
        add_settings_field(
            'search_weights',
            esc_html__('Search Priority (1=lowest, 10=highest)', 'microdog-enhanced-search'),
            array($this, 'render_weight_fields'),
            'microdog-wc-enhanced-search',
            'additional_settings_section',
            array(
                'fields' => array(
                    'title_weight' => esc_html__('Title Weight', 'microdog-enhanced-search'),
                    'content_weight' => esc_html__('Content Weight', 'microdog-enhanced-search'),
                    'excerpt_weight' => esc_html__('Excerpt Weight', 'microdog-enhanced-search'),
                    'sku_weight' => esc_html__('SKU Weight', 'microdog-enhanced-search'),
                    'attribute_weight' => esc_html__('Attribute Weight', 'microdog-enhanced-search')
                )
            )
        );
    }

    /**
     * Render field with exact match option
     * 
     * @param array $args Field arguments
     */
    public function render_field_with_exact_match($args) {
        $field = $args['field'];
        $search_id = 'search_' . $field;
        $exact_match_id = 'exact_match_' . $field;
        $search_checked = isset($this->settings[$search_id]) && $this->settings[$search_id] ? 'checked' : '';
        $exact_match_checked = isset($this->settings[$exact_match_id]) && $this->settings[$exact_match_id] ? 'checked' : '';
        
        ?>
        <div class="search-field-with-exact-match">
            <div>
                <input type="checkbox" 
                       id="<?php echo esc_attr($search_id); ?>"
                       name="microdog_wc_enhanced_search_settings[<?php echo esc_attr($search_id); ?>]" 
                       value="1" <?php echo esc_attr($search_checked); ?>>
                <label for="<?php echo esc_attr($search_id); ?>">
                    <?php esc_html_e('Enabled', 'microdog-enhanced-search'); ?>
                </label>
            </div>
            <div class="exact-match-option">
                <input type="checkbox" 
                       id="<?php echo esc_attr($exact_match_id); ?>"
                       name="microdog_wc_enhanced_search_settings[<?php echo esc_attr($exact_match_id); ?>]" 
                       value="1" <?php echo esc_attr($exact_match_checked); ?>>
                <label for="<?php echo esc_attr($exact_match_id); ?>">
                    <?php esc_html_e('Exact Match', 'microdog-enhanced-search'); ?>
                </label>
            </div>
        </div>
        <?php
    }

    /**
     * Render checkbox field
     * 
     * @param array $args Field arguments
     */
    public function render_checkbox($args) {
        $id = esc_attr($args['id']);
        $checked = isset($this->settings[$id]) && $this->settings[$id] ? 'checked' : '';
        ?>
        <input type="checkbox" 
               id="<?php echo esc_attr($id); ?>"
               name="microdog_wc_enhanced_search_settings[<?php echo esc_attr($id); ?>]" 
               value="1" <?php echo esc_attr($checked); ?>>
        <?php
    }
    
    /**
     * Render text field
     * 
     * @param array $args Field arguments
     */
    public function render_text_field($args) {
        $id = esc_attr($args['id']);
        $value = isset($this->settings[$id]) ? esc_attr($this->settings[$id]) : '';
        ?>
        <input type="text" 
               id="<?php echo esc_attr($id); ?>"
               name="microdog_wc_enhanced_search_settings[<?php echo esc_attr($id); ?>]" 
               value="<?php echo esc_attr($value); ?>"
               class="regular-text">
        <?php
    }
    
    /**
     * Render weight fields
     * 
     * @param array $args Field arguments
     */
    public function render_weight_fields($args) {
        $fields = $args['fields'];
        
        echo '<div class="microdog-weight-fields">';
        foreach ($fields as $id => $label) {
            $value = isset($this->settings[$id]) ? intval($this->settings[$id]) : 5;
            ?>
            <div class="weight-field">
                <label for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
                <input type="number" 
                       id="<?php echo esc_attr($id); ?>"
                       name="microdog_wc_enhanced_search_settings[<?php echo esc_attr($id); ?>]" 
                       value="<?php echo esc_attr($value); ?>"
                       min="1" max="10" step="1">
            </div>
            <?php
        }
        echo '</div>';
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Security check
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'microdog-enhanced-search'));
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('Enhanced Search Settings', 'microdog-enhanced-search'); ?></h1>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('microdog_wc_enhanced_search_group');
                do_settings_sections('microdog-wc-enhanced-search');
                submit_button();
                ?>
                <?php wp_nonce_field('microdog_wces_settings_nonce', 'microdog_wces_nonce'); ?>
            </form>
            
            <?php if (!empty($this->settings['enable_caching'])): ?>
            <div class="microdog-wces-cache-section">
                <h2><?php esc_html_e('Cache Management', 'microdog-enhanced-search'); ?></h2>
                <p><?php esc_html_e('Search results caching is currently enabled. You can clear the cache if you need to refresh the search results.', 'microdog-enhanced-search'); ?></p>
                <button type="button" class="button button-secondary" id="microdog-wces-clear-cache">
                    <?php esc_html_e('Clear Search Cache', 'microdog-enhanced-search'); ?>
                </button>
                <span id="microdog-wces-cache-message"></span>
                
                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('#microdog-wces-clear-cache').on('click', function() {
                        var button = $(this);
                        button.prop('disabled', true);
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'microdog_wces_clear_cache',
                                nonce: '<?php echo esc_js(wp_create_nonce('microdog_wces_nonce')); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $('#microdog-wces-cache-message').html('<span style="color:green">' + response.data.message + '</span>');
                                } else {
                                    $('#microdog-wces-cache-message').html('<span style="color:red">' + response.data.message + '</span>');
                                }
                                button.prop('disabled', false);
                            },
                            error: function() {
                                $('#microdog-wces-cache-message').html('<span style="color:red"><?php echo esc_js(__('An error occurred while clearing the cache.', 'microdog-enhanced-search')); ?></span>');
                                button.prop('disabled', false);
                            }
                        });
                    });
                });
                </script>
            </div>
            <?php endif; ?>
            
            <div class="microdog-wces-info-section">
                <h2><?php esc_html_e('Plugin Information', 'microdog-enhanced-search'); ?></h2>
                <p><?php esc_html_e('Enhanced Search for WooCommerce extends the default WooCommerce search functionality with the following features:', 'microdog-enhanced-search'); ?></p>
                <ul class="ul-disc">
                    <li><?php esc_html_e('Search in product SKUs', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Search in product attributes', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Search in product custom fields', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Search in product categories and tags', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Search in product variations', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Per-field exact match options', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Search results caching for improved performance', 'microdog-enhanced-search'); ?></li>
                    <li><?php esc_html_e('Weighted search results for better relevance', 'microdog-enhanced-search'); ?></li>
                </ul>
            </div>
        </div>
        
        <style>
            .search-field-with-exact-match {
                display: flex;
                align-items: center;
            }
            .search-field-with-exact-match > div {
                margin-right: 20px;
            }
            .microdog-weight-fields {
                display: flex;
                flex-wrap: wrap;
                gap: 15px;
            }
            .weight-field {
                display: flex;
                align-items: center;
                margin-bottom: 5px;
            }
            .weight-field label {
                margin-right: 8px;
                min-width: 100px;
            }
            .microdog-wces-cache-section, 
            .microdog-wces-info-section {
                margin-top: 30px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ccd0d4;
                border-radius: 4px;
            }
        </style>
        <?php
    }

    // ========================
    // SEARCH FUNCTIONALITY
    // ========================

    /**
     * Modify search form to include field-specific exact match options
     * 
     * @param string $form Search form HTML
     * @return string Modified search form HTML
     */
    public function modify_search_form($form) {
        if (strpos($form, 'product-search-form') !== false) {
            $exact_match_fields = array();
            
            // Check which fields have exact match enabled in settings
            $search_fields = array('sku', 'attributes', 'custom_fields', 'categories', 'tags', 'excerpt', 'variations');
            
            foreach ($search_fields as $field) {
                if (!empty($this->settings['search_' . $field]) && !empty($this->settings['exact_match_' . $field])) {
                    // translators: %s: Field name (such as SKU, attributes, etc.)
                    $exact_match_fields[$field] = sprintf(__('Exact match for %s', 'microdog-enhanced-search'), $field);
                }
            }
            
            // If any exact match fields are enabled, add them to the form
            if (!empty($exact_match_fields)) {
                $exact_match_html = '<div class="exact-match-options">';
                $exact_match_html .= '<h4>' . esc_html__('Exact Match Options', 'microdog-enhanced-search') . '</h4>';
                
                // Add nonce field for form verification
                $exact_match_html .= wp_nonce_field('woocommerce-search', '_wpnonce', true, false);
                
                foreach ($exact_match_fields as $field => $label) {
                    $checked = isset($_GET['exact_match_' . $field]) && wp_verify_nonce(isset($_GET['_wpnonce']) ? wp_unslash(sanitize_key($_GET['_wpnonce'])) : '', 'woocommerce-search') ? 'checked' : '';
                    $exact_match_html .= sprintf(
                        '<div class="exact-match-option"><label><input type="checkbox" name="exact_match_%1$s" value="1" %2$s> %3$s</label></div>',
                        esc_attr($field),
                        esc_attr($checked),
                        esc_html($label)
                    );
                }
                
                $exact_match_html .= '</div>';
                $form = str_replace('</form>', $exact_match_html . '</form>', $form);
                
                // Add some basic styling for the exact match options
                $form .= '<style>
                    .exact-match-options {
                        margin-top: 10px;
                        padding: 8px;
                        border: 1px solid #ddd;
                        border-radius: 4px;
                        background: #f9f9f9;
                    }
                    .exact-match-options h4 {
                        margin: 0 0 8px;
                        font-size: 14px;
                    }
                    .exact-match-option {
                        margin-bottom: 5px;
                    }
                </style>';
            }
        }
        
        return $form;
    }

    /**
     * Modify search query to include additional fields with per-field exact match handling
     * 
     * @param array $clauses Query clauses
     * @param WP_Query $query Query object
     * @return array Modified query clauses
     */
    public function modify_search_query($clauses, $query) {
        global $wpdb;

        if (!is_admin() && $query->is_search() && $query->is_main_query() && $query->get('post_type') === 'product') {
            $search_term = sanitize_text_field($query->get('s'));
            
            // Verify nonce for form processing if it exists
            $nonce_verified = false;
            if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'woocommerce-search')) {
                $nonce_verified = true;
            }
            
            $joins = array();
            $conditions = array();
            
            // Search in SKU
            if (!empty($this->settings['search_sku'])) {
                $exact_match_sku = !empty($this->settings['exact_match_sku']) && 
                                  (!$nonce_verified || (isset($_GET['exact_match_sku']) && $_GET['exact_match_sku'] === '1'));
                
                $joins['sku'] = "LEFT JOIN {$wpdb->postmeta} AS sku_meta ON {$wpdb->posts}.ID = sku_meta.post_id AND sku_meta.meta_key = '_sku'";
                
                if ($exact_match_sku) {
                    $conditions[] = $wpdb->prepare("sku_meta.meta_value = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("sku_meta.meta_value LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
            }

            // Search in Custom Fields
            if (!empty($this->settings['search_custom_fields'])) {
                $exact_match_cf = !empty($this->settings['exact_match_custom_fields']) && 
                                 (!$nonce_verified || (isset($_GET['exact_match_custom_fields']) && $_GET['exact_match_custom_fields'] === '1'));
                
                $joins['cf'] = "LEFT JOIN {$wpdb->postmeta} AS cf_meta ON {$wpdb->posts}.ID = cf_meta.post_id";
                
                $custom_fields = !empty($this->settings['custom_fields_list']) 
                    ? array_map('trim', explode(',', $this->settings['custom_fields_list'])) 
                    : array();
                
                if (!empty($custom_fields)) {
                    // Build meta key conditions
                    $meta_key_parts = array();
                    foreach ($custom_fields as $field) {
                        $meta_key_parts[] = $wpdb->prepare("cf_meta.meta_key = %s", $field);
                    }
                    $meta_keys_query = '(' . implode(' OR ', $meta_key_parts) . ')';
                    
                    if ($exact_match_cf) {
                        $conditions[] = $meta_keys_query . ' AND ' . $wpdb->prepare("cf_meta.meta_value = %s", $search_term);
                    } else {
                        $conditions[] = $meta_keys_query . ' AND ' . $wpdb->prepare("cf_meta.meta_value LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                    }
                } else {
                    // If no specific custom fields, search in all non-internal meta
                    $internal_meta_keys = array('_sku', '_price', '_sale_price', '_regular_price', '_stock', '_stock_status', '_tax_class', '_weight', '_length', '_width', '_height', '_visibility', '_virtual', '_downloadable');
                    
                    // Build excluded meta key conditions
                    $exclude_key_parts = array();
                    foreach ($internal_meta_keys as $key) {
                        $exclude_key_parts[] = $wpdb->prepare("cf_meta.meta_key != %s", $key);
                    }
                    $exclude_keys_query = '(' . implode(' AND ', $exclude_key_parts) . ')';
                    
                    if ($exact_match_cf) {
                        $conditions[] = $exclude_keys_query . ' AND ' . $wpdb->prepare("cf_meta.meta_key NOT LIKE %s", '\_%') . ' AND ' . $wpdb->prepare("cf_meta.meta_value = %s", $search_term);
                    } else {
                        $conditions[] = $exclude_keys_query . ' AND ' . $wpdb->prepare("cf_meta.meta_key NOT LIKE %s", '\_%') . ' AND ' . $wpdb->prepare("cf_meta.meta_value LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                    }
                }
            }

            // Search in Attributes
            if (!empty($this->settings['search_attributes'])) {
                $exact_match_attr = !empty($this->settings['exact_match_attributes']) && 
                                   (!$nonce_verified || (isset($_GET['exact_match_attributes']) && $_GET['exact_match_attributes'] === '1'));
                
                $joins['attr_rel'] = "LEFT JOIN {$wpdb->term_relationships} AS attr_rel ON {$wpdb->posts}.ID = attr_rel.object_id";
                $joins['attr_tax'] = "LEFT JOIN {$wpdb->term_taxonomy} AS attr_tax ON attr_rel.term_taxonomy_id = attr_tax.term_taxonomy_id";
                $joins['attr_terms'] = "LEFT JOIN {$wpdb->terms} AS attr_terms ON attr_tax.term_id = attr_terms.term_id";
                
                if ($exact_match_attr) {
                    $conditions[] = $wpdb->prepare("attr_terms.name = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("attr_terms.name LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
                
                // Also search in attribute values in meta
                $joins['attr_meta'] = "LEFT JOIN {$wpdb->postmeta} AS attr_meta ON {$wpdb->posts}.ID = attr_meta.post_id";
                $attr_meta_condition = $wpdb->prepare("attr_meta.meta_key LIKE %s", 'attribute\_%');
                $joins['attr_meta'] .= " AND " . $attr_meta_condition;
                
                if ($exact_match_attr) {
                    $conditions[] = $wpdb->prepare("attr_meta.meta_value = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("attr_meta.meta_value LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
            }

            // Search in Categories
            if (!empty($this->settings['search_categories'])) {
                $exact_match_cat = !empty($this->settings['exact_match_categories']) && 
                                  (!$nonce_verified || (isset($_GET['exact_match_categories']) && $_GET['exact_match_categories'] === '1'));
                
                $joins['cat_rel'] = "LEFT JOIN {$wpdb->term_relationships} AS cat_rel ON {$wpdb->posts}.ID = cat_rel.object_id";
                $joins['cat_tax'] = "LEFT JOIN {$wpdb->term_taxonomy} AS cat_tax ON cat_rel.term_taxonomy_id = cat_tax.term_taxonomy_id AND cat_tax.taxonomy = 'product_cat'";
                $joins['cat_terms'] = "LEFT JOIN {$wpdb->terms} AS cat_terms ON cat_tax.term_id = cat_terms.term_id";
                
                if ($exact_match_cat) {
                    $conditions[] = $wpdb->prepare("cat_terms.name = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("cat_terms.name LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
            }

            // Search in Tags
            if (!empty($this->settings['search_tags'])) {
                $exact_match_tag = !empty($this->settings['exact_match_tags']) && 
                                  (!$nonce_verified || (isset($_GET['exact_match_tags']) && $_GET['exact_match_tags'] === '1'));
                
                $joins['tag_rel'] = "LEFT JOIN {$wpdb->term_relationships} AS tag_rel ON {$wpdb->posts}.ID = tag_rel.object_id";
                $joins['tag_tax'] = "LEFT JOIN {$wpdb->term_taxonomy} AS tag_tax ON tag_rel.term_taxonomy_id = tag_tax.term_taxonomy_id AND tag_tax.taxonomy = 'product_tag'";
                $joins['tag_terms'] = "LEFT JOIN {$wpdb->terms} AS tag_terms ON tag_tax.term_id = tag_terms.term_id";
                
                if ($exact_match_tag) {
                    $conditions[] = $wpdb->prepare("tag_terms.name = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("tag_terms.name LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
            }

            // Search in Excerpt
            if (!empty($this->settings['search_excerpt'])) {
                $exact_match_excerpt = !empty($this->settings['exact_match_excerpt']) && 
                                      (!$nonce_verified || (isset($_GET['exact_match_excerpt']) && $_GET['exact_match_excerpt'] === '1'));
                
                if ($exact_match_excerpt) {
                    $conditions[] = $wpdb->prepare("{$wpdb->posts}.post_excerpt = %s", $search_term);
                } else {
                    $conditions[] = $wpdb->prepare("{$wpdb->posts}.post_excerpt LIKE %s", '%' . $wpdb->esc_like($search_term) . '%');
                }
            }

            // Apply joins and conditions to the query clauses
            if (!empty($joins)) {
                $clauses['join'] .= ' ' . implode(' ', $joins);
            }

            if (!empty($conditions)) {
                // Apply search weights if configured
                $weighted_conditions = array();
                
                // Get title search condition
                $title_search = preg_match("/\({$wpdb->posts}.post_title LIKE .*?\)/", $clauses['where'], $title_matches);
                
                if ($title_search && !empty($title_matches[0])) {
                    // Remove original title search
                    $clauses['where'] = str_replace($title_matches[0], "1=1", $clauses['where']);
                    
                    // Add weighted title search
                    $title_weight = isset($this->settings['title_weight']) ? intval($this->settings['title_weight']) : 10;
                    $weighted_conditions[] = "({$title_matches[0]} * {$title_weight})";
                }
                
                // Add other weighted conditions
                $weights = array(
                    'content_weight' => 5,     // Default content weight
                    'excerpt_weight' => 3,     // Default excerpt weight
                    'sku_weight' => 8,         // Default SKU weight
                    'attribute_weight' => 6    // Default attribute weight
                );
                
                // Override defaults with settings if available
                foreach ($weights as $weight_key => $default_value) {
                    $weights[$weight_key] = isset($this->settings[$weight_key]) ? intval($this->settings[$weight_key]) : $default_value;
                }
                
                // Apply weights to all other conditions
                foreach ($conditions as $condition) {
                    $weight = 1; // Default weight
                    
                    // Determine weight based on the condition type
                    if (strpos($condition, 'post_excerpt') !== false) {
                        $weight = $weights['excerpt_weight'];
                    } elseif (strpos($condition, 'sku_meta') !== false) {
                        $weight = $weights['sku_weight'];
                    } elseif (strpos($condition, 'attr_terms') !== false || strpos($condition, 'attr_meta') !== false) {
                        $weight = $weights['attribute_weight'];
                    }
                    
                    $weighted_conditions[] = "({$condition} * {$weight})";
                }
                
                // Modify the where clause to use weighted conditions if available
                if (!empty($weighted_conditions)) {
                    $weighted_conditions_str = implode(' OR ', $weighted_conditions);
                    $clauses['where'] = preg_replace(
                        "/\(\s*{$wpdb->posts}.post_content LIKE .*?\s*\)/",
                        "( $weighted_conditions_str )",
                        $clauses['where']
                    );
                } else {
                    // Fall back to simple OR conditions if no weights
                    $conditions_str = implode(' OR ', $conditions);
                    $clauses['where'] = preg_replace(
                        "/\(\s*{$wpdb->posts}.post_title LIKE .+?\s*OR\s*{$wpdb->posts}.post_content LIKE .+?\s*\)/",
                        "( $0 OR $conditions_str )",
                        $clauses['where']
                    );
                }
            }

            // Enable result caching if configured
            if (!empty($this->settings['enable_caching'])) {
                $cache_key = 'mdwes_' . md5(serialize($query->query_vars));
                
                // Store this key for later cleanup
                $transient_keys = get_option('_transient_keys_mdwes_', array());
                if (!in_array($cache_key, $transient_keys, true)) {
                    $transient_keys[] = $cache_key;
                    update_option('_transient_keys_mdwes_', $transient_keys);
                }
                
                $cached_results = get_transient($cache_key);
                
                if ($cached_results !== false) {
                    // Use cached results
                    $query->found_posts = $cached_results['found_posts'];
                    $query->max_num_pages = $cached_results['max_num_pages'];
                    
                    if (!empty($cached_results['post_ids'])) {
                        $where_parts = array();
                        foreach ($cached_results['post_ids'] as $post_id) {
                            $where_parts[] = $wpdb->prepare("{$wpdb->posts}.ID = %d", $post_id);
                        }
                        $clauses['where'] = " AND (" . implode(' OR ', $where_parts) . ")";
                    }
                } else {
                    // Set up a hook to cache results after the query runs
                    add_filter('the_posts', function($posts, $q) use ($cache_key, $query) {
                        if ($q->is_main_query() && $q->is_search() && $q === $query) {
                            $post_ids = wp_list_pluck($posts, 'ID');
                            set_transient($cache_key, array(
                                'found_posts' => $q->found_posts,
                                'max_num_pages' => $q->max_num_pages,
                                'post_ids' => $post_ids
                            ), HOUR_IN_SECONDS); // Cache for 1 hour
                        }
                        return $posts;
                    }, 10, 2);
                }
            }
            
            // Group results by post ID to avoid duplicates
            $clauses['groupby'] = "{$wpdb->posts}.ID";
        }

        return $clauses;
    }
    
    /**
     * Handle variation search by including parent products in search results
     * 
     * @param WP_Query $query WP Query object
     */
    public function handle_variation_search($query) {
        if (!is_admin() && $query->is_search() && $query->is_main_query() && $query->get('post_type') === 'product') {
            if (!empty($this->settings['search_variations'])) {
                $search_term = sanitize_text_field($query->get('s'));
                
                // Verify nonce for form processing if it exists
                $nonce_verified = false;
                if (isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_key(wp_unslash($_GET['_wpnonce'])), 'woocommerce-search')) {
                    $nonce_verified = true;
                }
                
                // Check if exact match is enabled for variations
                $exact_match_variations = !empty($this->settings['exact_match_variations']) && 
                                         (!$nonce_verified || (isset($_GET['exact_match_variations']) && $_GET['exact_match_variations'] === '1'));
                
                $meta_query = array(
                    'relation' => 'OR'
                );
                
                if ($exact_match_variations) {
                    $meta_query[] = array(
                        'key' => '_sku',
                        'value' => $search_term,
                        'compare' => '='
                    );
                } else {
                    $meta_query[] = array(
                        'key' => '_sku',
                        'value' => $search_term,
                        'compare' => 'LIKE'
                    );
                }
                
                // Add attribute search for variations
                $attributes = wc_get_attribute_taxonomies();
                if (!empty($attributes)) {
                    foreach ($attributes as $attribute) {
                        if ($exact_match_variations) {
                            $meta_query[] = array(
                                'key' => 'attribute_pa_' . sanitize_title($attribute->attribute_name),
                                'value' => $search_term,
                                'compare' => '='
                            );
                        } else {
                            $meta_query[] = array(
                                'key' => 'attribute_pa_' . sanitize_title($attribute->attribute_name),
                                'value' => $search_term,
                                'compare' => 'LIKE'
                            );
                        }
                    }
                }
                
                // Search in variation custom meta data
                $custom_fields = !empty($this->settings['custom_fields_list']) 
                    ? array_map('trim', explode(',', $this->settings['custom_fields_list'])) 
                    : array();
                
                if (!empty($custom_fields)) {
                    foreach ($custom_fields as $field) {
                        if ($exact_match_variations) {
                            $meta_query[] = array(
                                'key' => $field,
                                'value' => $search_term,
                                'compare' => '='
                            );
                        } else {
                            $meta_query[] = array(
                                'key' => $field,
                                'value' => $search_term,
                                'compare' => 'LIKE'
                            );
                        }
                    }
                } else {
                    // If no specific custom fields, search in all non-internal meta
                    if ($exact_match_variations) {
                        $meta_query[] = array(
                            'key_compare' => 'NOT LIKE',
                            'key' => '_',
                            'value' => $search_term,
                            'compare' => '='
                        );
                    } else {
                        $meta_query[] = array(
                            'key_compare' => 'NOT LIKE',
                            'key' => '_',
                            'value' => $search_term,
                            'compare' => 'LIKE'
                        );
                    }
                }

                $variation_args = array(
                    'post_type' => 'product_variation',
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                    'meta_query' => $meta_query
                );
                
                // Get variations matching our search criteria
                $variation_ids = get_posts($variation_args);

                if (!empty($variation_ids)) {
                    // Get parent product IDs for matching variations
                    $parent_ids = array();
                    foreach ($variation_ids as $variation_id) {
                        $parent_id = wp_get_post_parent_id($variation_id);
                        if ($parent_id) {
                            $parent_ids[] = $parent_id;
                        }
                    }
                    
                    if (!empty($parent_ids)) {
                        // Merge with existing post__in if already set
                        $post__in = $query->get('post__in');
                        $post__in = $post__in ? array_merge($post__in, $parent_ids) : $parent_ids;
                        $query->set('post__in', array_unique($post__in));
                    }
                }
            }
        }
    }
}

/**
 * Sanitize plugin settings
 * 
 * @param array $input Raw input data
 * @return array Sanitized data
 */
function microdog_wces_sanitize_settings($input) {
    $clean = array();
    
    // Basic checkboxes for search fields
    $search_fields = array('sku', 'attributes', 'custom_fields', 'categories', 'tags', 'excerpt', 'variations');
    
    foreach ($search_fields as $field) {
        $clean['search_' . $field] = isset($input['search_' . $field]) ? 1 : 0;
        $clean['exact_match_' . $field] = isset($input['exact_match_' . $field]) ? 1 : 0;
    }
    
    // Enable caching
    $clean['enable_caching'] = isset($input['enable_caching']) ? 1 : 0;
    
    // Text fields
    if (isset($input['custom_fields_list'])) {
        $clean['custom_fields_list'] = sanitize_text_field($input['custom_fields_list']);
    }
    
    // Weight fields
    $weight_fields = array(
        'title_weight', 'content_weight', 'excerpt_weight', 
        'sku_weight', 'attribute_weight'
    );
    
    foreach ($weight_fields as $field) {
        if (isset($input[$field])) {
            $value = intval($input[$field]);
            $clean[$field] = min(max($value, 1), 10); // Ensure value is between 1-10
        }
    }
    
    return $clean;
}

// Initialize the plugin
$microdog_wces_instance = Microdog_WC_Enhanced_Search::get_instance();

/**
 * Get plugin instance accessor
 *
 * @return Microdog_WC_Enhanced_Search
 */
function microdog_wces() {
    return Microdog_WC_Enhanced_Search::get_instance();
}