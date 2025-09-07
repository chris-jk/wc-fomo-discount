<?php
/**
 * Plugin Auto-Updater
 * Updates from GitHub repository
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCFD_Auto_Updater {
    
    private $plugin_slug;
    private $version;
    private $github_user;
    private $github_repo;
    private $plugin_file;
    private $github_token;
    
    public function __construct($plugin_file, $github_user, $github_repo, $version, $github_token = '') {
        $this->plugin_file = $plugin_file;
        $this->plugin_slug = plugin_basename($plugin_file);
        $this->version = $version;
        $this->github_user = $github_user;
        $this->github_repo = $github_repo;
        $this->github_token = $github_token ?: get_option('wcfd_github_token', '');
        
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge_updater_cache'), 10, 2);
        add_filter('upgrader_pre_download', array($this, 'upgrader_pre_download'), 10, 4);
    }
    
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Get remote version
        $remote_version = $this->get_remote_version();
        
        if ($remote_version && version_compare($this->version, $remote_version, '<')) {
            $transient->response[$this->plugin_slug] = (object) array(
                'slug' => $this->plugin_slug,
                'new_version' => $remote_version,
                'url' => $this->get_github_repo_url(),
                'package' => $this->get_download_url()
            );
        }
        
        return $transient;
    }
    
    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information' || $args->slug !== $this->plugin_slug) {
            return $result;
        }
        
        $remote_version = $this->get_remote_version();
        $remote_readme = $this->get_remote_readme();
        
        return (object) array(
            'name' => 'WooCommerce FOMO Discount Generator',
            'slug' => $this->plugin_slug,
            'version' => $remote_version,
            'author' => 'Your Name',
            'author_profile' => $this->get_github_repo_url(),
            'requires' => '5.0',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'download_link' => $this->get_download_url(),
            'trunk' => $this->get_download_url(),
            'sections' => array(
                'description' => $remote_readme,
                'changelog' => $this->get_changelog()
            ),
            'banners' => array(),
            'external' => true
        );
    }
    
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;
        
        $install_directory = plugin_dir_path($this->plugin_file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;
        
        if ($this->is_plugin_active()) {
            activate_plugin($this->plugin_slug);
        }
        
        return $result;
    }
    
    public function purge_updater_cache($upgrader, $options) {
        if ($options['type'] == 'plugin' && isset($options['plugins']) && 
            in_array($this->plugin_slug, $options['plugins'])) {
            delete_transient('wcfd_remote_version');
            delete_transient('wcfd_remote_readme');
            delete_transient('wcfd_remote_changelog');
        }
    }
    
    public function upgrader_pre_download($reply, $package, $upgrader, $hook_extra = null) {
        // Only handle our plugin's downloads
        if (!empty($this->github_token) && strpos($package, $this->github_repo) !== false) {
            $temp_file = download_url($package, 300, false, array(
                'headers' => array(
                    'Authorization' => 'token ' . $this->github_token,
                    'Accept' => 'application/vnd.github.v3+json'
                )
            ));
            
            if (!is_wp_error($temp_file)) {
                return $temp_file;
            }
        }
        
        return $reply;
    }
    
    private function get_remote_version() {
        $cache_key = 'wcfd_remote_version';
        $cached_version = get_transient($cache_key);
        
        if ($cached_version !== false) {
            return $cached_version;
        }
        
        // Security: Validate GitHub repo details to prevent SSRF
        if (!$this->validate_github_details()) {
            return false;
        }
        
        $request = wp_remote_get($this->get_api_url('releases/latest'), array(
            'timeout' => 10,
            'sslverify' => true,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        ));
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) == 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['tag_name'])) {
                $version = ltrim($data['tag_name'], 'v');
                set_transient($cache_key, $version, HOUR_IN_SECONDS * 6);
                return $version;
            }
        }
        
        return false;
    }
    
    private function get_remote_readme() {
        $cache_key = 'wcfd_remote_readme';
        $cached_readme = get_transient($cache_key);
        
        if ($cached_readme !== false) {
            return $cached_readme;
        }
        
        $request = $this->make_secure_request('contents/README.md');
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) == 200) {
            $body = wp_remote_retrieve_body($request);
            $data = json_decode($body, true);
            
            if (isset($data['content'])) {
                $readme = base64_decode($data['content']);
                set_transient($cache_key, $readme, HOUR_IN_SECONDS * 6);
                return $readme;
            }
        }
        
        return 'Generate limited quantity, time-limited discount codes with real-time countdown and email verification.';
    }
    
    private function get_changelog() {
        $cache_key = 'wcfd_remote_changelog';
        $cached_changelog = get_transient($cache_key);
        
        if ($cached_changelog !== false) {
            return $cached_changelog;
        }
        
        $request = $this->make_secure_request('releases');
        
        if (!is_wp_error($request) && wp_remote_retrieve_response_code($request) == 200) {
            $body = wp_remote_retrieve_body($request);
            $releases = json_decode($body, true);
            
            $changelog = '';
            if (is_array($releases)) {
                foreach (array_slice($releases, 0, 5) as $release) {
                    $changelog .= '<h4>' . $release['tag_name'] . ' - ' . date('Y-m-d', strtotime($release['published_at'])) . '</h4>';
                    $changelog .= '<p>' . wp_kses_post($release['body']) . '</p>';
                }
            }
            
            set_transient($cache_key, $changelog, HOUR_IN_SECONDS * 6);
            return $changelog;
        }
        
        return '<h4>1.0.0</h4><p>Initial release with email verification and FOMO campaigns.</p>';
    }
    
    private function get_download_url() {
        if (!empty($this->github_token)) {
            // For private repos, use the API download endpoint
            return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/zipball/master";
        }
        return "https://github.com/{$this->github_user}/{$this->github_repo}/archive/master.zip";
    }
    
    private function get_api_url($endpoint) {
        return "https://api.github.com/repos/{$this->github_user}/{$this->github_repo}/{$endpoint}";
    }
    
    private function get_github_repo_url() {
        return "https://github.com/{$this->github_user}/{$this->github_repo}";
    }
    
    private function is_plugin_active() {
        return is_plugin_active($this->plugin_slug);
    }
    
    private function validate_github_details() {
        // Validate GitHub username (alphanumeric, hyphens, max 39 chars)
        if (!preg_match('/^[a-zA-Z0-9\-]{1,39}$/', $this->github_user)) {
            return false;
        }
        
        // Validate repository name (alphanumeric, hyphens, underscores, dots, max 100 chars)
        if (!preg_match('/^[a-zA-Z0-9\-_.]{1,100}$/', $this->github_repo)) {
            return false;
        }
        
        return true;
    }
    
    private function make_secure_request($endpoint) {
        if (!$this->validate_github_details()) {
            return new WP_Error('invalid_repo', 'Invalid GitHub repository details');
        }
        
        $headers = array(
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
        );
        
        // Add authorization header for private repositories
        if (!empty($this->github_token)) {
            $headers['Authorization'] = 'token ' . $this->github_token;
        }
        
        return wp_remote_get($this->get_api_url($endpoint), array(
            'timeout' => 10,
            'sslverify' => true,
            'headers' => $headers
        ));
    }
}