<?php
/**
 * Performance Monitoring System
 * 
 * @package WC_Fomo_Discount
 * @subpackage Utils
 * @since 2.0.0
 */

namespace WCFD\Utils;

use WCFD\Core\Logger;
use WCFD\Database\Database_Manager;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Performance Monitor class for tracking plugin performance
 */
class Performance_Monitor {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var Database_Manager
     */
    private $db_manager;
    
    /**
     * @var array Active timers
     */
    private $timers = [];
    
    /**
     * @var array Memory snapshots
     */
    private $memory_snapshots = [];
    
    /**
     * @var array Query counts
     */
    private $query_counts = [];
    
    /**
     * @var bool Monitoring enabled
     */
    private $monitoring_enabled;
    
    /**
     * Constructor
     * 
     * @param Logger $logger
     * @param Database_Manager $db_manager
     */
    public function __construct(Logger $logger, Database_Manager $db_manager) {
        $this->logger = $logger;
        $this->db_manager = $db_manager;
        $this->monitoring_enabled = defined('WCFD_PERFORMANCE_MONITORING') && WCFD_PERFORMANCE_MONITORING;
        
        if ($this->monitoring_enabled) {
            $this->init_hooks();
        }
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action('init', [$this, 'start_request_monitoring']);
        add_action('wp_footer', [$this, 'end_request_monitoring']);
        add_action('admin_footer', [$this, 'end_request_monitoring']);
        
        // Database query monitoring
        if (defined('SAVEQUERIES') && SAVEQUERIES) {
            add_filter('query', [$this, 'monitor_query']);
        }
    }
    
    /**
     * Start monitoring a request
     */
    public function start_request_monitoring() {
        if (!$this->monitoring_enabled) {
            return;
        }
        
        $this->start_timer('full_request');
        $this->memory_snapshots['request_start'] = [
            'usage' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'timestamp' => microtime(true)
        ];
        
        global $wpdb;
        $this->query_counts['request_start'] = $wpdb->num_queries;
    }
    
    /**
     * End monitoring a request
     */
    public function end_request_monitoring() {
        if (!$this->monitoring_enabled || !isset($this->timers['full_request'])) {
            return;
        }
        
        $this->end_timer('full_request');
        
        global $wpdb;
        $query_count = $wpdb->num_queries - ($this->query_counts['request_start'] ?? 0);
        
        $memory_end = [
            'usage' => memory_get_usage(),
            'peak' => memory_get_peak_usage(),
            'timestamp' => microtime(true)
        ];
        
        $memory_used = $memory_end['usage'] - $this->memory_snapshots['request_start']['usage'];
        $peak_memory = max($memory_end['peak'], $this->memory_snapshots['request_start']['peak']);
        
        $this->record_request_metrics([
            'queries' => $query_count,
            'memory_used' => $memory_used,
            'peak_memory' => $peak_memory,
            'page' => $this->get_current_page()
        ]);
    }
    
    /**
     * Start a performance timer
     * 
     * @param string $name Timer name
     * @param array $context Additional context
     */
    public function start_timer($name, $context = []) {
        if (!$this->monitoring_enabled) {
            return;
        }
        
        $this->timers[$name] = [
            'start_time' => microtime(true),
            'start_memory' => memory_get_usage(),
            'context' => $context
        ];
        
        $this->logger->debug("Performance timer started: $name", $context);
    }
    
    /**
     * End a performance timer
     * 
     * @param string $name Timer name
     * @param array $additional_context Additional context
     */
    public function end_timer($name, $additional_context = []) {
        if (!$this->monitoring_enabled || !isset($this->timers[$name])) {
            return;
        }
        
        $timer = $this->timers[$name];
        $end_time = microtime(true);
        $end_memory = memory_get_usage();
        
        $metrics = [
            'duration_ms' => round(($end_time - $timer['start_time']) * 1000, 2),
            'memory_used' => $end_memory - $timer['start_memory'],
            'memory_mb' => round(($end_memory - $timer['start_memory']) / 1048576, 2)
        ];
        
        $context = array_merge($timer['context'], $additional_context, $metrics);
        
        $this->logger->debug("Performance timer ended: $name", $context);
        
        // Record to database if enabled
        if (apply_filters('wcfd_record_performance_metrics', false)) {
            $this->record_performance_metric($name, $metrics['duration_ms'], $metrics['memory_used']);
        }
        
        // Check for slow operations
        $slow_threshold = apply_filters('wcfd_slow_operation_threshold', 1000); // 1 second
        if ($metrics['duration_ms'] > $slow_threshold) {
            $this->logger->warning("Slow operation detected: $name", $context);
        }
        
        // Check for high memory usage
        $memory_threshold = apply_filters('wcfd_high_memory_threshold', 10 * 1048576); // 10MB
        if ($metrics['memory_used'] > $memory_threshold) {
            $this->logger->warning("High memory usage detected: $name", $context);
        }
        
        unset($this->timers[$name]);
        
        // Trigger action for external monitoring
        do_action('wcfd_performance_metric_recorded', $name, $metrics, $context);
    }
    
