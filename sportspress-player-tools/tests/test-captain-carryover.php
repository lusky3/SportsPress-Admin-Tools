<?php
/**
 * Standalone tests for SPT_Captain_Carryover and the Captain Selection box save.
 *
 * Usage: php test-captain-carryover.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['cc_types']   = array(); // post ID => post type
$GLOBALS['cc_meta']    = array(); // post ID => key => value
$GLOBALS['cc_dates']   = array(); // post ID => post_date
$GLOBALS['cc_queries'] = 0;

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function get_post_type( $id ) {
	return $GLOBALS['cc_types'][ (int) $id ] ?? false;
}
function get_post_meta( $id, $key, $single = false ) {
	return $GLOBALS['cc_meta'][ (int) $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['cc_meta'][ (int) $id ][ $key ] = $value;
	return true;
}
function delete_post_meta( $id, $key ) {
	unset( $GLOBALS['cc_meta'][ (int) $id ][ $key ] );
	return true;
}
function get_post_field( $field, $id ) {
	return $GLOBALS['cc_dates'][ (int) $id ] ?? '';
}
/** The team's newest other list dated before the one being filled. */
function get_posts( $args ) {
	$GLOBALS['cc_queries']++;
	$team   = (int) $args['meta_query'][0]['value'];
	$before = $args['date_query'][0]['before'];
	$found  = array();
	foreach ( $GLOBALS['cc_types'] as $id => $type ) {
		if ( 'sp_list' === $type && ! in_array( $id, $args['post__not_in'], true )
			&& (int) ( $GLOBALS['cc_meta'][ $id ]['sp_team'] ?? 0 ) === $team
			&& $GLOBALS['cc_dates'][ $id ] < $before ) {
			$found[ $id ] = $GLOBALS['cc_dates'][ $id ];
		}
	}
	arsort( $found );
	return array_slice( array_keys( $found ), 0, 1 );
}
function wp_verify_nonce( $nonce, $action ) {
	return 'good' === $nonce;
}
function sanitize_text_field( $value ) {
	return $value;
}
function wp_unslash( $value ) {
	return $value;
}
function current_user_can( $cap, $id = 0 ) {
	return true;
}

require_once dirname( __FILE__ ) . '/../includes/class-captain-carryover.php';
require_once dirname( __FILE__ ) . '/../includes/class-player-modifications.php';

$passed = 0;
$failed = 0;
function cc_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
}

/** Team 7: last season's list 100 (captain 22), this season's empty list 200. */
function cc_reset() {
	$GLOBALS['cc_types']   = array( 100 => 'sp_list', 200 => 'sp_list', 300 => 'sp_player', 50 => 'sp_list' );
	$GLOBALS['cc_dates']   = array( 50 => '2024-09-01', 100 => '2025-09-01', 200 => '2026-09-01', 300 => '2026-09-01' );
	$GLOBALS['cc_meta']    = array(
		50  => array( 'sp_team' => 7, 'spt_captain' => 11 ),
		100 => array( 'sp_team' => 7, 'spt_captain' => 22 ),
		200 => array( 'sp_team' => 7 ),
	);
	$GLOBALS['cc_queries'] = 0;
}

echo "=== carry the captain onto the next season's list ===\n\n";

cc_reset();
$carry = new SPT_Captain_Carryover();
foreach ( array( 33, 22, 44 ) as $pid ) {
	$carry->on_player_added( 0, 200, 'sp_player', (string) $pid );
}
cc_assert( 22 === get_post_meta( 200, 'spt_captain' ), "last season's captain becomes captain when added to the new list" );
cc_assert( '1' === get_post_meta( 200, '_spt_captain_carried' ), 'the list is marked as carried' );
cc_assert( 1 === $GLOBALS['cc_queries'], 'the previous list is looked up once per list, not once per player' );

cc_reset();
$carry = new SPT_Captain_Carryover();
foreach ( array( 33, 44 ) as $pid ) {
	$carry->on_player_added( 0, 200, 'sp_player', $pid );
}
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'no captain when last season\'s captain is not on the new list' );

cc_reset();
$GLOBALS['cc_meta'][100]['spt_captain'] = '';
unset( $GLOBALS['cc_meta'][100]['spt_captain'] );
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_player', 11 );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'only the most recent list counts: an older captain is not brought back' );

cc_reset();
$GLOBALS['cc_meta'][200]['spt_captain'] = 44;
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_player', 22 );
cc_assert( 44 === get_post_meta( 200, 'spt_captain' ), 'a list that already has a captain is left alone' );

cc_reset();
$GLOBALS['cc_meta'][200]['_spt_captain_carried'] = '1';
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_player', 22 );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'a list whose captain was decided once (carried or chosen by hand) is never re-filled' );

cc_reset();
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_number', 22 );
( new SPT_Captain_Carryover() )->on_player_added( 0, 300, 'sp_player', 22 );
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_player', 0 );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ) && 0 === $GLOBALS['cc_queries'], 'other meta keys, non-list posts and empty values are ignored without a query' );

cc_reset();
unset( $GLOBALS['cc_meta'][200]['sp_team'] );
( new SPT_Captain_Carryover() )->on_player_added( 0, 200, 'sp_player', 22 );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'a list with no team is ignored' );

cc_reset();
( new SPT_Captain_Carryover() )->on_player_added( 0, 100, 'sp_player', 11 );
cc_assert( 22 === get_post_meta( 100, 'spt_captain' ), 'an older list being edited is not touched once it has a captain' );

echo "\n=== Captain Selection box: only a change by hand is saved ===\n\n";

$mods = new SPT_Player_Modifications();
function cc_save( $mods, $post ) {
	$_POST = array_merge( array( 'spt_captain_meta_nonce' => 'good' ), $post );
	$mods->save_captain_meta( 200 );
}

cc_reset();
$GLOBALS['cc_meta'][200]['spt_captain'] = 22; // Just carried over on this same save.
cc_save( $mods, array( 'spt_captain' => '', 'spt_captain_shown' => '' ) );
cc_assert( 22 === get_post_meta( 200, 'spt_captain' ), 'an untouched (blank) box does not wipe a captain set during the save' );
cc_assert( '' === get_post_meta( 200, '_spt_captain_carried' ), 'an untouched box is not recorded as a decision' );

cc_reset();
cc_save( $mods, array( 'spt_captain' => '44', 'spt_captain_shown' => '' ) );
cc_assert( 44 === get_post_meta( 200, 'spt_captain' ) && '1' === get_post_meta( 200, '_spt_captain_carried' ), 'choosing a captain saves it and makes it final' );

cc_reset();
$GLOBALS['cc_meta'][200]['spt_captain'] = 22;
cc_save( $mods, array( 'spt_captain' => '', 'spt_captain_shown' => '22' ) );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ) && '1' === get_post_meta( 200, '_spt_captain_carried' ), 'clearing the captain by hand deletes it and stops it being carried back' );

cc_reset();
$GLOBALS['cc_meta'][200]['spt_captain'] = 22;
cc_save( $mods, array( 'spt_captain' => '' ) );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'a form without the shown field (older cached screen) keeps the old behaviour' );

cc_reset();
$_POST = array( 'spt_captain_meta_nonce' => 'bad', 'spt_captain' => '44' );
$mods->save_captain_meta( 200 );
cc_assert( '' === get_post_meta( 200, 'spt_captain' ), 'a bad nonce changes nothing' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
