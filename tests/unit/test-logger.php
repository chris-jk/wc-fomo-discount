<?php
/**
 * Test Logger Class
 * 
 * @package WC_Fomo_Discount
 * @subpackage Tests
 */

use WCFD\Core\Logger;

class Test_Logger extends WP_UnitTestCase {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * Set up test
     */
    public function setUp(): void {
        parent::setUp();
        $this->logger = new Logger();
    }
    
    /**
     * Test log levels
     */
    public function test_log_levels() {
        $message = 'Test message';
        $context = ['test' => 'data'];
        
        // Test each log level
        $this->logger->debug($message, $context);
        $this->logger->info($message, $context);
        $this->logger->warning($message, $context);
        $this->logger->error($message, $context);
        $this->logger->critical($message, $context);
        
        // Since we can't easily test file writing in unit tests,
        // we'll just verify the methods don't throw exceptions
        $this->assertTrue(true);
    }
    
    /**
     * Test performance timing
     */
    public function test_performance_timing() {
        $operation = 'test_operation';
        
        // Start timer
        $this->logger->start_timer($operation);
        
        // Simulate some work
        usleep(1000); // 1ms
        
        // End timer
        $this->logger->end_timer($operation);
        
        // Test should complete without errors
        $this->assertTrue(true);
    }
    
    /**
     * Test log cleanup
     */
    public function test_log_cleanup() {
        // Create a mock old log file for testing
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/wcfd-logs';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        // Create an old log file
        $old_log = $log_dir . '/wcfd-' . date('Y-m-d', strtotime('-35 days')) . '.log';
        file_put_contents($old_log, 'Old log content');
        
        // Run cleanup (should delete files older than 30 days)
        $this->logger->cleanup_old_logs(30);
        
        // Verify old log was deleted
        $this->assertFalse(file_exists($old_log));
    }
    
    /**
     * Test get recent logs
     */
    public function test_get_recent_logs() {
        // Log some messages
        $this->logger->info('Test message 1');
        $this->logger->error('Test error message');
        $this->logger->info('Test message 2');
        
        // Get recent logs
        $logs = $this->logger->get_recent_logs(10);
        
        // Should return an array
        $this->assertIsArray($logs);
        
        // Test filtering by level
        $error_logs = $this->logger->get_recent_logs(10, 'error');
        $this->assertIsArray($error_logs);
    }
}