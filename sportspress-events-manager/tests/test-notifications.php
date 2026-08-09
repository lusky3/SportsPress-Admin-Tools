<?php
/**
 * Standalone tests for game-change notification rendering (SPEM_REST_API) and
 * season-name validation (SPEM_Season_Rollover).
 *
 * The headline case is H24: notification emails rendered the ORIGINAL game
 * date shifted by the site's UTC offset, because `post_date` (a site-local
 * wall-clock string) was pushed through `wp_date( $fmt, strtotime( $date ) )`.
 * WordPress pins PHP's timezone to UTC, so strtotime() read the wall clock as
 * a UTC instant and wp_date() then re-rendered it in the site timezone.
 *
 * Usage: php test-notifications.php
 */

// Mock WordPress
define('ABSPATH', dirname(__FILE__) . '/');

// WordPress forces PHP's timezone to UTC on every request.
date_default_timezone_set('UTC');

// Site timezone for the tests — the league this plugin runs for is Ontario,
// which is UTC-4 in August. Any non-UTC zone exposes the bug.
define('SPEM_TEST_SITE_TIMEZONE', 'America/Toronto');

// ── Mock WordPress functions ────────────────────────────────────────────────

function __($text, $domain = 'default') { return $text; }

function add_action() {}

/** Faithful stand-in for WP's wp_timezone(). */
function wp_timezone() {
    return new DateTimeZone(SPEM_TEST_SITE_TIMEZONE);
}

/** Faithful stand-in for WP's wp_date(): render an INSTANT in a timezone. */
function wp_date($format, $timestamp = null, $timezone = null) {
    $tz = $timezone ? $timezone : wp_timezone();
    $dt = new DateTimeImmutable('@' . (int) $timestamp);
    return $dt->setTimezone($tz)->format($format);
}

/**
 * Faithful stand-in for WP's mysql2date(): parse a local wall-clock string in
 * the site timezone, then render it back in the site timezone.
 */
function mysql2date($format, $date, $translate = true) {
    if (empty($date)) {
        return false;
    }
    $tz = wp_timezone();
    $datetime = date_create($date, $tz);
    if (false === $datetime) {
        return false;
    }
    return wp_date($format, $datetime->getTimestamp(), $tz);
}

/**
 * Stand-in for get_the_date()/get_post_time( ..., $translate = true ), which is
 * what the "New date" line uses. WP builds the DateTime from post_date with
 * wp_timezone() and renders it back in wp_timezone() — identical semantics to
 * mysql2date(), which is exactly why the two email lines must agree.
 */
function get_the_date($format, $post) {
    $tz = wp_timezone();
    $datetime = date_create_immutable_from_format('Y-m-d H:i:s', $post->post_date, $tz);
    if (false === $datetime) {
        return false;
    }
    return wp_date($format, $datetime->getTimestamp(), $tz);
}

class WP_Error {
    private $code;
    private $message;
    public function __construct($code = '', $message = '', $data = null) {
        $this->code = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($thing) { return $thing instanceof WP_Error; }

require_once dirname(__FILE__) . '/../includes/class-rest-api.php';
require_once dirname(__FILE__) . '/../includes/class-season-rollover.php';

// ── Test helpers ────────────────────────────────────────────────────────────

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

$api = new SPEM_REST_API();

echo "=== Testing notification date rendering (H24) ===\n\n";

$format = SPEM_REST_API::NOTIFICATION_DATE_FORMAT;

// ── H24 regression guard ────────────────────────────────────────────────────

echo "-- format_notification_date --\n";

$original_post_date = '2026-08-08 19:00:00'; // 7:00 PM local, as stored.
$expected           = 'Saturday, August 8, 2026 at 7:00 PM';

$actual = invoke_private($api, 'format_notification_date', array($original_post_date));
assert_test(
    $actual === $expected,
    "A 19:00 post_date renders as 7:00 PM local (got: $actual)"
);

// The exact call the old code made. Kept verbatim so this test fails loudly if
// anyone reintroduces it.
$old_style = wp_date($format, strtotime($original_post_date));
assert_test(
    $old_style === 'Saturday, August 8, 2026 at 3:00 PM',
    "Legacy wp_date(strtotime(...)) shifts 19:00 to 3:00 PM (got: $old_style)"
);
assert_test(
    $actual !== $old_style,
    'Fixed formatting differs from the legacy double-converted formatting'
);

// The two lines of the reschedule email must agree: "Original date" is rendered
// by format_notification_date(), "New date" by get_the_date().
$event = (object) array('post_date' => $original_post_date);
assert_test(
    get_the_date($format, $event) === $actual,
    'Original-date line agrees with the get_the_date() new-date line'
);

// A date that would cross midnight under the old conversion.
$midnight = '2026-08-08 02:30:00';
assert_test(
    invoke_private($api, 'format_notification_date', array($midnight))
        === 'Saturday, August 8, 2026 at 2:30 AM',
    'A 02:30 post_date keeps its own calendar day (no roll back to the 7th)'
);
assert_test(
    wp_date($format, strtotime($midnight)) === 'Friday, August 7, 2026 at 10:30 PM',
    'Legacy formatting rolled a 02:30 game back to the previous evening'
);

// Winter date — the offset is -5 there, so a hard-coded -4 fix would fail.
assert_test(
    invoke_private($api, 'format_notification_date', array('2026-01-10 19:00:00'))
        === 'Saturday, January 10, 2026 at 7:00 PM',
    'Works across the DST boundary (January, UTC-5)'
);

assert_test(
    invoke_private($api, 'format_notification_date', array('')) === 'Unknown',
    'Empty original date renders as "Unknown"'
);
assert_test(
    invoke_private($api, 'format_notification_date', array('not-a-date')) === 'Unknown',
    'Unparseable original date renders as "Unknown"'
);

// ── Season name validation ──────────────────────────────────────────────────

echo "\n-- is_valid_season_name --\n";

$rollover = new SPEM_Season_Rollover();

$valid = array('W2025', 'S2025', 'W2025-26', 'S2025-26', 'W2025 Playoffs', 'S2025-26 Playoffs');
foreach ($valid as $name) {
    assert_test(
        invoke_private($rollover, 'is_valid_season_name', array($name)) === true,
        "Accepts '$name'"
    );
}

$invalid = array(
    '',                    // empty
    '2025',                // no leading letter
    'W25',                 // two-digit year
    'W2025-2026',          // four-digit span
    'W2025 playoffs',      // lowercase p
    'W2025 Playoffs Extra', // trailing junk
    'W2025;DROP',          // injection-ish junk
    ' W2025',              // leading space
    'W2025 ',              // trailing space
);
foreach ($invalid as $name) {
    assert_test(
        invoke_private($rollover, 'is_valid_season_name', array($name)) === false,
        "Rejects '" . $name . "'"
    );
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
