<?php
/**
 * Standalone tests for SPSS_Roster_Matcher
 *
 * Usage: php test-roster-matcher.php
 *
 * No WordPress and no database required. We define ABSPATH so the real
 * SPSS_Extraction_Result and SPSS_Roster_Matcher files load, then drive the
 * matcher with hand-built result objects and rosters.
 */

// Mock WordPress bootstrap constant guarded by both classes.
define('ABSPATH', dirname(__FILE__) . '/');

// ── Load classes under test (real files, no WP deps beyond ABSPATH) ──────────

require_once dirname(__FILE__) . '/../includes/recognition/class-extraction-result.php';
require_once dirname(__FILE__) . '/../includes/class-roster-matcher.php';

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
 * Build a rosters array from home/away entry lists.
 */
function make_rosters($home, $away) {
    return array('home' => $home, 'away' => $away);
}

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_Roster_Matcher ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// ── players: number, name fallback, and unmatched ────────────────────────────

$rosters = make_rosters(
    array(
        array('player_id' => 1, 'name' => 'Kevin Fox (C)', 'number' => '7'),
        array('player_id' => 2, 'name' => 'Sam Rivera',    'number' => '10'),
    ),
    array(
        array('player_id' => 3, 'name' => 'Alex Doe',      'number' => '9'),
    )
);

$result = new SPSS_Extraction_Result(array(
    'players' => array(
        // Matched by number (model's guess should be overwritten).
        array('team' => 'home', 'jersey_written' => '#7 ', 'player_name' => 'wrong', 'matched_player_id' => 999, 'matched_by' => 'model'),
        // Number blank → matched by name fallback ("Kevin Fox" normalizes near roster).
        array('team' => 'home', 'jersey_written' => null, 'player_name' => 'Sam Rivera', 'matched_player_id' => null, 'matched_by' => 'model'),
        // Neither number nor name resolve → unmatched.
        array('team' => 'away', 'jersey_written' => '88', 'player_name' => 'Nobody', 'matched_player_id' => 777, 'matched_by' => 'model'),
    ),
));
SPSS_Roster_Matcher::match($result, $rosters);
$players = $result->data['players'];

assert_test(
    $players[0]['matched_player_id'] === 1 && $players[0]['matched_by'] === 'roster_number',
    'Player matched by jersey number ("#7 " -> 7 -> player 1), overwriting model'
);
assert_test(
    $players[1]['matched_player_id'] === 2 && $players[1]['matched_by'] === 'roster_name',
    'Player matched by name fallback when jersey is blank'
);
assert_test(
    $players[2]['matched_player_id'] === null && $players[2]['matched_by'] === 'unmatched',
    'Unresolvable jersey/name stays matched_by=unmatched with null id'
);

// ── scoring: scorer + assists resolved from jerseys ──────────────────────────

$result = new SPSS_Extraction_Result(array(
    'scoring' => array(
        array('team' => 'home', 'scorer_jersey' => '7', 'assist1_jersey' => '10', 'assist2_jersey' => null, 'goal_number' => 1, 'period' => 1),
        array('team' => 'away', 'scorer_jersey' => '9', 'assist1_jersey' => '55', 'assist2_jersey' => '', 'goal_number' => 1, 'period' => 2),
    ),
));
SPSS_Roster_Matcher::match($result, $rosters);
$scoring = $result->data['scoring'];

assert_test(
    $scoring[0]['scorer_player_id'] === 1 && $scoring[0]['assist1_player_id'] === 2,
    'Scoring row resolves scorer_player_id and assist1_player_id from jerseys'
);
assert_test(
    $scoring[0]['assist2_player_id'] === null,
    'Blank assist2 jersey resolves to null'
);
assert_test(
    $scoring[1]['scorer_player_id'] === 3 && $scoring[1]['assist1_player_id'] === null,
    'Away scoring resolves to away roster; unknown jersey 55 -> null'
);

// ── penalties: player_id resolved ────────────────────────────────────────────

$result = new SPSS_Extraction_Result(array(
    'penalties' => array(
        array('team' => 'home', 'jersey' => '10', 'length' => 2, 'period' => 1, 'offense' => 'Tripping'),
        array('team' => 'home', 'jersey' => null, 'length' => 2, 'period' => 2, 'offense' => 'Bench'),
    ),
));
SPSS_Roster_Matcher::match($result, $rosters);
$penalties = $result->data['penalties'];

assert_test(
    $penalties[0]['player_id'] === 2,
    'Penalty row resolves player_id from jersey'
);
assert_test(
    $penalties[1]['player_id'] === null,
    'Penalty row with blank jersey resolves player_id to null'
);

// ── pim derivation ───────────────────────────────────────────────────────────

$result = new SPSS_Extraction_Result(array(
    'players' => array(
        // Two penalties (2 + 5) → pim becomes 7 (existing 0 overwritten).
        array('team' => 'home', 'jersey_written' => '7', 'player_name' => 'Kevin Fox (C)', 'pim' => 0),
        // No penalties → prior pim (4) is preserved.
        array('team' => 'home', 'jersey_written' => '10', 'player_name' => 'Sam Rivera', 'pim' => 4),
    ),
    'penalties' => array(
        array('team' => 'home', 'jersey' => '7', 'length' => 2, 'period' => 1, 'offense' => 'Hooking'),
        array('team' => 'home', 'jersey' => '7', 'length' => 5, 'period' => 3, 'offense' => 'Fighting'),
    ),
));
SPSS_Roster_Matcher::match($result, $rosters);
$players = $result->data['players'];

assert_test(
    $players[0]['pim'] === 7,
    'Player with two penalties (2 + 5) gets derived pim=7'
);
assert_test(
    $players[1]['pim'] === 4,
    'Player with no penalties keeps prior pim (4)'
);

// ── team isolation: same number on home vs away ──────────────────────────────

$iso_rosters = make_rosters(
    array(array('player_id' => 100, 'name' => 'Home Guy', 'number' => '12')),
    array(array('player_id' => 200, 'name' => 'Away Guy', 'number' => '12'))
);
$result = new SPSS_Extraction_Result(array(
    'players' => array(
        array('team' => 'home', 'jersey_written' => '12', 'player_name' => 'x'),
        array('team' => 'away', 'jersey_written' => '12', 'player_name' => 'y'),
    ),
));
SPSS_Roster_Matcher::match($result, $iso_rosters);
$players = $result->data['players'];

assert_test(
    $players[0]['matched_player_id'] === 100 && $players[1]['matched_player_id'] === 200,
    'Same jersey #12 resolves to the correct side (home->100, away->200)'
);

// ── missing scoring/penalties keys are tolerated ─────────────────────────────

$result = new SPSS_Extraction_Result(array(
    'players' => array(
        array('team' => 'home', 'jersey_written' => '7', 'player_name' => 'x', 'pim' => 3),
    ),
));
SPSS_Roster_Matcher::match($result, $rosters);
assert_test(
    $result->data['players'][0]['matched_player_id'] === 1
        && $result->data['players'][0]['pim'] === 3,
    'Missing scoring/penalties keys are safe; pim untouched with no penalties'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
