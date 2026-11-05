<?php
/**
 * Standalone tests for SPPA_App_Shell: rewrites, the HTML shell, the service
 * worker, the manifest and the calendar feed route.
 *
 * Usage: php test-app-shell.php
 */

require __DIR__ . '/bootstrap.php';

date_default_timezone_set( 'America/Toronto' );

$sppa_dir = sys_get_temp_dir() . '/sppa-shell-' . getmypid() . '/';
define( 'SPPA_PLUGIN_PATH', $sppa_dir );
define( 'SPPA_PLUGIN_URL', 'https://arl.example.test/wp-content/plugins/sportspress-player-app/' );
define( 'SPPA_VERSION', '0.1.0' );

register_shutdown_function(
	function () use ( $sppa_dir ) {
		foreach ( array( 'build/.vite/manifest.json', 'build/sw.js' ) as $rel ) {
			if ( is_file( $sppa_dir . $rel ) ) {
				unlink( $sppa_dir . $rel );
			}
		}
		foreach ( array( 'build/.vite', 'build', '' ) as $rel ) {
			if ( is_dir( $sppa_dir . $rel ) ) {
				rmdir( $sppa_dir . $rel );
			}
		}
	}
);

// WordPress functions only this suite needs.
$GLOBALS['sppa_rewrite_rules'] = array();
$GLOBALS['sppa_flushes']       = 0;
function add_rewrite_rule( $regex, $query, $after = 'bottom' ) {
	$GLOBALS['sppa_rewrite_rules'][] = array( $regex, $query, $after );
}
function flush_rewrite_rules( $hard = true ) {
	++$GLOBALS['sppa_flushes'];
}
function get_query_var( $var, $default = '' ) {
	return $GLOBALS['sppa_query_vars'][ $var ] ?? $default;
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
	return $GLOBALS['sppa_blogname'];
}
$GLOBALS['sppa_blogname'] = 'ARL Hockey';

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
	'restRoot'   => 'https://arl.example.test/wp-json/sppa/v1/',
	'nonce'      => 'n0nce',
	'ajaxUrl'    => 'https://arl.example.test/wp-admin/admin-ajax.php',
	'loginUrl'   => 'https://arl.example.test/wp-login.php',
	'logoutUrl'  => 'https://arl.example.test/wp-login.php?action=logout',
	'accountUrl' => 'https://arl.example.test/my-account/',
	'helpUrl'    => 'https://help.arl.hockey/',
	'appBase'    => '/my-arl/',
	'appName'    => 'My ARL',
	'siteName'   => 'ARL Hockey',
);

echo "\n=== shell_html ===\n\n";

$html = SPPA_App_Shell::shell_html( $fixture, $boot );
sppa_assert( 0 === strpos( ltrim( $html ), '<!DOCTYPE html>' ), 'a full HTML document' );
sppa_assert( false !== strpos( $html, SPPA_PLUGIN_URL . 'build/assets/index-abc.js' ), 'the entry script URL carries the plugin build prefix' );
sppa_assert( 1 === preg_match( '#<script type="module"[^>]*src="' . preg_quote( SPPA_PLUGIN_URL . 'build/assets/index-abc.js', '#' ) . '"#', $html ), 'the entry is a module script' );
sppa_assert( 1 === preg_match( '#<link rel="stylesheet"[^>]*href="' . preg_quote( SPPA_PLUGIN_URL . 'build/assets/index-def.css', '#' ) . '"#', $html ), 'the entry css is a stylesheet link' );
sppa_assert( false !== strpos( $html, '<link rel="manifest" href="/my-arl/manifest">' ), 'links the extensionless manifest URL' );
sppa_assert( 1 === preg_match( '#<meta name="theme-color" content="\#132343">#', $html ), 'theme-color meta' );
sppa_assert( 1 === preg_match( '#<meta name="viewport" content="width=device-width, initial-scale=1#', $html ), 'viewport meta' );
sppa_assert( false !== strpos( $html, '<script>window.SPPA_BOOT = ' ), 'boot data is a plain inline script' );
sppa_assert( false !== strpos( $html, '<meta name="robots" content="noindex, nofollow">' ), 'a member-only page is not indexed' );

