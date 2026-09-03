#!/bin/bash
# Run all standalone PHP tests across all plugins
# Usage: ./run-all-tests.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
TOTAL_PASS=0
TOTAL_FAIL=0
FAILED_FILES=()

run_test() {
    local file="$1"
    local rel="${file#$SCRIPT_DIR/}"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "Running: $rel"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    if php "$file"; then
        TOTAL_PASS=$((TOTAL_PASS + 1))
    else
        TOTAL_FAIL=$((TOTAL_FAIL + 1))
        FAILED_FILES+=("$rel")
    fi
    echo ""
}

# Discover and run all standalone test files
run_test "$SCRIPT_DIR/sportspress-admin-tools/tests/test-lock.php"
run_test "$SCRIPT_DIR/sportspress-admin-tools/tests/test-database.php"
run_test "$SCRIPT_DIR/sportspress-admin-tools/tests/test-health-dashboard.php"
run_test "$SCRIPT_DIR/sportspress-admin-tools/tests/test-season-helper.php"
run_test "$SCRIPT_DIR/sportspress-etransfer-automation/tests/test-name-matcher.php"
run_test "$SCRIPT_DIR/sportspress-etransfer-automation/tests/test-etransfer-automation.php"
run_test "$SCRIPT_DIR/sportspress-etransfer-automation/tests/test-webhook-routing.php"
run_test "$SCRIPT_DIR/sportspress-player-registration/tests/test-registration-logic.php"
run_test "$SCRIPT_DIR/sportspress-player-registration/tests/test-player-matching.php"
run_test "$SCRIPT_DIR/sportspress-player-tools/tests/test-batch-list-creator.php"
run_test "$SCRIPT_DIR/sportspress-player-tools/tests/test-player-skill-level.php"
run_test "$SCRIPT_DIR/sportspress-player-tools/tests/test-email-sync.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-matchup-generator.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-exporters.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-constraints.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-validate-cache-backtracking.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-engine-correctness.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-export-and-safety.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-events-import.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-notifications.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-rollover-teams.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-naming.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-standings-pages.php"
run_test "$SCRIPT_DIR/sportspress-events-manager/tests/test-schedule-template.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-league-manager.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-rest-api.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-leaders.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-player-stats-aggregator.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-penalty-watch.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-league-table-rows.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-season-audit.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-time.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-matcher.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-lifecycle.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-claim.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-tieback.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-waitlist-gate.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-consistency-checker.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-sportspress-writer.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-recognition-providers.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-roster-matcher.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-budget.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-settings-secrets.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-cf-access.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-provider-diagnostics.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-sheet-lifecycle.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-rest-ingest.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-ingest-retry-failed.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-dashboard-rest.php"

echo "════════════════════════════════════════"
echo "  ALL TESTS SUMMARY"
echo "════════════════════════════════════════"
echo "  Test suites passed: $TOTAL_PASS"
echo "  Test suites failed: $TOTAL_FAIL"

if [ ${#FAILED_FILES[@]} -gt 0 ]; then
    echo ""
    echo "  Failed suites:"
    for f in "${FAILED_FILES[@]}"; do
        echo "    ✗ $f"
    done
    echo ""
    exit 1
else
    echo ""
    echo "  ✓ All test suites passed!"
    echo ""
    exit 0
fi
