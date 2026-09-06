<?php
/**
 * Native WordPress updates for the suite, served from GitHub releases.
 *
 * These plugins are not on wordpress.org, so WordPress has nothing to check
 * them against and the Plugins screen has always shown them as current no
 * matter how far behind they were. This makes them update like any other
 * plugin: an "update available" notice, the "View details" modal, one-click
 * install, and auto-updates if the site has them on.
 *
 * WHY A MANIFEST, AND NOT THE TAG
 *
 * The repository tags one release for eight independently-versioned plugins —
 * v1.1.0 shipped sportspress-admin-tools 1.0.5 alongside six plugins at 1.1.0.
 * A plugin therefore cannot learn its own version from the tag it appears in.
 * The release publishes a manifest.json asset that says, for each plugin, what
 * version that release contains; scripts/build-manifest.php generates it from
 * the same parsing the release guard uses, so the two cannot disagree.
 *
 * One HTTP round trip serves the whole suite: the manifest is fetched once and
 * cached in a site transient, and every watched plugin reads its own entry out
 * of it. Eight plugins do not mean eight requests.
 *
 * WHY BOTH UPDATE PATHS
 *
 * WordPress 5.8+ reads the `Update URI` plugin header and routes the check
 * through `update_plugins_{$hostname}`. That is the native mechanism and it
 * also stops wordpress.org being asked about these plugins at all, which
 * matters: a wordpress.org plugin sharing one of these slugs would otherwise
 * be offered as an update and installed straight over it. Older WordPress
 * ignores the header, so `pre_set_site_transient_update_plugins` covers it,
 * and the slug-collision defence is applied by hand there.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Updater {

	const REPO          = 'lusky3/SportsPress-Admin-Tools';
	const MANIFEST_NAME = 'manifest.json';
	const CACHE_KEY     = 'spat_updater_manifest';
	const CACHE_TTL     = 12 * HOUR_IN_SECONDS;

	/**
	 * A failed lookup is cached too, briefly. This runs on the update check,
	 * which fires on ordinary admin page loads — without a negative cache a
	 * network problem would mean a blocking HTTP attempt on every one of them.
	 */
	const FAILURE_TTL = HOUR_IN_SECONDS;

	/**
	 * Hosts a release asset may legitimately come from.
	 *
	 * The manifest is fetched over HTTPS from this repository's own release,
	 * so it is already trusted; this is the second lock. A download URL is
	 * handed straight to the upgrader, which will unpack it over a live plugin
	 * directory, so it is worth refusing to pass on anything that does not
	 * come from where it should.
	 */
	const PACKAGE_HOSTS = array( 'github.com', 'objects.githubusercontent.com' );

	/** @var array<string,string> Watched plugins: slug => plugin basename. */
	private static array $watched = array();

	/** @var bool Whether the filters have been registered. */
	private static bool $booted = false;

	/**
	 * Put a plugin under the updater's care.
	 *
	 * Called from each plugin's main file with __FILE__. Deliberately cheap:
	 * it records a path and nothing else, so it is safe to call at load time
	 * before WordPress is ready for anything more.
	 *
	 * @param string $plugin_file Absolute path to the plugin's main file.
	 * @return void
	 */
	public static function watch( string $plugin_file ): void {
		$basename = plugin_basename( $plugin_file );
		$slug     = dirname( $basename );

		if ( '.' === $slug ) {
			return;
		}

		self::$watched[ $slug ] = $basename;
		self::boot();
	}

	/**
	 * Register the filters, once, however many plugins are watched.
	 *
	 * @return void
	 */
	private static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_filter( 'update_plugins_github.com', array( __CLASS__, 'filter_update_uri_check' ), 10, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'filter_update_transient' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'filter_plugin_details' ), 10, 3 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	/**
	 * The plugins this updater is responsible for.
	 *
	 * @return array<string,string> slug => plugin basename.
	 */
	public static function watched(): array {
		return self::$watched;
	}

	// ------------------------------------------------------------------
	// Pure decisions. No WordPress, no network — these carry the rules
	// worth pinning down, and tests/test-updater.php drives them directly.
	// ------------------------------------------------------------------

	/**
	 * Whether a tag names a finished release.
	 *
	 * GitHub's "latest release" endpoint already skips releases flagged as
	 * prereleases, but that flag is set by hand and this repository has tagged
	 * release candidates without it — v1.1.0-rc5 is currently the "latest"
	 * release by that definition. Offering a release candidate to a live
	 * league as a routine plugin update is not a mistake worth being one
	 * checkbox away from, so the shape of the tag has to agree as well.
	 *
	 * Accepts v1.2 and v1.2.3, with or without the v. Rejects anything
	 * carrying a suffix: -rc1, -beta, +build.
	 *
	 * @param string $tag Release tag.
	 * @return bool
	 */
	public static function is_release_tag( string $tag ): bool {
		return 1 === preg_match( '/^v?\d+(?:\.\d+){1,2}$/', $tag );
	}

	/**
	 * Whether a package URL may be handed to the upgrader.
	 *
	 * @param string $url Candidate download URL.
	 * @return bool
	 */
	public static function is_trusted_package( string $url ): bool {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		if ( ! in_array( strtolower( $parts['host'] ), self::PACKAGE_HOSTS, true ) ) {
			return false;
		}
		// A host on the allowlist is not enough: both of them serve every
		// public repository on GitHub, so the path has to name this one.
		return 0 === strpos( $parts['path'], '/' . self::REPO . '/releases/download/' );
	}

	/**
	 * Whether an available version should be offered over an installed one.
	 *
	 * Anything missing means no update. In particular an empty available
	 * version must never win: version_compare( "", "1.1.0", ">" ) is false,
	 * but relying on that leaves the intent to a coercion rule rather than
	 * stating it.
	 *
	 * @param string $installed Installed version.
	 * @param string $available Version the release offers.
	 * @return bool
	 */
	public static function is_newer( string $installed, string $available ): bool {
		if ( '' === $installed || '' === $available ) {
			return false;
		}
		return version_compare( $available, $installed, '>' );
	}

	/**
	 * One plugin's entry out of a manifest, or null when it has none.
	 *
	 * A manifest that does not mention a plugin is the normal case for a
	 * release cut before that plugin existed, so it is silence rather than an
	 * error.
	 *
	 * @param array  $manifest Decoded manifest.
	 * @param string $slug     Plugin slug.
	 * @return array|null
	 */
	public static function entry_for( array $manifest, string $slug ): ?array {
		if ( empty( $manifest['plugins'][ $slug ] ) || ! is_array( $manifest['plugins'][ $slug ] ) ) {
			return null;
		}
		$entry = $manifest['plugins'][ $slug ];
		return empty( $entry['version'] ) ? null : $entry;
	}

	/**
	 * The download URL for a plugin's asset in a release.
	 *
	 * Built from the release's own asset list rather than from the tag,
	 * because GitHub's download URLs are not ours to predict and an asset that
	 * failed to upload should read as absent rather than as a URL that 404s
	 * halfway through an upgrade.
	 *
	 * @param array  $assets Release assets, each with name and browser_download_url.
	 * @param string $name   Asset filename to find.
	 * @return string Empty when absent or untrusted.
	 */
	public static function asset_url( array $assets, string $name ): string {
		foreach ( $assets as $asset ) {
			if ( ! is_array( $asset ) || ( $asset['name'] ?? '' ) !== $name ) {
				continue;
			}
			$url = (string) ( $asset['browser_download_url'] ?? '' );
			return self::is_trusted_package( $url ) ? $url : '';
		}
		return '';
	}

	// ------------------------------------------------------------------
	// I/O and the WordPress surface.
	// ------------------------------------------------------------------

	/**
	 * The latest finished release, as { tag, assets, manifest }.
	 *
	 * Cached for both outcomes. A success is good for CACHE_TTL; a failure is
	 * cached briefly under the same key, because this runs on the update check
	 * and that fires on ordinary admin page loads — an unreachable GitHub must
	 * not mean a blocking request on every one of them.
	 *
	 * @return array|null
	 */
	private static function release(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['manifest'] ) ? null : $cached;
		}

		$release = self::fetch_json( 'https://api.github.com/repos/' . self::REPO . '/releases/latest' );

		if ( ! is_array( $release ) || ! self::is_release_tag( (string) ( $release['tag_name'] ?? '' ) ) ) {
			set_site_transient( self::CACHE_KEY, array( 'manifest' => null ), self::FAILURE_TTL );
			return null;
		}

		$assets   = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$manifest = self::fetch_manifest( $assets );

		if ( null === $manifest ) {
			set_site_transient( self::CACHE_KEY, array( 'manifest' => null ), self::FAILURE_TTL );
			return null;
		}

		$data = array(
			'tag'      => (string) $release['tag_name'],
			'assets'   => $assets,
			'manifest' => $manifest,
		);
		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );

		return $data;
	}

	/**
	 * The manifest asset's contents.
	 *
	 * @param array $assets Release assets.
	 * @return array|null
	 */
	private static function fetch_manifest( array $assets ): ?array {
		$url = self::asset_url( $assets, self::MANIFEST_NAME );
		if ( '' === $url ) {
			return null;
		}
		$manifest = self::fetch_json( $url );

		return ( is_array( $manifest ) && ! empty( $manifest['plugins'] ) && is_array( $manifest['plugins'] ) )
			? $manifest
			: null;
	}

	/**
	 * GET a URL and decode it as JSON.
	 *
	 * @param string $url URL to fetch.
	 * @return array|null
	 */
	private static function fetch_json( string $url ): ?array {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/json',
					// GitHub rejects requests with no User-Agent outright.
					'User-Agent' => 'SportsPress-Admin-Tools/' . SPAT_VERSION . '; ' . home_url( '/' ),
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $decoded ) ? $decoded : null;
	}

	/**
	 * The update WordPress should offer for one watched plugin, if any.
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $installed Installed version.
	 * @return array|null
	 */
	private static function update_for( string $slug, string $installed ): ?array {
		$release = self::release();
		if ( null === $release ) {
			return null;
		}

		$entry = self::entry_for( $release['manifest'], $slug );
		if ( null === $entry || ! self::is_newer( $installed, (string) $entry['version'] ) ) {
			return null;
		}

		$package = self::asset_url( $release['assets'], (string) ( $entry['asset'] ?? $slug . '.zip' ) );
		if ( '' === $package ) {
			// The release names a newer version but its zip is missing or came
			// from somewhere unexpected. Offering an update WordPress cannot
			// install would put a permanent nag on the Plugins screen.
			return null;
		}

		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => $slug,
			'plugin'       => self::$watched[ $slug ] ?? $slug . '/' . $slug . '.php',
			'new_version'  => (string) $entry['version'],
			'url'          => 'https://github.com/' . self::REPO,
			'package'      => $package,
			'tested'       => (string) ( $entry['tested'] ?? '' ),
			'requires'     => (string) ( $entry['requires'] ?? '' ),
			'requires_php' => (string) ( $entry['requires_php'] ?? '' ),
		);
	}

	/**
	 * WordPress 5.8+: the `Update URI` header routes here.
	 *
	 * @param array|false $update      Update offered so far.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function filter_update_uri_check( $update, array $plugin_data, string $plugin_file ) {
		$slug = dirname( $plugin_file );
		if ( ! isset( self::$watched[ $slug ] ) ) {
			return $update;
		}

		$offer = self::update_for( $slug, (string) ( $plugin_data['Version'] ?? '' ) );

		return null === $offer ? $update : $offer;
	}

	/**
	 * Older WordPress: inject into the update transient directly.
	 *
	 * @param mixed $transient Update transient.
	 * @return mixed
	 */
	public static function filter_update_transient( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		foreach ( self::$watched as $slug => $basename ) {
			// Whatever wordpress.org had to say about a plugin of this slug is
			// not about this plugin. On 5.8+ the Update URI header means it was
			// never asked; here the answer has to be discarded by hand, or a
			// same-named plugin from the directory gets installed over ours.
			unset( $transient->response[ $basename ], $transient->no_update[ $basename ] );

			$installed = self::installed_version( $basename );
			$offer     = self::update_for( $slug, $installed );

			if ( null === $offer ) {
				// Listing it as up to date is what puts the auto-update toggle
				// on the row; a plugin absent from both lists gets no controls.
				$transient->no_update[ $basename ] = (object) array(
					'id'          => 'github.com/' . self::REPO,
					'slug'        => $slug,
					'plugin'      => $basename,
					'new_version' => $installed,
					'url'         => 'https://github.com/' . self::REPO,
					'package'     => '',
				);
				continue;
			}

			$transient->response[ $basename ] = (object) $offer;
		}

		return $transient;
	}

	/**
	 * The installed version of a watched plugin.
	 *
	 * @param string $basename Plugin basename.
	 * @return string
	 */
	private static function installed_version( string $basename ): string {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false );

		return (string) ( $data['Version'] ?? '' );
	}

	/**
	 * Fill in the "View details" modal, which would otherwise 404 to
	 * wordpress.org.
	 *
	 * @param mixed  $result Result so far.
	 * @param string $action plugins_api action.
	 * @param object $args   Request args.
	 * @return mixed
	 */
	public static function filter_plugin_details( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$slug = (string) $args->slug;
		if ( ! isset( self::$watched[ $slug ] ) ) {
			return $result;
		}

		$release = self::release();
		if ( null === $release ) {
			return $result;
		}

		$entry = self::entry_for( $release['manifest'], $slug );
		if ( null === $entry ) {
			return $result;
		}

		return (object) array(
			'name'          => (string) ( $entry['name'] ?? $slug ),
			'slug'          => $slug,
			'version'       => (string) $entry['version'],
			'author'        => '<a href="https://github.com/lusky3">Cody (lusky3)</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => (string) ( $entry['requires'] ?? '' ),
			'tested'        => (string) ( $entry['tested'] ?? '' ),
			'requires_php'  => (string) ( $entry['requires_php'] ?? '' ),
			'download_link' => self::asset_url( $release['assets'], (string) ( $entry['asset'] ?? $slug . '.zip' ) ),
			'sections'      => array(
				'changelog' => self::changelog_html( (string) ( $entry['changelog'] ?? '' ) ),
			),
		);
	}

	/**
	 * The manifest's changelog text as the modal's markup.
	 *
	 * readme.txt changelogs are `* one per line`. Escaped before any tag is
	 * added: this text comes from a release asset, and the modal renders it as
	 * HTML.
	 *
	 * @param string $changelog Raw changelog text.
	 * @return string
	 */
	private static function changelog_html( string $changelog ): string {
		if ( '' === trim( $changelog ) ) {
			return '';
		}

		$items = array();
		foreach ( preg_split( '/\r?\n/', $changelog ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$items[] = '<li>' . esc_html( ltrim( $line, "*- \t" ) ) . '</li>';
		}

		return $items ? '<ul>' . implode( '', $items ) . '</ul>' : '';
	}

	/**
	 * Forget the cached manifest.
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_site_transient( self::CACHE_KEY );
	}
}
