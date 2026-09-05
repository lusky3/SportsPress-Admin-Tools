<?php
/**
 * Standalone tests for SPT_Player_Profile_Picture player resolution.
 *
 * Guards the 2026-09 fix for a WRITE bug, not a display bug. The page used to
 * resolve "your player record" with get_posts( array( 'author' => $user_id ) ).
 * post_author records who CREATED a record, not who it is about, so on
 * rookiehockey.ca the account `codylusk` — which authored exactly one player,
 * "Nick Prystie" — was shown Nick Prystie's record as its own profile, and
 * pressing Upload called set_post_thumbnail() on it.
 *
 * The resolver must therefore ask the sp_user question and ONLY the sp_user
 * question. These tests would pass against the broken code if the get_posts()
 * stub ignored its arguments, so the stub below honours BOTH 'author' and
 * 'meta_query' against a fixture roster and answers whichever was asked.
 *
 * Usage: php test-profile-picture-resolution.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ---------------------------------------------------------------------------
// Fixture roster: post id => array( author, sp_user|null )
//
// Modelled on real arl-local data so a regression reads as the reported bug:
//   66    Cody Lusk        authored by 9 (admin), NO sp_user  -> nobody's profile
//   3352  Nick Prystie     authored by 1276, NO sp_user       -> nobody's profile
//   500   Linked Player    authored by 9, sp_user = 42        -> user 42's profile
//   501   Other's Player   authored by 42, sp_user = 77       -> user 77's, not 42's
//   502   Second Link      authored by 9, sp_user = 55
//   503   Duplicate Link   authored by 9, sp_user = 55        -> 55 is ambiguous
// ---------------------------------------------------------------------------
final class PPR_Fixture {

	/** Post id => array( author, sp_user|null ). */
	public static $roster = array(
		66   => array( 'author' => 9,    'sp_user' => null ),
		3352 => array( 'author' => 1276, 'sp_user' => null ),
		500  => array( 'author' => 9,    'sp_user' => 42 ),
		501  => array( 'author' => 42,   'sp_user' => 77 ),
		502  => array( 'author' => 9,    'sp_user' => 55 ),
		503  => array( 'author' => 9,    'sp_user' => 55 ),
	);

	/** Every get_posts() arg set the resolver issued, for assertion. */
	public static $queries = array();

	/**
	 * Post ids whose roster row satisfies $matches.
	 *
	 * @param callable $matches Receives one roster row, returns bool.
	 * @return array<int>
	 */
	public static function ids_where( callable $matches ): array {
		$out = array();
		foreach ( self::$roster as $id => $row ) {
			if ( $matches( $row ) ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	/**
	 * Answer from the fixture roster according to what was actually asked.
	 *
	 * Deliberately strict: an 'author' query returns author matches and a
	 * meta_query on sp_user returns link matches. A resolver that asks the
	 * wrong question gets the wrong answer, which is the point.
	 */
	function get_posts( $args = array() ) {
		PPR_Fixture::$queries[] = $args;

		if ( isset( $args['author'] ) ) {
			$want = (int) $args['author'];
			return PPR_Fixture::ids_where(
				static function ( $row ) use ( $want ) {
					return (int) $row['author'] === $want;
				}
			);
		}

		if ( ! empty( $args['meta_query'][0]['key'] ) && 'sp_user' === $args['meta_query'][0]['key'] ) {
			$want = (int) $args['meta_query'][0]['value'];
			return PPR_Fixture::ids_where(
				static function ( $row ) use ( $want ) {
					return null !== $row['sp_user'] && (int) $row['sp_user'] === $want;
				}
			);
		}

		// An unrecognised query shape must not look like a successful lookup.
		return array();
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}

require_once __DIR__ . '/../includes/class-player-profile-picture.php';

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

function resolve( $user_id ) {
	PPR_Fixture::$queries = array();
	$obj = new SPT_Player_Profile_Picture();
	$ref = new ReflectionMethod( $obj, 'get_user_player_posts' );
	$ref->setAccessible( true );
	return array_map( 'intval', (array) $ref->invokeArgs( $obj, array( $user_id ) ) );
}

echo "\n=== the reported bug ===\n\n";

// The regression that started this: user 1276 authored exactly one player they
// are not linked to. The old code returned it and the page wrote a photo there.
assert_test(
	array() === resolve( 1276 ),
	'codylusk (authored only Nick Prystie, no sp_user) resolves to NO player'
);
assert_test(
	! in_array( 3352, resolve( 1276 ), true ),
	'Nick Prystie is never handed to the account that merely authored him'
);

// The admin who imported the roster.
assert_test(
	array() === resolve( 9 ),
	'an admin authoring 4 fixture players but linked to none resolves to NO player'
);

echo "\n=== the sp_user link ===\n\n";

assert_test( array( 500 ) === resolve( 42 ), 'a linked user resolves to exactly their linked player' );
assert_test(
	! in_array( 501, resolve( 42 ), true ),
	'a player the user merely authored is not returned alongside their link'
);
assert_test( array( 501 ) === resolve( 77 ), 'a link is honoured even when someone else authored the record' );
assert_test( array() === resolve( 1234 ), 'a user with neither link nor authorship resolves to nothing' );
assert_test( array() === resolve( 0 ), 'user id 0 resolves to nothing' );

echo "\n=== branch selection ===\n\n";

// The 0 / 1 / many branches key off this count, so the ambiguous case must stay
// ambiguous rather than silently picking one.
assert_test( 2 === count( resolve( 55 ) ), 'two records sharing one link stay ambiguous (count 2, not 1)' );
assert_test( 1 === count( resolve( 42 ) ), 'a single link yields exactly one player' );
assert_test( 0 === count( resolve( 1276 ) ), 'no link yields zero players' );

echo "\n=== the query actually issued ===\n\n";

// A stub that ignored its args would let the broken code pass every assertion
// above. Assert the shape of the question, not just the answer.
resolve( 42 );
$q = PPR_Fixture::$queries[0] ?? array();
assert_test( ! isset( $q['author'] ), 'the resolver does NOT query by post_author' );
assert_test(
	isset( $q['meta_query'][0]['key'] ) && 'sp_user' === $q['meta_query'][0]['key'],
	'the resolver queries the sp_user meta link'
);
assert_test( ( $q['post_type'] ?? '' ) === 'sp_player', 'it is scoped to sp_player' );
assert_test( ( $q['fields'] ?? '' ) === 'ids', 'it asks for ids only' );

echo "\n=== caching ===\n\n";

// The cache exists so the menu-item filter and the form render do not re-query.
$obj = new SPT_Player_Profile_Picture();
$ref = new ReflectionMethod( $obj, 'get_user_player_posts' );
$ref->setAccessible( true );
PPR_Fixture::$queries = array();
$ref->invokeArgs( $obj, array( 42 ) );
$ref->invokeArgs( $obj, array( 42 ) );
assert_test( 1 === count( PPR_Fixture::$queries ), 'a repeated lookup for one user issues a single query' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
