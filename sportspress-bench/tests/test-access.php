<?php
/**
 * Standalone tests for SPBN_Access (who may see and change what).
 *
 * Usage: php test-access.php
 */

require __DIR__ . '/bootstrap.php';
require_once dirname( __DIR__ ) . '/includes/class-access.php';

echo "\n=== is_member ===\n\n";

spbn_assert( true === SPBN_Access::is_member( array( 12, 7 ), 7 ), 'a team in the list is a membership' );
spbn_assert( false === SPBN_Access::is_member( array( 12, 7 ), 9 ), 'a team not in the list is not' );
spbn_assert( false === SPBN_Access::is_member( array(), 12 ), 'no teams: never a member' );
spbn_assert( false === SPBN_Access::is_member( array( 0 ), 0 ), 'team id 0 is never a membership, even against a stray 0' );
spbn_assert( true === SPBN_Access::is_member( array( '12' ), 12 ), 'ids compare as ints' );

echo "\n=== can_view_game: member / non-member / organizer / accepted spare ===\n\n";

$event_teams = array( 12, 7 );
spbn_assert( true === SPBN_Access::can_view_game( array( 12 ), $event_teams, false, false ), 'member of the home side may view' );
spbn_assert( true === SPBN_Access::can_view_game( array( 7, 30 ), $event_teams, false, false ), 'member of the away side (plus a stale team) may view' );
spbn_assert( false === SPBN_Access::can_view_game( array( 30 ), $event_teams, false, false ), 'member of neither side may not view' );
spbn_assert( false === SPBN_Access::can_view_game( array(), $event_teams, false, false ), 'a user with no teams (unlinked) may not view' );
spbn_assert( true === SPBN_Access::can_view_game( array(), $event_teams, true, false ), 'an organizer with no teams may view' );
spbn_assert( true === SPBN_Access::can_view_game( array( 30 ), $event_teams, true, false ), 'an organizer on another team may view' );
spbn_assert( true === SPBN_Access::can_view_game( array( 30 ), $event_teams, false, true ), 'an accepted spare from another team may view' );
spbn_assert( true === SPBN_Access::can_view_game( array(), $event_teams, false, true ), 'an accepted spare who is on no team may view' );
spbn_assert( false === SPBN_Access::can_view_game( array( 12 ), array(), false, false ), 'a game with no teams is visible to no member' );
spbn_assert( true === SPBN_Access::can_view_game( array( '12' ), array( '12', '7' ), false, false ), 'string ids from the database still match' );

echo "\n=== can_write_availability ===\n\n";

spbn_assert( true === SPBN_Access::can_write_availability( array( 50, 51 ), 51, false ), 'a player may write for their own player' );
spbn_assert( false === SPBN_Access::can_write_availability( array( 50 ), 99, false ), 'a non-organizer may not write another player\'s answer' );
spbn_assert( false === SPBN_Access::can_write_availability( array(), 50, false ), 'a user with no player may not write' );
spbn_assert( true === SPBN_Access::can_write_availability( array( 50 ), 99, true ), 'an organizer may write any player\'s answer' );
spbn_assert( true === SPBN_Access::can_write_availability( array(), 99, true ), 'an organizer with no player of their own may write' );
spbn_assert( false === SPBN_Access::can_write_availability( array( 0 ), 0, false ), 'player id 0 is never writable by a non-organizer' );
spbn_assert( false === SPBN_Access::can_write_availability( array( 50 ), 0, false ), 'player id 0 is not anyone\'s player' );

echo "\n=== can_create_call ===\n\n";

spbn_assert( true === SPBN_Access::can_create_call( array( 12 ), 12, false ), 'a member may post a call for their team' );
spbn_assert( false === SPBN_Access::can_create_call( array( 12 ), 7, false ), 'a member may not post a call for the other team' );
spbn_assert( false === SPBN_Access::can_create_call( array(), 12, false ), 'a user with no team may not post a call' );
spbn_assert( true === SPBN_Access::can_create_call( array(), 12, true ), 'an organizer may post a call for any team' );

echo "\n=== can_manage_call: creator / teammate / stranger / organizer ===\n\n";

$call = array( 'id' => 5, 'team_id' => 12, 'created_by' => 4 );
spbn_assert( true === SPBN_Access::can_manage_call( $call, array(), 4, false ), 'the creator may manage, even after leaving the team' );
spbn_assert( true === SPBN_Access::can_manage_call( $call, array( 12 ), 9, false ), 'a teammate of the calling team may manage' );
spbn_assert( false === SPBN_Access::can_manage_call( $call, array( 7 ), 9, false ), 'a member of the other team may not' );
spbn_assert( false === SPBN_Access::can_manage_call( $call, array(), 9, false ), 'a stranger may not' );
spbn_assert( true === SPBN_Access::can_manage_call( $call, array(), 9, true ), 'an organizer may' );
spbn_assert( false === SPBN_Access::can_manage_call( array( 'team_id' => 12, 'created_by' => 0 ), array(), 0, false ), 'user 0 never matches a call with no creator' );
spbn_assert( true === SPBN_Access::can_manage_call( array( 'team_id' => '12', 'created_by' => '4' ), array( 3 ), 4, false ), 'string ids from the database still match' );

spbn_done();
