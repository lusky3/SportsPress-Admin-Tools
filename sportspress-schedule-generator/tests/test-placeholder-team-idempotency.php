<?php
/**
 * Test: SPSG_Placeholder_Team_Manager::create_placeholder_team() is
 * idempotent -- calling it twice with the same team name + config_id
 * reuses the existing placeholder post instead of minting a duplicate.
 *
 * Phase 7 of the postseason/playoffs design: SPSG_Postseason_Matchup_Builder
 * will call this (via SPSG_Postseason_Seed_Resolver::mint_division_placeholders())
 * once per "Generate" click, so repeat calls for the same config must be safe.
 *
 * Standalone -- bootstraps minimal WP mocks (an in-memory sp_team post
 * store) then loads the real class directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

class WP_Error {
	public $code;
	public $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }

$ptmi_posts = array(); // post_id => stdClass( ID, post_title )
$ptmi_meta  = array(); // post_id => [ meta_key => value ]
$ptmi_next_id = 1;

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_insert_post( $data, $wp_error = false ) {
	global $ptmi_posts, $ptmi_next_id;
	$id = $ptmi_next_id++;
	$ptmi_posts[ $id ] = (object) array( 'ID' => $id, 'post_title' => $data['post_title'] );
	return $id;
}

function update_post_meta( $post_id, $key, $value ) {
	global $ptmi_meta;
	$ptmi_meta[ $post_id ][ $key ] = $value;
}

/**
 * Filters the in-memory post store by post_title and meta_query entries
 * (AND logic) -- enough to exercise find_existing_placeholder()'s exact
 * query shape without a real database.
 */
function get_posts( $args ) {
	global $ptmi_posts, $ptmi_meta;
	$matches = array();
	foreach ( $ptmi_posts as $id => $post ) {
		if ( isset( $args['title'] ) && $post->post_title !== $args['title'] ) {
			continue;
		}
		$meta_ok = true;
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
			if ( ( $ptmi_meta[ $id ][ $clause['key'] ] ?? null ) !== $clause['value'] ) {
				$meta_ok = false;
				break;
			}
		}
		if ( $meta_ok ) {
			$matches[] = $post;
		}
	}
	$limit = $args['posts_per_page'] ?? count( $matches );
	return array_slice( $matches, 0, $limit );
}

require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';

$passed = 0;
$failed = 0;

function ptmi_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

echo "=== create_placeholder_team(): idempotent for the same name + config_id ===\n\n";

$first_id = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 1', 'config_playoffs', 'Div 1' );
ptmi_assert( ! is_wp_error( $first_id ) && is_int( $first_id ), 'first call mints a new team and returns its id' );

$second_id = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 1', 'config_playoffs', 'Div 1' );
ptmi_assert( $second_id === $first_id, 'a second call with the same name + config_id reuses the same team id, no duplicate post' );

global $ptmi_posts;
ptmi_assert( 1 === count( $ptmi_posts ), 'only one sp_team post actually exists after both calls' );

echo "\n=== create_placeholder_team(): distinct names or configs still mint separately ===\n\n";

$different_name_id = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 2', 'config_playoffs', 'Div 1' );
ptmi_assert( $different_name_id !== $first_id, 'a different team name mints a distinct team, even for the same config' );

$different_config_id = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 1', 'config_other', 'Div 1' );
ptmi_assert( $different_config_id !== $first_id, 'the same name under a different config_id mints a distinct team' );

echo "\n=== create_placeholder_team(): no config_id given always mints fresh (no scope to dedupe against) ===\n\n";

$no_config_a = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 3', '', 'Div 1' );
$no_config_b = SPSG_Placeholder_Team_Manager::create_placeholder_team( 'Div 1 Seed 3', '', 'Div 1' );
ptmi_assert( $no_config_a !== $no_config_b, 'calling with an empty config_id twice mints two separate teams' );

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
