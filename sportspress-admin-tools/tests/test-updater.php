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
define( 'WP_PLUGIN_DIR', __DIR__ . '/plugins' );

function add_filter() {}
function add_action() {}
function plugin_basename( $file ) {
	return ltrim( str_replace( __DIR__ . '/plugins/', '', $file ), '/' );
}
function home_url( $path = '' ) {
	return 'https://example.test' . $path;
}

/**
 * Fixture state for the network and transient stubs.
 *
 * @param array|null $set Values to merge in.
 * @return array
 */
function spat_updater_fixture( ?array $set = null ): array {
	static $state = array(
		'transients' => array(),
		'responses'  => array(),
		'requests'   => array(),
		'versions'   => array(),
	);
	if ( null !== $set ) {
		foreach ( $set as $k => $v ) {
			$state[ $k ] = $v;
		}
	}
	return $state;
}

function spat_updater_reset(): void {
	spat_updater_fixture(
		array(
			'transients' => array(),
			'responses'  => array(),
			'requests'   => array(),
			'versions'   => array(),
		)
	);
}

class WP_Error {
	public function get_error_message() {
		return 'stub error';
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_site_transient( $key ) {
	$state = spat_updater_fixture();
	return $state['transients'][ $key ] ?? false;
}
function set_site_transient( $key, $value, $ttl = 0 ) { // phpcs:ignore
	$state                       = spat_updater_fixture();
	$state['transients'][ $key ] = $value;
	spat_updater_fixture( array( 'transients' => $state['transients'] ) );
	return true;
}
function delete_site_transient( $key ) {
	$state = spat_updater_fixture();
	unset( $state['transients'][ $key ] );
	spat_updater_fixture( array( 'transients' => $state['transients'] ) );
	return true;
}

/**
 * Serves canned responses keyed by URL, and records every URL asked for.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_remote_get( $url, $args = array() ) { // phpcs:ignore
	$state              = spat_updater_fixture();
	$state['requests'][] = $url;
	spat_updater_fixture( array( 'requests' => $state['requests'] ) );

	return $state['responses'][ $url ] ?? new WP_Error();
}
function wp_remote_retrieve_response_code( $response ) {
	return $response['code'] ?? 0;
}
function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

/**
 * Stub mirroring the WordPress signature.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_plugin_data( $file, $markup = true, $translate = true ) { // phpcs:ignore
	$state    = spat_updater_fixture();
	$basename = ltrim( str_replace( WP_PLUGIN_DIR, '', $file ), '/' );
	return array( 'Version' => $state['versions'][ $basename ] ?? '' );
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

echo "\n=== end to end: a release becomes an offered update ===\n\n";

const SPAT_TEST_API      = 'https://api.github.com/repos/lusky3/SportsPress-Admin-Tools/releases/latest';
const SPAT_TEST_DOWNLOAD = 'https://github.com/lusky3/SportsPress-Admin-Tools/releases/download/v1.2.0/';

/**
 * Canned GitHub responses for one release.
 *
 * @param string $tag      Release tag.
 * @param array  $versions Manifest versions, slug => version.
 * @return array
 */
function spat_test_release( string $tag, array $versions ): array {
	$plugins = array();
	$assets  = array(
		array( 'name' => 'manifest.json', 'browser_download_url' => SPAT_TEST_DOWNLOAD . 'manifest.json' ),
	);
	foreach ( $versions as $slug => $version ) {
		$plugins[ $slug ] = array(
			'name'         => ucwords( str_replace( '-', ' ', $slug ) ),
			'version'      => $version,
			'asset'        => $slug . '.zip',
			'requires'     => '5.0',
			'tested'       => '6.9',
			'requires_php' => '8.1',
			'changelog'    => "* Did a thing\n* Did another",
		);
		$assets[] = array( 'name' => $slug . '.zip', 'browser_download_url' => SPAT_TEST_DOWNLOAD . $slug . '.zip' );
	}

	return array(
		SPAT_TEST_API => array(
			'code' => 200,
			'body' => wp_json_encode_test( array( 'tag_name' => $tag, 'assets' => $assets ) ),
		),
		SPAT_TEST_DOWNLOAD . 'manifest.json' => array(
			'code' => 200,
			'body' => wp_json_encode_test( array( 'schema' => 1, 'tag' => $tag, 'plugins' => $plugins ) ),
		),
	);
}

function wp_json_encode_test( $data ) {
	return json_encode( $data ); // phpcs:ignore
}

/** Drive the transient filter the way WordPress does. */
function spat_test_check() {
	$transient           = new stdClass();
	$transient->response = array();
	$transient->no_update = array();
	return SPAT_Updater::filter_update_transient( $transient );
}

// Two watched plugins at different installed versions — the shape the manifest
// exists for.
$tools_file = WP_PLUGIN_DIR . '/sportspress-player-tools/sportspress-player-tools.php';
$parent_file = WP_PLUGIN_DIR . '/sportspress-admin-tools/sportspress-admin-tools.php';
$U::watch( $parent_file );
$U::watch( $tools_file );

spat_updater_reset();
spat_updater_fixture(
	array(
		'versions'  => array(
			'sportspress-admin-tools/sportspress-admin-tools.php'   => '1.0.5',
			'sportspress-player-tools/sportspress-player-tools.php' => '1.1.0',
		),
		'responses' => spat_test_release( 'v1.2.0', array(
			'sportspress-admin-tools'  => '1.0.5',   // unchanged in this release
			'sportspress-player-tools' => '1.2.0',   // newer
		) ),
	)
);

$out = spat_test_check();

assert_test( isset( $out->response['sportspress-player-tools/sportspress-player-tools.php'] ), 'the plugin the release actually bumped is offered an update' );
assert_test( ! isset( $out->response['sportspress-admin-tools/sportspress-admin-tools.php'] ), 'the plugin at the same version in that release is not — one tag, two answers' );
assert_test( isset( $out->no_update['sportspress-admin-tools/sportspress-admin-tools.php'] ), '  it is listed as current instead, which is what shows the auto-update toggle' );

$offer = $out->response['sportspress-player-tools/sportspress-player-tools.php'];
assert_test( '1.2.0' === $offer->new_version, 'the offered version comes from the manifest' );
assert_test( SPAT_TEST_DOWNLOAD . 'sportspress-player-tools.zip' === $offer->package, 'the package is that plugin\'s own asset' );
assert_test( '8.1' === $offer->requires_php, 'the PHP requirement rides along, so WordPress can refuse an incompatible upgrade' );

echo "\n=== one round trip for the whole suite ===\n\n";

$requests = spat_updater_fixture()['requests'];
assert_test( 2 === count( $requests ), 'two requests total — the release and its manifest — not two per plugin (' . count( $requests ) . ')' );

spat_test_check();
assert_test( 2 === count( spat_updater_fixture()['requests'] ), 'a second check makes no further requests; the manifest is cached' );

SPAT_Updater::flush();
spat_test_check();
assert_test( 4 === count( spat_updater_fixture()['requests'] ), 'flushing the cache makes it fetch again — this is what runs after an upgrade' );

echo "\n=== a release candidate is ignored entirely ===\n\n";

spat_updater_reset();
spat_updater_fixture(
	array(
		'versions'  => array( 'sportspress-player-tools/sportspress-player-tools.php' => '1.1.0' ),
		'responses' => spat_test_release( 'v1.2.0-rc1', array( 'sportspress-player-tools' => '1.2.0' ) ),
	)
);
$out = spat_test_check();
assert_test( empty( $out->response ), 'a prerelease tag offers nothing, even though it names a newer version' );
assert_test( 1 === count( spat_updater_fixture()['requests'] ), '  and its manifest is never even fetched' );

echo "\n=== GitHub unreachable ===\n\n";

spat_updater_reset();
spat_updater_fixture( array( 'versions' => array( 'sportspress-player-tools/sportspress-player-tools.php' => '1.1.0' ) ) );
$out = spat_test_check();
assert_test( empty( $out->response ), 'an unreachable GitHub offers nothing rather than erroring' );
assert_test( isset( $out->no_update['sportspress-player-tools/sportspress-player-tools.php'] ), '  and the plugin is still listed, so its row keeps its controls' );
spat_test_check();
assert_test( 1 === count( spat_updater_fixture()['requests'] ), 'the failure is cached too — this runs on ordinary admin page loads' );

echo "\n=== a wordpress.org plugin cannot hijack one of these slugs ===\n\n";

spat_updater_reset();
spat_updater_fixture(
	array(
		'versions'  => array( 'sportspress-player-tools/sportspress-player-tools.php' => '1.1.0' ),
		'responses' => spat_test_release( 'v1.2.0', array( 'sportspress-player-tools' => '1.1.0' ) ),
	)
);
$transient            = new stdClass();
$transient->response  = array(
	// What the directory would say about a same-named plugin of its own.
	'sportspress-player-tools/sportspress-player-tools.php' => (object) array(
		'new_version' => '99.0',
		'package'     => 'https://downloads.wordpress.org/plugin/sportspress-player-tools.99.0.zip',
	),
);
$transient->no_update = array();
$out                  = SPAT_Updater::filter_update_transient( $transient );
assert_test( ! isset( $out->response['sportspress-player-tools/sportspress-player-tools.php'] ), 'a directory update for one of our slugs is discarded, not installed over us' );

echo "\n=== the View details modal ===\n\n";

spat_updater_reset();
spat_updater_fixture(
	array(
		'versions'  => array( 'sportspress-player-tools/sportspress-player-tools.php' => '1.1.0' ),
		'responses' => spat_test_release( 'v1.2.0', array( 'sportspress-player-tools' => '1.2.0' ) ),
	)
);
$info = SPAT_Updater::filter_plugin_details( false, 'plugin_information', (object) array( 'slug' => 'sportspress-player-tools' ) );
assert_test( is_object( $info ) && '1.2.0' === $info->version, 'the modal is populated instead of 404ing to wordpress.org' );
assert_test( is_object( $info ) && false !== strpos( $info->sections['changelog'], '<li>Did a thing</li>' ), '  with the changelog rendered as list items' );
assert_test( is_object( $info ) && false === strpos( $info->sections['changelog'], '*' ), '  and the readme bullet markers stripped' );

$untouched = SPAT_Updater::filter_plugin_details( false, 'plugin_information', (object) array( 'slug' => 'some-other-plugin' ) );
assert_test( false === $untouched, 'another plugin\'s details request is left alone' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