preg_match( '#window\.SPPA_BOOT = (\{.*?\});</script>#s', $html, $m );
$decoded = isset( $m[1] ) ? json_decode( $m[1], true ) : null;
sppa_assert( is_array( $decoded ) && $decoded === $boot, 'the boot JSON decodes back to exactly the boot array' );

$evil = $boot;
$evil['siteName'] = 'Eve</script><script>alert(1)</script> & "co"';
$html = SPPA_App_Shell::shell_html( $fixture, $evil );
sppa_assert( false === stripos( substr( $html, strpos( $html, 'SPPA_BOOT' ) ), '</script><script>alert' ), 'a script close inside siteName does not break out of the boot script' );
$script_closes = substr_count( strtolower( $html ), '</script>' );
$script_opens  = substr_count( strtolower( $html ), '<script' );
sppa_assert( $script_closes === $script_opens, 'every script tag opened is closed exactly once (no raw close from data)' );
sppa_assert( false === strpos( $html, 'alert(1)</script>' ), 'the injected markup never appears raw' );
preg_match( '#window\.SPPA_BOOT = (\{.*?\});</script>#s', $html, $m );
sppa_assert( isset( $m[1] ) && json_decode( $m[1], true )['siteName'] === $evil['siteName'], 'and the value still round-trips exactly' );
sppa_assert( false === strpos( $m[1], '&' ), 'ampersands are hex-escaped too' );

echo "\n--- imports and unsafe manifest entries ---\n\n";

$chunky = array(
	'index.html'     => array( 'file' => 'assets/index-abc.js', 'css' => array( 'assets/index-def.css' ), 'isEntry' => true, 'imports' => array( '_vendor.js' ) ),
	'_vendor.js'     => array( 'file' => 'assets/vendor-111.js', 'css' => array( 'assets/vendor-222.css' ) ),
);
$html   = SPPA_App_Shell::shell_html( $chunky, $boot );
sppa_assert( false !== strpos( $html, 'rel="modulepreload"' ) && false !== strpos( $html, 'assets/vendor-111.js' ), 'an imported chunk is preloaded' );
sppa_assert( false !== strpos( $html, 'assets/vendor-222.css' ), 'an imported chunk\'s css is linked' );

$bad  = array( 'index.html' => array( 'file' => 'assets/index.js', 'css' => array( 'a"onload="x.css', '../../evil.css', 'ok/path.css' ) ) );
$html = SPPA_App_Shell::shell_html( $bad, $boot );
sppa_assert( false === strpos( $html, 'evil.css' ), 'a css path with .. is not emitted' );
sppa_assert( false === strpos( $html, 'onload=' ), 'a css path with quotes is not emitted' );
sppa_assert( false !== strpos( $html, 'build/ok/path.css' ), 'a clean css path still is' );
$html = SPPA_App_Shell::shell_html( array( 'index.html' => array( 'file' => '../../evil.js' ) ), $boot );
sppa_assert( false === strpos( $html, 'evil.js' ) && false !== strpos( $html, 'My ARL is not built' ), 'an entry file with .. is never emitted (the page falls back to not-built)' );

echo "\n--- not built ---\n\n";

foreach ( array( 'empty manifest' => array(), 'no entry' => array( 'other.js' => array( 'file' => 'x.js' ) ), 'entry without file' => array( 'index.html' => array( 'css' => array() ) ) ) as $label => $vm ) {
	$html = SPPA_App_Shell::shell_html( $vm, $boot );
	sppa_assert( false !== strpos( $html, 'My ARL is not built' ), "{$label}: the minimal not-built page" );
	sppa_assert( false === strpos( $html, 'SPPA_BOOT' ) && false === strpos( $html, 'n0nce' ), "{$label}: no boot data, no nonce in it" );
}

echo "\n=== manifest_data ===\n\n";

