<?php
/**
 * Standalone tests for SPSS_Consistency_Checker
 *
 * Usage: php test-consistency-checker.php
 *
 * No WordPress and no database required. We define ABSPATH so the real
 * SPSS_Extraction_Result and SPSS_Consistency_Checker files load, then drive
 * the checker with hand-built result objects.
 */

// Mock WordPress bootstrap constant guarded by both classes.
define('ABSPATH', dirname(__FILE__) . '/');

// ── Load classes under test (real files, no WP deps beyond ABSPATH) ──────────

require_once dirname(__FILE__) . '/../includes/recognition/class-extraction-result.php';
require_once dirname(__FILE__) . '/../includes/class-consistency-checker.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test($condition, $message) {
    global $passed, $failed;
    if ($condition) {
        echo "✓ PASS: $message\n";
        $passed++;
    } else {
        echo "✗ FAIL: $message\n";
        $failed++;
    }
}

/**
 * Count flags of a given type present on a result.
 */
function count_flags($result, $type) {
    $n = 0;
    foreach ($result->flags as $flag) {
        if ($flag['type'] === $type) {
            $n++;
        }
    }
    return $n;
}

/**
 * Build a player row with sane defaults; override via $overrides.
 */
function make_player($overrides = array()) {
    return array_merge(array(
        'team'              => 'home',
        'jersey_written'    => '10',
        'matched_player_id' => 42,
        'matched_by'        => 'jersey',
        'goals'             => 0,
        'assists'           => 0,
        'pim'               => 0,
        'field_confidence'  => array(),
    ), $overrides);
}

/**
 * Build an extraction result from final scores + player rows.
 */
function make_result($home_final, $away_final, $players) {
    return new SPSS_Extraction_Result(array(
        'sheet_meta' => array(),
        'teams'      => array(
            'home' => array('final_score' => $home_final),
            'away' => array('final_score' => $away_final),
        ),
        'periods'    => array(),
        'players'    => $players,
        'goalies'    => array(),
    ));
}

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_Consistency_Checker ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// ── Clean sheet → zero flags ─────────────────────────────────────────────────

$clean = make_result(3, 1, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => 2)),
    make_player(array('team' => 'home', 'jersey_written' => '11', 'matched_player_id' => 2, 'goals' => 1)),
    make_player(array('team' => 'away', 'jersey_written' => '7',  'matched_player_id' => 3, 'goals' => 1)),
));
$added = SPSS_Consistency_Checker::check($clean);

assert_test(
    count($added) === 0 && count($clean->flags) === 0,
    'Clean sheet produces zero flags'
);
assert_test(
    is_array($added),
    'check() returns an array of added flags'
);

// ── Score mismatch ───────────────────────────────────────────────────────────

$mismatch = make_result(3, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => 2)),
    make_player(array('team' => 'home', 'jersey_written' => '11', 'matched_player_id' => 2, 'goals' => 2)),
));
$added = SPSS_Consistency_Checker::check($mismatch);

assert_test(
    count_flags($mismatch, 'score_mismatch') === 1,
    'Goals-sum vs final mismatch flags score_mismatch (home: sum 4 vs final 3)'
);

// A side with a null player goal must NOT raise a score_mismatch (unknown sum).
$partial = make_result(3, null, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => null,
        'field_confidence' => array('goals' => 0.9))),
    make_player(array('team' => 'home', 'jersey_written' => '11', 'matched_player_id' => 2, 'goals' => 1)),
));
$added = SPSS_Consistency_Checker::check($partial);
assert_test(
    count_flags($partial, 'score_mismatch') === 0,
    'Null player goal suppresses score_mismatch on that side'
);

// ── Unmatched jersey ─────────────────────────────────────────────────────────

$unmatched = make_result(1, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => '99', 'matched_player_id' => null,
        'matched_by' => 'unmatched', 'goals' => 1)),
    make_player(array('team' => 'away', 'jersey_written' => '7', 'matched_player_id' => 5, 'goals' => 0)),
));
$added = SPSS_Consistency_Checker::check($unmatched);

assert_test(
    count_flags($unmatched, 'unmatched_jersey') === 1,
    'Unmatched player row flags unmatched_jersey'
);
$unmatched_flag = null;
foreach ($unmatched->flags as $f) {
    if ($f['type'] === 'unmatched_jersey') {
        $unmatched_flag = $f;
    }
}
assert_test(
    $unmatched_flag !== null && $unmatched_flag['player_index'] === 0,
    'unmatched_jersey flag carries the player_index'
);

// ── Duplicate jersey ─────────────────────────────────────────────────────────

$dupes = make_result(0, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => 0)),
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 2, 'goals' => 0)),
    // Same number on the OTHER team is fine.
    make_player(array('team' => 'away', 'jersey_written' => '10', 'matched_player_id' => 3, 'goals' => 0)),
));
$added = SPSS_Consistency_Checker::check($dupes);

assert_test(
    count_flags($dupes, 'duplicate_jersey') === 1,
    'Same jersey twice on one team flags duplicate_jersey once'
);

// ── Illegible / missing ──────────────────────────────────────────────────────

$illegible = make_result(0, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => null, 'matched_player_id' => 1, 'goals' => 0)),
));
$added = SPSS_Consistency_Checker::check($illegible);

assert_test(
    count_flags($illegible, 'illegible') === 1,
    'Missing jersey number flags illegible'
);

// Missing goals with low confidence → illegible.
$low_conf = make_result(0, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => null,
        'field_confidence' => array('goals' => 0.2))),
));
$added = SPSS_Consistency_Checker::check($low_conf);
assert_test(
    count_flags($low_conf, 'illegible') === 1,
    'Missing goals with low confidence flags illegible'
);

// Missing goals with HIGH confidence → no illegible flag (conservative).
$high_conf = make_result(0, null, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1, 'goals' => null,
        'field_confidence' => array('goals' => 0.95))),
));
$added = SPSS_Consistency_Checker::check($high_conf);
assert_test(
    count_flags($high_conf, 'illegible') === 0,
    'Missing goals with high confidence does not flag illegible'
);

// ── Out of range ─────────────────────────────────────────────────────────────

$range = make_result(0, 0, array(
    make_player(array('team' => 'home', 'jersey_written' => '10', 'matched_player_id' => 1,
        'goals' => 99, 'assists' => 0, 'pim' => 0)),
    make_player(array('team' => 'away', 'jersey_written' => '7', 'matched_player_id' => 2,
        'goals' => 0, 'assists' => -3, 'pim' => 0)),
));
$added = SPSS_Consistency_Checker::check($range);

assert_test(
    count_flags($range, 'out_of_range') === 2,
    'Out-of-range goals (99) and negative assists (-3) each flag out_of_range'
);
$range_flag = null;
foreach ($range->flags as $f) {
    if ($f['type'] === 'out_of_range') {
        $range_flag = $f;
        break;
    }
}
assert_test(
    $range_flag !== null && $range_flag['player_index'] === 0,
    'out_of_range flag carries the player_index'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
