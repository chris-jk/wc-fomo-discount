<?php
/**
 * Main Plugin Class (Refactored)
 * 
 * @package WC_Fomo_Discount
 * @since 2.0.0
 */

namespace WCFD;

use WCFD\Core\Logger;
use WCFD\Core\Validator;
use WCFD\Core\Campaign_Manager;
use WCFD\Database\Database_Manager;
use WCFD\Admin\Admin_Handler;
use WCFD\Frontend\Frontend_Handler;
use WCFD\Utils\Rate_Limiter;
use WCFD\Utils\Email_Handler;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class WC_Fomo_Discount {
    
    /**
     * @var WC_Fomo_Discount Single instance
     */
    private static $instance = null;
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var Validator
     */
    private $validator;
    
    /**
     * @var Database_Manager
     */
    private $db_manager;
    
    /**
     * @var Campaign_Manager
     */
    private $campaign_manager;
    
    /**
     * @var Admin_Handler
     */
    private $admin_handler;
    
    /**
     * @var Frontend_Handler
     */
    private $frontend_handler;
    
    /**
     * @var string Plugin version
     */
    const VERSION = '2.0.0';
    
    /**
     * Get singleton instance
     * 
     * @return WC_Fomo_Discount
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_components();
        $this->init_hooks();
    }
    
    /**
     * Define plugin constants
     */
    private function define_constants() {
        if (!defined('WCFD_VERSION')) {
            define('WCFD_VERSION', self::VERSION);
        }
        if (!defined('WCFD_PLUGIN_DIR')) {
            define('WCFD_PLUGIN_DIR', plugin_dir_path(dirname(__FILE__)));
        }
        if (!defined('WCFD_PLUGIN_URL')) {
            define('WCFD_PLUGIN_URL', plugin_dir_url(dirname(__FILE__)));
        }
    }
    
    /**
     * Include required files
     */
    private function includes() {
        // Autoloader
        require_once WCFD_PLUGIN_DIR . 'includes/autoloader.php';
        
        // Core
        require_once WCFD_PLUGIN_DIR . 'includes/core/class-logger.php';
        require_once WCFD_PLUGIN_DIR . 'includes/core/class-validator.php';
        require_once WCFD_PLUGIN_DIR . 'includes/core/class-campaign-manager.php';
        
        // Database
        require_once WCFD_PLUGIN_DIR . 'includes/database/class-database-manager.php';
        
        // Admin
        if (is_admin()) {
            require_once WCFD_PLUGIN_DIR . 'includes/admin/class-admin-handler.php';
        }
        
        // Frontend
        if (!is_admin()) {
            require_once WCFD_PLUGIN_DIR . 'includes/frontend/class-frontend-handler.php';
        }
        
        // Utils
        require_once WCFD_PLUGIN_DIR . 'includes/utils/class-rate-limiter.php';
        require_once WCFD_PLUGIN_DIR . 'includes/utils/class-email-handler.php';
    }
    
    /**
     * Initialize components
     */
    private function init_components() {
        // Core components
        $this->logger = new Logger();
        $this->validator = new Validator($this->logger);
        $this->db_manager = new Database_Manager($this->logger);
        $this->campaign_manager = new Campaign_Manager($this->logger, $this->db_manager, $this->validator);
        
        // Initialize database
        $this->db_manager->init();
        
        // Admin components
        if (is_admin()) {
            $this->admin_handler = new Admin_Handler($this->logger, $this->campaign_manager, $this->db_manager);
        }
        
        // Frontend components
        if (!is_admin()) {
            $this->frontend_handler = new Frontend_Handler($this->logger, $this->campaign_manager, $this->validator);
        }
        
        $this->logger->info('Plugin components initialized', ['version' => self::VERSION]);
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation
        register_activation_hook(dirname(dirname(__FILE__)) . '/wc-fomo-discount.php', [$this, 'activate']);
        register_deactivation_hook(dirname(dirname(__FILE__)) . '/wc-fomo-discount.php', [$this, 'deactivate']);
        
        // Core hooks
        add_action('init', [$this, 'init']);
        add_action('plugins_loaded', [$this, 'plugins_loaded']);
        
        // Cron jobs
        add_action('wcfd_cleanup_expired', [$this, 'cleanup_expired_codes']);
        add_action('wcfd_optimize_database', [$this, 'optimize_database']);
        add_action('wcfd_cleanup_logs', [$this, 'cleanup_logs']);
        
        // REST API
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->logger->info('Plugin activated');
        
        // Initialize database
        $this->db_manager->init();
        
        // Schedule cron jobs
        if (!wp_next_scheduled('wcfd_cleanup_expired')) {
            wp_schedule_event(time(), 'hourly', 'wcfd_cleanup_expired');
        }
        
        if (!wp_next_scheduled('wcfd_optimize_database')) {
            wp_schedule_event(time(), 'weekly', 'wcfd_optimize_database');
        }
        
        if (!wp_next_scheduled('wcfd_cleanup_logs')) {
            wp_schedule_event(time(), 'daily', 'wcfd_cleanup_logs');
        }
        
        // Flush rewrite rules
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        $this->logger->info('Plugin deactivated');
        
        // Clear cron jobs
        wp_clear_scheduled_hook('wcfd_cleanup_expired');
        wp_clear_scheduled_hook('wcfd_optimize_database');
        wp_clear_scheduled_hook('wcfd_cleanup_logs');
        
        // Clear cache
        wp_cache_flush();
    }
    
    /**
     * Init hook
     */
    public function init() {
        // Load textdomain
        load_plugin_textdomain('wc-fomo-discount', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Register shortcodes
        add_shortcode('fomo_discount', [$this, 'render_shortcode']);
    }
    
    /**
     * Plugins loaded hook
     */
    public function plugins_loaded() {
        // Check WooCommerce dependency
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo __('WooCommerce FOMO Discount requires WooCommerce to be installed and activated.', 'wc-fomo-discount');
                echo '</p></div>';
            });
            return;
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                echo __('WooCommerce FOMO Discount requires PHP 7.4 or higher.', 'wc-fomo-discount');
                echo '</p></div>';
            });
            return;
        }
    }
    
    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wcfd/v1', '/campaigns', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_campaigns'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('wcfd/v1', '/claim', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_claim_discount'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('wcfd/v1', '/stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_stats'],
            'permission_callback' => function() {
                return current_user_can('manage_woocommerce');
            }
        ]);
    }
    
    /**
     * REST API: Get campaigns
     */
    public function rest_get_campaigns(\WP_REST_Request $request) {
        $campaigns = $this->campaign_manager->get_active_campaigns([
            'limit' => $request->get_param('limit') ?? 10
        ]);
        
        return rest_ensure_response($campaigns);
    }
    
    /**
     * REST API: Claim discount
     */
    public function rest_claim_discount(\WP_REST_Request $request) {
        $campaign_id = $request->get_param('campaign_id');
        $email = $request->get_param('email');
        
        if (!$this->validator->validate_email($email)) {
            return new \WP_Error('invalid_email', __('Invalid email address', 'wc-fomo-discount'), ['status' => 400]);
        }
        
        $ip_address = $this->validator->validate_ip_address($_SERVER['REMOTE_ADDR']);
        
        $result = $this->campaign_manager->claim_discount($campaign_id, $email, $ip_address);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return rest_ensure_response($result);
    }
    
    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'campaign_id' => 0,
            'style' => 'default'
        ], $atts);
        
        if (empty($atts['campaign_id'])) {
            return '';
        }
        
        return $this->frontend_handler->render_widget($atts['campaign_id'], $atts['style']);
    }
    
    /**
     * Cleanup expired codes
     */
    public function cleanup_expired_codes() {
        global $wpdb;
        
        $this->logger->start_timer('cleanup_expired');
        
        // Delete expired verifications
        $deleted_verifications = $wpdb->query(
            "DELETE FROM {$this->db_manager->get_table('email_verifications')} 
            WHERE expires_at < NOW() AND verified_at IS NULL"
        );
        
        // Delete expired WooCommerce coupons
        $expired_coupons = $wpdb->get_results(
            "SELECT coupon_code FROM {$this->db_manager->get_table('claimed_codes')} 
            WHERE expires_at < NOW()"
        );
        
        foreach ($expired_coupons as $coupon) {
            $coupon_id = wc_get_coupon_id_by_code($coupon->coupon_code);
            if ($coupon_id) {
                wp_delete_post($coupon_id, true);
            }
        }
        
        $this->logger->end_timer('cleanup_expired');
        $this->logger->info('Cleanup completed', [
            'deleted_verifications' => $deleted_verifications,
            'deleted_coupons' => count($expired_coupons)
        ]);
    }
    
    /**
     * Optimize database
     */
    public function optimize_database() {
        $this->db_manager->optimize_tables();
    }
    
    /**
     * Cleanup old logs
     */
    public function cleanup_logs() {
        $this->logger->cleanup_old_logs(30);
    }
    
    /**
     * Get component
     * 
     * @param string $component
     * @return object|null
     */
    public function get_component($component) {
        switch ($component) {
            case 'logger':
                return $this->logger;
            case 'validator':
                return $this->validator;
            case 'campaign_manager':
                return $this->campaign_manager;
            case 'db_manager':
                return $this->db_manager;
            default:
                return null;
        }
    }
}