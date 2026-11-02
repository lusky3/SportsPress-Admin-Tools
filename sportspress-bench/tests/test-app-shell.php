<?php
/**
 * Standalone tests for SPBN_App_Shell: rewrites, the HTML shell, the service
 * worker, the manifest and the calendar feed route.
 *
 * Usage: php test-app-shell.php
 */

require __DIR__ . '/bootstrap.php';

date_default_timezone_set( 'America/Toronto' );

$spbn_dir = sys_get_temp_dir() . '/spbn-shell-' . getmypid() . '/';
define( 'SPBN_PLUGIN_PATH', $spbn_dir );
define( 'SPBN_PLUGIN_URL', 'https://arl.example.test/wp-content/plugins/sportspress-bench/' );
define( 'SPBN_VERSION', '0.1.0' );

register_shutdown_function(
	function () use ( $spbn_dir ) {
		foreach ( array( 'build/.vite/manifest.json', 'build/sw.js' ) as $rel ) {
			if ( is_file( $spbn_dir . $rel ) ) {
				unlink( $spbn_dir . $rel );
			}
		}
		foreach ( array( 'build/.vite', 'build', '' ) as $rel ) {
			if ( is_dir( $spbn_dir . $rel ) ) {
				rmdir( $spbn_dir . $rel );
			}
		}
	}
);

// WordPress functions only this suite needs.
$GLOBALS['spbn_rewrite_rules'] = array();
$GLOBALS['spbn_flushes']       = 0;
function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	$GLOBALS['spbn_rewrite_rules'][] = array( $regex, $query, $after );
}
function flush_rewrite_rules( $hard = true ) {
	++$GLOBALS['spbn_flushes'];
}
function get_query_var( $var, $default = '' ) {
	return $GLOBALS['spbn_query_vars'][ $var ] ?? $default;
}
function wp_login_url( $redirect = '' ) {
	return 'https://arl.example.test/wp-login.php' . ( '' === $redirect ? '' : '?redirect_to=' . rawurlencode( $redirect ) );
}
function wp_logout_url( $redirect = '' ) {
	return 'https://arl.example.test/wp-login.php?action=logout&redirect_to=' . rawurlencode( $redirect );
}
function admin_url( $path = '' ) {
	return 'https://arl.example.test/wp-admin/' . $path;
}
function rest_url( $path = '' ) {
	return 'https://arl.example.test/wp-json/' . $path;
}
function wp_create_nonce( $action ) {
	return 'nonce-for-' . $action;
}
function get_bloginfo( $show = '' ) {
	return $GLOBALS['spbn_blogname'];
}
$GLOBALS['spbn_blogname'] = 'ARL Hockey';

require_once dirname( __DIR__ ) . '/includes/class-schema.php';
require_once dirname( __DIR__ ) . '/includes/class-event-presenter.php';
require_once dirname( __DIR__ ) . '/includes/class-roster-reader.php';
require_once dirname( __DIR__ ) . '/includes/class-changes.php';
require_once dirname( __DIR__ ) . '/includes/class-ical.php';
require_once dirname( __DIR__ ) . '/includes/class-spares.php';
require_once dirname( __DIR__ ) . '/includes/class-access.php';
require_once dirname( __DIR__ ) . '/includes/class-availability.php';
require_once dirname( __DIR__ ) . '/includes/class-mailer.php';
require_once dirname( __DIR__ ) . '/includes/class-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-app-shell.php';

$fixture = array(
	'index.html' => array(
		'file'    => 'assets/index-abc.js',
		'css'     => array( 'assets/index-def.css' ),
		'isEntry' => true,
	),
);
$boot    = array(
	'restRoot'   => 'https://arl.example.test/wp-json/spbn/v1/',
	'nonce'      => 'n0nce',
	'ajaxUrl'    => 'https://arl.example.test/wp-admin/admin-ajax.php',
	'loginUrl'   => 'https://arl.example.test/wp-login.php',
	'logoutUrl'  => 'https://arl.example.test/wp-login.php?action=logout',
	'accountUrl' => 'https://arl.example.test/my-account/',
	'helpUrl'    => 'https://help.arl.hockey/',
	'appBase'    => '/bench/',
	'siteName'   => 'ARL Hockey',
);

echo "\n=== shell_html ===\n\n";

