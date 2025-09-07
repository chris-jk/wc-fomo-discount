<?php
/**
 * Admin Interface Handler
 * 
 * @package WC_Fomo_Discount
 * @subpackage Admin
 * @since 2.0.0
 */

namespace WCFD\Admin;

use WCFD\Core\Logger;
use WCFD\Core\Campaign_Manager;
use WCFD\Database\Database_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin Handler class for managing admin interface
 */
class Admin_Handler {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var Campaign_Manager
     */
    private $campaign_manager;
    
    /**
     * @var Database_Manager
     */
    private $db_manager;
    
    /**
     * Constructor
     */
    public function __construct(Logger $logger, Campaign_Manager $campaign_manager, Database_Manager $db_manager) {
        $this->logger = $logger;
        $this->campaign_manager = $campaign_manager;
        $this->db_manager = $db_manager;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        add_action('admin_notices', [$this, 'display_admin_notices']);
        
        // AJAX handlers
        add_action('wp_ajax_wcfd_get_campaign_stats', [$this, 'ajax_get_campaign_stats']);
        add_action('wp_ajax_wcfd_export_data', [$this, 'ajax_export_data']);
    }
    
    /**
     * Add admin menu items
     */
    public function add_admin_menu() {
        // Main menu
        add_menu_page(
            __('FOMO Discounts', 'wc-fomo-discount'),
            __('FOMO Discounts', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-dashboard',
            [$this, 'render_dashboard_page'],
            'dashicons-megaphone',
            56
        );
        
        // Dashboard
        add_submenu_page(
            'wcfd-dashboard',
            __('Dashboard', 'wc-fomo-discount'),
            __('Dashboard', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-dashboard',
            [$this, 'render_dashboard_page']
        );
        
        // Campaigns
        add_submenu_page(
            'wcfd-dashboard',
            __('Campaigns', 'wc-fomo-discount'),
            __('Campaigns', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-campaigns',
            [$this, 'render_campaigns_page']
        );
        
        // Analytics
        add_submenu_page(
            'wcfd-dashboard',
            __('Analytics', 'wc-fomo-discount'),
            __('Analytics', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-analytics',
            [$this, 'render_analytics_page']
        );
        
        // Settings
        add_submenu_page(
            'wcfd-dashboard',
            __('Settings', 'wc-fomo-discount'),
            __('Settings', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-settings',
            [$this, 'render_settings_page']
        );
        
        // System Status
        add_submenu_page(
            'wcfd-dashboard',
            __('System Status', 'wc-fomo-discount'),
            __('System Status', 'wc-fomo-discount'),
            'manage_woocommerce',
            'wcfd-system-status',
            [$this, 'render_system_status_page']
        );
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'wcfd') === false) {
            return;
        }
        
        // Styles
        wp_enqueue_style(
            'wcfd-admin',
            plugins_url('assets/admin/css/admin.css', dirname(dirname(__FILE__))),
            [],
            '2.0.0'
        );
        
        // Scripts
        wp_enqueue_script(
            'wcfd-admin',
            plugins_url('assets/admin/js/admin.js', dirname(dirname(__FILE__))),
            ['jquery', 'wp-api', 'chart-js'],
            '2.0.0',
            true
        );
        
        // Chart.js for analytics
        if ($hook === 'fomo-discounts_page_wcfd-analytics') {
            wp_enqueue_script(
                'chart-js',
                'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',
                [],
                '3.9.1'
            );
        }
        
        // Localize script
        wp_localize_script('wcfd-admin', 'wcfd_admin', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcfd_admin_nonce'),
            'strings' => [
                'confirm_delete' => __('Are you sure you want to delete this campaign?', 'wc-fomo-discount'),
                'confirm_cleanup' => __('This will delete all test data. Are you sure?', 'wc-fomo-discount'),
                'exporting' => __('Exporting...', 'wc-fomo-discount'),
                'export_complete' => __('Export complete!', 'wc-fomo-discount')
            ]
        ]);
    }
    
    /**
     * Handle admin actions
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        
        // Handle campaign creation
        if (isset($_POST['wcfd_create_campaign'])) {
            $this->handle_create_campaign();
        }
        
        // Handle campaign update
        if (isset($_POST['wcfd_update_campaign'])) {
            $this->handle_update_campaign();
        }
        
        // Handle campaign deletion
        if (isset($_GET['wcfd_delete_campaign'])) {
            $this->handle_delete_campaign();
        }
        
        // Handle data export
        if (isset($_GET['wcfd_export'])) {
            $this->handle_data_export();
        }
        
        // Handle cleanup
        if (isset($_POST['wcfd_cleanup_test_data'])) {
            $this->handle_cleanup_test_data();
        }
    }
    
    /**
     * Render dashboard page
     */
    public function render_dashboard_page() {
        $stats = $this->get_dashboard_stats();
        include dirname(dirname(__FILE__)) . '/admin/views/dashboard.php';
    }
    
    /**
     * Render campaigns page
     */
    public function render_campaigns_page() {
        if (isset($_GET['action']) && $_GET['action'] === 'edit' && isset($_GET['campaign_id'])) {
            $campaign = $this->campaign_manager->get_campaign(intval($_GET['campaign_id']));
            include dirname(dirname(__FILE__)) . '/admin/views/campaign-edit.php';
        } elseif (isset($_GET['action']) && $_GET['action'] === 'new') {
            include dirname(dirname(__FILE__)) . '/admin/views/campaign-new.php';
        } else {
            $campaigns = $this->get_all_campaigns();
            include dirname(dirname(__FILE__)) . '/admin/views/campaigns-list.php';
        }
    }
    
    /**
     * Render analytics page
     */
    public function render_analytics_page() {
        $analytics = $this->get_analytics_data();
        include dirname(dirname(__FILE__)) . '/admin/views/analytics.php';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $settings = $this->get_settings();
        include dirname(dirname(__FILE__)) . '/admin/views/settings.php';
    }
    
    /**
     * Render system status page
     */
    public function render_system_status_page() {
        $status = $this->get_system_status();
        include dirname(dirname(__FILE__)) . '/admin/views/system-status.php';
    }
    
    /**
     * Get dashboard statistics
     */
    private function get_dashboard_stats() {
        global $wpdb;
        
        $stats = wp_cache_get('wcfd_dashboard_stats', 'wcfd');
        
        if ($stats === false) {
            $stats = [
                'active_campaigns' => $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$this->db_manager->get_table('campaigns')} WHERE status = 'active'"
                ),
                'total_codes_claimed' => $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} WHERE email_verified = 1"
                ),
                'codes_claimed_today' => $wpdb->get_var(
                    "SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} 
                    WHERE DATE(claimed_at) = CURDATE() AND email_verified = 1"
                ),
                'conversion_rate' => $this->calculate_conversion_rate(),
                'recent_claims' => $this->get_recent_claims(10),
                'top_campaigns' => $this->get_top_campaigns(5)
            ];
            
