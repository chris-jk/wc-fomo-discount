<?php
/**
 * Autoloader for WC Fomo Discount
 * 
 * @package WC_Fomo_Discount
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Autoloader function
 */
spl_autoload_register(function ($class) {
    // Check if it's our namespace
    if (strpos($class, 'WCFD\\') !== 0) {
        return;
    }
    
    // Remove namespace prefix
    $class = str_replace('WCFD\\', '', $class);
    
    // Convert namespace separators to directory separators
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    
    // Convert class name to filename
    $filename = 'class-' . strtolower(str_replace('_', '-', basename($class))) . '.php';
    $directory = dirname($class);
    
    // Map namespaces to directories
    $namespace_map = [
        'Core' => 'core',
        'Admin' => 'admin',
        'Frontend' => 'frontend',
        'Database' => 'database',
        'Utils' => 'utils'
    ];
    
    if (isset($namespace_map[$directory])) {
        $directory = $namespace_map[$directory];
    }
    
    // Build file path
    $file = WCFD_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR . strtolower($directory) . DIRECTORY_SEPARATOR . $filename;
    
    // Load file if it exists
    if (file_exists($file)) {
        require_once $file;
    }
});