$html = SPBN_App_Shell::shell_html( $fixture, $boot );
spbn_assert( 0 === strpos( ltrim( $html ), '<!DOCTYPE html>' ), 'a full HTML document' );
spbn_assert( false !== strpos( $html, SPBN_PLUGIN_URL . 'build/assets/index-abc.js' ), 'the entry script URL carries the plugin build prefix' );
spbn_assert( 1 === preg_match( '#<script type="module"[^>]*src="' . preg_quote( SPBN_PLUGIN_URL . 'build/assets/index-abc.js', '#' ) . '"#', $html ), 'the entry is a module script' );
spbn_assert( 1 === preg_match( '#<link rel="stylesheet"[^>]*href="' . preg_quote( SPBN_PLUGIN_URL . 'build/assets/index-def.css', '#' ) . '"#', $html ), 'the entry css is a stylesheet link' );
spbn_assert( false !== strpos( $html, '<link rel="manifest" href="/bench/manifest.webmanifest">' ), 'links the manifest' );
spbn_assert( 1 === preg_match( '#<meta name="theme-color" content="\#132343">#', $html ), 'theme-color meta' );
spbn_assert( 1 === preg_match( '#<meta name="viewport" content="width=device-width, initial-scale=1#', $html ), 'viewport meta' );
spbn_assert( false !== strpos( $html, '<script>window.SPBN_BOOT = ' ), 'boot data is a plain inline script' );
spbn_assert( false !== strpos( $html, '<meta name="robots" content="noindex, nofollow">' ), 'a member-only page is not indexed' );

preg_match( '#window\.SPBN_BOOT = (\{.*?\});</script>#s', $html, $m );
$decoded = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
spbn_assert( is_array( $decoded ) && $decoded === $boot, 'the boot JSON decodes back to exactly the boot array' );

$evil = $boot;
$evil['siteName'] = 'Eve</script><script>alert(1)</script> & "co"';
$html = SPBN_App_Shell::shell_html( $fixture, $evil );
spbn_assert( false === stripos( substr( $html, strpos( $html, 'SPBN_BOOT' ) ), '</script><script>alert' ), 'a script close inside siteName does not break out of the boot script' );
$script_closes = substr_count( strtolower( $html ), '</script>' );
$script_opens  = substr_count( strtolower( $html ), '<script' );
spbn_assert( $script_closes === $script_opens, 'every script tag opened is closed exactly once (no raw close from data)' );
spbn_assert( false === strpos( $html, 'alert(1)</script>' ), 'the injected markup never appears raw' );
preg_match( '#window\.SPBN_BOOT = (\{.*?\});</script>#s', $html, $m );
spbn_assert( isset( $m[1] ) && json_decode( $m[1], true )['siteName'] === $evil['siteName'], 'and the value still round-trips exactly' );
spbn_assert( false === strpos( $m[1], '&' ), 'ampersands are hex-escaped too' );

echo "\n--- imports and unsafe manifest entries ---\n\n";

$chunky = array(
	'index.html'     => array( 'file' => 'assets/index-abc.js', 'css' => array( 'assets/index-def.css' ), 'isEntry' => true, 'imports' => array( '_vendor.js' ) ),
	'_vendor.js'     => array( 'file' => 'assets/vendor-111.js', 'css' => array( 'assets/vendor-222.css' ) ),
);
$html   = SPBN_App_Shell::shell_html( $chunky, $boot );
spbn_assert( false !== strpos( $html, 'rel="modulepreload"' ) && false !== strpos( $html, 'assets/vendor-111.js' ), 'an imported chunk is preloaded' );
spbn_assert( false !== strpos( $html, 'assets/vendor-222.css' ), 'an imported chunk\'s css is linked' );

$bad  = array( 'index.html' => array( 'file' => 'assets/index.js', 'css' => array( 'a"onload="x.css', '../../evil.css', 'ok/path.css' ) ) );
$html = SPBN_App_Shell::shell_html( $bad, $boot );
spbn_assert( false === strpos( $html, 'evil.css' ), 'a css path with .. is not emitted' );
spbn_assert( false === strpos( $html, 'onload=' ), 'a css path with quotes is not emitted' );
spbn_assert( false !== strpos( $html, 'build/ok/path.css' ), 'a clean css path still is' );
$html = SPBN_App_Shell::shell_html( array( 'index.html' => array( 'file' => '../../evil.js' ) ), $boot );
spbn_assert( false === strpos( $html, 'evil.js' ) && false !== strpos( $html, 'Bench is not built' ), 'an entry file with .. is never emitted (the page falls back to not-built)' );

