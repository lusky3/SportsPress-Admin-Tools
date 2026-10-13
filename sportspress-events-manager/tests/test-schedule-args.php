<?php
/**
 * Standalone tests for SPEM_Schedule_Args (pure logic, no WordPress).
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-schedule-args.php';

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

echo "\n=== normalize_status() ===\n\n";

assert_test( 'any' === SPEM_Schedule_Args::normalize_status( 'all' ), 'all maps to any' );
assert_test( 'future' === SPEM_Schedule_Args::normalize_status( 'upcoming' ), 'upcoming maps to future' );
assert_test( 'publish' === SPEM_Schedule_Args::normalize_status( 'results' ), 'results maps to publish' );
assert_test( 'publish' === SPEM_Schedule_Args::normalize_status( 'publish' ), 'publish passes through' );
assert_test( 'future' === SPEM_Schedule_Args::normalize_status( 'future' ), 'future passes through' );
assert_test( 'any' === SPEM_Schedule_Args::normalize_status( 'any' ), 'any passes through' );
assert_test( null === SPEM_Schedule_Args::normalize_status( 'bogus' ), 'unknown status is null' );

echo "\n=== normalize_atts() ===\n\n";

assert_test(
	array(
		'id'     => '123',
		'league' => 'd5',
		'status' => 'any',
	) == SPEM_Schedule_Args::normalize_atts(
		array(
			'ID'       => '123',
			'DIVISION' => 'd5',
			'STATUS'   => 'all',
		)
	),
	'keys lower-cased, division mapped to league, status normalised'
);
assert_test(
	array() === SPEM_Schedule_Args::normalize_atts(
		array(
			'team'  => '',
			'bogus' => 'x',
		)
	),
	'empty values and unknown keys are dropped'
);
assert_test(
	array() === SPEM_Schedule_Args::normalize_atts( array( 'status' => 'bogus' ) ),
	'an invalid status is dropped'
);

$out = SPEM_Schedule_Args::normalize_atts( array( 'calendar' => '123' ) );
assert_test( array( 'id' => '123' ) === $out, 'calendar is an alias of id' );
$out = SPEM_Schedule_Args::normalize_atts( array( 'id' => '5', 'calendar' => '123' ) );
assert_test( array( 'id' => '5' ) === $out, 'id wins when both id and calendar are given' );
$out = SPEM_Schedule_Args::normalize_atts( array( 'calendar' => '123', 'id' => '5' ) );
assert_test( array( 'id' => '5' ) === $out, 'id wins regardless of attribute order' );
$pass = array( 'venue', 'day', 'format', 'rows', 'paginated', 'sortable', 'scrollable', 'responsive', 'show_team_logo', 'link_events', 'link_teams', 'link_venues', 'show_title', 'title', 'title_format', 'time_format' );
foreach ( $pass as $key ) {
	assert_test( array( $key => 'x' ) === SPEM_Schedule_Args::normalize_atts( array( strtoupper( $key ) => ' x ' ) ), "{$key} is passed through as a scalar attribute" );
}
assert_test( array() === SPEM_Schedule_Args::normalize_atts( array( 'venue' => array( '1' ) ) ), 'a non-scalar pass-through value is dropped' );

echo "\n=== merge() ===\n\n";

$merged = SPEM_Schedule_Args::merge(
	array(
		'status' => 'future',
		'team'   => '7',
	),
	array( 'team' => 'all' )
);
assert_test( array_key_exists( 'team', $merged ) && '' === $merged['team'], 'visitor all clears the key but keeps it present' );
assert_test( 'future' === $merged['status'], 'untouched attribute values are kept' );

$merged = SPEM_Schedule_Args::merge( array( 'team' => '7' ), array( 'team' => '9' ) );
assert_test( '9' === $merged['team'], 'visitor value overrides attribute value' );

echo "\n=== drop_initial_only() ===\n\n";

$args = array(
	'number'    => '5',
	'status'    => 'future',
	'date'      => 'range',
	'date_from' => '2026-01-01',
);
$out  = SPEM_Schedule_Args::drop_initial_only( $args, array( 'season' => 'w2025-26' ) );
assert_test( ! isset( $out['date'] ) && ! isset( $out['date_from'] ), 'a visitor season drops the date window' );
assert_test( ! isset( $out['number'] ) && 'future' === $out['status'], 'a visitor season drops the number limit too (initial view only)' );
$out = SPEM_Schedule_Args::drop_initial_only( $args, array( 'status' => 'all' ) );
assert_test( ! isset( $out['date'] ) && ! isset( $out['number'] ), 'a visitor status drops the window and the number' );
assert_test(
	$args === SPEM_Schedule_Args::drop_initial_only( $args, array( 'team' => '7' ) ),
	'a visitor team keeps everything, number included'
);
assert_test(
	$args === SPEM_Schedule_Args::drop_initial_only( $args, array( 'league' => 'd1' ) ),
	'a visitor division keeps everything, number included'
);

echo "\n=== cache_key() ===\n\n";

$a = array(
	'team'   => '7',
	'status' => 'future',
);
$b = array(
	'status' => 'future',
	'team'   => '7',
);
assert_test( SPEM_Schedule_Args::cache_key( 2, $a ) === SPEM_Schedule_Args::cache_key( 2, $b ), 'key order does not change the cache key' );
assert_test( SPEM_Schedule_Args::cache_key( 2, $a ) !== SPEM_Schedule_Args::cache_key( 3, $a ), 'version changes the cache key' );
$a['team'] = '8';
assert_test( SPEM_Schedule_Args::cache_key( 2, $a ) !== SPEM_Schedule_Args::cache_key( 2, $b ), 'a value change changes the cache key' );
assert_test( 0 === strpos( SPEM_Schedule_Args::cache_key( 2, $b ), 'spem_schedule_v2_' ), 'cache key carries the version prefix' );
assert_test( 1 === preg_match( '/^spem_schedule_v2_[0-9a-f]{64}$/', SPEM_Schedule_Args::cache_key( 2, $b ) ), 'cache key is the prefix plus a sha256 hex digest' );
assert_test( strlen( SPEM_Schedule_Args::cache_key( 2, $b ) ) < 172, 'cache key fits the transient name limit' );

echo "\n=== is_allowed_visitor_key() ===\n\n";

foreach ( array( 'season', 'league', 'team', 'status' ) as $key ) {
	assert_test( SPEM_Schedule_Args::is_allowed_visitor_key( $key ), "{$key} is allowed" );
}
assert_test( false === SPEM_Schedule_Args::is_allowed_visitor_key( 'id' ), 'id is not allowed' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
