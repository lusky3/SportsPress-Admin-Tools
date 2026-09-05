<?php
/**
 * Shared plumbing for the standalone coverage runner.
 *
 * The suite is not PHPUnit: run-all-tests.sh runs every registered test file in
 * its own `php` subprocess because most of them redeclare the same global
 * WordPress stubs (get_posts(), __(), add_action(), ...) and would fatal on
 * symbol redeclaration if required into a single process. Coverage therefore
 * has to be collected once per subprocess and merged afterwards; see
 * bin/coverage/run-one.php and bin/coverage/merge.php.
 *
 * Everything lives on one class rather than in plain functions: run-one.php
 * requires this file into the same global scope that then requires a test file,
 * and the harness owns a large surface of global function names. A class
 * nobody else declares cannot collide with it.
 *
 * @package SportsPress_Admin_Tools
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

/*
 * This is CLI tooling: it writes plain text to a terminal, never HTML to a
 * browser, and runs with WordPress absent — esc_html() is not defined here,
 * so the escaping sniff has nothing to suggest that would not fatal. Every
 * value printed is a file path or a number this script computed itself.
 */
// phpcs:disable WordPress.Security.EscapeOutput -- CLI-only output, no WordPress runtime.

if ( 'cli' !== PHP_SAPI ) {
	exit( 1 );
}

$spat_cov_autoload = dirname( __DIR__, 2 ) . '/vendor/autoload.php';

if ( ! is_file( $spat_cov_autoload ) ) {
	fwrite( STDERR, "Coverage tooling is not installed. Run `composer install` first.\n" );
	exit( 1 );
}

require_once $spat_cov_autoload;
unset( $spat_cov_autoload );

/**
 * Coverage configuration shared by the per-process runner and the merge step.
 */
final class SPAT_Coverage_Support {

	/**
	 * Repository root (the directory holding the sportspress-* plugin dirs).
	 *
	 * @return string
	 */
	public static function root(): string {
		return dirname( __DIR__, 2 );
	}

	/**
	 * Directory the static-analysis cache is kept in.
	 *
	 * Shared across all subprocesses so each source file is parsed once for the
	 * whole run instead of once per test file — which matters here, where 57
	 * subprocesses each see eight plugins' worth of code. Lives inside the
	 * gitignored raw-data directory, in a dot-prefixed subdirectory so the merge
	 * step's *.cov glob never picks it up.
	 *
	 * @return string
	 */
	public static function cache_dir(): string {
		return self::root() . '/.coverage-raw/.cache';
	}

	/**
	 * Source files coverage is measured over.
	 *
	 * Shipped plugin code only — each plugin's main file plus everything under
	 * its includes/ — and the repository's own scripts/. Deliberately excluded:
	 *
	 * - tests/         the harness itself, not the code under test.
	 * - vendor/        third-party, and dev-only at that.
	 * - node_modules/  likewise.
	 * - build/         the compiled React bundle: generated, and JS besides.
	 * - src/           React sources; no JS test suite exists to measure them.
	 * - uninstall.php  only ever executed by WordPress's uninstall lifecycle,
	 *                  with WP_UNINSTALL_PLUGIN defined and a live $wpdb. The
	 *                  standalone harness cannot require it without side
	 *                  effects, so including it would only pin a permanently 0%
	 *                  file into the report that no test could ever move.
	 *
	 * Files that no test covers today are still included when they are ordinary
	 * reachable code: a real 0% is a gap worth reporting, unlike uninstall.php's,
	 * which would be a measurement artefact.
	 *
	 * @return string[] Absolute file paths.
	 */
	public static function source_files(): array {
		$root  = self::root();
		$files = array();

		foreach ( glob( $root . '/sportspress-*', GLOB_ONLYDIR ) ?: array() as $plugin ) {
			$name = basename( $plugin );

			$main = $plugin . '/' . $name . '.php';
			if ( is_file( $main ) ) {
				$files[] = $main;
			}

			$found = self::php_files_recursive( $plugin . '/includes' );
			sort( $found );
			$files = array_merge( $files, $found );
		}

		/*
		 * scripts/debug/ is gitignored local scratch that never reaches CI.
		 * Measuring it would sit three permanently-0% files in the report and
		 * make the local percentage disagree with the one SonarCloud sees.
		 */
		$scripts = array_filter(
			self::php_files_recursive( $root . '/scripts' ),
			static function ( string $path ) use ( $root ): bool {
				return 0 !== strpos( $path, $root . '/scripts/debug/' );
			}
		);
		sort( $scripts );
		$files = array_merge( $files, $scripts );

		return array_values( array_filter( $files, 'is_file' ) );
	}

	/**
	 * Every .php file under a directory, at any depth.
	 *
	 * Descends rather than using a flat glob so a future subdirectory's files
	 * cannot be silently dropped from the report with nothing to flag the gap.
	 *
	 * @param string $directory Directory to search.
	 * @return string[] Absolute file paths.
	 */
	private static function php_files_recursive( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS )
		);

		$files = array();
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$files[] = $file->getPathname();
			}
		}

		return $files;
	}

	/**
	 * Filter restricted to the suite's own source files.
	 *
	 * @return Filter
	 */
	public static function filter(): Filter {
		$filter = new Filter();
		$filter->includeFiles( self::source_files() );

		return $filter;
	}

	/**
	 * A CodeCoverage bound to the line-coverage driver (PCOV here and in CI).
	 *
	 * Selector::forLineCoverage() picks PCOV when the extension is loaded and
	 * falls back to Xdebug otherwise; it throws
	 * NoCodeCoverageDriverAvailableException when neither is present, which is
	 * the failure we want rather than a silently empty report.
	 *
	 * @return CodeCoverage
	 */
	public static function coverage(): CodeCoverage {
		$filter = self::filter();

		$coverage = new CodeCoverage(
			( new Selector() )->forLineCoverage( $filter ),
			$filter
		);

		$coverage->cacheStaticAnalysis( self::cache_dir() );

		return $coverage;
	}
}
