<?php
/**
 * Standalone tests for SPSS_REST_API signature helpers.
 *
 * Usage: php test-rest-ingest.php
 *
 * No WordPress, no HTTP, no database. We define ABSPATH and a no-op add_action
 * so the class file loads, then exercise ONLY the pure static signature helpers
 * (ingest_signature / twilio_signature). We never construct SPSS_REST_API, so
 * no rest_api_init hook is touched — the helpers use only hash_hmac / base64 /
 * ksort, which need no WordPress runtime.
 */

define('ABSPATH', dirname(__FILE__) . '/');

// The class constructor calls add_action(); define a no-op so requiring the
// file is safe. (Static-only use never invokes it, but this keeps the require
// robust regardless.)
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('add_filter')) {
    function add_filter() {}
}
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
}

// ── Lightweight WordPress stubs for handler-level tests ──────────────────────
// These let us exercise handle_ingest()/handle_twilio() (and their auth gates)
// without WordPress or HTTP. Options and transients live in module globals the
// tests reset per case.

$GLOBALS['spss_options']    = array();
$GLOBALS['spss_transients'] = array();

if (!function_exists('get_option')) {
    function get_option($name, $default = false) {
        return array_key_exists($name, $GLOBALS['spss_options'])
            ? $GLOBALS['spss_options'][$name]
            : $default;
    }
}
if (!function_exists('get_transient')) {
    function get_transient($key) {
        return array_key_exists($key, $GLOBALS['spss_transients'])
            ? $GLOBALS['spss_transients'][$key]
            : false;
    }
}
if (!function_exists('set_transient')) {
    function set_transient($key, $value, $ttl = 0) {
        $GLOBALS['spss_transients'][$key] = $value;
        return true;
    }
}
if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        if ($response instanceof WP_Error || $response instanceof WP_REST_Response) {
            return $response;
        }
        return new WP_REST_Response($response);
    }
}
if (!function_exists('rest_url')) {
    function rest_url($path = '') {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }
}
if (!function_exists('wp_parse_url')) {
    function wp_parse_url($url, $component = -1) {
        return parse_url($url);
    }
}
if (!function_exists('wp_tempnam')) {
    function wp_tempnam() {
        return tempnam(sys_get_temp_dir(), 'spss');
    }
}
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}
if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = array()) {
        return array('body' => '', 'headers' => array());
    }
}
if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) {
        return is_array($response) && isset($response['body']) ? $response['body'] : '';
    }
}
if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, $header) {
        return is_array($response) && isset($response['headers'][$header])
            ? $response['headers'][$header]
            : '';
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = null) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }
        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
        public function add_data($data) { $this->data = $data; }
    }
}
if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        private $data;
        private $status;
        private $headers = array();
        public function __construct($data = null, $status = 200) {
            $this->data   = $data;
            $this->status = $status;
        }
        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
        public function set_status($status) { $this->status = $status; }
        public function header($key, $value) { $this->headers[$key] = $value; }
        public function get_headers() { return $this->headers; }
    }
}
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $body;
        private $headers;
        private $body_params;
        private $params;
        public function __construct($body = '', $headers = array(), $body_params = array(), $params = array()) {
            $this->body        = $body;
            $this->headers     = array_change_key_case($headers, CASE_LOWER);
            $this->body_params = $body_params;
            $this->params      = $params;
        }
        public function get_body() { return $this->body; }
        public function get_header($key) {
            $key = strtolower($key);
            return isset($this->headers[$key]) ? $this->headers[$key] : '';
        }
        public function get_body_params() { return $this->body_params; }
        public function get_param($key) {
            return isset($this->params[$key]) ? $this->params[$key] : null;
        }
    }
}
if (!class_exists('SPSS_Ingest_Service')) {
    class SPSS_Ingest_Service {
        // Shared size cap (single source of truth in the real class).
        const MAX_IMAGE_BYTES = 15 * 1024 * 1024;
        // Configurable return value for accept_image/accept_bytes (int sheet id or WP_Error).
        public static $result = 123;
        public static function accept_image(array $args) {
            return self::$result;
        }
        // Mirrors the real accept_bytes: enforce the size cap, else defer to accept_image.
        public static function accept_bytes($bytes, array $args) {
            if (strlen($bytes) > self::MAX_IMAGE_BYTES) {
                return new WP_Error('spss_image_too_large', 'Image exceeds the maximum allowed size.', array('status' => 413));
            }
            return self::$result;
        }
    }
}

