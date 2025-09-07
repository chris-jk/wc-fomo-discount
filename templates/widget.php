<?php
/**
 * FOMO Discount Widget Template
 * 
 * @package WC_Fomo_Discount
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Calculate urgency class
$urgency_class = '';
if ($campaign->codes_remaining <= 5) {
    $urgency_class = 'wcfd-urgent';
} elseif ($campaign->codes_remaining <= 20) {
    $urgency_class = 'wcfd-low-stock';
}
?>

<div class="wcfd-discount-widget <?php echo esc_attr($style . ' ' . $urgency_class); ?>" 
     data-campaign-id="<?php echo esc_attr($campaign->id); ?>"
     role="region" 
     aria-label="<?php esc_attr_e('Exclusive discount offer', 'wc-fomo-discount'); ?>">
    
    <!-- Header -->
    <div class="wcfd-header">
        <h3 class="wcfd-title"><?php echo esc_html($campaign->campaign_name); ?></h3>
        <div class="wcfd-discount-badge">
            <?php if ($campaign->discount_type == 'percent'): ?>
                <span class="wcfd-discount-value"><?php echo esc_html($campaign->discount_value); ?>%</span>
                <span class="wcfd-discount-text"><?php esc_html_e('OFF', 'wc-fomo-discount'); ?></span>
            <?php else: ?>
                <span class="wcfd-discount-text"><?php esc_html_e('SAVE', 'wc-fomo-discount'); ?></span>
                <span class="wcfd-discount-value"><?php echo wc_price($campaign->discount_value); ?></span>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Counter -->
    <div class="wcfd-counter">
        <div class="wcfd-counter-number" 
             role="status" 
             aria-label="<?php esc_attr_e('Discount codes remaining', 'wc-fomo-discount'); ?>">
            <span class="wcfd-count"><?php echo esc_html($campaign->codes_remaining); ?></span>
        </div>
        <div class="wcfd-counter-text">
            <?php esc_html_e('codes remaining', 'wc-fomo-discount'); ?>
        </div>
    </div>
    
    <!-- Claim Form -->
    <div class="wcfd-claim-form">
        <?php if (!is_user_logged_in()): ?>
            <div class="wcfd-email-group">
                <label for="wcfd-email-<?php echo esc_attr($campaign->id); ?>" class="wcfd-sr-only">
                    <?php esc_html_e('Email address', 'wc-fomo-discount'); ?>
                </label>
                <input type="email" 
                       id="wcfd-email-<?php echo esc_attr($campaign->id); ?>"
                       class="wcfd-email" 
                       placeholder="<?php esc_attr_e('Enter your email address', 'wc-fomo-discount'); ?>"
                       aria-required="true"
                       autocomplete="email">
            </div>
        <?php endif; ?>
        
        <button type="button" 
                class="wcfd-claim-btn"
                aria-describedby="wcfd-claim-help-<?php echo esc_attr($campaign->id); ?>">
            <?php esc_html_e('Get My Code!', 'wc-fomo-discount'); ?>
        </button>
        
        <div id="wcfd-claim-help-<?php echo esc_attr($campaign->id); ?>" class="wcfd-sr-only">
            <?php esc_html_e('Click to claim your exclusive discount code', 'wc-fomo-discount'); ?>
        </div>
    </div>
    
    <!-- Success State -->
    <div class="wcfd-success" style="display: none;" role="alert">
        <div class="wcfd-success-header">
            <span class="wcfd-success-icon">🎉</span>
            <h4><?php esc_html_e('Success!', 'wc-fomo-discount'); ?></h4>
        </div>
        
        <div class="wcfd-success-content">
            <p><?php esc_html_e('Your exclusive discount code:', 'wc-fomo-discount'); ?></p>
            <div class="wcfd-code-container">
                <span class="wcfd-code" aria-label="<?php esc_attr_e('Discount code', 'wc-fomo-discount'); ?>"></span>
                <button class="wcfd-copy-btn" 
                        aria-label="<?php esc_attr_e('Copy code to clipboard', 'wc-fomo-discount'); ?>"
                        title="<?php esc_attr_e('Copy to clipboard', 'wc-fomo-discount'); ?>">
                    📋
                </button>
            </div>
            
            <div class="wcfd-expiry" aria-live="polite"></div>
            
            <div class="wcfd-actions">
                <a href="<?php echo esc_url(wc_get_checkout_url()); ?>" 
                   class="wcfd-checkout-btn">
                    <?php esc_html_e('Apply at Checkout', 'wc-fomo-discount'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Error State -->
    <div class="wcfd-error" 
         style="display: none;" 
         role="alert" 
         aria-live="assertive"
         tabindex="-1"></div>
    
    <!-- Loading Overlay -->
    <div class="wcfd-loading-overlay" style="display: none;">
        <div class="wcfd-spinner" aria-hidden="true"></div>
        <span class="wcfd-sr-only" aria-live="polite">
            <?php esc_html_e('Processing your request', 'wc-fomo-discount'); ?>
        </span>
    </div>
    
    <!-- Screen Reader Announcements -->
    <div aria-live="polite" aria-atomic="true" class="wcfd-sr-only wcfd-announcer"></div>
</div>