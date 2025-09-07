<?php
/**
 * PHPUnit bootstrap file for WC Fomo Discount
 * 
 * @package WC_Fomo_Discount
 * @subpackage Tests
 */

// Define test constants
define('WCFD_TESTS_DIR', dirname(__FILE__));
define('WCFD_PLUGIN_DIR', dirname(WCFD_TESTS_DIR) . '/');

// Load WordPress test environment
$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Manually load plugins
 */
function _manually_load_plugin() {
    // Load WooCommerce first
    require_once WP_PLUGIN_DIR . '/woocommerce/woocommerce.php';
    
    // Load our plugin
    require_once WCFD_PLUGIN_DIR . 'wc-fomo-discount.php';
}

tests_add_filter('muplugins_loaded', '_manually_load_plugin');

require $_tests_dir . '/includes/bootstrap.php';