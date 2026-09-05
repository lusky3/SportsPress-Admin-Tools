<?php
/**
 * Standalone tests for the release guard's decision logic.
 *
 * The guard exists to stop a plugin shipping without a version bump or a
 * changelog entry. These tests cover the judgement calls rather than the git
 * plumbing: what counts as a shipped change, when a bump is required, and the
 * three-way version consistency check.
 *
 * Usage: php test-release-guard.php
 */

define( 'SPAT_GUARD_TEST_MODE', true );
require_once __DIR__ . '/../scripts/release-guard.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		++$passed;
	} else {
		echo "✗ FAIL: {$message}\n";
		++$failed;
	}
}

/** Build a plugin state with sensible defaults. */
function state( array $over = array() ) {
	return array_merge(
		array(
			'name'            => 'sportspress-example',
			'shipped_changed' => true,
			'versions'        => array( 'header' => '1.1.0', 'stable' => '1.1.0', 'constant' => '1.1.0' ),
			'previous'        => '1.0.0',
			'readme'          => "== Changelog ==\n\n= 1.1.0 =\n* Did a thing\n",
		),
		$over
	);
}

echo "\n=== what counts as a shipped change (.distignore driven) ===\n\n";

// The real vocabulary used across the eight plugins: anchored directories,
// bare root files, and a glob.
$distignore = spat_guard_parse_distignore(
	"# a comment\n\n/tests\n/docs\n/.github\n/node_modules\n/src\n"
	. ".distignore\n.gitignore\n.snyk\ncomposer.json\nphpcs.xml\n"
	. "README.md\nAGENTS.md\nassets/README*\n"
);

assert_test( ! in_array( '# a comment', $distignore, true ), 'comments are stripped from .distignore' );
assert_test( in_array( 'tests', $distignore, true ), 'a leading slash is an anchor, not part of the name' );

// The guard must not demand a version bump for work a user never receives,
// or it teaches people to bump meaninglessly — worse than no guard at all.
foreach ( array( 'tests/test-foo.php', 'docs/design.md', 'composer.json', 'phpcs.xml', '.distignore', 'AGENTS.md', 'src/app.js', 'assets/README.txt' ) as $path ) {
	assert_test( spat_guard_is_non_shipping( $path, $distignore ), "'{$path}' does not force a version bump" );
}

// Everything else ships, including the compiled bundle.
foreach ( array( 'includes/class-foo.php', 'build/index.js', 'readme.txt', 'sportspress-example.php', 'assets/app.css' ) as $path ) {
	assert_test( ! spat_guard_is_non_shipping( $path, $distignore ), "'{$path}' DOES force a version bump" );
}

// A path nobody anticipated must default to "ships", not to silently escaping.
assert_test( ! spat_guard_is_non_shipping( 'newthing/file.php', $distignore ), 'an unrecognised directory defaults to shipping' );

// Regression: these six were hardcoded as non-shipping while the packager
// genuinely shipped them, so a change confined to one reached users with no
// version bump. Reading .distignore is what fixed that, and a plugin without
// one must ship everything rather than assume.
foreach ( array( 'CLAUDE.md', '.claude/settings.json', 'phpunit.xml', '.editorconfig', 'src/app.js', 'README.md' ) as $path ) {
	assert_test( ! spat_guard_is_non_shipping( $path, array() ), "with no .distignore, '{$path}' ships and needs a bump" );
}

echo "\n=== version parsing ===\n\n";

$php = "<?php\n/**\n * Plugin Name: Example\n * Version: 2.3.4\n */\ndefine( 'SPX_VERSION', '2.3.4' );\n";
$rd  = "=== Example ===\nStable tag: 2.3.4\n\n== Changelog ==\n\n= 2.3.4 =\n* thing\n";
$v   = spat_guard_read_versions( $php, $rd );
assert_test( '2.3.4' === $v['header'], 'reads Version: from the plugin header' );
assert_test( '2.3.4' === $v['stable'], 'reads Stable tag: from readme.txt' );
assert_test( '2.3.4' === $v['constant'], 'reads the *_VERSION constant' );

// SPAT_DB_VERSION is derived and SPAT_CONTRACT_VERSION moves independently;
// neither should be mistaken for the release version. Declared FIRST on
// purpose: the previous version of this test put SPAT_VERSION first, so it
// passed on ordering alone and would have passed with the exclusion deleted.
$php2 = "<?php\n/**\n * Version: 1.0.5\n */\ndefine( 'SPAT_CONTRACT_VERSION', '9.9.9' );\ndefine( 'SPAT_DB_VERSION', '7.7.7' );\ndefine( 'SPAT_VERSION', '1.0.5' );\n";
$v2   = spat_guard_read_versions( $php2, '' );
assert_test( '1.0.5' === $v2['constant'], 'CONTRACT/DB constants are skipped even when declared first' );

