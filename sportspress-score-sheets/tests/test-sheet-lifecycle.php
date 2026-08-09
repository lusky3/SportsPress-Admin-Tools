<?php
/**
 * Standalone tests for score-sheet lifecycle guards (H8: sheets stranded in
 * `processing` forever after a hard-killed recognition worker).
 *
 * Usage: php test-sheet-lifecycle.php
 *
 * No WordPress, no database. SPSS_Database::is_stale_processing() is a pure
 * predicate over a row object, so the real class file loads with only ABSPATH
 * and __() defined; every other method touches $wpdb and is never called here.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) { return $text; }
}

require_once dirname( __FILE__ ) . '/../includes/class-database.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

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

/** Build a sheet row. Timestamps are UTC 'Y-m-d H:i:s', as stored. */
function make_row( $status, $processing_started_offset = null, $created_offset = -60 ) {
	return (object) array(
		'id'                    => 1,
		'status'                => $status,
		'created_at'            => gmdate( 'Y-m-d H:i:s', time() + $created_offset ),
		'processing_started_at' => ( null === $processing_started_offset )
			? null
			: gmdate( 'Y-m-d H:i:s', time() + $processing_started_offset ),
	);
}

$stale = SPSS_Database::STALE_PROCESSING_SECONDS;

// ═══════════════════════════════════════════════════════════════════════════
echo "=== H8: SPSS_Database::is_stale_processing() ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

assert_test(
	$stale > 600,
	'the staleness threshold sits above the recognition chain wall-time budget'
);

// A worker that just claimed the sheet is alive — never sweep it.
assert_test(
	false === SPSS_Database::is_stale_processing( make_row( 'processing', -5 ) ),
	'a just-claimed processing row is not stale'
);

// Still inside the threshold (a slow but plausible chain).
assert_test(
	false === SPSS_Database::is_stale_processing( make_row( 'processing', -( $stale - 120 ) ) ),
	'a long-running but in-budget processing row is not stale'
);

// Past the threshold — the worker cannot still be running.
assert_test(
	true === SPSS_Database::is_stale_processing( make_row( 'processing', -( $stale + 60 ) ) ),
	'a processing row claimed past the threshold IS stale'
);

// Only `processing` is ever swept.
foreach ( array( 'queued', 'pending_review', 'confirmed', 'failed', 'duplicate' ) as $status ) {
	assert_test(
		false === SPSS_Database::is_stale_processing( make_row( $status, -( $stale + 600 ) ) ),
		sprintf( 'status "%s" is never reported stale, however old', $status )
	);
}

// Rows claimed before the processing_started_at column existed fall back to
// created_at, so a pre-upgrade stranded row is still recoverable.
assert_test(
	true === SPSS_Database::is_stale_processing( make_row( 'processing', null, -( $stale + 600 ) ) ),
	'a legacy row with no processing_started_at falls back to created_at'
);
assert_test(
	false === SPSS_Database::is_stale_processing( make_row( 'processing', null, -60 ) ),
	'a legacy row created a minute ago is not stale'
);

// A freshly re-queued sheet has an old created_at but a new claim; the claim
// timestamp must win, or reprocessing would immediately look stale again.
assert_test(
	false === SPSS_Database::is_stale_processing( make_row( 'processing', -30, -( $stale * 10 ) ) ),
	'a re-queued sheet is judged by its claim time, not its original created_at'
);

// Garbage in, no crash and no false positive.
assert_test(
	false === SPSS_Database::is_stale_processing( null ),
	'a missing row is not stale'
);
assert_test(
	false === SPSS_Database::is_stale_processing( (object) array( 'status' => 'processing' ) ),
	'a row with no timestamps at all is not stale (never guess)'
);

// The threshold argument is honoured, with a floor so a caller cannot pass 0 and
// sweep live workers.
assert_test(
	true === SPSS_Database::is_stale_processing( make_row( 'processing', -300 ), 120 ),
	'an explicit shorter threshold is honoured'
);
assert_test(
	false === SPSS_Database::is_stale_processing( make_row( 'processing', -5 ), 0 ),
	'a 0 threshold is floored, so a just-claimed row still survives'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
