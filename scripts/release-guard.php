<?php
/**
 * Release guard — refuse to publish a plugin whose shipped code changed without
 * a version bump and a matching changelog entry.
 *
 * Runs on a v* tag, before packaging. For every sportspress-* plugin it asks:
 *
 *   1. Did anything SHIPPED change since the previous tag? Changes confined to
 *      tests/, docs/ and the other .distignore'd paths do not reach a user, so
 *      they must not force a version bump — otherwise the guard trains people
 *      to bump versions meaninglessly, which is worse than no guard.
 *   2. If so, is the version higher than it was at the previous tag?
 *   3. Does readme.txt's changelog carry a "= <new version> =" section?
 *   4. Do all THREE version locations agree — the plugin header's Version:,
 *      readme.txt's Stable tag:, and the *_VERSION constant? They drift
 *      silently, and the constant is the one that matters most: admin-tools
 *      derives SPAT_DB_VERSION from it, so a stale constant means a migration
 *      that never runs.
 *
 * The parsing and decision logic here is pure so it can be tested without git
 * or WordPress; see tests/test-release-guard.php. The CLI wrapper at the bottom
 * is the only part that touches the repository.
 *
 * Usage:
 *   php scripts/release-guard.php [--base=<git-ref>] [--verbose]
 *
 * Exit 0 = safe to publish. Exit 1 = one or more plugins would ship unlabelled.
 *
 * @package SportsPress_Admin_Tools
 */

// ---------------------------------------------------------------------------
// Pure logic
// ---------------------------------------------------------------------------

/**
 * Paths inside a plugin that never reach a user, mirroring .distignore.
 *
 * Kept deliberately conservative: anything not listed counts as shipped, so a
 * new directory defaults to REQUIRING a bump rather than silently escaping the
 * guard.
 *
 * @param string $relative_path Path relative to the plugin directory.
 * @return bool True when the path is excluded from the built package.
 */
function spat_guard_is_non_shipping( string $relative_path ): bool {
	$prefixes = array( 'tests/', 'docs/', '.github/', 'node_modules/', 'src/', '.claude/' );
	foreach ( $prefixes as $prefix ) {
		if ( 0 === strpos( $relative_path, $prefix ) ) {
			return true;
		}
	}

	$files = array(
		'.distignore', '.gitignore', '.gitattributes', '.editorconfig',
		'composer.json', 'composer.lock', 'package.json', 'package-lock.json',
		'phpcs.xml', 'phpunit.xml', 'phpunit.xml.dist',
		'AGENTS.md', 'ARCHITECTURE.md', 'CLAUDE.md',
	);

	return in_array( $relative_path, $files, true );
}

/**
 * Extract the three version declarations from a plugin's files.
 *
 * @param string $header_php Contents of the main plugin file.
 * @param string $readme     Contents of readme.txt.
 * @return array{header:string,stable:string,constant:string}
 */
