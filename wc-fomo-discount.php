<?php
/**
 * Plugin Name: WooCommerce FOMO Discount Generator
 * Description: Generate limited quantity, time-limited discount codes with real-time countdown
 * Version: 1.0.0
 * Author: Cash
 * Text Domain: wc-fomo-discount
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCFD_VERSION', '1.0.1');
define('WCFD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WCFD_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include auto-updater
require_once(WCFD_PLUGIN_DIR . 'updater.php');

// Main plugin class
class WC_FOMO_Discount_Generator
{

    private static $instance = null;

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('init', array($this, 'init'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_email_export'));

        // AJAX handlers
        add_action('wp_ajax_wcfd_claim_discount', array($this, 'ajax_claim_discount'));
        add_action('wp_ajax_nopriv_wcfd_claim_discount', array($this, 'ajax_claim_discount'));
        add_action('wp_ajax_wcfd_get_campaign_status', array($this, 'ajax_get_campaign_status'));
        add_action('wp_ajax_nopriv_wcfd_get_campaign_status', array($this, 'ajax_get_campaign_status'));
        add_action('wp_ajax_wcfd_verify_email', array($this, 'ajax_verify_email'));
        add_action('wp_ajax_nopriv_wcfd_verify_email', array($this, 'ajax_verify_email'));
        add_action('wp_ajax_wcfd_join_waitlist', array($this, 'ajax_join_waitlist'));
        add_action('wp_ajax_nopriv_wcfd_join_waitlist', array($this, 'ajax_join_waitlist'));

        // Shortcode for discount widget
        add_shortcode('fomo_discount', array($this, 'render_discount_widget'));

        // Create tables on activation
        register_activation_hook(__FILE__, array($this, 'create_tables'));
        
        // Schedule cleanup cron
        add_action('wcfd_cleanup_expired_codes', array($this, 'cleanup_expired_codes'));
        if (!wp_next_scheduled('wcfd_cleanup_expired_codes')) {
            wp_schedule_event(time(), 'hourly', 'wcfd_cleanup_expired_codes');
        }
        
        // Initialize auto-updater
        // CHANGE THESE VALUES TO YOUR GITHUB DETAILS:
        new WCFD_Auto_Updater(__FILE__, 'chris-jk', 'wc-fomo-discount', WCFD_VERSION);
        
        // Configure SMTP if settings exist
        add_action('phpmailer_init', array($this, 'configure_smtp'));
    }

    public function init()
    {
        // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', function () {
                echo '<div class="error"><p>WooCommerce FOMO Discount Generator requires WooCommerce to be installed and activated.</p></div>';
            });
            return;
        }
    }

    public function create_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        // Campaigns table
        $campaigns_table = $wpdb->prefix . 'wcfd_campaigns';
        $sql1 = "CREATE TABLE $campaigns_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_name varchar(255) NOT NULL,
            discount_type enum('percent','fixed') DEFAULT 'percent',
            discount_value decimal(10,2) NOT NULL,
            total_codes int(11) NOT NULL,
            codes_remaining int(11) NOT NULL,
            expiry_hours int(11) NOT NULL,
            scope_type enum('all','products','categories') DEFAULT 'all',
            scope_ids text,
            enable_tiers tinyint(1) DEFAULT 0,
            tier_config text,
            enable_ip_limit tinyint(1) DEFAULT 0,
            max_per_ip int(11) DEFAULT 1,
            status enum('active','paused','expired') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status)
        ) $charset_collate;";

        // Claimed codes table
        $claimed_table = $wpdb->prefix . 'wcfd_claimed_codes';
        $sql2 = "CREATE TABLE $claimed_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_id int(11) NOT NULL,
            user_email varchar(255) NOT NULL,
            user_id int(11) DEFAULT NULL,
            coupon_code varchar(50) NOT NULL,
            claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            used_at datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            email_verified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_campaign_email (campaign_id, user_email),
            KEY idx_coupon_code (coupon_code),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";
        
        // Email verification table
        $verification_table = $wpdb->prefix . 'wcfd_email_verifications';
        $sql3 = "CREATE TABLE $verification_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_id int(11) NOT NULL,
            user_email varchar(255) NOT NULL,
            verification_token varchar(64) NOT NULL,
            coupon_code varchar(50) NOT NULL,
            expires_at datetime NOT NULL,
            claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
            verified_at datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_token (verification_token),
            KEY idx_email_campaign (user_email, campaign_id),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";

        // Waitlist table for lead magnet
        $waitlist_table = $wpdb->prefix . 'wcfd_waitlist';
        $sql4 = "CREATE TABLE $waitlist_table (
            id int(11) NOT NULL AUTO_INCREMENT,
            email varchar(255) NOT NULL,
            campaign_id int(11) DEFAULT NULL,
            source enum('sold_out','general') DEFAULT 'sold_out',
            status enum('active','notified','unsubscribed') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            notified_at datetime DEFAULT NULL,
            ip_address varchar(45) DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY unique_email_campaign (email, campaign_id),
            KEY idx_status (status),
            KEY idx_email (email),
            KEY idx_created (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql1);
        dbDelta($sql2);
        dbDelta($sql3);
        dbDelta($sql4);
        
        // Add version option for future upgrades
        add_option('wcfd_db_version', WCFD_VERSION);
    }

    public function enqueue_scripts()
    {
        wp_enqueue_script('wcfd-frontend', WCFD_PLUGIN_URL . 'assets/frontend.js', array('jquery'), WCFD_VERSION, true);
        wp_enqueue_style('wcfd-frontend', WCFD_PLUGIN_URL . 'assets/frontend.css', array(), WCFD_VERSION);

        wp_localize_script('wcfd-frontend', 'wcfd_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcfd_nonce')
        ));
    }

    public function add_admin_menu()
    {
        add_menu_page(
            'FOMO Discounts',
            'FOMO Discounts',
            'manage_woocommerce',
            'wcfd-campaigns',
            array($this, 'admin_page'),
            'dashicons-megaphone',
            58
        );
        
        add_submenu_page(
            'wcfd-campaigns',
            'Email Leads',
            'Email Leads',
            'manage_woocommerce',
            'wcfd-emails',
            array($this, 'emails_page')
        );
        
        add_submenu_page(
            'wcfd-campaigns',
            'Email Settings',
            'Email Settings',
            'manage_woocommerce',
            'wcfd-email-settings',
            array($this, 'email_settings_page')
        );
    }

    public function admin_page()
    {
        // Handle form submissions
        if (isset($_POST['wcfd_create_campaign'])) {
            $this->create_campaign($_POST);
        }

        global $wpdb;
        $campaigns = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}wcfd_campaigns ORDER BY created_at DESC");
        ?>
        <div class="wrap">
            <h1>FOMO Discount Campaigns</h1>

            <div class="wcfd-admin-container">
                <div class="wcfd-create-campaign">
                    <h2>Create New Campaign</h2>
                    <form method="post">
                        <?php wp_nonce_field('wcfd_create_campaign', 'wcfd_nonce'); ?>

                        <table class="form-table">
                            <tr>
                                <th>Campaign Name</th>
                                <td><input type="text" name="campaign_name" required /></td>
                            </tr>
                            <tr>
                                <th>Discount Type</th>
                                <td>
                                    <select name="discount_type">
                                        <option value="percent">Percentage</option>
                                        <option value="fixed">Fixed Amount</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th>Discount Value</th>
                                <td><input type="number" name="discount_value" step="0.01" required /></td>
                            </tr>
                            <tr>
                                <th>Total Codes</th>
                                <td><input type="number" name="total_codes" min="1" required /></td>
                            </tr>
                            <tr>
                                <th>Code Validity (hours)</th>
                                <td><input type="number" name="expiry_hours" min="1" value="24" required /></td>
                            </tr>
                            <tr>
                                <th>Scope</th>
                                <td>
                                    <select name="scope_type" id="scope_type">
                                        <option value="all">All Products</option>
                                        <option value="products">Specific Products</option>
                                        <option value="categories">Specific Categories</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="scope_ids_row" style="display:none;">
                                <th>IDs (comma-separated)</th>
                                <td><input type="text" name="scope_ids" placeholder="1,2,3" /></td>
                            </tr>
                            <tr>
                                <th>Tiered Discounts</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_tiers" id="enable_tiers" />
                                        Enable tiered discount system
                                    </label>
                                    <p class="description">Start with higher discount, decrease as more codes are claimed</p>
                                </td>
                            </tr>
                            <tr id="tier_config_row" style="display:none;">
                                <th>Tier Configuration</th>
                                <td>
                                    <div id="tier_builder">
                                        <p>Define discount tiers (higher discounts for earlier claims):</p>
                                        <div class="tier-row">
                                            <label>First <input type="number" name="tier_1_codes" min="1" placeholder="50" style="width:60px;"> codes get <input type="number" name="tier_1_discount" step="0.01" placeholder="25" style="width:60px;"><span class="tier-1-suffix">%</span> discount</label>
                                        </div>
                                        <div class="tier-row">
                                            <label>Next <input type="number" name="tier_2_codes" min="1" placeholder="30" style="width:60px;"> codes get <input type="number" name="tier_2_discount" step="0.01" placeholder="15" style="width:60px;"><span class="tier-2-suffix">%</span> discount</label>
                                        </div>
                                        <div class="tier-row">
                                            <label>Remaining codes get <input type="number" name="tier_3_discount" step="0.01" placeholder="10" style="width:60px;"><span class="tier-3-suffix">%</span> discount</label>
                                        </div>
                                        <p class="description">Example: First 50 codes = 25% off, next 30 codes = 15% off, remaining = 10% off</p>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>IP Address Limiting</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="enable_ip_limit" id="enable_ip_limit" />
                                        Limit claims per IP address
                                    </label>
                                    <p class="description">Prevent multiple claims from the same IP address</p>
                                </td>
                            </tr>
                            <tr id="ip_limit_row" style="display:none;">
                                <th>Max Claims Per IP</th>
                                <td>
                                    <input type="number" name="max_per_ip" min="1" value="1" style="width:80px;" />
                                    <p class="description">Maximum discount codes one IP address can claim</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <input type="submit" name="wcfd_create_campaign" class="button-primary" value="Create Campaign" />
                        </p>
                    </form>
                </div>

                <div class="wcfd-campaigns-list">
                    <h2>Active Campaigns</h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Discount</th>
                                <th>Codes Remaining</th>
                                <th>Status</th>
                                <th>Shortcode</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($campaigns as $campaign): ?>
                                <tr>
                                    <td><?php echo esc_html($campaign->campaign_name); ?></td>
                                    <td>
                                        <?php
                                        echo $campaign->discount_type == 'percent'
                                            ? $campaign->discount_value . '%'
                                            : get_woocommerce_currency_symbol() . $campaign->discount_value;
                                        ?>
                                    </td>
                                    <td><?php echo $campaign->codes_remaining . '/' . $campaign->total_codes; ?></td>
                                    <td><?php echo ucfirst($campaign->status); ?></td>
                                    <td><code>[fomo_discount id="<?php echo $campaign->id; ?>"]</code></td>
                                    <td>
                                        <a href="#" class="button wcfd-toggle-status" data-id="<?php echo $campaign->id; ?>">
                                            <?php echo $campaign->status == 'active' ? 'Pause' : 'Activate'; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <script>
                jQuery(document).ready(function ($) {
                    $('#scope_type').change(function () {
                        if ($(this).val() !== 'all') {
                            $('#scope_ids_row').show();
                        } else {
                            $('#scope_ids_row').hide();
                        }
                    });
                    
                    // Handle tier configuration visibility
                    $('#enable_tiers').change(function () {
                        if ($(this).is(':checked')) {
                            $('#tier_config_row').show();
                            updateTierSuffixes();
                        } else {
                            $('#tier_config_row').hide();
                        }
                    });
                    
                    // Handle IP limit configuration visibility
                    $('#enable_ip_limit').change(function () {
                        if ($(this).is(':checked')) {
                            $('#ip_limit_row').show();
                        } else {
                            $('#ip_limit_row').hide();
                        }
                    });
                    
                    // Update tier suffixes based on discount type
                    $('select[name="discount_type"]').change(function () {
                        updateTierSuffixes();
                    });
                    
                    function updateTierSuffixes() {
                        var discountType = $('select[name="discount_type"]').val();
                        var suffix = discountType === 'percent' ? '%' : '<?php echo get_woocommerce_currency_symbol(); ?>';
                        $('.tier-1-suffix, .tier-2-suffix, .tier-3-suffix').text(suffix);
                    }
                    
                    // Initialize on page load
                    updateTierSuffixes();
                });
            </script>
        </div>
        <?php
    }

    private function create_campaign($data)
    {
        global $wpdb;

        // Verify nonce
        if (!wp_verify_nonce($data['wcfd_nonce'], 'wcfd_create_campaign')) {
            return;
        }

        // Process tier configuration
        $tier_config = '';
        if (isset($data['enable_tiers']) && $data['enable_tiers']) {
            $tier_config = json_encode([
                'tier_1' => [
                    'codes' => intval($data['tier_1_codes']),
                    'discount' => floatval($data['tier_1_discount'])
                ],
                'tier_2' => [
                    'codes' => intval($data['tier_2_codes']),
                    'discount' => floatval($data['tier_2_discount'])
                ],
                'tier_3' => [
                    'discount' => floatval($data['tier_3_discount'])
                ]
            ]);
        }
        
        $wpdb->insert(
            $wpdb->prefix . 'wcfd_campaigns',
            array(
                'campaign_name' => sanitize_text_field($data['campaign_name']),
                'discount_type' => sanitize_text_field($data['discount_type']),
                'discount_value' => floatval($data['discount_value']),
                'total_codes' => intval($data['total_codes']),
                'codes_remaining' => intval($data['total_codes']),
                'expiry_hours' => intval($data['expiry_hours']),
                'scope_type' => sanitize_text_field($data['scope_type']),
                'scope_ids' => $this->validate_scope_ids($data['scope_ids']),
                'enable_tiers' => isset($data['enable_tiers']) ? 1 : 0,
                'tier_config' => $tier_config,
                'enable_ip_limit' => isset($data['enable_ip_limit']) ? 1 : 0,
                'max_per_ip' => intval($data['max_per_ip'] ?? 1),
                'status' => 'active'
            )
        );

        echo '<div class="notice notice-success is-dismissible"><p>' . __('Campaign created successfully!', 'wc-fomo-discount') . '</p></div>';
    }
    
    private function validate_scope_ids($input) {
        if (empty($input)) {
            return '';
        }
        
        $ids = explode(',', $input);
        $clean_ids = array();
        
        foreach ($ids as $id) {
            $id = intval(trim($id));
            if ($id > 0) {
                $clean_ids[] = $id;
            }
        }
        
        return implode(',', $clean_ids);
    }

    public function render_discount_widget($atts)
    {
        $atts = shortcode_atts(array(
            'id' => 0
        ), $atts);

        if (!$atts['id']) {
            return 'Campaign ID required';
        }

        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_campaigns WHERE id = %d AND status = 'active'",
            $atts['id']
        ));

        if (!$campaign) {
            return '';
        }

        ob_start();
        ?>
        <div class="wcfd-discount-widget" data-campaign-id="<?php echo $campaign->id; ?>">
            <div class="wcfd-header">
                <h3>🔥 Limited Time Offer!</h3>
                <?php if ($campaign->enable_tiers && !empty($campaign->tier_config)): ?>
                    <?php 
                    $current_discount = $this->calculate_discount_value($campaign);
                    $tiers = json_decode($campaign->tier_config, true);
                    $codes_claimed = $campaign->total_codes - $campaign->codes_remaining;
                    ?>
                    <div class="wcfd-tier-info">
                        <div class="wcfd-discount-value">
                            <?php
                            echo $campaign->discount_type == 'percent'
                                ? $current_discount . '% OFF'
                                : 'SAVE ' . get_woocommerce_currency_symbol() . $current_discount;
                            ?>
                        </div>
                        <div class="wcfd-tier-status">
                            <?php 
                            $tier_1_codes = $tiers['tier_1']['codes'] ?? 0;
                            $tier_2_codes = $tiers['tier_2']['codes'] ?? 0;
                            
                            if ($codes_claimed < $tier_1_codes) {
                                echo "🔥 TIER 1: " . ($tier_1_codes - $codes_claimed) . " codes left at this price!";
                            } elseif ($codes_claimed < ($tier_1_codes + $tier_2_codes)) {
                                echo "⚡ TIER 2: " . (($tier_1_codes + $tier_2_codes) - $codes_claimed) . " codes left at this price!";
                            } else {
                                echo "💫 FINAL TIER: Last chance discount!";
                            }
                            ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="wcfd-discount-value">
                        <?php
                        echo $campaign->discount_type == 'percent'
                            ? $campaign->discount_value . '% OFF'
                            : 'SAVE ' . get_woocommerce_currency_symbol() . $campaign->discount_value;
                        ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="wcfd-counter">
                <div class="wcfd-codes-left">
                    <span class="wcfd-count"><?php echo $campaign->codes_remaining; ?></span>
                    <span class="wcfd-label">codes left</span>
                </div>
                <div class="wcfd-urgency">
                    Hurry! Only <?php echo $campaign->codes_remaining; ?> out of <?php echo $campaign->total_codes; ?> remaining
                </div>
            </div>

            <div class="wcfd-claim-form">
                <?php if (is_user_logged_in()): ?>
                    <button class="wcfd-claim-btn" data-campaign="<?php echo $campaign->id; ?>">
                        Claim Your Discount Now!
                    </button>
                <?php else: ?>
                    <input type="email" class="wcfd-email" placeholder="Enter your email" required />
                    <button class="wcfd-claim-btn" data-campaign="<?php echo $campaign->id; ?>">
                        Get My Code!
                    </button>
                <?php endif; ?>
            </div>

            <div class="wcfd-success" style="display:none;">
                <h4>🎉 Success!</h4>
                <p>Your discount code: <strong class="wcfd-code"></strong></p>
                <p class="wcfd-expiry"></p>
            </div>

            <div class="wcfd-error" style="display:none;"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function ajax_claim_discount()
    {
        check_ajax_referer('wcfd_nonce', 'nonce');

        $campaign_id = intval($_POST['campaign_id']);
        
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $email = $current_user->user_email;
            if (empty($email)) {
                wp_send_json_error(__('Your account does not have an email address. Please add one in your profile.', 'wc-fomo-discount'));
            }
        } else {
            $email = sanitize_email($_POST['email']);
            if (empty($email)) {
                wp_send_json_error(__('Email address is required', 'wc-fomo-discount'));
            }
        }

        // Additional validation
        if (!is_email($email)) {
            wp_send_json_error(__('Please provide a valid email address', 'wc-fomo-discount'));
        }
        
        // Rate limiting check
        $ip_address = $_SERVER['REMOTE_ADDR'];
        if ($this->is_rate_limited($ip_address, $campaign_id)) {
            wp_send_json_error(__('Too many requests. Please try again later.', 'wc-fomo-discount'));
        }

        global $wpdb;

        // Check if email already claimed this campaign (for both logged-in and non-logged-in users)
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_claimed_codes 
            WHERE campaign_id = %d AND user_email = %s AND email_verified = 1",
            $campaign_id,
            $email
        ));

        if ($existing) {
            wp_send_json_error(__('This email has already claimed a discount for this campaign', 'wc-fomo-discount'));
        }

        // For logged-in users, process immediately (trusted email)
        if (is_user_logged_in()) {
            $this->process_discount_claim($campaign_id, $email, $ip_address, true);
            return;
        }

        // For non-logged-in users, continue with email verification process

        // Check for pending verification
        $pending = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_email_verifications 
            WHERE campaign_id = %d AND user_email = %s AND verified_at IS NULL AND expires_at > NOW()",
            $campaign_id,
            $email
        ));

        if ($pending) {
            wp_send_json_error(__('A verification email was already sent. Please check your inbox and spam folder.', 'wc-fomo-discount'));
        }

        // Start verification process for non-logged-in users
        $this->send_verification_email($campaign_id, $email, $ip_address);

    }
    
    private function send_verification_email($campaign_id, $email, $ip_address) {
        global $wpdb;
        
        // Get campaign info
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_campaigns 
            WHERE id = %d AND status = 'active' AND codes_remaining > 0",
            $campaign_id
        ));

        if (!$campaign) {
            wp_send_json_error(__('Campaign not available', 'wc-fomo-discount'));
        }

        // Generate unique tokens
        $verification_token = bin2hex(random_bytes(32));
        $coupon_code = 'FOMO' . strtoupper(wp_generate_password(8, false));
        
        // Set expiry (30 minutes for verification)
        $expiry = new DateTime();
        $expiry->add(new DateInterval('PT30M'));
        
        // Store verification request
        $result = $wpdb->insert(
            $wpdb->prefix . 'wcfd_email_verifications',
            array(
                'campaign_id' => $campaign_id,
                'user_email' => $email,
                'verification_token' => $verification_token,
                'coupon_code' => $coupon_code,
                'expires_at' => $expiry->format('Y-m-d H:i:s'),
                'ip_address' => $ip_address
            )
        );
        
        if (!$result) {
            wp_send_json_error(__('Failed to process verification request', 'wc-fomo-discount'));
        }
        
        // Create verification URL
        $verify_url = add_query_arg([
            'wcfd_verify' => $verification_token,
            'campaign' => $campaign_id
        ], home_url());
        
        // Send verification email
        $subject = __('🎉 Verify your email to claim your exclusive discount!', 'wc-fomo-discount');
        $message = sprintf(
            __("Hi there!\n\n" .
            "You're just one click away from claiming your exclusive discount!\n\n" .
            "Discount: %s\n" .
            "Campaign: %s\n\n" .
            "Click here to verify your email and get your discount code:\n%s\n\n" .
            "⚠️ This link expires in 30 minutes for security.\n\n" .
            "If you didn't request this discount, you can safely ignore this email.\n\n" .
            "Happy shopping!", 'wc-fomo-discount'),
            $campaign->discount_type == 'percent'
                ? $campaign->discount_value . '% OFF'
                : 'SAVE ' . get_woocommerce_currency_symbol() . $campaign->discount_value,
            $campaign->campaign_name,
            $verify_url
        );
        
        $email_sent = wp_mail($email, $subject, $message);
        if (!$email_sent) {
            error_log('WCFD: Failed to send verification email to ' . $email);
            wp_send_json_error(__('Failed to send verification email. Please try again.', 'wc-fomo-discount'));
        }
        
        wp_send_json_success(array(
            'message' => __('Verification email sent! Please check your inbox and click the link to claim your discount.', 'wc-fomo-discount')
        ));
    }
    
    public function ajax_verify_email() {
        $token = sanitize_text_field($_GET['wcfd_verify']);
        $campaign_id = intval($_GET['campaign']);
        
        if (!$token || !$campaign_id) {
            wp_die(__('Invalid verification link', 'wc-fomo-discount'));
        }
        
        global $wpdb;
        
        // Get verification record
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_email_verifications 
            WHERE verification_token = %s AND campaign_id = %d AND verified_at IS NULL",
            $token,
            $campaign_id
        ));
        
        if (!$verification) {
            wp_die(__('Verification link is invalid or has already been used.', 'wc-fomo-discount'));
        }
        
        // Check if expired
        if (strtotime($verification->expires_at) < time()) {
            wp_die(__('Verification link has expired. Please request a new discount.', 'wc-fomo-discount'));
        }
        
        // Process the discount claim
        $this->process_discount_claim($campaign_id, $verification->user_email, $verification->ip_address, false, $verification);
    }
    
    private function process_discount_claim($campaign_id, $email, $ip_address, $email_verified = false, $verification = null) {
        global $wpdb;
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get campaign with lock
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcfd_campaigns 
                WHERE id = %d AND status = 'active' AND codes_remaining > 0
                FOR UPDATE",
                $campaign_id
            ));

            if (!$campaign) {
                $wpdb->query('ROLLBACK');
                if ($verification) {
                    wp_die(__('Sorry, this campaign is no longer available.', 'wc-fomo-discount'));
                } else {
                    wp_send_json_error(__('Campaign not available', 'wc-fomo-discount'));
                }
            }

            // Check IP limiting
            if ($campaign->enable_ip_limit) {
                $ip_claims = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}wcfd_claimed_codes 
                    WHERE campaign_id = %d AND ip_address = %s AND email_verified = 1",
                    $campaign_id, $ip_address
                ));
                
                if ($ip_claims >= $campaign->max_per_ip) {
                    $wpdb->query('ROLLBACK');
                    if ($verification) {
                        wp_die(__('Maximum discount codes already claimed from your IP address.', 'wc-fomo-discount'));
                    } else {
                        wp_send_json_error(__('Maximum discount codes already claimed from your IP address.', 'wc-fomo-discount'));
                    }
                }
            }

            // Calculate discount value (tiered or standard)
            $discount_value = $this->calculate_discount_value($campaign);
            $discount_type = $campaign->discount_type;

            // Use existing coupon code if from verification, otherwise generate new
            $coupon_code = $verification ? $verification->coupon_code : 'FOMO' . strtoupper(wp_generate_password(8, false));

            // Create WooCommerce coupon
            $coupon = new WC_Coupon();
            $coupon->set_code($coupon_code);
            $coupon->set_discount_type($discount_type == 'percent' ? 'percent' : 'fixed_cart');
            $coupon->set_amount($discount_value);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_email_restrictions(array($email));

            // Set expiry
            $expiry = new DateTime();
            $expiry->add(new DateInterval('PT' . $campaign->expiry_hours . 'H'));
            $coupon->set_date_expires($expiry->getTimestamp());

            // Apply scope restrictions
            if ($campaign->scope_type == 'products' && !empty($campaign->scope_ids)) {
                $product_ids = array_map('intval', explode(',', $campaign->scope_ids));
                $coupon->set_product_ids($product_ids);
            } elseif ($campaign->scope_type == 'categories' && !empty($campaign->scope_ids)) {
                $category_ids = array_map('intval', explode(',', $campaign->scope_ids));
                $coupon->set_product_categories($category_ids);
            }

            $coupon_id = $coupon->save();
            if (!$coupon_id) {
                $wpdb->query('ROLLBACK');
                if ($verification) {
                    wp_die(__('Failed to create discount coupon. Please contact support.', 'wc-fomo-discount'));
                } else {
                    wp_send_json_error(__('Failed to create discount coupon', 'wc-fomo-discount'));
                }
            }

            // Record in database
            $result = $wpdb->insert(
                $wpdb->prefix . 'wcfd_claimed_codes',
                array(
                    'campaign_id' => $campaign_id,
                    'user_email' => $email,
                    'user_id' => get_current_user_id() ?: null,
                    'coupon_code' => $coupon_code,
                    'expires_at' => $expiry->format('Y-m-d H:i:s'),
                    'ip_address' => $ip_address,
                    'email_verified' => $email_verified ? 1 : 0
                )
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                // Log the database error for debugging
                error_log('WCFD Database Error: ' . $wpdb->last_error);
                error_log('WCFD Failed Query: ' . $wpdb->last_query);
                
                $error_message = 'Failed to save discount code';
                if (defined('WP_DEBUG') && WP_DEBUG && !empty($wpdb->last_error)) {
                    $error_message .= ': ' . $wpdb->last_error;
                }
                
                if ($verification) {
                    wp_die(__($error_message . '. Please contact support.', 'wc-fomo-discount'));
                } else {
                    wp_send_json_error(__($error_message, 'wc-fomo-discount'));
                }
            }

            // Mark verification as complete
            if ($verification) {
                $wpdb->update(
                    $wpdb->prefix . 'wcfd_email_verifications',
                    array('verified_at' => current_time('mysql')),
                    array('id' => $verification->id)
                );
            }

            // Update codes remaining
            $result = $wpdb->update(
                $wpdb->prefix . 'wcfd_campaigns',
                array('codes_remaining' => $campaign->codes_remaining - 1),
                array('id' => $campaign_id)
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                if ($verification) {
                    wp_die(__('Failed to update campaign. Please contact support.', 'wc-fomo-discount'));
                } else {
                    wp_send_json_error(__('Failed to update campaign', 'wc-fomo-discount'));
                }
            }
            
            // Commit transaction
            $wpdb->query('COMMIT');

            // Send success email
            $subject = __('🎉 Your Exclusive Discount Code!', 'wc-fomo-discount');
            $message = sprintf(
                __("Congratulations! You've successfully claimed your exclusive discount.\n\n" .
                "Your verified discount code: %s\n" .
                "Discount: %s\n" .
                "Valid until: %s\n\n" .
                "Use it quickly before it expires!\n\n" .
                "Shop now: %s\n\n" .
                "Thank you for verifying your email!", 'wc-fomo-discount'),
                $coupon_code,
                $campaign->discount_type == 'percent'
                ? $campaign->discount_value . '% OFF'
                : 'SAVE ' . get_woocommerce_currency_symbol() . $campaign->discount_value,
                $expiry->format('M j, Y g:i A'),
                home_url()
            );

            wp_mail($email, $subject, $message);

            if ($verification) {
                // Redirect to success page for email verification
                $success_url = add_query_arg([
                    'wcfd_success' => '1',
                    'code' => $coupon_code,
                    'campaign' => $campaign->campaign_name
                ], home_url());
                wp_redirect($success_url);
                exit;
            } else {
                // AJAX response for logged-in users
                wp_send_json_success(array(
                    'code' => $coupon_code,
                    'expires_at' => $expiry->format('Y-m-d H:i:s'),
                    'codes_remaining' => $campaign->codes_remaining - 1
                ));
            }
            
        } catch (Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log('WCFD Error: ' . $e->getMessage());
            if ($verification) {
                wp_die(__('An error occurred processing your discount. Please contact support.', 'wc-fomo-discount'));
            } else {
                wp_send_json_error(__('An error occurred. Please try again.', 'wc-fomo-discount'));
            }
        }
    }

    public function ajax_get_campaign_status()
    {
        // Add nonce verification for security
        check_ajax_referer('wcfd_nonce', 'nonce');
        
        $campaign_id = intval($_POST['campaign_id']);

        global $wpdb;
        $campaign = $wpdb->get_row($wpdb->prepare(
            "SELECT codes_remaining FROM {$wpdb->prefix}wcfd_campaigns WHERE id = %d",
            $campaign_id
        ));

        if ($campaign) {
            wp_send_json_success(array(
                'codes_remaining' => $campaign->codes_remaining
            ));
        } else {
            wp_send_json_error(__('Campaign not found', 'wc-fomo-discount'));
        }
    }
    
    public function ajax_join_waitlist()
    {
        // Add nonce verification for security
        check_ajax_referer('wcfd_nonce', 'nonce');
        
        $campaign_id = isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : null;
        $email = sanitize_email($_POST['email']);
        $ip_address = $_SERVER['REMOTE_ADDR'];
        
        // Validate email
        if (empty($email) || !is_email($email)) {
            wp_send_json_error(__('Please provide a valid email address', 'wc-fomo-discount'));
        }
        
        // Rate limiting check (same as discount claiming)
        if ($this->is_rate_limited($ip_address, $campaign_id)) {
            wp_send_json_error(__('Too many requests. Please try again later.', 'wc-fomo-discount'));
        }
        
        global $wpdb;
        
        // Check if email already exists in waitlist for this campaign
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}wcfd_waitlist 
            WHERE email = %s AND (campaign_id = %d OR campaign_id IS NULL)",
            $email, $campaign_id
        ));
        
        if ($existing) {
            wp_send_json_success(array(
                'message' => __('You\'re already on our waitlist! We\'ll notify you about future deals.', 'wc-fomo-discount')
            ));
        }
        
        // Add to waitlist
        $result = $wpdb->insert(
            $wpdb->prefix . 'wcfd_waitlist',
            array(
                'email' => $email,
                'campaign_id' => $campaign_id,
                'source' => 'sold_out',
                'status' => 'active',
                'ip_address' => $ip_address
            )
        );
        
        if ($result === false) {
            error_log('WCFD Waitlist Error: ' . $wpdb->last_error);
            wp_send_json_error(__('Failed to join waitlist. Please try again.', 'wc-fomo-discount'));
        }
        
        // Send confirmation email
        $this->send_waitlist_confirmation_email($email, $campaign_id);
        
        wp_send_json_success(array(
            'message' => __('Thanks! You\'ve been added to our waitlist. We\'ll notify you about future deals.', 'wc-fomo-discount')
        ));
    }
    
    private function is_rate_limited($ip, $campaign_id) {
        global $wpdb;
        
        // Check if user made more than 5 attempts in last hour
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wcfd_claimed_codes 
            WHERE ip_address = %s 
            AND campaign_id = %d 
            AND claimed_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $ip,
            $campaign_id
        ));
        
        return $count >= 5;
    }
    
    public function cleanup_expired_codes() {
        global $wpdb;
        
        // Get expired codes that haven't been used
        $expired_codes = $wpdb->get_results(
            "SELECT coupon_code FROM {$wpdb->prefix}wcfd_claimed_codes 
            WHERE expires_at < NOW() AND used_at IS NULL"
        );
        
        foreach ($expired_codes as $code) {
            // Delete WooCommerce coupon
            $coupon = new WC_Coupon($code->coupon_code);
            if ($coupon->get_id()) {
                $coupon->delete(true);
            }
        }
        
        // Mark codes as cleaned up
        $wpdb->query(
            "UPDATE {$wpdb->prefix}wcfd_claimed_codes 
            SET used_at = NOW() 
            WHERE expires_at < NOW() AND used_at IS NULL"
        );
    }
    
    public function handle_email_export() {
        if (!isset($_GET['wcfd_export']) || !current_user_can('manage_woocommerce')) {
            return;
        }
        
        if (!wp_verify_nonce($_GET['nonce'], 'wcfd_export_emails')) {
            wp_die(__('Security check failed', 'wc-fomo-discount'));
        }
        
        $this->export_emails_csv();
    }
    
    private function export_emails_csv() {
        global $wpdb;
        
        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        
        if ($campaign_id) {
            $emails = $wpdb->get_results($wpdb->prepare(
                "SELECT cc.*, c.campaign_name 
                FROM {$wpdb->prefix}wcfd_claimed_codes cc
                LEFT JOIN {$wpdb->prefix}wcfd_campaigns c ON cc.campaign_id = c.id
                WHERE cc.campaign_id = %d
                ORDER BY cc.claimed_at DESC",
                $campaign_id
            ));
            $filename = 'fomo-emails-campaign-' . $campaign_id . '-' . date('Y-m-d');
        } else {
            $emails = $wpdb->get_results(
                "SELECT cc.*, c.campaign_name 
                FROM {$wpdb->prefix}wcfd_claimed_codes cc
                LEFT JOIN {$wpdb->prefix}wcfd_campaigns c ON cc.campaign_id = c.id
                ORDER BY cc.claimed_at DESC"
            );
            $filename = 'fomo-emails-all-' . date('Y-m-d');
        }
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename . '.csv');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'Email',
            'Campaign',
            'Coupon Code',
            'Claimed Date',
            'Expires At',
            'Used At',
            'IP Address'
        ]);
        
        // CSV data
        foreach ($emails as $email) {
            fputcsv($output, [
                $email->user_email,
                $email->campaign_name,
                $email->coupon_code,
                $email->claimed_at,
                $email->expires_at,
                $email->used_at ?: 'Not used',
                $email->ip_address
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    public function emails_page() {
        global $wpdb;
        
        // Get campaign filter
        $selected_campaign = isset($_GET['campaign']) ? intval($_GET['campaign']) : 0;
        
        // Get campaigns for filter dropdown
        $campaigns = $wpdb->get_results(
            "SELECT id, campaign_name FROM {$wpdb->prefix}wcfd_campaigns ORDER BY campaign_name"
        );
        
        // Build query
        $where = '';
        $params = [];
        if ($selected_campaign) {
            $where = 'WHERE cc.campaign_id = %d';
            $params[] = $selected_campaign;
        }
        
        // Get emails with pagination
        $per_page = 50;
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($page - 1) * $per_page;
        
        $total_query = "SELECT COUNT(*) FROM {$wpdb->prefix}wcfd_claimed_codes cc $where";
        $total = $wpdb->get_var($wpdb->prepare($total_query, $params));
        $total_pages = ceil($total / $per_page);
        
        $emails_query = "SELECT cc.*, c.campaign_name 
            FROM {$wpdb->prefix}wcfd_claimed_codes cc
            LEFT JOIN {$wpdb->prefix}wcfd_campaigns c ON cc.campaign_id = c.id
            $where
            ORDER BY cc.claimed_at DESC
            LIMIT %d OFFSET %d";
        
        $params[] = $per_page;
        $params[] = $offset;
        
        $emails = $wpdb->get_results($wpdb->prepare($emails_query, $params));
        
        // Get statistics
        $stats_where = $selected_campaign ? "WHERE campaign_id = $selected_campaign" : "";
        $stats = $wpdb->get_row(
            "SELECT 
                COUNT(*) as total_emails,
                COUNT(DISTINCT user_email) as unique_emails,
                COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_codes,
                COUNT(CASE WHEN expires_at < NOW() AND used_at IS NULL THEN 1 END) as expired_unused
            FROM {$wpdb->prefix}wcfd_claimed_codes $stats_where"
        );
        
        ?>
        <div class="wrap">
            <h1><?php _e('Email Leads', 'wc-fomo-discount'); ?></h1>
            
            <!-- Statistics Cards -->
            <div class="wcfd-stats-grid">
                <div class="wcfd-stat-card">
                    <h3><?php echo number_format($stats->total_emails); ?></h3>
                    <p><?php _e('Total Claims', 'wc-fomo-discount'); ?></p>
                </div>
                <div class="wcfd-stat-card">
                    <h3><?php echo number_format($stats->unique_emails); ?></h3>
                    <p><?php _e('Unique Emails', 'wc-fomo-discount'); ?></p>
                </div>
                <div class="wcfd-stat-card">
                    <h3><?php echo number_format($stats->used_codes); ?></h3>
                    <p><?php _e('Codes Used', 'wc-fomo-discount'); ?></p>
                </div>
                <div class="wcfd-stat-card">
                    <h3><?php echo $stats->total_emails > 0 ? round(($stats->used_codes / $stats->total_emails) * 100, 1) : 0; ?>%</h3>
                    <p><?php _e('Conversion Rate', 'wc-fomo-discount'); ?></p>
                </div>
            </div>
            
            <!-- Filters and Export -->
            <div class="wcfd-email-controls">
                <div class="wcfd-filters">
                    <form method="get">
                        <input type="hidden" name="page" value="wcfd-emails">
                        <select name="campaign" onchange="this.form.submit()">
                            <option value=""><?php _e('All Campaigns', 'wc-fomo-discount'); ?></option>
                            <?php foreach ($campaigns as $campaign): ?>
                                <option value="<?php echo $campaign->id; ?>" <?php selected($selected_campaign, $campaign->id); ?>>
                                    <?php echo esc_html($campaign->campaign_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                </div>
                
                <div class="wcfd-export-buttons">
                    <a href="<?php echo wp_nonce_url(
                        add_query_arg([
                            'wcfd_export' => '1',
                            'campaign_id' => $selected_campaign
                        ], admin_url('admin.php?page=wcfd-emails')), 
                        'wcfd_export_emails', 
                        'nonce'
                    ); ?>" class="button button-primary">
                        📊 <?php _e('Export CSV', 'wc-fomo-discount'); ?>
                    </a>
                </div>
            </div>
            
            <!-- Emails Table -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Email', 'wc-fomo-discount'); ?></th>
                        <th><?php _e('Campaign', 'wc-fomo-discount'); ?></th>
                        <th><?php _e('Code', 'wc-fomo-discount'); ?></th>
                        <th><?php _e('Claimed', 'wc-fomo-discount'); ?></th>
                        <th><?php _e('Status', 'wc-fomo-discount'); ?></th>
                        <th><?php _e('IP Address', 'wc-fomo-discount'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($emails)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 40px;">
                                <?php _e('No emails captured yet.', 'wc-fomo-discount'); ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($emails as $email): ?>
                            <tr>
                                <td><strong><?php echo esc_html($email->user_email); ?></strong></td>
                                <td><?php echo esc_html($email->campaign_name); ?></td>
                                <td><code><?php echo esc_html($email->coupon_code); ?></code></td>
                                <td><?php echo date('M j, Y g:i A', strtotime($email->claimed_at)); ?></td>
                                <td>
                                    <?php if ($email->used_at): ?>
                                        <span class="wcfd-status used">✅ <?php _e('Used', 'wc-fomo-discount'); ?></span>
                                    <?php elseif (strtotime($email->expires_at) < time()): ?>
                                        <span class="wcfd-status expired">❌ <?php _e('Expired', 'wc-fomo-discount'); ?></span>
                                    <?php else: ?>
                                        <span class="wcfd-status active">⏳ <?php _e('Active', 'wc-fomo-discount'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($email->ip_address); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num"><?php printf(__('%s items', 'wc-fomo-discount'), number_format($total)); ?></span>
                        <?php
                        $page_links = paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ]);
                        echo $page_links;
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
        .wcfd-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        .wcfd-stat-card {
            background: white;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .wcfd-stat-card h3 {
            font-size: 32px;
            margin: 0;
            color: #0073aa;
        }
        
        .wcfd-stat-card p {
            margin: 5px 0 0 0;
            color: #666;
            font-size: 14px;
        }
        
        .wcfd-email-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 20px 0;
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
        }
        
        .wcfd-status {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
        }
        
        .wcfd-status.used { background: #d4edda; color: #155724; }
        .wcfd-status.expired { background: #f8d7da; color: #721c24; }
        .wcfd-status.active { background: #fff3cd; color: #856404; }
        </style>
        <?php
    }
    
    public function email_settings_page() {
        // Handle form submission
        if (isset($_POST['wcfd_save_email_settings'])) {
            $this->save_email_settings($_POST);
        }
        
        // Get current settings
        $smtp_settings = get_option('wcfd_smtp_settings', array(
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'username' => '',
            'password' => '',
            'encryption' => 'tls',
            'from_email' => get_option('admin_email'),
            'from_name' => get_bloginfo('name')
        ));
        // Check current email method
        $using_smtp = !empty($smtp_settings['enabled']) && !empty($smtp_settings['host']) && !empty($smtp_settings['username']);
        ?>
        <div class="wrap">
            <h1>Email Settings</h1>
            <p>Configure SMTP settings to ensure reliable email delivery and prevent emails from being flagged as spam.</p>
            
            <!-- Current Status -->
            <div class="wcfd-email-status" style="margin: 20px 0; padding: 15px; border-radius: 5px; <?php echo $using_smtp ? 'background: #d4edda; border: 1px solid #c3e6cb;' : 'background: #fff3cd; border: 1px solid #ffeaa7;'; ?>">
                <h3 style="margin: 0 0 10px 0; color: <?php echo $using_smtp ? '#155724' : '#856404'; ?>;">
                    <?php echo $using_smtp ? '✅ Current Status: SMTP Configured' : '⚠️ Current Status: Using WordPress Default Mail'; ?>
                </h3>
                <p style="margin: 0; color: <?php echo $using_smtp ? '#155724' : '#856404'; ?>;">
                    <?php if ($using_smtp): ?>
                        Emails are being sent via SMTP (<?php echo esc_html($smtp_settings['host']); ?>) for reliable delivery.
                    <?php else: ?>
                        Emails are being sent using WordPress's default mail() function. <strong>This may result in emails being flagged as spam or not delivered.</strong> Configure SMTP below for better deliverability.
                    <?php endif; ?>
                </p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wcfd_save_email_settings', 'wcfd_email_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable SMTP</th>
                        <td>
                            <label>
                                <input type="checkbox" name="smtp_enabled" value="1" <?php checked($smtp_settings['enabled']); ?> />
                                Use SMTP for sending emails (recommended)
                            </label>
                            <p class="description">Enable this to use SMTP instead of PHP's mail() function for better deliverability.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Host</th>
                        <td>
                            <input type="text" name="smtp_host" value="<?php echo esc_attr($smtp_settings['host']); ?>" class="regular-text" placeholder="smtp.gmail.com" />
                            <p class="description">Your SMTP server hostname (e.g., smtp.gmail.com, smtp.mailgun.org)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Port</th>
                        <td>
                            <input type="number" name="smtp_port" value="<?php echo esc_attr($smtp_settings['port']); ?>" class="small-text" />
                            <p class="description">Common ports: 587 (TLS), 465 (SSL), 25 (unsecured)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Encryption</th>
                        <td>
                            <select name="smtp_encryption">
                                <option value="none" <?php selected($smtp_settings['encryption'], 'none'); ?>>None</option>
                                <option value="ssl" <?php selected($smtp_settings['encryption'], 'ssl'); ?>>SSL</option>
                                <option value="tls" <?php selected($smtp_settings['encryption'], 'tls'); ?>>TLS (recommended)</option>
                            </select>
                            <p class="description">Choose the encryption method supported by your SMTP provider.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Username</th>
                        <td>
                            <input type="text" name="smtp_username" value="<?php echo esc_attr($smtp_settings['username']); ?>" class="regular-text" placeholder="your-email@gmail.com" />
                            <p class="description">Your SMTP username (usually your email address)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">SMTP Password</th>
                        <td>
                            <input type="password" name="smtp_password" value="<?php echo esc_attr($smtp_settings['password']); ?>" class="regular-text" placeholder="<?php echo $smtp_settings['password'] ? '••••••••' : 'Your SMTP password'; ?>" />
                            <p class="description">Your SMTP password or app-specific password</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">From Email</th>
                        <td>
                            <input type="email" name="from_email" value="<?php echo esc_attr($smtp_settings['from_email']); ?>" class="regular-text" />
                            <p class="description">Email address that emails will be sent from</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">From Name</th>
                        <td>
                            <input type="text" name="from_name" value="<?php echo esc_attr($smtp_settings['from_name']); ?>" class="regular-text" />
                            <p class="description">Name that will appear in the "From" field</p>
                        </td>
                    </tr>
                </table>
                
                <div class="wcfd-smtp-providers" style="margin-top: 20px; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                    <h3>Popular SMTP Provider Settings</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
                        <div>
                            <strong>Gmail:</strong><br>
                            Host: smtp.gmail.com<br>
                            Port: 587<br>
                            Encryption: TLS<br>
                            <em>Note: Use App Password, not regular password</em>
                        </div>
                        <div>
                            <strong>Outlook/Hotmail:</strong><br>
                            Host: smtp-mail.outlook.com<br>
                            Port: 587<br>
                            Encryption: TLS
                        </div>
                        <div>
                            <strong>SendGrid:</strong><br>
                            Host: smtp.sendgrid.net<br>
                            Port: 587<br>
                            Encryption: TLS<br>
                            Username: apikey
                        </div>
                        <div>
                            <strong>Mailgun:</strong><br>
                            Host: smtp.mailgun.org<br>
                            Port: 587<br>
                            Encryption: TLS
                        </div>
                    </div>
                </div>
                
                <p class="submit">
                    <input type="submit" name="wcfd_save_email_settings" class="button-primary" value="Save Email Settings" />
                    <input type="submit" name="wcfd_test_email" class="button-secondary" value="Send Test Email" style="margin-left: 10px;" />
                </p>
            </form>
        </div>
        <?php
    }
    
    public function save_email_settings($post_data) {
        // Verify nonce
        if (!wp_verify_nonce($post_data['wcfd_email_nonce'], 'wcfd_save_email_settings')) {
            wp_die(__('Security check failed', 'wc-fomo-discount'));
        }
        
        // Handle test email
        if (isset($post_data['wcfd_test_email'])) {
            $this->send_test_email();
            return;
        }
        
        // Save SMTP settings
        $smtp_settings = array(
            'enabled' => isset($post_data['smtp_enabled']),
            'host' => sanitize_text_field($post_data['smtp_host']),
            'port' => intval($post_data['smtp_port']),
            'username' => sanitize_text_field($post_data['smtp_username']),
            'password' => sanitize_text_field($post_data['smtp_password']),
            'encryption' => sanitize_text_field($post_data['smtp_encryption']),
            'from_email' => sanitize_email($post_data['from_email']),
            'from_name' => sanitize_text_field($post_data['from_name'])
        );
        
        update_option('wcfd_smtp_settings', $smtp_settings);
        
        echo '<div class="notice notice-success"><p>Email settings saved successfully!</p></div>';
    }
    
    public function configure_smtp($phpmailer) {
        $smtp_settings = get_option('wcfd_smtp_settings', array());
        
        // Only configure SMTP if enabled and required fields are filled
        if (empty($smtp_settings['enabled']) || empty($smtp_settings['host']) || empty($smtp_settings['username'])) {
            // Show admin notice about using WP mail
            add_action('admin_notices', array($this, 'show_wp_mail_notice'));
            return;
        }
        
        $phpmailer->isSMTP();
        $phpmailer->Host = $smtp_settings['host'];
        $phpmailer->Port = $smtp_settings['port'];
        $phpmailer->Username = $smtp_settings['username'];
        $phpmailer->Password = $smtp_settings['password'];
        $phpmailer->SMTPAuth = true;
        
        // Set encryption
        if ($smtp_settings['encryption'] === 'ssl') {
            $phpmailer->SMTPSecure = 'ssl';
        } elseif ($smtp_settings['encryption'] === 'tls') {
            $phpmailer->SMTPSecure = 'tls';
        }
        
        // Set from email and name
        if (!empty($smtp_settings['from_email'])) {
            $phpmailer->setFrom($smtp_settings['from_email'], $smtp_settings['from_name']);
        }
        
        // Enable debug for testing (only in wp-config.php debug mode)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 1;
        }
    }
    
    public function send_test_email() {
        $to = get_option('admin_email');
        $subject = 'FOMO Discount Generator - Test Email';
        $smtp_settings = get_option('wcfd_smtp_settings', array());
        $using_smtp = !empty($smtp_settings['enabled']) && !empty($smtp_settings['host']);
        
        $message = "
        <h2>Test Email from FOMO Discount Generator</h2>
        <p>If you receive this email, your email configuration is working!</p>
        <p><strong>Email Method:</strong> " . ($using_smtp ? 'SMTP' : 'WordPress default mail()') . "</p>
        <p><strong>Sent:</strong> " . date('Y-m-d H:i:s') . "</p>
        <p><strong>From:</strong> WooCommerce FOMO Discount Generator</p>
        " . (!$using_smtp ? '<p style=\"color: #d63384;\"><strong>⚠️ Warning:</strong> Using WordPress default mail() function. Emails may be flagged as spam. Configure SMTP for better deliverability.</p>' : '') . "
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        if (wp_mail($to, $subject, $message, $headers)) {
            $method = $using_smtp ? 'SMTP' : 'WordPress default mail()';
            echo '<div class="notice notice-success"><p>Test email sent successfully to ' . esc_html($to) . ' using ' . $method . '! Check your inbox.</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>Failed to send test email. Please check your email configuration.</p></div>';
        }
    }
    
    private function send_waitlist_confirmation_email($email, $campaign_id) {
        global $wpdb;
        
        // Get campaign info if available
        $campaign_name = 'Future Deals';
        if ($campaign_id) {
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT campaign_name FROM {$wpdb->prefix}wcfd_campaigns WHERE id = %d",
                $campaign_id
            ));
            if ($campaign) {
                $campaign_name = $campaign->campaign_name;
            }
        }
        
        $subject = 'You\'re on our waitlist! 🎉';
        $message = "
        <h2>Welcome to our exclusive waitlist!</h2>
        <p>Hi there! 👋</p>
        <p>Thanks for joining our waitlist for <strong>$campaign_name</strong>. You'll be the first to know when we have new discount codes available!</p>
        
        <div style='background: #f9f9f9; padding: 20px; border-radius: 8px; margin: 20px 0;'>
            <h3>🔔 What happens next?</h3>
            <ul style='line-height: 1.6;'>
                <li>📧 We'll email you when new discounts are available</li>
                <li>⚡ You'll get early access before anyone else</li>
                <li>🎁 Exclusive deals just for waitlist members</li>
            </ul>
        </div>
        
        <p>Don't worry - we won't spam you. We only send notifications about amazing deals!</p>
        
        <p>Thanks for your patience,<br>
        The " . get_bloginfo('name') . " Team</p>
        
        <hr style='margin: 30px 0;'>
        <small style='color: #666;'>
            Don't want these emails? <a href='#' style='color: #666;'>Unsubscribe here</a>
        </small>
        ";
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($email, $subject, $message, $headers);
    }
    
    public function show_wp_mail_notice() {
        // Only show on FOMO discount pages and avoid duplicate notices
        if (!isset($_GET['page']) || strpos($_GET['page'], 'wcfd') === false) {
            return;
        }
        
        // Check if we already showed this notice recently
        if (get_transient('wcfd_wp_mail_notice_shown')) {
            return;
        }
        
        set_transient('wcfd_wp_mail_notice_shown', true, HOUR_IN_SECONDS);
        
        echo '<div class="notice notice-warning is-dismissible">
            <p><strong>⚠️ Email Delivery Warning:</strong> FOMO Discount Generator is using WordPress\'s default mail() function. 
            Emails may be flagged as spam or not delivered reliably. 
            <a href="' . admin_url('admin.php?page=wcfd-email-settings') . '">Configure SMTP settings</a> for better deliverability.</p>
        </div>';
    }
    
    private function calculate_discount_value($campaign) {
        // If tiers are not enabled, return standard discount value
        if (!$campaign->enable_tiers || empty($campaign->tier_config)) {
            return $campaign->discount_value;
        }
        
        // Parse tier configuration
        $tiers = json_decode($campaign->tier_config, true);
        if (!$tiers) {
            return $campaign->discount_value;
        }
        
        // Calculate how many codes have been claimed
        $total_codes = $campaign->total_codes;
        $codes_remaining = $campaign->codes_remaining;
        $codes_claimed = $total_codes - $codes_remaining;
        
        // Determine which tier applies
        $tier_1_codes = $tiers['tier_1']['codes'] ?? 0;
        $tier_2_codes = $tiers['tier_2']['codes'] ?? 0;
        
        if ($codes_claimed < $tier_1_codes) {
            // Still in tier 1 (highest discount)
            return $tiers['tier_1']['discount'] ?? $campaign->discount_value;
        } elseif ($codes_claimed < ($tier_1_codes + $tier_2_codes)) {
            // In tier 2 (medium discount)
            return $tiers['tier_2']['discount'] ?? $campaign->discount_value;
        } else {
            // In tier 3 (lowest discount)
            return $tiers['tier_3']['discount'] ?? $campaign->discount_value;
        }
    }
}

// Initialize plugin
WC_FOMO_Discount_Generator::get_instance();