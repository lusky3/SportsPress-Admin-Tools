<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
/**
 * Standalone tests for SPAT_Player.
 *
 * These assertions used to live twice — once in
 * sportspress-player-registration and once in sportspress-player-tools —
 * because the lookup they cover had been written twice. The behaviour moved
 * here with the code.
 *
 * What they guard is one SportsPress fact: `sp_team` is a POST TYPE, not a
 * taxonomy, so every term function returns WP_Error for it. That error is
 * silent behind an is_array() guard and fatal without one, and the suite hit
 * both — a permanently blank Teams column in one place, a TypeError that
 * flagged completed orders as failed in the other.
 *
 * Usage: php test-player-helper.php
 */

define( 'ABSPATH', __DIR__ );

/**
 * Fixture state, held in a function-local static rather than a superglobal.
 *
 * @param array|null $meta  Post meta: post id => key => values.
 * @param array|null $posts Posts: post id => object.
 * @return array
 */
function spat_player_fixture( ?array $meta = null, ?array $posts = null ): array {
	static $state = array(
		'meta'  => array(),
		'posts' => array(),
	);
	if ( null !== $meta ) {
		$state['meta'] = $meta;
	}
	if ( null !== $posts ) {
		$state['posts'] = $posts;
	}
	return $state;
}

/**
 * Stub mirroring the WordPress signature.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_post_meta( $id, $key, $single = false ) { // phpcs:ignore
	$state = spat_player_fixture();
	$value = $state['meta'][ $id ][ $key ] ?? array();
	return $single ? ( $value[0] ?? '' ) : $value;
}

function get_post( $id ) {
	$state = spat_player_fixture();
	return $state['posts'][ $id ] ?? null;
}

require_once __DIR__ . '/../includes/class-player.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		++$passed;
	} else {
		echo "✗ FAIL: {$message}\n";
		++$failed;
	}
}

spat_player_fixture(
	null,
	array(
		701 => (object) array( 'ID' => 701, 'post_title' => 'Ice Hawks' ),
		702 => (object) array( 'ID' => 702, 'post_title' => 'Rink Rats' ),
	)
);

echo "\n=== SPAT_Player::team_names() ===\n\n";

spat_player_fixture( array( 601 => array( 'sp_team' => array( 701, 702 ) ) ) );
assert_test(
	array( 'Ice Hawks', 'Rink Rats' ) === SPAT_Player::team_names( 601 ),
	'team names come from sp_team post meta, resolved to team post titles'
);

// The ordinary case at registration time, and the one that used to throw.
spat_player_fixture( array( 602 => array() ) );
assert_test( array() === SPAT_Player::team_names( 602 ), 'a player on no team yields an empty list, not an error' );

spat_player_fixture( array( 603 => array( 'sp_team' => array( 701, 9999 ) ) ) );
assert_test( array( 'Ice Hawks' ) === SPAT_Player::team_names( 603 ), 'a deleted team post is skipped rather than rendered as a blank name' );

// Repeated meta for one team is a data anomaly, not two memberships. Rendering
// "Ice Hawks, Ice Hawks" in an export or a notification is a defect either way.
spat_player_fixture( array( 604 => array( 'sp_team' => array( 701, 701, 702 ) ) ) );
assert_test( array( 'Ice Hawks', 'Rink Rats' ) === SPAT_Player::team_names( 604 ), 'a team recorded twice appears once' );

// Re-indexed, so callers get a plain list rather than array_unique()'s gaps.
spat_player_fixture( array( 605 => array( 'sp_team' => array( 701, 701 ) ) ) );
assert_test( array( 0 ) === array_keys( SPAT_Player::team_names( 605 ) ), 'the returned list is re-indexed from zero' );

// Meta values arrive from the database as strings.
spat_player_fixture( array( 606 => array( 'sp_team' => array( '701', '702' ) ) ) );
assert_test( array( 'Ice Hawks', 'Rink Rats' ) === SPAT_Player::team_names( 606 ), 'string meta values are cast before lookup' );

// A player with no meta row at all, rather than an empty one.
assert_test( array() === SPAT_Player::team_names( 999999 ), 'an unknown player yields an empty list' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
