<?php
/**
 * Standalone tests for SPPA_Access (who may see and change what).
 *
 * Usage: php test-access.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-access.php';

if ( ! defined( 'SPPA_VERSION' ) ) {
	define( 'SPPA_VERSION', '0.1.0' );
}

echo "\n=== is_member ===\n\n";

sppa_assert( true === SPPA_Access::is_member( array( 12, 7 ), 7 ), 'a team in the list is a membership' );
sppa_assert( false === SPPA_Access::is_member( array( 12, 7 ), 9 ), 'a team not in the list is not' );
sppa_assert( false === SPPA_Access::is_member( array(), 12 ), 'no teams: never a member' );
sppa_assert( false === SPPA_Access::is_member( array( 0 ), 0 ), 'team id 0 is never a membership, even against a stray 0' );
sppa_assert( true === SPPA_Access::is_member( array( '12' ), 12 ), 'ids compare as ints' );

echo "\n=== can_view_game: member / non-member / organizer / accepted spare ===\n\n";

$event_teams = array( 12, 7 );
sppa_assert( true === SPPA_Access::can_view_game( array( 12 ), $event_teams, false, false ), 'member of the home side may view' );
sppa_assert( true === SPPA_Access::can_view_game( array( 7, 30 ), $event_teams, false, false ), 'member of the away side (plus a stale team) may view' );
sppa_assert( false === SPPA_Access::can_view_game( array( 30 ), $event_teams, false, false ), 'member of neither side may not view' );
sppa_assert( false === SPPA_Access::can_view_game( array(), $event_teams, false, false ), 'a user with no teams (unlinked) may not view' );
sppa_assert( true === SPPA_Access::can_view_game( array(), $event_teams, true, false ), 'an organizer with no teams may view' );
sppa_assert( true === SPPA_Access::can_view_game( array( 30 ), $event_teams, true, false ), 'an organizer on another team may view' );
sppa_assert( true === SPPA_Access::can_view_game( array( 30 ), $event_teams, false, true ), 'an accepted spare from another team may view' );
sppa_assert( true === SPPA_Access::can_view_game( array(), $event_teams, false, true ), 'an accepted spare who is on no team may view' );
sppa_assert( false === SPPA_Access::can_view_game( array( 12 ), array(), false, false ), 'a game with no teams is visible to no member' );
sppa_assert( true === SPPA_Access::can_view_game( array( '12' ), array( '12', '7' ), false, false ), 'string ids from the database still match' );

echo "\n=== can_write_availability ===\n\n";

sppa_assert( true === SPPA_Access::can_write_availability( array( 50, 51 ), 51, false ), 'a player may write for their own player' );
sppa_assert( false === SPPA_Access::can_write_availability( array( 50 ), 99, false ), 'a non-organizer may not write another player\'s answer' );
sppa_assert( false === SPPA_Access::can_write_availability( array(), 50, false ), 'a user with no player may not write' );
sppa_assert( true === SPPA_Access::can_write_availability( array( 50 ), 99, true ), 'an organizer may write any player\'s answer' );
sppa_assert( true === SPPA_Access::can_write_availability( array(), 99, true ), 'an organizer with no player of their own may write' );
sppa_assert( false === SPPA_Access::can_write_availability( array( 0 ), 0, false ), 'player id 0 is never writable by a non-organizer' );
sppa_assert( false === SPPA_Access::can_write_availability( array( 50 ), 0, false ), 'player id 0 is not anyone\'s player' );

echo "\n=== can_create_call ===\n\n";

sppa_assert( true === SPPA_Access::can_create_call( array( 12 ), 12, false ), 'a member may post a call for their team' );
sppa_assert( false === SPPA_Access::can_create_call( array( 12 ), 7, false ), 'a member may not post a call for the other team' );
sppa_assert( false === SPPA_Access::can_create_call( array(), 12, false ), 'a user with no team may not post a call' );
sppa_assert( true === SPPA_Access::can_create_call( array(), 12, true ), 'an organizer may post a call for any team' );

echo "\n=== can_manage_call: creator / teammate / stranger / organizer ===\n\n";

$call = array( 'id' => 5, 'team_id' => 12, 'created_by' => 4 );
sppa_assert( true === SPPA_Access::can_manage_call( $call, array(), 4, false ), 'the creator may manage, even after leaving the team' );
sppa_assert( true === SPPA_Access::can_manage_call( $call, array( 12 ), 9, false ), 'a teammate of the calling team may manage' );
sppa_assert( false === SPPA_Access::can_manage_call( $call, array( 7 ), 9, false ), 'a member of the other team may not' );
sppa_assert( false === SPPA_Access::can_manage_call( $call, array(), 9, false ), 'a stranger may not' );
sppa_assert( true === SPPA_Access::can_manage_call( $call, array(), 9, true ), 'an organizer may' );
sppa_assert( false === SPPA_Access::can_manage_call( array( 'team_id' => 12, 'created_by' => 0 ), array(), 0, false ), 'user 0 never matches a call with no creator' );
sppa_assert( true === SPPA_Access::can_manage_call( array( 'team_id' => '12', 'created_by' => '4' ), array( 3 ), 4, false ), 'string ids from the database still match' );

echo "\n=== live scorekeeping ===\n\n";

sppa_assert( true === SPPA_Access::can_keep_time( true, false, false ), 'the sppa_keep_time capability keeps time' );
sppa_assert( true === SPPA_Access::can_keep_time( false, true, false ), 'so does edit_others_sp_events' );
sppa_assert( true === SPPA_Access::can_keep_time( false, false, true ), 'and an organizer' );
sppa_assert( false === SPPA_Access::can_keep_time( false, false, false ), 'a player does not' );
sppa_assert( true === SPPA_Access::can_reopen( true ) && false === SPPA_Access::can_reopen( false ), 'only organizers reopen a finished game' );
sppa_assert( true === SPPA_Access::can_void( 9, 9, false ), 'a keeper may undo their own entry' );
sppa_assert( false === SPPA_Access::can_void( 9, 10, false ), 'but not another keeper\'s' );
sppa_assert( true === SPPA_Access::can_void( 9, 10, true ), 'an organizer may undo anyone\'s' );
sppa_assert( false === SPPA_Access::can_void( 0, 0, false ), 'nobody (user 0) owns an entry' );
sppa_assert( true === SPPA_Access::can_view_live( array( 12 ), array( 12, 7 ), false, false, false ), 'a member of either team sees the live score' );
sppa_assert( true === SPPA_Access::can_view_live( array(), array( 12, 7 ), false, true, false ), 'so does an accepted spare' );
sppa_assert( true === SPPA_Access::can_view_live( array(), array( 12, 7 ), true, false, false ), 'and an organizer' );
sppa_assert( true === SPPA_Access::can_view_live( array(), array( 12, 7 ), false, false, true ), 'and a timekeeper who plays for neither team' );
sppa_assert( false === SPPA_Access::can_view_live( array( 9 ), array( 12, 7 ), false, false, false ), 'a player on another team does not' );

echo "\n=== the ARL Timekeeper role ===\n\n";

SPPA_Access::install_role();
$role = get_role( 'sppa_timekeeper' );
sppa_assert( $role && 'ARL Timekeeper' === $role->display_name && array( 'read' => true, 'sppa_keep_time' => true ) === $role->capabilities, 'install_role adds "ARL Timekeeper" with read and sppa_keep_time only' );
$stored = get_option( 'sppa_role_version' );
sppa_assert( is_string( $stored ) && 0 === strpos( $stored, '0.1.0-' ), 'and remembers the definition it installed' );
$role->capabilities = array( 'read' => true );
SPPA_Access::maybe_install_role();
sppa_assert( array( 'read' => true ) === get_role( 'sppa_timekeeper' )->capabilities, 'maybe_install_role is a no-op while the stored definition is current' );
update_option( 'sppa_role_version', '0.0.9-deadbeef' );
SPPA_Access::maybe_install_role();
sppa_assert( true === get_role( 'sppa_timekeeper' )->capabilities['sppa_keep_time'] && $stored === get_option( 'sppa_role_version' ), 'after an upgrade it gives the existing role its capabilities again' );
remove_role( 'sppa_timekeeper' );
update_option( 'sppa_role_version', '' );
SPPA_Access::maybe_install_role();
sppa_assert( null !== get_role( 'sppa_timekeeper' ), 'and re-adds a role that went missing' );

sppa_done();
