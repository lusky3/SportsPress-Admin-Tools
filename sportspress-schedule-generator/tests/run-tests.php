<?php
/**
 * Test Runner for SportsPress Schedule Generator
 *
 * @author Cody (lusky3)
 */

echo "SportsPress Schedule Generator Test Suite\n";
echo "========================================\n\n";

$test_files = array(
    // Unit Tests
    'unit/test-constraint-registry.php',
    'unit/test-constraint-manager.php',
    'unit/test-game-model.php',
    
    // Integration Tests
    'integration/test-schedule-generation.php',
    'integration/test-export-functionality.php',
    'integration/test-constraint-interactions.php'
);

$total_tests = 0;
$passed_tests = 0;
$failed_tests = 0;

foreach ($test_files as $test_file) {
    $full_path = __DIR__ . '/' . $test_file;
    
    if (file_exists($full_path)) {
        echo "Running $test_file...\n";
        echo str_repeat('-', 50) . "\n";
        
        // Capture output
        ob_start();
        $test_passed = true;
        
        try {
            include $full_path;
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
            $test_passed = false;
        }
        
        $output = ob_get_clean();
        echo $output;
        
        $total_tests++;
        if ($test_passed && strpos($output, 'FAIL:') === false) {
            $passed_tests++;
            echo "✓ PASSED\n";
        } else {
            $failed_tests++;
            echo "✗ FAILED\n";
        }
        
        echo "\n";
    } else {
        echo "WARNING: Test file not found: $test_file\n\n";
    }
}

echo "Test Summary\n";
echo "============\n";
echo "Total Tests: $total_tests\n";
echo "Passed: $passed_tests\n";
echo "Failed: $failed_tests\n";

if ($failed_tests === 0) {
    echo "\n🎉 All tests passed!\n";
    exit(0);
} else {
    echo "\n❌ Some tests failed.\n";
    exit(1);
}