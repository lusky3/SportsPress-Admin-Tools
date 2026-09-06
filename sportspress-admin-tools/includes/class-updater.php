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



	// ------------------------------------------------------------------
	// I/O and the WordPress surface.
	// ------------------------------------------------------------------




	/**
	 * The update WordPress should offer for one watched plugin, if any.
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $installed Installed version.
	 * @return array|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function update_for( string $slug, string $installed ): ?array {
		$release = SPAT_Updater_Release::current();
		if ( null === $release ) {
			return null;
		}

		$entry = SPAT_Updater_Release::entry_for( $release['manifest'], $slug );
		if ( null === $entry || ! self::is_newer( $installed, (string) $entry['version'] ) ) {
			return null;
		}

		$package = SPAT_Updater_Release::asset_url( $release['assets'], (string) ( $entry['asset'] ?? $slug . '.zip' ) );
		if ( '' === $package ) {
			// The release names a newer version but its zip is missing or came
			// from somewhere unexpected. Offering an update WordPress cannot
			// install would put a permanent nag on the Plugins screen.
			return null;
		}

		return array(
			'id'           => 'github.com/' . SPAT_Updater_Release::REPO,
			'slug'         => $slug,
			'plugin'       => self::$watched[ $slug ] ?? $slug . '/' . $slug . '.php',
			'new_version'  => (string) $entry['version'],
			'url'          => 'https://github.com/' . SPAT_Updater_Release::REPO,
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
					'id'          => 'github.com/' . SPAT_Updater_Release::REPO,
					'slug'        => $slug,
					'plugin'      => $basename,
					'new_version' => $installed,
					'url'         => 'https://github.com/' . SPAT_Updater_Release::REPO,
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
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function filter_plugin_details( $result, string $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$slug = (string) $args->slug;
		if ( ! isset( self::$watched[ $slug ] ) ) {
			return $result;
		}

		$release = SPAT_Updater_Release::current();
		if ( null === $release ) {
			return $result;
		}

		$entry = SPAT_Updater_Release::entry_for( $release['manifest'], $slug );
		if ( null === $entry ) {
			return $result;
		}

		return (object) array(
			'name'          => (string) ( $entry['name'] ?? $slug ),
			'slug'          => $slug,
			'version'       => (string) $entry['version'],
			'author'        => '<a href="https://github.com/lusky3">Cody (lusky3)</a>',
			'homepage'      => 'https://github.com/' . SPAT_Updater_Release::REPO,
			'requires'      => (string) ( $entry['requires'] ?? '' ),
			'tested'        => (string) ( $entry['tested'] ?? '' ),
			'requires_php'  => (string) ( $entry['requires_php'] ?? '' ),
			'download_link' => SPAT_Updater_Release::asset_url( $release['assets'], (string) ( $entry['asset'] ?? $slug . '.zip' ) ),
			'sections'      => array(
				'changelog' => SPAT_Updater_Release::changelog_html( (string) ( $entry['changelog'] ?? '' ) ),
			),
		);
	}


	/**
	 * Forget the cached manifest.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function flush(): void {
		SPAT_Updater_Release::flush();
	}
}
