<?php
/**
 * Standalone tests for the pure part of SPEM_Dynamic_Schedule (no WordPress).
 * The SportsPress call itself is verified on staging.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-schedule-args.php';
require_once __DIR__ . '/../includes/class-dynamic-schedule.php';

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

echo "\n=== build_event_list_shortcode() ===\n\n";

assert_test(
	'[event_list id="123" status="any" season="12"]' === SPEM_Dynamic_Schedule::build_event_list_shortcode(
		array(
			'id'     => '123',
			'status' => 'any',
			'season' => '12',
		)
	),
	'builds the shortcode text from non-empty keys'
);

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode(
	array(
		'id'     => '123',
		'league' => '',
		'team'   => '',
		'season' => '12',
	)
);
assert_test( false === strpos( $out, 'league' ) && false === strpos( $out, 'team' ), 'empty keys are omitted' );
assert_test( '[event_list]' === SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'team' => '' ) ), 'all-empty gives a bare shortcode' );

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'team' => 'a"b]c' ) );
assert_test( 1 === preg_match( '/^\[event_list team="[^"\]]*"\]$/', $out ), 'a quote or bracket in a value cannot break out' );

$out = SPEM_Dynamic_Schedule::build_event_list_shortcode( array( 'columns' => 'event,time' ) );
assert_test( '[event_list columns="event,time"]' === $out, 'commas in a value are kept' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
