<?php
/**
 * Verification script for inter-division games validation
 * 
 * This script verifies that the validation logic for inter-division games
 * is working correctly.
 */

// Mock WordPress functions
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = array()) {
        die($message);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return strip_tags(trim($str));
    }
}

if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}

if (!function_exists('wp_timezone_string')) {
    function wp_timezone_string() {
        return 'UTC';
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('absint')) {
    function absint($value) {
        return abs((int) $value);
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        
        public function __construct($code, $message, $data = array()) {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        
        public function get_error_message() {
            return $this->message;
        }
        
        public function get_error_data() {
            return $this->data;
        }
    }
}

function is_wp_error($thing) {
    return ($thing instanceof WP_Error);
}

// Load the Schedule Configuration class
require_once dirname(__FILE__) . '/../includes/class-schedule-configuration.php';

echo "Inter-Division Games Validation Verification\n";
echo "============================================\n\n";

// Test 1: Valid inter-division games configuration
echo "Test 1: Valid inter-division games (within games per team limit)\n";
$valid_config = array(
    'season_start' => '2024-03-01',
    'season_end' => '2024-06-30',
    'games_per_team' => 14,
    'match_length' => 60,
    'playing_days' => array('saturday', 'sunday'),
    'time_slots' => array(
        'saturday' => array('09:00', '10:00', '11:00', '13:00', '14:00'),
        'sunday' => array('09:00', '10:00', '11:00', '13:00', '14:00')
    ),
    'divisions' => array(
        array(
            'id' => 'div_1',
            'name' => 'Division A',
            'teams' => array('Team 1', 'Team 2', 'Team 3', 'Team 4')
        ),
        array(
            'id' => 'div_2',
            'name' => 'Division B',
            'teams' => array('Team 5', 'Team 6', 'Team 7', 'Team 8')
        )
    ),
    'venues' => array(
        array(
            'id' => 'venue_1',
            'name' => 'Main Field',
            'capacity' => 100,
            'available_days' => array('saturday', 'sunday')
        )
    ),
    'inter_division_games' => array(
        'div_1_div_2' => 4  // 4 inter-division games, well within 14 total
    ),
    'matchup_style' => 'double_round_robin'
);

$config = new SPSG_Schedule_Configuration($valid_config);
$result = $config->validate();

if ($result === true) {
    echo "   ✓ PASS: Valid configuration accepted\n";
} else {
    echo "   ✗ FAIL: Valid configuration rejected\n";
    if (is_wp_error($result)) {
        echo "   Error: " . $result->get_error_message() . "\n";
    }
}

// Test 2: Inter-division games exceeding total games per team
echo "\nTest 2: Inter-division games exceeding games per team limit\n";
$invalid_config = $valid_config;
$invalid_config['games_per_team'] = 10;
$invalid_config['inter_division_games'] = array(
    'div_1_div_2' => 12  // 12 > 10, should fail
);

$config2 = new SPSG_Schedule_Configuration($invalid_config);
$result2 = $config2->validate();

if (is_wp_error($result2)) {
    $error_data = $result2->get_error_data();
    if (isset($error_data['errors']['inter_division_games'])) {
        echo "   ✓ PASS: Invalid configuration rejected with correct error\n";
        echo "   Error message: " . $error_data['errors']['inter_division_games'] . "\n";
    } else {
        echo "   ✗ FAIL: Invalid configuration rejected but wrong error\n";
        print_r($error_data);
    }
} else {
    echo "   ✗ FAIL: Invalid configuration was accepted\n";
}

// Test 3: Empty inter-division games (should be valid)
echo "\nTest 3: Empty inter-division games configuration\n";
$empty_config = $valid_config;
$empty_config['inter_division_games'] = array();

$config3 = new SPSG_Schedule_Configuration($empty_config);
$result3 = $config3->validate();

if ($result3 === true) {
    echo "   ✓ PASS: Empty inter-division games accepted\n";
} else {
    echo "   ✗ FAIL: Empty inter-division games rejected\n";
    if (is_wp_error($result3)) {
        echo "   Error: " . $result3->get_error_message() . "\n";
    }
}

// Test 4: Multiple division pairs with total exceeding limit
echo "\nTest 4: Multiple division pairs with total exceeding limit\n";
$multi_config = $valid_config;
$multi_config['games_per_team'] = 10;
$multi_config['divisions'][] = array(
    'id' => 'div_3',
    'name' => 'Division C',
    'teams' => array('Team 9', 'Team 10', 'Team 11', 'Team 12')
);
$multi_config['inter_division_games'] = array(
    'div_1_div_2' => 4,
    'div_1_div_3' => 4,
    'div_2_div_3' => 4  // Total: 12 > 10
);

$config4 = new SPSG_Schedule_Configuration($multi_config);
$result4 = $config4->validate();

if (is_wp_error($result4)) {
    $error_data = $result4->get_error_data();
    if (isset($error_data['errors']['inter_division_games'])) {
        echo "   ✓ PASS: Multiple pairs exceeding limit rejected\n";
        echo "   Error message: " . $error_data['errors']['inter_division_games'] . "\n";
    } else {
        echo "   ✗ FAIL: Rejected but wrong error\n";
    }
} else {
    echo "   ✗ FAIL: Invalid configuration was accepted\n";
}

// Test 5: Multiple division pairs within limit
echo "\nTest 5: Multiple division pairs within limit\n";
$multi_valid_config = $multi_config;
$multi_valid_config['games_per_team'] = 20;
$multi_valid_config['inter_division_games'] = array(
    'div_1_div_2' => 4,
    'div_1_div_3' => 3,
    'div_2_div_3' => 3  // Total: 10 < 20
);

$config5 = new SPSG_Schedule_Configuration($multi_valid_config);
$result5 = $config5->validate();

if ($result5 === true) {
    echo "   ✓ PASS: Multiple pairs within limit accepted\n";
} else {
    echo "   ✗ FAIL: Valid configuration rejected\n";
    if (is_wp_error($result5)) {
        $error_data = $result5->get_error_data();
        if (isset($error_data['errors'])) {
            print_r($error_data['errors']);
        }
    }
}

echo "\n============================================\n";
echo "Validation verification complete!\n";
