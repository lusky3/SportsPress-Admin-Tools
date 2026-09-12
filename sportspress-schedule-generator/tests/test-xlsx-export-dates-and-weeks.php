<?php
/**
 * Test: XLSX/CSV exports show the correct day and a populated "Week" column.
 *
 * Two reported bugs:
 *
 * 1. Compact XLSX showed the wrong day of week (Friday games printed as
 *    Thursday, Sunday as Saturday). `compact_date_label()` used to build the
 *    label via `wp_date( 'l F j, Y', strtotime( $date ) )`. `$date` is a bare
 *    "Y-m-d" calendar date with no time-of-day of its own; `strtotime()`
 *    resolves it as midnight in the process's default timezone (UTC under
 *    WordPress), and `wp_date()` then converts THAT timestamp into the
 *    site's configured timezone before formatting. For any site timezone
 *    behind UTC, that round trip pushes midnight back into the previous
 *    calendar day. Reproduced here with a real behind-UTC zone
 *    (America/Toronto) and a `wp_date()` mock that actually performs the
 *    conversion (the existing test-exporters.php mock is `gmdate()`, which
 *    never shifts and so could never have caught this).
 *
 * 2. The XLSX "Week" column (detailed layout) was always blank, and the
 *    compact layout's "Week N" header numbered by "Nth distinct date" rather
 *    than "Nth real calendar week", so a Friday/Sunday pair in the same
 *    league week got two different numbers. Neither a plain game object nor
 *    array ever carries a week_number of its own -- nothing upstream sets
 *    one. SPSG_Schedule_Helper::build_week_number_map() now computes it,
 *    shared by both exporters (CSV included, which had the identical blank
 *    "Week" column bug).
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

// A real, behind-UTC site timezone -- the class of timezone that reproduces
// the compact-export day-shift bug. Set before anything else runs.
date_default_timezone_set( 'UTC' );
define( 'SPSG_TEST_SITE_TIMEZONE', 'America/Toronto' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '' ) { throw new RuntimeException( (string) $msg ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	// A realistic mock: converts the UTC timestamp `strtotime()` produced
	// into the configured site timezone before formatting, exactly like the
	// real wp_date(). The bug this test guards against only reproduces with
	// a mock that actually does this conversion.
	function wp_date( $fmt, $ts = null ) {
		$dt = new DateTime( '@' . ( $ts ?? time() ) );
		$dt->setTimezone( new DateTimeZone( SPSG_TEST_SITE_TIMEZONE ) );
		return $dt->format( $fmt );
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		$base = sys_get_temp_dir() . '/spsg-test-uploads';
		if ( ! is_dir( $base ) ) {
			mkdir( $base, 0777, true );
		}
		return array(
			'basedir' => $base,
			'baseurl' => 'http://example.test/uploads',
		);
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return mkdir( $dir, 0777, true );
		}
		return true;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}
if ( ! interface_exists( 'SPSG_Exporter_Interface' ) ) {
	interface SPSG_Exporter_Interface {
		public function export( $schedule, $config, $style = '' );
		public function get_format();
		public function get_extension();
		public function get_mime_type();
		public function supports_formatting();
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-xlsx-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';

$passed = 0;
$failed = 0;

function xdw_assert( $cond, $msg ) {
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

function xdw_game( $date, $home, $away, $division, $time = '19:00' ) {
	return (object) array(
		'date'              => $date,
		'time_slot'         => $time,
		'end_time'          => '20:00',
		'match_length'      => 60,
		'home_team'         => (object) array( 'id' => $home, 'name' => $home ),
		'away_team'         => (object) array( 'id' => $away, 'name' => $away ),
		'venue'             => (object) array( 'id' => 'v1', 'name' => 'Rink' ),
		'division'          => (object) array( 'id' => 'd1', 'name' => $division ),
		'is_inter_division' => false,
		'is_makeup'         => false,
	);
}

echo "=== Testing export date correctness and Week numbering ===\n\n";

// ---------------------------------------------------------------------------
// 1. Unit: SPSG_Schedule_Helper::build_week_number_map()
// ---------------------------------------------------------------------------
echo "Test 1: build_week_number_map() groups by real calendar week\n";

// 2026-09-25 is a Friday, 2026-09-27 the following Sunday -- same ISO week.
// 2026-10-02 is the next Friday -- a different ISO week.
$map = SPSG_Schedule_Helper::build_week_number_map(
	array(
		xdw_game( '2026-09-25', 'A', 'B', 'D1' ),
		xdw_game( '2026-09-27', 'C', 'D', 'D1' ),
		xdw_game( '2026-10-02', 'A', 'C', 'D1' ),
	)
);

xdw_assert(
	$map['2026-09-25'] === $map['2026-09-27'],
	'Friday and the following Sunday (same real week) share one week number'
);
xdw_assert(
	$map['2026-10-02'] !== $map['2026-09-25'],
	'the next Friday (a different real week) gets a different week number'
);
xdw_assert(
	1 === $map['2026-09-25'] && 2 === $map['2026-10-02'],
	'week numbers are sequential starting at 1, in chronological order (got ' . $map['2026-09-25'] . ', ' . $map['2026-10-02'] . ')'
);

// Week numbers must be chronological even when the schedule array itself
// isn't -- a real 272-game season fed to the pre-fix implementation (which
// numbered by "order first seen in $schedule") produced "Week 3" for a date
// that fell chronologically AFTER "Week 4", because the slot allocator
// doesn't guarantee its output is date-ordered.
$map_out_of_order = SPSG_Schedule_Helper::build_week_number_map(
	array(
		xdw_game( '2026-10-16', 'A', 'B', 'D1' ), // 3rd real week, listed 1st
		xdw_game( '2026-09-25', 'A', 'B', 'D1' ), // 1st real week, listed 2nd
		xdw_game( '2026-10-09', 'A', 'B', 'D1' ), // 2nd real week, listed 3rd
	)
);
xdw_assert(
	1 === $map_out_of_order['2026-09-25']
		&& 2 === $map_out_of_order['2026-10-09']
		&& 3 === $map_out_of_order['2026-10-16'],
	'week numbers stay chronological even when input games are not date-ordered (got '
		. $map_out_of_order['2026-09-25'] . ', ' . $map_out_of_order['2026-10-09'] . ', ' . $map_out_of_order['2026-10-16'] . ')'
);

// A season crossing a calendar-year boundary: these two dates are adjacent
// days (Thu/Fri of the same real week) straddling Dec 31 / Jan 1.
$map_boundary = SPSG_Schedule_Helper::build_week_number_map(
	array(
		xdw_game( '2026-12-31', 'A', 'B', 'D1' ),
		xdw_game( '2027-01-01', 'C', 'D', 'D1' ),
	)
);
xdw_assert(
	$map_boundary['2026-12-31'] === $map_boundary['2027-01-01'],
	'a real week straddling Dec 31 / Jan 1 is not split into two week numbers'
);

// ---------------------------------------------------------------------------
// 2. XLSX compact: the day-of-week label must not shift under a behind-UTC
//    site timezone.
// ---------------------------------------------------------------------------
echo "\nTest 2: compact XLSX date headers show the correct day of week\n";

if ( ! class_exists( 'ZipArchive' ) ) {
	echo "  (skipped — PHP ZipArchive extension not available)\n";
} else {
	$schedule = array(
		xdw_game( '2026-09-25', 'Ducks', 'Hammers', 'Division 1' ), // Friday
		xdw_game( '2026-09-27', 'Kings', 'Petes', 'Division 1' ),   // Sunday
	);

	$exporter = new SPSG_XLSX_Exporter();
	$result   = $exporter->export( $schedule, null, 'compact' );

	if ( xdw_assert( ! is_wp_error( $result ), 'compact export succeeds' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) ) ) {
		$zip = new ZipArchive();
		if ( xdw_assert( $zip->open( $result['path'] ) === true, 'compact XLSX opens as a ZIP' ) ) {
			$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
			$zip->close();

			xdw_assert(
				false !== strpos( $shared_xml, 'Friday' ) && false === strpos( $shared_xml, 'Thursday' ),
				'2026-09-25 is labelled Friday, not shifted back to Thursday'
			);
			xdw_assert(
				false !== strpos( $shared_xml, 'Sunday' ) && false === strpos( $shared_xml, 'Saturday' ),
				'2026-09-27 is labelled Sunday, not shifted back to Saturday'
			);
			xdw_assert(
				false !== strpos( $shared_xml, 'Week 1 - Friday' ),
				'both games share "Week 1" in their date headers (' . ( false !== strpos( $shared_xml, 'Week 2' ) ? 'found a spurious Week 2' : 'no Week 2 present' ) . ')'
			);
		}
	}
}

// ---------------------------------------------------------------------------
// 2b. Compact XLSX: date SECTIONS themselves must appear in chronological
//     order, not the schedule array's own (allocator-dependent) order. Found
//     via direct verification against a real 272-game season, where a later
//     date's section printed before an earlier one's.
// ---------------------------------------------------------------------------
echo "\nTest 2b: compact XLSX date sections are in chronological order\n";

if ( ! class_exists( 'ZipArchive' ) ) {
	echo "  (skipped — PHP ZipArchive extension not available)\n";
} else {
	$schedule = array(
		xdw_game( '2026-10-16', 'A', 'B', 'D1' ), // listed 1st, chronologically 3rd
		xdw_game( '2026-09-25', 'A', 'B', 'D1' ), // listed 2nd, chronologically 1st
		xdw_game( '2026-10-09', 'A', 'B', 'D1' ), // listed 3rd, chronologically 2nd
	);

	$exporter = new SPSG_XLSX_Exporter();
	$result   = $exporter->export( $schedule, null, 'compact' );

	if ( xdw_assert( ! is_wp_error( $result ), 'compact export (out-of-order input) succeeds' ) ) {
		$zip = new ZipArchive();
		if ( xdw_assert( $zip->open( $result['path'] ) === true, 'compact XLSX opens as a ZIP' ) ) {
			$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
			$zip->close();

			preg_match_all( '/<t>(Week \d+ - [^<]+)<\/t>/', $shared_xml, $labels );
			$pos_sept25 = null;
			$pos_oct16  = null;
			foreach ( $labels[1] as $i => $label ) {
				if ( false !== strpos( $label, 'September 25' ) ) {
					$pos_sept25 = $i;
				}
				if ( false !== strpos( $label, 'October 16' ) ) {
					$pos_oct16 = $i;
				}
			}
			xdw_assert(
				null !== $pos_sept25 && null !== $pos_oct16 && $pos_sept25 < $pos_oct16,
				'September 25 section appears before October 16, despite the reverse input order'
			);
		}
	}
}

// ---------------------------------------------------------------------------
// 3. XLSX detailed: the "Week" column (last column, L) is populated.
// ---------------------------------------------------------------------------
echo "\nTest 3: detailed XLSX \"Week\" column is populated, not blank\n";

if ( ! class_exists( 'ZipArchive' ) ) {
	echo "  (skipped — PHP ZipArchive extension not available)\n";
} else {
	$schedule = array(
		xdw_game( '2026-09-25', 'Ducks', 'Hammers', 'Division 1' ),
		xdw_game( '2026-09-27', 'Kings', 'Petes', 'Division 1' ),
	);

	$exporter = new SPSG_XLSX_Exporter();
	$result   = $exporter->export( $schedule, null, 'detailed' );

	if ( xdw_assert( ! is_wp_error( $result ), 'detailed export succeeds' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) ) ) {
		$zip = new ZipArchive();
		if ( xdw_assert( $zip->open( $result['path'] ) === true, 'detailed XLSX opens as a ZIP' ) ) {
			$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
			$zip->close();

			// Column L is "Week" (see xml_sheet()'s $numeric_columns comment).
			preg_match( '/<c r="L2"[^>]*><v>(\d+)<\/v><\/c>/', $sheet_xml, $row2 );
			preg_match( '/<c r="L3"[^>]*><v>(\d+)<\/v><\/c>/', $sheet_xml, $row3 );

			xdw_assert(
				isset( $row2[1] ) && '' !== $row2[1],
				'row 2\'s Week cell is populated (got: ' . ( $row2[1] ?? 'MISSING' ) . ')'
			);
			xdw_assert(
				isset( $row2[1] ) && isset( $row3[1] ) && $row2[1] === $row3[1],
				'both games (same real week) report the same Week number'
			);
		}
	}
}

// ---------------------------------------------------------------------------
// 4. CSV: the "Week" column has the same bug (never populated) and the same
//    fix (shared SPSG_Schedule_Helper::build_week_number_map()).
// ---------------------------------------------------------------------------
echo "\nTest 4: CSV \"Week\" column is populated, not blank\n";

$schedule = array(
	xdw_game( '2026-09-25', 'Ducks', 'Hammers', 'Division 1' ),
	xdw_game( '2026-09-27', 'Kings', 'Petes', 'Division 1' ),
);

$exporter = new SPSG_CSV_Exporter();
$result   = $exporter->export( $schedule, null, 'csv' );

if ( xdw_assert( ! is_wp_error( $result ), 'CSV export succeeds' . ( is_wp_error( $result ) ? ' (' . $result->get_error_message() . ')' : '' ) ) ) {
	$lines = array_map( 'str_getcsv', file( $result['path'] ) );
	$header = $lines[0];
	$week_col = array_search( 'Week', $header, true );

	xdw_assert( false !== $week_col, '"Week" header column exists' );
	if ( false !== $week_col ) {
		xdw_assert(
			isset( $lines[1][ $week_col ] ) && '' !== $lines[1][ $week_col ],
			'row 1\'s Week cell is populated (got: "' . ( $lines[1][ $week_col ] ?? 'MISSING' ) . '")'
		);
		xdw_assert(
			isset( $lines[1][ $week_col ], $lines[2][ $week_col ] ) && $lines[1][ $week_col ] === $lines[2][ $week_col ],
			'both games (same real week) report the same Week number'
		);
	}
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
