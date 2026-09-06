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
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Updater_Release {

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

	/**
	 * The manifest's changelog text as the modal's markup.
	 *
	 * Changelogs in readme.txt are one bullet per line. Escaped before any tag
	 * is added: this text comes from a release asset, and the modal renders it
	 * as HTML.
	 *
	 * @param string $changelog Raw changelog text.
	 * @return string
	 */
	public static function changelog_html( string $changelog ): string {
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
}
