<?php
/**
 * Frontend Handler
 * 
 * @package WC_Fomo_Discount
 * @subpackage Frontend
 * @since 2.0.0
 */

namespace WCFD\Frontend;

use WCFD\Core\Logger;
use WCFD\Core\Campaign_Manager;
use WCFD\Core\Validator;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Frontend Handler class for managing frontend functionality
 */
class Frontend_Handler {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var Campaign_Manager
     */
    private $campaign_manager;
    
    /**
     * @var Validator
     */
    private $validator;
    
    /**
     * Constructor
     */
    public function __construct(Logger $logger, Campaign_Manager $campaign_manager, Validator $validator) {
        $this->logger = $logger;
        $this->campaign_manager = $campaign_manager;
        $this->validator = $validator;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize frontend hooks
     */
    private function init_hooks() {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_shortcode('fomo_discount', [$this, 'render_shortcode']);
        
        // AJAX handlers
        add_action('wp_ajax_wcfd_claim_discount', [$this, 'ajax_claim_discount']);
        add_action('wp_ajax_nopriv_wcfd_claim_discount', [$this, 'ajax_claim_discount']);
        add_action('wp_ajax_wcfd_get_campaign_status', [$this, 'ajax_get_campaign_status']);
        add_action('wp_ajax_nopriv_wcfd_get_campaign_status', [$this, 'ajax_get_campaign_status']);
        add_action('wp_ajax_wcfd_verify_email', [$this, 'ajax_verify_email']);
        add_action('wp_ajax_nopriv_wcfd_verify_email', [$this, 'ajax_verify_email']);
        add_action('wp_ajax_wcfd_join_waitlist', [$this, 'ajax_join_waitlist']);
        add_action('wp_ajax_nopriv_wcfd_join_waitlist', [$this, 'ajax_join_waitlist']);
    }
    
    /**
     * Enqueue frontend scripts and styles
     */
    public function enqueue_scripts() {
        // Only enqueue on pages that have the widget or shortcode
        if (!$this->should_enqueue_assets()) {
            return;
        }
        
        // Main CSS
        wp_enqueue_style(
            'wcfd-frontend',
            WCFD_PLUGIN_URL . 'assets/frontend.css',
            [],
            WCFD_VERSION
        );
        
        // Accessibility CSS
        wp_enqueue_style(
            'wcfd-accessibility',
            WCFD_PLUGIN_URL . 'assets/css/accessibility.css',
            ['wcfd-frontend'],
            WCFD_VERSION
        );
        
        // Main JS
        wp_enqueue_script(
            'wcfd-frontend',
            WCFD_PLUGIN_URL . 'assets/frontend.js',
            ['jquery'],
            WCFD_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('wcfd-frontend', 'wcfd_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcfd_nonce'),
            'strings' => [
                'processing' => __('Processing...', 'wc-fomo-discount'),
                'get_code' => __('Get My Code!', 'wc-fomo-discount'),
                'error_generic' => __('An error occurred. Please try again.', 'wc-fomo-discount'),
                'error_email' => __('Please enter a valid email address.', 'wc-fomo-discount'),
                'success' => __('Success! Your discount code has been generated.', 'wc-fomo-discount')
            ]
        ]);
        
        $this->logger->debug('Frontend assets enqueued', ['version' => WCFD_VERSION]);
    }
    
