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
        add_action('init', [$this, 'handle_autologin']);
        add_action('init', [$this, 'handle_coupon_application']);
        
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
        // Debug log to confirm our refactored system is running
        error_log('WCFD: Refactored frontend handler enqueuing scripts - Version: ' . WCFD_VERSION);
        
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
        
        // Main JS - Force cache bust
        wp_enqueue_script(
            'wcfd-frontend',
            WCFD_PLUGIN_URL . 'assets/frontend.js',
            ['jquery'],
            WCFD_VERSION . '.' . time(),
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
        
        // Create or get user account
        $user_id = $this->create_or_get_user($email);
        
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
        
        // Create auto-login token
        $login_token = wp_generate_password(32, false);
        update_user_meta($user_id, 'wcfd_autologin_token', $login_token);
        update_user_meta($user_id, 'wcfd_autologin_expires', time() + 3600); // 1 hour
        
        // Get the current page URL to redirect back to after verification
        $current_url = $_SERVER['HTTP_REFERER'] ?? home_url();
        
        // Send verification email
        $verification_url = add_query_arg([
            'wcfd_verify' => $token,
            'email' => urlencode($email),
            'redirect_to' => urlencode($current_url),
            'wcfd_autologin' => $login_token
        ], home_url());
        
        $campaign = $this->campaign_manager->get_campaign($campaign_id);
        $subject = sprintf(__('🎉 Your %s Discount Code Inside!', 'wc-fomo-discount'), $campaign->campaign_name);
        
        $discount_text = $campaign->discount_type == 'percent' 
            ? $campaign->discount_value . '% OFF' 
            : 'SAVE $' . $campaign->discount_value;
            
        // Create direct checkout link with coupon pre-applied and auto-login
        $checkout_url = add_query_arg([
            'wcfd_apply_coupon' => $coupon_code,
            'wcfd_token' => $token,
            'wcfd_autologin' => $login_token
        ], wc_get_checkout_url());
        
        // Create shop link with coupon ready to apply and auto-login
        $shop_url = add_query_arg([
            'wcfd_apply_coupon' => $coupon_code,
            'wcfd_token' => $token,
            'wcfd_autologin' => $login_token
        ], wc_get_page_permalink('shop'));
            
        $message = sprintf(
            __("🎉 CONGRATULATIONS! Your exclusive discount is ready!\n\n" .
            "🎁 DISCOUNT CODE: %s\n" .
            "💰 YOUR SAVINGS: %s\n" .
            "⏰ EXPIRES: 24 hours from now\n\n" .
            "🚀 CLAIM NOW - 2 EASY OPTIONS:\n\n" .
            "1️⃣ INSTANT CHECKOUT (Recommended):\n%s\n\n" .
            "2️⃣ BROWSE & SHOP FIRST:\n%s\n\n" .
            "3️⃣ OR ACTIVATE ON ORIGINAL PAGE:\n%s\n\n" .
            "💡 PRO TIP: Use option 1 for fastest checkout - your discount will be automatically applied!\n\n" .
            "⚠️ LIMITED TIME: This exclusive offer expires in 24 hours. Don't miss out!\n\n" .
            "Questions? Reply to this email for instant support.\n\n" .
            "If you didn't request this, please ignore this email.", 'wc-fomo-discount'),
            $coupon_code,
            $discount_text,
            $checkout_url,
            $shop_url,
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
        $redirect_to = urldecode($_GET['redirect_to'] ?? '');
        $autologin_token = sanitize_text_field($_GET['wcfd_autologin'] ?? '');
        
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
        
        // Handle auto-login if token provided
        if (!empty($autologin_token) && !is_user_logged_in()) {
            $this->auto_login_user($email, $autologin_token);
        }
        
        // Mark verification as completed
        $wpdb->update(
            $wpdb->prefix . 'wcfd_email_verifications',
            ['verified_at' => current_time('mysql')],
            ['id' => $verification->id]
        );
        
        // Get the coupon code for display
        $coupon_code = $verification->coupon_code;
        
        // Create success message
        $campaign = $this->campaign_manager->get_campaign($verification->campaign_id);
        $success_message = sprintf(
            __('Email verified successfully! Your discount code %s is now active and ready to use.', 'wc-fomo-discount'),
            '<strong>' . $coupon_code . '</strong>'
        );
        
        // Determine where to redirect
        if (!empty($redirect_to) && filter_var($redirect_to, FILTER_VALIDATE_URL)) {
            // Add success parameters to the redirect URL
            $redirect_url = add_query_arg([
                'wcfd_verified' => '1',
                'wcfd_code' => $coupon_code,
                'wcfd_campaign' => $verification->campaign_id,
                'wcfd_message' => urlencode($success_message)
            ], $redirect_to);
        } else {
            // Fallback to home with success message
            $redirect_url = add_query_arg([
                'wcfd_verified' => '1',
                'wcfd_code' => $coupon_code,
                'wcfd_message' => urlencode($success_message)
            ], home_url());
        }
        
        // Redirect back to the original page with success message
        wp_redirect($redirect_url);
        exit;
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
    
    /**
     * Create or get user account
     */
    private function create_or_get_user($email) {
        $user = get_user_by('email', $email);
        
        if ($user) {
            return $user->ID;
        }
        
        // Create new user account
        $username = $this->generate_username_from_email($email);
        $password = wp_generate_password();
        
        $user_id = wp_create_user($username, $password, $email);
        
        if (is_wp_error($user_id)) {
            $this->logger->error('Failed to create user account', [
                'email' => $email,
                'error' => $user_id->get_error_message()
            ]);
            return false;
        }
        
        // Set user role to customer
        $user = new \WP_User($user_id);
        $user->set_role('customer');
        
        // Log the account creation
        $this->logger->info('Created new user account for FOMO discount', [
            'user_id' => $user_id,
            'email' => $email
        ]);
        
        return $user_id;
    }
    
    /**
     * Generate username from email
     */
    private function generate_username_from_email($email) {
        $base_username = sanitize_user(substr($email, 0, strpos($email, '@')));
        $username = $base_username;
        $counter = 1;
        
        while (username_exists($username)) {
            $username = $base_username . $counter;
            $counter++;
        }
        
        return $username;
    }
    
    /**
     * Handle auto-login from email links
     */
    public function handle_autologin() {
        if (!isset($_GET['wcfd_autologin'])) {
            return;
        }
        
        $login_token = sanitize_text_field($_GET['wcfd_autologin']);
        $email = sanitize_email($_GET['email'] ?? '');
        
        if (empty($login_token) || empty($email)) {
            return;
        }
        
        // Find user with this login token
        $users = get_users([
            'meta_key' => 'wcfd_autologin_token',
            'meta_value' => $login_token,
            'number' => 1
        ]);
        
        if (empty($users)) {
            return;
        }
        
        $user = $users[0];
        
        // Check if token is still valid
        $expires = get_user_meta($user->ID, 'wcfd_autologin_expires', true);
        if (!$expires || $expires < time()) {
            delete_user_meta($user->ID, 'wcfd_autologin_token');
            delete_user_meta($user->ID, 'wcfd_autologin_expires');
            return;
        }
        
        // Verify email matches
        if ($user->user_email !== $email) {
            return;
        }
        
        // Log the user in
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        
        // Clean up the login token (one-time use)
        delete_user_meta($user->ID, 'wcfd_autologin_token');
        delete_user_meta($user->ID, 'wcfd_autologin_expires');
        
        $this->logger->info('Auto-login successful', [
            'user_id' => $user->ID,
            'email' => $email
        ]);
    }
    
    /**
     * Auto-login user with token
     */
    private function auto_login_user($email, $login_token) {
        $users = get_users([
            'meta_key' => 'wcfd_autologin_token',
            'meta_value' => $login_token,
            'number' => 1
        ]);
        
        if (empty($users)) {
            return false;
        }
        
        $user = $users[0];
        
        // Verify email matches
        if ($user->user_email !== $email) {
            return false;
        }
        
        // Check if token is still valid
        $expires = get_user_meta($user->ID, 'wcfd_autologin_expires', true);
        if (!$expires || $expires < time()) {
            return false;
        }
        
        // Log the user in
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, true);
        
        // Clean up the login token (one-time use)
        delete_user_meta($user->ID, 'wcfd_autologin_token');
        delete_user_meta($user->ID, 'wcfd_autologin_expires');
        
        return true;
    }
    
    /**
     * Handle coupon application from email links
     */
    public function handle_coupon_application() {
        if (!isset($_GET['wcfd_apply_coupon']) || !isset($_GET['wcfd_token'])) {
            return;
        }
        
        $coupon_code = sanitize_text_field($_GET['wcfd_apply_coupon']);
        $token = sanitize_text_field($_GET['wcfd_token']);
        
        // Verify the token exists in our verification table
        global $wpdb;
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_email_verifications 
            WHERE verification_token = %s AND coupon_code = %s",
            $token, $coupon_code
        ));
        
        if (!$verification) {
            return; // Invalid token
        }
        
        // Add to cart if there are products or redirect to shop
        if (WC()->cart && !WC()->cart->is_empty()) {
            // Apply coupon to existing cart
            WC()->cart->apply_coupon($coupon_code);
            wc_add_notice(sprintf(__('Discount code %s has been applied!', 'wc-fomo-discount'), $coupon_code), 'success');
        } else {
            // Store coupon for later application
            WC()->session->set('wcfd_pending_coupon', $coupon_code);
            wc_add_notice(sprintf(__('Your discount code %s is ready! Add items to your cart to apply it.', 'wc-fomo-discount'), $coupon_code), 'notice');
        }
        
        $this->logger->info('Coupon applied via email link', [
            'coupon_code' => $coupon_code,
            'user_id' => get_current_user_id(),
            'verification_id' => $verification->id
        ]);
    }
}