require_once dirname(__FILE__) . '/../includes/class-rest-api.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_REST_API signature helpers ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// ── ingest_signature: determinism ────────────────────────────────────────────

$ts     = '1720000000';
$body   = '{"image_b64":"AAECAw==","channel":"webhook"}';
$secret = 'super-secret-key';

$sig_a = SPSS_REST_API::ingest_signature($ts, $body, $secret);
$sig_b = SPSS_REST_API::ingest_signature($ts, $body, $secret);

assert_test(
    is_string($sig_a) && $sig_a !== '',
    'ingest_signature returns a non-empty string'
);
assert_test(
    $sig_a === $sig_b,
    'ingest_signature is deterministic (same inputs -> same output)'
);

// Matches the documented scheme exactly.
assert_test(
    $sig_a === hash_hmac('sha256', $ts . '.' . $body, $secret),
    'ingest_signature equals hash_hmac(sha256, ts.body, secret)'
);

// ── ingest_signature: sensitivity ────────────────────────────────────────────

assert_test(
    SPSS_REST_API::ingest_signature($ts, $body, 'different-secret') !== $sig_a,
    'Different secret produces a different signature'
);
assert_test(
    SPSS_REST_API::ingest_signature($ts, $body . 'x', $secret) !== $sig_a,
    'Tampered body produces a different signature'
);
assert_test(
    SPSS_REST_API::ingest_signature('1720000001', $body, $secret) !== $sig_a,
    'Different timestamp produces a different signature'
);

// ── ingest_signature: round-trip verification (as the handler does) ──────────

$expected = SPSS_REST_API::ingest_signature($ts, $body, $secret);
assert_test(
    hash_equals($expected, $sig_a),
    'Round-trip: recomputed expected hash_equals the original (valid request)'
);

$tampered_body = $body . '  ';
$forged        = SPSS_REST_API::ingest_signature($ts, $tampered_body, $secret);
assert_test(
    !hash_equals($expected, $forged),
    'Round-trip: signature over a tampered body fails hash_equals'
);

// ── twilio_signature: known vector ───────────────────────────────────────────

// Hand-computed reference:
//   url    = https://example.test/wp-json/spss/v1/twilio
//   params = Body=hi, From=+15551234567,
//            MediaUrl0=https://api.twilio.com/media/abc, MessageSid=SM123
//   token  = test_token_shhh
// After ksort the concat is:
//   url . "Bodyhi" . "From+15551234567"
//       . "MediaUrl0https://api.twilio.com/media/abc" . "MessageSidSM123"
//   expected = base64( HMAC-SHA1(concat, token) )
$twilio_url    = 'https://example.test/wp-json/spss/v1/twilio';
$twilio_params = array(
    'MessageSid' => 'SM123',
    'From'       => '+15551234567',
    'Body'       => 'hi',
    'MediaUrl0'  => 'https://api.twilio.com/media/abc',
);
$twilio_token = 'test_token_shhh';
$twilio_expected = 'cvp/D1rqe3NPnIAB7jPgUWXtMoo=';

$twilio_sig = SPSS_REST_API::twilio_signature($twilio_url, $twilio_params, $twilio_token);
assert_test(
    $twilio_sig === $twilio_expected,
    'twilio_signature matches the hand-computed known vector'
);

// ── twilio_signature: order independence ─────────────────────────────────────

$reordered = array(
    'Body'       => 'hi',
    'MediaUrl0'  => 'https://api.twilio.com/media/abc',
    'MessageSid' => 'SM123',
    'From'       => '+15551234567',
);
assert_test(
    SPSS_REST_API::twilio_signature($twilio_url, $reordered, $twilio_token) === $twilio_sig,
    'twilio_signature is order-independent (ksort makes param order irrelevant)'
);

