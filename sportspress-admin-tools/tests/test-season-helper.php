<?php
/**
 * Standalone tests for SPAT_Season.
 *
 * These predicates decide which season and position a paid registration is
 * attributed to, so they are pinned down here without a WordPress bootstrap.
 * The regexes are the ones SPPR_Player_Registration used before the
 * extraction; the parity assertions at the bottom are what prove that.
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

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPAT_Season_Test_State {
	public $titles     = array();
	public $cat_terms  = array();
	public $tag_terms  = array();
}

function spat_season_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPAT_Season_Test_State();
	}
	return $state;
}

function get_the_title( $post_id ) {
	$state = spat_season_test_state();
	return isset( $state->titles[ $post_id ] ) ? $state->titles[ $post_id ] : '';
}

function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) { // phpcs:ignore
	$state = spat_season_test_state();
	$bag   = ( 'product_cat' === $taxonomy ) ? $state->cat_terms : $state->tag_terms;
	return isset( $bag[ $post_id ] ) ? $bag[ $post_id ] : array();
}

function apply_filters( $hook, $value ) { // phpcs:ignore
	return $value;
}

function term( $name ) {
	return (object) array( 'name' => $name );
}

require_once __DIR__ . '/../includes/class-season.php';

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

$s = 'SPAT_Season';

echo "\n=== from_title() ===\n\n";

assert_test( 'S2026' === $s::from_title( 'S2026 Player Registration' ), 'a summer season code is read from a title' );
assert_test( 'W2025-26' === $s::from_title( 'W2025-26 Goalie Registration' ), 'a split-year winter code is read from a title' );
assert_test( 'W2026' === $s::from_title( 'Registration W2026' ), 'a code at the end of a title is read' );
assert_test( null === $s::from_title( 'Player Registration' ), 'a title with no code returns null' );
assert_test( null === $s::from_title( 'X2026 Registration' ), 'a code with an unknown season letter is not matched' );
assert_test( null === $s::from_title( 'S202 Registration' ), 'a three-digit year is not matched' );
assert_test( null === $s::from_title( '' ), 'an empty title returns null' );

echo "\n=== from_category_name() ===\n\n";

assert_test( 'S2026' === $s::from_category_name( 'S2026' ), 'a category that is exactly a season code matches' );
assert_test( 'W2025-26' === $s::from_category_name( 'W2025-26' ), 'a split-year category code matches' );
assert_test( null === $s::from_category_name( 'S2026 Registration' ), 'a category merely containing a code does not match' );
assert_test( null === $s::from_category_name( 'registration' ), 'an ordinary category name returns null' );

echo "\n=== is_goalie_tag_name() ===\n\n";

assert_test( $s::is_goalie_tag_name( 'goalie' ), 'the goalie tag matches' );
assert_test( $s::is_goalie_tag_name( 'Goalie' ), 'the goalie tag matches case-insensitively' );
assert_test( $s::is_goalie_tag_name( ' goalie ' ), 'surrounding whitespace is tolerated' );
assert_test( ! $s::is_goalie_tag_name( 'goalies' ), 'a plural tag does not match, preserving SPPR behaviour' );
assert_test( ! $s::is_goalie_tag_name( 'player' ), 'the player tag does not match' );

echo "\n=== from_product() ===\n\n";

$state                 = spat_season_test_state();
$state->titles[ 101 ]  = 'S2026 Player Registration';
$state->titles[ 102 ]  = 'Player Registration';
$state->cat_terms[ 102 ] = array( term( 'registration' ), term( 'W2026' ) );
$state->titles[ 103 ]  = 'Player Registration';
$state->cat_terms[ 103 ] = array( term( 'registration' ) );

assert_test( 'S2026' === $s::from_product( 101 ), 'the title wins when it carries a code' );
assert_test( 'W2026' === $s::from_product( 102 ), 'a category code is used when the title has none' );
assert_test( null === $s::from_product( 103 ), 'a product with no code anywhere returns null' );

echo "\n=== position_from_product() ===\n\n";

$state->tag_terms[ 201 ] = array( term( 'goalie' ) );
$state->tag_terms[ 202 ] = array( term( 'skater' ) );

assert_test( 'goalie' === $s::position_from_product( 201 ), 'a goalie-tagged product is a goalie' );
assert_test( 'player' === $s::position_from_product( 202 ), 'an otherwise-tagged product is a player' );
assert_test( 'player' === $s::position_from_product( 203 ), 'an untagged product defaults to player' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
