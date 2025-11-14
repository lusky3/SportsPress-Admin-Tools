<?php
/**
 * Secure file downloads for admin tools
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

class SPET_File_Downloads {
    
    public function __construct() {
        add_action('init', array($this, 'handle_download_request'));
    }
    
    public function handle_download_request() {
        if (!isset($_GET['spat_download']) || !isset($_GET['file'])) {
            return;
        }
        
        // Check authentication and permissions
        if (!is_user_logged_in() || !current_user_can('manage_options')) {
            wp_die(__('You do not have permission to download this file.', 'sportspress-admin-tools'));
        }
        
        // Verify nonce for security
        if (!isset($_GET['spat_download']) || !wp_verify_nonce($_GET['spat_download'], 'spat_file_download')) {
            wp_die(__('Invalid download request.', 'sportspress-admin-tools'));
        }
        
        $file = sanitize_file_name($_GET['file']);
        $allowed_files = array(
            'cloudflare-worker.js',
            'wrangler.toml',
            'README-cloudflare.md'
        );
        
        if (!in_array($file, $allowed_files)) {
            wp_die(__('File not found.', 'sportspress-admin-tools'));
        }
        
        // Generate dynamic content
        $content = $this->get_file_content($file);
        
        // Set headers for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($content));
        
        // Output content
        echo $content;
        wp_die();
    }
    
    private function get_file_content($file) {
        $assets_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/';
        $file_path = $assets_dir . $file;
        
        // Prevent path traversal attacks
        $real_path = realpath($file_path);
        $real_assets_dir = realpath($assets_dir);
        
        if (!$real_path || !$real_assets_dir || strpos($real_path, $real_assets_dir) !== 0) {
            wp_die(__('Invalid file path.', 'sportspress-admin-tools'));
        }
        
        if (!file_exists($file_path)) {
            wp_die(__('File not found.', 'sportspress-admin-tools'));
        }
        
        $content = file_get_contents($file_path);
        
        if ($content === false) {
            wp_die(__('Unable to read file.', 'sportspress-admin-tools'));
        }
        
        // Replace placeholders with actual values
        $webhook_url = rest_url('spat/v1/etransfer-webhook');
        $webhook_secret = get_option('spat_etransfer_webhook_secret', '');
        
        if ($file === 'wrangler.toml') {
            $content = str_replace(
                array(
                    '# WEBHOOK_URL = "https://yoursite.com/wp-json/spat/v1/etransfer-webhook"',
                    '# WEBHOOK_SECRET = "your-webhook-secret-from-plugin"'
                ),
                array(
                    'WEBHOOK_URL = "' . $webhook_url . '"',
                    'WEBHOOK_SECRET = "' . $webhook_secret . '"'
                ),
                $content
            );
        }
        
        if ($file === 'README-cloudflare.md') {
            $content = str_replace(
                array(
                    'https://yoursite.com/wp-json/spat/v1/etransfer-webhook',
                    'Your webhook secret from the plugin settings'
                ),
                array(
                    $webhook_url,
                    $webhook_secret
                ),
                $content
            );
        }
        
        return $content;
    }
    
    public static function get_download_url($file) {
        return add_query_arg(array(
            'spat_download' => wp_create_nonce('spat_file_download'),
            'file' => $file
        ), home_url());
    }
}