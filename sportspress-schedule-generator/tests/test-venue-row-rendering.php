<?php
/**
 * Test: the Venues & Times tab shows a venue's saved per-day time slots and
 * blackout dates as checked/populated, not as blank.
 *
 * A venue's per-day time-slot overrides and blackout dates are stored on
 * the configuration as `$config->venue_timeslots[$venue_id]` /
 * `$config->venue_blackout_dates[$venue_id]` (top-level, keyed by venue ID)
 * -- never nested inside the venue's own array entry. SPSG_Admin_Renderer::
 * render_venue_row() used to read `$venue['timeslots']` /
 * `$venue['blackout_dates']` instead, keys nothing ever populates, so a
 * saved venue with real Friday/Sunday hours configured rendered every day
 * unchecked with an empty time box, and the blackout-dates textarea always
 * empty, regardless of what was actually saved.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_textarea' ) ) {
	function esc_textarea( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$result = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';

$passed = 0;
$failed = 0;

function vrr_assert( $cond, $msg ) {
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

echo "=== Testing Venues & Times tab renders saved per-venue data ===\n\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'venues'                  => array( array( 'id' => '114679', 'name' => 'Black' ) ),
		'venue_timeslots'         => array(
			'114679' => array( 'friday' => array( '19:00', '20:00' ), 'sunday' => array( '16:00' ) ),
		),
		'venue_blackout_dates'    => array( '114679' => array( '2026-12-25' ) ),
	)
);

$renderer = new SPSG_Admin_Renderer( null );

ob_start();
$renderer->render_venue_row( $config->venues[0], 0, $config );
$html = ob_get_clean();

vrr_assert(
	1 === preg_match( '/data-day="friday"[^>]*checked/', $html ),
	'Friday checkbox is checked for a venue with saved Friday hours'
);
vrr_assert(
	1 === preg_match( '/data-day="sunday"[^>]*checked/', $html ),
	'Sunday checkbox is checked for a venue with saved Sunday hours'
);
vrr_assert(
	1 === preg_match( '/data-day="monday"(?![^>]*checked)/', $html ) || false === strpos( substr( $html, strpos( $html, 'data-day="monday"' ), 60 ), 'checked' ),
	'Monday (no saved hours) is left unchecked'
);
vrr_assert(
	false !== strpos( $html, "19:00\n20:00" ),
	"Friday's textarea contains the saved times (" . ( false !== strpos( $html, '19:00' ) ? 'found 19:00' : 'missing' ) . ')'
);
vrr_assert(
	false !== strpos( $html, '2026-12-25' ),
	'blackout-dates textarea contains the saved date'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