echo "\n--- not built ---\n\n";

foreach ( array( 'empty manifest' => array(), 'no entry' => array( 'other.js' => array( 'file' => 'x.js' ) ), 'entry without file' => array( 'index.html' => array( 'css' => array() ) ) ) as $label => $vm ) {
	$html = SPBN_App_Shell::shell_html( $vm, $boot );
	spbn_assert( false !== strpos( $html, 'Bench is not built' ), "{$label}: the minimal not-built page" );
	spbn_assert( false === strpos( $html, 'SPBN_BOOT' ) && false === strpos( $html, 'n0nce' ), "{$label}: no boot data, no nonce in it" );
}

echo "\n=== manifest_data ===\n\n";

$mf = SPBN_App_Shell::manifest_data();
spbn_assert( 'ARL Bench' === $mf['name'] && 'Bench' === $mf['short_name'], 'name and short_name' );
spbn_assert( '/bench/' === $mf['start_url'] && '/bench/' === $mf['scope'] && 'standalone' === $mf['display'], 'start_url, scope and display' );
spbn_assert( '#132343' === $mf['theme_color'] && '#F7FBFC' === $mf['background_color'], 'theme and background colours' );
$srcs = array_column( $mf['icons'], 'src' );
spbn_assert( count( $srcs ) >= 3 && 3 === count( array_filter( $srcs, function ( $s ) { return 0 === strpos( $s, SPBN_PLUGIN_URL . 'build/icons/' ); } ) ), 'icons come from build/icons/' );
spbn_assert( in_array( 'maskable', array_column( $mf['icons'], 'purpose' ), true ), 'one icon is maskable' );

echo "\n=== sw_body ===\n\n";

$sw = SPBN_App_Shell::sw_body( "precache(['__SPBN_BUILD_URL__assets/a.js','__SPBN_BUILD_URL__assets/b.css']);" );
spbn_assert( "precache(['" . SPBN_PLUGIN_URL . "build/assets/a.js','" . SPBN_PLUGIN_URL . "build/assets/b.css']);" === $sw, 'every __SPBN_BUILD_URL__ becomes the plugin build URL' );
spbn_assert( false === strpos( SPBN_App_Shell::sw_body( 'x' ), '__SPBN' ), 'a body without the token is unchanged' );
spbn_assert( "self.addEventListener('install',()=>self.skipWaiting());" === SPBN_App_Shell::sw_body( null ), 'a missing build/sw.js is a no-op worker' );

echo "\n=== add_rewrites / registration ===\n\n";

SPBN_App_Shell::add_rewrites();
$rules = array_column( $GLOBALS['spbn_rewrite_rules'], 1, 0 );
spbn_assert( 4 === count( $GLOBALS['spbn_rewrite_rules'] ), 'four rewrite rules' );
spbn_assert( 'index.php?spbn=ical&spbn_token=$matches[1]' === ( $rules['^bench/ical/([A-Za-z0-9]{32})\.ics$'] ?? '' ), 'the feed rule captures a 32-character token' );
spbn_assert( 'index.php?spbn=sw' === ( $rules['^bench/sw\.js$'] ?? '' ), 'the service worker rule' );
spbn_assert( 'index.php?spbn=manifest' === ( $rules['^bench/manifest\.webmanifest$'] ?? '' ), 'the manifest rule' );
spbn_assert( 'index.php?spbn=app' === ( $rules['^bench(?:/.*)?$'] ?? '' ), 'the catch-all rule serves the app' );
$order = array_column( $GLOBALS['spbn_rewrite_rules'], 0 );
spbn_assert( '^bench(?:/.*)?$' === end( $order ), 'the catch-all is added last so the specific rules win' );
spbn_assert( array( 'top' ) === array_values( array_unique( array_column( $GLOBALS['spbn_rewrite_rules'], 2 ) ) ), 'all four go to the top' );
spbn_assert( 1 === preg_match( '#' . str_replace( '#', '\#', $order[0] ) . '#', 'bench/ical/' . str_repeat( 'a', 32 ) . '.ics' ), 'the feed regex matches a feed path' );
spbn_assert( 0 === preg_match( '#' . $order[0] . '#', 'bench/ical/' . str_repeat( 'a', 31 ) . '.ics' ), 'and not a 31-character token' );
spbn_assert( 0 === preg_match( '#' . $order[3] . '#', 'benchmark' ), 'the catch-all does not take /benchmark' );
spbn_assert( 1 === preg_match( '#' . $order[3] . '#', 'bench/games/5' ) && 1 === preg_match( '#' . $order[3] . '#', 'bench' ) && 1 === preg_match( '#' . $order[3] . '#', 'bench/' ), 'but takes /bench, /bench/ and deep links' );

