<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
/**
 * Standalone tests for SPAT_Updater's decision layer.
 *
 * These plugins update themselves from GitHub releases, which means this class
 * decides what URL WordPress will download and unpack over a live plugin
 * directory. The rules worth pinning are the ones that keep that from going
 * wrong: which tags count as releases, which download hosts are acceptable,
 * and when a version is actually newer.
 *
 * Usage: php test-updater.php
 */

define( 'ABSPATH', __DIR__ );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'SPAT_VERSION', '1.0.5' );

function wp_parse_url( $url, $component = -1 ) { // phpcs:ignore
	return parse_url( $url, $component );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}
function add_filter() {}
function add_action() {}
function plugin_basename( $file ) {
	return ltrim( str_replace( __DIR__, '', $file ), '/' );
}

require_once __DIR__ . '/../includes/class-updater.php';

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

$U = 'SPAT_Updater';

echo "\n=== is_release_tag(): a release candidate is not a release ===\n\n";

// GitHub's "latest release" endpoint only skips releases flagged as
// prereleases, and that flag is set by hand. This repository has tagged
// v1.1.0-rc5 without it, so the shape of the tag has to agree too — otherwise
// a release candidate is offered to a live league as a routine plugin update.
foreach ( array( 'v1.1.0', '1.1.0', 'v1.1', 'v10.20.30' ) as $tag ) {
	assert_test( $U::is_release_tag( $tag ), "'{$tag}' is a release" );
}
foreach ( array( 'v1.1.0-rc5', 'v1.1.0-beta', 'v1.1.0+build.7', 'nightly', '', 'v1', 'v1.2.3.4' ) as $tag ) {
	assert_test( ! $U::is_release_tag( $tag ), "'{$tag}' is not a release" );
}

echo "\n=== is_trusted_package(): where a download may come from ===\n\n";

$good = 'https://github.com/lusky3/SportsPress-Admin-Tools/releases/download/v1.1.0/sportspress-player-tools.zip';
assert_test( $U::is_trusted_package( $good ), 'a release asset from this repository is accepted' );
assert_test(
	$U::is_trusted_package( 'https://objects.githubusercontent.com/lusky3/SportsPress-Admin-Tools/releases/download/v1.1.0/x.zip' ),
	'the CDN host GitHub redirects assets to is accepted'
);

// Both allowed hosts serve every public repository on GitHub, so the host
// alone proves nothing about whose code is in the zip.
assert_test(
	! $U::is_trusted_package( 'https://github.com/someone-else/evil/releases/download/v1/x.zip' ),
	'another repository on the same host is refused'
);
assert_test( ! $U::is_trusted_package( str_replace( 'https://', 'http://', $good ) ), 'plain http is refused' );
assert_test( ! $U::is_trusted_package( 'https://github.evil.com/lusky3/SportsPress-Admin-Tools/releases/download/v1/x.zip' ), 'a lookalike host is refused' );
assert_test( ! $U::is_trusted_package( 'https://github.com/lusky3/SportsPress-Admin-Tools/archive/main.zip' ), 'a non-release path on the right repository is refused' );
assert_test( ! $U::is_trusted_package( '' ), 'an empty URL is refused' );
assert_test( ! $U::is_trusted_package( 'not a url' ), 'a malformed URL is refused' );

echo "\n=== is_newer() ===\n\n";

assert_test( $U::is_newer( '1.0.5', '1.1.0' ), 'a higher version is offered' );
assert_test( ! $U::is_newer( '1.1.0', '1.1.0' ), 'the same version is not offered' );
assert_test( ! $U::is_newer( '1.1.0', '1.0.5' ), 'a lower version is not offered — a rolled-back release must not downgrade a site' );
assert_test( $U::is_newer( '1.1.0', '1.1.1' ), 'a patch bump is offered' );
assert_test( ! $U::is_newer( '', '1.1.0' ), 'an unreadable installed version offers nothing' );
assert_test( ! $U::is_newer( '1.0.0', '' ), 'an empty available version offers nothing' );

echo "\n=== entry_for(): reading one plugin out of the manifest ===\n\n";

$manifest = array(
	'plugins' => array(
		'sportspress-admin-tools' => array( 'version' => '1.0.5', 'asset' => 'sportspress-admin-tools.zip' ),
		'sportspress-player-tools' => array( 'version' => '1.1.0', 'asset' => 'sportspress-player-tools.zip' ),
		'sportspress-broken'       => array( 'asset' => 'x.zip' ),
	),
);

// The case the manifest exists for: one tag, two different versions.
assert_test( '1.0.5' === $U::entry_for( $manifest, 'sportspress-admin-tools' )['version'], 'each plugin gets its own version out of a single release' );
assert_test( '1.1.0' === $U::entry_for( $manifest, 'sportspress-player-tools' )['version'], '  even when they differ within that release' );
assert_test( null === $U::entry_for( $manifest, 'sportspress-score-sheets' ), 'a plugin the release predates is silence, not an error' );
assert_test( null === $U::entry_for( $manifest, 'sportspress-broken' ), 'an entry with no version is ignored' );
assert_test( null === $U::entry_for( array(), 'anything' ), 'an empty manifest offers nothing' );

echo "\n=== asset_url(): built from the release, never guessed ===\n\n";

$assets = array(
	array( 'name' => 'sportspress-player-tools.zip', 'browser_download_url' => $good ),
	array( 'name' => 'manifest.json', 'browser_download_url' => 'https://github.com/lusky3/SportsPress-Admin-Tools/releases/download/v1.1.0/manifest.json' ),
	array( 'name' => 'hijacked.zip', 'browser_download_url' => 'https://evil.example/x.zip' ),
);

assert_test( $good === $U::asset_url( $assets, 'sportspress-player-tools.zip' ), 'the asset URL comes from the release listing' );
assert_test( '' === $U::asset_url( $assets, 'sportspress-score-sheets.zip' ), 'an asset that failed to upload reads as absent, not as a URL that 404s mid-upgrade' );
assert_test( '' === $U::asset_url( $assets, 'hijacked.zip' ), 'an asset pointing off-host is discarded even though the release lists it' );
assert_test( '' === $U::asset_url( array(), 'anything.zip' ), 'a release with no assets offers nothing' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
