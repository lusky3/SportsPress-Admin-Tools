<?php
/**
 * Standalone tests for SPLM_SportsPress_Data::default_season_id().
 *
 * Regression guard: a site's own SportsPress "current season" setting
 * (sportspress_season) was silently ignored everywhere League Manager
 * resolves a default season -- the discipline queue admin page, the
 * evaluation pass, the weekly digest, and the notice-recipients' captain
 * copy all read splm_default_season alone and treated 0 as "unconfigured".
 * But 0 on that option is its own labelled choice ("Use SportsPress current
 * season" -- see class-admin.php's render_default_season_field()), not an
 * absence of configuration, so every one of those features was silently
 * inert on any site that left the override at its own recommended default.
 *
 * Usage: php test-sportspress-data-default-season.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$mock_options = array();

function get_option( $name, $default = false ) {
	global $mock_options;
	return array_key_exists( $name, $mock_options ) ? $mock_options[ $name ] : $default;
}

require_once __DIR__ . '/../includes/class-sportspress-data.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$d = 'SPLM_SportsPress_Data';

echo "\n=== default_season_id(): neither configured ===\n\n";

$mock_options = array();
assert_test( 0 === $d::default_season_id(), 'reports 0 when neither the override nor SportsPress core has a season set' );

echo "\n=== default_season_id(): the League Manager override wins when set ===\n\n";

$mock_options = array(
	'splm_default_season' => 42,
	'sportspress_season'  => 7,
);
assert_test( 42 === $d::default_season_id(), 'a non-zero override is used as-is, even when SportsPress core disagrees' );

echo "\n=== default_season_id(): falls back to SportsPress core's current season ===\n\n";

$mock_options = array(
	'splm_default_season' => 0,
	'sportspress_season'  => 7,
);
assert_test(
	7 === $d::default_season_id(),
	'0 on the override is its own choice ("Use SportsPress current season"), not "unconfigured" -- falls back to sportspress_season'
);

$mock_options = array( 'sportspress_season' => 9 );
assert_test( 9 === $d::default_season_id(), 'an entirely absent override also falls back to sportspress_season' );

echo "\n=== default_season_id(): SportsPress core unset too ===\n\n";

$mock_options = array( 'splm_default_season' => 0 );
assert_test( 0 === $d::default_season_id(), 'reports 0, not a false-y non-zero value, when SportsPress core has no season either' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
