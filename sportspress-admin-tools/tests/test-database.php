<?php
/**
 * Standalone tests for SPAT_Database::migrate_option_to_table
 *
 * The migration is not idempotent (the log tables have no natural key, so a
 * re-run would duplicate rows) and the source option is the only copy of the
 * audit history until every row lands. These tests pin the retention contract:
 * the option is dropped ONLY when the target table exists and every insert
 * succeeded.
 *
 * Usage: php test-database.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

$mock_options = array();
$logged       = array();

if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        global $mock_options;
        return array_key_exists($key, $mock_options) ? $mock_options[$key] : $default;
    }
}
if (!function_exists('update_option')) {
    function update_option($key, $value) {
        global $mock_options;
        $mock_options[$key] = $value;
        return true;
    }
}
if (!function_exists('delete_option')) {
    function delete_option($key) {
        global $mock_options;
        if (array_key_exists($key, $mock_options)) {
            unset($mock_options[$key]);
            return true;
        }
        return false;
    }
}
if (!function_exists('maybe_serialize')) {
    function maybe_serialize($data) {
        return (is_array($data) || is_object($data)) ? serialize($data) : $data;
    }
}

/**
 * Stub logger so class_exists( 'SPAT_Logger' ) is satisfied and the error/warn
 * calls the migration makes on failure become assertable.
 */
class SPAT_Logger {
    public static function error($tag, $message, $context = array()) {
        global $logged;
        $logged[] = array('level' => 'ERROR', 'message' => $message);
    }
    public static function warn($tag, $message, $context = array()) {
        global $logged;
        $logged[] = array('level' => 'WARN', 'message' => $message);
    }
    public static function info($tag, $message, $context = array()) {}
}

/**
 * Minimal $wpdb stand-in. `existing_tables` drives SHOW TABLES; `fail_on` is a
 * 1-based list of INSERT ordinals that should report failure.
 */
class SPAT_Mock_WPDB {

    public $prefix = 'wp_';
    public $last_error = '';
    public $existing_tables = array();
    public $fail_on = array();
    public $queries = array();
    private $insert_count = 0;

    public function reset() {
        $this->queries = array();
        $this->fail_on = array();
        $this->insert_count = 0;
    }

    public function prepare($sql, ...$args) {
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }
        $parts = preg_split('/(%s|%d)/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        $i = 0;
        foreach ($parts as $part) {
            if ($part === '%s') {
                $out .= "'" . (string) $args[$i++] . "'";
            } elseif ($part === '%d') {
                $out .= (int) $args[$i++];
            } else {
                $out .= $part;
            }
        }
        return $out;
    }

    public function get_var($sql) {
        if (preg_match("/^SHOW TABLES LIKE '(.*?)'$/", $sql, $m)) {
            return in_array($m[1], $this->existing_tables, true) ? $m[1] : null;
        }
        return null;
    }

    public function query($sql) {
        $this->queries[] = $sql;
        if (strpos($sql, 'INSERT IGNORE INTO') === 0) {
            $this->insert_count++;
            if (in_array($this->insert_count, $this->fail_on, true)) {
                $this->last_error = 'simulated insert failure';
                return false;
            }
            return 1;
        }
        return 1;
    }
}

$wpdb = new SPAT_Mock_WPDB();

require_once dirname(__FILE__) . '/../includes/class-database.php';

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

function migrate($option_name, $table_name, $mapper) {
    $ref = new ReflectionMethod('SPAT_Database', 'migrate_option_to_table');
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, array($option_name, $table_name, $mapper));
}

function count_inserts() {
    global $wpdb;
    $n = 0;
    foreach ($wpdb->queries as $q) {
        if (strpos($q, 'INSERT IGNORE INTO') === 0) {
            $n++;
        }
    }
    return $n;
}

$mapper = function ($log) {
    return array(
        'timestamp' => $log['timestamp'],
        'action'    => $log['action'],
    );
};

$three_logs = array(
    array('timestamp' => '2026-01-01 00:00:00', 'action' => 'a'),
    array('timestamp' => '2026-01-02 00:00:00', 'action' => 'b'),
    array('timestamp' => '2026-01-03 00:00:00', 'action' => 'c'),
);

