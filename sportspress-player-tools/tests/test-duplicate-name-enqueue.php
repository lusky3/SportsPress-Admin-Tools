<?php
/**
 * Standalone tests for the duplicate-name warning's PHP wiring (PT-7):
 * SPT_Player_Modifications::enqueue_duplicate_name_check() (when the script
 * loads and what it is given) and SPPT_REST_API::check_edit_players_permission().
 *
 * Usage: php test-duplicate-name-enqueue.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPT_PLUGIN_URL', 'http://example.test/wp-content/plugins/sportspress-player-tools/' );
define( 'SPT_VERSION', '9.9.9' );

$GLOBALS['dn_screen']   = null;
$GLOBALS['dn_can_edit'] = true;
$GLOBALS['dn_enqueued'] = array();
$GLOBALS['dn_localized'] = array();

function add_action( ...$args ) {}
function add_filter( ...$args ) {}
function __( $text ) { return $text; }
function get_current_screen() { return $GLOBALS['dn_screen']; }
function current_user_can( $cap ) { return $GLOBALS['dn_can_edit'] && 'edit_posts' === $cap; }
function rest_url( $path ) { return 'http://example.test/wp-json/' . $path; }
function esc_url_raw( $url ) { return $url; }
function wp_create_nonce( $action ) { return 'nonce-for-' . $action; }
function wp_enqueue_script( $handle, $src, $deps, $ver, $in_footer ) {
	$GLOBALS['dn_enqueued'][ $handle ] = array( $src, $deps, $ver, $in_footer );
}
function wp_localize_script( $handle, $name, $data ) {
	$GLOBALS['dn_localized'][ $handle ] = array( $name, $data );
}

require_once dirname( __FILE__ ) . '/../includes/class-player-modifications.php';
require_once dirname( __FILE__ ) . '/../includes/class-rest-api.php';

$passed = 0;
$failed = 0;
function dn_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function dn_reset() {
	$GLOBALS['dn_enqueued']  = array();
	$GLOBALS['dn_localized'] = array();
	$GLOBALS['dn_can_edit']  = true;
}

$mods = new SPT_Player_Modifications();

echo "=== enqueue_duplicate_name_check() ===\n\n";

dn_reset();
$GLOBALS['dn_screen'] = (object) array( 'post_type' => 'sp_player' );
$mods->enqueue_duplicate_name_check( 'post-new.php' );
dn_assert( isset( $GLOBALS['dn_enqueued']['spt-duplicate-name-check'] ), 'loads on the Add New Player screen' );
$args = $GLOBALS['dn_enqueued']['spt-duplicate-name-check'] ?? array();
dn_assert( 'http://example.test/wp-content/plugins/sportspress-player-tools/assets/js/duplicate-name-check.js' === ( $args[0] ?? '' ), 'serves the asset file from the plugin folder' );
dn_assert( array( 'jquery' ) === ( $args[1] ?? null ) && '9.9.9' === ( $args[2] ?? '' ) && true === ( $args[3] ?? null ), 'depends on jquery, versioned with SPT_VERSION, loaded in the footer' );
$loc = $GLOBALS['dn_localized']['spt-duplicate-name-check'] ?? array();
dn_assert( 'sptDuplicateName' === ( $loc[0] ?? '' ), 'localizes under window.sptDuplicateName' );
dn_assert( 'http://example.test/wp-json/splm/v1/players/check-duplicate-name' === ( $loc[1]['restUrl'] ?? '' ), 'passes the check-duplicate-name REST URL' );
dn_assert( 'nonce-for-wp_rest' === ( $loc[1]['nonce'] ?? '' ), 'passes a wp_rest nonce' );
dn_assert( 3 === count( $loc[1]['i18n'] ?? array() ), 'passes the three translatable strings' );

dn_reset();
$mods->enqueue_duplicate_name_check( 'post.php' );
dn_assert( isset( $GLOBALS['dn_enqueued']['spt-duplicate-name-check'] ), 'loads on the Edit Player screen too' );

dn_reset();
$mods->enqueue_duplicate_name_check( 'edit.php' );
dn_assert( array() === $GLOBALS['dn_enqueued'], 'does not load on other admin pages' );

dn_reset();
$GLOBALS['dn_screen'] = (object) array( 'post_type' => 'sp_team' );
$mods->enqueue_duplicate_name_check( 'post.php' );
dn_assert( array() === $GLOBALS['dn_enqueued'], 'does not load when editing a non-player post type' );

dn_reset();
$GLOBALS['dn_screen'] = null;
$mods->enqueue_duplicate_name_check( 'post.php' );
dn_assert( array() === $GLOBALS['dn_enqueued'], 'does not load when there is no current screen' );

dn_reset();
$GLOBALS['dn_screen']   = (object) array( 'post_type' => 'sp_player' );
$GLOBALS['dn_can_edit'] = false;
$mods->enqueue_duplicate_name_check( 'post.php' );
dn_assert( array() === $GLOBALS['dn_enqueued'], 'does not load for a user who cannot edit posts' );

echo "\n=== check_edit_players_permission() ===\n\n";

$api = new SPPT_REST_API();
$GLOBALS['dn_can_edit'] = true;
dn_assert( true === $api->check_edit_players_permission(), 'allowed for a user who can edit posts' );
$GLOBALS['dn_can_edit'] = false;
dn_assert( false === $api->check_edit_players_permission(), 'refused for a user who cannot' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
