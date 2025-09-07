<?php
/**
 * Centralized Logging System
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
 * Logger class for centralized error and debug logging
 */
class Logger {
    
    /**
     * Log levels
     */
    const LEVEL_DEBUG = 'debug';
    const LEVEL_INFO = 'info';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR = 'error';
    const LEVEL_CRITICAL = 'critical';
    
    /**
     * @var bool Debug mode flag
     */
    private $debug_mode;
    
    /**
     * @var string Log file path
     */
    private $log_file;
    
    /**
     * @var array Performance metrics
     */
    private $performance_metrics = [];
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wcfd-logs';
        
        // Create log directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // Add .htaccess to protect log files
            file_put_contents($log_dir . '/.htaccess', 'Deny from all');
        }
        
        $this->log_file = $log_dir . '/wcfd-' . date('Y-m-d') . '.log';
    }
    
    /**
     * Log a message
     * 
     * @param string $message Message to log
     * @param string $level Log level
     * @param array $context Additional context
     */
    public function log($message, $level = self::LEVEL_INFO, $context = []) {
        // Only log debug messages if debug mode is enabled
        if ($level === self::LEVEL_DEBUG && !$this->debug_mode) {
            return;
        }
        
        $timestamp = current_time('mysql');
        $user_id = get_current_user_id();
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        $log_entry = [
            'timestamp' => $timestamp,
            'level' => $level,
            'message' => $message,
            'user_id' => $user_id,
            'ip' => $ip,
            'context' => $context
        ];
        
        // Format log message
        $formatted_message = sprintf(
            "[%s] [%s] [User: %d] [IP: %s] %s",
            $timestamp,
            strtoupper($level),
            $user_id,
            $ip,
            $message
        );
        
        if (!empty($context)) {
            $formatted_message .= ' | Context: ' . json_encode($context);
        }
        
        // Write to file
        error_log($formatted_message . PHP_EOL, 3, $this->log_file);
        
        // Also log to WordPress debug.log if it's an error or critical
        if (in_array($level, [self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            error_log('[WCFD] ' . $formatted_message);
        }
        
        // Trigger action for external logging services
        do_action('wcfd_log_entry', $log_entry);
    }
    
    /**
     * Convenience methods for different log levels
     */
    public function debug($message, $context = []) {
        $this->log($message, self::LEVEL_DEBUG, $context);
    }
    
    public function info($message, $context = []) {
        $this->log($message, self::LEVEL_INFO, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log($message, self::LEVEL_WARNING, $context);
    }
    
    public function error($message, $context = []) {
        $this->log($message, self::LEVEL_ERROR, $context);
    }
    
    public function critical($message, $context = []) {
        $this->log($message, self::LEVEL_CRITICAL, $context);
    }
    
    /**
     * Start performance timer
     * 
     * @param string $operation Operation name
     */
    public function start_timer($operation) {
        $this->performance_metrics[$operation] = [
            'start' => microtime(true),
            'memory_start' => memory_get_usage()
        ];
    }
    
    /**
     * End performance timer and log metrics
     * 
     * @param string $operation Operation name
     */
    public function end_timer($operation) {
        if (!isset($this->performance_metrics[$operation])) {
            return;
        }
        
        $metrics = $this->performance_metrics[$operation];
        $duration = microtime(true) - $metrics['start'];
        $memory_used = memory_get_usage() - $metrics['memory_start'];
        
        $this->debug("Performance: $operation", [
            'duration_ms' => round($duration * 1000, 2),
            'memory_bytes' => $memory_used,
            'memory_mb' => round($memory_used / 1048576, 2)
        ]);
        
        unset($this->performance_metrics[$operation]);
        
        // Trigger action for performance monitoring
        do_action('wcfd_performance_metric', $operation, $duration, $memory_used);
    }
    
    /**
     * Get recent log entries
     * 
     * @param int $limit Number of entries to retrieve
     * @param string $level Filter by log level
     * @return array
     */
    public function get_recent_logs($limit = 100, $level = null) {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $lines = file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $lines = array_reverse($lines);
        $logs = [];
        
        foreach ($lines as $line) {
            if ($level && stripos($line, "[$level]") === false) {
                continue;
            }
            
            $logs[] = $line;
            
            if (count($logs) >= $limit) {
                break;
            }
        }
        
        return $logs;
    }
    
    /**
     * Clear old log files
     * 
     * @param int $days_to_keep Number of days to keep logs
     */
    public function cleanup_old_logs($days_to_keep = 30) {
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wcfd-logs';
        
        if (!is_dir($log_dir)) {
            return;
        }
        
        $files = glob($log_dir . '/wcfd-*.log');
        $cutoff_time = strtotime("-{$days_to_keep} days");
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoff_time) {
                unlink($file);
                $this->info("Deleted old log file: " . basename($file));
            }
        }
    }
}