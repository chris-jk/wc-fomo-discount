<?php
/**
 * Dashboard View
 * 
 * @package WC_Fomo_Discount
 * @subpackage Admin\Views
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php esc_html_e('FOMO Discount Dashboard', 'wc-fomo-discount'); ?></h1>
    
    <p><?php esc_html_e('Welcome to the FOMO Discount plugin! Create urgency and boost sales with limited-time discount campaigns.', 'wc-fomo-discount'); ?></p>
    
    <div class="wcfd-dashboard-actions" style="margin: 20px 0;">
        <a href="<?php echo esc_url(admin_url('admin.php?page=wcfd-campaigns&action=new')); ?>" class="button button-primary button-hero">
            <?php esc_html_e('Create New Campaign', 'wc-fomo-discount'); ?>
        </a>
        <a href="<?php echo esc_url(admin_url('admin.php?page=wcfd-campaigns')); ?>" class="button button-hero">
            <?php esc_html_e('View All Campaigns', 'wc-fomo-discount'); ?>
        </a>
    </div>
    
    <?php if (isset($stats) && !empty($stats)): ?>
        <div class="wcfd-stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin: 20px 0;">
            <div class="wcfd-stat-card">
                <h3><?php esc_html_e('Total Campaigns', 'wc-fomo-discount'); ?></h3>
                <span class="stat-number"><?php echo esc_html($stats['total_campaigns']); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h3><?php esc_html_e('Active Campaigns', 'wc-fomo-discount'); ?></h3>
                <span class="stat-number"><?php echo esc_html($stats['active_campaigns']); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h3><?php esc_html_e('Total Claims', 'wc-fomo-discount'); ?></h3>
                <span class="stat-number"><?php echo esc_html($stats['total_claims']); ?></span>
            </div>
            <div class="wcfd-stat-card">
                <h3><?php esc_html_e('Conversion Rate', 'wc-fomo-discount'); ?></h3>
                <span class="stat-number"><?php echo esc_html(number_format($stats['conversion_rate'], 1)); ?>%</span>
            </div>
        </div>
    <?php endif; ?>
    
    <div class="wcfd-quick-start" style="background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; margin: 20px 0;">
        <h2><?php esc_html_e('Quick Start Guide', 'wc-fomo-discount'); ?></h2>
        <ol>
            <li><strong><?php esc_html_e('Create a Campaign', 'wc-fomo-discount'); ?></strong> - <?php esc_html_e('Set up your discount amount, expiry time, and number of codes.', 'wc-fomo-discount'); ?></li>
            <li><strong><?php esc_html_e('Copy the Shortcode', 'wc-fomo-discount'); ?></strong> - <?php esc_html_e('Use the provided shortcode to display the FOMO widget on any page or post.', 'wc-fomo-discount'); ?></li>
            <li><strong><?php esc_html_e('Monitor Performance', 'wc-fomo-discount'); ?></strong> - <?php esc_html_e('Track how many codes are claimed and optimize your campaigns.', 'wc-fomo-discount'); ?></li>
        </ol>
    </div>
</div>

<style>
.wcfd-stat-card {
    background: #fff;
    border: 1px solid #c3c4c7;
    border-radius: 4px;
    padding: 20px;
    text-align: center;
}
.wcfd-stat-card h3 {
    margin: 0 0 15px 0;
    font-size: 16px;
    color: #646970;
}
.stat-number {
    display: block;
    font-size: 32px;
    font-weight: 600;
    color: #1d2327;
}
</style>