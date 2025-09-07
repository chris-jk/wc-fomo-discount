<?php
/**
 * Plugin Name: WooCommerce FOMO Discount Generator (Refactored)
 * Description: Generate limited quantity, time-limited discount codes with real-time countdown - Refactored for better performance and maintainability
 * Version: 2.0.0
 * Author: Cash
 * Text Domain: wc-fomo-discount
 * Requires at least: 5.0
 * Tested up to: 6.3
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Requires PHP: 7.4
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
if (!defined('WCFD_VERSION')) {
    define('WCFD_VERSION', '2.0.0');
}
if (!defined('WCFD_PLUGIN_DIR')) {
    define('WCFD_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('WCFD_PLUGIN_URL')) {
    define('WCFD_PLUGIN_URL', plugin_dir_url(__FILE__));
}

// Enable performance monitoring in debug mode
if (!defined('WCFD_PERFORMANCE_MONITORING')) {
    define('WCFD_PERFORMANCE_MONITORING', defined('WP_DEBUG') && WP_DEBUG);
}

/**
 * Main plugin initialization
 */
function wcfd_init() {
    // Check PHP version
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('WooCommerce FOMO Discount requires PHP 7.4 or higher. Your current version: ' . PHP_VERSION, 'wc-fomo-discount');
            echo '</p></div>';
        });
        return;
    }
    
    // Check WooCommerce dependency
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>';
            echo __('WooCommerce FOMO Discount requires WooCommerce to be installed and activated.', 'wc-fomo-discount');
            echo '</p></div>';
        });
        return;
    }
    
    // Load the refactored plugin
    require_once WCFD_PLUGIN_DIR . 'includes/class-wc-fomo-discount.php';
    
    // Initialize the plugin
    \WCFD\WC_Fomo_Discount::get_instance();
}

// Hook into plugins_loaded to ensure WooCommerce is loaded first
add_action('plugins_loaded', 'wcfd_init', 10);

/**
 * Plugin activation hook
 */
function wcfd_activate() {
    // Run activation only if dependencies are met
    if (class_exists('WooCommerce') && version_compare(PHP_VERSION, '7.4', '>=')) {
        require_once WCFD_PLUGIN_DIR . 'includes/class-wc-fomo-discount.php';
        $plugin = \WCFD\WC_Fomo_Discount::get_instance();
        $plugin->activate();
    }
}
register_activation_hook(__FILE__, 'wcfd_activate');

/**
 * Plugin deactivation hook
 */
function wcfd_deactivate() {
    if (class_exists('\WCFD\WC_Fomo_Discount')) {
        $plugin = \WCFD\WC_Fomo_Discount::get_instance();
        $plugin->deactivate();
    }
}
register_deactivation_hook(__FILE__, 'wcfd_deactivate');

/**
 * Plugin uninstall hook
 */
function wcfd_uninstall() {
    // Clean up plugin data
    delete_option('wcfd_db_version');
    delete_option('wcfd_settings');
    
    // Remove cron jobs
    wp_clear_scheduled_hook('wcfd_cleanup_expired');
    wp_clear_scheduled_hook('wcfd_optimize_database');
    wp_clear_scheduled_hook('wcfd_cleanup_logs');
    
    // Optionally remove all plugin data
    if (apply_filters('wcfd_remove_data_on_uninstall', false)) {
        global $wpdb;
        
        // Drop plugin tables
        $tables = [
            $wpdb->prefix . 'wcfd_campaigns',
            $wpdb->prefix . 'wcfd_claimed_codes',
            $wpdb->prefix . 'wcfd_email_verifications',
            $wpdb->prefix . 'wcfd_waitlist',
            $wpdb->prefix . 'wcfd_performance_metrics',
            $wpdb->prefix . 'wcfd_audit_log'
        ];
        
        foreach ($tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
    }
}
register_uninstall_hook(__FILE__, 'wcfd_uninstall');

/**
 * Load plugin textdomain
 */
function wcfd_load_textdomain() {
    load_plugin_textdomain('wc-fomo-discount', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}
add_action('init', 'wcfd_load_textdomain');

/**
 * Add custom plugin action links
 */
function wcfd_plugin_action_links($links) {
    $action_links = [
        'settings' => '<a href="' . admin_url('admin.php?page=wcfd-settings') . '">' . __('Settings', 'wc-fomo-discount') . '</a>',
        'dashboard' => '<a href="' . admin_url('admin.php?page=wcfd-dashboard') . '">' . __('Dashboard', 'wc-fomo-discount') . '</a>',
    ];
    
    return array_merge($action_links, $links);
}
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'wcfd_plugin_action_links');

/**
 * Add plugin meta links
 */
function wcfd_plugin_row_meta($links, $file) {
    if (plugin_basename(__FILE__) === $file) {
        $row_meta = [
            'docs' => '<a href="#" target="_blank">' . __('Documentation', 'wc-fomo-discount') . '</a>',
            'support' => '<a href="#" target="_blank">' . __('Support', 'wc-fomo-discount') . '</a>',
        ];
        
        return array_merge($links, $row_meta);
    }
    
    return $links;
}
add_filter('plugin_row_meta', 'wcfd_plugin_row_meta', 10, 2);

/**
 * WPML compatibility
 */
function wcfd_wpml_compatibility() {
    if (function_exists('icl_register_string')) {
        // Register strings for translation
        icl_register_string('wc-fomo-discount', 'Get My Code Button Text', 'Get My Code!');
        icl_register_string('wc-fomo-discount', 'Processing Button Text', 'Processing...');
        icl_register_string('wc-fomo-discount', 'Codes Remaining Text', 'codes remaining');
    }
}
add_action('init', 'wcfd_wpml_compatibility');

/**
 * Check for conflicts with other plugins
 */
function wcfd_check_conflicts() {
    $conflicts = [];
    
    // Check for conflicting coupon plugins
    if (is_plugin_active('advanced-coupons-for-woocommerce/advanced-coupons-for-woocommerce.php')) {
        $conflicts[] = 'Advanced Coupons for WooCommerce';
    }
    
    if (!empty($conflicts)) {
        add_action('admin_notices', function() use ($conflicts) {
            echo '<div class="notice notice-warning"><p>';
            echo sprintf(
                __('WooCommerce FOMO Discount has detected potential conflicts with: %s. Please test thoroughly.', 'wc-fomo-discount'),
                implode(', ', $conflicts)
            );
            echo '</p></div>';
        });
    }
}
add_action('admin_init', 'wcfd_check_conflicts');

// Include auto-updater for backward compatibility
if (file_exists(WCFD_PLUGIN_DIR . 'updater.php')) {
    require_once WCFD_PLUGIN_DIR . 'updater.php';
}