$mf = SPPA_App_Shell::manifest_data();
sppa_assert( 'My ARL' === $mf['name'] && 'My ARL' === $mf['short_name'], 'name and short_name' );
sppa_assert( '/my-arl/' === $mf['start_url'] && '/my-arl/' === $mf['scope'] && 'standalone' === $mf['display'], 'start_url, scope and display' );
sppa_assert( '#132343' === $mf['theme_color'] && '#F7FBFC' === $mf['background_color'], 'theme and background colours' );
$srcs = array_column( $mf['icons'], 'src' );
sppa_assert( count( $srcs ) >= 3 && 3 === count( array_filter( $srcs, function ( $s ) { return 0 === strpos( $s, SPPA_PLUGIN_URL . 'build/icons/' ); } ) ), 'icons come from build/icons/' );
sppa_assert( in_array( 'maskable', array_column( $mf['icons'], 'purpose' ), true ), 'one icon is maskable' );

echo "\n=== sw_body ===\n\n";

$sw = SPPA_App_Shell::sw_body( "precache(['__SPPA_BUILD_URL__assets/a.js','__SPPA_BUILD_URL__assets/b.css']);" );
sppa_assert( "precache(['" . SPPA_PLUGIN_URL . "build/assets/a.js','" . SPPA_PLUGIN_URL . "build/assets/b.css']);" === $sw, 'every __SPPA_BUILD_URL__ becomes the plugin build URL' );
sppa_assert( false === strpos( SPPA_App_Shell::sw_body( 'x' ), '__SPPA' ), 'a body without the token is unchanged' );
sppa_assert( "self.addEventListener('install',()=>self.skipWaiting());" === SPPA_App_Shell::sw_body( null ), 'a missing build/sw.js is a no-op worker' );

echo "\n=== add_rewrites / registration ===\n\n";

SPPA_App_Shell::add_rewrites();
$rules = array_column( $GLOBALS['sppa_rewrite_rules'], 1, 0 );
sppa_assert( 4 === count( $GLOBALS['sppa_rewrite_rules'] ), 'four rewrite rules' );
sppa_assert( 'index.php?sppa=ical&sppa_token=$matches[1]' === ( $rules['^my-arl/ical/([A-Za-z0-9]{32})(?:\.ics)?/?$'] ?? '' ), 'the feed rule captures a 32-character token, with or without .ics' );
sppa_assert( 'index.php?sppa=sw' === ( $rules['^my-arl/sw/?$'] ?? '' ), 'the service worker rule is extensionless' );
sppa_assert( 'index.php?sppa=manifest' === ( $rules['^my-arl/manifest/?$'] ?? '' ), 'the manifest rule is extensionless' );
sppa_assert( 'index.php?sppa=app' === ( $rules['^my-arl(?:/.*)?$'] ?? '' ), 'the catch-all rule serves the app' );
$order = array_column( $GLOBALS['sppa_rewrite_rules'], 0 );
sppa_assert( '^my-arl(?:/.*)?$' === end( $order ), 'the catch-all is added last so the specific rules win' );
sppa_assert( array( 'top' ) === array_values( array_unique( array_column( $GLOBALS['sppa_rewrite_rules'], 2 ) ) ), 'all four go to the top' );
sppa_assert( false === strpos( implode( ' ', $order ), 'webmanifest' ) && false === strpos( implode( ' ', $order ), 'sw\.js' ), 'no rule depends on a .js or .webmanifest URL' );

