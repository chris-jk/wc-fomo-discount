<?php
/**
 * Database Management System with Migrations
 * 
 * @package WC_Fomo_Discount
 * @subpackage Database
 * @since 2.0.0
 */

namespace WCFD\Database;

use WCFD\Core\Logger;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Database Manager class for handling schema and migrations
 */
class Database_Manager {
    
    /**
     * @var Logger
     */
    private $logger;
    
    /**
     * @var string Current database version
     */
    private $current_version;
    
    /**
     * @var string Target database version
     */
    const DB_VERSION = '2.0.0';
    
    /**
     * @var array Table names
     */
    private $tables = [];
    
    /**
     * Constructor
     * 
     * @param Logger $logger
     */
    public function __construct(Logger $logger) {
        global $wpdb;
        
        $this->logger = $logger;
        $this->current_version = get_option('wcfd_db_version', '0.0.0');
        
        // Define table names
        $this->tables = [
            'campaigns' => $wpdb->prefix . 'wcfd_campaigns',
            'claimed_codes' => $wpdb->prefix . 'wcfd_claimed_codes',
            'email_verifications' => $wpdb->prefix . 'wcfd_email_verifications',
            'waitlist' => $wpdb->prefix . 'wcfd_waitlist',
            'performance_metrics' => $wpdb->prefix . 'wcfd_performance_metrics',
            'audit_log' => $wpdb->prefix . 'wcfd_audit_log'
        ];
    }
    
    /**
     * Initialize database
     */
    public function init() {
        if (version_compare($this->current_version, self::DB_VERSION, '<')) {
            $this->logger->info('Database migration required', [
                'current_version' => $this->current_version,
                'target_version' => self::DB_VERSION
            ]);
            
            $this->migrate();
        }
    }
    
    /**
     * Run database migrations
     */
    private function migrate() {
        $this->logger->start_timer('database_migration');
        
        $migrations = $this->get_migrations();
        $applied = 0;
        
        foreach ($migrations as $version => $migration) {
            if (version_compare($this->current_version, $version, '<')) {
                $this->logger->info("Applying migration: $version");
                
                try {
                    call_user_func($migration);
                    $applied++;
                    update_option('wcfd_db_version', $version);
                    $this->current_version = $version;
                } catch (\Exception $e) {
                    $this->logger->error('Migration failed', [
                        'version' => $version,
                        'error' => $e->getMessage()
                    ]);
                    break;
                }
            }
        }
        
        $this->logger->end_timer('database_migration');
        $this->logger->info("Database migration completed. Applied $applied migrations.");
    }
    
    /**
     * Get all migrations
     * 
     * @return array
     */
    private function get_migrations() {
        return [
            '1.0.0' => [$this, 'migration_1_0_0'],
            '1.1.0' => [$this, 'migration_1_1_0'],
            '2.0.0' => [$this, 'migration_2_0_0']
        ];
    }
    
    /**
     * Migration 1.0.0 - Initial tables
     */
    private function migration_1_0_0() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Campaigns table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['campaigns']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_name varchar(255) NOT NULL,
            discount_type enum('percent','fixed') NOT NULL,
            discount_value decimal(10,2) NOT NULL,
            tiered_discounts text,
            total_codes int(11) NOT NULL,
            codes_remaining int(11) NOT NULL,
            expiry_hours int(11) NOT NULL DEFAULT 24,
            enable_ip_limit tinyint(1) DEFAULT 0,
            max_per_ip int(11) DEFAULT 1,
            scope_type enum('all','products','categories') DEFAULT 'all',
            scope_ids text,
            status enum('active','paused','ended') DEFAULT 'active',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_status (status),
            KEY idx_codes_remaining (codes_remaining)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
        
        // Claimed codes table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['claimed_codes']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_id int(11) NOT NULL,
            user_email varchar(255) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            coupon_code varchar(50) NOT NULL,
            claimed_at datetime DEFAULT CURRENT_TIMESTAMP,
            expires_at datetime NOT NULL,
            ip_address varchar(45),
            email_verified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_campaign_id (campaign_id),
            KEY idx_user_email (user_email),
            KEY idx_coupon_code (coupon_code),
            KEY idx_expires_at (expires_at),
            KEY idx_email_verified (email_verified)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
        
        // Email verifications table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['email_verifications']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_id int(11) NOT NULL,
            user_email varchar(255) NOT NULL,
            verification_token varchar(64) NOT NULL,
            coupon_code varchar(50) NOT NULL,
            ip_address varchar(45),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            verified_at datetime DEFAULT NULL,
            expires_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_token (verification_token),
            KEY idx_email_campaign (user_email, campaign_id),
            KEY idx_expires_at (expires_at)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
        
