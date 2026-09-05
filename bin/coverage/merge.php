<?php
/**
 * Merge per-process coverage snapshots and emit the combined report.
 *
 * Usage:
 *
 *     php bin/coverage/merge.php <raw-data-dir> <output-dir>
 *
 * Reads every *.cov file bin/coverage/run-one.php wrote (each one a
 * SebastianBergmann\CodeCoverage\Report\PHP dump: a PHP file returning an
 * unserialized CodeCoverage object), folds them into a single CodeCoverage via
 * CodeCoverage::merge(), then writes Clover XML to <output-dir>/clover.xml and
 * prints a per-plugin line-coverage summary to stdout.
 *
 * Clover is the one format written because it is the one both consumers need:
 * SonarCloud reads it through sonar.php.coverage.reportPaths, and Codacy
 * parses it natively.
 *
 * @package SportsPress_Admin_Tools
 */

use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Node\File as FileNode;
use SebastianBergmann\CodeCoverage\Report\Clover;

/*
 * This is CLI tooling: it writes plain text to a terminal, never HTML to a
 * browser, and runs with WordPress absent — esc_html() is not defined here,
 * so the escaping sniff has nothing to suggest that would not fatal. Every
 * value printed is a file path or a number this script computed itself.
 */
// phpcs:disable WordPress.Security.EscapeOutput -- CLI-only output, no WordPress runtime.

require_once __DIR__ . '/lib.php';

if ( $argc < 3 ) {
	fwrite( STDERR, "Usage: php bin/coverage/merge.php <raw-data-dir> <output-dir>\n" );
	exit( 1 );
}

$raw_dir    = rtrim( $argv[1], '/' );
$output_dir = rtrim( $argv[2], '/' );
$snapshots  = glob( $raw_dir . '/*.cov' );

if ( ! $snapshots ) {
	fwrite( STDERR, "No coverage snapshots found in {$raw_dir}/\n" );
	exit( 1 );
}

sort( $snapshots );

/*
 * The combined object is built from the shared filter rather than from the
 * first snapshot, so every source file is present in the report even when no
 * test ever loaded it. CodeCoverage::includeUncoveredFiles() is on by default
 * and turns those into honest 0% entries; without them the percentage would
 * only ever be computed over the files the suite happens to touch — which for
 * a monorepo of eight plugins would flatter the number badly.
 */
$combined = SPAT_Coverage_Support::coverage();

foreach ( $snapshots as $snapshot ) {
	$loaded = require_once $snapshot;

	if ( ! $loaded instanceof CodeCoverage ) {
		fwrite( STDERR, "Not a coverage snapshot: {$snapshot}\n" );
		exit( 1 );
	}

	$combined->merge( $loaded );
}

$clover = $output_dir . '/clover.xml';
( new Clover() )->process( $combined, $clover, 'SportsPress Admin Tools' );

//
// Human/CI-log summary. 143 files is too many to read one per line in a CI log,
// so roll up by plugin and list only the fully-uncovered files underneath.
//

$report = $combined->getReport();
$root   = SPAT_Coverage_Support::root() . '/';
$groups = array();
$zero   = array();

foreach ( $report as $node ) {
	if ( ! $node instanceof FileNode ) {
		continue;
	}

	$relative = str_replace( $root, '', $node->pathAsString() );
	$group    = explode( '/', $relative )[0];

	if ( ! isset( $groups[ $group ] ) ) {
		$groups[ $group ] = array(
			'executed'   => 0,
			'executable' => 0,
			'files'      => 0,
		);
	}

	$groups[ $group ]['executed']   += $node->numberOfExecutedLines();
	$groups[ $group ]['executable'] += $node->numberOfExecutableLines();
	++$groups[ $group ]['files'];

	if ( 0 === $node->numberOfExecutedLines() && $node->numberOfExecutableLines() > 0 ) {
		$zero[] = $relative;
	}
}

ksort( $groups );
$width = $groups ? max( array_map( 'strlen', array_keys( $groups ) ) ) : 10;

echo "\nLine coverage by plugin\n";
echo str_repeat( '-', $width + 34 ) . "\n";

foreach ( $groups as $group => $row ) {
	$percent = $row['executable'] > 0 ? ( $row['executed'] / $row['executable'] ) * 100 : 0.0;
	printf( "%-{$width}s  %6.2f%%  (%d/%d lines, %d files)\n", $group, $percent, $row['executed'], $row['executable'], $row['files'] );
}

echo str_repeat( '-', $width + 34 ) . "\n";
printf(
	"%-{$width}s  %6.2f%%  (%d/%d lines)\n",
	'TOTAL',
	(float) $report->percentageOfExecutedLines()->asFloat(),
	$report->numberOfExecutedLines(),
	$report->numberOfExecutableLines()
);

if ( $zero ) {
	sort( $zero );
	printf( "\n%d file(s) with no coverage at all:\n", count( $zero ) );
	foreach ( $zero as $file ) {
		echo "  {$file}\n";
	}
}

printf(
	"\nMerged %d coverage snapshot(s).\nClover XML written to %s\n",
	count( $snapshots ),
	$clover
);