/** The query of the first rule (in registration order) that matches a request path. */
function sppa_route_of( $path ) {
	foreach ( $GLOBALS['sppa_rewrite_rules'] as $rule ) {
		if ( 1 === preg_match( '#' . str_replace( '#', '\#', $rule[0] ) . '#', $path ) ) {
			return $rule[1];
		}
	}
	return '';
}
$tok = str_repeat( 'a', 32 );
sppa_assert( 'index.php?sppa=sw' === sppa_route_of( 'my-arl/sw' ) && 'index.php?sppa=sw' === sppa_route_of( 'my-arl/sw/' ), '/my-arl/sw and /my-arl/sw/ reach the service worker' );
sppa_assert( 'index.php?sppa=manifest' === sppa_route_of( 'my-arl/manifest' ) && 'index.php?sppa=manifest' === sppa_route_of( 'my-arl/manifest/' ), '/my-arl/manifest reaches the manifest' );
sppa_assert( 'index.php?sppa=ical&sppa_token=$matches[1]' === sppa_route_of( 'my-arl/ical/' . $tok ) && 'index.php?sppa=ical&sppa_token=$matches[1]' === sppa_route_of( 'my-arl/ical/' . $tok . '.ics' ) && 'index.php?sppa=ical&sppa_token=$matches[1]' === sppa_route_of( 'my-arl/ical/' . $tok . '/' ), 'the feed answers with or without .ics' );
sppa_assert( 'index.php?sppa=app' === sppa_route_of( 'my-arl/ical/' . substr( $tok, 1 ) ), 'a 31-character token is not a feed (it falls to the app shell)' );
sppa_assert( 1 === preg_match( '#' . str_replace( '#', '\#', $order[0] ) . '#', 'my-arl/ical/' . $tok . '.ics' ) && 0 === preg_match( '#' . str_replace( '#', '\#', $order[0] ) . '#', 'my-arl/ical/' . $tok . 'x' ), 'a 33rd character is not part of a token' );
sppa_assert( 'index.php?sppa=app' === sppa_route_of( 'my-arl' ) && 'index.php?sppa=app' === sppa_route_of( 'my-arl/' ) && 'index.php?sppa=app' === sppa_route_of( 'my-arl/games/5' ), 'but /my-arl, /my-arl/ and deep links are the app' );
sppa_assert( '' === sppa_route_of( 'my-arlx' ), 'and /my-arlx is nobody\'s' );

sppa_assert( array( 'sppa', 'sppa_token', 'x' ) === SPPA_App_Shell::query_vars( array( 'x' ) ) || array( 'x', 'sppa', 'sppa_token' ) === SPPA_App_Shell::query_vars( array( 'x' ) ), 'query vars sppa and sppa_token are added' );

SPPA_App_Shell::register();
sppa_assert( ! empty( $GLOBALS['sppa_actions']['init'] ) && ! empty( $GLOBALS['sppa_actions']['template_redirect'] ) && ! empty( $GLOBALS['sppa_filters']['query_vars'] ), 'register() hooks init, template_redirect and query_vars' );

echo "\n--- flush once per version and rule set ---\n\n";

$GLOBALS['sppa_flushes'] = 0;
unset( $GLOBALS['sppa_options']['sppa_rewrite_version'] );
SPPA_App_Shell::maybe_flush_rewrites();
$stored = get_option( 'sppa_rewrite_version' );
sppa_assert( 1 === $GLOBALS['sppa_flushes'] && 1 === preg_match( '/^' . preg_quote( SPPA_VERSION, '/' ) . '-[0-9a-f]{8}$/', (string) $stored ), 'nothing stored: flush once and store version plus a hash of the rules' );
SPPA_App_Shell::maybe_flush_rewrites();
sppa_assert( 1 === $GLOBALS['sppa_flushes'], 'same version and rules: no second flush' );
update_option( 'sppa_rewrite_version', SPPA_VERSION );
SPPA_App_Shell::maybe_flush_rewrites();
sppa_assert( 2 === $GLOBALS['sppa_flushes'] && $stored === get_option( 'sppa_rewrite_version' ), 'an install that stored only the bare version (the earlier rules) flushes again, so the new rules take effect' );
update_option( 'sppa_rewrite_version', '0.0.1-' . substr( $stored, -8 ) );
SPPA_App_Shell::maybe_flush_rewrites();
sppa_assert( 3 === $GLOBALS['sppa_flushes'], 'an older plugin version flushes again' );

echo "\n=== handle: app ===\n\n";

sppa_reset();
$res = SPPA_App_Shell::handle( 'app', 0, '' );
sppa_assert( 302 === $res['status'] && 'https://arl.example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://arl.example.test/my-arl/' ) === $res['redirect'], 'not logged in: redirect to wp-login with redirect_to /my-arl/' );
sppa_assert( '' === ( $res['body'] ?? '' ), 'and no shell body' );

echo "\n--- a deep link survives the login ---\n\n";

