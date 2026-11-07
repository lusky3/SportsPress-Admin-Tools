<?php
/**
 * Standalone tests for the captain badge on a SportsPress player list.
 *
 * SportsPress builds a list's name cells with get_the_title() and never fires
 * `sportspress_list_player_name`, so the badge is added through the table data
 * the list template does filter (`sportspress_player_list_data`).
 *
 * Usage: php test-captain-badge.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['cb_filters'] = array();
$GLOBALS['cb_meta']    = array();

function add_action( ...$args ) {}
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['cb_filters'][ $tag ][] = array( $callback, $priority, $args );
}
function apply_filters( $tag, $value ) {
	return $value;
}
function __( $text ) {
	return $text;
}
function esc_attr__( $text ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['cb_meta'][ $id ][ $key ] ?? '';
}

require_once dirname( __FILE__ ) . '/../includes/class-player-modifications.php';

$passed = 0;
$failed = 0;
function cb_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
}

$mods = new SPT_Player_Modifications();

echo "=== captain badge on a player list ===\n\n";

$hooks = $GLOBALS['cb_filters']['sportspress_player_list_data'] ?? array();
cb_assert(
	1 === count( $hooks ) && array( $mods, 'add_captain_to_list_data' ) == $hooks[0][0] && 2 === $hooks[0][2],
	'the badge is registered on sportspress_player_list_data (the hook SportsPress fires), with both arguments'
);
cb_assert(
	isset( $GLOBALS['cb_filters']['sportspress_list_player_name'] ),
	'the original sportspress_list_player_name hook is kept for older SportsPress'
);

// A list (ID 500) whose captain is player 22.
$GLOBALS['cb_meta'][500]['spt_captain'] = 22;
$data = array(
	0  => array( 'name' => 'Player', 'number' => '#' ),
	11 => array( 'name' => 'Ann Skater', 'number' => '4' ),
	22 => array( 'name' => 'Kevin Fox', 'number' => '9' ),
	33 => array( 'name' => 'Bob Goalie (G)', 'number' => '1' ),
);
$out = $mods->add_captain_to_list_data( $data, 500 );

cb_assert( false !== strpos( $out[22]['name'], '<span class="spt-captain-indicator"' ) && 0 === strpos( $out[22]['name'], 'Kevin Fox ' ), "the captain's name gets the badge after it" );
cb_assert( false !== strpos( $out[22]['name'], '>C</span>' ), 'the badge reads C' );
cb_assert( $data[11] === $out[11] && $data[33] === $out[33] && $data[0] === $out[0], 'other players and the label row are untouched' );
cb_assert( 'Bob Goalie (G)' === $out[33]['name'], 'a goalie "(G)" name is untouched' );
cb_assert( $data[22]['number'] === $out[22]['number'], "the captain's other columns are untouched" );

// Running it twice (or alongside the old filter) never doubles the badge.
$twice = $mods->add_captain_to_list_data( $out, 500 );
cb_assert( 1 === substr_count( $twice[22]['name'], 'spt-captain-indicator' ), 'a name that already has the badge is not given a second one' );
$via_old = $mods->add_captain_indicator( $out[22]['name'], 22, 500 );
cb_assert( 1 === substr_count( $via_old, 'spt-captain-indicator' ), 'the original filter does not double it either' );

// No captain / captain not on this list's table / unexpected data.
$none = $mods->add_captain_to_list_data( $data, 501 );
cb_assert( $data === $none, 'a list with no captain is returned unchanged' );
$GLOBALS['cb_meta'][502]['spt_captain'] = 99;
cb_assert( $data === $mods->add_captain_to_list_data( $data, 502 ), 'a captain who is not in the table is ignored' );
cb_assert( array() === $mods->add_captain_to_list_data( array(), 500 ), 'empty table data is returned as is' );
cb_assert( 'x' === $mods->add_captain_to_list_data( 'x', 500 ), 'non-array data is returned as is' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
