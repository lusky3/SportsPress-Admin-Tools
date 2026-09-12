<?php
/**
 * Test: loading a configuration resolves bare SportsPress team-ID entries
 * to their real team name.
 *
 * A division's `teams` are normally plain name strings — typed by hand, or
 * embedded by the "Load from SportsPress" picker, which writes the real
 * name at the time a team is added. A configuration authored directly
 * through the REST API can instead store a bare `sp_team` post ID in that
 * same slot, which has no display meaning of its own. Nothing downstream
 * ever looked such an ID up, so the admin form, the generated schedule, and
 * the SportsPress import's by-name matching all showed/matched on the raw
 * ID instead of the team's real name. SPSG_Schedule_Configuration::
 * load_from_array() now resolves these in place on every load.
 *
 * See tests/test-team-name-resolution-noop.php for the companion "no-op
 * without SPSG_Sports_Press_Integration loaded" safety check — kept in its
 * own file so this one can require that class for the rest of these tests.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

function wp_timezone_string() { return 'America/Toronto'; }

/**
 * Fake sp_team posts for get_post() to resolve against, keyed by ID.
 * A function-local static avoids reaching for $GLOBALS.
 */
function get_post( $id ) {
	static $posts = null;
	if ( null === $posts ) {
		$posts = array(
			115093 => (object) array( 'ID' => 115093, 'post_type' => 'sp_team', 'post_status' => 'publish', 'post_title' => 'Ducks' ),
			102749 => (object) array( 'ID' => 102749, 'post_type' => 'sp_team', 'post_status' => 'publish', 'post_title' => 'Hammers' ),
			999001 => (object) array( 'ID' => 999001, 'post_type' => 'sp_team', 'post_status' => 'draft', 'post_title' => 'Unpublished Team' ),
			999002 => (object) array( 'ID' => 999002, 'post_type' => 'sp_event', 'post_status' => 'publish', 'post_title' => 'Not A Team' ),
		);
	}
	return $posts[ (int) $id ] ?? null;
}

class SportsPress {}

require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';

$passed = 0;
$failed = 0;

function trn_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

echo "=== Testing team-name resolution on config load ===\n\n";

// ---------------------------------------------------------------------------
// 1. Unit: SPSG_Sports_Press_Integration::resolve_team_name()
// ---------------------------------------------------------------------------
echo "Test 1: resolve_team_name() unit behaviour\n";

trn_assert(
	'Ducks' === SPSG_Sports_Press_Integration::resolve_team_name( '115093' ),
	'a published sp_team post ID resolves to its title'
);
trn_assert(
	'Ducks' === SPSG_Sports_Press_Integration::resolve_team_name( 115093 ),
	'an integer post ID resolves the same as its string form'
);
trn_assert(
	'424242' === SPSG_Sports_Press_Integration::resolve_team_name( '424242' ),
	'an ID with no matching post is returned unchanged'
);
trn_assert(
	'999001' === SPSG_Sports_Press_Integration::resolve_team_name( '999001' ),
	'an unpublished (draft) sp_team post is not resolved'
);
trn_assert(
	'999002' === SPSG_Sports_Press_Integration::resolve_team_name( '999002' ),
	'a published post of a different post type is not resolved'
);
trn_assert(
	'Ducks' === SPSG_Sports_Press_Integration::resolve_team_name( 'Ducks' ),
	'a literal (non-numeric) team name is returned unchanged'
);

// ---------------------------------------------------------------------------
// 2. End to end: loading a configuration built the way the REST API allows
//    (bare ID strings) resolves real teams, leaves the rest alone, and
//    doesn't touch already-resolved {id, name} entries.
// ---------------------------------------------------------------------------
echo "\nTest 2: SPSG_Schedule_Configuration::load_from_array() resolves in place\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'divisions' => array(
			array(
				'id'    => 'div1',
				'name'  => 'Division 1',
				'teams' => array( '115093', '102749', '424242', 'Ice Bears' ),
			),
			array(
				'id'    => 'div2',
				'name'  => 'Division 2',
				'teams' => array( array( 'id' => '76', 'name' => 'Oilers' ) ),
			),
		),
	)
);

trn_assert(
	array( 'Ducks', 'Hammers', '424242', 'Ice Bears' ) === $config->divisions[0]['teams'],
	'div1: real IDs resolved to names, unmatched ID and literal name left as-is (' . json_encode( $config->divisions[0]['teams'] ) . ')'
);
trn_assert(
	array( array( 'id' => '76', 'name' => 'Oilers' ) ) === $config->divisions[1]['teams'],
	'div2: an already-resolved {id, name} entry is left untouched'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