$login_to = function ( $path ) {
	return 'https://arl.example.test/wp-login.php?redirect_to=' . rawurlencode( 'https://arl.example.test' . $path );
};
$res = SPPA_App_Shell::handle( 'app', 0, '', '/my-arl/games/101' );
sppa_assert( $login_to( '/my-arl/games/101' ) === $res['redirect'], 'not logged in on /my-arl/games/101: redirect_to is that page' );
sppa_assert( $login_to( '/my-arl/spares' ) === SPPA_App_Shell::handle( 'app', 0, '', '/my-arl/spares?utm=x#y' )['redirect'], 'the query string and fragment are dropped' );
sppa_assert( $login_to( '/my-arl/x' ) === SPPA_App_Shell::handle( 'app', 0, '', '//evil.example/my-arl/x' )['redirect'], 'a scheme-relative URI cannot change the host (redirect_to is always on this site)' );
$unsafe = array( '/my-arl/../wp-admin/', '/wp-admin/', '/my-arlx', '/my-arl/%2e%2e/x', '/my-arl//x', '/my-arl/<script>', "/my-arl/x\r\nLocation: y", "/my-arl/x\n", '' );
$ok     = true;
foreach ( $unsafe as $uri ) {
	$ok = $ok && $login_to( '/my-arl/' ) === SPPA_App_Shell::handle( 'app', 0, '', $uri )['redirect'];
}
sppa_assert( $ok, 'anything that is not a plain /my-arl/ path (dot segments, other paths, encoded or doubled slashes, markup, line breaks, nothing) falls back to /my-arl/' );
sppa_assert( '/my-arl/team/12' === SPPA_App_Shell::return_path( '/my-arl/team/12' ) && '/my-arl' === SPPA_App_Shell::return_path( '/my-arl' ), 'return_path keeps a plain /my-arl path as it is' );

$res = SPPA_App_Shell::handle( 'app', 7, '' );
sppa_assert( 200 === $res['status'] && true === $res['nocache'] && 0 === strpos( $res['headers']['Content-Type'], 'text/html' ), 'logged in: 200 html with no-cache headers' );
sppa_assert( false !== strpos( $res['body'], 'My ARL is not built' ), 'with no build the shell says so (the PWA is a later step)' );

mkdir( $sppa_dir . 'build/.vite', 0777, true );
file_put_contents( $sppa_dir . 'build/.vite/manifest.json', wp_json_encode( $fixture ) );
$res = SPPA_App_Shell::handle( 'app', 7, '' );
sppa_assert( false !== strpos( $res['body'], SPPA_PLUGIN_URL . 'build/assets/index-abc.js' ) && false !== strpos( $res['body'], 'window.SPPA_BOOT' ), 'with a built manifest the shell has the entry script and the boot data' );
preg_match( '#window\.SPPA_BOOT = (\{.*?\});</script>#s', $res['body'], $m );
$b = json_decode( $m[1], true );
sppa_assert( 'nonce-for-wp_rest' === $b['nonce'], 'boot nonce is the wp_rest nonce' );
sppa_assert( 'https://arl.example.test/wp-json/sppa/v1/' === $b['restRoot'], 'restRoot is the sppa/v1 root' );
sppa_assert( 'https://arl.example.test/wp-admin/admin-ajax.php' === $b['ajaxUrl'], 'ajaxUrl' );
sppa_assert( '/my-arl/' === $b['appBase'] && 'ARL Hockey' === $b['siteName'], 'appBase and siteName' );
sppa_assert( 'https://help.arl.hockey/' === $b['helpUrl'], 'helpUrl default' );
add_filter( 'sppa_help_url', function () { return 'https://example.test/help'; } );
$b2 = SPPA_App_Shell::boot_data();
sppa_assert( 'https://example.test/help' === $b2['helpUrl'], 'helpUrl passes through the sppa_help_url filter' );
sppa_assert( false !== strpos( $b['loginUrl'], 'wp-login.php' ) && false !== strpos( $b['logoutUrl'], 'action=logout' ) && '' !== $b['accountUrl'], 'login, logout and account URLs are present' );
sppa_assert( array( 'restRoot', 'nonce', 'ajaxUrl', 'loginUrl', 'logoutUrl', 'accountUrl', 'helpUrl', 'appBase', 'appName', 'siteName' ) === array_keys( $b ), 'boot has exactly the contract keys' );
$GLOBALS['sppa_filters'] = array();

