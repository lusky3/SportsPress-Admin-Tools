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

require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
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

// ---------------------------------------------------------------------------
// Fallback path: no $config at all (a brand-new venue row has nothing saved
// yet) falls back to whatever the venue array itself carries directly under
// 'timeslots' / 'blackout_dates' -- the legacy shape, kept so a caller that
// doesn't have a configuration handy still renders sensibly.
// ---------------------------------------------------------------------------
echo "\n--- Fallback: no \$config, venue array carries its own data ---\n";

ob_start();
$renderer->render_venue_row(
	array(
		'id'             => '114686',
		'name'           => 'Red',
		'timeslots'      => array( 'friday' => array( '19:30' ) ),
		'blackout_dates' => array( '2027-01-01' ),
	),
	1
);
$html_no_config = ob_get_clean();

vrr_assert(
	1 === preg_match( '/data-day="friday"[^>]*checked/', $html_no_config ),
	'no $config: Friday checkbox is checked from the venue array\'s own "timeslots"'
);
vrr_assert(
	false !== strpos( $html_no_config, '19:30' ),
	'no $config: Friday textarea contains the venue array\'s own saved time'
);
vrr_assert(
	false !== strpos( $html_no_config, '2027-01-01' ),
	'no $config: blackout-dates textarea contains the venue array\'s own saved date'
);

// ---------------------------------------------------------------------------
// A $config is given but has nothing for this venue: falls back to the venue
// array the same way as "no $config at all".
// ---------------------------------------------------------------------------
echo "\n--- Fallback: \$config given but empty for this venue ---\n";

$empty_config = new SPSG_Schedule_Configuration( array( 'venues' => array() ) );

ob_start();
$renderer->render_venue_row(
	array(
		'id'             => '999999',
		'name'           => 'Unlisted Rink',
		'timeslots'      => array( 'sunday' => array( '18:00' ) ),
		'blackout_dates' => array( '2027-02-14' ),
	),
	2,
	$empty_config
);
$html_empty_config = ob_get_clean();

vrr_assert(
	1 === preg_match( '/data-day="sunday"[^>]*checked/', $html_empty_config ),
	'$config with nothing for this venue: still falls back to the venue array\'s own data'
);
vrr_assert(
	false !== strpos( $html_empty_config, '2027-02-14' ),
	'$config with nothing for this venue: blackout date still comes from the venue array'
);

// ---------------------------------------------------------------------------
// render_venues_times_tab() is what actually calls render_venue_row() for
// each configured venue (and passes $config through) -- cover that call
// site too, for both the "has venues" and "no venues yet" branches.
// ---------------------------------------------------------------------------
echo "\n--- render_venues_times_tab() drives render_venue_row() per venue ---\n";

ob_start();
$renderer->render_venues_times_tab( $config );
$tab_html = ob_get_clean();

vrr_assert(
	1 === preg_match( '/data-day="friday"[^>]*checked/', $tab_html ),
	'render_venues_times_tab(): the configured venue\'s Friday hours show as checked'
);

$config_no_venues = new SPSG_Schedule_Configuration( array() );

ob_start();
$renderer->render_venues_times_tab( $config_no_venues );
$tab_html_empty = ob_get_clean();

vrr_assert(
	false !== strpos( $tab_html_empty, 'venues[0][name]' ),
	'render_venues_times_tab(): a config with no venues yet still renders one blank venue row'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
