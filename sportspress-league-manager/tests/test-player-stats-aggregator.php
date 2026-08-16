<?php
/**
 * Standalone tests for SPLM_Player_Stats_Aggregator's pure helpers.
 *
 * for_season() scans WordPress and is verified against staging rather than
 * mocked; what is pinned down here is the date and window arithmetic, which is
 * where the bugs actually live — particularly across a calendar-year boundary,
 * exactly where a winter season sits.
 */

define( 'ABSPATH', __DIR__ );

// The helpers under test use WordPress's time constants; the pure code path
// needs no other part of WordPress.
define( 'DAY_IN_SECONDS', 86400 );
define( 'WEEK_IN_SECONDS', 604800 );

require_once __DIR__ . '/../includes/class-player-stats-aggregator.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$agg = 'SPLM_Player_Stats_Aggregator';

echo "\n=== week_key() ===\n\n";

assert_test( '2026-03-16' === $agg::week_key( '2026-03-22 21:00:00' ), 'a Sunday game buckets into the Monday that began its week' );
assert_test( '2026-03-16' === $agg::week_key( '2026-03-16 19:30:00' ), 'a Monday game buckets into itself' );
assert_test(
	$agg::week_key( '2026-03-16 19:30:00' ) === $agg::week_key( '2026-03-22 21:00:00' ),
	'two games in the same week share a bucket'
);
assert_test(
	$agg::week_key( '2026-03-22 21:00:00' ) !== $agg::week_key( '2026-03-23 21:00:00' ),
	'consecutive days either side of Monday fall in different buckets'
);
assert_test( '2025-12-29' === $agg::week_key( '2026-01-01 20:00:00' ), 'a New Year game buckets into the week that started in the previous year' );

echo "\n=== totals() ===\n\n";

$weeks = array(
	'2026-01-05' => array( 'gp' => 1, 'g' => 2, 'a' => 1, 'pim' => 2 ),
	'2026-01-12' => array( 'gp' => 1, 'g' => 0, 'a' => 3, 'pim' => 0 ),
	'2026-02-02' => array( 'gp' => 2, 'g' => 1, 'a' => 0, 'pim' => 5 ),
);

$t = $agg::totals( $weeks );
assert_test( 4 === $t['gp'], 'games played sum across buckets' );
assert_test( 3 === $t['g'] && 4 === $t['a'] && 7 === $t['pim'], 'every stat sums across buckets' );
assert_test(
	array( 'gp' => 0, 'g' => 0, 'a' => 0, 'pim' => 0 ) === $agg::totals( array() ),
	'no buckets totals to zeroes, not an empty array'
);

echo "\n=== window_totals() ===\n\n";

$w = $agg::window_totals( $weeks, '2026-01-12' );
assert_test( 1 === $w['g'] && 3 === $w['a'] && 5 === $w['pim'], 'only buckets on or after the cutoff are counted' );
assert_test( 3 === $w['gp'], 'window games played excludes buckets before the cutoff' );
assert_test( 7 === $agg::window_totals( $weeks, '2026-01-05' )['pim'], 'a cutoff at the first bucket includes everything' );
assert_test( 0 === $agg::window_totals( $weeks, '2026-03-01' )['pim'], 'a cutoff past every bucket counts nothing' );
assert_test(
	$agg::window_totals( $weeks, '2026-01-05' ) === $agg::totals( $weeks ),
	'a window covering the whole season equals the season total'
);

echo "\n=== window_cutoff() ===\n\n";

assert_test(
	'2026-03-02' === $agg::window_cutoff( 4, '2026-03-25', '2025-09-22' ),
	'a 4-week window from a Wednesday starts on the Monday 3 weeks earlier (4 weeks inclusive)'
);
assert_test(
	'2026-03-23' === $agg::window_cutoff( 1, '2026-03-25', '2025-09-22' ),
	'a 1-week window is the current week only'
);
assert_test(
	'2025-12-22' === $agg::window_cutoff( 4, '2026-01-14', '2025-09-22' ),
	'a window reaching back over New Year does not break'
);
assert_test(
	'2025-09-22' === $agg::window_cutoff( 52, '2026-01-14', '2025-09-22' ),
	'a window longer than the season is clamped to the season start, so a new season starts everyone at zero'
);
assert_test(
	'2025-09-22' === $agg::window_cutoff( 4, '2025-09-30', '2025-09-22' ),
	'early in a season the window is clamped rather than reaching into the previous one'
);

echo "\n=== season_start() ===\n\n";

assert_test(
	'2026-01-05' === $agg::season_start( array( array( 'weeks' => $weeks ), array( 'weeks' => array( '2026-02-09' => array() ) ) ) ),
	'season start is the earliest week across every player, not just the first one'
);
assert_test( '' === $agg::season_start( array() ), 'no players yields an empty season start' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
