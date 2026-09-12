<?php
/**
 * Test: a per-venue, date-specific time-slot override (e.g. "the arena's
 * normal 4-9pm Sunday slot is shortened to 4-7pm on Dec 27, 2026") can
 * actually be configured through the admin UI.
 *
 * `venue_date_availability` and the allocator's priority cascade
 * (date-specific availability > per-venue weekday timeslots > global
 * weekday timeslots -- SPSG_Schedule_Helper::resolve_venue_slots_uncached())
 * already existed and already worked correctly; the gap was that nothing in
 * the admin UI ever exposed a way to populate it for a single date. The
 * only way in was the "Import Venue Schedule from CSV" feature, which is
 * WEEK-granularity only (week_end is hardcoded to week_start + 6 days --
 * SPSG_Venue_Schedule_Importer::parse_csv()) and would have silently
 * widened a single-Sunday exception to cover the whole week, including any
 * other day that venue plays.
 *
 * Added a "Date-Specific Time Overrides" textarea to each venue's row (one
 * override per line: "DATE = TIME, TIME" for a single date, or "DATE to
 * DATE = TIME, TIME" for a range -- the shape a CSV-imported week already
 * produces, so editing and re-saving one round-trips without loss) that
 * maps directly onto the same `venue_date_availability` the allocator
 * already reads.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
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
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$result = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';

$passed = 0;
$failed = 0;

function vdo_assert( $cond, $msg ) {
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

echo "=== Testing parse_venue_date_availability_text() (the textarea format) ===\n\n";

$sanitizer = new SPSG_Configuration_Sanitizer();
$parse = new ReflectionMethod( 'SPSG_Configuration_Sanitizer', 'parse_venue_date_availability_text' );
$parse->setAccessible( true );

$single_date = $parse->invoke( $sanitizer, "2026-12-27 = 16:00, 17:00, 18:00" );
vdo_assert(
	1 === count( $single_date )
		&& '2026-12-27' === $single_date[0]['start_date']
		&& '2026-12-27' === $single_date[0]['end_date']
		&& array( '16:00', '17:00', '18:00' ) === $single_date[0]['time_slots'],
	'a single-date line parses to a range with start_date === end_date and the listed times'
);

$date_range = $parse->invoke( $sanitizer, "2026-12-21 to 2026-12-27 = 16:00, 17:00" );
vdo_assert(
	1 === count( $date_range )
		&& '2026-12-21' === $date_range[0]['start_date']
		&& '2026-12-27' === $date_range[0]['end_date'],
	'a "DATE to DATE" line parses to a real date range (the shape a CSV-imported week already produces)'
);

$multi_line = $parse->invoke( $sanitizer, "2026-12-27 = 16:00, 17:00\nnot a valid line\n2027-01-03 = 16:00" );
vdo_assert(
	2 === count( $multi_line ),
	'multiple lines parse independently, and a malformed line is silently skipped rather than erroring'
);

echo "\n=== Testing sanitize_venue_date_availability() accepts the textarea string ===\n\n";

$sanitized = $sanitizer->sanitize(
	array(
		'venue_date_availability' => array(
			'114686' => "2026-12-27 = 16:00, 17:00, 18:00",
		),
	)
);
vdo_assert(
	1 === count( $sanitized['venue_date_availability']['114686'] )
		&& '2026-12-27' === $sanitized['venue_date_availability']['114686'][0]['start_date']
		&& array( '16:00', '17:00', '18:00' ) === $sanitized['venue_date_availability']['114686'][0]['time_slots'],
	'a raw textarea string submitted for one venue sanitizes into a valid date-availability range'
);

echo "\n=== Testing sanitize_venue_date_availability() still accepts structured arrays (CSV import path) ===\n\n";

$sanitized_structured = $sanitizer->sanitize(
	array(
		'venue_date_availability' => array(
			'114686' => array(
				array( 'start_date' => '2026-12-21', 'end_date' => '2026-12-27', 'time_slots' => array( '16:00', '17:00' ) ),
			),
		),
	)
);
vdo_assert(
	1 === count( $sanitized_structured['venue_date_availability']['114686'] )
		&& '2026-12-21' === $sanitized_structured['venue_date_availability']['114686'][0]['start_date'],
	'a structured range array (from CSV import/REST) still sanitizes correctly, unaffected by the new string-input support'
);

echo "\n=== Testing the allocator actually honours a date-specific override over the normal weekday timeslots ===\n\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'venues' => array( array( 'id' => '114686', 'name' => 'Red' ) ),
		'venue_timeslots' => array(
			'114686' => array( 'sunday' => array( '16:00', '17:00', '18:00', '19:00', '20:00' ) ),
		),
		'venue_date_availability' => $sanitized['venue_date_availability'],
	)
);

$normal_sunday_slots = SPSG_Schedule_Helper::resolve_venue_slots( '114686', '2027-01-03', 'sunday', $config );
vdo_assert(
	array( '16:00', '17:00', '18:00', '19:00', '20:00' ) === $normal_sunday_slots,
	'a normal Sunday (no override) still gets the full 4pm-9pm slot list'
);

$shortened_sunday_slots = SPSG_Schedule_Helper::resolve_venue_slots( '114686', '2026-12-27', 'sunday', $config );
vdo_assert(
	array( '16:00', '17:00', '18:00' ) === $shortened_sunday_slots,
	'Dec 27, 2026 (the overridden date) gets only the shortened 4pm-7pm slot list, not the normal Sunday hours'
);

echo "\n=== Testing an explicit 'this venue doesn't play this day' override isn't masked by the global fallback ===\n\n";

// Reported live on Tikal (winter_2026-28_v1): venue "Red" has NO Sunday
// hours at all (the admin UI's Sunday checkbox for Red is unchecked, which
// saves venue_timeslots['114686']['sunday'] as an explicit empty array --
// not an absent key). A global time_slots['sunday'] belonging to a DIFFERENT
// venue's hours must not leak onto Red just because `!empty([])` is false.
$no_sunday_config = new SPSG_Schedule_Configuration(
	array(
		'venues' => array( array( 'id' => '114686', 'name' => 'Red' ) ),
		'venue_timeslots' => array(
			'114686' => array(
				'friday' => array( '18:45', '19:45' ),
				'sunday' => array(),
			),
		),
		'time_slots' => array(
			'sunday' => array( '16:00', '17:00', '18:00', '19:00', '20:00', '21:00' ),
		),
	)
);

$red_sunday_slots = SPSG_Schedule_Helper::resolve_venue_slots( '114686', '2026-09-27', 'sunday', $no_sunday_config );
vdo_assert(
	empty( $red_sunday_slots ),
	"a venue's explicit empty per-day timeslot list wins over the global fallback (no borrowed hours)"
);

$red_friday_slots = SPSG_Schedule_Helper::resolve_venue_slots( '114686', '2026-09-25', 'friday', $no_sunday_config );
vdo_assert(
	array( '18:45', '19:45' ) === $red_friday_slots,
	'a day the venue DOES have configured still resolves normally (the fix is scoped to the empty case only)'
);

echo "\n=== Testing render_venue_row() shows the saved override and round-trips it ===\n\n";

class SPSG_Test_Config_Manager_Stub_VDO {
	public function get_all_configurations() { return array(); }
	public function list_presets() { return array(); }
}

$renderer = new SPSG_Admin_Renderer( new SPSG_Test_Config_Manager_Stub_VDO() );

ob_start();
$renderer->render_venue_row( array( 'id' => '114686', 'name' => 'Red' ), 0, $config );
$html = ob_get_clean();

vdo_assert(
	false !== strpos( $html, '2026-12-27 = 16:00, 17:00, 18:00' ),
	'the saved override renders back into the textarea in the same "DATE = TIME, TIME" format a user would type'
);

$reparsed = $sanitizer->sanitize(
	array( 'venue_date_availability' => array( '114686' => '2026-12-27 = 16:00, 17:00, 18:00' ) )
);
vdo_assert(
	$sanitized['venue_date_availability'] === $reparsed['venue_date_availability'],
	'round-tripping the rendered textarea back through sanitize() reproduces the exact same saved data (no drift)'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
