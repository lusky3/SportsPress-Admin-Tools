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
if (!function_exists('__')) {
    function __($text, $domain = 'default') { return $text; }
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

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
