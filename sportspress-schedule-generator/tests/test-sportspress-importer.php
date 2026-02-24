<?php
/**
 * Test SportsPress Importer
 *
 * Simple verification test for the importer class
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}

// Define plugin path constant
if (!defined('SPSG_PLUGIN_PATH')) {
    define('SPSG_PLUGIN_PATH', dirname(__FILE__) . '/../');
}

// Mock WordPress WP_Error class for testing
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = array();
        private $error_data = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (empty($code)) {
                return;
            }
            $this->errors[$code][] = $message;
            if (!empty($data)) {
                $this->error_data[$code] = $data;
            }
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return empty($codes) ? '' : $codes[0];
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            $messages = isset($this->errors[$code]) ? $this->errors[$code] : array();
            return empty($messages) ? '' : $messages[0];
        }
        
        public function get_error_messages($code = '') {
            if (empty($code)) {
                $all_messages = array();
                foreach ($this->errors as $code => $messages) {
                    $all_messages = array_merge($all_messages, $messages);
                }
                return $all_messages;
            }
            return isset($this->errors[$code]) ? $this->errors[$code] : array();
        }
    }
}

// Mock is_wp_error function
if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return ($thing instanceof WP_Error);
    }
}

// Mock wp_parse_args function
if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = array()) {
        if (is_object($args)) {
            $parsed_args = get_object_vars($args);
        } elseif (is_array($args)) {
            $parsed_args = &$args;
        } else {
            parse_str($args, $parsed_args);
        }
        
        if (is_array($defaults)) {
            return array_merge($defaults, $parsed_args);
        }
        return $parsed_args;
    }
}

// Mock WordPress functions
if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_n')) {
    function _n($single, $plural, $number, $domain = 'default') {
        return $number === 1 ? $single : $plural;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 1;
    }
}

if (!function_exists('set_transient')) {
    function set_transient($transient, $value, $expiration = 0) {
        return true;
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient($transient) {
        return true;
    }
}

if (!function_exists('error_log')) {
    function error_log($message, $message_type = 0, $destination = null, $extra_headers = null) {
        // Silent for tests
        return true;
    }
}

// Load plugin files
require_once dirname(__FILE__) . '/../includes/class-autoloader.php';
SPSG_Autoloader::init();

echo "=== SportsPress Importer Test ===\n\n";

// Test 1: Class exists
echo "Test 1: Class exists... ";
if (class_exists('SPSG_SportsPress_Importer')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 2: Can instantiate
echo "Test 2: Can instantiate... ";
try {
    $importer = new SPSG_SportsPress_Importer();
    echo "✓ PASS\n";
} catch (Exception $e) {
    echo "✗ FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

// Test 3: Import method exists
echo "Test 3: Import method exists... ";
if (method_exists($importer, 'import')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 4: Import returns error when SportsPress not active
echo "Test 4: Import returns error when SportsPress not active... ";
$result = $importer->import(array());
if (is_wp_error($result)) {
    // Should return either sportspress_inactive or invalid_schedule
    $code = $result->get_error_code();
    if ($code === 'sportspress_inactive' || $code === 'invalid_schedule') {
        echo "✓ PASS (Error code: $code)\n";
    } else {
        echo "✗ FAIL: Unexpected error code: $code\n";
        exit(1);
    }
} else {
    echo "✗ FAIL: Expected WP_Error\n";
    exit(1);
}

// Test 5: Import validates null schedule
echo "Test 5: Import validates null schedule... ";
$result = $importer->import(null);
if (is_wp_error($result)) {
    $code = $result->get_error_code();
    if ($code === 'sportspress_inactive' || $code === 'invalid_schedule') {
        echo "✓ PASS (Error code: $code)\n";
    } else {
        echo "✗ FAIL: Unexpected error code: $code\n";
        exit(1);
    }
} else {
    echo "✗ FAIL: Expected WP_Error\n";
    exit(1);
}

// Test 6: Check conflict detection method exists
echo "Test 6: Conflict detection method exists... ";
$reflection = new ReflectionClass('SPSG_SportsPress_Importer');
if ($reflection->hasMethod('check_conflicts')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 7: Check team mapping method exists
echo "Test 7: Team mapping method exists... ";
if ($reflection->hasMethod('map_teams')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 8: Check venue mapping method exists
echo "Test 8: Venue mapping method exists... ";
if ($reflection->hasMethod('map_venue')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 9: Check logging method exists
echo "Test 9: Logging method exists... ";
if ($reflection->hasMethod('log_import_action')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 10: Check create event method exists
echo "Test 10: Create event method exists... ";
if ($reflection->hasMethod('create_event')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 11: Check update event method exists
echo "Test 11: Update event method exists... ";
if ($reflection->hasMethod('update_event')) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL\n";
    exit(1);
}

// Test 12: Import options parsing
echo "Test 12: Import options parsing... ";
// Create a mock schedule with one game
$mock_game = (object) array(
    'id' => 'game_1',
    'date' => '2024-03-15',
    'time_slot' => '19:00',
    'home_team' => (object) array('name' => 'Team A'),
    'away_team' => (object) array('name' => 'Team B'),
    'venue' => (object) array('name' => 'Arena 1'),
    'division' => (object) array('name' => 'Division A')
);

// Test with dry_run option - should not fail on missing SportsPress
$result = $importer->import(array($mock_game), array('dry_run' => true));
if (is_wp_error($result)) {
    // Expected if SportsPress is not active
    if ($result->get_error_code() === 'sportspress_inactive') {
        echo "✓ PASS (SportsPress not active, as expected)\n";
    } else {
        echo "✗ FAIL: Unexpected error: " . $result->get_error_message() . "\n";
        exit(1);
    }
} else {
    // If SportsPress is active, check results structure
    if (isset($result['imported']) && isset($result['skipped']) && isset($result['failed'])) {
        echo "✓ PASS\n";
    } else {
        echo "✗ FAIL: Invalid results structure\n";
        exit(1);
    }
}

echo "\n=== All Tests Passed ===\n";
exit(0);