echo "\n=== handle: service worker and manifest ===\n\n";

$res = SPPA_App_Shell::handle( 'sw', 0, '' );
sppa_assert( 200 === $res['status'] && 'application/javascript; charset=utf-8' === $res['headers']['Content-Type'] && '/my-arl/' === $res['headers']['Service-Worker-Allowed'] && 'no-cache' === $res['headers']['Cache-Control'], 'sw: headers (works logged out)' );
sppa_assert( "self.addEventListener('install',()=>self.skipWaiting());" === $res['body'], 'sw: no build/sw.js serves the no-op worker' );
file_put_contents( $sppa_dir . 'build/sw.js', "p('__SPPA_BUILD_URL__assets/a.js');" );
$res = SPPA_App_Shell::handle( 'sw', 0, '' );
sppa_assert( "p('" . SPPA_PLUGIN_URL . "build/assets/a.js');" === $res['body'], 'sw: serves build/sw.js with the build URL filled in' );
sppa_assert( 'nosniff' === ( $res['headers']['X-Content-Type-Options'] ?? '' ), 'sw: nosniff' );

$res = SPPA_App_Shell::handle( 'manifest', 0, '' );
sppa_assert( 200 === $res['status'] && 'application/manifest+json' === $res['headers']['Content-Type'], 'manifest: 200 with the manifest media type' );
sppa_assert( 'My ARL' === ( json_decode( $res['body'], true )['name'] ?? '' ), 'manifest: body is the JSON manifest' );

sppa_assert( null === SPPA_App_Shell::handle( '', 0, '' ) && null === SPPA_App_Shell::handle( 'nope', 7, '' ), 'an unknown route is not handled (the request carries on)' );

echo "\n=== handle: calendar feed ===\n\n";

sppa_reset();
$GLOBALS['sppa_options']['sportspress_season'] = 674;
sppa_register_term( 674, 'sp_season', '2026' );
foreach ( array( 12 => 'Ducks', 7 => 'Knights of Ni', 9 => 'Bears', 8 => 'Wolves' ) as $id => $name ) {
	sppa_add_post( $id, 'sp_team', $name );
}
sppa_add_post( 50, 'sp_player', 'Pat Skater' );
sppa_add_meta( 50, 'sp_user', '7' );
sppa_add_meta( 50, 'sp_current_team', '12' );
sppa_add_post( 51, 'sp_player', 'Pat Goalie' );
sppa_add_meta( 51, 'sp_user', '7' );
sppa_add_meta( 51, 'sp_current_team', '7' );
sppa_add_user( 7, 'pat@example.test', 'Pat Skater' );
$token                                    = 'AbCdEfGhIjKlMnOpQrStUvWxYz012345';
$GLOBALS['sppa_user_meta'][7]['sppa_ical_token'] = $token;

sppa_event_at( 101, '2026-11-04 21:45:00', 'future', array( 12, 7 ) );
sppa_set_terms( 101, 'sp_season', array( 674 => '2026' ) );
sppa_event_at( 103, '2026-11-11 21:45:00', 'draft', array( 12, 9 ) );
sppa_set_terms( 103, 'sp_season', array( 674 => '2026' ) );
sppa_add_meta( 103, '_spem_cancelled', '1' );
sppa_event_at( 104, '2026-11-18 21:45:00', 'draft', array( 12, 9 ) );
sppa_set_terms( 104, 'sp_season', array( 674 => '2026' ) );
sppa_event_at( 102, '2026-11-06 20:00:00', 'future', array( 9, 8 ) );
sppa_set_terms( 102, 'sp_season', array( 674 => '2026' ) );

$db                  = $GLOBALS['wpdb'];
$db->results_handler = function ( $sql ) {
	if ( false !== strpos( $sql, 'FROM wp_sppa_changes WHERE event_id = 101' ) ) {
		return '2';
	}
	if ( false !== strpos( $sql, 'FROM wp_sppa_spare_responses r' ) ) {
		return array(
			array( 'id' => '5', 'event_id' => '102', 'team_id' => '9', 'position' => 'goalie', 'needed' => '1', 'note' => '', 'status' => 'filled', 'created_by' => '3', 'created_at' => '2026-11-01 12:00:00', 'updated_at' => '2026-11-01 12:00:00', 'player_id' => '50' ),
		);
	}
	return array();
};

