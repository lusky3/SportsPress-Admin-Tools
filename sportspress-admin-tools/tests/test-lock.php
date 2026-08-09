<?php
/**
 * Standalone tests for SPAT_Lock
 *
 * Exercises the wp_options fallback backend — the one that carries the
 * atomicity guarantees (the object-cache branch just delegates to
 * wp_cache_add). Covers acquire/contention, stale-lock stealing, and the
 * owner-checked release the July fix introduced.
 *
 * Usage: php test-lock.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

if (!function_exists('wp_using_ext_object_cache')) {
    function wp_using_ext_object_cache() { return false; }
}
if (!function_exists('delete_option')) {
    function delete_option($option) {
        global $wpdb;
        if (isset($wpdb->rows[$option])) {
            unset($wpdb->rows[$option]);
            return true;
        }
        return false;
    }
}

/**
 * Minimal $wpdb stand-in backing a wp_options table that enforces the UNIQUE
 * KEY on option_name, so a plain INSERT against an existing key fails exactly
 * the way MySQL rejects it. That rejection is the whole basis of the lock.
 */
class SPAT_Mock_WPDB {

    public $options = 'wp_options';
    public $rows = array();
    private $suppressed = false;

    public function suppress_errors($suppress = true) {
        $previous = $this->suppressed;
        $this->suppressed = $suppress;
        return $previous;
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
        if (preg_match("/^SELECT option_value FROM \S+ WHERE option_name = '(.*?)'$/", $sql, $m)) {
            return isset($this->rows[$m[1]]) ? $this->rows[$m[1]] : null;
        }
        return null;
    }

    public function query($sql) {
        if (preg_match("/^INSERT INTO \S+ \(option_name, option_value, autoload\) VALUES \('(.*?)', '(.*?)', 'no'\)$/", $sql, $m)) {
            if (isset($this->rows[$m[1]])) {
                return false; // Duplicate key — lock already held.
            }
            $this->rows[$m[1]] = $m[2];
            return 1;
        }
        if (preg_match("/^UPDATE \S+ SET option_value = '(.*?)' WHERE option_name = '(.*?)' AND option_value = '(.*?)'$/", $sql, $m)) {
            if (isset($this->rows[$m[2]]) && $this->rows[$m[2]] === $m[3]) {
                $this->rows[$m[2]] = $m[1];
                return 1;
            }
            return 0;
        }
        if (preg_match("/^DELETE FROM \S+ WHERE option_name = '(.*?)' AND option_value = '(.*?)'$/", $sql, $m)) {
            if (isset($this->rows[$m[1]]) && $this->rows[$m[1]] === $m[2]) {
                unset($this->rows[$m[1]]);
                return 1;
            }
            return 0;
        }
        return false;
    }
}

$wpdb = new SPAT_Mock_WPDB();

require_once dirname(__FILE__) . '/../includes/class-lock.php';

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

echo "=== Testing SPAT_Lock ===\n\n";

// --- acquire / contention ---
echo "-- acquire --\n";

$handle = SPAT_Lock::acquire('alpha', 30);
assert_test(is_string($handle) && $handle !== '', 'acquire returns a handle string');
assert_test((int) $handle > time(), 'handle carries an absolute expiry as its numeric prefix');
assert_test(strpos($handle, ':') !== false, 'handle is "<expiry>:<token>"');

$second = SPAT_Lock::acquire('alpha', 30);
assert_test($second === false, 'second acquire of a live lock returns false');

$other = SPAT_Lock::acquire('beta', 30);
assert_test(is_string($other), 'a different key is unaffected by a held lock');
assert_test($other !== $handle, 'each holder gets a distinct handle');

// TTL floor: a zero/negative TTL must still produce a future expiry, otherwise
// the lock would be born stale and instantly stealable.
$zero_ttl = SPAT_Lock::acquire('ttl-floor', 0);
assert_test((int) $zero_ttl > time(), 'TTL is floored at 1 second');