    /**
     * Monitor database queries
     * 
     * @param string $query
     * @return string
     */
    public function monitor_query($query) {
        static $query_count = 0;
        $query_count++;
        
        // Log slow queries
        $slow_query_threshold = apply_filters('wcfd_slow_query_threshold', 0.1); // 100ms
        
        $start_time = microtime(true);
        
        // We can't actually time the query here since this is a filter
        // So we'll use a shutdown hook to check for slow queries
        add_action('shutdown', function() use ($query, $query_count, $start_time) {
            global $wpdb;
            
            if (!empty($wpdb->queries)) {
                $last_query = end($wpdb->queries);
                if (isset($last_query[1]) && $last_query[1] > $this->slow_query_threshold) {
                    $this->logger->warning('Slow query detected', [
                        'query' => $query,
                        'time' => $last_query[1],
                        'query_number' => $query_count
                    ]);
                }
            }
        }, 999);
        
        return $query;
    }
    
    /**
     * Record performance metric to database
     * 
     * @param string $operation
     * @param float $duration_ms
     * @param int $memory_bytes
     */
    private function record_performance_metric($operation, $duration_ms, $memory_bytes) {
        global $wpdb;
        
        $wpdb->insert(
            $this->db_manager->get_table('performance_metrics'),
            [
                'operation' => $operation,
                'duration_ms' => $duration_ms,
                'memory_bytes' => $memory_bytes,
                'user_id' => get_current_user_id() ?: null
            ],
            ['%s', '%f', '%d', '%d']
        );
    }
    
    /**
     * Record request-level metrics
     * 
     * @param array $metrics
     */
    private function record_request_metrics($metrics) {
        $this->logger->info('Request metrics', $metrics);
        
        // Store in transient for admin dashboard
        $recent_metrics = get_transient('wcfd_recent_metrics') ?: [];
        $recent_metrics[] = array_merge($metrics, [
            'timestamp' => time(),
            'url' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Keep only last 100 requests
        $recent_metrics = array_slice($recent_metrics, -100);
        set_transient('wcfd_recent_metrics', $recent_metrics, 3600);
    }
    
    /**
     * Get current page identifier
     * 
     * @return string
     */
    private function get_current_page() {
        if (is_admin()) {
            return 'admin:' . ($_GET['page'] ?? 'unknown');
        }
        
        if (is_home()) return 'home';
        if (is_shop()) return 'shop';
        if (is_product()) return 'product';
        if (is_cart()) return 'cart';
        if (is_checkout()) return 'checkout';
        if (is_account_page()) return 'account';
        
        return 'frontend:' . (get_post_type() ?: 'unknown');
    }
    
    /**
     * Get performance statistics
     * 
     * @param int $hours Hours to look back
     * @return array
     */
    public function get_performance_stats($hours = 24) {
        global $wpdb;
        
        $stats = wp_cache_get("wcfd_perf_stats_$hours", 'wcfd');
        
        if ($stats === false) {
            $stats = $wpdb->get_results($wpdb->prepare(
                "SELECT operation,
                    COUNT(*) as count,
                    AVG(duration_ms) as avg_duration,
                    MIN(duration_ms) as min_duration,
                    MAX(duration_ms) as max_duration,
                    AVG(memory_bytes) as avg_memory,
                    MAX(memory_bytes) as max_memory
                FROM {$this->db_manager->get_table('performance_metrics')}
                WHERE timestamp > DATE_SUB(NOW(), INTERVAL %d HOUR)
                GROUP BY operation
                ORDER BY avg_duration DESC",
                $hours
            ), ARRAY_A);
            
            wp_cache_set("wcfd_perf_stats_$hours", $stats, 'wcfd', 300);
        }
        
        return $stats;
    }
    
    /**
     * Get slowest operations
     * 
     * @param int $limit
     * @return array
     */
    public function get_slowest_operations($limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT operation, duration_ms, memory_bytes, timestamp, user_id
            FROM {$this->db_manager->get_table('performance_metrics')}
            WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY duration_ms DESC
            LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Clean up old performance data
     * 
     * @param int $days Days to keep
     */
    public function cleanup_old_data($days = 7) {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->db_manager->get_table('performance_metrics')}
            WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        $this->logger->info("Cleaned up old performance data", ['deleted_rows' => $deleted]);
    }
    
    /**
     * Enable/disable monitoring
     * 
     * @param bool $enabled
     */
    public function set_monitoring_enabled($enabled) {
        $this->monitoring_enabled = $enabled;
        
        if ($enabled) {
            $this->init_hooks();
        }
    }
    
    /**
     * Check if monitoring is enabled
     * 
     * @return bool
     */
    public function is_monitoring_enabled() {
        return $this->monitoring_enabled;
    }
}