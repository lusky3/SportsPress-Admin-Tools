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
run_test "$SCRIPT_DIR/sportspress-etransfer-automation/tests/test-name-matcher.php"
run_test "$SCRIPT_DIR/sportspress-etransfer-automation/tests/test-etransfer-automation.php"
run_test "$SCRIPT_DIR/sportspress-player-registration/tests/test-registration-logic.php"
run_test "$SCRIPT_DIR/sportspress-player-tools/tests/test-batch-list-creator.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-matchup-generator.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-exporters.php"
run_test "$SCRIPT_DIR/sportspress-schedule-generator/tests/test-constraints.php"
run_test "$SCRIPT_DIR/sportspress-league-manager/tests/test-league-manager.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-consistency-checker.php"
run_test "$SCRIPT_DIR/sportspress-score-sheets/tests/test-sportspress-writer.php"

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
