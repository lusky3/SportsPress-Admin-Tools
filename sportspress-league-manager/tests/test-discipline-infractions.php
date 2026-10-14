<?php
/**
 * Standalone tests for the infraction list: seed shape and sanitiser clamping.
 */

define( 'ABSPATH', __DIR__ );

function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); } // phpcs:ignore
function sanitize_textarea_field( $v ) { return trim( strip_tags( (string) $v ) ); } // phpcs:ignore
function absint( $v ) { return abs( (int) $v ); }
function __( $t ) { return $t; } // phpcs:ignore

require_once __DIR__ . '/../includes/class-discipline-infraction.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$i = 'SPLM_Discipline_Infraction';

echo "\n=== seed_rows() ===\n\n";
$seed = $i::seed_rows();
assert_test( count( $seed ) >= 7, 'seed covers the §5.10 chart (7+ entries)' );
$by_title = array();
foreach ( $seed as $row ) { $by_title[ $row['title'] ] = $row; }
assert_test( 3 === $by_title['Fighting (first offence)']['default_games'], 'Fighting first offence defaults to 3 games' );
assert_test( 'indefinite' === $by_title['Gross Misconduct']['outcome'], 'Gross Misconduct is indefinite' );
assert_test( 'indefinite' === $by_title['Match Penalty']['outcome'], 'Match Penalty is indefinite' );
assert_test( 2 === $by_title['Major Penalty (first offence)']['default_games'], 'Major first offence defaults to 2 games' );
assert_test( 1 === $by_title['Game Misconduct (2nd or 3rd period)']['default_games'], 'Game Misconduct 2nd/3rd period defaults to 1 game' );
$has_removal = false;
foreach ( $seed as $row ) { if ( false !== stripos( $row['title'], 'second offence' ) || false !== stripos( $row['title'], 'removal' ) ) { $has_removal = true; } }
assert_test( ! $has_removal, 'removal rows are not seeded (spec decision #4)' );
foreach ( $seed as $row ) {
	assert_test( '' !== $row['rule_text'], 'seed row "' . $row['title'] . '" carries rulebook wording' );
}

echo "\n=== sanitize_row() ===\n\n";
$r = $i::sanitize_row( array( 'rule_ref' => '6.11', 'title' => ' Test <b>x</b> ', 'rule_text' => 'Text', 'outcome' => 'games', 'default_games' => '3' ) );
assert_test( 'Test x' === $r['title'], 'title is stripped and trimmed' );
assert_test( 3 === $r['default_games'], 'games cast to int' );
$r = $i::sanitize_row( array( 'title' => 'T', 'outcome' => 'removal', 'default_games' => 5 ) );
assert_test( 'games' === $r['outcome'], 'unknown outcome falls back to games' );
$r = $i::sanitize_row( array( 'title' => 'T', 'outcome' => 'games', 'default_games' => 999 ) );
assert_test( 20 === $r['default_games'], 'games clamped to MAX_GAMES' );
$r = $i::sanitize_row( array( 'title' => 'T', 'outcome' => 'indefinite', 'default_games' => 4 ) );
assert_test( 0 === $r['default_games'], 'indefinite forces games to 0' );
$r = $i::sanitize_row( array( 'title' => str_repeat( 'é', 130 ), 'rule_ref' => str_repeat( '§', 30 ) ) );
assert_test( 120 === mb_strlen( $r['title'] ) && 20 === mb_strlen( $r['rule_ref'] ), 'title and rule_ref truncate by characters, not bytes' );
$r = $i::sanitize_row( null );
assert_test( '' === $r['title'], 'null input yields an empty row, not a fatal' );

echo "\n=== maybe_upgrade gate ===\n\n";
assert_test( '1.0.0' === $i::DB_VERSION && 'splm_discipline_infraction_db_version' === $i::VERSION_OPTION, 'DB_VERSION and option name' );
assert_test( false === $i::needs_upgrade( '1.0.0', true ), 'current version + table present: no upgrade' );
assert_test( true === $i::needs_upgrade( '', true ), 'no stored version: upgrade' );
assert_test( true === $i::needs_upgrade( '0.9', true ), 'older stored version: upgrade' );
assert_test( true === $i::needs_upgrade( '1.0.0', false ), 'table missing despite version: upgrade' );
assert_test( true === $i::needs_upgrade( false, false ), 'unset option (false) and no table: upgrade' );

echo "\n=== seed_plan() / version recording ===\n\n";
assert_test( 'skip-flagged' === $i::seed_plan( true, 0 ), 'flag set: skip, even with no rows' );
assert_test( 'skip-flagged' === $i::seed_plan( true, 5 ), 'flag set with rows: skip' );
assert_test( 'mark-seeded' === $i::seed_plan( false, 3 ), 'rows exist without the flag: mark seeded, never overwrite' );
assert_test( 'insert' === $i::seed_plan( false, 0 ), 'no flag, no rows: insert' );
assert_test( true === $i::should_record_version( true, true ), 'version recorded when table exists and seeded' );
assert_test( false === $i::should_record_version( true, false ), 'version NOT recorded when seeding failed' );
assert_test( false === $i::should_record_version( false, true ), 'version NOT recorded when table missing' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
