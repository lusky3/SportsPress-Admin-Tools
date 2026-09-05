#!/usr/bin/env bash
#
# Run every registered standalone test under a coverage driver and produce a
# combined Clover report.
#
# Mirrors run-all-tests.sh's aggregate exit code exactly — this is that script
# plus instrumentation, not a replacement for it. run-all-tests.sh stays the
# fast, dependency-free path for a plain local run; use this one when you want
# numbers.
#
# The suite list comes from run-all-tests.sh itself (SPAT_LIST_TESTS=1) rather
# than a second copy here. A duplicated list is how the release guard's
# shipped-file rules drifted out of step with .distignore; one registry, read
# by both, cannot.
#
# Requires `composer install` (dev dependencies) and either PCOV or Xdebug.

set -u

repo_root="$(cd "$(dirname "$0")" && pwd)"
raw_dir="${repo_root}/.coverage-raw"
report_dir="${repo_root}/coverage"

if [ ! -f "${repo_root}/vendor/autoload.php" ]; then
	echo "Coverage tooling is not installed. Run 'composer install' first." >&2
	exit 1
fi

rm -rf "${raw_dir}" "${report_dir}"
mkdir -p "${raw_dir}" "${report_dir}"

# pcov.directory defaults to "auto", which resolves to the working directory.
# run-one.php chdir()s into each suite's own directory, so leaving it on auto
# would instrument that plugin's tests/ and nothing else. Pin it to the
# repository root and keep the driver out of the paths the report discards
# anyway, so it only tracks files the filter can use.
php_opts=(
	-d "pcov.directory=${repo_root}"
	-d "pcov.exclude=~(?:/vendor/|/tests/|/node_modules/|/build/)~"
)

failed=0
count=0

while IFS= read -r test_file; do
	[ -n "${test_file}" ] || continue
	count=$((count + 1))
	rel="${test_file#"${repo_root}"/}"
	# One .cov per suite, named after its full path rather than its basename.
	# No two suites collide today, but eight plugins each own a tests/ dir and
	# a second test-database.php is an easy thing to add; a basename collision
	# would silently drop one suite's coverage rather than fail.
	slug="${rel//\//__}"
	echo "=== ${rel}"
	if ! php "${php_opts[@]}" "${repo_root}/bin/coverage/run-one.php" "${test_file}" "${raw_dir}/${slug}.cov"; then
		failed=$((failed + 1))
		echo "*** ${rel} FAILED"
	fi
	echo
done < <(SPAT_LIST_TESTS=1 "${repo_root}/run-all-tests.sh")

if [ "${count}" -eq 0 ]; then
	echo "No test suites were listed by run-all-tests.sh" >&2
	exit 1
fi

php "${repo_root}/bin/coverage/merge.php" "${raw_dir}" "${report_dir}" || exit 1

if [ "${failed}" -gt 0 ]; then
	echo "${failed} of ${count} test suite(s) failed"
	exit 1
fi

echo "All ${count} test suites passed"
