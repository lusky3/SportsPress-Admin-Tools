<?php
/**
 * Test: the Generate tab's schedule preview table shows each game's time and
 * renders a "Group Arenas" toggle.
 *
 * render_schedule_preview() is what re-renders the table after
 * window.location.reload() on a fresh generation -- it reads straight from
 * the raw game objects SPSG_Configuration_Manager... er, SPSG_Schedule_Engine
 * stores in the schedule transient, NOT the AJAX response's separately
 * "formatted for display" array (SPSG_Schedule_Generator::
 * format_schedule_for_display(), which maps time_slot -> the key `time`).
 * Those raw objects carry the time under `time_slot` (see
 * SPSG_Slot_Allocator::create_game()); the renderer read `$game['time']`,
 * a key that only exists on the AJAX-formatted copy, so the Time column
 * (and each row's `data-time` attribute the client-side sort/group logic
 * depends on) was always empty.
 *
 * Standalone -- bootstraps WP mocks then loads the renderer directly.
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

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}

require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';

$passed = 0;
$failed = 0;

function sptc_assert( $cond, $msg ) {
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

echo "=== Testing the schedule preview table shows the Time column ===\n\n";

// Shaped like a real raw game object from SPSG_Slot_Allocator::create_game()
// -- `time_slot`, not `time` -- exactly what the schedule transient holds.
$schedule = array(
	(object) array(
		'date' => '2026-09-25',
		'time_slot' => '19:00',
		'end_time' => '20:00',
		'match_length' => 60,
		'home_team' => (object) array( 'id' => '1', 'name' => 'Puck Dynasty' ),
		'away_team' => (object) array( 'id' => '2', 'name' => 'Replacements' ),
		'venue' => (object) array( 'id' => '114679', 'name' => 'Black' ),
		'division' => (object) array( 'id' => 'd1', 'name' => 'Division 1' ),
		'is_inter_division' => false,
	),
	(object) array(
		'date' => '2026-09-25',
		'time_slot' => '19:30',
		'end_time' => '20:30',
		'match_length' => 60,
		'home_team' => (object) array( 'id' => '3', 'name' => 'Black Bulls' ),
		'away_team' => (object) array( 'id' => '4', 'name' => 'Canucks' ),
		'venue' => (object) array( 'id' => '114686', 'name' => 'Red' ),
		'division' => (object) array( 'id' => 'd2', 'name' => 'Division 2' ),
		'is_inter_division' => false,
	),
);

$renderer = new SPSG_Admin_Renderer( null );

ob_start();
$renderer->render_schedule_preview( $schedule, null, 'schedule_test123' );
$html = ob_get_clean();

sptc_assert(
	false !== strpos( $html, '<td>19:00</td>' ),
	'the first row\'s Time cell shows "19:00" (not blank)'
);
sptc_assert(
	false !== strpos( $html, '<td>19:30</td>' ),
	'the second row\'s Time cell shows "19:30" (not blank)'
);
sptc_assert(
	1 === preg_match( '/data-time="19:00"/', $html ),
	'the first row\'s data-time attribute carries "19:00" (client-side sort/group depend on it)'
);
sptc_assert(
	1 === preg_match( '/data-time="19:30"/', $html ),
	'the second row\'s data-time attribute carries "19:30"'
);

echo "\n=== Testing the Group Arenas toggle is rendered ===\n\n";

sptc_assert(
	1 === preg_match( '/<input type="checkbox" id="spsg-group-arenas"/', $html ),
	'a "Group Arenas" checkbox is present in the preview filters row'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