echo "=== Testing SPAT_Database::migrate_option_to_table ===\n\n";

// --- missing target table ---
echo "-- missing target table --\n";

$wpdb->reset();
$wpdb->existing_tables = array();
$mock_options = array('spat_src' => $three_logs);
$logged = array();

migrate('spat_src', 'wp_spat_role_logs', $mapper);
assert_test(get_option('spat_src', false) === $three_logs, 'source option is retained when the target table does not exist');
assert_test(count_inserts() === 0, 'no inserts are attempted against a missing table');

// --- happy path ---
echo "\n-- all inserts succeed --\n";

$wpdb->reset();
$wpdb->existing_tables = array('wp_spat_role_logs');
$mock_options = array('spat_src' => $three_logs);
$logged = array();

migrate('spat_src', 'wp_spat_role_logs', $mapper);
assert_test(count_inserts() === 3, 'one insert per log entry');
assert_test(get_option('spat_src', 'gone') === 'gone', 'source option is deleted once every row landed');
assert_test(count($logged) === 0, 'nothing is logged on a clean migration');

// --- retention on failure ---
echo "\n-- an insert fails --\n";

$wpdb->reset();
$wpdb->existing_tables = array('wp_spat_role_logs');
$wpdb->fail_on = array(2);
$mock_options = array('spat_src' => $three_logs);
$logged = array();

migrate('spat_src', 'wp_spat_role_logs', $mapper);
assert_test(count_inserts() === 3, 'a failed insert does not abort the remaining rows');
assert_test(get_option('spat_src', 'gone') === $three_logs, 'source option is RETAINED when any insert failed (audit history is not lost)');

$levels = array();
foreach ($logged as $entry) {
    $levels[] = $entry['level'];
}
assert_test(in_array('ERROR', $levels, true), 'the failing insert is logged as an error');
assert_test(in_array('WARN', $levels, true), 'the retained source option is reported as a warning');

// Every insert failing must behave the same way.
$wpdb->reset();
$wpdb->existing_tables = array('wp_spat_role_logs');
$wpdb->fail_on = array(1, 2, 3);
$mock_options = array('spat_src' => $three_logs);
$logged = array();

migrate('spat_src', 'wp_spat_role_logs', $mapper);
assert_test(get_option('spat_src', 'gone') === $three_logs, 'source option is retained when every insert failed');

// --- empty source ---
echo "\n-- empty source option --\n";

$wpdb->reset();
$wpdb->existing_tables = array('wp_spat_role_logs');
$mock_options = array('spat_src' => array());
$logged = array();

migrate('spat_src', 'wp_spat_role_logs', $mapper);
assert_test(get_option('spat_src', 'gone') === 'gone', 'an empty source option is deleted');
assert_test(count_inserts() === 0, 'an empty source option issues no inserts');

// --- NULL mapping ---
echo "\n-- NULL values --\n";

// A missing reference number must reach the column as SQL NULL, not '': the
// target has a UNIQUE KEY on reference_number and multiple '' rows would all
// collide under INSERT IGNORE, silently dropping all but the first.
$wpdb->reset();
$wpdb->existing_tables = array('wp_spat_etransfer_logs');
$mock_options = array('spat_src' => array(
    array('reference_number' => null, 'result' => 'x'),
    array('reference_number' => null, 'result' => 'y'),
));
$logged = array();

migrate('spat_src', 'wp_spat_etransfer_logs', function ($log) {
    return array(
        'reference_number' => $log['reference_number'],
        'result'           => $log['result'],
    );
});

$null_inserts = 0;
foreach ($wpdb->queries as $q) {
    if (strpos($q, 'INSERT IGNORE INTO') === 0 && strpos($q, 'VALUES (NULL, ') !== false) {
        $null_inserts++;
    }
}
assert_test($null_inserts === 2, 'null values are emitted as literal SQL NULL, not a quoted empty string');
assert_test(get_option('spat_src', 'gone') === 'gone', 'source option is deleted after a clean NULL-bearing migration');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
