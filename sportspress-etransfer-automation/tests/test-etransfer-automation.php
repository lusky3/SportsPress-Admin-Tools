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

// --- verify_signature tests ---
echo "\n-- verify_signature --\n";

$body = '{"test":"data"}';
$expected_sig = hash_hmac('sha256', $body, 'test-secret-key');

$headers_correct = array('x_signature' => array($expected_sig));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_correct));
assert_test($result === true, 'Correct signature verifies');

$headers_wrong = array('x_signature' => array('bad-signature'));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_wrong));
assert_test($result === false, 'Incorrect signature fails');

$headers_empty = array();
$result = invoke_private($automation, 'verify_signature', array($body, $headers_empty));
assert_test($result === false, 'Missing signature header fails');

// x-signature header variant
$headers_dash = array('x-signature' => array($expected_sig));
$result = invoke_private($automation, 'verify_signature', array($body, $headers_dash));
assert_test($result === true, 'x-signature header variant works');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
