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
	) === SPEM_Schedule_Args::normalize_atts(
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
assert_test( '5' === $out['number'], 'a visitor season keeps the number limit' );
$out = SPEM_Schedule_Args::drop_initial_only( $args, array( 'status' => 'all' ) );
assert_test( ! isset( $out['date'] ) && '5' === $out['number'], 'a visitor status drops the window, keeps number' );
assert_test(
	$args === SPEM_Schedule_Args::drop_initial_only( $args, array( 'team' => '7' ) ),
	'a visitor team keeps everything'
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

echo "\n=== is_allowed_visitor_key() ===\n\n";

foreach ( array( 'season', 'league', 'team', 'status' ) as $key ) {
	assert_test( SPEM_Schedule_Args::is_allowed_visitor_key( $key ), "{$key} is allowed" );
}
assert_test( false === SPEM_Schedule_Args::is_allowed_visitor_key( 'id' ), 'id is not allowed' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
