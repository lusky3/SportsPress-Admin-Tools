<?php
/**
 * Standalone tests for SPT_Player_Skill_Level's scoring pipeline.
 *
 * Covers M36 (the skill engine had zero coverage across four behavioural
 * changes) and pins the H28 / M30 / M31 fixes:
 *
 *   - H28 ties        map_scores_to_skills() gives identical raw scores an
 *                     identical skill instead of fanning them across the scale.
 *   - M30 player 0    aggregate_event_players() drops SportsPress's reserved
 *                     team-totals row instead of scoring it as a phantom player.
 *   - M31 goalie data goalies with no recorded goals-against are excluded from
 *                     the pool rather than handed a perfect 0.00 GAA.
 *   - weighting       goals count for more than assists in the skater score.
 *   - min games       players below the threshold are skipped and counted.
 *
 * Usage: php test-player-skill-level.php
 */

// Mock WordPress.
define( 'ABSPATH', dirname( __FILE__ ) . '/' );

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {}
}
if ( ! function_exists( 'apply_filters' ) ) {
	// Pass-through: the production defaults (goal ×2, assist ×0.5) are what we
	// are asserting against.
	function apply_filters( $tag, $value ) {
		return $value;
	}
}

require_once dirname( __FILE__ ) . '/../includes/class-player-skill-level.php';

// Test helpers.
$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
	} else {
		echo "✗ FAIL: $message\n";
		$failed++;
	}
}

/**
 * The pre-H28 mapper, kept verbatim so the tie tests below demonstrate an actual
 * regression guard rather than just asserting the new behaviour.
 *
 * @param array $pool Map of player ID => raw score.
 * @return array Map of player ID => skill level.
 */
function legacy_map_scores_to_skills( $pool ) {
	$skill_of = array();
	arsort( $pool );
	$n    = count( $pool );
	$rank = 0;
	foreach ( $pool as $pid => $raw ) {
		++$rank;
		$percentile       = $n > 1 ? ( $n - $rank ) / ( $n - 1 ) : 0.5;
		$skill_of[ $pid ] = max( 1, min( 10, (int) round( $percentile * 9 ) + 1 ) );
	}
	return $skill_of;
}

/**
 * Build one team's sp_players row set.
 *
 * @param array $rows player_id => stat array.
 * @return array
 */
function box_score( $rows ) {
	return array( 101 => $rows );
}

echo "=== H28: tie handling in map_scores_to_skills() ===\n\n";

// Table-driven: label => array( pool, expectations ).
$tie_cases = array(
	'three-way tie in the middle of a 6-player pool' => array(
		'pool'  => array( 1 => 3.0, 2 => 1.0, 3 => 1.0, 4 => 1.0, 5 => 0.5, 6 => 0.0 ),
		'tied'  => array( 2, 3, 4 ),
		'exact' => array( 1 => 10, 2 => 6, 3 => 6, 4 => 6, 5 => 3, 6 => 1 ),
	),
	'half the pool tied at the bottom (all pointless skaters)' => array(
		'pool'  => array( 1 => 2.0, 2 => 1.5, 3 => 0.0, 4 => 0.0, 5 => 0.0, 6 => 0.0 ),
		'tied'  => array( 3, 4, 5, 6 ),
		// Midrank of positions 3..6 is 4.5 → percentile 0.30 → skill 4.
		'exact' => array( 1 => 10, 2 => 8, 3 => 4, 4 => 4, 5 => 4, 6 => 4 ),
	),
	'every score identical (untracked stat / all goalies at 0 GAA)' => array(
		'pool'  => array( 1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0, 5 => 0.0 ),
		'tied'  => array( 1, 2, 3, 4, 5 ),
		'exact' => array( 1 => 6, 2 => 6, 3 => 6, 4 => 6, 5 => 6 ),
	),
	'tie at the very top' => array(
		'pool'  => array( 1 => 4.0, 2 => 4.0, 3 => 1.0, 4 => 0.0 ),
		'tied'  => array( 1, 2 ),
		'exact' => array( 1 => 9, 2 => 9, 3 => 4, 4 => 1 ),
	),
);