        // Waitlist table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['waitlist']} (
            id int(11) NOT NULL AUTO_INCREMENT,
            campaign_id int(11) NOT NULL,
            email varchar(255) NOT NULL,
            joined_at datetime DEFAULT CURRENT_TIMESTAMP,
            notified tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY idx_campaign_email (campaign_id, email),
            KEY idx_notified (notified)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
    }
    
    /**
     * Migration 1.1.0 - Add performance metrics table
     */
    private function migration_1_1_0() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['performance_metrics']} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            operation varchar(100) NOT NULL,
            duration_ms decimal(10,2) NOT NULL,
            memory_bytes bigint(20) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_operation (operation),
            KEY idx_timestamp (timestamp)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
    }
    
    /**
     * Migration 2.0.0 - Add audit log and indexes
     */
    private function migration_2_0_0() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Audit log table
        $sql = "CREATE TABLE IF NOT EXISTS {$this->tables['audit_log']} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned DEFAULT NULL,
            action varchar(100) NOT NULL,
            object_type varchar(50),
            object_id int(11),
            old_value text,
            new_value text,
            ip_address varchar(45),
            user_agent text,
            timestamp datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_user_id (user_id),
            KEY idx_action (action),
            KEY idx_timestamp (timestamp),
            KEY idx_object (object_type, object_id)
        ) $charset_collate;";
        
        $this->execute_sql($sql);
        
        // Add composite indexes for better performance
        $this->add_index($this->tables['claimed_codes'], 'idx_campaign_verified', ['campaign_id', 'email_verified']);
        $this->add_index($this->tables['claimed_codes'], 'idx_ip_campaign', ['ip_address', 'campaign_id']);
    }
    
    /**
     * Execute SQL statement
     * 
     * @param string $sql
     */
    private function execute_sql($sql) {
        global $wpdb;
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        if ($wpdb->last_error) {
            $this->logger->error('Database error', [
                'error' => $wpdb->last_error,
                'sql' => $sql
            ]);
        }
    }
    
    /**
     * Add index to table
     * 
     * @param string $table
     * @param string $index_name
     * @param array $columns
     */
    private function add_index($table, $index_name, $columns) {
        global $wpdb;
        
        // Check if index exists
        $index_exists = $wpdb->get_var(
            "SHOW INDEX FROM $table WHERE Key_name = '$index_name'"
        );
        
        if (!$index_exists) {
            $columns_str = implode(', ', $columns);
            $sql = "ALTER TABLE $table ADD INDEX $index_name ($columns_str)";
            $wpdb->query($sql);
            
            if ($wpdb->last_error) {
                $this->logger->error('Failed to add index', [
                    'table' => $table,
                    'index' => $index_name,
                    'error' => $wpdb->last_error
                ]);
            }
        }
    }
    
    /**
     * Get table name
     * 
     * @param string $table
     * @return string
     */
    public function get_table($table) {
        return $this->tables[$table] ?? null;
    }
    
    /**
     * Check if tables exist
     * 
     * @return bool
     */
    public function tables_exist() {
        global $wpdb;
        
        foreach ($this->tables as $table) {
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") != $table) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Drop all plugin tables (for uninstall)
     */
    public function drop_tables() {
        global $wpdb;
        
        foreach ($this->tables as $table) {
            $wpdb->query("DROP TABLE IF EXISTS $table");
        }
        
        delete_option('wcfd_db_version');
        $this->logger->info('All plugin tables dropped');
    }
    
    /**
     * Optimize tables
     */
    public function optimize_tables() {
        global $wpdb;
        
        $this->logger->start_timer('optimize_tables');
        
        foreach ($this->tables as $table) {
            $wpdb->query("OPTIMIZE TABLE $table");
        }
        
        $this->logger->end_timer('optimize_tables');
        $this->logger->info('Database tables optimized');
    }
    
    /**
     * Get database statistics
     * 
     * @return array
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = [];
        
        foreach ($this->tables as $key => $table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
            $size = $wpdb->get_var(
                "SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) 
                FROM information_schema.TABLES 
                WHERE table_schema = DATABASE() 
                AND table_name = '$table'"
            );
            
            $stats[$key] = [
                'rows' => $count,
                'size_mb' => $size
            ];
        }
        
        return $stats;
    }
}