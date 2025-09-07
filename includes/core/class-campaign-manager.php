<?php
/**
 * Campaign Management System
 * 
 * @package WC_Fomo_Discount
 * @subpackage Core
 * @since 2.0.0
 */

namespace WCFD\Core;

use WCFD\Database\Database_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Campaign Manager class for handling all campaign operations
 */
class Campaign_Manager {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var Database_Manager
     */
    private $db_manager;
    
    /**
     * @var Validator
     */
    private $validator;
    
    /**
     * Constructor
     * 
     * @param Logger $logger
     * @param Database_Manager $db_manager
     * @param Validator $validator
     */
    public function __construct(Logger $logger, Database_Manager $db_manager, Validator $validator) {
        $this->logger = $logger;
        $this->db_manager = $db_manager;
        $this->validator = $validator;
    }
    
    /**
     * Create a new campaign
     * 
     * @param array $data Campaign data
     * @return int|WP_Error Campaign ID or error
     */
    public function create_campaign($data) {
        global $wpdb;
        
        $this->logger->start_timer('create_campaign');
        
        // Validate input
        $validated = $this->validate_campaign_data($data);
        if (is_wp_error($validated)) {
            return $validated;
        }
        
        // Prepare data
        $insert_data = [
            'campaign_name' => sanitize_text_field($data['campaign_name']),
            'discount_type' => $data['discount_type'],
            'discount_value' => floatval($data['discount_value']),
            'total_codes' => intval($data['total_codes']),
            'codes_remaining' => intval($data['total_codes']),
            'expiry_hours' => intval($data['expiry_hours'] ?? 24),
            'enable_ip_limit' => isset($data['enable_ip_limit']) ? 1 : 0,
            'max_per_ip' => intval($data['max_per_ip'] ?? 1),
            'scope_type' => $data['scope_type'] ?? 'all',
            'status' => 'active'
        ];
        
        // Handle tiered discounts
        if (!empty($data['tiered_discounts'])) {
            $insert_data['tiered_discounts'] = json_encode($data['tiered_discounts']);
        }
        
        // Handle scope IDs
        if (!empty($data['scope_ids'])) {
            $insert_data['scope_ids'] = implode(',', array_map('intval', $data['scope_ids']));
        }
        
        // Insert campaign
        $result = $wpdb->insert(
            $this->db_manager->get_table('campaigns'),
            $insert_data,
            [
                '%s', '%s', '%f', '%d', '%d', '%d', '%d', '%d', '%s', '%s'
            ]
        );
        
        if ($result === false) {
            $this->logger->error('Failed to create campaign', [
                'error' => $wpdb->last_error,
                'data' => $insert_data
            ]);
            return new \WP_Error('db_error', __('Failed to create campaign', 'wc-fomo-discount'));
        }
        
        $campaign_id = $wpdb->insert_id;
        
        $this->logger->end_timer('create_campaign');
        $this->logger->info('Campaign created', ['campaign_id' => $campaign_id]);
        
        // Trigger action
        do_action('wcfd_campaign_created', $campaign_id, $insert_data);
        
        return $campaign_id;
    }
    
