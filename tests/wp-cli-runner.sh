#!/usr/bin/env bash
# wp-cli-runner.sh — Execute PHP test files inside the sportspress-test container via WP-CLI.
# Usage: ./tests/wp-cli-runner.sh <test-file.php> [<test-file2.php> ...]
set -euo pipefail

CONTAINER="sportspress-test"
PLUGIN_DIR="/var/www/html/wp-content/plugins/sportspress-admin-tools"

if [ $# -eq 0 ]; then
	echo "Usage: $0 <test-file.php> [<test-file2.php> ...]"
	echo "  Files are relative to the repo root."
	exit 1
fi

# Verify container is running
if ! docker inspect -f '{{.State.Running}}' "$CONTAINER" 2>/dev/null | grep -q true; then
	echo "ERROR: Container '$CONTAINER' is not running." >&2
	echo "Start it with: make test-up (from sportspress-sandbox dir)" >&2
	exit 1
fi

failed=0
total=0
for file in "$@"; do
	total=$((total + 1))
	container_path="$PLUGIN_DIR/$file"

	echo "=== Running: $file ==="
	if ! docker exec "$CONTAINER" test -f "$container_path"; then
		echo "  ERROR: File not found in container: $container_path"
		failed=$((failed + 1))
		continue
	fi

	output=$(docker exec "$CONTAINER" wp eval-file "$container_path" --allow-root --path=/var/www/html 2>&1) || true
	echo "$output"

	if echo "$output" | grep -qE "(FAIL:|Failed: [1-9]|Fatal error|ERROR:)"; then
		failed=$((failed + 1))
	fi
	echo ""
done

echo "=== Summary: $((total - failed))/$total passed ==="
exit $((failed > 0 ? 1 : 0))
