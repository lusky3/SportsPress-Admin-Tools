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

/**
 * Terms and product term assignments, so the taxonomy lookups can be driven.
 *
 * `terms` is taxonomy => array of term_id => name.
 * `assigned` is product_id => taxonomy => array of term_ids.
 */
function splm_matcher_terms( ?array $terms = null, ?array $assigned = null ) {
	static $state = array(
		'terms'    => array(),
		'assigned' => array(),
	);
	if ( null !== $terms ) {
		$state['terms'] = $terms;
	}
	if ( null !== $assigned ) {
		$state['assigned'] = $assigned;
	}
	return $state;
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {} // phpcs:ignore

function get_terms( $args ) {
	$state = splm_matcher_terms();
	$out   = array();
	foreach ( $state['terms'][ $args['taxonomy'] ] ?? array() as $id => $name ) {
		$out[] = (object) array(
			'term_id' => $id,
			'name'    => $name,
		);
	}
	return $out;
}

function has_term( $terms, $taxonomy, $post_id ) {
	$state    = splm_matcher_terms();
	$assigned = $state['assigned'][ $post_id ][ $taxonomy ] ?? array();
	return (bool) array_intersect( (array) $terms, $assigned );
}

$GLOBALS['splm_last_query'] = array();
$GLOBALS['splm_query_result'] = array();
function get_posts( $args ) {
	$GLOBALS['splm_last_query'] = $args;
	return $GLOBALS['splm_query_result'];
}

require_once __DIR__ . '/../../sportspress-admin-tools/includes/class-season.php';
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

echo "\n=== marker_terms() and has_marker() ===\n\n";

// The shape the live store actually has: "Registration" is a product CATEGORY,
// "Waitlist" is a product TAG, and every waitlist product sits in the
// registration category too. Reading product_cat alone — which is what the
// design assumed and the code did — makes the waitlist marker invisible.
splm_matcher_terms(
	array(
		'product_cat' => array( 91 => 'Registration', 673 => 'Winter 2026-27' ),
		'product_tag' => array( 7 => 'Player', 8 => 'Goalie', 9 => 'Waitlist' ),
	),
	array(
		// Player Registration (W2026-27) — the target.
		116522 => array(
			'product_cat' => array( 91, 673 ),
			'product_tag' => array( 7 ),
		),
		// Player Waitlist (W2026-27) — registration category AND the waitlist tag.
		117090 => array(
			'product_cat' => array( 91, 673 ),
			'product_tag' => array( 7, 9 ),
		),
	)
);

$waitlist_markers = $m::marker_terms( 'waitlist' );
assert_test( array( 'product_tag' => array( 9 ) ) === $waitlist_markers, 'a tag-only keyword resolves to a product_tag marker' );
assert_test( array( 'product_cat' => array( 91 ) ) === $m::marker_terms( 'registration' ), 'a category-only keyword resolves to a product_cat marker' );
assert_test( array() === $m::marker_terms( 'nonesuch' ), 'a keyword naming no term resolves to an empty map' );

assert_test( $m::has_marker( 117090, $waitlist_markers ), 'a tag-marked waitlist product is recognised' );
assert_test( ! $m::has_marker( 116522, $waitlist_markers ), 'the registration product is not mistaken for a waitlist one' );
assert_test( ! $m::has_marker( 117090, array() ), 'an empty marker map matches nothing, so a blank keyword cannot mark every product' );

assert_test( $m::is_waitlist_product( 117090 ), 'is_waitlist_product() sees the tag — this is the check that gates ingestion' );
assert_test( ! $m::is_waitlist_product( 116522 ), 'is_waitlist_product() is false for a plain registration product' );

// Both taxonomies are consulted, so the design's original category convention
// keeps working if a convener ever creates one.
splm_matcher_terms(
	array(
		'product_cat' => array( 91 => 'Registration', 500 => 'Waitlist' ),
		'product_tag' => array( 7 => 'Player' ),
	),
	array( 200 => array( 'product_cat' => array( 91, 500 ), 'product_tag' => array( 7 ) ) )
);
assert_test( $m::is_waitlist_product( 200 ), 'a category-marked waitlist product still matches' );

echo "\n=== find_target_product() looks for registration in categories only ===\n\n";

// The asymmetry is load-bearing and easy to "tidy up" into a bug. A waitlist
// marker EXCLUDES a candidate, so reading it from an extra taxonomy can only
// narrow the search. A registration marker INCLUDES one, so reading it from
// an extra taxonomy admits candidates — and a single ordinary product tagged
// `Registration` for the same season and position makes the real product
// ambiguous, which select_target() answers with 0, refusing every offer for
// that season.
splm_matcher_terms(
	array(
		'product_cat' => array( 91 => 'Registration' ),
		// A tag by the same name exists on the live store, unused. If the
		// registration lookup ever reads tags again, this is what it will find.
		'product_tag' => array( 662 => 'Registration', 632 => 'Waitlist' ),
	),
	array()
);
$GLOBALS['splm_query_result'] = array();
$m::find_target_product( 'W2026-27', 'player' );

$tax = $GLOBALS['splm_last_query']['tax_query'];
assert_test( 1 === count( $tax ), 'the target query carries exactly one taxonomy clause' );
assert_test( 'product_cat' === $tax[0]['taxonomy'], '  and it is product_cat, never product_tag' );
assert_test( array( 91 ) === $tax[0]['terms'], '  scoped to the registration category, not the identically named tag' );
assert_test( ! isset( $tax['relation'] ), '  with no OR relation widening it across taxonomies' );

// Password-protected products stay out: this league's late-registration
// product is the second same-season candidate that made every target ambiguous.
assert_test( false === $GLOBALS['splm_last_query']['has_password'], 'password-protected products are excluded from the candidate set' );

// A store with no registration category has no target, rather than falling
// back to a tag and guessing.
splm_matcher_terms( array( 'product_cat' => array(), 'product_tag' => array( 662 => 'Registration' ) ), array() );
assert_test( 0 === $m::find_target_product( 'W2026-27', 'player' ), 'a registration keyword that names only a tag finds no target' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