function spat_guard_read_versions( string $header_php, string $readme ): array {
	$out = array(
		'header'   => '',
		'stable'   => '',
		'constant' => '',
	);

	if ( preg_match( '/^[\s*]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $header_php, $m ) ) {
		$out['header'] = trim( $m[1] );
	}
	if ( preg_match( '/^Stable tag:\s*([0-9][0-9A-Za-z.\-]*)/mi', $readme, $m ) ) {
		$out['stable'] = trim( $m[1] );
	}
	// Any FOO_VERSION constant, excluding the derived/contract ones that are
	// deliberately independent of the release version.
	if ( preg_match_all( "/define\(\s*'([A-Z]+)_VERSION'\s*,\s*'([^']+)'\s*\)/", $header_php, $all, PREG_SET_ORDER ) ) {
		foreach ( $all as $m ) {
			if ( in_array( $m[1], array( 'SPAT_CONTRACT', 'SPAT_DB' ), true ) ) {
				continue;
			}
			$out['constant'] = trim( $m[2] );
			break;
		}
	}

	return $out;
}

/**
 * Whether the changelog documents this version.
 *
 * Matches the WordPress.org readme convention, "= 1.2.3 =" on its own line.
 *
 * @param string $readme  Contents of readme.txt.
 * @param string $version Version being released.
 * @return bool
 */
function spat_guard_changelog_has_version( string $readme, string $version ): bool {
	if ( '' === $version ) {
		return false;
	}

	return 1 === preg_match( '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$/m', $readme );
}

/**
 * Decide whether one plugin may be published.
 *
 * @param array $state {
 *     @type string $name            Plugin directory name.
 *     @type bool   $shipped_changed Whether any shipped file changed since base.
 *     @type array  $versions        Result of spat_guard_read_versions().
 *     @type string $previous        Header version at the base ref, '' if new.
 *     @type string $readme          Contents of readme.txt.
 * }
 * @return array{ok:bool,problems:array<string>} Problems are empty when ok.
 */
function spat_guard_check_plugin( array $state ): array {
	$problems = array();
	$v        = $state['versions'];
	$current  = $v['header'];

	// Consistency is checked always, not only on change: a drifted constant is
	// a latent migration bug whether or not this release touched the plugin.
	if ( '' === $current ) {
		$problems[] = 'no Version: header found';
	}
	if ( '' !== $current && '' !== $v['stable'] && $v['stable'] !== $current ) {
		$problems[] = sprintf( 'Stable tag (%s) disagrees with Version: header (%s)', $v['stable'], $current );
	}
	if ( '' !== $current && '' !== $v['constant'] && $v['constant'] !== $current ) {
		$problems[] = sprintf( '*_VERSION constant (%s) disagrees with Version: header (%s)', $v['constant'], $current );
	}

	if ( ! $state['shipped_changed'] ) {
		return array(
			'ok'       => empty( $problems ),
			'problems' => $problems,
		);
	}

	// Shipped code changed, so this release must be labelled.
	if ( '' !== $state['previous'] && '' !== $current ) {
		if ( version_compare( $current, $state['previous'], '<=' ) ) {
			$problems[] = sprintf(
				'shipped files changed but version was not raised (still %s, was %s at the previous tag)',
				$current,
				$state['previous']
			);
		}
	}

	if ( ! spat_guard_changelog_has_version( $state['readme'], $current ) ) {
		$problems[] = sprintf( 'readme.txt changelog has no "= %s =" section', $current );
	}

	return array(
		'ok'       => empty( $problems ),
		'problems' => $problems,
	);
}

// ---------------------------------------------------------------------------
// CLI wrapper — the only part that touches git
// ---------------------------------------------------------------------------

if ( PHP_SAPI !== 'cli' || ( defined( 'SPAT_GUARD_TEST_MODE' ) && SPAT_GUARD_TEST_MODE ) ) {
	return;
}

$opts    = getopt( '', array( 'base::', 'verbose' ) );
$verbose = isset( $opts['verbose'] );
$root    = dirname( __DIR__ );

chdir( $root );

$base = $opts['base'] ?? '';
if ( '' === $base ) {
	// The tag before this one. On the very first tag there is nothing to compare
	// against, so consistency is still checked but no bump is demanded.
	$base = trim( (string) shell_exec( 'git describe --tags --abbrev=0 HEAD^ 2>/dev/null' ) );
}

echo $base ? "Comparing against: {$base}\n\n" : "No previous tag found — checking version consistency only.\n\n";

$failed = 0;
$dirs   = glob( 'sportspress-*', GLOB_ONLYDIR ) ?: array();

foreach ( $dirs as $plugin ) {
	$main   = "{$plugin}/{$plugin}.php";
	$readme = "{$plugin}/readme.txt";
	if ( ! is_file( $main ) ) {
		continue;
	}

	$readme_txt = is_file( $readme ) ? (string) file_get_contents( $readme ) : '';
	$versions   = spat_guard_read_versions( (string) file_get_contents( $main ), $readme_txt );

	$shipped_changed = false;
	$previous        = '';

	if ( '' !== $base ) {
		$changed = array_filter(
			explode( "\n", (string) shell_exec( 'git diff --name-only ' . escapeshellarg( $base ) . ' HEAD -- ' . escapeshellarg( $plugin ) . ' 2>/dev/null' ) )
		);
		foreach ( $changed as $file ) {
			$rel = substr( trim( $file ), strlen( $plugin ) + 1 );
			if ( '' !== $rel && ! spat_guard_is_non_shipping( $rel ) ) {
				$shipped_changed = true;
				if ( $verbose ) {
					echo "  [{$plugin}] shipped change: {$rel}\n";
				}
				break;
			}
		}

		$prev_main = (string) shell_exec( 'git show ' . escapeshellarg( $base . ':' . $main ) . ' 2>/dev/null' );
		if ( '' !== $prev_main ) {
			$prev_v   = spat_guard_read_versions( $prev_main, '' );
			$previous = $prev_v['header'];
		}
	}

	$result = spat_guard_check_plugin(
		array(
			'name'            => $plugin,
			'shipped_changed' => $shipped_changed,
			'versions'        => $versions,
			'previous'        => $previous,
			'readme'          => $readme_txt,
		)
	);

	$label = $shipped_changed ? 'changed' : 'unchanged';
	if ( $result['ok'] ) {
		printf( "PASS  %-34s %-9s v%s\n", $plugin, $label, $versions['header'] );
	} else {
		++$failed;
		printf( "FAIL  %-34s %-9s v%s\n", $plugin, $label, $versions['header'] );
		foreach ( $result['problems'] as $p ) {
			echo "        - {$p}\n";
		}
	}
}

echo "\n";
if ( $failed > 0 ) {
	printf( "Release blocked: %d plugin(s) would ship without a version bump or changelog entry.\n", $failed );
	exit( 1 );
}

echo "All plugins are correctly labelled for release.\n";
exit( 0 );
