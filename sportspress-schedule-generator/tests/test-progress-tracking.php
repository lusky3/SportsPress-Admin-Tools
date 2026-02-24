<?php
/**
 * Test Progress Tracking and Cancellation
 *
 * Simple test to verify progress tracking and cancellation functionality
 */

// Load WordPress test environment
require_once __DIR__ . '/bootstrap.php';

echo "Testing Progress Tracking and Cancellation\n";
echo "==========================================\n\n";

// Test 1: Progress tracking initialization
echo "Test 1: Progress tracking initialization\n";
$engine = new SPSG_Schedule_Engine();

// Use reflection to access private method
$reflection = new ReflectionClass($engine);
$init_method = $reflection->getMethod('init_progress_tracking');
$init_method->setAccessible(true);

// Get progress transient key
$key_property = $reflection->getProperty('progress_transient_key');
$key_property->setAccessible(true);
$transient_key = $key_property->getValue($engine);

echo "Progress transient key: $transient_key\n";

// Initialize progress
$init_method->invoke($engine);

// Check if transient was created
$progress = get_transient($transient_key);
if ($progress !== false) {
    echo "✓ Progress transient created successfully\n";
    echo "  Phase: {$progress['phase']}\n";
    echo "  Percentage: {$progress['percentage']}%\n";
    echo "  Message: {$progress['message']}\n";
} else {
    echo "✗ Failed to create progress transient\n";
}

echo "\n";

// Test 2: Progress updates
echo "Test 2: Progress updates\n";
$update_method = $reflection->getMethod('update_progress');
$update_method->setAccessible(true);

$update_method->invoke($engine, 'matchups', 25, 'Generating matchups...');
$progress = get_transient($transient_key);

if ($progress && $progress['phase'] === 'matchups' && $progress['percentage'] === 25) {
    echo "✓ Progress update successful\n";
    echo "  Phase: {$progress['phase']}\n";
    echo "  Percentage: {$progress['percentage']}%\n";
    echo "  Message: {$progress['message']}\n";
} else {
    echo "✗ Progress update failed\n";
}

echo "\n";

// Test 3: Cancellation flag
echo "Test 3: Cancellation support\n";
$engine->cancel_generation();
$progress = get_transient($transient_key);

if ($progress && isset($progress['cancelled']) && $progress['cancelled'] === true) {
    echo "✓ Cancellation flag set successfully\n";
    echo "  Cancelled: " . ($progress['cancelled'] ? 'true' : 'false') . "\n";
} else {
    echo "✗ Failed to set cancellation flag\n";
}

// Test is_cancelled method
$is_cancelled_method = $reflection->getMethod('is_cancelled');
$is_cancelled_method->setAccessible(true);
$is_cancelled = $is_cancelled_method->invoke($engine);

if ($is_cancelled) {
    echo "✓ is_cancelled() returns true\n";
} else {
    echo "✗ is_cancelled() returns false\n";
}

echo "\n";

// Test 4: Clear progress
echo "Test 4: Clear progress\n";
$clear_method = $reflection->getMethod('clear_progress');
$clear_method->setAccessible(true);
$clear_method->invoke($engine);

$progress = get_transient($transient_key);
if ($progress === false) {
    echo "✓ Progress cleared successfully\n";
} else {
    echo "✗ Failed to clear progress\n";
}

echo "\n";

// Test 5: Get progress (public method)
echo "Test 5: Get progress (public method)\n";
$init_method->invoke($engine);
$progress = $engine->get_progress();

if ($progress !== false && isset($progress['phase'])) {
    echo "✓ get_progress() works correctly\n";
    echo "  Phase: {$progress['phase']}\n";
} else {
    echo "✗ get_progress() failed\n";
}

echo "\n";

// Test 6: Configuration error with suggestions
echo "Test 6: Configuration error with suggestions\n";
$create_error_method = $reflection->getMethod('create_configuration_error');
$create_error_method->setAccessible(true);

$issues = array(
    'Not enough time slots. Need 100 slots but only 50 available.',
    'Season too short. Need at least 30 days but only 20 available.'
);

$error = $create_error_method->invoke($engine, $issues);

if (is_wp_error($error)) {
    echo "✓ Configuration error created successfully\n";
    echo "  Code: {$error->get_error_code()}\n";
    echo "  Message: {$error->get_error_message()}\n";
    
    $data = $error->get_error_data();
    if (isset($data['suggestions']) && !empty($data['suggestions'])) {
        echo "  Suggestions:\n";
        foreach ($data['suggestions'] as $suggestion) {
            echo "    - $suggestion\n";
        }
    }
} else {
    echo "✗ Failed to create configuration error\n";
}

echo "\n";

// Cleanup
delete_transient($transient_key);

echo "==========================================\n";
echo "All tests completed!\n";
