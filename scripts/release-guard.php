<?php
/**
 * Release guard — refuse to publish a plugin whose shipped code changed without
 * a version bump and a matching changelog entry.
 *
 * Runs on a v* tag, before packaging. For every sportspress-* plugin it asks:
 *
 *   1. Did anything SHIPPED change since the previous tag? "Shipped" is read
 *      from the plugin's own .distignore — the same file the packaging step
 *      builds its zip exclusions from, so the two cannot drift apart. Changes
 *      confined to tests/, docs/ and the rest do not reach a user, so they must
 *      not force a version bump — otherwise the guard trains people to bump
 *      versions meaninglessly, which is worse than no guard.
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
 * Exit 2 = the guard could not determine the answer, which is never a pass: a
 * gate that cannot see must block, not wave things through.
 *
 * @package SportsPress_Admin_Tools
 */

// ---------------------------------------------------------------------------
// Pure logic
// ---------------------------------------------------------------------------

/**
 * Parse a .distignore into a list of exclusion patterns.
 *
 * .distignore is the single source of truth for what never reaches a user:
 * the packaging workflow builds its zip exclusions from the same file. Keeping
 * a second hand-copied list here is what let the two drift apart before.
 *
 * @param string $contents Contents of a plugin's .distignore, '' when absent.
 * @return array<string> Patterns, plugin-root-relative, comments stripped.
 */
function spat_guard_parse_distignore( string $contents ): array {
	$patterns = array();

	foreach ( explode( "\n", $contents ) as $line ) {
		$line = trim( preg_replace( '/#.*$/', '', $line ) );
		if ( '' === $line ) {
			continue;
		}
		// Every pattern is plugin-root-relative, so a leading slash is just an
		// anchor marker and carries no extra meaning here.
		$patterns[] = ltrim( $line, '/' );
	}

	return $patterns;
}

/**
 * Whether a path inside a plugin is excluded from the built package.
 *
 * Deliberately conservative: a path that matches nothing counts as SHIPPED, so
 * an unanticipated file defaults to REQUIRING a version bump rather than
 * silently escaping the guard. A plugin with no .distignore therefore ships
 * everything, which is the safe reading of a missing file.
 *
 * @param string        $relative_path Path relative to the plugin directory.
 * @param array<string> $patterns      Result of spat_guard_parse_distignore().
 * @return bool True when the path is excluded from the built package.
 */