    /**
     * Update campaign
     * 
     * @param int $campaign_id
     * @param array $data
     * @return bool|WP_Error
     */
    public function update_campaign($campaign_id, $data) {
        global $wpdb;
        
        $this->logger->start_timer('update_campaign');
        
        // Validate campaign exists
        $campaign = $this->get_campaign($campaign_id);
        if (!$campaign) {
            return new \WP_Error('not_found', __('Campaign not found', 'wc-fomo-discount'));
        }
        
        // Store old values for audit
        $old_values = (array) $campaign;
        
        // Validate input
        $validated = $this->validate_campaign_data($data, true);
        if (is_wp_error($validated)) {
            return $validated;
        }
        
        // Prepare update data
        $update_data = [];
        $format = [];
        
        $allowed_fields = [
            'campaign_name' => '%s',
            'discount_type' => '%s',
            'discount_value' => '%f',
            'total_codes' => '%d',
            'codes_remaining' => '%d',
            'expiry_hours' => '%d',
            'enable_ip_limit' => '%d',
            'max_per_ip' => '%d',
            'scope_type' => '%s',
            'status' => '%s'
        ];
        
        foreach ($allowed_fields as $field => $field_format) {
            if (isset($data[$field])) {
                $update_data[$field] = $data[$field];
                $format[] = $field_format;
            }
        }
        
        // Handle special fields
        if (isset($data['tiered_discounts'])) {
            $update_data['tiered_discounts'] = json_encode($data['tiered_discounts']);
            $format[] = '%s';
        }
        
        if (isset($data['scope_ids'])) {
            $update_data['scope_ids'] = implode(',', array_map('intval', $data['scope_ids']));
            $format[] = '%s';
        }
        
        // Update campaign
        $result = $wpdb->update(
            $this->db_manager->get_table('campaigns'),
            $update_data,
            ['id' => $campaign_id],
            $format,
            ['%d']
        );
        
        if ($result === false) {
            $this->logger->error('Failed to update campaign', [
                'campaign_id' => $campaign_id,
                'error' => $wpdb->last_error
            ]);
            return new \WP_Error('db_error', __('Failed to update campaign', 'wc-fomo-discount'));
        }
        
        $this->logger->end_timer('update_campaign');
        $this->logger->info('Campaign updated', [
            'campaign_id' => $campaign_id,
            'changes' => array_keys($update_data)
        ]);
        
        // Log audit
        $this->log_audit('campaign_updated', 'campaign', $campaign_id, $old_values, $update_data);
        
        // Trigger action
        do_action('wcfd_campaign_updated', $campaign_id, $update_data, $old_values);
        
        return true;
    }
    