$res  = SPPA_App_Shell::handle( 'ical', 0, $token );
$body = $res['body'];
sppa_assert( 200 === $res['status'] && 'text/calendar; charset=utf-8' === $res['headers']['Content-Type'], 'feed: 200 text/calendar' );
sppa_assert( 'inline; filename="my-arl.ics"' === $res['headers']['Content-Disposition'], 'feed: inline with an my-arl.ics filename (the URL has no extension)' );
sppa_assert( 0 === strpos( $body, "BEGIN:VCALENDAR\r\n" ), 'feed: a VCALENDAR' );
sppa_assert( 1 === substr_count( $body, 'UID:sppa-101-' ) && false !== strpos( $body, 'UID:sppa-101-12@arl.example.test' ), 'feed: a game between two of the user\'s own teams is listed once, from the home side' );
sppa_assert( false !== strpos( $body, 'SEQUENCE:2' ), 'feed: SEQUENCE is the event\'s change-row count' );
sppa_assert( false !== strpos( $body, 'UID:sppa-103-12@' ) && 1 === preg_match( '/UID:sppa-103-12@[^\r]*\r\n(?:[^\r]*\r\n)*?STATUS:CANCELLED/', $body ), 'feed: a cancelled (drafted) game is listed as STATUS:CANCELLED' );
sppa_assert( false === strpos( $body, 'UID:sppa-104-' ), 'feed: an unfinished draft is not listed' );
sppa_assert( false !== strpos( $body, 'UID:sppa-102-9@arl.example.test' ), 'feed: a game the user accepted as a spare is listed (for the calling team)' );
sppa_assert( 'sppa_ical_7_0' === $GLOBALS['sppa_transient_writes'][0][0] && 600 === $GLOBALS['sppa_transient_writes'][0][1], 'feed: cached in sppa_ical_{user}_{generation} for 10 minutes' );
sppa_assert( $body === $GLOBALS['sppa_transients']['sppa_ical_7_0'], 'feed: the cached body is the body sent' );

$queries_before = count( $db->queries );
$GLOBALS['sppa_transients']['sppa_ical_7_0'] = "BEGIN:VCALENDAR\r\nCACHED\r\nEND:VCALENDAR\r\n";
$res = SPPA_App_Shell::handle( 'ical', 0, $token );
sppa_assert( false !== strpos( $res['body'], 'CACHED' ) && count( $db->queries ) === $queries_before, 'feed: a cached body is served without rebuilding' );

update_option( 'sppa_generation', 3 );
$res = SPPA_App_Shell::handle( 'ical', 0, $token );
sppa_assert( false === strpos( $res['body'], 'CACHED' ) && isset( $GLOBALS['sppa_transients']['sppa_ical_7_3'] ), 'feed: a new generation misses the cache and writes under the new key' );

echo "\n--- unknown and malformed tokens are 404 and nothing more ---\n\n";

$unknown = SPPA_App_Shell::handle( 'ical', 0, str_repeat( 'z', 32 ) );
$short   = SPPA_App_Shell::handle( 'ical', 0, 'abc' );
$empty   = SPPA_App_Shell::handle( 'ical', 0, '' );
$inject  = SPPA_App_Shell::handle( 'ical', 0, "' OR 1=1 -- " . str_repeat( 'a', 20 ) );
foreach ( array( 'unknown' => $unknown, 'short' => $short, 'empty' => $empty, 'injection' => $inject ) as $label => $r ) {
	sppa_assert( 404 === $r['status'], "{$label} token: 404 (never 401/403)" );
	sppa_assert( empty( $r['redirect'] ) && false === strpos( $r['body'], 'BEGIN:VCALENDAR' ), "{$label} token: no redirect, no calendar" );
}
sppa_assert( $unknown['body'] === $short['body'] && $unknown['headers'] === $short['headers'], 'an unknown and a malformed token are indistinguishable' );
$res = SPPA_App_Shell::handle( 'ical', 7, str_repeat( 'z', 32 ) );
sppa_assert( 404 === $res['status'], 'being logged in does not make a wrong token work' );
$res = SPPA_App_Shell::handle( 'ical', 0, strtolower( $token ) );
sppa_assert( 404 === $res['status'], 'a token with the wrong case is 404 (exact match only)' );