// --- owner-checked release ---
echo "\n-- release --\n";

SPAT_Lock::release('alpha', 'not-the-handle');
assert_test(SPAT_Lock::acquire('alpha', 30) === false, 'release with a foreign handle does NOT free the lock');

SPAT_Lock::release('alpha', $handle);
$reacquired = SPAT_Lock::acquire('alpha', 30);
assert_test(is_string($reacquired), 'release with the owning handle frees the lock');
SPAT_Lock::release('alpha', $reacquired);

// The legacy unconditional delete is still supported for callers that don't
// track their handle — children rely on this signature.
SPAT_Lock::release('beta', null);
$after_legacy = SPAT_Lock::acquire('beta', 30);
assert_test(is_string($after_legacy), 'release( $key, null ) still deletes unconditionally');
SPAT_Lock::release('beta', $after_legacy);

// --- stale-lock stealing ---
echo "\n-- stale steal --\n";

// Plant a lock whose holder crashed: its stored expiry is in the past.
$stale_handle = (time() - 10) . ':deadholder';
$wpdb->rows['spat_lock_gamma'] = $stale_handle;

$stolen = SPAT_Lock::acquire('gamma', 30);
assert_test(is_string($stolen), 'a lock past its expiry can be stolen');
assert_test($wpdb->rows['spat_lock_gamma'] === $stolen, 'the steal replaces the stored handle with the thief\'s');

// A second thief observing the same stale value loses: the guarded UPDATE only
// matches the value it read, which the first thief has already replaced.
$second_thief = SPAT_Lock::acquire('gamma', 30);
assert_test($second_thief === false, 'only one thief wins a stale lock');

// This is the case the owner-checked release exists for: the original holder
// finally finishes, after its TTL lapsed and the slot was stolen, and must not
// delete the new holder's live lock.
SPAT_Lock::release('gamma', $stale_handle);
assert_test(isset($wpdb->rows['spat_lock_gamma']) && $wpdb->rows['spat_lock_gamma'] === $stolen, 'a TTL-overrunning holder cannot release the thief\'s lock');
assert_test(SPAT_Lock::acquire('gamma', 30) === false, 'the stolen lock is still held after the old holder releases');

SPAT_Lock::release('gamma', $stolen);

// A live (unexpired) lock is never stolen.
$live = SPAT_Lock::acquire('delta', 300);
assert_test(SPAT_Lock::acquire('delta', 300) === false, 'a live lock is not stolen even on a retry');
SPAT_Lock::release('delta', $live);

// --- with() ---
echo "\n-- with --\n";

$ran = 0;
$result = SPAT_Lock::with('epsilon', 30, function () use (&$ran) {
    $ran++;
    return 'payload';
});
assert_test($result === 'payload', 'with() returns the callback return value');
assert_test($ran === 1, 'with() runs the callback exactly once');
assert_test(!isset($wpdb->rows['spat_lock_epsilon']), 'with() releases the lock afterwards');

// Contention: the callback must not run and the holder's lock must survive.
$held = SPAT_Lock::acquire('zeta', 30);
$ran_contended = 0;
$contended = SPAT_Lock::with('zeta', 30, function () use (&$ran_contended) {
    $ran_contended++;
    return 'should-not-happen';
});
assert_test($contended === false, 'with() returns false when the lock is held');
assert_test($ran_contended === 0, 'with() does not run the callback when the lock is held');
assert_test($wpdb->rows['spat_lock_zeta'] === $held, 'a failed with() does not disturb the holder\'s lock');
SPAT_Lock::release('zeta', $held);

// A throwing callback must still release (finally), and must not swallow.
$threw = false;
try {
    SPAT_Lock::with('eta', 30, function () {
        throw new RuntimeException('boom');
    });
} catch (RuntimeException $e) {
    $threw = true;
}
assert_test($threw === true, 'with() propagates exceptions from the callback');
assert_test(!isset($wpdb->rows['spat_lock_eta']), 'with() releases the lock even when the callback throws');

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
