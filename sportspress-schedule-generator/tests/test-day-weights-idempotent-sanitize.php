<?php
/**
 * Test: sanitizing an already-sanitized distribution_rules (one that carries
 * `day_ratios`, not the raw `day_weights` form field) preserves the ratios
 * instead of silently dropping them.
 *
 * SPSG_Admin_Ajax::ajax_save_config() sanitizes $_POST once
 * (sanitize_form_data()) before calling SPSG_Configuration_Manager::save(),
 * which sanitizes its input a SECOND time internally -- every real save runs
 * the sanitizer twice. sanitize_distribution_rules()'s day_weights ->
 * day_ratios conversion is one-way: the raw `day_weights` key is consumed and
 * replaced by `day_ratios`. Re-running the sanitizer on that output (the
 * second pass) found no `day_weights` key and produced a distribution_rules
 * with no day_ratios at all, so every real Save of a custom day-weight split
 * (e.g. 70% Friday / 30% Sunday) silently reverted to an even split the
 * moment it hit the database -- confirmed 2026-09-09 against the real
 * "winter_2026-28_v1" config on Tikal: the user set Day Weights to 70/30 and
 * saved, but the persisted config still showed day_balance: [] with no
 * day_ratios, and the generated schedule came out 64/36 -> after the fix,
 * 48/52 (Sunday-favoured) before -- schedule_helper's resolve_day_ratios()
 * was faithfully honouring the *actual* stored (even-split) target the whole
 * time, not misbehaving on its own.
 *
 * Standalone -- no WordPress dependency at all (pure sanitizer logic).
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}

require_once dirname( __FILE__ ) . '/../includes/class-configuration-sanitizer.php';

$passed = 0;
$failed = 0;

function dwis_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

$sanitizer = new SPSG_Configuration_Sanitizer();

echo "=== Testing a single sanitize pass converts day_weights -> day_ratios ===\n\n";

$first_pass = $sanitizer->sanitize(
	array(
		'distribution_rules' => array(
			'day_weights' => array( 'friday' => '70', 'sunday' => '30' ),
		),
	)
);

dwis_assert(
	isset( $first_pass['distribution_rules']['day_ratios'] )
		&& 0.7 === $first_pass['distribution_rules']['day_ratios']['friday']
		&& 0.3 === $first_pass['distribution_rules']['day_ratios']['sunday'],
	'first sanitize pass: 70/30 day_weights convert to day_ratios {friday:0.7, sunday:0.3}'
);

echo "\n=== Testing a second sanitize pass (the real save() double-sanitize) preserves it ===\n\n";

// This is exactly SPSG_Configuration_Manager::save()'s internal re-sanitize
// of its own (already-sanitized) input -- ajax_save_config() sanitizes
// $_POST once, then passes that result into save(), which sanitizes again.
$second_pass = $sanitizer->sanitize( $first_pass );

dwis_assert(
	isset( $second_pass['distribution_rules']['day_ratios'] ),
	'second sanitize pass: day_ratios key still present (previously silently dropped entirely)'
);
dwis_assert(
	isset( $second_pass['distribution_rules']['day_ratios']['friday'] )
		&& 0.7 === $second_pass['distribution_rules']['day_ratios']['friday'],
	'second sanitize pass: Friday ratio still 0.7, not reverted to an even split'
);
dwis_assert(
	isset( $second_pass['distribution_rules']['day_ratios']['sunday'] )
		&& 0.3 === $second_pass['distribution_rules']['day_ratios']['sunday'],
	'second sanitize pass: Sunday ratio still 0.3'
);

echo "\n=== Testing a config with no day weighting configured stays that way through two passes ===\n\n";

$no_weighting_first = $sanitizer->sanitize( array( 'distribution_rules' => array() ) );
$no_weighting_second = $sanitizer->sanitize( $no_weighting_first );

dwis_assert(
	! isset( $no_weighting_second['distribution_rules']['day_ratios'] ),
	'a config with no day_weights/day_ratios at all does not spuriously gain a day_ratios key'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
