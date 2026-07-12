#!/usr/bin/env bash
# Run every standalone PHP test suite under PCOV, one process each (the suites
# define global WP stubs so they cannot share a process), and merge the
# per-process dumps into a Clover report for SonarCloud.
#
# Usage: tools/coverage/run.sh [output-clover-path]
set -uo pipefail

ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
OUT="${1:-$ROOT/coverage/clover.xml}"
PREPEND="$ROOT/tools/coverage/prepend.php"
COVDIR="$(mktemp -d)"
mkdir -p "$(dirname "$OUT")"

# Use the exact suite list from run-all-tests.sh (the known standalone-runnable
# suites) so coverage stays in lock-step with CI and we don't run PHPUnit-only
# files that fatal under a bare `php`. The single quotes are intentional: the
# `$SCRIPT_DIR` tokens are literal text to match/strip, not shell expansions.
# shellcheck disable=SC2016
mapfile -t SUITES < <(grep -oE 'run_test "\$SCRIPT_DIR/[^"]+"' "$ROOT/run-all-tests.sh" | sed -E 's#run_test "\$SCRIPT_DIR/##; s#"$##')

fail=0
count=0
for rel in "${SUITES[@]}"; do
	t="$ROOT/$rel"
	[ -f "$t" ] || { echo "  ! missing: $rel"; continue; }
	count=$((count + 1))
	if ! SPSS_COV_DIR="$COVDIR" php \
		-d pcov.enabled=1 -d opcache.enable_cli=0 -d "pcov.directory=$ROOT" \
		-d auto_prepend_file="$PREPEND" \
		"$t" >/dev/null 2>&1; then
		echo "  ✗ test suite failed: $rel"
		fail=1
	fi
done

echo "ran $count suites under coverage"
php "$ROOT/tools/coverage/merge.php" "$COVDIR" "$OUT" "$ROOT"
rm -rf "$COVDIR"
exit "$fail"
