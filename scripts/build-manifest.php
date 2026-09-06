<?php
/**
 * Build the release manifest that the in-plugin updater reads.
 *
 * The repository tags one release for eight independently-versioned plugins:
 * v1.1.0 has carried sportspress-admin-tools 1.0.5 alongside six plugins at
 * 1.1.0. A plugin therefore cannot learn its own version from the tag, and the
 * only other ways to find it are downloading every zip or requesting eight
 * files over the API. So the release publishes the answer directly, as one
 * small asset the updater fetches once for the whole suite.
 *
 * Version parsing is not reimplemented here. scripts/release-guard.php is
 * already the authority on what version a plugin is — it refuses to publish a
 * release where the header, the readme's stable tag and the *_VERSION constant
 * disagree — and it runs in the same workflow immediately before this. A
 * second parser would be a second opinion, and the point of the guard is that
 * there is only one.
 *
 * Usage: php scripts/build-manifest.php <tag> [output-path]
 *
 * @author Cody (lusky3)
 */

define( 'SPAT_GUARD_TEST_MODE', true );
require_once __DIR__ . '/release-guard.php';

const SPAT_MANIFEST_REPO = 'lusky3/SportsPress-Admin-Tools';

/**
 * One header field out of a plugin's file header.
 *
 * @param string $header_php Plugin file contents.
 * @param string $field      Header field name.
 * @return string
 */
function spat_manifest_header( string $header_php, string $field ): string {
	$pattern = '/^[\s*]*' . preg_quote( $field, '/' ) . ':\s*(.+)$/mi';
	if ( preg_match( $pattern, $header_php, $m ) ) {
		return trim( $m[1] );
	}
	return '';
}

/**
 * The changelog entry for one version, as the "View details" modal shows it.
 *
 * Takes everything under `= <version> =` up to the next heading. Returns an
 * empty string when the readme has no entry, rather than falling back to the
 * whole changelog — the guard already refuses to release in that case, so an
 * empty section here means something upstream is wrong and should look wrong.
 *
 * @param string $readme  readme.txt contents.
 * @param string $version Version to extract.
 * @return string
 */
function spat_manifest_changelog( string $readme, string $version ): string {
	$pattern = '/^=\s*' . preg_quote( $version, '/' ) . '\s*=\s*$(.*?)(?=^=\s|\z)/ms';
	if ( ! preg_match( $pattern, $readme, $m ) ) {
		return '';
	}
	return trim( $m[1] );
}

/**
 * json_encode with the flags WordPress would use, without needing WordPress.
 *
 * @param mixed $data Data to encode.
 * @return string
 */
function wp_json_encode_compat( $data ): string {
	return (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
}

/**
 * The manifest for every plugin in the repository.
 *
 * @param string $root Repository root.
 * @param string $tag  Release tag.
 * @return array
 */
function spat_manifest_build( string $root, string $tag ): array {
	$plugins = array();

	foreach ( glob( $root . '/sportspress-*', GLOB_ONLYDIR ) as $dir ) {
		$slug = basename( $dir );
		$main = $dir . '/' . $slug . '.php';
		if ( ! is_file( $main ) ) {
			continue;
		}

		$header_php = (string) file_get_contents( $main );
		$readme     = is_file( $dir . '/readme.txt' ) ? (string) file_get_contents( $dir . '/readme.txt' ) : '';
		$versions   = spat_guard_read_versions( $header_php, $readme );

		if ( '' === $versions['header'] ) {
			fwrite( STDERR, "manifest: {$slug} has no readable Version header\n" );
			continue;
		}

		$plugins[ $slug ] = array(
			'name'         => spat_manifest_header( $header_php, 'Plugin Name' ),
			'version'      => $versions['header'],
			'asset'        => $slug . '.zip',
			'requires'     => spat_manifest_header( $header_php, 'Requires at least' ),
			'tested'       => spat_manifest_header( $header_php, 'Tested up to' ),
			'requires_php' => spat_manifest_header( $header_php, 'Requires PHP' ),
			'changelog'    => spat_manifest_changelog( $readme, $versions['header'] ),
		);
	}

	ksort( $plugins );

	return array(
		'schema'  => 1,
		'repo'    => SPAT_MANIFEST_REPO,
		'tag'     => $tag,
		'plugins' => $plugins,
	);
}

// Everything above is reusable; everything below runs the command. Guarded the
// same way scripts/release-guard.php is, so tests can require this file for its
// functions without argv handling firing.
if ( PHP_SAPI !== 'cli' || ( defined( 'SPAT_MANIFEST_TEST_MODE' ) && SPAT_MANIFEST_TEST_MODE ) ) {
	return;
}

// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only stdout, no WordPress runtime.

$tag = $argv[1] ?? '';
if ( '' === $tag ) {
	fwrite( STDERR, "usage: php scripts/build-manifest.php <tag> [output-path]\n" );
	exit( 2 );
}
$out = $argv[2] ?? __DIR__ . '/../dist/manifest.json';

$manifest = spat_manifest_build( dirname( __DIR__ ), $tag );

if ( empty( $manifest['plugins'] ) ) {
	fwrite( STDERR, "manifest: no plugins found — refusing to write an empty manifest\n" );
	exit( 1 );
}

@mkdir( dirname( $out ), 0775, true ); // phpcs:ignore
file_put_contents( $out, wp_json_encode_compat( $manifest ) . "\n" );

printf( "manifest: %d plugins written to %s\n", count( $manifest['plugins'] ), $out );
foreach ( $manifest['plugins'] as $slug => $p ) {
	printf( "  %-34s %s\n", $slug, $p['version'] );
}
