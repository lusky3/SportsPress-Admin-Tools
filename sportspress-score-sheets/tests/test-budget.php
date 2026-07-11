<?php
/**
 * Standalone tests for SPSS_Budget
 *
 * Usage: php test-budget.php
 *
 * No WordPress and no database required. We provide an in-memory option store
 * (get_option / update_option backed by a static array), define ABSPATH so the
 * real class-budget.php loads, then drive the budget API directly. gmdate is
 * the real PHP function so the current UTC month key is genuine.
 */

// ── In-memory WordPress option store ─────────────────────────────────────────

$GLOBALS['spss_test_options'] = array();

function get_option($name, $default = false) {
    if (array_key_exists($name, $GLOBALS['spss_test_options'])) {
        return $GLOBALS['spss_test_options'][$name];
    }
    return $default;
}

function update_option($name, $value) {
    $GLOBALS['spss_test_options'][$name] = $value;
    return true;
}

/** Reset the option store between test groups. */
function reset_options() {
    $GLOBALS['spss_test_options'] = array();
}

// ── Load class under test (real file, no WP deps beyond ABSPATH) ─────────────

define('ABSPATH', dirname(__FILE__) . '/');
require_once dirname(__FILE__) . '/../includes/class-budget.php';

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

/** A stub recognition provider that advertises a per-sheet cost. */
class SPSS_Test_Provider {
    private $cost;
    public function __construct($cost) {
        $this->cost = $cost;
    }
    public function estimated_cost_per_sheet() {
        return $this->cost;
    }
}

$this_month = gmdate('Y-m');

// ═══════════════════════════════════════════════════════════════════════════
echo "=== Testing SPSS_Budget ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// ── cost_per_sheet resolution order ──────────────────────────────────────────

reset_options();
update_option('spss_openai_cost_per_sheet', 0.42);
$provider = new SPSS_Test_Provider(0.99);
assert_test(
    SPSS_Budget::cost_per_sheet('openai', $provider) === 0.42,
    'cost_per_sheet: numeric option override wins over provider estimate'
);

reset_options();
$provider = new SPSS_Test_Provider(0.75);
assert_test(
    SPSS_Budget::cost_per_sheet('openai', $provider) === 0.75,
    'cost_per_sheet: falls back to provider->estimated_cost_per_sheet()'
);

reset_options();
assert_test(
    SPSS_Budget::cost_per_sheet('openai', null) === 0.0,
    'cost_per_sheet: falls back to 0.0 with neither option nor provider'
);

reset_options();
update_option('spss_openai_cost_per_sheet', -5.0);
assert_test(
    SPSS_Budget::cost_per_sheet('openai', null) === 0.0,
    'cost_per_sheet: negative option is clamped to 0'
);

// ── monthly_budget ───────────────────────────────────────────────────────────

reset_options();
assert_test(
    SPSS_Budget::monthly_budget('openai') === 0.0,
    'monthly_budget: unset = 0 (unlimited)'
);
update_option('spss_openai_monthly_budget', 0);
assert_test(
    SPSS_Budget::monthly_budget('openai') === 0.0,
    'monthly_budget: explicit 0 = unlimited'
);
update_option('spss_openai_monthly_budget', 12.5);
assert_test(
    SPSS_Budget::monthly_budget('openai') === 12.5,
    'monthly_budget: reads configured cap'
);

// ── can_spend: unlimited provider always true ────────────────────────────────

reset_options();
update_option('spss_openai_cost_per_sheet', 100.0); // large cost, no cap
assert_test(
    SPSS_Budget::can_spend('openai') === true,
    'can_spend: unlimited provider (no cap) is always true'
);

// ── can_spend: cap 1.00, cost 0.30 → allow 3, deny the 4th ───────────────────

reset_options();
update_option('spss_openai_monthly_budget', 1.00);
update_option('spss_openai_cost_per_sheet', 0.30);

assert_test(SPSS_Budget::can_spend('openai') === true, 'can_spend: 1st call allowed (0.00 + 0.30 <= 1.00)');
SPSS_Budget::record('openai');
assert_test(SPSS_Budget::can_spend('openai') === true, 'can_spend: 2nd call allowed (0.30 + 0.30 <= 1.00)');
SPSS_Budget::record('openai');
assert_test(SPSS_Budget::can_spend('openai') === true, 'can_spend: 3rd call allowed (0.60 + 0.30 <= 1.00)');
SPSS_Budget::record('openai');
assert_test(SPSS_Budget::can_spend('openai') === false, 'can_spend: 4th call denied (0.90 + 0.30 > 1.00)');

// ── can_spend: exactly-at-cap is allowed (<=) ────────────────────────────────

reset_options();
update_option('spss_openai_monthly_budget', 1.00);
update_option('spss_openai_cost_per_sheet', 0.50);
SPSS_Budget::record('openai'); // spent 0.50
assert_test(
    SPSS_Budget::can_spend('openai') === true,
    'can_spend: exactly-at-cap allowed (0.50 + 0.50 == 1.00)'
);

// ── record then spent_this_month / remaining ─────────────────────────────────

reset_options();
update_option('spss_openai_monthly_budget', 5.00);
update_option('spss_openai_cost_per_sheet', 1.25);
SPSS_Budget::record('openai');
SPSS_Budget::record('openai');
assert_test(
    SPSS_Budget::spent_this_month('openai') === 2.50,
    'spent_this_month: reflects the sum of recorded costs (2 x 1.25 = 2.50)'
);
assert_test(
    SPSS_Budget::remaining('openai') === 2.50,
    'remaining: decreases as spend is recorded (5.00 - 2.50 = 2.50)'
);
assert_test(
    SPSS_Budget::remaining('gemini') === INF,
    'remaining: INF for an unlimited (unconfigured) provider'
);
assert_test(
    SPSS_Budget::spent_this_month('gemini') === 0.0,
    'spent_this_month: 0.0 for a provider with no ledger entry'
);

// ── ledger prunes prior months ───────────────────────────────────────────────

reset_options();
update_option('spss_openai_cost_per_sheet', 0.10);
// Seed the ledger with an old month and a current-month entry.
update_option(SPSS_Budget::LEDGER_OPTION, array(
    '2020-01'   => array('openai' => 99.0, 'gemini' => 4.0),
    $this_month => array('openai' => 0.10),
));
SPSS_Budget::record('openai');
$ledger = get_option(SPSS_Budget::LEDGER_OPTION);
assert_test(
    !isset($ledger['2020-01']),
    'record: prunes prior month keys from the ledger'
);
assert_test(
    isset($ledger[$this_month]) && $ledger[$this_month]['openai'] === 0.20,
    'record: current month survives and accumulates (0.10 + 0.10 = 0.20)'
);
assert_test(
    count($ledger) === 1,
    'record: ledger holds only the current month key after pruning'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
