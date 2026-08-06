#!/usr/bin/env bash
# api-smoke-test.sh — Verify the sportspress-sandbox environment is healthy.
# Usage: ./tests/api-smoke-test.sh [base_url]
set -euo pipefail

BASE_URL="${1:-http://localhost:8082}"
HEALTH_URL="$BASE_URL/wp-json/test/v1/health"

echo "=== API Smoke Tests ==="
echo "Target: $BASE_URL"
echo ""

passed=0
failed=0

check() {
	local desc="$1" cond="$2"
	if eval "$cond"; then
		passed=$((passed + 1))
		echo "  ✓ $desc"
	else
		failed=$((failed + 1))
		echo "  ✗ FAIL: $desc"
	fi
}

# 1. Health endpoint responds
echo "--- Health Endpoint ---"
HEALTH=$(curl -sf "$HEALTH_URL" 2>/dev/null) || HEALTH=""
check "Health endpoint responds" '[ -n "$HEALTH" ]'

if [ -n "$HEALTH" ]; then
	check "Returns valid JSON" 'echo "$HEALTH" | python3 -m json.tool >/dev/null 2>&1'
	check "WordPress version present" 'echo "$HEALTH" | grep -q "wordpress_version"'
	check "SportsPress active" 'echo "$HEALTH" | grep -q "sportspress"'
	check "Admin tools plugin active" 'echo "$HEALTH" | grep -q "sportspress-admin-tools"'
fi

# 2. WordPress REST API
echo ""
echo "--- REST API ---"
REST=$(curl -sf "$BASE_URL/wp-json/wp/v2/types" 2>/dev/null) || REST=""
check "WP REST API responds" '[ -n "$REST" ]'
check "SportsPress post types registered" 'echo "$REST" | grep -q "sp_team"'

# 3. WP-CLI inside container
echo ""
echo "--- WP-CLI ---"
CONTAINER="sportspress-test"
if docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
	check "WP-CLI responds" 'docker exec "$CONTAINER" wp --allow-root --path=/var/www/html option get blogname >/dev/null 2>&1'
	check "Baseline SQL exists" 'docker exec "$CONTAINER" test -f /tmp/baseline.sql'

	PLUGIN_COUNT=$(docker exec "$CONTAINER" wp plugin list --status=active --format=count --allow-root --path=/var/www/html 2>/dev/null) || PLUGIN_COUNT=0
	check "Multiple plugins active ($PLUGIN_COUNT)" '[ "$PLUGIN_COUNT" -ge 5 ]'
else
	echo "  - Skipped (container not running)"
fi

# 4. SportsPress data exists
echo ""
echo "--- Test Data ---"
TEAMS=$(curl -sf "$BASE_URL/wp-json/wp/v2/sp_team?per_page=1" 2>/dev/null) || TEAMS="[]"
check "Teams exist" 'echo "$TEAMS" | grep -q "id"'

PLAYERS=$(curl -sf "$BASE_URL/wp-json/wp/v2/sp_player?per_page=1" 2>/dev/null) || PLAYERS="[]"
check "Players exist" 'echo "$PLAYERS" | grep -q "id"'

EVENTS=$(curl -sf "$BASE_URL/wp-json/wp/v2/sp_event?per_page=1" 2>/dev/null) || EVENTS="[]"
check "Events exist" 'echo "$EVENTS" | grep -q "id"'

echo ""
echo "=== Results: $passed passed, $failed failed ==="
exit $((failed > 0 ? 1 : 0))