spbn_assert( array( 'spbn', 'spbn_token', 'x' ) === SPBN_App_Shell::query_vars( array( 'x' ) ) || array( 'x', 'spbn', 'spbn_token' ) === SPBN_App_Shell::query_vars( array( 'x' ) ), 'query vars spbn and spbn_token are added' );

SPBN_App_Shell::register();
spbn_assert( ! empty( $GLOBALS['spbn_actions']['init'] ) && ! empty( $GLOBALS['spbn_actions']['template_redirect'] ) && ! empty( $GLOBALS['spbn_filters']['query_vars'] ), 'register() hooks init, template_redirect and query_vars' );

echo "\n--- flush once on a version change ---\n\n";

$GLOBALS['spbn_flushes'] = 0;
unset( $GLOBALS['spbn_options']['spbn_rewrite_version'] );
SPBN_App_Shell::maybe_flush_rewrites();
spbn_assert( 1 === $GLOBALS['spbn_flushes'] && SPBN_VERSION === get_option( 'spbn_rewrite_version' ), 'no stored version: flush once and store it' );
SPBN_App_Shell::maybe_flush_rewrites();
spbn_assert( 1 === $GLOBALS['spbn_flushes'], 'same version: no second flush' );
update_option( 'spbn_rewrite_version', '0.0.1' );
SPBN_App_Shell::maybe_flush_rewrites();
spbn_assert( 2 === $GLOBALS['spbn_flushes'] && SPBN_VERSION === get_option( 'spbn_rewrite_version' ), 'an older stored version flushes again' );

echo "\n=== handle: app ===\n\n";

spbn_reset();
$res = SPBN_App_Shell::handle( 'app', 0, '' );
spbn_assert( 302 === $res['status'] && 'https://arl.example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://arl.example.test/bench/' ) === $res['redirect'], 'not logged in: redirect to wp-login with redirect_to /bench/' );
spbn_assert( '' === ( $res['body'] ?? '' ), 'and no shell body' );

$res = SPBN_App_Shell::handle( 'app', 7, '' );
spbn_assert( 200 === $res['status'] && true === $res['nocache'] && 0 === strpos( $res['headers']['Content-Type'], 'text/html' ), 'logged in: 200 html with no-cache headers' );
spbn_assert( false !== strpos( $res['body'], 'Bench is not built' ), 'with no build the shell says so (the PWA is a later step)' );

mkdir( $spbn_dir . 'build/.vite', 0777, true );
file_put_contents( $spbn_dir . 'build/.vite/manifest.json', wp_json_encode( $fixture ) );
$res = SPBN_App_Shell::handle( 'app', 7, '' );
spbn_assert( false !== strpos( $res['body'], SPBN_PLUGIN_URL . 'build/assets/index-abc.js' ) && false !== strpos( $res['body'], 'window.SPBN_BOOT' ), 'with a built manifest the shell has the entry script and the boot data' );
preg_match( '#window\.SPBN_BOOT = (\{.*?\});</script>#s', $res['body'], $m );
$b = json_decode( $m[1], true );
spbn_assert( 'nonce-for-wp_rest' === $b['nonce'], 'boot nonce is the wp_rest nonce' );
spbn_assert( 'https://arl.example.test/wp-json/spbn/v1/' === $b['restRoot'], 'restRoot is the spbn/v1 root' );
spbn_assert( 'https://arl.example.test/wp-admin/admin-ajax.php' === $b['ajaxUrl'], 'ajaxUrl' );
spbn_assert( '/bench/' === $b['appBase'] && 'ARL Hockey' === $b['siteName'], 'appBase and siteName' );
spbn_assert( 'https://help.arl.hockey/' === $b['helpUrl'], 'helpUrl default' );
add_filter( 'spbn_help_url', function () { return 'https://example.test/help'; } );
$b2 = SPBN_App_Shell::boot_data();
spbn_assert( 'https://example.test/help' === $b2['helpUrl'], 'helpUrl passes through the spbn_help_url filter' );
spbn_assert( false !== strpos( $b['loginUrl'], 'wp-login.php' ) && false !== strpos( $b['logoutUrl'], 'action=logout' ) && '' !== $b['accountUrl'], 'login, logout and account URLs are present' );
spbn_assert( array( 'restRoot', 'nonce', 'ajaxUrl', 'loginUrl', 'logoutUrl', 'accountUrl', 'helpUrl', 'appBase', 'siteName' ) === array_keys( $b ), 'boot has exactly the contract keys' );
$GLOBALS['spbn_filters'] = array();

