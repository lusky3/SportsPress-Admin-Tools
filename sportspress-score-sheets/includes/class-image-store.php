<?php
/**
 * Protected storage for uploaded score-sheet images.
 *
 * Images live in a non-listable uploads subdirectory (Apache .htaccess deny +
 * index files; Nginx needs a manual rule — see below), under random filenames,
 * and are re-encoded on ingest to strip EXIF/GPS metadata. They are never
 * served by direct URL — only through SPSS_File_Server (nonce + capability).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Image_Store {

	const SUBDIR = 'spss-sheets';

	/** Cap the long edge of stored images (px); re-encoding also strips EXIF/GPS. */
	const MAX_EDGE = 2600;

	/** JPEG/WebP re-encode quality. */
	const IMAGE_QUALITY = 85;

	public static function dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::SUBDIR;
	}

	/**
	 * Ensure the storage directory exists and is protected from direct access.
	 */
	public static function protect_dir() {
		$dir = self::dir();
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$index_php = $dir . '/index.php';
		if ( $wp_filesystem && ! file_exists( $index_php ) ) {
			$wp_filesystem->put_contents( $index_php, '<?php // Silence is golden.', FS_CHMOD_FILE );
		}
		$index_html = $dir . '/index.html';
		if ( $wp_filesystem && ! file_exists( $index_html ) ) {
			$wp_filesystem->put_contents( $index_html, '', FS_CHMOD_FILE );
		}
		$htaccess = $dir . '/.htaccess';
		if ( $wp_filesystem && ! file_exists( $htaccess ) ) {
			// Apache 2.4+ with 2.2 fallback. On Nginx add manually:
			// location ~* /wp-content/uploads/spss-sheets/ { deny all; }
			$rules = "# Apache 2.4+\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>";
			$wp_filesystem->put_contents( $htaccess, $rules, FS_CHMOD_FILE );
		}
	}

	/**
	 * Store an uploaded/received image: re-encode to strip metadata (EXIF/GPS),
	 * auto-orient, and cap dimensions. Returns the path RELATIVE to the uploads
	 * basedir (what we persist in the DB), or WP_Error.
	 *
	 * @param string $tmp_path Absolute path to a readable source image.
	 * @param string $ext      Desired output extension (jpg|png|webp). PDFs and
	 *                         HEIC are converted to jpg when the editor supports it.
	 * @return string|WP_Error Relative path under uploads basedir.
	 */
	public static function store_from_path( $tmp_path, $ext = 'jpg' ) {
		self::protect_dir();

		$editor = wp_get_image_editor( $tmp_path );
		if ( is_wp_error( $editor ) ) {
			// Editor can't read it (e.g. HEIC without libheif). Caller should have
			// validated decodability first; surface a clean error.
			return new WP_Error( 'spss_image_unreadable', __( 'The uploaded image could not be processed on this server.', 'sportspress-score-sheets' ) );
		}

		// Cap the long edge; re-encoding drops EXIF (incl. GPS) entirely.
		$size = $editor->get_size();
		if ( is_array( $size ) && ! empty( $size['width'] ) && ! empty( $size['height'] ) ) {
			$editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
		}
		$editor->set_quality( self::IMAGE_QUALITY );

		$ext      = in_array( strtolower( $ext ), array( 'jpg', 'jpeg', 'png', 'webp' ), true ) ? strtolower( $ext ) : 'jpg';
		$filename = 'sheet-' . wp_generate_password( 20, false ) . '.' . ( 'jpeg' === $ext ? 'jpg' : $ext );
		$target   = trailingslashit( self::dir() ) . $filename;

		$saved = $editor->save( $target );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}

		$uploads = wp_upload_dir();
		$abs     = isset( $saved['path'] ) ? $saved['path'] : $target;
		return ltrim( str_replace( trailingslashit( $uploads['basedir'] ), '', $abs ), '/' );
	}

	/**
	 * Resolve a stored relative path to an absolute path, guaranteeing it stays
	 * inside the storage directory (traversal-safe).
	 *
	 * @return string|false Absolute path or false if outside the store / missing.
	 */
	public static function resolve( $relative_path ) {
		$uploads  = wp_upload_dir();
		$abs      = trailingslashit( $uploads['basedir'] ) . ltrim( (string) $relative_path, '/' );
		$real     = realpath( $abs );
		$real_dir = realpath( self::dir() );
		if ( false === $real || false === $real_dir || 0 !== strpos( $real, $real_dir . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			return false;
		}
		return $real;
	}

	public static function delete( $relative_path ) {
		$real = self::resolve( $relative_path );
		if ( $real ) {
			wp_delete_file( $real );
			return true;
		}
		return false;
	}
}
