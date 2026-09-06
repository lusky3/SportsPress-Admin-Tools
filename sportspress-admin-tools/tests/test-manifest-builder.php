<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
/**
 * Standalone tests for scripts/build-manifest.php.
 *
 * The manifest is what tells eight independently-versioned plugins which of
 * them a single release tag actually contains a newer copy of. If it is wrong,
 * a site either never updates or updates to the wrong thing.
 *
 * Usage: php test-manifest-builder.php
 */

define( 'SPAT_MANIFEST_TEST_MODE', true );

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

// The builder is a CLI script; run it and read what it produced, rather than
// including it and having its argv handling fire.
$root  = dirname( __DIR__, 2 );
$out   = sys_get_temp_dir() . '/spat-manifest-test-' . getmypid() . '.json';
$cmd   = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( $root . '/scripts/build-manifest.php' )
	. ' v9.9.9 ' . escapeshellarg( $out ) . ' 2>&1';
$output = shell_exec( $cmd );
$data   = is_file( $out ) ? json_decode( (string) file_get_contents( $out ), true ) : null;
@unlink( $out ); // phpcs:ignore

echo "\n=== the manifest the release publishes ===\n\n";

assert_test( is_array( $data ), 'the builder produces valid JSON' );
assert_test( is_array( $data ) && 'v9.9.9' === ( $data['tag'] ?? '' ), 'the tag it was built for is recorded' );
assert_test( is_array( $data ) && 'lusky3/SportsPress-Admin-Tools' === ( $data['repo'] ?? '' ), 'the repository is recorded, so a manifest cannot be read as another project\'s' );
assert_test( is_array( $data ) && 1 === ( $data['schema'] ?? 0 ), 'the schema is versioned, so an older updater can refuse a shape it does not know' );

$plugins = is_array( $data ) ? ( $data['plugins'] ?? array() ) : array();
assert_test( count( $plugins ) >= 8, 'every plugin in the repository appears (' . count( $plugins ) . ')' );

echo "\n=== every entry carries what the update UI needs ===\n\n";

$incomplete = array();
foreach ( $plugins as $slug => $entry ) {
	foreach ( array( 'name', 'version', 'asset', 'requires', 'tested', 'requires_php' ) as $field ) {
		if ( empty( $entry[ $field ] ) ) {
			$incomplete[] = "{$slug}.{$field}";
		}
	}
	if ( $entry['asset'] !== $slug . '.zip' ) {
		$incomplete[] = "{$slug}.asset-name";
	}
}
assert_test( empty( $incomplete ), 'no entry is missing a field: ' . ( $incomplete ? implode( ', ', $incomplete ) : 'none' ) );

// The reason the manifest exists at all: one tag, several versions. If this
// ever collapses to a single version the format is doing nothing for us.
$versions = array_unique( array_column( $plugins, 'version' ) );
assert_test( count( $versions ) > 1, 'plugins in one release carry different versions (' . implode( ', ', $versions ) . ') — which is why the tag cannot answer this' );

// The version has to match what the plugin header actually says, or a site
// either never updates or updates forever.
$mismatched = array();
foreach ( $plugins as $slug => $entry ) {
	$main = $root . '/' . $slug . '/' . $slug . '.php';
	if ( ! is_file( $main ) ) {
		continue;
	}
	if ( preg_match( '/^[\s*]*Version:\s*(\S+)/mi', (string) file_get_contents( $main ), $m ) && trim( $m[1] ) !== $entry['version'] ) {
		$mismatched[] = $slug;
	}
}
assert_test( empty( $mismatched ), 'every manifest version matches its plugin header: ' . ( $mismatched ? implode( ', ', $mismatched ) : 'all match' ) );

echo "\n=== the Update URI header is what keeps wordpress.org out of it ===\n\n";

$missing_uri = array();
foreach ( array_keys( $plugins ) as $slug ) {
	$main = $root . '/' . $slug . '/' . $slug . '.php';
	$src  = is_file( $main ) ? (string) file_get_contents( $main ) : '';
	if ( false === strpos( $src, 'Update URI: https://github.com/lusky3/SportsPress-Admin-Tools' ) ) {
		$missing_uri[] = $slug;
	}
	if ( false === strpos( $src, 'SPAT_Updater::watch( __FILE__ )' ) ) {
		$missing_uri[] = $slug . ' (no watch call)';
	}
}
assert_test( empty( $missing_uri ), 'every plugin declares Update URI and registers itself: ' . ( $missing_uri ? implode( ', ', $missing_uri ) : 'all wired' ) );

echo "\n=== the parent/child contract floor ===\n\n";

// SPAT_Player is called unguarded from two children, so an older parent that
// still passes class_exists() would fatal on the first call. The contract
// version is how that is caught instead — but only if the floors are actually
// raised when the shared surface grows, which is the step easy to forget.
$parent_src = (string) file_get_contents( $root . '/sportspress-admin-tools/sportspress-admin-tools.php' );
preg_match( "/define\(\s*'SPAT_CONTRACT_VERSION',\s*'([^']+)'/", $parent_src, $m );
$contract = $m[1] ?? '';
assert_test( '' !== $contract, "the parent declares a contract version ({$contract})" );

// Every child that calls a SPAT_* helper without a class_exists() guard must
// require a contract at least as new as the one that introduced it.
$unguarded = array();
foreach ( glob( $root . '/sportspress-*', GLOB_ONLYDIR ) as $dir ) {
	$slug = basename( $dir );
	if ( 'sportspress-admin-tools' === $slug ) {
		continue;
	}
	$calls = false;
	foreach ( glob( $dir . '/includes/*.php' ) as $file ) {
		if ( false !== strpos( (string) file_get_contents( $file ), 'SPAT_Player::' ) ) {
			$calls = true;
			break;
		}
	}
	if ( ! $calls ) {
		continue;
	}
	$main = (string) file_get_contents( $dir . '/' . $slug . '.php' );
	if ( ! preg_match( "/SPAT_CONTRACT_VERSION,\s*'([^']+)'/", $main, $fm ) || version_compare( $fm[1], '1.2.0', '<' ) ) {
		$unguarded[] = $slug;
	}
}
assert_test( empty( $unguarded ), 'every child calling SPAT_Player requires the contract that introduced it: ' . ( $unguarded ? implode( ', ', $unguarded ) : 'all current' ) );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