// Being strict about quote style used to mean an unrecognised style silently
// disabled the check that matters most.
$dq = spat_guard_read_versions( "<?php\n * Version: 2.0.0\ndefine( \"SPX_VERSION\", \"2.0.0\" );\n", '' );
assert_test( '2.0.0' === $dq['constant'], 'a double-quoted define() is read, not silently skipped' );

$cn = spat_guard_read_versions( "<?php\n * Version: 2.0.0\nconst SPX_VERSION = '2.0.0';\n", '' );
assert_test( '2.0.0' === $cn['constant'], 'a const declaration is read, not silently skipped' );

// An unreadable constant must be reported rather than passing quietly.
$weird = spat_guard_read_versions( "<?php\n * Version: 2.0.0\ndefine( 'SPX_VERSION', spx_compute() );\n", '' );
assert_test( '' === $weird['constant'], 'an unparseable constant value yields no version' );
assert_test( true === $weird['constant_declared'], '  but is still recorded as declared' );
$r = spat_guard_check_plugin( state( array( 'versions' => $weird + array( 'header' => '2.0.0', 'stable' => '2.0.0' ) ) ) );
assert_test( ! $r['ok'], 'a declared-but-unreadable constant BLOCKS instead of passing silently' );

echo "\n=== changelog detection ===\n\n";

assert_test( spat_guard_changelog_has_version( "== Changelog ==\n\n= 1.1.0 =\n* x\n", '1.1.0' ), 'finds an exact changelog heading' );
assert_test( ! spat_guard_changelog_has_version( "= 1.1.0 =\n", '1.2.0' ), 'does not accept a different version' );
assert_test( ! spat_guard_changelog_has_version( "= 1.1.01 =\n", '1.1.0' ), 'does not match a longer version by prefix' );
assert_test( ! spat_guard_changelog_has_version( 'mentions 1.1.0 in prose', '1.1.0' ), 'prose mentioning the number is not a changelog entry' );
assert_test( spat_guard_changelog_has_version( "=1.1.0=\n", '1.1.0' ), 'tolerates missing spaces in the heading' );
assert_test( ! spat_guard_changelog_has_version( "= 1.1.0 =\n", '' ), 'an empty version never matches' );

echo "\n=== the release decision ===\n\n";

assert_test( spat_guard_check_plugin( state() )['ok'], 'bumped + changelogged + consistent passes' );

$r = spat_guard_check_plugin( state( array( 'previous' => '1.1.0' ) ) );
assert_test( ! $r['ok'], 'shipped change with no version bump is BLOCKED' );
assert_test( false !== strpos( implode( ' ', $r['problems'] ), 'not raised' ), '  and says the version was not raised' );

$r = spat_guard_check_plugin( state( array( 'readme' => "== Changelog ==\n\n= 1.0.0 =\n* old\n" ) ) );
assert_test( ! $r['ok'], 'bumped version with no changelog entry is BLOCKED' );

// The case the guard is really for: unchanged plugins must not be nagged.
$r = spat_guard_check_plugin( state( array( 'shipped_changed' => false, 'previous' => '1.1.0' ) ) );
assert_test( $r['ok'], 'an unchanged plugin needs no bump and no new changelog entry' );

// Consistency is enforced even when nothing changed, because a drifted
// constant is a latent migration bug regardless of this release.
$r = spat_guard_check_plugin( state( array(
	'shipped_changed' => false,
	'versions'        => array( 'header' => '1.1.0', 'stable' => '1.0.0', 'constant' => '1.1.0' ),
) ) );
assert_test( ! $r['ok'], 'a stale Stable tag is caught even with no changes' );

$r = spat_guard_check_plugin( state( array(
	'versions' => array( 'header' => '1.1.0', 'stable' => '1.1.0', 'constant' => '1.0.0' ),
) ) );
assert_test( ! $r['ok'], 'a stale *_VERSION constant is caught' );
assert_test( false !== strpos( implode( ' ', $r['problems'] ), 'constant' ), '  and names the constant' );

// A brand new plugin has no previous tag to compare against.
$r = spat_guard_check_plugin( state( array( 'previous' => '' ) ) );
assert_test( $r['ok'], 'a new plugin with no previous tag passes on changelog alone' );

$r = spat_guard_check_plugin( state( array( 'versions' => array( 'header' => '', 'stable' => '', 'constant' => '' ) ) ) );
assert_test( ! $r['ok'], 'a missing Version: header is BLOCKED' );

// A downgrade is as wrong as no change.
$r = spat_guard_check_plugin( state( array( 'previous' => '2.0.0' ) ) );
assert_test( ! $r['ok'], 'a version that went BACKWARDS is BLOCKED' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
