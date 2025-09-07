<?php
/**
 * WooCommerce FOMO Discount Generator Uninstall
 *
 * Uninstalling plugin deletes tables and options.
 */

// Exit if accessed directly
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Drop custom tables
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wcfd_campaigns");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wcfd_claimed_codes");

// Delete options
delete_option('wcfd_db_version');

// Remove scheduled cron
$timestamp = wp_next_scheduled('wcfd_cleanup_expired_codes');
if ($timestamp) {
    wp_unschedule_event($timestamp, 'wcfd_cleanup_expired_codes');
}

// Delete all FOMO discount coupons
$args = array(
    'posts_per_page' => -1,
    'post_type' => 'shop_coupon',
    'post_status' => 'any',
    'meta_query' => array(
        array(
            'key' => 'code',
            'value' => 'FOMO',
            'compare' => 'LIKE'
        )
    )
);

$coupons = get_posts($args);
foreach ($coupons as $coupon) {
    wp_delete_post($coupon->ID, true);
}