echo "\n=== handle: service worker and manifest ===\n\n";

$res = SPBN_App_Shell::handle( 'sw', 0, '' );
spbn_assert( 200 === $res['status'] && 'application/javascript' === $res['headers']['Content-Type'] && '/bench/' === $res['headers']['Service-Worker-Allowed'] && 'no-cache' === $res['headers']['Cache-Control'], 'sw: headers (works logged out)' );
spbn_assert( "self.addEventListener('install',()=>self.skipWaiting());" === $res['body'], 'sw: no build/sw.js serves the no-op worker' );
file_put_contents( $spbn_dir . 'build/sw.js', "p('__SPBN_BUILD_URL__assets/a.js');" );
$res = SPBN_App_Shell::handle( 'sw', 0, '' );
spbn_assert( "p('" . SPBN_PLUGIN_URL . "build/assets/a.js');" === $res['body'], 'sw: serves build/sw.js with the build URL filled in' );
spbn_assert( 'nosniff' === ( $res['headers']['X-Content-Type-Options'] ?? '' ), 'sw: nosniff' );

$res = SPBN_App_Shell::handle( 'manifest', 0, '' );
spbn_assert( 200 === $res['status'] && 0 === strpos( $res['headers']['Content-Type'], 'application/manifest+json' ), 'manifest: 200 with the manifest media type' );
spbn_assert( 'ARL Bench' === ( json_decode( $res['body'], true )['name'] ?? '' ), 'manifest: body is the JSON manifest' );

spbn_assert( null === SPBN_App_Shell::handle( '', 0, '' ) && null === SPBN_App_Shell::handle( 'nope', 7, '' ), 'an unknown route is not handled (the request carries on)' );

echo "\n=== handle: calendar feed ===\n\n";

spbn_reset();
$GLOBALS['spbn_options']['sportspress_season'] = 674;
spbn_register_term( 674, 'sp_season', '2026' );
foreach ( array( 12 => 'Ducks', 7 => 'Knights of Ni', 9 => 'Bears', 8 => 'Wolves' ) as $id => $name ) {
	spbn_add_post( $id, 'sp_team', $name );
}
spbn_add_post( 50, 'sp_player', 'Pat Skater' );
spbn_add_meta( 50, 'sp_user', '7' );
spbn_add_meta( 50, 'sp_current_team', '12' );
spbn_add_post( 51, 'sp_player', 'Pat Goalie' );
spbn_add_meta( 51, 'sp_user', '7' );
spbn_add_meta( 51, 'sp_current_team', '7' );
spbn_add_user( 7, 'pat@example.test', 'Pat Skater' );
$token                                    = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
$GLOBALS['spbn_user_meta'][7]['spbn_ical_token'] = $token;

spbn_event_at( 101, '2026-11-04 21:45:00', 'future', array( 12, 7 ) );
spbn_set_terms( 101, 'sp_season', array( 674 => '2026' ) );
spbn_event_at( 103, '2026-11-11 21:45:00', 'draft', array( 12, 9 ) );
spbn_set_terms( 103, 'sp_season', array( 674 => '2026' ) );
spbn_add_meta( 103, '_spem_cancelled', '1' );
spbn_event_at( 104, '2026-11-18 21:45:00', 'draft', array( 12, 9 ) );
spbn_set_terms( 104, 'sp_season', array( 674 => '2026' ) );
spbn_event_at( 102, '2026-11-06 20:00:00', 'future', array( 9, 8 ) );
spbn_set_terms( 102, 'sp_season', array( 674 => '2026' ) );

