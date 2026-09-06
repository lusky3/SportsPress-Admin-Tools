<?php
/**
 * Where update information comes from: this repository's GitHub releases.
 *
 * Split out of SPAT_Updater, which was doing two jobs. This one knows about
 * GitHub — which tags count as releases, which download hosts are acceptable,
 * how a release is fetched and cached, and how to read one plugin out of the
 * manifest. SPAT_Updater knows about WordPress, and asks this class what is
 * available.
 *
 * The split is worth having beyond keeping a class small: the rules here are
 * the security-relevant ones, and they are pure enough to test exhaustively on
 * their own — every one of them decides something about a URL that WordPress
 * will download and unpack over a live plugin directory.
 *
 * See class-updater.php for why a manifest exists at all.
 *
 * Eleven methods against PHPMD's threshold of ten, suppressed rather than
 * split again: the class was already split out of SPAT_Updater on the real
 * seam, every method here is part of one job — knowing what GitHub is
 * offering — and every complexity threshold is met. Splitting further to move
 * a count would put related rules in different files for no reader's benefit.
 * Same reasoning SPLM_Waitlist_Gate carries.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Updater_Release {

	const REPO          = 'lusky3/SportsPress-Admin-Tools';
	const MANIFEST_NAME = 'manifest.json';

	/** Manifest shape this updater understands. */
	const SCHEMA        = 1;
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
		if ( empty( $entry['version'] ) ) {
			return null;
		}

		// The asset name is bound to the slug rather than taken from the entry.
		// An entry for sportspress-player-tools naming
		// sportspress-admin-tools.zip would otherwise have WordPress unpack one
		// plugin over another's directory — the manifest would be choosing
		// which files land where, which is not a decision it gets to make.
		// The field is still read so a disagreement is a refusal rather than
		// something silently ignored.
		$expected = self::asset_name( $slug );
		if ( ! empty( $entry['asset'] ) && $entry['asset'] !== $expected ) {
			return null;
		}
		$entry['asset'] = $expected;

		return $entry;
	}

	/**
	 * The only asset name a plugin may be updated from.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function asset_name( string $slug ): string {
		return $slug . '.zip';
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
	public static function current(): ?array {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return empty( $cached['manifest'] ) ? null : $cached;
		}

		$fresh = self::fetch_current();

		// Both outcomes are cached. A failure is cached briefly under the same
		// key, because this runs on the update check and that fires on ordinary
		// admin page loads — an unreachable GitHub must not mean a blocking
		// request on every one of them.
		set_site_transient(
			self::CACHE_KEY,
			null === $fresh ? array( 'manifest' => null ) : $fresh,
			null === $fresh ? self::FAILURE_TTL : self::CACHE_TTL
		);

		return $fresh;
	}

	/**
	 * Fetch and vet the latest release, ignoring the cache.
	 *
	 * @return array|null
	 */
	private static function fetch_current(): ?array {
		$release = self::fetch_json( 'https://api.github.com/repos/' . self::REPO . '/releases/latest' );
		if ( ! is_array( $release ) || ! self::is_release_tag( (string) ( $release['tag_name'] ?? '' ) ) ) {
			return null;
		}

		$tag      = (string) $release['tag_name'];
		$assets   = is_array( $release['assets'] ?? null ) ? $release['assets'] : array();
		$manifest = self::fetch_manifest( $assets, $tag );

		if ( null === $manifest ) {
			return null;
		}

		return array(
			'tag'      => $tag,
			'assets'   => $assets,
			'manifest' => $manifest,
		);
	}

	/**
	 * Whether a manifest is this project's, and describes this release.
	 *
	 * These three fields are written into the manifest precisely so they can be
	 * checked, and until now nothing checked them — the only test asserting
	 * `repo` was recording an intention the code ignored. A manifest naming
	 * another project or another tag is not a manifest for what is about to be
	 * installed, whether that is an attack or a mis-built release.
	 *
	 * @param array  $manifest Decoded manifest.
	 * @param string $tag      Tag of the release it came from.
	 * @return bool
	 */
	private static function belongs_to( array $manifest, string $tag ): bool {
		if ( (int) ( $manifest['schema'] ?? 0 ) !== self::SCHEMA ) {
			return false;
		}
		if ( (string) ( $manifest['repo'] ?? '' ) !== self::REPO ) {
			return false;
		}
		return (string) ( $manifest['tag'] ?? '' ) === $tag;
	}

	/**
	 * The manifest asset's contents.
	 *
	 * @param array $assets Release assets.
	 * @return array|null
	 */
	private static function fetch_manifest( array $assets, string $tag ): ?array {
		$url = self::asset_url( $assets, self::MANIFEST_NAME );
		if ( '' === $url ) {
			return null;
		}
		$manifest = self::fetch_json( $url );

		if ( ! is_array( $manifest ) || empty( $manifest['plugins'] ) || ! is_array( $manifest['plugins'] ) ) {
			return null;
		}

		return self::belongs_to( $manifest, $tag ) ? $manifest : null;
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
					'User-Agent' => 'SportsPress-Admin-Tools/' . ( defined( 'SPAT_VERSION' ) ? SPAT_VERSION : '0' ) . '; ' . home_url( '/' ),
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
	 * Forget the cached release.
	 *
	 * @return void
	 */
	public static function flush(): void {
		delete_site_transient( self::CACHE_KEY );
	}

}
