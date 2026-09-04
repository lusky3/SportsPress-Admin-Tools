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
		'.distignore',
		'.gitignore',
		'.gitattributes',
		'.editorconfig',
		'composer.json',
		'composer.lock',
		'package.json',
		'package-lock.json',
		'phpcs.xml',
		'phpunit.xml',
		'phpunit.xml.dist',
		'AGENTS.md',
		'ARCHITECTURE.md',
		'CLAUDE.md',
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
 * Problems with a plugin's version declarations, regardless of whether it changed.
 *
 * Run unconditionally: a drifted *_VERSION constant is a migration that never
 * fires, and that is a latent bug whether or not this release touched the plugin.
 *
 * @param array $versions Result of spat_guard_read_versions().
 * @return array<string> Human-readable problems, empty when consistent.
 */
function spat_guard_version_problems( array $versions ): array {
	$current = $versions['header'];

	if ( '' === $current ) {
		return array( 'no Version: header found' );
	}

	$problems = array();

	if ( '' !== $versions['stable'] && $versions['stable'] !== $current ) {
		$problems[] = sprintf( 'Stable tag (%s) disagrees with Version: header (%s)', $versions['stable'], $current );
	}
	if ( '' !== $versions['constant'] && $versions['constant'] !== $current ) {
		$problems[] = sprintf( '*_VERSION constant (%s) disagrees with Version: header (%s)', $versions['constant'], $current );
	}

	return $problems;
}

/**
 * Problems with how a changed plugin is labelled for release.
 *
 * Only meaningful once shipped files have actually changed.
 *
 * @param string $current  Version in the Version: header.
 * @param string $previous Header version at the base ref, '' when the plugin is new.
 * @param string $readme   Contents of readme.txt.
 * @return array<string> Human-readable problems, empty when correctly labelled.
 */
function spat_guard_release_problems( string $current, string $previous, string $readme ): array {
	$problems = array();

	// A new plugin has no previous tag to be raised above, so only the changelog
	// is demanded of it.
	if ( '' !== $previous && '' !== $current && version_compare( $current, $previous, '<=' ) ) {
		$problems[] = sprintf(
			'shipped files changed but version was not raised (still %s, was %s at the previous tag)',
			$current,
			$previous
		);
	}

	if ( ! spat_guard_changelog_has_version( $readme, $current ) ) {
		$problems[] = sprintf( 'readme.txt changelog has no "= %s =" section', $current );
	}

	return $problems;
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
	$problems = spat_guard_version_problems( $state['versions'] );

	if ( $state['shipped_changed'] ) {
		$problems = array_merge(
			$problems,
			spat_guard_release_problems(
				$state['versions']['header'],
				$state['previous'],
				$state['readme']
			)
		);
	}

	return array(
		'ok'       => empty( $problems ),
		'problems' => $problems,
	);
}

/**
 * Run a git command and return its stdout.
 *
 * Takes an argument array and hands it to proc_open unchanged, so no shell is
 * ever spawned and tag or path arguments cannot be reinterpreted as syntax.
 * A failing command yields '' — every caller here treats "no output" and
 * "command failed" the same way.
 *
 * @param array<string> $args Arguments after `git`.
 * @return string Standard output, or '' when the command failed.
 */
function spat_guard_git( array $args ): string {
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	$process = proc_open( array_merge( array( 'git' ), $args ), $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		return '';
	}

	$out = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	// Drain stderr so git cannot block on a full pipe; its contents are unused.
	stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );

	return 0 === proc_close( $process ) ? $out : '';
}

// ---------------------------------------------------------------------------
// CLI wrapper — the only part that touches git
// ---------------------------------------------------------------------------

/*
 * Everything below writes plain text to a terminal, never HTML to a browser,
 * and runs without WordPress loaded — esc_html() and friends are not defined
 * here, so the escaping sniff has nothing to suggest that would not fatal.
 * Every interpolated value is a plugin directory name, a version string, or a
 * path this script read out of git; none of it reaches a web response.
 */
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only stdout, no WordPress runtime.

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
	$base = trim( spat_guard_git( array( 'describe', '--tags', '--abbrev=0', 'HEAD^' ) ) );
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
			explode( "\n", spat_guard_git( array( 'diff', '--name-only', $base, 'HEAD', '--', $plugin ) ) )
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

		$prev_main = spat_guard_git( array( 'show', $base . ':' . $main ) );
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
