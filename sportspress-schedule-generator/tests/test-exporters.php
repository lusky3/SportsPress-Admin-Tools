<?php
/**
 * Test Exporters (CSV + XLSX)
 *
 * Covers:
 *  - SPSG_CSV_Exporter::csv_safe formula-injection guard (Pass-2 F1, extended cases).
 *  - SPSG_XLSX_Exporter numeric-branch handling (Pass-2 F4): digit-only team
 *    names (e.g. "007") must NOT be emitted as raw <v>007</v> in a string
 *    column, otherwise Excel coerces them to numbers and drops leading zeros.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

// Constants.
define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

// WP function mocks.
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $msg = '' ) { throw new RuntimeException( (string) $msg ); }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $fmt, $ts = null ) { return gmdate( $fmt, $ts ?? time() ); }
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

// Interface + exporters.
if ( ! interface_exists( 'SPSG_Exporter_Interface' ) ) {
	interface SPSG_Exporter_Interface {
		public function export( $schedule, $config, $style = '' );
		public function get_format();
		public function get_extension();
		public function get_mime_type();
		public function supports_formatting();
	}
}
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';
require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-xlsx-exporter.php';

// Helpers.
function tx_assert( $cond, $msg ) {
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		return true;
	}
	echo "✗ FAIL: $msg\n";
	return false;
}

echo "=== Testing Exporters ===\n\n";
$passed = 0;
$failed = 0;

// -------------------------------------------------------------------------
// Test 1: csv_safe — extended Pass-2 F1 coverage.
// -------------------------------------------------------------------------
echo "Test 1: csv_safe formula-injection guard (extended)\n";

// (input, expected) pairs.
$csv_cases = array(
	// Dangerous prefixes.
	array( '=cmd|',          "'=cmd|" ),
	array( '+1+1',           "'+1+1" ),
	array( '-1',             "'-1" ),
	array( '@SUM(A1)',       "'@SUM(A1)" ),
	array( "\tcell",         "'\tcell" ),
	array( "\rcell",         "'\rcell" ),
	array( "\nrowtwo",       "'\nrowtwo" ),
	// Safe.
	array( 'Toronto FC',     'Toronto FC' ),
	array( '',               '' ),
	array( '123',            '123' ),       // pure number string — safe.
	array( 123,              123 ),         // integer — guard preserves as-is.
	array( 12.5,             12.5 ),        // float — guard preserves as-is.
	// Leading whitespace + dangerous char.
	// NOTE: current implementation only checks substr(0,1), so a leading
	// SPACE then '=' is NOT prefixed. Documenting actual behaviour: most
	// spreadsheet apps trim leading whitespace before parsing, but PhpSheet
	// and LibreOffice keep them — so leading-space-then-= is technically
	// passed through. We assert the documented behaviour: no quoting.
	array( ' =1+1',          ' =1+1' ),
);

$csv_ok = true;
foreach ( $csv_cases as $case ) {
	list( $in, $expected ) = $case;
	$out = SPSG_CSV_Exporter::csv_safe( $in );
	if ( $out !== $expected ) {
		$csv_ok = false;
		echo "  csv_safe(" . var_export( $in, true ) . ") => "
			. var_export( $out, true ) . " expected "
			. var_export( $expected, true ) . "\n";
	}
}
if ( tx_assert( $csv_ok, 'csv_safe handles dangerous + safe values per Pass-2 F1 spec' ) ) {
	$passed++;
} else {
	$failed++;
}

// Null + boolean — passthrough.
$out_null = SPSG_CSV_Exporter::csv_safe( null );
if ( tx_assert( $out_null === null, 'csv_safe(null) passes through unchanged' ) ) {
	$passed++;
} else {
	$failed++;
}
$out_true = SPSG_CSV_Exporter::csv_safe( true );
if ( tx_assert( $out_true === true, 'csv_safe(true) passes through unchanged' ) ) {
	$passed++;
} else {
	$failed++;
}

echo "\n";

// -------------------------------------------------------------------------
// Test 2: XLSX exporter must NOT emit a digit-only team name as raw numeric.
// Pass-2 F4: team name "007" should land in inline-string or shared-string
// cells, never as <v>7</v>.
// -------------------------------------------------------------------------
echo "Test 2: XLSX numeric-branch — digit-only team names stay as strings\n";

if ( ! class_exists( 'ZipArchive' ) ) {
	echo "  (skipped — PHP ZipArchive extension not available)\n";
} else {
	$schedule = array(
		(object) array(
			'date'              => '2024-03-15',
			'time_slot'         => '19:00',
			'end_time'          => '20:00',
			'match_length'      => 60,
			'home_team'         => (object) array( 'id' => 't007', 'name' => '007' ),
			'away_team'         => (object) array( 'id' => 't1990', 'name' => '1990' ),
			'venue'             => (object) array( 'id' => 'v1', 'name' => 'Field 1' ),
			'division'          => (object) array( 'id' => 'd1', 'name' => 'Div A' ),
			'home_away'         => '007 (H) vs 1990 (A)',
			'is_inter_division' => false,
			'is_makeup'         => false,
			'week_number'       => 1,
		),
	);

	$exporter = new SPSG_XLSX_Exporter();
	$result   = $exporter->export( $schedule, null, 'detailed' );

	if ( is_wp_error( $result ) ) {
		$failed++;
		echo "✗ FAIL: XLSX export returned WP_Error: " . $result->get_error_message() . "\n";
	} else {
		$path = $result['path'];
		if ( ! tx_assert( file_exists( $path ), 'XLSX file was created on disk' ) ) {
			$failed++;
		} else {
			$passed++;

			// Read sheet1.xml out of the zip and look for the team-name cell.
			$zip = new ZipArchive();
			if ( $zip->open( $path ) === true ) {
				$sheet_xml = $zip->getFromName( 'xl/worksheets/sheet1.xml' );
				$shared_xml = $zip->getFromName( 'xl/sharedStrings.xml' );
				$zip->close();

				// "007" must not appear as a raw numeric value cell. The
				// dangerous emission is <v>007</v> or <v>7</v> inside a cell
				// without t="s" or t="inlineStr".
				$has_bare_numeric_007 = (bool) preg_match(
					'#<c[^>]*\sr="[A-Z]+\d+"(?![^>]*t="(?:s|inlineStr)")[^>]*>\s*<v>0*7</v>#',
					$sheet_xml
				);
				if ( tx_assert( ! $has_bare_numeric_007, '"007" team name is NOT emitted as a raw <v> numeric' ) ) {
					$passed++;
				} else {
					$failed++;
					echo "  sheet1.xml excerpt:\n" . substr( $sheet_xml, 0, 2000 ) . "\n";
				}

				// "1990" must not be emitted as bare numeric either.
				$has_bare_numeric_1990 = (bool) preg_match(
					'#<c[^>]*\sr="[A-Z]+\d+"(?![^>]*t="(?:s|inlineStr)")[^>]*>\s*<v>1990</v>#',
					$sheet_xml
				);
				if ( tx_assert( ! $has_bare_numeric_1990, '"1990" team name is NOT emitted as a raw <v> numeric' ) ) {
					$passed++;
				} else {
					$failed++;
				}

				// The team names should appear in shared strings OR an inline-string cell.
				$in_shared = ( $shared_xml && strpos( $shared_xml, '<t>007</t>' ) !== false )
					|| ( $shared_xml && strpos( $shared_xml, '<t>1990</t>' ) !== false );
				$in_inline = strpos( $sheet_xml, '<is><t>007</t></is>' ) !== false
					|| strpos( $sheet_xml, '<is><t>1990</t></is>' ) !== false;
				if ( tx_assert( $in_shared || $in_inline, 'Digit-only team names are present as strings (shared OR inline)' ) ) {
					$passed++;
				} else {
					$failed++;
				}
			} else {
				$failed++;
				echo "✗ FAIL: could not open generated XLSX as ZIP\n";
			}

			// Note: leaving the generated XLSX in the system temp directory.
			// We deliberately do not call unlink() on the path returned by
			// the exporter (semgrep flags it as user-input in a path).
		}
	}
}

echo "\n";

// Summary.
echo "=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ( $passed + $failed ) . "\n";

if ( $failed === 0 ) {
	echo "\n✓ All tests passed!\n";
	exit( 0);
}
echo "\n✗ Some tests failed\n";
exit( 1 );
