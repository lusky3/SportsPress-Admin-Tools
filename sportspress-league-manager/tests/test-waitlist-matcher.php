<?php
/**
 * Standalone tests for SPLM_Waitlist_Matcher.
 *
 * select_target() decides which real registration product a waitlist entry
 * will be offered. Getting it wrong either strands an entrant (no target) or
 * points them at the wrong season's product, so every ambiguity case is
 * pinned down here. Ambiguity resolves to 0 — never a guess — because the
 * dashboard can ask a human, and picking silently cannot be undone.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

class SPLM_Matcher_Test_State {
	public $options = array();
}

function splm_matcher_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Matcher_Test_State();
	}
	return $state;
}

function get_option( $name, $default = false ) {
	$state = splm_matcher_test_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}

require_once __DIR__ . '/../includes/class-waitlist-matcher.php';

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

$m = 'SPLM_Waitlist_Matcher';

echo "\n=== matches_keyword() ===\n\n";

assert_test( $m::matches_keyword( 'Waitlist', 'waitlist' ), 'an exact name matches case-insensitively' );
assert_test( $m::matches_keyword( 'S2026 Waitlist', 'waitlist' ), 'a name containing the keyword matches' );
assert_test( $m::matches_keyword( 'registration', 'REGISTRATION' ), 'the keyword itself is matched case-insensitively' );
assert_test( ! $m::matches_keyword( 'S2026 Registration', 'waitlist' ), 'an unrelated name does not match' );
assert_test( ! $m::matches_keyword( 'anything', '' ), 'an empty keyword never matches, so a blank option cannot swallow every product' );
assert_test( ! $m::matches_keyword( '', 'waitlist' ), 'an empty name does not match' );

echo "\n=== keyword defaults ===\n\n";

$state = splm_matcher_test_state();
assert_test( 'waitlist' === $m::keyword(), 'the waitlist keyword defaults to waitlist' );
assert_test( 'registration' === $m::registration_keyword(), 'the registration keyword defaults to SPPR\'s own default' );

$state->options['splm_waitlist_keyword']   = 'queue';
$state->options['spr_registration_keyword'] = 'signup';
assert_test( 'queue' === $m::keyword(), 'a configured waitlist keyword is used' );
assert_test( 'signup' === $m::registration_keyword(), 'SPPR\'s configured registration keyword is honoured' );

$state->options = array();

echo "\n=== select_target() ===\n\n";

function candidate( $id, $season, $position, $is_waitlist = false ) {
	return array(
		'id'          => $id,
		'season'      => $season,
		'position'    => $position,
		'is_waitlist' => $is_waitlist,
	);
}

$one = array( candidate( 11, 'S2026', 'player' ) );
assert_test( 11 === $m::select_target( $one, 'S2026', 'player' ), 'a single exact match is selected' );
assert_test( 0 === $m::select_target( $one, 'S2026', 'goalie' ), 'a position mismatch selects nothing' );
assert_test( 0 === $m::select_target( $one, 'W2026', 'player' ), 'a season mismatch selects nothing' );

assert_test( 0 === $m::select_target( array(), 'S2026', 'player' ), 'no candidates selects nothing' );

$two = array( candidate( 11, 'S2026', 'player' ), candidate( 12, 'S2026', 'player' ) );
assert_test( 0 === $m::select_target( $two, 'S2026', 'player' ), 'two equally valid candidates are ambiguous and select nothing' );

$mixed = array(
	candidate( 11, 'S2026', 'player' ),
	candidate( 12, 'S2026', 'goalie' ),
	candidate( 13, 'W2026', 'player' ),
);
assert_test( 11 === $m::select_target( $mixed, 'S2026', 'player' ), 'the right season and position is picked out of a mixed set' );
assert_test( 12 === $m::select_target( $mixed, 'S2026', 'goalie' ), 'the goalie product is picked for a goalie entry' );

// The whole point of the exclusion: the waitlist SKU itself shares the season
// and position with the product being looked for, so without this filter it
// would be its own target and the claim link would loop back to the waitlist.
$with_waitlist = array(
	candidate( 11, 'S2026', 'player' ),
	candidate( 99, 'S2026', 'player', true ),
);
assert_test( 11 === $m::select_target( $with_waitlist, 'S2026', 'player' ), 'the waitlist product is excluded from its own target search' );

$only_waitlist = array( candidate( 99, 'S2026', 'player', true ) );
assert_test( 0 === $m::select_target( $only_waitlist, 'S2026', 'player' ), 'a set containing only the waitlist product selects nothing' );

$null_season = array( candidate( 11, null, 'player' ) );
assert_test( 0 === $m::select_target( $null_season, 'S2026', 'player' ), 'a candidate with no detectable season is skipped' );

assert_test( 0 === $m::select_target( $one, '', 'player' ), 'an empty season to look for selects nothing rather than matching everything' );

$dupes = array( candidate( 11, 'S2026', 'player' ), candidate( 11, 'S2026', 'player' ) );
assert_test( 11 === $m::select_target( $dupes, 'S2026', 'player' ), 'the same id listed twice is one match, not an ambiguity' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
