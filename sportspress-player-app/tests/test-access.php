<?php
/**
 * Standalone tests for SPPA_Access (who may see and change what).
 *
 * Usage: php test-access.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-access.php';

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

sppa_done();
