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

/**
 * Minimal $wpdb: stores rows as array( post_id, meta_key, meta_value ) and
 * answers the two get_col() queries SPAT_Player::ids_for_email() issues.
 * A query only matches case-insensitively when it says LOWER(...meta_value), as
 * MySQL's default collation is not something this stub can lean on.
 *
 * A query that joins $posts only sees meta of posts in $post_rows
 * (id => array( post_type, post_status )), filtered by the post_type = and
 * post_status NOT IN conditions it states.
 */
class SPAT_Test_WPDB {
	public $postmeta  = 'wp_postmeta';
	public $posts     = 'wp_posts';
	public $rows      = array();
	public $post_rows = array();

	public function prepare( $sql, ...$args ) {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $sql, 1 );
		}
		return $sql;
	}

	public function get_col( $sql ) {
		preg_match( "/meta_key = '([^']+)'/", $sql, $key );
		preg_match( "/= '([^']*)'\s*\z/", trim( $sql ), $val );
		$lower    = 1 === preg_match( '/LOWER\((\w+\.)?meta_value\)/', $sql );
		$joined   = false !== strpos( $sql, $this->posts );
		$type     = preg_match( "/post_type = '([^']+)'/", $sql, $t ) ? $t[1] : null;
		$excluded = array();
		if ( preg_match( '/post_status NOT IN \(([^)]*)\)/', $sql, $s ) ) {
			$excluded = array_map(
				function ( $v ) {
					return trim( $v, " '" );
				},
				explode( ',', $s[1] )
			);
		}
		$out = array();
		foreach ( $this->rows as $row ) {
			$stored = $lower ? strtolower( $row[2] ) : $row[2];
			if ( $row[1] !== $key[1] || $stored !== $val[1] ) {
				continue;
			}
			if ( $joined ) {
				$post = $this->post_rows[ $row[0] ] ?? null;
				if ( null === $post || ( null !== $type && $post[0] !== $type ) || in_array( $post[1], $excluded, true ) ) {
					continue;
				}
			}
			$out[] = (string) $row[0];
		}
		return $out;
	}
}

function get_user_by( $field, $value ) {
	$users = $GLOBALS['spat_test_users'] ?? array();
	return $users[ strtolower( $value ) ] ?? false;
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

echo "\n=== SPAT_Player::ids_for_email() ===\n\n";

$wpdb       = new SPAT_Test_WPDB();
$wpdb->rows = array(
	array( 300, 'spt_email', 'jordan.smith@example.com' ),
	array( 100, 'spt_email', 'jordan.smith@example.com' ),
	array( 200, 'spt_email', 'other@example.com' ),
	array( 400, 'sp_user', '7' ),
	array( 100, 'sp_user', '7' ),
	array( 500, 'sp_user', '7' ),                          // Staff post carrying sp_user.
	array( 510, 'spt_email', 'jordan.smith@example.com' ), // Non-player post with the meta.
	array( 600, 'spt_email', 'jordan.smith@example.com' ), // Trashed duplicate player.
	array( 610, 'sp_user', '7' ),                          // Auto-draft player.
	array( 620, 'spt_email', 'jordan.smith@example.com' ), // Draft player (not live-filtered).
);
$wpdb->post_rows = array(
	100 => array( 'sp_player', 'publish' ),
	200 => array( 'sp_player', 'publish' ),
	300 => array( 'sp_player', 'publish' ),
	400 => array( 'sp_player', 'publish' ),
	500 => array( 'sp_staff', 'publish' ),
	510 => array( 'post', 'publish' ),
	600 => array( 'sp_player', 'trash' ),
	610 => array( 'sp_player', 'auto-draft' ),
	620 => array( 'sp_player', 'draft' ),
);
$GLOBALS['wpdb']            = $wpdb;
$GLOBALS['spat_test_users'] = array( 'linked@example.com' => (object) array( 'ID' => 7 ) );

assert_test( array( 100, 300, 600, 620 ) === SPAT_Player::ids_for_email( 'Jordan.Smith@Example.com' ), 'email lookup is case-insensitive and shared emails return every sp_player (any status), ascending' );
assert_test( array() === SPAT_Player::ids_for_email( 'nobody@example.com' ), 'an unknown email yields an empty list' );
assert_test( array( 100, 400, 610 ) === SPAT_Player::ids_for_email( 'LINKED@example.com' ), 'players linked through the sp_user of the WP user are found, de-duplicated; staff posts are not' );
assert_test( array( 100, 300, 620 ) === SPAT_Player::ids_for_email( 'jordan.smith@example.com', true ), 'live only: trashed players are left out, drafts kept' );
assert_test( array( 100, 400 ) === SPAT_Player::ids_for_email( 'linked@example.com', true ), 'live only: auto-draft players are left out' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
