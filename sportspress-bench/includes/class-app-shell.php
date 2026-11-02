<?php
/**
 * The /bench/ front door: rewrites, the HTML shell that boots the app, the
 * service worker and manifest routes, and the token-authenticated calendar feed.
 *
 * Every route is decided by handle(), which returns a response descriptor
 * ({status, headers, body, nocache?, redirect?}) instead of printing, so the
 * routing, headers and bodies are unit-tested. dispatch() is the thin part that
 * turns a descriptor into output.
 *
 * Built assets live in the plugin's build/ directory (committed). The Vite
 * manifest at build/.vite/manifest.json says which hashed files the shell loads;
 * build/sw.js is the Workbox service worker, whose precache URLs carry the
 * literal prefix __SPBN_BUILD_URL__ that sw_body() swaps for the real URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_App_Shell {

	const QUERY_VAR = 'spbn';

	const TOKEN_VAR = 'spbn_token';

	const REWRITE_OPTION = 'spbn_rewrite_version';

	/** The literal the PWA's Workbox config puts in front of every precache URL. */
	const BUILD_URL_TOKEN = '__SPBN_BUILD_URL__';

	/** The calendar feed is rebuilt at most this often per user and generation. */
	const FEED_TTL_SECONDS = 600;

	const NOOP_SW = "self.addEventListener('install',()=>self.skipWaiting());";

	const THEME_COLOR = '#132343';

	const BACKGROUND_COLOR = '#F7FBFC';

	/**
	 * Hook everything: the rewrite rules, the one-time flush, the query vars and
	 * the early template_redirect dispatcher.
	 */
	public static function register(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrites' ), 10 );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'dispatch' ), 1 );
	}

	/**
	 * The four /bench/ rules (regex => query), specific first and the catch-all
	 * last (rules added to the top keep their order). The dynamic URLs have no
	 * file extension on purpose: a web server's static-file location blocks
	 * (".js", ".webmanifest") answer such paths themselves and never reach
	 * WordPress. The feed also accepts the older ".ics" form.
	 *
	 * @return array<string,string>
	 */
	public static function rules(): array {
		$var = 'index.php?' . self::QUERY_VAR . '=';
		return array(
			'^bench/ical/([A-Za-z0-9]{32})(?:\.ics)?/?$' => $var . 'ical&' . self::TOKEN_VAR . '=$matches[1]',
			'^bench/sw/?$'                               => $var . 'sw',
			'^bench/manifest/?$'                         => $var . 'manifest',
			'^bench(?:/.*)?$'                            => $var . 'app',
		);
	}

	public static function add_rewrites(): void {
		foreach ( self::rules() as $regex => $query ) {
			add_rewrite_rule( $regex, $query, 'top' );
		}
	}

	/**
	 * Flush the rules once per plugin version and rule set: an install that was
	 * updated, enabled without an activation hook, or that predates a rule change
	 * has no current /bench/ rules until it does. Soft flush (database rules
	 * only; no .htaccess write).
	 */
	public static function maybe_flush_rewrites(): void {
		$signature = SPBN_VERSION . '-' . substr( md5( (string) wp_json_encode( self::rules() ) ), 0, 8 );
		if ( get_option( self::REWRITE_OPTION ) === $signature ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( self::REWRITE_OPTION, $signature );
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::QUERY_VAR;
		$vars[] = self::TOKEN_VAR;
		return $vars;
	}

	/**
	 * template_redirect @ 1: answer a /bench/ request and stop; leave anything
	 * else to WordPress.
	 */
	public static function dispatch(): void {
		$route = (string) get_query_var( self::QUERY_VAR );
		if ( '' === $route ) {
			return;
		}
		// Only used as a login return path, and only after return_path() has validated it.
		$uri      = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( (string) $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by return_path().
		$response = self::handle( $route, (int) get_current_user_id(), (string) get_query_var( self::TOKEN_VAR ), $uri );
		if ( null === $response ) {
			return;
		}

		if ( ! empty( $response['redirect'] ) ) {
			wp_safe_redirect( $response['redirect'] );
			exit;
		}
		if ( ! empty( $response['nocache'] ) ) {
			nocache_headers();
		}
		status_header( (int) $response['status'] );
		foreach ( (array) $response['headers'] as $name => $value ) {
			header( $name . ': ' . $value );
		}
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html is escaped in shell_html, the rest is JSON, JavaScript or iCalendar text.
		exit;
	}

	/**
	 * What to send for a route.
	 *
	 * @param string $route           app|sw|manifest|ical.
	 * @param int    $current_user_id The logged-in user, or 0.
	 * @param string $token           The feed token (ical only).
	 * @param string $request_uri     The requested URI (app only: where login returns to).
	 * @return array|null Descriptor, or null for a route that is not ours.
	 */
	public static function handle( string $route, int $current_user_id, string $token, string $request_uri = '' ): ?array {
		switch ( $route ) {
			case 'app':
				return self::app_response( $current_user_id, $request_uri );
			case 'sw':
				return array(
					'status'  => 200,
					'headers' => array(
						'Content-Type'           => 'application/javascript; charset=utf-8',
						'Service-Worker-Allowed' => '/bench/',
						'Cache-Control'          => 'no-cache',
						'X-Content-Type-Options' => 'nosniff',
					),
					'body'    => self::sw_body( self::read_build_file( 'sw.js' ) ),
				);
			case 'manifest':
				return array(
					'status'  => 200,
					'headers' => array(
						'Content-Type'           => 'application/manifest+json',
						'Cache-Control'          => 'no-cache',
						'X-Content-Type-Options' => 'nosniff',
					),
					'body'    => (string) wp_json_encode( self::manifest_data() ),
				);
			case 'ical':
				return self::feed_response( $token );
		}
		return null;
	}

	/**
	 * The HTML document that boots the app. Pure.
	 *
	 * Reads the entry record keyed "index.html" from the Vite manifest and links
	 * its script, its stylesheets and the chunks it imports. Without an entry
	 * (the app has not been built) it is a minimal page that says so and carries
	 * no boot data.
	 *
	 * @param array $vite_manifest Decoded build/.vite/manifest.json.
	 * @param array $boot          The window.SPBN_BOOT object.
	 */
	public static function shell_html( array $vite_manifest, array $boot ): string {
		$entry = $vite_manifest['index.html'] ?? null;
		if ( ! is_array( $entry ) || ! is_string( $entry['file'] ?? null ) || ! self::is_build_path( $entry['file'] ) ) {
			return self::not_built_html();
		}

		$build   = SPBN_PLUGIN_URL . 'build/';
		$scripts = array();
		$styles  = array();
		$preload = array();
		self::collect( $vite_manifest, 'index.html', $scripts, $styles, $preload, array() );

		$head   = array();
		$head[] = '<meta charset="utf-8">';
		$head[] = '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">';
		$head[] = '<meta name="theme-color" content="' . self::THEME_COLOR . '">';
		$head[] = '<meta name="robots" content="noindex, nofollow">';
		$head[] = '<meta name="mobile-web-app-capable" content="yes">';
		$head[] = '<meta name="apple-mobile-web-app-capable" content="yes">';
		$head[] = '<meta name="apple-mobile-web-app-title" content="Bench">';
		$head[] = '<title>' . esc_html__( 'ARL Bench', 'sportspress-bench' ) . '</title>';
		$head[] = '<link rel="manifest" href="/bench/manifest">';
		$head[] = '<link rel="apple-touch-icon" href="' . esc_url( $build . 'icons/icon-192.png' ) . '">';
		foreach ( $styles as $css ) {
			$head[] = '<link rel="stylesheet" href="' . esc_url( $build . $css ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page, not a theme template; wp_enqueue_* never prints here.
		}
		foreach ( $preload as $file ) {
			$head[] = '<link rel="modulepreload" href="' . esc_url( $build . $file ) . '">';
		}
		$head[] = '<script>window.SPBN_BOOT = ' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>';
		foreach ( $scripts as $file ) {
			$head[] = '<script type="module" crossorigin src="' . esc_url( $build . $file ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page, not a theme template; wp_enqueue_* never prints here.
		}

		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n" . implode( "\n", $head ) . "\n</head>\n<body>\n"
			. '<div id="root"></div>' . "\n"
			. '<noscript>' . esc_html__( 'Bench needs JavaScript to run.', 'sportspress-bench' ) . '</noscript>' . "\n"
			. "</body>\n</html>\n";
	}

	/**
	 * The service worker source with the precache prefix filled in; a no-op
	 * worker when build/sw.js does not exist yet.
	 */
	public static function sw_body( ?string $source ): string {
		if ( null === $source ) {
			return self::NOOP_SW;
		}
		return str_replace( self::BUILD_URL_TOKEN, SPBN_PLUGIN_URL . 'build/', $source );
	}

	/**
	 * The web app manifest.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest_data(): array {
		$icons = SPBN_PLUGIN_URL . 'build/icons/';
		return array(
			'id'               => '/bench/',
			'name'             => 'ARL Bench',
			'short_name'       => 'Bench',
			'description'      => 'Your ARL schedule, availability and spare calls.',
			'lang'             => 'en',
			'start_url'        => '/bench/',
			'scope'            => '/bench/',
			'display'          => 'standalone',
			'orientation'      => 'portrait',
			'theme_color'      => self::THEME_COLOR,
			'background_color' => self::BACKGROUND_COLOR,
			'icons'            => array(
				array(
					'src'     => $icons . 'icon-192.png',
					'sizes'   => '192x192',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $icons . 'icon-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'any',
				),
				array(
					'src'     => $icons . 'icon-maskable-512.png',
					'sizes'   => '512x512',
					'type'    => 'image/png',
					'purpose' => 'maskable',
				),
			),
		);
	}

	/**
	 * What the app reads from window.SPBN_BOOT.
	 *
	 * @return array<string,string>
	 */
	public static function boot_data(): array {
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		return array(
			'restRoot'   => esc_url_raw( rest_url( 'spbn/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'loginUrl'   => wp_login_url( home_url( '/bench/' ) ),
			'logoutUrl'  => wp_logout_url( wp_login_url( home_url( '/bench/' ) ) ),
			'accountUrl' => '' !== $account ? $account : home_url( '/my-account/' ),
			'helpUrl'    => (string) apply_filters( 'spbn_help_url', 'https://help.arl.hockey/' ),
			'appBase'    => '/bench/',
			'siteName'   => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * The app page to come back to after logging in: the request's path when it
	 * is a plain /bench path (letters, digits, '-', '_' segments; no dot
	 * segments, encoded characters, doubled slashes or anything else), else
	 * /bench/. Query string and fragment are dropped. Always a path, so the
	 * caller puts it on this site's own URL. Pure.
	 */
	public static function return_path( string $request_uri ): string {
		// parse_url() turns control characters into '_'; refuse them outright instead.
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $request_uri ) ) {
			return '/bench/';
		}
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		// A site installed in a subdirectory serves /bench/ under its home path.
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		return 1 === preg_match( '#^/bench(?:/[A-Za-z0-9_-]+)*/?\z#', $path ) ? $path : '/bench/';
	}

	// ---- Internals ----

	private static function app_response( int $user_id, string $request_uri ): array {
		if ( $user_id <= 0 ) {
			return array(
				'status'   => 302,
				'headers'  => array(),
				'body'     => '',
				'redirect' => wp_login_url( home_url( self::return_path( $request_uri ) ) ),
			);
		}

		return array(
			'status'  => 200,
			'nocache' => true,
			'headers' => array(
				'Content-Type'           => 'text/html; charset=utf-8',
				'X-Robots-Tag'           => 'noindex, nofollow',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => self::shell_html( self::read_vite_manifest(), self::boot_data() ),
		);
	}

	/**
	 * The feed for a token, or the same plain 404 for any token that is not a
	 * live one (unknown, malformed, rotated away): the answer never says whether
	 * a token ever existed.
	 */
	private static function feed_response( string $token ): array {
		$user_id = SPBN_Ical::user_for_token( $token );
		if ( $user_id <= 0 ) {
			return array(
				'status'  => 404,
				'headers' => array(
					'Content-Type'  => 'text/plain; charset=utf-8',
					'Cache-Control' => 'no-store',
				),
				'body'    => 'Not found',
			);
		}

		return array(
			'status'  => 200,
			'headers' => array(
				'Content-Type'           => 'text/calendar; charset=utf-8',
				'Cache-Control'          => 'private, max-age=0, must-revalidate',
				'Content-Disposition'    => 'inline; filename="arl-bench.ics"',
				'X-Robots-Tag'           => 'noindex, nofollow',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => self::feed_body( $user_id ),
		);
	}

	/**
	 * The user's calendar: their teams' season games plus games they accepted as
	 * a spare, cached ten minutes under spbn_ical_{user}_{generation} (a logged
	 * schedule change bumps the generation and the next fetch rebuilds).
	 */
	private static function feed_body( int $user_id ): string {
		$key    = 'spbn_ical_' . $user_id . '_' . (int) get_option( 'spbn_generation', 0 );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$games = array();
		foreach ( SPBN_REST::entries_for_user( new SPBN_Roster_Reader(), $user_id ) as $entry ) {
			$game             = SPBN_Event_Presenter::present( $entry['raw'], $entry['team_id'], array( 'as_spare' => $entry['as_spare'] ) );
			$game['sequence'] = SPBN_Changes::count_for( (int) $game['id'] );
			$games[]          = $game;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$body = SPBN_Ical::render( $games, $host, __( 'ARL Bench', 'sportspress-bench' ) );
		set_transient( $key, $body, self::FEED_TTL_SECONDS );
		return $body;
	}

	/**
	 * Contents of a file under build/, or null when it is not there.
	 */
	private static function read_build_file( string $relative ): ?string {
		$path = SPBN_PLUGIN_PATH . 'build/' . $relative;
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}
		$body = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local build artifact, not a remote URL.
		return false === $body ? null : $body;
	}

	/**
	 * The decoded Vite manifest, or an empty array when it is missing or broken.
	 */
	private static function read_vite_manifest(): array {
		$json = self::read_build_file( '.vite/manifest.json' );
		if ( null === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Gather the entry's script, stylesheets and imported chunks (depth first,
	 * each once). Entries whose paths are not plain relative build paths are
	 * left out.
	 *
	 * @param array    $manifest Vite manifest.
	 * @param string   $key      Record to read.
	 * @param string[] $scripts  Entry script (out).
	 * @param string[] $styles   Stylesheets (out).
	 * @param string[] $preload  Imported chunk files (out).
	 * @param string[] $seen     Keys already visited.
	 */
	private static function collect( array $manifest, string $key, array &$scripts, array &$styles, array &$preload, array $seen ): void {
		$record = $manifest[ $key ] ?? null;
		if ( ! is_array( $record ) || in_array( $key, $seen, true ) ) {
			return;
		}
		$seen[] = $key;

		foreach ( (array) ( $record['imports'] ?? array() ) as $import ) {
			if ( ! is_string( $import ) ) {
				continue;
			}
			$chunk = $manifest[ $import ] ?? null;
			if ( is_array( $chunk ) && is_string( $chunk['file'] ?? null ) && self::is_build_path( $chunk['file'] ) && ! in_array( $chunk['file'], $preload, true ) ) {
				$preload[] = $chunk['file'];
			}
			self::collect( $manifest, $import, $scripts, $styles, $preload, $seen );
		}
		foreach ( (array) ( $record['css'] ?? array() ) as $css ) {
			if ( is_string( $css ) && self::is_build_path( $css ) && ! in_array( $css, $styles, true ) ) {
				$styles[] = $css;
			}
		}
		if ( 'index.html' === $key && is_string( $record['file'] ?? null ) && self::is_build_path( $record['file'] ) ) {
			$scripts[] = $record['file'];
		}
	}

	/**
	 * A relative path of safe characters with no dot-dot segment.
	 */
	private static function is_build_path( string $path ): bool {
		return 1 === preg_match( '#^[A-Za-z0-9_][A-Za-z0-9_./-]*$#', $path ) && false === strpos( $path, '..' );
	}

	private static function not_built_html(): string {
		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<meta name="robots" content="noindex, nofollow">' . "\n"
			. '<title>' . esc_html__( 'ARL Bench', 'sportspress-bench' ) . "</title>\n</head>\n<body>\n<p>"
			. esc_html__( 'Bench is not built yet. Ask the site administrator to install a release build.', 'sportspress-bench' )
			. "</p>\n</body>\n</html>\n";
	}
}
