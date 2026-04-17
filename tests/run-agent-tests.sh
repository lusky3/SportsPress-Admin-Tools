#!/usr/bin/env bash
# run-agent-tests.sh — Full test lifecycle for agent-driven testing.
# Manages: environment startup, smoke tests, unit tests, integration tests, teardown.
#
# Usage:
#   ./tests/run-agent-tests.sh              # Run all tests (start env if needed)
#   ./tests/run-agent-tests.sh unit         # Run only standalone unit tests
#   ./tests/run-agent-tests.sh integration  # Run only WP integration tests
#   ./tests/run-agent-tests.sh smoke        # Run only smoke/health tests
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
SANDBOX_DIR="$(cd "$REPO_DIR/../sportspress-sandbox" 2>/dev/null && pwd)" || true
CONTAINER="sportspress-test"
BASE_URL="http://localhost:8082"
SUITE="${1:-all}"

# Colors (disabled if not a terminal)
if [ -t 1 ]; then
	GREEN='\033[0;32m'; RED='\033[0;31m'; YELLOW='\033[1;33m'; NC='\033[0m'
else
	GREEN=''; RED=''; YELLOW=''; NC=''
fi

info()  { echo -e "${GREEN}[INFO]${NC} $*"; }
warn()  { echo -e "${YELLOW}[WARN]${NC} $*"; }
fail()  { echo -e "${RED}[FAIL]${NC} $*"; }

unit_failed=0
integration_failed=0
smoke_failed=0

# ── Environment Management ──────────────────────────────────────────

ensure_environment() {
	if docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
		info "Container '$CONTAINER' is already running."
		return 0
	fi

	if [ -z "$SANDBOX_DIR" ] || [ ! -f "$SANDBOX_DIR/compose.yml" ]; then
		fail "sportspress-sandbox not found at $REPO_DIR/../sportspress-sandbox"
		fail "Clone it: git clone https://github.com/lusky3/sportspress-sandbox.git"
		exit 1
	fi

	info "Starting test environment..."
	docker compose -f "$SANDBOX_DIR/compose.yml" up -d --build --wait 2>&1 || true

	info "Waiting for WordPress to be ready..."
	for i in $(seq 1 60); do
		if curl -sf "$BASE_URL/wp-json/test/v1/health" >/dev/null 2>&1; then
			info "WordPress ready after ${i}s."
			return 0
		fi
		sleep 2
	done
	fail "WordPress did not become ready within 120s."
	exit 1
}

reset_state() {
	info "Resetting database to baseline..."
	docker exec "$CONTAINER" wp db import /tmp/baseline.sql --allow-root --path=/var/www/html 2>/dev/null
	docker exec "$CONTAINER" wp cache flush --allow-root --path=/var/www/html 2>/dev/null
	docker exec "$CONTAINER" wp rewrite flush --allow-root --path=/var/www/html 2>/dev/null
}

# ── Test Runners ────────────────────────────────────────────────────

run_smoke() {
	info "Running smoke tests..."
	if bash "$SCRIPT_DIR/api-smoke-test.sh" "$BASE_URL"; then
		info "Smoke tests passed."
	else
		fail "Smoke tests failed."
		smoke_failed=1
	fi
}

run_unit() {
	info "Running standalone unit tests..."
	if bash "$REPO_DIR/run-all-tests.sh"; then
		info "Unit tests passed."
	else
		fail "Unit tests failed."
		unit_failed=1
	fi
}

run_integration() {
	local wp_runner="$SCRIPT_DIR/wp-cli-runner.sh"

	info "Running WordPress integration tests..."
	reset_state

	if bash "$wp_runner" \
		tests/integration/test-wordpress-integration.php \
		tests/integration/test-comprehensive.php \
		tests/integration/test-new-features.php \
		tests/integration/test-admin-events-coverage.php; then
		info "Integration tests passed."
	else
		fail "Integration tests failed."
		integration_failed=1
	fi
}

# ── Main ────────────────────────────────────────────────────────────

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   SportsPress Admin Tools Test Runner    ║"
echo "╚══════════════════════════════════════════╝"
echo ""

ensure_environment

case "$SUITE" in
	smoke)       run_smoke ;;
	unit)        run_unit ;;
	integration) run_integration ;;
	all)
		run_smoke
		echo ""
		run_unit
		echo ""
		run_integration
		;;
	*)
		echo "Usage: $0 {all|smoke|unit|integration}"
		exit 1
		;;
esac

# ── Summary ─────────────────────────────────────────────────────────

echo ""
echo "╔══════════════════════════════════════════╗"
echo "║              Test Summary                ║"
echo "╠══════════════════════════════════════════╣"
total_failed=$((smoke_failed + unit_failed + integration_failed))
[ "$SUITE" = "all" ] || [ "$SUITE" = "smoke" ] && \
	printf "║  Smoke:       %-26s║\n" "$([ $smoke_failed -eq 0 ] && echo '✓ PASSED' || echo '✗ FAILED')"
[ "$SUITE" = "all" ] || [ "$SUITE" = "unit" ] && \
	printf "║  Unit:        %-26s║\n" "$([ $unit_failed -eq 0 ] && echo '✓ PASSED' || echo '✗ FAILED')"
[ "$SUITE" = "all" ] || [ "$SUITE" = "integration" ] && \
	printf "║  Integration: %-26s║\n" "$([ $integration_failed -eq 0 ] && echo '✓ PASSED' || echo '✗ FAILED')"
echo "╚══════════════════════════════════════════╝"
echo ""

exit $((total_failed > 0 ? 1 : 0))
