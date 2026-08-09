<?php
/**
 * Export Manager
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages schedule export functionality
 */
class SPSG_Export_Manager {


	/**
	 * Available exporters
	 */
	private $exporters = array();

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->load_exporters();
	}

	/**
	 * Protect the export directory from direct access and directory listing.
	 *
	 * Note: On Nginx servers, add the following to your server block manually:
	 *   location ~* /wp-content/uploads/spsg-exports/ { deny all; }
	 */
	private function protect_export_directory() {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/spsg-exports';

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$index_php = $export_dir . '/index.php';
		if ( ! file_exists( $index_php ) ) {
			$wp_filesystem->put_contents( $index_php, '<?php // Silence is golden.', FS_CHMOD_FILE );
		}

		$index_html = $export_dir . '/index.html';
		if ( ! file_exists( $index_html ) ) {
			$wp_filesystem->put_contents( $index_html, '', FS_CHMOD_FILE );
		}

		$htaccess_file = $export_dir . '/.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			// Apache 2.4+ syntax with 2.2 fallback
			$htaccess = "# Apache 2.4+\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n\n# Apache 2.2\n<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>";
			$wp_filesystem->put_contents( $htaccess_file, $htaccess, FS_CHMOD_FILE );
		}
	}

	/**
	 * Export schedule in specified format
	 *
	 * @param array  $schedule Array of game objects
	 * @param mixed  $config Configuration object or array
	 * @param string $format Export format (csv, xlsx)
	 * @param array  $filters Optional filters (division, date_from, date_to)
	 * @param string $xlsx_style XLSX style: 'compact' or 'detailed'
	 * @return array|WP_Error Export result with file path and URL
	 */
	public function export( $schedule, $config, $format = 'csv', $filters = array(), $xlsx_style = 'compact' ) {
		if ( ! isset( $this->exporters[ $format ] ) ) {
			return new WP_Error( 'invalid_format', sprintf( __( 'Export format not supported: %s', 'sportspress-schedule-generator' ), $format ) );
		}

		// Ensure export directory is protected before any export
		$this->protect_export_directory();

		// Apply filters to schedule
		$filtered_schedule = $this->apply_filters( $schedule, $filters );

		if ( empty( $filtered_schedule ) ) {
			return new WP_Error( 'empty_schedule', __( 'No games match the specified filters', 'sportspress-schedule-generator' ) );
		}

		$exporter = $this->exporters[ $format ];
		return $exporter->export( $filtered_schedule, $config, $xlsx_style );
	}

	/**
	 * Apply filters to schedule
	 *
	 * @param array $schedule Array of game objects
	 * @param array $filters Filters to apply
	 * @return array Filtered schedule
	 */
	private function apply_filters( $schedule, $filters ) {
		if ( empty( $filters ) ) {
			return $schedule;
		}

		$filtered = $schedule;

		// Filter by division.
		//
		// H22: the export dropdown is populated from the preview table's
		// `data-division` attribute, which carries the division NAME, while this
		// comparison preferred the division ID whenever one was present — so a
		// name never matched and "Division A only" exports came back empty (or,
		// when divisions had no id, matched by accident). Match either identifier
		// so both the admin UI and id-passing API clients work.
		if ( ! empty( $filters['division'] ) ) {
			$division_key = (string) $filters['division'];
			$filtered     = array_filter(
				$filtered,
				function ( $game ) use ( $division_key ) {
					$g   = (array) $game;
					$div = isset( $g['division'] ) ? (array) $g['division'] : array();

					return (string) ( $div['id'] ?? '' ) === $division_key
						|| (string) ( $div['name'] ?? '' ) === $division_key;
				}
			);
		}

		// Filter by date range
		if ( ! empty( $filters['date_from'] ) ) {
			$date_from = $filters['date_from'];
			$filtered  = array_filter(
				$filtered,
				function ( $game ) use ( $date_from ) {
					$g = (array) $game;
					return ( $g['date'] ?? '' ) >= $date_from;
				}
			);
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$date_to = $filters['date_to'];
			$filtered = array_filter(
				$filtered,
				function ( $game ) use ( $date_to ) {
					$g = (array) $game;
					return ( $g['date'] ?? '' ) <= $date_to;
				}
			);
		}

		// Re-index array
		return array_values( $filtered );
	}

	/**
	 * Get available export formats
	 */
	public function get_available_formats() {
		$formats = array();

		foreach ( $this->exporters as $format => $exporter ) {
			$formats[ $format ] = array(
				'name' => $exporter->get_format(),
				'extension' => $exporter->get_extension(),
				'mime_type' => $exporter->get_mime_type(),
				'supports_formatting' => $exporter->supports_formatting(),
			);
		}

		return $formats;
	}

	/**
	 * Load available exporters
	 */
	private function load_exporters() {
		require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-csv-exporter.php';
		$this->exporters['csv'] = new SPSG_CSV_Exporter();

		require_once SPSG_PLUGIN_PATH . 'includes/exporters/class-xlsx-exporter.php';
		$this->exporters['xlsx'] = new SPSG_XLSX_Exporter();
	}
}