    /**
     * Get campaign by ID
     * 
     * @param int $campaign_id
     * @return object|null
     */
    public function get_campaign($campaign_id) {
        global $wpdb;
        
        $campaign = wp_cache_get("campaign_$campaign_id", 'wcfd');
        
        if ($campaign === false) {
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db_manager->get_table('campaigns')} WHERE id = %d",
                $campaign_id
            ));
            
            if ($campaign) {
                // Decode JSON fields
                if (!empty($campaign->tiered_discounts)) {
                    $campaign->tiered_discounts = json_decode($campaign->tiered_discounts, true);
                }
                
                if (!empty($campaign->scope_ids)) {
                    $campaign->scope_ids = explode(',', $campaign->scope_ids);
                }
                
                wp_cache_set("campaign_$campaign_id", $campaign, 'wcfd', 300);
            }
        }
        
        return $campaign;
    }
    
    /**
     * Get active campaigns
     * 
     * @param array $args Query arguments
     * @return array
     */
    public function get_active_campaigns($args = []) {
        global $wpdb;
        
        $defaults = [
            'limit' => 10,
            'offset' => 0,
            'orderby' => 'created_at',
            'order' => 'DESC'
        ];
        
        $args = wp_parse_args($args, $defaults);
        
        $cache_key = 'active_campaigns_' . md5(serialize($args));
        $campaigns = wp_cache_get($cache_key, 'wcfd');
        
        if ($campaigns === false) {
            $sql = $wpdb->prepare(
                "SELECT * FROM {$this->db_manager->get_table('campaigns')} 
                WHERE status = 'active' AND codes_remaining > 0 
                ORDER BY {$args['orderby']} {$args['order']} 
                LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            );
            
            $campaigns = $wpdb->get_results($sql);
            
            // Process campaigns
            foreach ($campaigns as &$campaign) {
                if (!empty($campaign->tiered_discounts)) {
                    $campaign->tiered_discounts = json_decode($campaign->tiered_discounts, true);
                }
                
                if (!empty($campaign->scope_ids)) {
                    $campaign->scope_ids = explode(',', $campaign->scope_ids);
                }
            }
            
            wp_cache_set($cache_key, $campaigns, 'wcfd', 300);
        }
        
        return $campaigns;
    }
    
    /**
     * Claim discount code
     * 
     * @param int $campaign_id
     * @param string $email
     * @param string $ip_address
     * @param bool $email_verified
     * @return array|WP_Error
     */
    public function claim_discount($campaign_id, $email, $ip_address, $email_verified = false) {
        global $wpdb;
        
        $this->logger->start_timer('claim_discount');
        
        // Start transaction
        $wpdb->query('START TRANSACTION');
        
        try {
            // Get campaign with lock
            $campaign = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->db_manager->get_table('campaigns')} 
                WHERE id = %d AND status = 'active' AND codes_remaining > 0
                FOR UPDATE",
                $campaign_id
            ));
            
            if (!$campaign) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('campaign_unavailable', __('Campaign not available', 'wc-fomo-discount'));
            }
            
            // Check if already claimed
            $existing = $this->has_claimed_code($campaign_id, $email);
            if ($existing) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('already_claimed', __('You have already claimed a code for this campaign', 'wc-fomo-discount'));
            }
            
            // Check IP limits
            if ($campaign->enable_ip_limit) {
                $ip_claims = $this->get_ip_claim_count($campaign_id, $ip_address);
                if ($ip_claims >= $campaign->max_per_ip) {
                    $wpdb->query('ROLLBACK');
                    return new \WP_Error('ip_limit', __('Maximum codes claimed from your IP address', 'wc-fomo-discount'));
                }
            }
            
            // Generate coupon code
            $coupon_code = $this->generate_coupon_code();
            
            // Create WooCommerce coupon
            $coupon_id = $this->create_wc_coupon($coupon_code, $campaign, $email);
            if (is_wp_error($coupon_id)) {
                $wpdb->query('ROLLBACK');
                return $coupon_id;
            }
            
            // Calculate expiry
            $expiry = new \DateTime();
            $expiry->add(new \DateInterval('PT' . $campaign->expiry_hours . 'H'));
            
            // Record claim
            $result = $wpdb->insert(
                $this->db_manager->get_table('claimed_codes'),
                [
                    'campaign_id' => $campaign_id,
                    'user_email' => $email,
                    'user_id' => get_current_user_id() ?: null,
                    'coupon_code' => $coupon_code,
                    'expires_at' => $expiry->format('Y-m-d H:i:s'),
                    'ip_address' => $ip_address,
                    'email_verified' => $email_verified ? 1 : 0
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s', '%d']
            );
            
            if ($result === false) {
                $wpdb->query('ROLLBACK');
                return new \WP_Error('db_error', __('Failed to save discount code', 'wc-fomo-discount'));
            }
            
            // Update codes remaining
            $wpdb->update(
                $this->db_manager->get_table('campaigns'),
                ['codes_remaining' => $campaign->codes_remaining - 1],
                ['id' => $campaign_id]
            );
            
            // Commit transaction
            $wpdb->query('COMMIT');
            
            $this->logger->end_timer('claim_discount');
            $this->logger->info('Discount claimed', [
                'campaign_id' => $campaign_id,
                'email' => $email,
                'coupon_code' => $coupon_code
            ]);
            
            // Clear cache
            wp_cache_delete("campaign_$campaign_id", 'wcfd');
            
            // Trigger action
            do_action('wcfd_discount_claimed', $campaign_id, $email, $coupon_code);
            
            return [
                'code' => $coupon_code,
                'expires_at' => $expiry->format('Y-m-d H:i:s'),
                'codes_remaining' => $campaign->codes_remaining - 1
            ];
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            $this->logger->error('Failed to claim discount', [
                'campaign_id' => $campaign_id,
                'error' => $e->getMessage()
            ]);
            return new \WP_Error('claim_error', __('An error occurred. Please try again.', 'wc-fomo-discount'));
        }
    }
    
    /**
     * Validate campaign data
     * 
     * @param array $data
     * @param bool $is_update
     * @return bool|WP_Error
     */
    private function validate_campaign_data($data, $is_update = false) {
        $errors = [];
        
        // Campaign name
        if (!$is_update || isset($data['campaign_name'])) {
            if (empty($data['campaign_name'])) {
                $errors[] = __('Campaign name is required', 'wc-fomo-discount');
            }
        }
        
        // Discount validation
        if (!$is_update || isset($data['discount_value']) || isset($data['discount_type'])) {
            $type = $data['discount_type'] ?? 'percent';
            $value = $data['discount_value'] ?? 0;
            
            $validated = $this->validator->validate_discount_value($value, $type);
            if (!$validated) {
                $errors[] = $this->validator->get_error_message();
            }
        }
        
        // Total codes
        if (!$is_update || isset($data['total_codes'])) {
            if ($data['total_codes'] < 1 || $data['total_codes'] > 10000) {
                $errors[] = __('Total codes must be between 1 and 10000', 'wc-fomo-discount');
            }
        }
        
        if (!empty($errors)) {
            return new \WP_Error('validation_error', implode('. ', $errors));
        }
        
        return true;
    }
    
    /**
     * Generate unique coupon code
     * 
     * @return string
     */
    private function generate_coupon_code() {
        return 'FOMO' . strtoupper(wp_generate_password(8, false));
    }
    
    /**
     * Create WooCommerce coupon
     * 
     * @param string $code
     * @param object $campaign
     * @param string $email
     * @return int|WP_Error
     */
    private function create_wc_coupon($code, $campaign, $email) {
        try {
            $coupon = new \WC_Coupon();
            
            $coupon->set_code($code);
            $coupon->set_discount_type($campaign->discount_type == 'percent' ? 'percent' : 'fixed_cart');
            $coupon->set_amount($campaign->discount_value);
            $coupon->set_individual_use(true);
            $coupon->set_usage_limit(1);
            $coupon->set_usage_limit_per_user(1);
            $coupon->set_email_restrictions([$email]);
            
            // Set expiry
            $expiry = new \DateTime();
            $expiry->add(new \DateInterval('PT' . $campaign->expiry_hours . 'H'));
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
                return new \WP_Error('coupon_error', __('Failed to create coupon', 'wc-fomo-discount'));
            }
            
            return $coupon_id;
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to create WooCommerce coupon', [
                'error' => $e->getMessage(),
                'code' => $code
            ]);
            return new \WP_Error('coupon_error', $e->getMessage());
        }
    }
    
    /**
     * Check if user has already claimed code
     * 
     * @param int $campaign_id
     * @param string $email
     * @return bool
     */
    private function has_claimed_code($campaign_id, $email) {
        global $wpdb;
        
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} 
            WHERE campaign_id = %d AND user_email = %s AND email_verified = 1",
            $campaign_id,
            $email
        ));
        
        return $exists > 0;
    }
    
    /**
     * Get IP claim count
     * 
     * @param int $campaign_id
     * @param string $ip_address
     * @return int
     */
    private function get_ip_claim_count($campaign_id, $ip_address) {
        global $wpdb;
        
        return $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->db_manager->get_table('claimed_codes')} 
            WHERE campaign_id = %d AND ip_address = %s AND email_verified = 1",
            $campaign_id,
            $ip_address
        ));
    }
    
    /**
     * Log audit entry
     * 
     * @param string $action
     * @param string $object_type
     * @param int $object_id
     * @param mixed $old_value
     * @param mixed $new_value
     */
    private function log_audit($action, $object_type, $object_id, $old_value = null, $new_value = null) {
        global $wpdb;
        
        $wpdb->insert(
            $this->db_manager->get_table('audit_log'),
            [
                'user_id' => get_current_user_id(),
                'action' => $action,
                'object_type' => $object_type,
                'object_id' => $object_id,
                'old_value' => json_encode($old_value),
                'new_value' => json_encode($new_value),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ],
            ['%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s']
        );
    }
}