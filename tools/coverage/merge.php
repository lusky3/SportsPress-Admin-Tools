<?php
/**
 * Merge per-process PCOV JSON dumps (written by tools/coverage/prepend.php) into
 * a single Clover XML report that SonarCloud (sonar.php.coverage.reportPaths)
 * understands.
 *
 * Usage: php tools/coverage/merge.php <dumps-dir> <output-clover.xml> <repo-root>
 *
 * PCOV line values: >0 executed, -1 executable-but-not-run, absent = ignore.
 * A line is "coverable" if any dump lists it non-zero; "covered" if any dump
 * lists it > 0.
 */

if ( $argc < 3 ) {
	fwrite( STDERR, "usage: merge.php <dumps-dir> <output.xml> [repo-root]\n" );
	exit( 2 );
}

$dumps_dir = rtrim( $argv[1], '/' );
$out_path  = $argv[2];
$root      = rtrim( $argv[3] ?? getcwd(), '/' );

/** Skip test harnesses, coverage tooling, vendored and non-source files. */
function spss_cov_is_source( $file ) {
	if ( ! preg_match( '/\.php$/', $file ) ) {
		return false;
	}
	foreach ( array( '/tests/', '/tools/', '/vendor/', '/node_modules/' ) as $skip ) {
		if ( false !== strpos( $file, $skip ) ) {
			return false;
		}
	}
	return true;
}

$merged = array(); // file => [ line => count ]
foreach ( (array) glob( $dumps_dir . '/cov-*.json' ) as $dump ) {
	$data = json_decode( (string) file_get_contents( $dump ), true );
	if ( ! is_array( $data ) ) {
		continue;
	}
	foreach ( $data as $file => $lines ) {
		if ( ! spss_cov_is_source( $file ) || ! is_array( $lines ) ) {
			continue;
		}
		if ( ! isset( $merged[ $file ] ) ) {
			$merged[ $file ] = array();
		}
		foreach ( $lines as $ln => $val ) {
			$val = (int) $val;
			if ( 0 === $val ) {
				continue; // non-executable
			}
			$hits = $val > 0 ? $val : 0;
			if ( ! isset( $merged[ $file ][ $ln ] ) ) {
				$merged[ $file ][ $ln ] = 0;
			}
			// Coverable is implied by presence; covered = max hits across dumps.
			$merged[ $file ][ $ln ] = max( $merged[ $file ][ $ln ], $hits );
		}
	}
}

ksort( $merged );

$xml  = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<coverage generated=\"0\"><project timestamp=\"0\">\n";
$tot_stmts = 0;
$tot_cov   = 0;
foreach ( $merged as $file => $lines ) {
	ksort( $lines );
	$stmts = count( $lines );
	$cov   = count( array_filter( $lines, static fn( $c ) => $c > 0 ) );
	$tot_stmts += $stmts;
	$tot_cov   += $cov;
	// Emit repo-relative paths so Sonar resolves them regardless of the CI
	// checkout's absolute location.
	$rel        = ( 0 === strpos( $file, $root . '/' ) ) ? substr( $file, strlen( $root ) + 1 ) : $file;
	$xml       .= '  <file name="' . htmlspecialchars( $rel, ENT_XML1 ) . "\">\n";
	foreach ( $lines as $ln => $c ) {
		$xml .= '    <line num="' . (int) $ln . '" type="stmt" count="' . (int) $c . "\"/>\n";
	}
	$xml .= '    <metrics statements="' . $stmts . '" coveredstatements="' . $cov . "\"/>\n";
	$xml .= "  </file>\n";
}
$xml .= '  <metrics files="' . count( $merged ) . '" statements="' . $tot_stmts . '" coveredstatements="' . $tot_cov . "\"/>\n";
$xml .= "</project></coverage>\n";

file_put_contents( $out_path, $xml );

$pct = $tot_stmts > 0 ? round( 100 * $tot_cov / $tot_stmts, 1 ) : 0.0;
fwrite( STDERR, sprintf( "Clover written: %s\nfiles=%d covered=%d/%d statements (%s%%)\n", $out_path, count( $merged ), $tot_cov, $tot_stmts, $pct ) );
