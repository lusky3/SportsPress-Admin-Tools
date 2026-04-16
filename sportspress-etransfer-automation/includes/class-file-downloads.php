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

class SPET_File_Downloads
{

    public function __construct()
    {
        add_action('init', array($this, 'handle_download_request'));
    }

    public function handle_download_request()
    {
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

        $file_key = sanitize_text_field($_GET['file']);

        // Map keys to filenames for security
        // This prevents path traversal by never using user input as a filename
        $file_map = array(
            'cloudflare-worker' => 'cloudflare-worker.js',
            'wrangler' => 'wrangler.toml',
            'readme' => 'README-cloudflare.md'
        );

        if (!array_key_exists($file_key, $file_map)) {
            wp_die(__('File not found.', 'sportspress-admin-tools'));
        }

        $filename = $file_map[$file_key];

        // Generate dynamic content
        $content = $this->get_file_content($filename);

        // Set headers for download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));

        // Output content
        echo $content;
        exit;
    }

    private function get_file_content($file)
    {
        $assets_dir = plugin_dir_path(dirname(__FILE__)) . 'assets/';
        $file_path = $assets_dir . $file;

        // Prevent path traversal attacks (redundant with mapping but good practice)
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

        // Replace placeholders with actual values from plugin settings.
        // Security note: The webhook secret is intentionally injected into downloadable
        // configuration files (wrangler.toml, README). This is safe because:
        // 1. Downloads require authentication (is_user_logged_in)
        // 2. Downloads require admin capability (manage_options)
        // 3. Downloads are protected by nonce verification
        // 4. The secret is only used for HMAC webhook signature verification
        $webhook_url = rest_url('spet/v1/etransfer-webhook');
        $webhook_secret = get_option('spet_webhook_secret', '');

        if ($file === 'wrangler.toml') {
            $content = str_replace(
                array(
                '# WEBHOOK_URL = "https://yoursite.com/wp-json/spet/v1/etransfer-webhook"',
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
                'https://yoursite.com/wp-json/spet/v1/etransfer-webhook',
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

    public static function get_download_url($file_key)
    {
        // Build the URL with the key
        return add_query_arg(array(
            'spat_download' => wp_create_nonce('spat_file_download'),
            'file' => $file_key
        ), home_url());
    }
}
