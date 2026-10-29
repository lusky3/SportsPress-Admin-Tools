<?php
/**
 * Standalone tests for `wp spr audit-name-matches` (SPPR_CLI::audit_name_matches).
 *
 * The audit itself is covered in test-player-matching.php; this pins the command
 * around it: registration, player-ID arguments, output format, and that the
 * summary stays out of machine-readable output.
 *
 * Usage: php test-cli-audit.php
 */

namespace WP_CLI\Utils {
	function format_items( $format, $items, $fields ) {
		$GLOBALS['cli_tables'][] = array(
			'format' => $format,
			'items'  => $items,
			'fields' => $fields,
		);
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	function absint( $value ) {
		return abs( (int) $value );
	}

	$cli_tables   = array();
	$cli_commands = array();

	class WP_CLI {
		public static $log = array();
		public static function log( $message ) {
			self::$log[] = $message;
		}
		public static function success( $message ) {
			self::$log[] = $message;
		}
		public static function warning( $message ) {
			self::$log[] = $message;
		}
		public static function add_command( $name, $callable ) {
			$GLOBALS['cli_commands'][ $name ] = $callable;
		}
	}

	/** Stand-in for the audit: records what it was asked for, returns a canned report. */
	class SPPR_Name_Match_Audit {
		public static $asked_for = null;
		public static $report    = array();
		public function run( $player_ids = array() ) {
			self::$asked_for = $player_ids;
			return self::$report;
		}
	}

	require_once __DIR__ . '/../includes/class-cli.php';

	$passed = 0;
	$failed = 0;
	function assert_test( $condition, $message ) {
		global $passed, $failed;
		if ( $condition ) {
			echo "✓ PASS: $message\n";
			++$passed;
		} else {
			echo "✗ FAIL: $message\n";
			++$failed;
		}
	}
	function reset_cli() {
		global $cli_tables;
		$cli_tables     = array();
		WP_CLI::$log    = array();
		SPPR_Name_Match_Audit::$asked_for = null;
	}

	echo "=== Testing wp spr audit-name-matches ===\n\n";

	assert_test( isset( $cli_commands['spr audit-name-matches'] ), 'The command is registered' );
	$command = $cli_commands['spr audit-name-matches'];

	// Nothing flagged: no table, just the summary.
	reset_cli();
	SPPR_Name_Match_Audit::$report = array(
		'scanned'  => 3,
		'skipped'  => 1,
		'findings' => array(),
	);
	call_user_func( $command, array(), array() );
	assert_test( array() === SPPR_Name_Match_Audit::$asked_for, 'No arguments audits every player' );
	assert_test( array() === $cli_tables, 'Nothing flagged prints no table' );
	assert_test(
		1 === count( WP_CLI::$log ) && false !== strpos( WP_CLI::$log[0], 'Scanned 3 name-only match(es): 0 flagged, 1 skipped' )
			&& false !== strpos( WP_CLI::$log[0], 'Nothing was changed.' ),
		'The table format ends with the summary'
	);

	// Findings as CSV, limited to valid player IDs.
	reset_cli();
	$finding = array(
		'log_id'   => 2,
		'order_id' => 115076,
		'reasons'  => 'linked_to_other_user',
	);
	SPPR_Name_Match_Audit::$report = array(
		'scanned'  => 1,
		'skipped'  => 0,
		'findings' => array( $finding ),
	);
	call_user_func( $command, array( '103431', 'abc', '0' ), array( 'format' => 'csv' ) );
	assert_test( array( 103431 ) === SPPR_Name_Match_Audit::$asked_for, 'Player-ID arguments are passed on; junk and 0 are dropped' );
	assert_test(
		1 === count( $cli_tables ) && 'csv' === $cli_tables[0]['format']
			&& array( $finding ) === $cli_tables[0]['items']
			&& array( 'log_id', 'order_id', 'reasons' ) === $cli_tables[0]['fields'],
		'Findings are printed in the requested format with every column'
	);
	assert_test( array() === WP_CLI::$log, 'Machine-readable output carries no summary line' );

	echo "\n=== Results ===\n";
	echo "Passed: $passed\n";
	echo "Failed: $failed\n";
	exit( $failed > 0 ? 1 : 0 );
}
