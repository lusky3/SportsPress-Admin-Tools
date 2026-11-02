<?php
/**
 * The app's front door (/my-arl/ by default): rewrites, the HTML shell that
 * boots the app, the service worker and manifest routes, and the
 * token-authenticated calendar feed.
 *
 * Two site settings shape what players see. The URL base comes from the
 * sppa_base_path filter (default "my-arl"; base_path() is the one place it is
 * read) and the app's display name from the sppa_app_name option (default
 * "My ARL"; app_name()).
 *
 * Every route is decided by handle(), which returns a response descriptor
 * ({status, headers, body, nocache?, redirect?}) instead of printing, so the
 * routing, headers and bodies are unit-tested. dispatch() is the thin part that
 * turns a descriptor into output.
 *
 * Built assets live in the plugin's build/ directory (committed). The Vite
 * manifest at build/.vite/manifest.json says which hashed files the shell loads;
 * build/sw.js is the Workbox service worker, whose precache URLs carry the
 * literal prefix __SPPA_BUILD_URL__ that sw_body() swaps for the real URL.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_App_Shell {

	const QUERY_VAR = 'sppa';

	const TOKEN_VAR = 'sppa_token';

	const REWRITE_OPTION = 'sppa_rewrite_version';

	/** The literal the PWA's Workbox config puts in front of every precache URL. */
	const BUILD_URL_TOKEN = '__SPPA_BUILD_URL__';

	/** The URL base when the sppa_base_path filter gives nothing usable. */
	const DEFAULT_BASE = 'my-arl';

	/** The option that holds the name players see, and its default and length cap. */
	const NAME_OPTION = 'sppa_app_name';

	const DEFAULT_NAME = 'My ARL';

	const NAME_MAX = 30;

	/** The calendar feed is rebuilt at most this often per user and generation. */
	const FEED_TTL_SECONDS = 600;

	const NOOP_SW = "self.addEventListener('install',()=>self.skipWaiting());";

	const THEME_COLOR = '#132343';

	const BACKGROUND_COLOR = '#F7FBFC';

	/**
	 * The URL segment the app lives under, with no slashes: the sppa_base_path
	 * filter's value (default "my-arl") when it is lower-case letters, digits
	 * and hyphens, else the default. Every route, link and header reads it here.
	 */
	public static function base_path(): string {
		$base = trim( (string) apply_filters( 'sppa_base_path', self::DEFAULT_BASE ), '/' );
		return 1 === preg_match( '/^[a-z0-9-]+$/D', $base ) ? $base : self::DEFAULT_BASE;
	}

	/**
	 * The app's root as a site-relative path with both slashes: "/my-arl/".
	 */
	public static function base_url(): string {
		return '/' . self::base_path() . '/';
	}

	/**
	 * The name players see: the sppa_app_name option, plain text, at most 30
	 * characters; "My ARL" when it is empty.
	 */
	public static function app_name(): string {
		$name = trim( sanitize_text_field( (string) get_option( self::NAME_OPTION, self::DEFAULT_NAME ) ) );
		$name = trim( mb_substr( $name, 0, self::NAME_MAX ) );
		return '' !== $name ? $name : self::DEFAULT_NAME;
	}

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
	 * The four rules under base_path() (regex => query), specific first and the catch-all
	 * last (rules added to the top keep their order). The dynamic URLs have no
	 * file extension on purpose: a web server's static-file location blocks
	 * (".js", ".webmanifest") answer such paths themselves and never reach
	 * WordPress. The feed also accepts the older ".ics" form.
	 *
	 * @return array<string,string>
	 */
	public static function rules(): array {
		$var  = 'index.php?' . self::QUERY_VAR . '=';
		$base = self::base_path(); // [a-z0-9-] only (see base_path), so it is regex-safe as is.
		return array(
			'^' . $base . '/ical/([A-Za-z0-9]{32})(?:\.ics)?/?$' => $var . 'ical&' . self::TOKEN_VAR . '=$matches[1]',
			'^' . $base . '/sw/?$'                               => $var . 'sw',
			'^' . $base . '/manifest/?$'                         => $var . 'manifest',
			'^' . $base . '(?:/.*)?$'                            => $var . 'app',
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
	 * has no current rules until it does. Soft flush (database rules
	 * only; no .htaccess write).
	 */
	public static function maybe_flush_rewrites(): void {
		$signature = SPPA_VERSION . '-' . substr( md5( (string) wp_json_encode( self::rules() ) ), 0, 8 );
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
	 * template_redirect @ 1: answer an app request and stop; leave anything
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
						'Service-Worker-Allowed' => self::base_url(),
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
	 * @param array $boot          The window.SPPA_BOOT object.
	 */
	public static function shell_html( array $vite_manifest, array $boot ): string {
		$entry = $vite_manifest['index.html'] ?? null;
		if ( ! is_array( $entry ) || ! is_string( $entry['file'] ?? null ) || ! self::is_build_path( $entry['file'] ) ) {
			return self::not_built_html();
		}

		$name    = self::app_name();
		$build   = SPPA_PLUGIN_URL . 'build/';
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
		$head[] = '<meta name="apple-mobile-web-app-title" content="' . esc_attr( $name ) . '">';
		$head[] = '<title>' . esc_html( $name ) . '</title>';
		$head[] = '<link rel="manifest" href="' . esc_url( self::base_url() . 'manifest' ) . '">';
		$head[] = '<link rel="apple-touch-icon" href="' . esc_url( $build . 'icons/icon-192.png' ) . '">';
		foreach ( $styles as $css ) {
			$head[] = '<link rel="stylesheet" href="' . esc_url( $build . $css ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- standalone page, not a theme template; wp_enqueue_* never prints here.
		}
		foreach ( $preload as $file ) {
			$head[] = '<link rel="modulepreload" href="' . esc_url( $build . $file ) . '">';
		}
		$head[] = '<script>window.SPPA_BOOT = ' . wp_json_encode( $boot, JSON_HEX_TAG | JSON_HEX_AMP ) . ';</script>';
		foreach ( $scripts as $file ) {
			$head[] = '<script type="module" crossorigin src="' . esc_url( $build . $file ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- standalone page, not a theme template; wp_enqueue_* never prints here.
		}

		/* translators: %s: the app's name, "My ARL" by default. */
		$needs_js = sprintf( __( '%s needs JavaScript to run.', 'sportspress-player-app' ), $name );

		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n" . implode( "\n", $head ) . "\n</head>\n<body>\n"
			. '<div id="root"></div>' . "\n"
			. '<noscript>' . esc_html( $needs_js ) . '</noscript>' . "\n"
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
		return str_replace( self::BUILD_URL_TOKEN, SPPA_PLUGIN_URL . 'build/', $source );
	}

	/**
	 * The web app manifest.
	 *
	 * @return array<string,mixed>
	 */
	public static function manifest_data(): array {
		$icons = SPPA_PLUGIN_URL . 'build/icons/';
		$name  = self::app_name();
		$base  = self::base_url();
		return array(
			'id'               => $base,
			'name'             => $name,
			'short_name'       => $name,
			'description'      => 'Your ARL schedule, availability and spare calls.',
			'lang'             => 'en',
			'start_url'        => $base,
			'scope'            => $base,
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
	 * What the app reads from window.SPPA_BOOT.
	 *
	 * @return array<string,string>
	 */
	public static function boot_data(): array {
		$base    = self::base_url();
		$account = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : '';
		return array(
			'restRoot'   => esc_url_raw( rest_url( 'sppa/v1/' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'loginUrl'   => wp_login_url( home_url( $base ) ),
			'logoutUrl'  => wp_logout_url( wp_login_url( home_url( $base ) ) ),
			'accountUrl' => '' !== $account ? $account : home_url( '/my-account/' ),
			'helpUrl'    => (string) apply_filters( 'sppa_help_url', 'https://help.arl.hockey/' ),
			'appBase'    => $base,
			'appName'    => self::app_name(),
			'siteName'   => (string) get_bloginfo( 'name' ),
		);
	}

	/**
	 * The app page to come back to after logging in: the request's path when it
	 * is a plain app path under base_path() (letters, digits, '-', '_' segments;
	 * no dot segments, encoded characters, doubled slashes or anything else),
	 * else the app's root. Query string and fragment are dropped. Always a path, so the
	 * caller puts it on this site's own URL. Pure.
	 */
	public static function return_path( string $request_uri ): string {
		$root = self::base_url();
		// parse_url() turns control characters into '_'; refuse them outright instead.
		if ( 1 === preg_match( '/[\x00-\x1f\x7f]/', $request_uri ) ) {
			return $root;
		}
		$path = wp_parse_url( $request_uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		// A site installed in a subdirectory serves the app under its home path.
		$home = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home && 0 === strpos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}

		$pattern = '#^/' . self::base_path() . '(?:/[A-Za-z0-9_-]+)*/?\z#';
		return 1 === preg_match( $pattern, $path ) ? $path : $root;
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
		$user_id = SPPA_Ical::user_for_token( $token );
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
				'Content-Disposition'    => 'inline; filename="' . sanitize_title( self::app_name() ) . '.ics"',
				'X-Robots-Tag'           => 'noindex, nofollow',
				'X-Content-Type-Options' => 'nosniff',
			),
			'body'    => self::feed_body( $user_id ),
		);
	}

	/**
	 * The user's calendar: their teams' season games plus games they accepted as
	 * a spare, cached ten minutes under sppa_ical_{user}_{generation} (a logged
	 * schedule change bumps the generation and the next fetch rebuilds).
	 */
	private static function feed_body( int $user_id ): string {
		$key    = 'sppa_ical_' . $user_id . '_' . (int) get_option( 'sppa_generation', 0 );
		$cached = get_transient( $key );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$games = array();
		foreach ( SPPA_REST::entries_for_user( new SPPA_Roster_Reader(), $user_id ) as $entry ) {
			$game             = SPPA_Event_Presenter::present( $entry['raw'], $entry['team_id'], array( 'as_spare' => $entry['as_spare'] ) );
			$game['sequence'] = SPPA_Changes::count_for( (int) $game['id'] );
			$games[]          = $game;
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$body = SPPA_Ical::render( $games, $host, self::app_name() );
		set_transient( $key, $body, self::FEED_TTL_SECONDS );
		return $body;
	}

	/**
	 * Contents of a file under build/, or null when it is not there.
	 */
	private static function read_build_file( string $relative ): ?string {
		$path = SPPA_PLUGIN_PATH . 'build/' . $relative;
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
		/* translators: %s: the app's name, "My ARL" by default. */
		$message = sprintf( __( '%s is not built yet. Ask the site administrator to install a release build.', 'sportspress-player-app' ), self::app_name() );

		return "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n"
			. '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n"
			. '<meta name="robots" content="noindex, nofollow">' . "\n"
			. '<title>' . esc_html( self::app_name() ) . "</title>\n</head>\n<body>\n<p>"
			. esc_html( $message )
			. "</p>\n</body>\n</html>\n";
	}
}