            wp_cache_set('wcfd_dashboard_stats', $stats, 'wcfd', 300);
        }
        
        return $stats;
    }
    
    /**
     * Get all campaigns
     */
    private function get_all_campaigns() {
        global $wpdb;
        
        $page = isset($_GET['paged']) ? intval($_GET['paged']) : 1;
        $per_page = 20;
        $offset = ($page - 1) * $per_page;
        
        $campaigns = $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, 
                (SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} 
                WHERE campaign_id = c.id AND email_verified = 1) as claimed_count
            FROM {$this->db_manager->get_table('campaigns')} c
            ORDER BY c.created_at DESC
            LIMIT %d OFFSET %d",
            $per_page,
            $offset
        ));
        
        return $campaigns;
    }
    
    /**
     * Get analytics data
     */
    private function get_analytics_data() {
        global $wpdb;
        
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '7days';
        
        $date_query = $this->get_date_query($period);
        
        return [
            'claims_over_time' => $this->get_claims_over_time($date_query),
            'conversion_funnel' => $this->get_conversion_funnel($date_query),
            'campaign_performance' => $this->get_campaign_performance($date_query),
            'user_behavior' => $this->get_user_behavior($date_query)
        ];
    }
    
    /**
     * Get system status
     */
    private function get_system_status() {
        return [
            'environment' => [
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
                'wc_version' => defined('WC_VERSION') ? WC_VERSION : 'Not installed',
                'plugin_version' => '2.0.0',
                'debug_mode' => WP_DEBUG ? 'Enabled' : 'Disabled',
                'memory_limit' => ini_get('memory_limit')
            ],
            'database' => [
                'tables_exist' => $this->db_manager->tables_exist(),
                'version' => get_option('wcfd_db_version'),
                'statistics' => $this->db_manager->get_statistics()
            ],
            'recent_errors' => $this->logger->get_recent_logs(20, 'error'),
            'performance' => $this->get_performance_metrics()
        ];
    }
    
    /**
     * Handle create campaign
     */
    private function handle_create_campaign() {
        if (!wp_verify_nonce($_POST['wcfd_nonce'], 'wcfd_create_campaign')) {
            wp_die(__('Security check failed', 'wc-fomo-discount'));
        }
        
        $campaign_data = [
            'campaign_name' => sanitize_text_field($_POST['campaign_name']),
            'discount_type' => sanitize_text_field($_POST['discount_type']),
            'discount_value' => floatval($_POST['discount_value']),
            'total_codes' => intval($_POST['total_codes']),
            'expiry_hours' => intval($_POST['expiry_hours']),
            'enable_ip_limit' => isset($_POST['enable_ip_limit']),
            'max_per_ip' => intval($_POST['max_per_ip'] ?? 1),
            'scope_type' => sanitize_text_field($_POST['scope_type'] ?? 'all')
        ];
        
        // Handle scope IDs
        if (!empty($_POST['scope_ids'])) {
            $campaign_data['scope_ids'] = array_map('intval', $_POST['scope_ids']);
        }
        
        // Handle tiered discounts
        if (!empty($_POST['tiered_discounts'])) {
            $campaign_data['tiered_discounts'] = $this->parse_tiered_discounts($_POST['tiered_discounts']);
        }
        
        $result = $this->campaign_manager->create_campaign($campaign_data);
        
        if (is_wp_error($result)) {
            $this->add_admin_notice('error', $result->get_error_message());
        } else {
            $this->add_admin_notice('success', __('Campaign created successfully!', 'wc-fomo-discount'));
            wp_redirect(admin_url('admin.php?page=wcfd-campaigns'));
            exit;
        }
    }
    
    /**
     * Handle update campaign
     */
    private function handle_update_campaign() {
        if (!wp_verify_nonce($_POST['wcfd_nonce'], 'wcfd_update_campaign')) {
            wp_die(__('Security check failed', 'wc-fomo-discount'));
        }
        
        $campaign_id = intval($_POST['campaign_id']);
        
        $campaign_data = [
            'campaign_name' => sanitize_text_field($_POST['campaign_name']),
            'discount_type' => sanitize_text_field($_POST['discount_type']),
            'discount_value' => floatval($_POST['discount_value']),
            'total_codes' => intval($_POST['total_codes']),
            'expiry_hours' => intval($_POST['expiry_hours']),
            'enable_ip_limit' => isset($_POST['enable_ip_limit']),
            'max_per_ip' => intval($_POST['max_per_ip'] ?? 1),
            'scope_type' => sanitize_text_field($_POST['scope_type'] ?? 'all'),
            'status' => sanitize_text_field($_POST['status'])
        ];
        
        // Handle scope IDs
        if (!empty($_POST['scope_ids'])) {
            $campaign_data['scope_ids'] = array_map('intval', $_POST['scope_ids']);
        }
        
        // Handle tiered discounts
        if (!empty($_POST['tiered_discounts'])) {
            $campaign_data['tiered_discounts'] = $this->parse_tiered_discounts($_POST['tiered_discounts']);
        }
        
        $result = $this->campaign_manager->update_campaign($campaign_id, $campaign_data);
        
        if (is_wp_error($result)) {
            $this->add_admin_notice('error', $result->get_error_message());
        } else {
            $this->add_admin_notice('success', __('Campaign updated successfully!', 'wc-fomo-discount'));
            wp_redirect(admin_url('admin.php?page=wcfd-campaigns'));
            exit;
        }
    }
    
    /**
     * Calculate conversion rate
     */
    private function calculate_conversion_rate() {
        global $wpdb;
        
        $total_views = get_option('wcfd_total_widget_views', 0);
        if ($total_views == 0) {
            return 0;
        }
        
        $total_claims = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} WHERE email_verified = 1"
        );
        
        return round(($total_claims / $total_views) * 100, 2);
    }
    
    /**
     * Get recent claims
     */
    private function get_recent_claims($limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT cc.*, c.campaign_name 
            FROM {$this->db_manager->get_table('claimed_codes')} cc
            JOIN {$this->db_manager->get_table('campaigns')} c ON cc.campaign_id = c.id
            WHERE cc.email_verified = 1
            ORDER BY cc.claimed_at DESC
            LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Get top campaigns
     */
    private function get_top_campaigns($limit = 5) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, COUNT(cc.id) as claim_count
            FROM {$this->db_manager->get_table('campaigns')} c
            LEFT JOIN {$this->db_manager->get_table('claimed_codes')} cc 
                ON c.id = cc.campaign_id AND cc.email_verified = 1
            WHERE c.status = 'active'
            GROUP BY c.id
            ORDER BY claim_count DESC
            LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Add admin notice
     */
    private function add_admin_notice($type, $message) {
        $notices = get_transient('wcfd_admin_notices') ?: [];
        $notices[] = [
            'type' => $type,
            'message' => $message
        ];
        set_transient('wcfd_admin_notices', $notices, 60);
    }
    
    /**
     * Display admin notices
     */
    public function display_admin_notices() {
        $notices = get_transient('wcfd_admin_notices');
        
        if ($notices) {
            foreach ($notices as $notice) {
                printf(
                    '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                    esc_attr($notice['type']),
                    esc_html($notice['message'])
                );
            }
            delete_transient('wcfd_admin_notices');
        }
    }
    
    /**
     * Get performance metrics
     */
    private function get_performance_metrics() {
        global $wpdb;
        
        return $wpdb->get_results(
            "SELECT operation, 
                AVG(duration_ms) as avg_duration, 
                MAX(duration_ms) as max_duration,
                COUNT(*) as count
            FROM {$this->db_manager->get_table('performance_metrics')}
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            GROUP BY operation
            ORDER BY avg_duration DESC
            LIMIT 10"
        );
    }
}