<?php
/**
 * Secure file downloads for admin tools
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPET_File_Downloads {


	public function __construct() {
		add_action( 'init', array( $this, 'handle_download_request' ) );
	}

	public function handle_download_request() {
		if ( ! isset( $_GET['spat_download'] ) || ! isset( $_GET['file'] ) ) {
			return;
		}

		// Check authentication and permissions
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'You do not have permission to download this file.', 'sportspress-etransfer-automation' ) );
		}

		// Verify nonce for security
		if ( ! isset( $_GET['spat_download'] ) || ! wp_verify_nonce( $_GET['spat_download'], 'spat_file_download' ) ) {
			wp_die( __( 'Invalid download request.', 'sportspress-etransfer-automation' ) );
		}

		$file_key = sanitize_text_field( $_GET['file'] );

		// Map keys to filenames for security
		// This prevents path traversal by never using user input as a filename
		$file_map = array(
			'cloudflare-worker' => 'cloudflare-worker.js',
			'wrangler' => 'wrangler.toml',
			'readme' => 'README-cloudflare.md',
		);

		if ( ! array_key_exists( $file_key, $file_map ) ) {
			wp_die( __( 'File not found.', 'sportspress-etransfer-automation' ) );
		}

		$filename = $file_map[ $file_key ];

		// Generate dynamic content
		$content = $this->get_file_content( $filename );

		// Set headers for download
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $content ) );

		// Output content. $content is the contents of an allowlisted bundled
		// asset (validated against $file_map above, path-traversal-guarded in
		// get_file_content), streamed as an octet-stream attachment — not request
		// data rendered into an HTML page, so it is not an XSS sink.
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nosemgrep
		exit;
	}

	private function get_file_content( $file ) {
		$assets_dir = plugin_dir_path( __DIR__ ) . 'assets/';
		$file_path = $assets_dir . $file;

		// Prevent path traversal attacks. This is defense-in-depth: $file already
		// comes from a fixed allowlist ($file_map in handle_download_request), so
		// user input never reaches this method as a free-form path. We still
		// confirm the resolved path stays within the assets directory.
		$real_path = realpath( $file_path );
		$real_assets_dir = realpath( $assets_dir );

		if ( ! $real_path || ! $real_assets_dir || ! str_starts_with( $real_path, $real_assets_dir . DIRECTORY_SEPARATOR ) ) {
			wp_die( __( 'Invalid file path.', 'sportspress-etransfer-automation' ) );
		}

		if ( ! file_exists( $file_path ) ) {
			wp_die( __( 'File not found.', 'sportspress-etransfer-automation' ) );
		}

		$content = file_get_contents( $file_path );

		if ( $content === false ) {
			wp_die( __( 'Unable to read file.', 'sportspress-etransfer-automation' ) );
		}

		// Replace placeholders with actual values from plugin settings.
		// Security note: The webhook secret is NEVER written into wrangler.toml —
		// it must be supplied out-of-band via `wrangler secret put WEBHOOK_SECRET`
		// so it is stored as an encrypted Cloudflare secret, not committed in a
		// plaintext config file. Only the (non-secret) WEBHOOK_URL is interpolated.
		// The README download still injects the secret because it is intended as a
		// one-time reference for the admin, gated by auth + manage_options + nonce.
		$webhook_url = rest_url( 'spet/v1/etransfer-webhook' );
		$webhook_secret = get_option( 'spet_webhook_secret', '' );

		if ( $file === 'wrangler.toml' ) {
			// Interpolate only the public webhook URL. Leave the commented
			// `# WEBHOOK_SECRET = "..."` line and the `wrangler secret put
			// WEBHOOK_SECRET` instruction in the template untouched.
			$content = str_replace(
				'WEBHOOK_URL = "https://yoursite.com/wp-json/spet/v1/etransfer-webhook"',
				'WEBHOOK_URL = "' . $webhook_url . '"',
				$content
			);
		}

		if ( $file === 'README-cloudflare.md' ) {
			$content = str_replace(
				array(
					'https://yoursite.com/wp-json/spet/v1/etransfer-webhook',
					'Your webhook secret from the plugin settings',
				),
				array(
					$webhook_url,
					$webhook_secret,
				),
				$content
			);
		}

		return $content;
	}

	public static function get_download_url( $file_key ) {
		// Build the URL with the key
		return add_query_arg(
			array(
				'spat_download' => wp_create_nonce( 'spat_file_download' ),
				'file' => $file_key,
			),
			home_url()
		);
	}
}