foreach ( $tie_cases as $label => $case ) {
	$result = SPT_Player_Skill_Level::map_scores_to_skills( $case['pool'] );

	// 1. Every tied player gets the same skill.
	$tied_skills = array();
	foreach ( $case['tied'] as $pid ) {
		$tied_skills[] = $result[ $pid ];
	}
	assert_test(
		count( array_unique( $tied_skills ) ) === 1,
		"Tied players share one skill — $label (got " . implode( ',', $tied_skills ) . ')'
	);

	// 2. The exact mapping is pinned so the boundary rule can't drift silently.
	ksort( $result );
	assert_test(
		$result === $case['exact'],
		"Exact skill mapping — $label (got " . json_encode( $result ) . ')'
	);

	// 3. Regression guard: the OLD mapper fanned the tied group out.
	$legacy       = legacy_map_scores_to_skills( $case['pool'] );
	$legacy_tied  = array();
	foreach ( $case['tied'] as $pid ) {
		$legacy_tied[] = $legacy[ $pid ];
	}
	assert_test(
		count( array_unique( $legacy_tied ) ) > 1,
		"Legacy mapper DID fan the tied group across skills — $label (got " . implode( ',', $legacy_tied ) . ')'
	);
}

// Strictly ordered pools must be unchanged by the fix.
$ordered = array( 1 => 5.0, 2 => 3.0, 3 => 1.0 );
assert_test(
	SPT_Player_Skill_Level::map_scores_to_skills( $ordered ) === legacy_map_scores_to_skills( $ordered ),
	'Strictly ordered pool maps identically to the legacy mapper (no unintended shift)'
);

$single = array( 7 => 1.25 );
assert_test(
	SPT_Player_Skill_Level::map_scores_to_skills( $single ) === array( 7 => 6 ),
	'Single-entry pool maps to the mid-scale value 6'
);

assert_test(
	SPT_Player_Skill_Level::map_scores_to_skills( array() ) === array(),
	'Empty pool returns an empty map'
);

// Float scores that are equal but computed differently still tie.
$float_pool = array( 1 => 1 / 3, 2 => 2 / 6, 3 => 0.0 );
$float_res  = SPT_Player_Skill_Level::map_scores_to_skills( $float_pool );
assert_test(
	$float_res[1] === $float_res[2],
	'Float scores equal within tolerance are treated as a tie'
);

echo "\n=== M30: SportsPress team-totals row (player key 0) ===\n\n";

$agg = SPT_Player_Skill_Level::aggregate_event_players(
	box_score(
		array(
			0  => array( 'g' => 9, 'a' => 12, 'pim' => 6 ), // Team totals row.
			11 => array( 'g' => 2, 'a' => 1, 'pim' => 0 ),
			12 => array( 'g' => 1, 'a' => 3, 'pim' => 2 ),
		)
	)
);

assert_test( ! isset( $agg[0] ), 'Player key 0 (team totals) is excluded from the aggregate' );
assert_test( array_keys( $agg ) === array( 11, 12 ), 'Only real player IDs enter the aggregate' );

// String keys and negative keys are the same class of junk.
$agg_str = SPT_Player_Skill_Level::aggregate_event_players(
	box_score(
		array(
			'0' => array( 'g' => 9, 'a' => 12 ),
			'-3' => array( 'g' => 5 ),
			'11' => array( 'g' => 2 ),
		)
	)
);
assert_test( array_keys( $agg_str ) === array( 11 ), 'String "0" and negative player keys are excluded too' );

