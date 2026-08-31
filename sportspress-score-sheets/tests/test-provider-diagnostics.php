<?php
/**
 * Standalone tests for provider diagnostics: SPSS_Recognition_HTTP's
 * extract_error_detail() / probe_get(), SPSS_Abstract_LLM_Provider's shared
 * test_connection() + each concrete provider's probe_url(), and
 * SPSS_SelfHosted_Provider's reachability-only test_connection().
 *
 * Usage: php test-provider-diagnostics.php
 *
 * No WordPress, no real HTTP — we shim just enough (a mutable option store, a
 * real filter registry, and a canned wp_remote_get()) that the REAL trait and
 * provider classes load and run. Harness state lives on a plain object behind
 * a function-static accessor (never $GLOBALS, never a static-property
 * subscript) — both trip PHPMD/Codacy findings that the CF-Access test
 * (test-cf-access.php) hit and fixed; same approach here from the start.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

/** Plain data holder for harness state (options, canned HTTP responses/errors). */
class SPSS_Diag_Test_State {
	public $options       = array();
	public $next_response = null; // array{code:int,body:string}|null — null falls through to a canned 200.
	public $next_error    = null; // WP_Error|null — takes precedence over next_response when set.
	public $last_request  = array();
}

/** Single harness-state instance (function-static object avoids $GLOBALS). */
function spss_diag_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPSS_Diag_Test_State();
	}
	return $state;
}

// ── Option store ─────────────────────────────────────────────────────────────
function get_option( $name, $default = false ) {
	$state = spss_diag_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}
function update_option( $name, $value ) {
	spss_diag_state()->options[ $name ] = $value;
	return true;
}

// ── Filter registry (exercises the same seam request_with_retry()/probe_get() use) ──
function add_filter( $tag, $cb ) {
	spss_diag_state()->filters[ $tag ][] = $cb;
	return true;
}
function apply_filters( $tag, $value ) {
	$extra   = array_slice( func_get_args(), 2 );
	$filters = spss_diag_state()->filters[ $tag ] ?? array();
	foreach ( $filters as $cb ) {
		$value = call_user_func_array( $cb, array_merge( array( $value ), $extra ) );
	}
	return $value;
}

// ── WP HTTP shims ────────────────────────────────────────────────────────────
function wp_remote_get( $url, $args ) {
	$state = spss_diag_state();
	$state->last_request = array( 'url' => $url, 'headers' => $args['headers'] ?? array() );
	if ( $state->next_error ) {
		return $state->next_error;
	}
	return $state->next_response ?? array( 'code' => 200, 'body' => '{}' );
}
function wp_remote_post( $url, $args ) {
	// Same canned-response mechanism, reused by request_with_retry() tests.
	return wp_remote_get( $url, $args );
}
function wp_remote_retrieve_response_code( $r ) { return is_array( $r ) ? ( $r['code'] ?? 0 ) : 0; }
function wp_remote_retrieve_body( $r ) { return is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''; }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
}