$db                  = $GLOBALS['wpdb'];
$db->results_handler = function ( $sql ) {
	if ( false !== strpos( $sql, 'FROM wp_spbn_changes WHERE event_id = 101' ) ) {
		return '2';
	}
	if ( false !== strpos( $sql, 'FROM wp_spbn_spare_responses r' ) ) {
		return array(
			array( 'id' => '5', 'event_id' => '102', 'team_id' => '9', 'position' => 'goalie', 'needed' => '1', 'note' => '', 'status' => 'filled', 'created_by' => '3', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00', 'player_id' => '50' ),
		);
	}
	return array();
};

$res  = SPBN_App_Shell::handle( 'ical', 0, $token );
$body = $res['body'];
spbn_assert( 200 === $res['status'] && 'text/calendar; charset=utf-8' === $res['headers']['Content-Type'], 'feed: 200 text/calendar' );
spbn_assert( 0 === strpos( $body, "BEGIN:VCALENDAR\r\n" ), 'feed: a VCALENDAR' );
spbn_assert( 1 === substr_count( $body, 'UID:spbn-101-' ) && false !== strpos( $body, 'UID:spbn-101-12@arl.example.test' ), 'feed: a game between two of the user\'s own teams is listed once, from the home side' );
spbn_assert( false !== strpos( $body, 'SEQUENCE:2' ), 'feed: SEQUENCE is the event\'s change-row count' );
spbn_assert( false !== strpos( $body, 'UID:spbn-103-12@' ) && 1 === preg_match( '/UID:spbn-103-12@[^\r]*\r\n(?:[^\r]*\r\n)*?STATUS:CANCELLED/', $body ), 'feed: a cancelled (drafted) game is listed as STATUS:CANCELLED' );
spbn_assert( false === strpos( $body, 'UID:spbn-104-' ), 'feed: an unfinished draft is not listed' );
spbn_assert( false !== strpos( $body, 'UID:spbn-102-9@arl.example.test' ), 'feed: a game the user accepted as a spare is listed (for the calling team)' );
spbn_assert( 'spbn_ical_7_0' === $GLOBALS['spbn_transient_writes'][0][0] && 600 === $GLOBALS['spbn_transient_writes'][0][1], 'feed: cached in spbn_ical_{user}_{generation} for 10 minutes' );
spbn_assert( $body === $GLOBALS['spbn_transients']['spbn_ical_7_0'], 'feed: the cached body is the body sent' );

$queries_before = count( $db->queries );
$GLOBALS['spbn_transients']['spbn_ical_7_0'] = "BEGIN:VCALENDAR\r\nCACHED\r\nEND:VCALENDAR\r\n";
$res = SPBN_App_Shell::handle( 'ical', 0, $token );
spbn_assert( false !== strpos( $res['body'], 'CACHED' ) && count( $db->queries ) === $queries_before, 'feed: a cached body is served without rebuilding' );

update_option( 'spbn_generation', 3 );
$res = SPBN_App_Shell::handle( 'ical', 0, $token );
spbn_assert( false === strpos( $res['body'], 'CACHED' ) && isset( $GLOBALS['spbn_transients']['spbn_ical_7_3'] ), 'feed: a new generation misses the cache and writes under the new key' );

echo "\n--- unknown and malformed tokens are 404 and nothing more ---\n\n";

$unknown = SPBN_App_Shell::handle( 'ical', 0, str_repeat( 'z', 32 ) );
$short   = SPBN_App_Shell::handle( 'ical', 0, 'abc' );
$empty   = SPBN_App_Shell::handle( 'ical', 0, '' );
$inject  = SPBN_App_Shell::handle( 'ical', 0, "' OR 1=1 -- " . str_repeat( 'a', 20 ) );
foreach ( array( 'unknown' => $unknown, 'short' => $short, 'empty' => $empty, 'injection' => $inject ) as $label => $r ) {
	spbn_assert( 404 === $r['status'], "{$label} token: 404 (never 401/403)" );
	spbn_assert( empty( $r['redirect'] ) && false === strpos( $r['body'], 'BEGIN:VCALENDAR' ), "{$label} token: no redirect, no calendar" );
}
spbn_assert( $unknown['body'] === $short['body'] && $unknown['headers'] === $short['headers'], 'an unknown and a malformed token are indistinguishable' );
$res = SPBN_App_Shell::handle( 'ical', 7, str_repeat( 'z', 32 ) );
spbn_assert( 404 === $res['status'], 'being logged in does not make a wrong token work' );
$res = SPBN_App_Shell::handle( 'ical', 0, strtolower( $token ) );
spbn_assert( 404 === $res['status'], 'a token with the wrong case is 404 (exact match only)' );

spbn_done();
