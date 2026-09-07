<?php
/**
 * Standalone tests for SPET_ETransfer_Automation
 *
 * Usage: php test-etransfer-automation.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

$mock_options = array(
    'spet_webhook_secret' => 'test-secret-key'
);

if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        global $mock_options;
        return isset($mock_options[$key]) ? $mock_options[$key] : $default;
    }
}
if (!function_exists('add_action')) {
    function add_action() {}
}
if (!function_exists('register_rest_route')) {
    function register_rest_route() {}
}
if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return trim(preg_replace('/[\r\n\t\0\x0B]+/', ' ', (string) $str));
    }
}

require_once dirname(__FILE__) . '/../includes/class-etransfer-automation.php';

// Test helpers
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

function invoke_private($obj, $method, $args = array()) {
    $ref = new ReflectionMethod($obj, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs($obj, $args);
}

$automation = new SPET_ETransfer_Automation();

echo "=== Testing SPET_ETransfer_Automation ===\n\n";

// --- extract_payment_data tests ---
echo "-- extract_payment_data --\n";

$valid_data = array(
    'text' => "INTERAC e-Transfer\n\nSent From:\n  John Smith\n\nAmount:\n  \$150.00\n\nReference Number:\n  CA1234567890",
    'reply_to' => array('address' => 'john@example.com')
);

$result = invoke_private($automation, 'extract_payment_data', array($valid_data));
assert_test($result !== false, 'Valid Interac email returns data');
assert_test($result['reference_number'] === 'CA1234567890', 'Reference number extracted');
assert_test($result['amount'] == 150.00, 'Amount extracted');
assert_test($result['sender_name'] === 'John Smith', 'Sender name extracted');
assert_test($result['customer_email'] === 'john@example.com', 'Reply-to email extracted (array)');

// Missing reference number
$no_ref = array('text' => "Amount:\n  \$100.00\nSent From:\n  Test User");
$result = invoke_private($automation, 'extract_payment_data', array($no_ref));
assert_test($result === false, 'Missing reference number returns false');

// Missing amount
$no_amount = array('text' => "Reference Number:\n  CA999\nSent From:\n  Test User");
$result = invoke_private($automation, 'extract_payment_data', array($no_amount));
assert_test($result === false, 'Missing amount returns false');

// Amount with commas
$comma_data = array(
    'text' => "Reference Number:\n  CA555\n\nAmount:\n  \$1,250.00\n\nSent From:\n  Jane Doe"
);
$result = invoke_private($automation, 'extract_payment_data', array($comma_data));
assert_test($result !== false && $result['amount'] == 1250.00, 'Amount with commas parsed correctly');

// Reply-to as string
$string_reply = array(
    'text' => "Reference Number:\n  CA111\n\nAmount:\n  \$50.00",
    'reply_to' => 'user@test.com'
);
$result = invoke_private($automation, 'extract_payment_data', array($string_reply));
assert_test($result['customer_email'] === 'user@test.com', 'Reply-to email extracted (string format)');

// Empty text
$empty = array('text' => '');
$result = invoke_private($automation, 'extract_payment_data', array($empty));
assert_test($result === false, 'Empty text returns false');

// No text key
$result = invoke_private($automation, 'extract_payment_data', array(array()));
assert_test($result === false, 'Missing text key returns false');

// Order number: bare 6-digit number in the Message field
$with_order_number = array(
    'text' => "Reference Number:\n  CADzqxQ4\n\nMessage:\nwinter 2025 ARL-114490\n\nDate: July 17, 2025\nSent From:\n  Andy Giang\n\nAmount:\n  \$590.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($with_order_number));
assert_test($result !== false && $result['order_number'] === 114490, 'Order number extracted from Message field, prefix and year ignored');

// Order number: "#" prefix
$hash_order_number = array(
    'text' => "Reference Number:\n  CA1\n\nMessage:\npayment #401\n\nDate: July 17, 2025\nSent From:\n  Test User\n\nAmount:\n  \$50.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($hash_order_number));
assert_test($result !== false && $result['order_number'] === 401, '# prefix is recognized as an order number');

// Order number: "order" word prefix
$order_word = array(
    'text' => "Reference Number:\n  CA1\n\nMessage:\nfor order 401\n\nDate: July 17, 2025\nSent From:\n  Test User\n\nAmount:\n  \$50.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($order_word));
assert_test($result !== false && $result['order_number'] === 401, 'the word "order" is recognized as an order number prefix');

// A bare, undelimited number is deliberately NOT treated as an order number --
// otherwise a memo mentioning only a season year could be misread as one.
$bare_number = array(
    'text' => "Reference Number:\n  CA1\n\nMessage:\nwinter 2025 registration\n\nDate: July 17, 2025\nSent From:\n  Test User\n\nAmount:\n  \$50.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($bare_number));
assert_test($result !== false && $result['order_number'] === null, 'a bare undelimited number (e.g. a season year) is NOT treated as an order number');

// No Message field at all
$no_message = array(
    'text' => "Reference Number:\n  CA1\n\nDate: July 17, 2025\nSent From:\n  Test User\n\nAmount:\n  \$50.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($no_message));
assert_test($result !== false && $result['order_number'] === null, 'No Message field leaves order_number null');

// Message field present but with no number in it
$message_no_number = array(
    'text' => "Reference Number:\n  CA1\n\nMessage:\npayment for winter season goalie\n\nDate: July 17, 2025\nSent From:\n  Test User\n\nAmount:\n  \$50.00"
);
$result = invoke_private($automation, 'extract_payment_data', array($message_no_number));
assert_test($result !== false && $result['order_number'] === null, 'Message with no number leaves order_number null');

// --- verify_signature tests ---
echo "\n-- verify_signature --\n";

$body = '{"test":"data"}';
$timestamp = '2026-04-25T02:00:00Z';
$expected_sig = hash_hmac('sha256', $timestamp . '.' . $body, 'test-secret-key');

$headers_correct = array('x_signature' => array($expected_sig), 'x_timestamp' => array($timestamp));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_correct));
assert_test($result === true, 'Correct signature with timestamp verifies');

$headers_wrong = array('x_signature' => array('bad-signature'), 'x_timestamp' => array($timestamp));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_wrong));
assert_test($result === false, 'Incorrect signature fails');

$headers_empty = array();
$result = invoke_private($automation, 'verify_signature', array($body, $headers_empty));
assert_test($result === false, 'Missing signature header fails');

// No timestamp header should fail (timestamp now required)
$headers_no_ts = array('x_signature' => array(hash_hmac('sha256', $body, 'test-secret-key')));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_no_ts));
assert_test($result === false, 'Missing timestamp header fails verification');

// x-signature/x-timestamp header variant
$headers_dash = array('x-signature' => array($expected_sig), 'x-timestamp' => array($timestamp));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_dash));
assert_test($result === true, 'x-signature/x-timestamp header variant works');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