// Sanity: a different token or url changes the signature.
assert_test(
    SPSS_REST_API::twilio_signature($twilio_url, $twilio_params, 'other') !== $twilio_sig,
    'Different Twilio token produces a different signature'
);
assert_test(
    SPSS_REST_API::twilio_signature($twilio_url . '/', $twilio_params, $twilio_token) !== $twilio_sig,
    'Different URL (trailing slash) produces a different signature'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing handle_ingest() auth gates and size cap ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

$api = new SPSS_REST_API();

// Helper: build a signed ingest request for a given body + secret.
function make_ingest_request($body, $secret, $ts = null, $override_sig = null) {
    $ts        = (null === $ts) ? (string) time() : (string) $ts;
    $signature = (null === $override_sig)
        ? SPSS_REST_API::ingest_signature($ts, $body, $secret)
        : $override_sig;
    return new WP_REST_Request(
        $body,
        array(
            'x-spss-timestamp' => $ts,
            'x-spss-signature' => $signature,
        )
    );
}

// Reset transients so the rate limiter never trips across cases.
function reset_ingest_state($secret = 'shared-secret') {
    $GLOBALS['spss_transients']       = array();
    $GLOBALS['spss_options']          = array('spss_webhook_secret' => $secret);
}

$secret = 'shared-secret';

// (1) Unconfigured secret → spss_not_configured (503).
$GLOBALS['spss_options']    = array();
$GLOBALS['spss_transients'] = array();
$res = $api->handle_ingest(new WP_REST_Request('{}', array('x-spss-timestamp' => (string) time())));
assert_test(
    $res instanceof WP_Error && 'spss_not_configured' === $res->get_error_code()
        && 503 === $res->get_error_data()['status'],
    'handle_ingest: unconfigured secret → spss_not_configured (503)'
);

// (2) Missing timestamp → spss_missing_timestamp (403).
reset_ingest_state($secret);
$res = $api->handle_ingest(new WP_REST_Request('{}', array()));
assert_test(
    $res instanceof WP_Error && 'spss_missing_timestamp' === $res->get_error_code()
        && 403 === $res->get_error_data()['status'],
    'handle_ingest: missing timestamp → spss_missing_timestamp (403)'
);

// (3) Expired timestamp → spss_request_expired (403).
reset_ingest_state($secret);
$body = '{"image_b64":"AAECAw=="}';
$res  = $api->handle_ingest(make_ingest_request($body, $secret, time() - 1000));
assert_test(
    $res instanceof WP_Error && 'spss_request_expired' === $res->get_error_code()
        && 403 === $res->get_error_data()['status'],
    'handle_ingest: expired timestamp (now-1000s) → spss_request_expired (403)'
);

// (4) Bad signature → spss_invalid_signature (403).
reset_ingest_state($secret);
$res = $api->handle_ingest(make_ingest_request($body, $secret, time(), 'deadbeef'));
assert_test(
    $res instanceof WP_Error && 'spss_invalid_signature' === $res->get_error_code()
        && 403 === $res->get_error_data()['status'],
    'handle_ingest: bad signature → spss_invalid_signature (403)'
);

// (5) Valid signature but bad base64 → spss_bad_image (400). Proves auth passed.
reset_ingest_state($secret);
$body = '{"image_b64":"!!!not-base64!!!"}';
$res  = $api->handle_ingest(make_ingest_request($body, $secret));
assert_test(
    $res instanceof WP_Error && 'spss_bad_image' === $res->get_error_code()
        && 400 === $res->get_error_data()['status'],
    'handle_ingest: valid sig + bad base64 → spss_bad_image (400) [auth passed]'
);

// (6) Valid signature + oversized image → spss_image_too_large (413) [FIX B].
reset_ingest_state($secret);
$big_bytes = str_repeat("\x00", (15 * 1024 * 1024) + 16);
$body      = '{"image_b64":"' . base64_encode($big_bytes) . '"}';
$res       = $api->handle_ingest(make_ingest_request($body, $secret));
assert_test(
    $res instanceof WP_Error && 'spss_image_too_large' === $res->get_error_code()
        && 413 === $res->get_error_data()['status'],
    'handle_ingest: valid sig + oversized image → spss_image_too_large (413)'
);
unset($big_bytes, $body);

// (7) Valid signature + valid image → success (status 'queued', sheet id).
reset_ingest_state($secret);
SPSS_Ingest_Service::$result = 4242;
$body = '{"image_b64":"' . base64_encode('a real little image') . '","channel":"webhook"}';
$res  = $api->handle_ingest(make_ingest_request($body, $secret));
assert_test(
    $res instanceof WP_REST_Response
        && is_array($res->get_data())
        && 'queued' === $res->get_data()['status']
        && 4242 === $res->get_data()['sheet_id'],
    'handle_ingest: valid sig + valid image → queued (sheet_id passthrough)'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing is_allowed_twilio_media_url() (SSRF host allow-list) ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// Reflection: the helper is private (it is a security control, not public API).
$allow = new ReflectionMethod('SPSS_REST_API', 'is_allowed_twilio_media_url');
$allow->setAccessible(true);
$is_allowed = function ($url) use ($allow) {
    return $allow->invoke(null, $url);
};

assert_test(
    true === $is_allowed('https://api.twilio.com/2010-04-01/Accounts/AC/Messages/MM/Media/ME'),
    'is_allowed_twilio_media_url: accepts https://api.twilio.com/...'
);
assert_test(
    true === $is_allowed('https://media.twiliocdn.com/AC/abc123'),
    'is_allowed_twilio_media_url: accepts https://media.twiliocdn.com/...'
);
assert_test(
    false === $is_allowed('http://api.twilio.com/media/abc'),
    'is_allowed_twilio_media_url: rejects http:// (non-https)'
);
assert_test(
    false === $is_allowed('https://evil.com/media/abc'),
    'is_allowed_twilio_media_url: rejects https://evil.com/...'
);
assert_test(
    false === $is_allowed('https://api.twilio.com.evil.com/media/abc'),
    'is_allowed_twilio_media_url: rejects suffix-spoof api.twilio.com.evil.com'
);
assert_test(
    false === $is_allowed('https://169.254.169.254/latest/meta-data/'),
    'is_allowed_twilio_media_url: rejects link-local metadata IP (SSRF)'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== Testing WhatsApp (Meta Cloud API) ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// whatsapp_signature: hex HMAC-SHA256 of the raw body with the app secret.
$wa_body   = '{"entry":[{"changes":[{"value":{"messages":[]}}]}]}';
$wa_secret = 'meta-app-secret';
$wa_sig    = SPSS_REST_API::whatsapp_signature($wa_body, $wa_secret);
assert_test(
    $wa_sig === hash_hmac('sha256', $wa_body, $wa_secret),
    'whatsapp_signature equals hash_hmac(sha256, body, app_secret)'
);
assert_test(
    SPSS_REST_API::whatsapp_signature($wa_body . 'x', $wa_secret) !== $wa_sig,
    'whatsapp_signature: tampered body changes the signature'
);

// ── Webhook verification (GET) ───────────────────────────────────────────────

$GLOBALS['spss_options']    = array('spss_whatsapp_verify_token' => 'my-verify-token');
$GLOBALS['spss_transients'] = array();

$res = $api->handle_whatsapp_verify(new WP_REST_Request('', array(), array(), array(
    'hub_mode'         => 'subscribe',
    'hub_verify_token' => 'my-verify-token',
    'hub_challenge'    => 'CHALLENGE_123',
)));
assert_test(
    $res instanceof WP_REST_Response && 'CHALLENGE_123' === $res->get_data(),
    'handle_whatsapp_verify: valid token echoes hub.challenge verbatim'
);

$res = $api->handle_whatsapp_verify(new WP_REST_Request('', array(), array(), array(
    'hub_mode'         => 'subscribe',
    'hub_verify_token' => 'WRONG-token',
    'hub_challenge'    => 'CHALLENGE_123',
)));
assert_test(
    $res instanceof WP_Error && 403 === ($res->get_error_data()['status'] ?? 0),
    'handle_whatsapp_verify: wrong verify token → 403'
);

$GLOBALS['spss_options'] = array(); // no verify token configured
$res = $api->handle_whatsapp_verify(new WP_REST_Request('', array(), array(), array(
    'hub_mode'         => 'subscribe',
    'hub_verify_token' => 'anything',
    'hub_challenge'    => 'x',
)));
assert_test(
    $res instanceof WP_Error && 403 === ($res->get_error_data()['status'] ?? 0),
    'handle_whatsapp_verify: unconfigured verify token → 403 (never echoes)'
);

// ── Inbound messages (POST) ──────────────────────────────────────────────────

// (1) Unconfigured → ack 200 so Meta doesn't retry, process nothing.
$GLOBALS['spss_options']    = array();
$GLOBALS['spss_transients'] = array();
$res = $api->handle_whatsapp(new WP_REST_Request($wa_body, array('x-hub-signature-256' => 'sha256=' . $wa_sig)));
assert_test(
    $res instanceof WP_REST_Response && 'received' === ($res->get_data()['status'] ?? ''),
    'handle_whatsapp: unconfigured → 200 received'
);

// (2) Configured + bad signature → 403.
$GLOBALS['spss_options'] = array(
    'spss_whatsapp_app_secret'   => $wa_secret,
    'spss_whatsapp_access_token' => 'access-token',
);
$GLOBALS['spss_transients'] = array();
$res = $api->handle_whatsapp(new WP_REST_Request($wa_body, array('x-hub-signature-256' => 'sha256=deadbeef')));
assert_test(
    $res instanceof WP_Error && 403 === ($res->get_error_data()['status'] ?? 0),
    'handle_whatsapp: bad X-Hub-Signature-256 → 403'
);

// (3) Configured + missing signature header → 403.
$GLOBALS['spss_transients'] = array();
$res = $api->handle_whatsapp(new WP_REST_Request($wa_body, array()));
assert_test(
    $res instanceof WP_Error && 403 === ($res->get_error_data()['status'] ?? 0),
    'handle_whatsapp: missing signature header → 403'
);

// (4) Configured + valid signature (no media messages) → 200 received (auth passed).
$GLOBALS['spss_transients'] = array();
$res = $api->handle_whatsapp(new WP_REST_Request($wa_body, array('x-hub-signature-256' => 'sha256=' . $wa_sig)));
assert_test(
    $res instanceof WP_REST_Response && 'received' === ($res->get_data()['status'] ?? ''),
    'handle_whatsapp: valid signature → 200 received (auth passed)'
);

// ── is_allowed_whatsapp_media_url (SSRF host allow-list) ─────────────────────

echo "\n=== Testing is_allowed_whatsapp_media_url() ===\n\n";
$wa_allow_m = new ReflectionMethod('SPSS_REST_API', 'is_allowed_whatsapp_media_url');
$wa_allow_m->setAccessible(true);
$wa_allow = function ($url) use ($wa_allow_m) {
    return $wa_allow_m->invoke(null, $url);
};
assert_test(true === $wa_allow('https://graph.facebook.com/v21.0/12345'), 'accepts https://graph.facebook.com/...');
assert_test(true === $wa_allow('https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=abc'), 'accepts https://lookaside.fbsbx.com/...');
assert_test(true === $wa_allow('https://scontent-abc.xx.fbcdn.net/v/t1/xyz'), 'accepts https://*.fbcdn.net/...');
assert_test(false === $wa_allow('http://graph.facebook.com/v21.0/12345'), 'rejects http:// (non-https)');
assert_test(false === $wa_allow('https://evil.com/v21.0/12345'), 'rejects https://evil.com/...');
assert_test(false === $wa_allow('https://graph.facebook.com.evil.com/x'), 'rejects suffix-spoof graph.facebook.com.evil.com');
assert_test(false === $wa_allow('https://169.254.169.254/latest/meta-data/'), 'rejects link-local metadata IP (SSRF)');

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