// The end-to-end effect: without the guard, player 0 outscores everyone and
// pushes every real player down a rung.
$with_phantom = array(
	0  => array( 'gp' => 3, 'g' => 9, 'a' => 12, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
	11 => array( 'gp' => 3, 'g' => 2, 'a' => 1, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
	12 => array( 'gp' => 3, 'g' => 1, 'a' => 3, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
);
$phantom_pools = SPT_Player_Skill_Level::build_score_pools( $with_phantom, 1, array() );
$phantom_skill = SPT_Player_Skill_Level::map_scores_to_skills( $phantom_pools['skaters'] );
$clean_pools   = SPT_Player_Skill_Level::build_score_pools(
	array_diff_key( $with_phantom, array( 0 => 1 ) ),
	1,
	array()
);
$clean_skill = SPT_Player_Skill_Level::map_scores_to_skills( $clean_pools['skaters'] );
assert_test(
	$phantom_skill[11] < $clean_skill[11],
	'A phantom player-0 row demonstrably depresses a real player\'s skill (' . $phantom_skill[11] . ' vs ' . $clean_skill[11] . ')'
);

echo "\n=== M31: goalies with and without goals-against data ===\n\n";

// has_ga tracking straight out of the aggregator.
$ga_cases = array(
	'ga recorded as a positive number' => array( array( 'ga' => 3 ), true ),
	'ga recorded as an explicit 0 (genuine shutout)' => array( array( 'ga' => 0 ), true ),
	'ga recorded as the string "0"' => array( array( 'ga' => '0' ), true ),
	'ga key absent entirely (stat not tracked)' => array( array( 'g' => 0 ), false ),
	'ga present but blank' => array( array( 'ga' => '' ), false ),
	'ga present but null' => array( array( 'ga' => null ), false ),
);
foreach ( $ga_cases as $label => $case ) {
	$one = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 21 => $case[0] ) ) );
	assert_test( $one[21]['has_ga'] === $case[1], "has_ga flag — $label" );
}

// A single recorded ga anywhere in scope counts as "tracked".
$mixed = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 21 => array( 'g' => 0 ) ) ) );
$mixed = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 21 => array( 'ga' => 2 ) ) ), $mixed );
assert_test( true === $mixed[21]['has_ga'], 'has_ga latches once any event records a ga value' );
assert_test( 2 === $mixed[21]['gp'], 'Games played accumulates across events' );

// Pool construction: no-data goalies leave the pool and are reported.
$goalie_agg = array(
	31 => array( 'gp' => 10, 'g' => 0, 'a' => 0, 'pim' => 0, 'ga' => 20, 'has_ga' => true ),  // 2.00 GAA
	32 => array( 'gp' => 10, 'g' => 0, 'a' => 0, 'pim' => 0, 'ga' => 40, 'has_ga' => true ),  // 4.00 GAA
	33 => array( 'gp' => 10, 'g' => 0, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => false ), // untracked
	34 => array( 'gp' => 10, 'g' => 0, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => true ),  // real shutout season
);
$goalie_ids = array( 31 => true, 32 => true, 33 => true, 34 => true );
$pools      = SPT_Player_Skill_Level::build_score_pools( $goalie_agg, 3, $goalie_ids );

assert_test( ! isset( $pools['goalies'][33] ), 'Goalie with no ga data is excluded from the pool' );
assert_test( 1 === $pools['skipped_no_data'], 'No-data goalie is counted in skipped_no_data' );
assert_test( isset( $pools['goalies'][34] ), 'Goalie with a genuine 0 GAA is still scored' );
assert_test( count( $pools['goalies'] ) === 3, 'Remaining goalies stay in the pool' );
assert_test( empty( $pools['skaters'] ), 'Goalies do not leak into the skater pool' );

$goalie_skill = SPT_Player_Skill_Level::map_scores_to_skills( $pools['goalies'] );
assert_test( $goalie_skill[34] > $goalie_skill[31], 'Lower GAA ranks higher (0.00 beats 2.00)' );
assert_test( $goalie_skill[31] > $goalie_skill[32], 'Lower GAA ranks higher (2.00 beats 4.00)' );

// Before the fix the untracked goalie would have tied the shutout goalie for best.
$unfixed = SPT_Player_Skill_Level::map_scores_to_skills(
	array( 31 => -2.0, 32 => -4.0, 33 => -0.0, 34 => -0.0 )
);
assert_test(
	$unfixed[33] === max( $unfixed ),
	'Regression guard: scoring a no-data goalie as 0.00 GAA would rank them best'
);