echo "\n=== base path and display name ===\n\n";

sppa_assert( 'my-arl' === SPPA_App_Shell::base_path() && '/my-arl/' === SPPA_App_Shell::base_url(), 'the URL base defaults to my-arl' );
add_filter( 'sppa_base_path', function () { return '/league-app/'; } );
sppa_assert( 'league-app' === SPPA_App_Shell::base_path() && '/league-app/' === SPPA_App_Shell::base_url(), 'the sppa_base_path filter sets the base (slashes trimmed)' );
$custom = SPPA_App_Shell::rules();
sppa_assert( isset( $custom['^league-app(?:/.*)?$'], $custom['^league-app/sw/?$'], $custom['^league-app/manifest/?$'] ) && ! isset( $custom['^my-arl(?:/.*)?$'] ), 'every rewrite rule follows the base' );
$mf_custom = SPPA_App_Shell::manifest_data();
sppa_assert( '/league-app/' === $mf_custom['id'] && '/league-app/' === $mf_custom['start_url'] && '/league-app/' === $mf_custom['scope'], 'manifest id, start_url and scope follow the base' );
$res = SPPA_App_Shell::handle( 'sw', 0, '' );
sppa_assert( '/league-app/' === $res['headers']['Service-Worker-Allowed'], 'Service-Worker-Allowed follows the base' );
sppa_assert( '/league-app/' === SPPA_App_Shell::boot_data()['appBase'], 'boot appBase follows the base' );
sppa_assert( '/league-app/games/4' === SPPA_App_Shell::return_path( '/league-app/games/4' ) && '/league-app/' === SPPA_App_Shell::return_path( '/my-arl/games/4' ), 'login return paths are validated against the base' );
sppa_assert( 'https://arl.example.test/league-app/ical/abc' === SPPA_Ical::feed_url( 'abc' ), 'the feed URL follows the base' );
$GLOBALS['sppa_filters'] = array();
foreach ( array( '', '/', 'My ARL', 'a/b', 'UPPER', '../x', 'a b', '<x>' ) as $bad ) {
	add_filter( 'sppa_base_path', function () use ( $bad ) { return $bad; } );
	sppa_assert( 'my-arl' === SPPA_App_Shell::base_path(), 'an unusable base (' . $bad . ') falls back to my-arl' );
	$GLOBALS['sppa_filters'] = array();
}

sppa_assert( 'My ARL' === SPPA_App_Shell::app_name(), 'the display name defaults to My ARL' );
update_option( 'sppa_app_name', '  Ducks <b>Hub</b>  ' );
sppa_assert( 'Ducks Hub' === SPPA_App_Shell::app_name(), 'the display name is sanitised plain text' );
update_option( 'sppa_app_name', str_repeat( 'x', 45 ) );
sppa_assert( str_repeat( 'x', 30 ) === SPPA_App_Shell::app_name(), 'the display name is capped at 30 characters' );
update_option( 'sppa_app_name', '   ' );
sppa_assert( 'My ARL' === SPPA_App_Shell::app_name(), 'a blank display name falls back to the default' );
update_option( 'sppa_app_name', 'Ducks Hub' );
$mf_named = SPPA_App_Shell::manifest_data();
sppa_assert( 'Ducks Hub' === $mf_named['name'] && 'Ducks Hub' === $mf_named['short_name'], 'manifest name and short_name use the display name' );
sppa_assert( 'Ducks Hub' === SPPA_App_Shell::boot_data()['appName'], 'boot appName is the display name' );
$named_html = SPPA_App_Shell::shell_html( $fixture, $boot );
sppa_assert( false !== strpos( $named_html, '<title>Ducks Hub</title>' ) && false !== strpos( $named_html, 'content="Ducks Hub"' ), 'the page title and home-screen title use the display name' );
delete_option( 'sppa_app_name' );

sppa_done();