// ── Other WP shims ───────────────────────────────────────────────────────────
function __( $text ) { return $text; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function wp_json_encode( $data ) { return json_encode( $data ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }

// ── Load classes under test ──────────────────────────────────────────────────
$recognition_dir = dirname( __FILE__ ) . '/../includes/recognition';
require_once $recognition_dir . '/interface-recognition-provider.php';
require_once $recognition_dir . '/class-extraction-result.php';
require_once $recognition_dir . '/class-abstract-llm-provider.php'; // pulls in trait-recognition-http.php itself
require_once $recognition_dir . '/class-claude-provider.php';
require_once $recognition_dir . '/class-gemini-provider.php';
require_once $recognition_dir . '/class-openai-provider.php';
require_once $recognition_dir . '/class-openrouter-provider.php'; // extends the OpenAI provider
require_once $recognition_dir . '/class-selfhosted-provider.php';

// A tiny concrete provider whose is_configured()/probe_url() we can toggle,
// for exercising the abstract base's shared test_connection() in isolation
// from any real vendor's endpoint conventions.
class SPSS_Diag_Test_Provider extends SPSS_Abstract_LLM_Provider {
	public $configured = true;
	public $probe      = 'https://example.test/v1/models';
	public function get_id(): string { return 'diagtest'; }
	public function get_label(): string { return 'Diag Test Provider'; }
	protected function default_model(): string { return 'test-model'; }
	protected function key_constant(): string { return ''; }
	protected function endpoint_url(): string { return 'https://example.test/v1/chat'; }
	protected function auth_headers(): array { return array( 'authorization' => 'Bearer test-key' ); }
	protected function build_body( string $image_b64, string $media_type, array $context ): array { return array(); }
	protected function parse_response( $decoded ) { return null; }
	public function is_configured(): bool { return $this->configured; }
	protected function probe_url(): string { return $this->probe; }
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

echo "=== extract_error_detail(): via SPSS_Diag_Test_Provider (uses the shared trait) ===\n";
$p = new SPSS_Diag_Test_Provider();

$m1 = new ReflectionMethod( $p, 'extract_error_detail' );
$m1->setAccessible( true );
$extract = function ( $body, $limit = 300 ) use ( $m1, $p ) { return $m1->invoke( $p, $body, $limit ); };

check(
	'OpenAI/LiteLLM-style {"error":{"message":...}} extracted',
	'Invalid proxy server token passed.' === $extract( '{"error":{"message":"Invalid proxy server token passed."}}' )
);
check(
	'Bare {"error":"..."} string extracted',
	'auth failed' === $extract( '{"error":"auth failed"}' )
);
check(
	'{"message":"..."} (no error key) extracted',
	'top level message' === $extract( '{"message":"top level message"}' )
);
check( 'Empty body -> empty string', '' === $extract( '' ) );
check( 'Whitespace-only body -> empty string', '' === $extract( "   \n\t " ) );
check(
	'Non-JSON HTML body: tags stripped, whitespace collapsed',
	'Bad Gateway' === $extract( "<html>\n<body>  <h1>Bad   Gateway</h1>\n</body></html>" )
);
check( 'Long message truncated to the given limit', 5 === strlen( $extract( '{"error":{"message":"abcdefghij"}}', 5 ) ) );

echo "=== probe_get(): success / network error classification ===\n";
$state = spss_diag_state();
$m2    = new ReflectionMethod( $p, 'probe_get' );
$m2->setAccessible( true );

$state->next_response = array( 'code' => 200, 'body' => '{"data":[{"id":"m1"}]}' );
$r = $m2->invoke( $p, 'https://example.test/v1/models', array( 'authorization' => 'Bearer x' ) );
check( 'probe_get(): success returns code+body array', is_array( $r ) && 200 === $r['code'] );

$state->next_response = null;
$state->next_error    = new WP_Error( 'http_request_failed', 'Connection timed out' );
$r2 = $m2->invoke( $p, 'https://example.test/v1/models', array() );
check( 'probe_get(): network failure returns the WP_Error as-is', is_wp_error( $r2 ) && 'Connection timed out' === $r2->get_error_message() );
$state->next_error = null;

echo "=== SPSS_Abstract_LLM_Provider::test_connection() (shared base) ===\n";

$p->configured = false;
check( 'Not configured -> WP_Error', is_wp_error( $p->test_connection() ) );
$p->configured = true;

$p->probe = '';
check( 'No probe_url() -> WP_Error ("does not support a connection test")', is_wp_error( $p->test_connection() ) );
$p->probe = 'https://example.test/v1/models';

$state->next_response = array( 'code' => 200, 'body' => '{"data":[]}' );
check( 'probe succeeds (2xx) -> true', true === $p->test_connection() );

$state->next_response = null;
$state->next_error    = new WP_Error( 'http_request_failed', 'Could not resolve host' );
$r3 = $p->test_connection();
check( 'Unreachable endpoint -> WP_Error mentioning the network error', is_wp_error( $r3 ) && false !== strpos( $r3->get_error_message(), 'Could not resolve host' ) );
$state->next_error = null;

$state->next_response = array( 'code' => 401, 'body' => '{"error":{"message":"Invalid proxy server token passed."}}' );
$r4 = $p->test_connection();
check(
	'Auth failure (401) -> WP_Error carries the vendor detail, not just "HTTP 401"',
	is_wp_error( $r4 ) && false !== strpos( $r4->get_error_message(), 'Invalid proxy server token passed.' )
);
$state->next_response = null;

echo "=== request_with_retry(): non-retryable status appends the extracted detail ===\n";
$m3 = new ReflectionMethod( $p, 'request_with_retry' );
$m3->setAccessible( true );
$state->next_response = array( 'code' => 401, 'body' => '{"error":{"message":"Invalid proxy server token passed."}}' );
$r5 = $m3->invoke( $p, 'https://example.test/v1/chat', array( 'authorization' => 'Bearer x' ), array( 'x' => 1 ), 5 );
check(
	'Stored recognition error includes the vendor detail (was previously just "HTTP 401")',
	is_wp_error( $r5 ) && false !== strpos( $r5->get_error_message(), 'Invalid proxy server token passed.' )
);
$state->next_response = null;

echo "=== each concrete provider's probe_url() ===\n";
$claude = new SPSS_Claude_Provider();
$m4     = new ReflectionMethod( $claude, 'probe_url' );
$m4->setAccessible( true );
check( 'Claude probe_url() is the Anthropic models-list endpoint', 'https://api.anthropic.com/v1/models' === $m4->invoke( $claude ) );

$gemini = new SPSS_Gemini_Provider();
$m5     = new ReflectionMethod( $gemini, 'probe_url' );
$m5->setAccessible( true );
check( 'Gemini probe_url() is API_BASE without the trailing model segment', 'https://generativelanguage.googleapis.com/v1beta/models' === $m5->invoke( $gemini ) );

$openai = new SPSS_OpenAI_Provider();
$m6     = new ReflectionMethod( $openai, 'probe_url' );
$m6->setAccessible( true );
check( 'OpenAI probe_url() is the OpenAI models-list endpoint', 'https://api.openai.com/v1/models' === $m6->invoke( $openai ) );

update_option( 'spss_openrouter_base_url', 'https://litellm.example.com/v1' );
$openrouter = new SPSS_OpenRouter_Provider();
$m7         = new ReflectionMethod( $openrouter, 'probe_url' );
$m7->setAccessible( true );
check(
	'OpenRouter probe_url() honours a custom base URL (e.g. a self-hosted LiteLLM gateway)',
	'https://litellm.example.com/v1/models' === $m7->invoke( $openrouter )
);

echo "=== SPSS_SelfHosted_Provider::test_connection() (reachability-only) ===\n";
$state->options = array(); // Reset — a stray option from earlier sections must not leak in.
$sh = new SPSS_SelfHosted_Provider();
check( 'Not configured (no endpoint) -> WP_Error', is_wp_error( $sh->test_connection() ) );

update_option( 'spss_selfhosted_endpoint', 'http://172.29.0.5:8000' );
$state->next_response = array( 'code' => 404, 'body' => 'Not Found' );
check(
	'Any HTTP response (even 404 — nothing is routed at the endpoint root) counts as reachable',
	true === $sh->test_connection()
);

$state->next_response = null;
$state->next_error    = new WP_Error( 'http_request_failed', 'Connection refused' );
check( 'Network failure -> WP_Error naming the sidecar', is_wp_error( $sh->test_connection() ) );
$state->next_error = null;

echo "\n=== Results ===\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