echo "\n=== Goal / assist weighting ===\n\n";

$weight_agg = array(
	41 => array( 'gp' => 4, 'g' => 4, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => false ), // 4 goals
	42 => array( 'gp' => 4, 'g' => 0, 'a' => 4, 'pim' => 0, 'ga' => 0, 'has_ga' => false ), // 4 assists
	43 => array( 'gp' => 4, 'g' => 2, 'a' => 2, 'pim' => 0, 'ga' => 0, 'has_ga' => false ), // split
);
$weight_pools = SPT_Player_Skill_Level::build_score_pools( $weight_agg, 1, array() );

assert_test(
	abs( $weight_pools['skaters'][41] - 2.0 ) < 1e-9,
	'Skater score is (2*g + 0.5*a) / gp — 4 goals in 4 games = 2.00'
);
assert_test(
	abs( $weight_pools['skaters'][42] - 0.5 ) < 1e-9,
	'4 assists in 4 games = 0.50'
);
assert_test(
	abs( $weight_pools['skaters'][43] - 1.25 ) < 1e-9,
	'2 goals + 2 assists in 4 games = 1.25'
);
assert_test(
	$weight_pools['skaters'][41] > $weight_pools['skaters'][43]
		&& $weight_pools['skaters'][43] > $weight_pools['skaters'][42],
	'Goals outweigh assists at equal point totals'
);

echo "\n=== Minimum games threshold ===\n\n";

$gp_agg = array(
	51 => array( 'gp' => 1, 'g' => 5, 'a' => 5, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
	52 => array( 'gp' => 2, 'g' => 1, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
	53 => array( 'gp' => 3, 'g' => 1, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
	54 => array( 'gp' => 9, 'g' => 1, 'a' => 0, 'pim' => 0, 'ga' => 0, 'has_ga' => false ),
);
$gp_pools = SPT_Player_Skill_Level::build_score_pools( $gp_agg, 3, array() );

assert_test( 2 === $gp_pools['skipped_low_gp'], 'Players below min_games are counted as skipped_low_gp' );
assert_test( array_keys( $gp_pools['skaters'] ) === array( 53, 54 ), 'Only players at or above min_games are scored' );
assert_test(
	! isset( $gp_pools['skaters'][51] ),
	'A one-game hot streak cannot enter the pool at min_games = 3'
);

// min_games is floored at 1 so a 0/negative threshold can't divide by zero.
$floor_pools = SPT_Player_Skill_Level::build_score_pools( $gp_agg, 0, array() );
assert_test( 0 === $floor_pools['skipped_low_gp'], 'min_games below 1 is treated as 1' );
assert_test( count( $floor_pools['skaters'] ) === 4, 'All players scored at min_games = 1' );

echo "\n=== Aggregation robustness ===\n\n";

assert_test(
	SPT_Player_Skill_Level::aggregate_event_players( '' ) === array(),
	'Non-array sp_players meta yields an empty aggregate'
);
assert_test(
	SPT_Player_Skill_Level::aggregate_event_players( array( 101 => 'garbage' ) ) === array(),
	'Non-array team row is skipped'
);
$stat_junk = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 61 => 'nope', 62 => array( 'g' => 1 ) ) ) );
assert_test( array_keys( $stat_junk ) === array( 62 ), 'Non-array stat row is skipped' );

$totals = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 71 => array( 'g' => 1, 'a' => 2, 'pim' => 4, 'ga' => 1 ) ) ) );
$totals = SPT_Player_Skill_Level::aggregate_event_players( box_score( array( 71 => array( 'g' => 2, 'a' => 0, 'pim' => 2, 'ga' => 3 ) ) ), $totals );
assert_test(
	3 === $totals[71]['g'] && 2 === $totals[71]['a'] && 6 === $totals[71]['pim'] && 4 === $totals[71]['ga'] && 2 === $totals[71]['gp'],
	'Stats accumulate correctly across events'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
