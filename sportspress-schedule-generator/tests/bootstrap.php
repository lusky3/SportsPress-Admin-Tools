<?php
/**
 * Test Bootstrap File
 * 
 * @author Cody (lusky3)
 */

// Define test environment
define('SPSG_TESTING', true);

// Mock WordPress functions for testing
if (!function_exists('wp_die')) {
    function wp_die($message = '') {
        throw new Exception($message);
    }
}

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

if (!function_exists('_e')) {
    function _e($text, $domain = 'default') {
        echo $text;
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_NOQUOTES, 'UTF-8');
    }
}

if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        return $default;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $errors = array();
        private $error_data = array();
        
        public function __construct($code = '', $message = '', $data = '') {
            if (!empty($code)) {
                $this->errors[$code][] = $message;
                if (!empty($data)) {
                    $this->error_data[$code] = $data;
                }
            }
        }
        
        public function get_error_message($code = '') {
            if (empty($code)) {
                $code = $this->get_error_code();
            }
            if (isset($this->errors[$code])) {
                return $this->errors[$code][0];
            }
            return '';
        }
        
        public function get_error_code() {
            $codes = array_keys($this->errors);
            return empty($codes) ? '' : $codes[0];
        }
    }
}

// Define plugin constants
define('SPSG_PLUGIN_PATH', dirname(__DIR__) . '/');
define('SPSG_PLUGIN_URL', 'http://localhost/wp-content/plugins/sportspress-schedule-generator/');

// Load plugin files
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-registry.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/models/class-game.php';

// Simple test framework
class SPSG_Test_Case {
    protected $name;
    protected $assertions = 0;
    protected $failures = 0;
    
    public function __construct($name = '') {
        $this->name = $name ?: get_class($this);
    }
    
    public function run() {
        echo "Running {$this->name}...\n";
        $this->setUp();
        $this->runTest();
        $this->tearDown();
        echo "Assertions: {$this->assertions}, Failures: {$this->failures}\n\n";
        return $this->failures === 0;
    }
    
    protected function setUp() {}
    protected function tearDown() {}
    protected function runTest() {}
    
    protected function assertTrue($condition, $message = '') {
        $this->assertions++;
        if (!$condition) {
            $this->failures++;
            echo "FAIL: " . ($message ?: 'Assertion failed') . "\n";
        }
    }
    
    protected function assertFalse($condition, $message = '') {
        $this->assertTrue(!$condition, $message);
    }
    
    protected function assertEquals($expected, $actual, $message = '') {
        $this->assertions++;
        if ($expected !== $actual) {
            $this->failures++;
            echo "FAIL: " . ($message ?: "Expected '$expected', got '$actual'") . "\n";
        }
    }
    
    protected function assertInstanceOf($expected, $actual, $message = '') {
        $this->assertTrue($actual instanceof $expected, $message ?: "Expected instance of $expected");
    }
}