<?php
/**
 * Standalone tests for SPET_Name_Matcher
 *
 * Usage: php test-name-matcher.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

$mock_options = array(
    'spet_equivalent_names' => "Robert|Bob|Rob\nWilliam|Will|Bill\nJames|Jim|Jimmy"
);

if (!function_exists('get_option')) {
    function get_option($key, $default = '') {
        global $mock_options;
        return isset($mock_options[$key]) ? $mock_options[$key] : $default;
    }
}

require_once dirname(__FILE__) . '/../includes/class-name-matcher.php';

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

// Clear cache before tests
SPET_Name_Matcher::clear_cache();

echo "=== Testing SPET_Name_Matcher ===\n\n";

// Exact match
assert_test(
    SPET_Name_Matcher::names_match('John Smith', 'John Smith') === true,
    'Exact name match'
);

// Case-insensitive
assert_test(
    SPET_Name_Matcher::names_match('john smith', 'JOHN SMITH') === true,
    'Case-insensitive match'
);
assert_test(
    SPET_Name_Matcher::names_match('John Smith', 'john smith') === true,
    'Case-insensitive match (mixed case)'
);

// Equivalent names
assert_test(
    SPET_Name_Matcher::names_match('Robert Smith', 'Bob Smith') === true,
    'Equivalent name match (Robert/Bob)'
);
assert_test(
    SPET_Name_Matcher::names_match('William Jones', 'Bill Jones') === true,
    'Equivalent name match (William/Bill)'
);
assert_test(
    SPET_Name_Matcher::names_match('James Brown', 'Jim Brown') === true,
    'Equivalent name match (James/Jim)'
);

// First+last required (not just first name)
assert_test(
    SPET_Name_Matcher::names_match('Robert Smith', 'Robert Jones') === false,
    'Different last names should not match'
);
assert_test(
    SPET_Name_Matcher::names_match('Bob Smith', 'Bob Jones') === false,
    'Same first name, different last name should not match'
);

// Non-matching names
assert_test(
    SPET_Name_Matcher::names_match('John Smith', 'Jane Doe') === false,
    'Completely different names return false'
);
assert_test(
    SPET_Name_Matcher::names_match('John Smith', 'Mike Smith') === false,
    'Different first name, same last name (no equivalence) returns false'
);

// Single-word names
assert_test(
    SPET_Name_Matcher::names_match('Madonna', 'Madonna') === true,
    'Single-word name exact match'
);
assert_test(
    SPET_Name_Matcher::names_match('Madonna', 'Cher') === false,
    'Single-word name non-match'
);

// Names with hyphens/apostrophes
assert_test(
    SPET_Name_Matcher::names_match("Mary-Jane O'Brien", "Mary-Jane O'Brien") === true,
    'Name with hyphen and apostrophe exact match'
);
assert_test(
    SPET_Name_Matcher::names_match("mary-jane o'brien", "MARY-JANE O'BRIEN") === true,
    'Name with hyphen and apostrophe case-insensitive'
);

// Empty/whitespace
assert_test(
    SPET_Name_Matcher::names_match('', '') === true,
    'Empty strings match (case-insensitive compare)'
);
assert_test(
    SPET_Name_Matcher::names_match('  John Smith  ', 'John Smith') === true,
    'Trimmed whitespace match'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
