<?php
/**
 * Standalone tests for Cloudflare Access service-token injection in
 * SPSS_Recognition_HTTP::cf_access_headers() and request_with_retry().
 *
 * Usage: php test-cf-access.php
 *
 * No WordPress, no HTTP, no database — we shim just enough (options, a real
 * filter registry, a capturing wp_remote_post) that the REAL trait loads and we
 * can assert:
 *   - headers injected only when the request host matches the configured host;
 *   - never leaked to a different host (the security boundary);
 *   - disabled (empty host) or missing creds => no headers;
 *   - the SPSS_CF_ACCESS_CLIENT_SECRET constant overrides the option;
 *   - the spss_recognition_request_headers filter can add/override headers;
 *   - and, with no Access configured, the outbound header set is unchanged.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ── In-memory options ────────────────────────────────────────────────────────
$GLOBALS['spss_options'] = array();
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['spss_options'] ) ? $GLOBALS['spss_options'][ $name ] : $default;
}
function update_option( $name, $value ) {
	$GLOBALS['spss_options'][ $name ] = $value;
	return true;
}

// ── Minimal filter registry (so the filter seam is actually exercised) ─────────
$GLOBALS['spss_filters'] = array();
function add_filter( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['spss_filters'][ $tag ][] = $cb;
	return true;
}
function apply_filters( $tag, $value ) {
	$args = array_slice( func_get_args(), 1 );
	foreach ( $GLOBALS['spss_filters'][ $tag ] ?? array() as $cb ) {
		$args[0] = call_user_func_array( $cb, $args );
	}
	return $args[0];
}

// ── Other WP shims ───────────────────────────────────────────────────────────
function __( $text, $domain = 'default' ) { return $text; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $data ) { return json_encode( $data ); }

// Capturing HTTP: record the headers each call receives, reply 200 with JSON.
$GLOBALS['spss_last_request'] = null;
function wp_remote_post( $url, $args ) {
	$GLOBALS['spss_last_request'] = array( 'url' => $url, 'headers' => $args['headers'] );
	return array( 'code' => 200, 'body' => json_encode( array( 'ok' => true ) ) );
}
function wp_remote_retrieve_response_code( $r ) { return $r['code']; }
function wp_remote_retrieve_body( $r ) { return $r['body']; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message; }
	public function get_error_message() { return $this->message; }
}

require_once ABSPATH . '../includes/recognition/trait-recognition-http.php';

// A minimal user of the trait, exposing the protected surface for assertions.
class SPSS_CF_Test_Provider {
	use SPSS_Recognition_HTTP;
	public function get_id(): string { return 'test'; }
	public function headers_for( $url ) { return $this->cf_access_headers( $url ); }
	public function post_to( $url ) {
		$this->request_with_retry( $url, array( 'Authorization' => 'Bearer provider-key' ), array( 'x' => 1 ), 5 );
		return $GLOBALS['spss_last_request']['headers'];
	}
}

// ── Assertions ───────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ✓ PASS: $label\n";
	} else {
		++$fail;
		echo "  ✗ FAIL: $label\n";
	}
}

$p   = new SPSS_CF_Test_Provider();
$url = 'https://litellm.example.com/v1/chat/completions';

echo "=== cf_access_headers(): host gating + credentials ===\n";

// Disabled: no host configured.
$GLOBALS['spss_options'] = array();
check( 'no host configured => no headers', array() === $p->headers_for( $url ) );

// Configured + matching host + both creds.
update_option( 'spss_cf_access_host', 'litellm.example.com' );
update_option( 'spss_cf_access_client_id', 'cid-123' );
update_option( 'spss_cf_access_client_secret', 'csecret-abc' );
$h = $p->headers_for( $url );
check( 'matching host => CF-Access-Client-Id present', ( $h['CF-Access-Client-Id'] ?? '' ) === 'cid-123' );
check( 'matching host => CF-Access-Client-Secret present', ( $h['CF-Access-Client-Secret'] ?? '' ) === 'csecret-abc' );

// Non-matching host => NO headers (security boundary — no secret leakage).
check( 'different host => no headers (no leakage)', array() === $p->headers_for( 'https://api.anthropic.com/v1/messages' ) );

// Case-insensitive host match.
check( 'host match is case-insensitive', ! empty( $p->headers_for( 'https://LiteLLM.Example.COM/v1/chat/completions' ) ) );

// Missing pieces => no headers.
update_option( 'spss_cf_access_client_id', '' );
check( 'empty client id => no headers', array() === $p->headers_for( $url ) );
update_option( 'spss_cf_access_client_id', 'cid-123' );
update_option( 'spss_cf_access_client_secret', '' );
check( 'empty secret (no constant) => no headers', array() === $p->headers_for( $url ) );

echo "=== request_with_retry(): merge + filter ===\n";

// Full outbound header set for a matching host: provider key + CF headers.
update_option( 'spss_cf_access_client_secret', 'csecret-abc' );
$sent = $p->post_to( $url );
check( 'outbound keeps provider Authorization', ( $sent['Authorization'] ?? '' ) === 'Bearer provider-key' );
check( 'outbound adds CF-Access-Client-Id', ( $sent['CF-Access-Client-Id'] ?? '' ) === 'cid-123' );
check( 'outbound adds CF-Access-Client-Secret', ( $sent['CF-Access-Client-Secret'] ?? '' ) === 'csecret-abc' );

// Different host: only the provider header, never the CF secret.
$sent_other = $p->post_to( 'https://api.anthropic.com/v1/messages' );
check( 'other host: provider header kept', ( $sent_other['Authorization'] ?? '' ) === 'Bearer provider-key' );
check( 'other host: NO CF headers', ! isset( $sent_other['CF-Access-Client-Id'] ) && ! isset( $sent_other['CF-Access-Client-Secret'] ) );

// Filter seam can inject/override headers.
add_filter(
	'spss_recognition_request_headers',
	function ( $headers ) {
		$headers['X-Test-Injected'] = 'yes';
		return $headers; }
);
$sent_filtered = $p->post_to( $url );
check( 'filter can add a header', ( $sent_filtered['X-Test-Injected'] ?? '' ) === 'yes' );

echo "=== constant precedence (must be last: constants are process-global) ===\n";
define( 'SPSS_CF_ACCESS_CLIENT_SECRET', 'from-wp-config' );
update_option( 'spss_cf_access_client_secret', 'from-db-option' );
$hc = $p->headers_for( $url );
check( 'constant overrides the option for the secret', ( $hc['CF-Access-Client-Secret'] ?? '' ) === 'from-wp-config' );

echo "\n=== Results ===\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
