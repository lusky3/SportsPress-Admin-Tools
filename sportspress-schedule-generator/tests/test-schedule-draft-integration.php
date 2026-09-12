<?php
/**
 * Test: the parts of the persistent-draft feature that sit outside
 * SPSG_Schedule_Draft_Store itself (see test-schedule-draft-store.php for
 * the store's own round-trip tests):
 *
 *  - render_generate_tab() reads a configuration's own draft (not "whatever
 *    this user last generated") and shows the placeholder when there isn't
 *    one, or the draft notice + schedule preview when there is.
 *  - SPSG_Schedule_Generator::discard_draft_if_import_finished() only
 *    discards on a real (non-dry-run) import's final chunk, for a known
 *    configuration -- never on an intermediate chunk, a dry run, or when no
 *    configuration id was supplied.
 *  - ajax_discard_draft() enforces permissions, requires a configuration id,
 *    and actually removes the draft on success.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly. Options are
 * backed by a plain in-memory array (not a transient), matching how
 * SPSG_Schedule_Draft_Store is actually built.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

function __( $s ) { return $s; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_html__( $s ) { return htmlspecialchars( $s, ENT_QUOTES ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_html_e( $s ) { echo htmlspecialchars( $s, ENT_QUOTES ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_attr_e( $s ) { echo htmlspecialchars( $s, ENT_QUOTES ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function wp_unslash( $v ) { return is_array( $v ) ? array_map( 'wp_unslash', $v ) : stripslashes( (string) $v ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function current_time() { return '2026-09-10 12:00:00'; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

$sdi_test_options = array();
function get_option( $name, $default = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $sdi_test_options;
	return $sdi_test_options[ $name ] ?? $default;
}
function update_option( $name, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $sdi_test_options;
	$sdi_test_options[ $name ] = $value;
	return true;
}
function delete_option( $name ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $sdi_test_options;
	unset( $sdi_test_options[ $name ] );
	return true;
}

// --- AJAX-handler harness: capture wp_send_json_*'s payload instead of
// exiting, and let tests control the "current user can" answer. ------------
class SDI_JSON_Response extends Exception {
	public $success;
	public $payload;
}
$sdi_current_user_can = true;
function check_ajax_referer() { return true; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function current_user_can() { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $sdi_current_user_can;
	return $sdi_current_user_can;
}
function wp_send_json_success( $data = null ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	$e = new SDI_JSON_Response();
	$e->success = true;
	$e->payload = $data;
	throw $e;
}
function wp_send_json_error( $data = null ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	$e = new SDI_JSON_Response();
	$e->success = false;
	$e->payload = $data;
	throw $e;
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-draft-store.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-generator.php';

$passed = 0;
$failed = 0;

function sdi_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

function sdi_config() {
	return (object) array(
		'id' => 'config_1',
		'season_start' => null,
		'season_end' => null,
		'games_per_team' => 10,
		'divisions' => array( array( 'id' => 'd1' ) ),
		'venues' => array( array( 'id' => 'v1' ) ),
		'playing_days' => array( 'friday' ),
	);
}

echo "=== render_generate_tab(): reads the configuration's own draft ===\n\n";

$renderer = new SPSG_Admin_Renderer( null );

ob_start();
$renderer->render_generate_tab( sdi_config() );
$html_no_draft = ob_get_clean();

sdi_assert(
	false !== strpos( $html_no_draft, 'spsg-schedule-preview-placeholder' ),
	'with no draft saved, the placeholder is shown'
);
sdi_assert(
	false === strpos( $html_no_draft, 'spsg-discard-draft' ),
	'with no draft saved, no Discard Draft button is rendered'
);

$schedule = array(
	(object) array(
		'date' => '2026-09-25',
		'time_slot' => '19:00',
		'end_time' => '20:00',
		'match_length' => 60,
		'home_team' => (object) array( 'id' => '1', 'name' => 'Puck Dynasty' ),
		'away_team' => (object) array( 'id' => '2', 'name' => 'Replacements' ),
		'venue' => (object) array( 'id' => 'v1', 'name' => 'Arena' ),
		'division' => (object) array( 'id' => 'd1', 'name' => 'Division 1' ),
		'is_inter_division' => false,
	),
);
SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array( 'total_games' => 1 ) );

ob_start();
$renderer->render_generate_tab( sdi_config() );
$html_with_draft = ob_get_clean();

sdi_assert(
	false === strpos( $html_with_draft, 'spsg-schedule-preview-placeholder' ),
	'with a draft saved, the placeholder is NOT shown'
);
sdi_assert(
	false !== strpos( $html_with_draft, 'id="spsg-discard-draft"' ),
	'with a draft saved, the Discard Draft button is rendered'
);
sdi_assert(
	false !== strpos( $html_with_draft, 'data-config-id="config_1"' ),
	'the Discard Draft button carries this configuration\'s id'
);
sdi_assert(
	false !== strpos( $html_with_draft, '2026-09-10 12:00:00' ),
	'the draft notice shows when it was generated'
);
sdi_assert(
	false !== strpos( $html_with_draft, '<td>19:00</td>' ),
	'the draft\'s own schedule preview is rendered underneath the notice'
);

// A second, unrelated configuration has no draft of its own.
$other_config = sdi_config();
$other_config->id = 'config_2';
ob_start();
$renderer->render_generate_tab( $other_config );
$html_other = ob_get_clean();
sdi_assert(
	false !== strpos( $html_other, 'spsg-schedule-preview-placeholder' ),
	'a different configuration with no draft of its own still shows the placeholder'
);

SPSG_Schedule_Draft_Store::delete( 'config_1' );

echo "\n=== discard_draft_if_import_finished(): only discards when truly finished ===\n\n";

$sg = ( new ReflectionClass( 'SPSG_Schedule_Generator' ) )->newInstanceWithoutConstructor();
$discard_method = new ReflectionMethod( 'SPSG_Schedule_Generator', 'discard_draft_if_import_finished' );
$discard_method->setAccessible( true );

SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array() );
$discard_method->invoke( $sg, true, array( 'dry_run' => false, 'config_id' => 'config_1' ) );
sdi_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'more chunks remaining ($has_more=true): draft is NOT discarded'
);

$discard_method->invoke( $sg, false, array( 'dry_run' => true, 'config_id' => 'config_1' ) );
sdi_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'a dry run: draft is NOT discarded even on the final chunk'
);

$discard_method->invoke( $sg, false, array( 'dry_run' => false, 'config_id' => '' ) );
sdi_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'no configuration id supplied: draft is NOT discarded'
);

$discard_method->invoke( $sg, false, array( 'dry_run' => false, 'config_id' => 'config_1' ) );
sdi_assert(
	null === SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'a real import\'s final chunk: draft IS discarded'
);

echo "\n=== ajax_discard_draft(): permissions, validation, and the actual discard ===\n\n";

SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array() );

$sdi_current_user_can = false;
$_POST = array( 'config_id' => 'config_1' );
try {
	$sg->ajax_discard_draft();
	sdi_assert( false, 'insufficient permissions: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert( false === $e->success, 'insufficient permissions: wp_send_json_error was called' );
}
sdi_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'insufficient permissions: the draft is untouched'
);

$sdi_current_user_can = true;
$_POST = array( 'config_id' => '' );
try {
	$sg->ajax_discard_draft();
	sdi_assert( false, 'missing config_id: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert( false === $e->success, 'missing config_id: wp_send_json_error was called' );
}
sdi_assert(
	null !== SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'missing config_id: the draft is untouched'
);

$_POST = array( 'config_id' => 'config_1' );
try {
	$sg->ajax_discard_draft();
	sdi_assert( false, 'valid request: expected wp_send_json_success to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert( true === $e->success, 'valid request: wp_send_json_success was called' );
}
sdi_assert(
	null === SPSG_Schedule_Draft_Store::get( 'config_1' ),
	'valid request: the draft was actually discarded'
);

echo "\n=== ajax_export_schedule(): schedule lookup resolves via the draft store ===\n\n";

$sdi_current_user_can = true;
$_POST = array( 'schedule_id' => 'nonexistent_id', 'format' => 'csv' );
try {
	$sg->ajax_export_schedule();
	sdi_assert( false, 'unknown schedule_id: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert( false === $e->success, 'unknown schedule_id: wp_send_json_error was called (schedule not found)' );
}

$known_id = SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array() );
$_POST = array( 'schedule_id' => $known_id, 'format' => 'bogus-format' );
try {
	$sg->ajax_export_schedule();
	sdi_assert( false, 'known schedule_id, invalid format: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert(
		false === $e->success && false !== strpos( $e->payload, 'Invalid export format' ),
		'known schedule_id resolves via the draft store, then format validation runs next'
	);
}
SPSG_Schedule_Draft_Store::delete( 'config_1' );

echo "\n=== ajax_import_to_sportspress(): schedule lookup resolves via the draft store ===\n\n";

$_POST = array( 'schedule_id' => 'nonexistent_id' );
try {
	$sg->ajax_import_to_sportspress();
	sdi_assert( false, 'unknown schedule_id: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert( false === $e->success, 'unknown schedule_id: wp_send_json_error was called (schedule not found)' );
}

$known_id2 = SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array() );
$_POST = array( 'schedule_id' => $known_id2, 'conflict_resolution' => 'bogus' );
try {
	$sg->ajax_import_to_sportspress();
	sdi_assert( false, 'known schedule_id, invalid conflict_resolution: expected wp_send_json_error to be reached' );
} catch ( SDI_JSON_Response $e ) {
	sdi_assert(
		false === $e->success && false !== strpos( $e->payload, 'Invalid conflict resolution' ),
		'known schedule_id resolves via the draft store, then option validation runs next'
	);
}
SPSG_Schedule_Draft_Store::delete( 'config_1' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