    /**
     * Check if we should enqueue assets on this page
     */
    private function should_enqueue_assets() {
        global $post;
        
        // Always enqueue on single posts/pages (they might have shortcodes)
        if (is_singular()) {
            return true;
        }
        
        // Enqueue on shop pages
        if (function_exists('is_woocommerce') && is_woocommerce()) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Render shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'id' => 0,
            'campaign_id' => 0, // Backward compatibility
            'style' => 'default'
        ], $atts, 'fomo_discount');
        
        // Use 'id' or fall back to 'campaign_id' for backward compatibility
        $campaign_id = intval($atts['id']) ?: intval($atts['campaign_id']);
        
        if (empty($campaign_id)) {
            return '<div class="wcfd-error">Error: Campaign ID is required. Usage: [fomo_discount id="123"]</div>';
        }
        
        return $this->render_widget($campaign_id, $atts['style']);
    }
    
    /**
     * Render widget HTML
     */
    public function render_widget($campaign_id, $style = 'default') {
        $campaign = $this->campaign_manager->get_campaign($campaign_id);
        
        if (!$campaign || $campaign->status !== 'active') {
            return '<div class="wcfd-error">Campaign not found or inactive.</div>';
        }
        
        if ($campaign->codes_remaining <= 0) {
            return '<div class="wcfd-sold-out">All discount codes have been claimed!</div>';
        }
        
        // Enqueue assets if not already done
        $this->enqueue_scripts();
        
        ob_start();
        include WCFD_PLUGIN_DIR . 'templates/widget.php';
        return ob_get_clean();
    }
    
    /**
     * AJAX: Claim discount
     */
    public function ajax_claim_discount() {
        try {
            // Verify nonce
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wcfd_nonce')) {
                wp_send_json_error(__('Security check failed', 'wc-fomo-discount'));
            }
            
            $campaign_id = intval($_POST['campaign_id'] ?? 0);
            $email = sanitize_email($_POST['email'] ?? '');
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? '';
            
            // Validate inputs
            if (!$this->validator->validate_campaign_id($campaign_id)) {
                wp_send_json_error($this->validator->get_error_message());
            }
            
            // For logged-in users, use their email
            if (is_user_logged_in()) {
                $current_user = wp_get_current_user();
                $email = $current_user->user_email;
                $email_verified = true;
            } else {
                if (!$this->validator->validate_email($email)) {
                    wp_send_json_error($this->validator->get_error_message());
                }
                $email_verified = false;
            }
            
            $validated_ip = $this->validator->validate_ip_address($ip_address);
            if (!$validated_ip) {
                wp_send_json_error($this->validator->get_error_message());
            }
            
            // For non-logged-in users, send verification email
            if (!$email_verified) {
                $this->send_verification_email($campaign_id, $email, $validated_ip);
                wp_send_json_success([
                    'message' => __('Please check your email to verify your address and claim your discount code.', 'wc-fomo-discount')
                ]);
                return;
            }
            
            // Claim discount for verified users
            $result = $this->campaign_manager->claim_discount($campaign_id, $email, $validated_ip, $email_verified);
            
            if (is_wp_error($result)) {
                wp_send_json_error($result->get_error_message());
            }
            
            wp_send_json_success($result);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX claim discount error', [
                'error' => $e->getMessage(),
                'campaign_id' => $campaign_id ?? null,
                'email' => $email ?? null
            ]);
            wp_send_json_error(__('An error occurred. Please try again.', 'wc-fomo-discount'));
        }
    }
    
    /**
     * AJAX: Get campaign status
     */
    public function ajax_get_campaign_status() {
        try {
            if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wcfd_nonce')) {
                wp_send_json_error(__('Security check failed', 'wc-fomo-discount'));
            }
            
            $campaign_id = intval($_POST['campaign_id'] ?? 0);
            $campaign = $this->campaign_manager->get_campaign($campaign_id);
            
            if (!$campaign) {
                wp_send_json_error(__('Campaign not found', 'wc-fomo-discount'));
            }
            
            wp_send_json_success([
                'codes_remaining' => intval($campaign->codes_remaining),
                'status' => $campaign->status
            ]);
            
        } catch (\Exception $e) {
            $this->logger->error('AJAX get campaign status error', ['error' => $e->getMessage()]);
            wp_send_json_error(__('An error occurred', 'wc-fomo-discount'));
        }
    }
    
    /**
     * Send verification email
     */
    private function send_verification_email($campaign_id, $email, $ip_address) {
        global $wpdb;
        
        // Generate verification token
        $token = wp_generate_password(32, false);
        $coupon_code = 'FOMO' . strtoupper(wp_generate_password(8, false));
        
        // Store verification request
        $wpdb->insert(
            $wpdb->prefix . 'wcfd_email_verifications',
            [
                'campaign_id' => $campaign_id,
                'user_email' => $email,
                'verification_token' => $token,
                'coupon_code' => $coupon_code,
                'ip_address' => $ip_address,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour'))
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
        );
        
        // Send verification email
        $verification_url = add_query_arg([
            'wcfd_verify' => $token,
            'email' => urlencode($email)
        ], home_url());
        
        $campaign = $this->campaign_manager->get_campaign($campaign_id);
        $subject = sprintf(__('Verify your email to claim your %s discount', 'wc-fomo-discount'), $campaign->campaign_name);
        
        $message = sprintf(
            __("Click the link below to verify your email and claim your exclusive discount code:\n\n%s\n\nThis link expires in 1 hour.\n\nIf you didn't request this, please ignore this email.", 'wc-fomo-discount'),
            $verification_url
        );
        
        wp_mail($email, $subject, $message);
        
        $this->logger->info('Verification email sent', [
            'email' => $email,
            'campaign_id' => $campaign_id
        ]);
    }
    
    /**
     * AJAX: Verify email
     */
    public function ajax_verify_email() {
        $token = sanitize_text_field($_GET['wcfd_verify'] ?? '');
        $email = sanitize_email($_GET['email'] ?? '');
        
        if (empty($token) || empty($email)) {
            wp_die(__('Invalid verification link', 'wc-fomo-discount'));
        }
        
        global $wpdb;
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_email_verifications 
            WHERE verification_token = %s AND user_email = %s AND verified_at IS NULL AND expires_at > NOW()",
            $token, $email
        ));
        
        if (!$verification) {
            wp_die(__('Verification link expired or invalid', 'wc-fomo-discount'));
        }
        
        // Process the discount claim
        $result = $this->campaign_manager->claim_discount(
            $verification->campaign_id,
            $email,
            $verification->ip_address,
            true,
            $verification
        );
        
        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }
        
        // Verification is handled within claim_discount method
    }
    
    /**
     * AJAX: Join waitlist
     */
    public function ajax_join_waitlist() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wcfd_nonce')) {
            wp_send_json_error(__('Security check failed', 'wc-fomo-discount'));
        }
        
        $campaign_id = intval($_POST['campaign_id'] ?? 0);
        $email = sanitize_email($_POST['email'] ?? '');
        
        if (!$this->validator->validate_email($email)) {
            wp_send_json_error($this->validator->get_error_message());
        }
        
        global $wpdb;
        
        // Check if already on waitlist
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcfd_waitlist 
            WHERE campaign_id = %d AND email = %s",
            $campaign_id, $email
        ));
        
        if ($existing) {
            wp_send_json_error(__('You are already on the waitlist', 'wc-fomo-discount'));
        }
        
        // Add to waitlist
        $result = $wpdb->insert(
            $wpdb->prefix . 'wcfd_waitlist',
            [
                'campaign_id' => $campaign_id,
                'email' => $email
            ],
            ['%d', '%s']
        );
        
        if ($result === false) {
            wp_send_json_error(__('Failed to join waitlist', 'wc-fomo-discount'));
        }
        
        $this->logger->info('User joined waitlist', [
            'email' => $email,
            'campaign_id' => $campaign_id
        ]);
        
        wp_send_json_success([
            'message' => __('Thanks! You\'ve been added to the waitlist and will be notified when new codes are available.', 'wc-fomo-discount')
        ]);
    }
}