function spat_guard_is_non_shipping( string $relative_path, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		// Exact file, or the directory itself.
		if ( $relative_path === $pattern ) {
			return true;
		}
		// Anything beneath an excluded directory.
		if ( 0 === strpos( $relative_path, $pattern . '/' ) ) {
			return true;
		}
		// Globs such as "assets/README*", and globbed directory contents.
		if ( fnmatch( $pattern, $relative_path ) || fnmatch( $pattern . '/*', $relative_path ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Constant name prefixes that are deliberately independent of the release
 * version: SPAT_DB_VERSION is derived and drives migrations, and
 * SPAT_CONTRACT_VERSION tracks the inter-plugin contract. Neither should ever
 * be mistaken for the version being shipped.
 */
const SPAT_GUARD_NON_RELEASE_CONSTANTS = array( 'SPAT_CONTRACT', 'SPAT_DB' );

/**
 * Whether a constant name is one of the deliberately-independent ones.
 *
 * @param string $name Constant name with the trailing _VERSION removed.
 * @return bool
 */
function spat_guard_is_release_constant( string $name ): bool {
	return ! in_array( $name, SPAT_GUARD_NON_RELEASE_CONSTANTS, true );
}

/**
 * Read the plugin's release-version constant.
 *
 * Accepts both quote styles and both declaration forms. Being strict here used
 * to mean an unrecognised style silently disabled the comparison — and this is
 * the check that matters most, since admin-tools derives SPAT_DB_VERSION from
 * this constant, so a stale value is a migration that never runs.
 *
 * @param string $header_php Contents of the main plugin file.
 * @return array{value:string,declared:bool} `declared` is true when the file
 *                                           names a release-version constant at
 *                                           all, readable or not.
 */
function spat_guard_read_version_constant( string $header_php ): array {
	$forms = array(
		'/define\(\s*[\'"]([A-Z][A-Z0-9_]*)_VERSION[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/',
		'/const\s+([A-Z][A-Z0-9_]*)_VERSION\s*=\s*[\'"]([^\'"]+)[\'"]/',
	);

	foreach ( $forms as $form ) {
		if ( ! preg_match_all( $form, $header_php, $matches, PREG_SET_ORDER ) ) {
			continue;
		}
		foreach ( $matches as $match ) {
			if ( spat_guard_is_release_constant( $match[1] ) ) {
				return array(
					'value'    => trim( $match[2] ),
					'declared' => true,
				);
			}
		}
	}

	// Nothing readable. Report whether one is nonetheless declared, so the
	// caller can complain rather than skip the check in silence.
	$declared = false;
	if ( preg_match_all( '/[\'"]([A-Z][A-Z0-9_]*)_VERSION[\'"]/', $header_php, $named, PREG_SET_ORDER ) ) {
		foreach ( $named as $match ) {
			if ( spat_guard_is_release_constant( $match[1] ) ) {
				$declared = true;
				break;
			}
		}
	}

	return array(
		'value'    => '',
		'declared' => $declared,
	);
}

/**
 * Extract the three version declarations from a plugin's files.
 *
 * @param string $header_php Contents of the main plugin file.
 * @param string $readme     Contents of readme.txt.
 * @return array{header:string,stable:string,constant:string,constant_declared:bool}
 */
function spat_guard_read_versions( string $header_php, string $readme ): array {
	$constant = spat_guard_read_version_constant( $header_php );

	$header = '';
	if ( preg_match( '/^[\s*]*Version:\s*(\d[\da-z.\-]*)/mi', $header_php, $m ) ) {
		$header = trim( $m[1] );
	}

	$stable = '';
	if ( preg_match( '/^Stable tag:\s*(\d[\da-z.\-]*)/mi', $readme, $m ) ) {
		$stable = trim( $m[1] );
	}

	return array(
		'header'            => $header,
		'stable'            => $stable,
		'constant'          => $constant['value'],
		'constant_declared' => $constant['declared'],
	);
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
	if ( '' === $versions['constant'] && ! empty( $versions['constant_declared'] ) ) {
		$problems[] = 'a *_VERSION constant is declared but its value could not be read — the guard cannot verify it';
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
 * Run a git command and return its stdout, or null when the command failed.
 *
 * Takes an argument array and hands it to proc_open unchanged, so no shell is
 * ever spawned and tag or path arguments cannot be reinterpreted as syntax.
 *
 * The null return matters more than it looks: collapsing "command failed" into
 * "no output" makes this guard fail OPEN — a bad ref or a tagless clone would
 * report no changes for every plugin and exit 0. Callers must treat null as a
 * hard error, never as an empty result.
 *
 * @param array<string> $args Arguments after `git`.
 * @return string|null Standard output, or null when git exited non-zero.
 */
function spat_guard_git( array $args ): ?string {
	$descriptors = array(
		1 => array( 'pipe', 'w' ),
		2 => array( 'pipe', 'w' ),
	);

	// The command is the literal 'git' and the arguments are passed as an
	// array, so proc_open execs it directly with no shell to reinterpret
	// them. There is no injection path, and the guard cannot do its job
	// without running git. Two rules flag the shape, so this suppresses the
	// line rather than naming one of them.
	// nosemgrep
	$process = proc_open( array_merge( array( 'git' ), $args ), $descriptors, $pipes );
	if ( ! is_resource( $process ) ) {
		return null;
	}

	$out = (string) stream_get_contents( $pipes[1] );
	fclose( $pipes[1] );
	// Drain stderr so git cannot block on a full pipe; its contents are unused.
	stream_get_contents( $pipes[2] );
	fclose( $pipes[2] );

	return 0 === proc_close( $process ) ? $out : null;
}

/**
 * Raised when the guard cannot establish what it is supposed to check.
 *
 * Throwing rather than exiting in place keeps process termination in exactly
 * one spot, and — more importantly — makes it impossible for a future caller
 * to report a problem and then carry on regardless. Forgetting an exit here
 * would reintroduce the fail-open bug this whole mechanism exists to prevent.
 */
class SPAT_Guard_Unrunnable extends RuntimeException {}

/**
 * Abort the run: an unverifiable release is a blocked release, never a pass.
 *
 * @param string $message Why the guard cannot proceed.
 * @throws SPAT_Guard_Unrunnable Always.
 * @return void
 */
function spat_guard_abort( string $message ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI script, no WordPress runtime: esc_html() is undefined here.
	throw new SPAT_Guard_Unrunnable( $message );
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

try {
	$opts    = getopt( '', array( 'base::', 'verbose' ) );
	$verbose = isset( $opts['verbose'] );
	$root    = dirname( __DIR__ );

	chdir( $root );

	// Establish that git works at all before reading anything into its silence.
	// Without this, "no previous tag" and "git is broken" look identical, and the
	// guard would wave every plugin through.
	if ( null === spat_guard_git( array( 'rev-parse', '--git-dir' ) ) ) {
		spat_guard_abort( 'git is unavailable or this is not a repository' );
	}

	// getopt() yields false for a bare `--base` with no value; casting keeps the
	// rest of this file dealing with exactly one "absent" representation.
	$base = isset( $opts['base'] ) && is_string( $opts['base'] ) ? trim( $opts['base'] ) : '';

	if ( '' !== $base ) {
		if ( null === spat_guard_git( array( 'rev-parse', '--verify', '--quiet', $base . '^{commit}' ) ) ) {
			spat_guard_abort( "--base={$base} does not resolve to a commit" );
		}
	} else {
		// The tag before this one. git describe also fails when no tag is reachable,
		// which for a first release is legitimate — but only because the check above
		// already proved git itself is healthy.
		$described = spat_guard_git( array( 'describe', '--tags', '--abbrev=0', 'HEAD^' ) );
		$base      = null === $described ? '' : trim( $described );
	}

	echo '' !== $base ? "Comparing against: {$base}\n\n" : "No previous tag found — checking version consistency only.\n\n";

	$failed = 0;
	$dirs   = glob( 'sportspress-*', GLOB_ONLYDIR ) ?: array();

	if ( empty( $dirs ) ) {
		spat_guard_abort( 'no sportspress-* plugin directories found' );
	}

	foreach ( $dirs as $plugin ) {
		$main   = "{$plugin}/{$plugin}.php";
		$readme = "{$plugin}/readme.txt";

		// A plugin whose main file does not match its directory name would silently
		// vanish from this report, so say so and block rather than skipping.
		if ( ! is_file( $main ) ) {
			++$failed;
			printf( "FAIL  %-34s %-9s %s\n", $plugin, 'unknown', '-' );
			echo "        - no {$plugin}.php found, so this plugin cannot be checked\n";
			continue;
		}

		$readme_txt = is_file( $readme ) ? (string) file_get_contents( $readme ) : '';
		$versions   = spat_guard_read_versions( (string) file_get_contents( $main ), $readme_txt );

		$has_distignore = is_file( "{$plugin}/.distignore" );
		$distignore     = $has_distignore
			? spat_guard_parse_distignore( (string) file_get_contents( "{$plugin}/.distignore" ) )
			: array();

		$shipped_changed = false;
		$previous        = '';

		if ( '' !== $base ) {
			$diff = spat_guard_git( array( 'diff', '--name-only', $base, 'HEAD', '--', $plugin ) );
			if ( null === $diff ) {
				spat_guard_abort( "could not diff {$plugin} between {$base} and HEAD" );
			}

			foreach ( array_filter( explode( "\n", $diff ) ) as $file ) {
				$rel = substr( trim( $file ), strlen( $plugin ) + 1 );
				if ( '' !== $rel && ! spat_guard_is_non_shipping( $rel, $distignore ) ) {
					$shipped_changed = true;
					if ( $verbose ) {
						echo "  [{$plugin}] shipped change: {$rel}\n";
					}
					break;
				}
			}

			// A plugin that did not exist at the base ref has no previous version;
			// git failing here is that case, not an error.
			$prev_main = spat_guard_git( array( 'show', $base . ':' . $main ) );
			if ( null !== $prev_main && '' !== $prev_main ) {
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

		// The packaging step builds its zip exclusions from this same file, so a
		// missing one does not mean "nothing to exclude" — it means tests/, docs/
		// and dev tooling all ship to users.
		if ( ! $has_distignore ) {
			$result['ok']         = false;
			$result['problems'][] = 'no .distignore, so tests/ and dev tooling would ship to users';
		}

		$label = $shipped_changed ? 'changed' : 'unchanged';
		if ( $result['ok'] ) {
			printf( "PASS  %-34s %-9s v%s\n", $plugin, $label, $versions['header'] );
		} else {
			++$failed;
			printf( "FAIL  %-34s %-9s v%s\n", $plugin, $label, $versions['header'] );
			foreach ( $result['problems'] as $problem ) {
				echo "        - {$problem}\n";
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
} catch ( SPAT_Guard_Unrunnable $e ) {
	fwrite( STDERR, 'Release guard could not run: ' . $e->getMessage() . "\n" );
	exit( 2 );
}
