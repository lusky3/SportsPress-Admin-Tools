<?php
/**
 * Serve stored sheet images to authorized admins only.
 *
 * Images live in a direct-access-denied uploads subdir; the only way to view
 * one is through this handler, gated by capability + a nonce, and hardened
 * against path traversal by resolving through SPSS_Image_Store (realpath
 * containment). Mirrors the etransfer file-download pattern.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_File_Server {

	const ACTION = 'spss_view_sheet_image';

	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve' ) );
	}

	/**
	 * Build a nonce-signed URL for a given sheet id.
	 */
	public static function image_url( $sheet_id ) {
		return add_query_arg(
			array(
				'action'     => self::ACTION,
				'sheet_id'   => (int) $sheet_id,
				'spss_nonce' => wp_create_nonce( self::ACTION . '_' . (int) $sheet_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	public function maybe_serve() {
		if ( ! isset( $_GET['action'] ) || self::ACTION !== $_GET['action'] ) {
			return;
		}

		$sheet_id = isset( $_GET['sheet_id'] ) ? absint( $_GET['sheet_id'] ) : 0;
		$nonce    = isset( $_GET['spss_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['spss_nonce'] ) ) : '';

		if ( ! $sheet_id || ! wp_verify_nonce( $nonce, self::ACTION . '_' . $sheet_id ) ) {
			wp_die( esc_html__( 'Invalid or expired link.', 'sportspress-score-sheets' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( 'manage_sportspress' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this image.', 'sportspress-score-sheets' ), '', array( 'response' => 403 ) );
		}

		$sheet = SPSS_Database::get_sheet( $sheet_id );
		if ( ! $sheet || empty( $sheet->image_path ) ) {
			wp_die( esc_html__( 'Image not found.', 'sportspress-score-sheets' ), '', array( 'response' => 404 ) );
		}

		$abs = SPSS_Image_Store::resolve( $sheet->image_path );
		if ( ! $abs ) {
			wp_die( esc_html__( 'Image not found.', 'sportspress-score-sheets' ), '', array( 'response' => 404 ) );
		}

		$info = @getimagesize( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mime = ( is_array( $info ) && ! empty( $info['mime'] ) ) ? $info['mime'] : 'application/octet-stream';

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Length: ' . filesize( $abs ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Disposition: inline; filename="sheet-' . $sheet_id . '"' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a validated, capability-gated image; WP_Filesystem has no streaming read.
		readfile( $abs );
		exit;
	}
}
