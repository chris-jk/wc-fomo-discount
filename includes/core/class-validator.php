<?php
/**
 * Input Validation System
 * 
 * @package WC_Fomo_Discount
 * @subpackage Core
 * @since 2.0.0
 */

namespace WCFD\Core;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validator class for comprehensive input validation
 */
class Validator {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var array Validation rules
     */
    private $rules = [];
    
    /**
     * @var array Validation errors
     */
    private $errors = [];
    
    /**
     * Constructor
     * 
     * @param Logger $logger
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Validate email with comprehensive checks
     * 
     * @param string $email
     * @return bool
     */
    public function validate_email($email) {
        // Basic validation
        if (empty($email)) {
            $this->add_error('email', __('Email is required', 'wc-fomo-discount'));
            return false;
        }
        
        // Sanitize
        $email = sanitize_email($email);
        
        // WordPress validation
        if (!is_email($email)) {
            $this->add_error('email', __('Invalid email format', 'wc-fomo-discount'));
            return false;
        }
        
        // Additional checks
        if (strlen($email) > 254) {
            $this->add_error('email', __('Email address is too long', 'wc-fomo-discount'));
            return false;
        }
        
        // Check for disposable email domains (optional)
        if ($this->is_disposable_email($email)) {
            $this->add_error('email', __('Disposable email addresses are not allowed', 'wc-fomo-discount'));
            $this->logger->warning('Disposable email attempt', ['email' => $email]);
            return false;
        }
        
        // DNS validation (optional, can be slow)
        if (apply_filters('wcfd_validate_email_dns', false)) {
            $domain = substr(strrchr($email, "@"), 1);
            if (!checkdnsrr($domain, 'MX')) {
                $this->add_error('email', __('Email domain does not exist', 'wc-fomo-discount'));
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Validate campaign ID
     * 
     * @param mixed $campaign_id
     * @return int|false
     */
    public function validate_campaign_id($campaign_id) {
        // Check if empty
        if (empty($campaign_id)) {
            $this->add_error('campaign_id', __('Campaign ID is required', 'wc-fomo-discount'));
            return false;
        }
        
        // Convert to integer
        $campaign_id = intval($campaign_id);
        
        // Range check
        if ($campaign_id <= 0 || $campaign_id > PHP_INT_MAX) {
            $this->add_error('campaign_id', __('Invalid campaign ID', 'wc-fomo-discount'));
            return false;
        }
        
        return $campaign_id;
    }
    
    /**
     * Validate discount value
     * 
     * @param mixed $value
     * @param string $type 'percent' or 'fixed'
     * @return float|false
     */
    public function validate_discount_value($value, $type) {
        // Check if empty
        if ($value === '' || $value === null) {
            $this->add_error('discount_value', __('Discount value is required', 'wc-fomo-discount'));
            return false;
        }
        
        // Convert to float
        $value = floatval($value);
        
        // Check for negative values
        if ($value < 0) {
            $this->add_error('discount_value', __('Discount value cannot be negative', 'wc-fomo-discount'));
            return false;
        }
        
        // Type-specific validation
        if ($type === 'percent') {
            if ($value > 100) {
                $this->add_error('discount_value', __('Percentage discount cannot exceed 100%', 'wc-fomo-discount'));
                return false;
            }
        } else if ($type === 'fixed') {
            // Check for reasonable maximum
            $max_discount = apply_filters('wcfd_max_fixed_discount', 10000);
            if ($value > $max_discount) {
                $this->add_error('discount_value', sprintf(__('Fixed discount cannot exceed %s', 'wc-fomo-discount'), wc_price($max_discount)));
                return false;
            }
        }
        
        return $value;
    }
    
    /**
     * Validate IP address
     * 
     * @param string $ip
     * @return string|false
     */
    public function validate_ip_address($ip) {
        // Handle proxy headers
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $this->add_error('ip_address', __('Invalid IP address', 'wc-fomo-discount'));
            return false;
        }
        
        // Check for banned IPs
        if ($this->is_banned_ip($ip)) {
            $this->add_error('ip_address', __('Access denied', 'wc-fomo-discount'));
            $this->logger->warning('Banned IP attempt', ['ip' => $ip]);
            return false;
        }
        
        return $ip;
    }
    
    /**
     * Validate nonce with rate limiting
     * 
     * @param string $nonce
     * @param string $action
     * @return bool
     */
    public function validate_nonce($nonce, $action) {
        if (empty($nonce)) {
            $this->add_error('nonce', __('Security token is missing', 'wc-fomo-discount'));
            return false;
        }
        
        if (!wp_verify_nonce($nonce, $action)) {
            $this->add_error('nonce', __('Security check failed. Please refresh and try again.', 'wc-fomo-discount'));
            $this->logger->warning('Nonce validation failed', [
                'action' => $action,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Sanitize and validate text input
     * 
     * @param string $input
     * @param array $options
     * @return string|false
     */
    public function validate_text($input, $options = []) {
        $defaults = [
            'required' => false,
            'min_length' => 0,
            'max_length' => 500,
            'pattern' => null,
            'allowed_html' => false
        ];
        
        $options = wp_parse_args($options, $defaults);
        
        // Check required
        if ($options['required'] && empty($input)) {
            $this->add_error('text', __('This field is required', 'wc-fomo-discount'));
            return false;
        }
        
        // Sanitize
        if ($options['allowed_html']) {
            $input = wp_kses_post($input);
        } else {
            $input = sanitize_text_field($input);
        }
        
        // Length validation
        $length = strlen($input);
        if ($length < $options['min_length']) {
            $this->add_error('text', sprintf(__('Minimum length is %d characters', 'wc-fomo-discount'), $options['min_length']));
            return false;
        }
        
        if ($length > $options['max_length']) {
            $this->add_error('text', sprintf(__('Maximum length is %d characters', 'wc-fomo-discount'), $options['max_length']));
            return false;
        }
        
        // Pattern validation
        if ($options['pattern'] && !preg_match($options['pattern'], $input)) {
            $this->add_error('text', __('Invalid format', 'wc-fomo-discount'));
            return false;
        }
        
        return $input;
    }
    
    /**
     * Check if email is from disposable domain
     * 
     * @param string $email
     * @return bool
     */
    private function is_disposable_email($email) {
        $disposable_domains = apply_filters('wcfd_disposable_email_domains', [
            'mailinator.com',
            'guerrillamail.com',
            '10minutemail.com',
            'tempmail.com',
            'throwaway.email'
        ]);
        
        $domain = substr(strrchr($email, "@"), 1);
        return in_array($domain, $disposable_domains);
    }
    
    /**
     * Check if IP is banned
     * 
     * @param string $ip
     * @return bool
     */
    private function is_banned_ip($ip) {
        $banned_ips = get_option('wcfd_banned_ips', []);
        
        // Check exact match
        if (in_array($ip, $banned_ips)) {
            return true;
        }
        
        // Check IP ranges
        foreach ($banned_ips as $banned) {
            if (strpos($banned, '/') !== false) {
                // CIDR notation
                list($subnet, $mask) = explode('/', $banned);
                if ($this->ip_in_range($ip, $subnet, $mask)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Check if IP is in range
     * 
     * @param string $ip
     * @param string $subnet
     * @param int $mask
     * @return bool
     */
    private function ip_in_range($ip, $subnet, $mask) {
        $subnet_decimal = ip2long($subnet);
        $ip_decimal = ip2long($ip);
        $mask_decimal = -1 << (32 - $mask);
        
        return ($ip_decimal & $mask_decimal) == ($subnet_decimal & $mask_decimal);
    }
    
    /**
     * Add validation error
     * 
     * @param string $field
     * @param string $message
     */
    private function add_error($field, $message) {
        $this->errors[$field] = $message;
    }
    
    /**
     * Get validation errors
     * 
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Check if validation has errors
     * 
     * @return bool
     */
    public function has_errors() {
        return !empty($this->errors);
    }
    
    /**
     * Clear validation errors
     */
    public function clear_errors() {
        $this->errors = [];
    }
    
    /**
     * Get formatted error message
     * 
     * @return string
     */
    public function get_error_message() {
        if (empty($this->errors)) {
            return '';
        }
        
        return implode('. ', array_values($this->errors));
    }
}