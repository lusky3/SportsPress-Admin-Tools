<?php
/**
 * Run one standalone test file with line coverage collection.
 *
 * Usage:
 *
 *     php bin/coverage/run-one.php <test-file> <output-file>
 *
 * The test file is required into this script's global scope, exactly as
 * `php sportspress-x/tests/test-foo.php` would load it, so its assertions run
 * unchanged and its closing exit() still carries the suite's pass/fail code.
 * Coverage is stopped and written from a shutdown function for precisely that
 * reason — an exit() from the test file skips the rest of this script but still
 * runs shutdown handlers, and returning from a shutdown handler leaves the exit
 * code the test chose untouched.
 *
 * Local variables here are all spat_cov_-prefixed and unset before the require,
 * so nothing in this file can shadow a variable a test harness relies on.
 *
 * @package SportsPress_Admin_Tools
 */

use SebastianBergmann\CodeCoverage\Report\PHP as PhpReport;

/*
 * This is CLI tooling: it writes plain text to a terminal, never HTML to a
 * browser, and runs with WordPress absent — esc_html() is not defined here,
 * so the escaping sniff has nothing to suggest that would not fatal. Every
 * value printed is a file path or a number this script computed itself.
 */
// phpcs:disable WordPress.Security.EscapeOutput -- CLI-only output, no WordPress runtime.

require_once __DIR__ . '/lib.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/coverage/run-one.php <test-file> <output-file>\n" );
	exit( 1 );
}

$spat_cov_test = realpath( $argv[1] );

if ( false === $spat_cov_test || ! is_file( $spat_cov_test ) ) {
	fwrite( STDERR, "Test file not found: {$argv[1]}\n" );
	exit( 1 );
}

$spat_cov_output   = $argv[2];
$spat_cov_id       = basename( $spat_cov_test );
$spat_cov_coverage = SPAT_Coverage_Support::coverage();

$spat_cov_coverage->start( $spat_cov_id );

register_shutdown_function(
	static function () use ( $spat_cov_coverage, $spat_cov_output ): void {
		try {
			$spat_cov_coverage->stop();
			( new PhpReport() )->process( $spat_cov_coverage, $spat_cov_output );
		} catch ( Throwable $spat_cov_error ) {
			fwrite( STDERR, 'Coverage collection failed: ' . $spat_cov_error->getMessage() . "\n" );
			exit( 1 );
		}
	}
);

unset( $spat_cov_coverage, $spat_cov_output, $spat_cov_id );

// Several suites chdir() or resolve paths relative to their own directory.
chdir( dirname( $spat_cov_test ) );

require_once $spat_cov_test;
