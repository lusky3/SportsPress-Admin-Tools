<?php
/**
 * Standalone tests for the manual-suspension service: statuses, row building,
 * duplicate detection, recipients and the history summary line.
 */

define( 'ABSPATH', __DIR__ );

function __( $t ) { return $t; } // phpcs:ignore
function _n( $s, $p, $n ) { return 1 === (int) $n ? $s : $p; } // phpcs:ignore
function absint( $v ) { return abs( (int) $v ); }

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';
require_once __DIR__ . '/../includes/class-discipline-suspension.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$db = 'SPLM_Discipline_Notice_Database';

echo "\n=== statuses ===\n\n";
assert_test( 'revoked' === $db::STATUS_REVOKED, 'STATUS_REVOKED is "revoked"' );
assert_test( in_array( 'revoked', $db::STATUSES, true ), 'revoked is a valid status' );
assert_test( 'manual' === $db::SOURCE_MANUAL && 'auto' === $db::SOURCE_AUTO, 'source constants exist' );
assert_test( '1.1.0' === $db::DB_VERSION, 'DB_VERSION bumped so maybe_upgrade() re-runs dbDelta' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
