<?php
/**
 * Rate Limiter Utility
 * 
 * @package WC_Fomo_Discount
 * @since 2.0.0
 */

namespace WCFD\Utils;

use WCFD\Core\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rate limiting functionality
 */
class Rate_Limiter {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var array Rate limit rules
     */
    private $rules = [
        'claim_discount' => [
            'limit' => 5,
            'period' => 300 // 5 minutes
        ],
        'email_verification' => [
            'limit' => 3,
            'period' => 600 // 10 minutes
        ],
        'waitlist_join' => [
            'limit' => 2,
            'period' => 300 // 5 minutes
        ]
    ];
    
    /**
     * Constructor
     */
    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }
    
    /**
     * Check if action is rate limited
     * 
     * @param string $action
     * @param string $identifier (IP address or user ID)
     * @return bool
     */
    public function is_limited($action, $identifier) {
        if (!isset($this->rules[$action])) {
            return false;
        }
        
        $rule = $this->rules[$action];
        $key = $this->get_cache_key($action, $identifier);
        
        $attempts = get_transient($key);
        if ($attempts === false) {
            return false;
        }
        
        $is_limited = $attempts >= $rule['limit'];
        
        if ($is_limited) {
            $this->logger->warning('Rate limit exceeded', [
                'action' => $action,
                'identifier' => $this->hash_identifier($identifier),
                'attempts' => $attempts,
                'limit' => $rule['limit']
            ]);
        }
        
        return $is_limited;
    }
    
    /**
     * Record an attempt
     * 
     * @param string $action
     * @param string $identifier
     * @return int Current attempt count
     */
    public function record_attempt($action, $identifier) {
        if (!isset($this->rules[$action])) {
            return 0;
        }
        
        $rule = $this->rules[$action];
        $key = $this->get_cache_key($action, $identifier);
        
        $attempts = get_transient($key);
        if ($attempts === false) {
            $attempts = 0;
        }
        
        $attempts++;
        set_transient($key, $attempts, $rule['period']);
        
        $this->logger->debug('Rate limit attempt recorded', [
            'action' => $action,
            'identifier' => $this->hash_identifier($identifier),
            'attempts' => $attempts,
            'limit' => $rule['limit']
        ]);
        
        return $attempts;
    }
    
    /**
     * Get time until rate limit resets
     * 
     * @param string $action
     * @param string $identifier
     * @return int Seconds until reset
     */
    public function get_reset_time($action, $identifier) {
        if (!isset($this->rules[$action])) {
            return 0;
        }
        
        $key = $this->get_cache_key($action, $identifier);
        $expiration = get_option('_transient_timeout_' . $key);
        
        if (!$expiration) {
            return 0;
        }
        
        return max(0, $expiration - time());
    }
    
    /**
     * Clear rate limit for identifier
     * 
     * @param string $action
     * @param string $identifier
     */
    public function clear_limit($action, $identifier) {
        $key = $this->get_cache_key($action, $identifier);
        delete_transient($key);
        
        $this->logger->debug('Rate limit cleared', [
            'action' => $action,
            'identifier' => $this->hash_identifier($identifier)
        ]);
    }
    
    /**
     * Update rate limit rules
     * 
     * @param array $rules
     */
    public function update_rules($rules) {
        $this->rules = array_merge($this->rules, $rules);
    }
    
    /**
     * Get cache key
     * 
     * @param string $action
     * @param string $identifier
     * @return string
     */
    private function get_cache_key($action, $identifier) {
        return 'wcfd_rate_limit_' . $action . '_' . md5($identifier);
    }
    
    /**
     * Hash identifier for logging (privacy)
     * 
     * @param string $identifier
     * @return string
     */
    private function hash_identifier($identifier) {
        return substr(md5($identifier), 0, 8);
    }
}