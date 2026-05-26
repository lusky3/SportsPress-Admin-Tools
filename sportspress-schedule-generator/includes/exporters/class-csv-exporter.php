<?php
/**
 * CSV Exporter
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * CSV export functionality
 */
class SPSG_CSV_Exporter implements SPSG_Exporter_Interface {


	/**
	 * Prefix a value with a single quote if it begins with a character that a
	 * spreadsheet may interpret as a formula. Mitigates CSV formula-injection
	 * attacks where attacker-controlled text (team / venue / division names)
	 * is opened directly in Excel or another spreadsheet program.
	 *
	 * @param mixed $value Raw value about to be written to CSV.
	 * @return mixed Same value, optionally prefixed with a single quote.
	 */
	public static function csv_safe( $value ) {
		if ( $value === null || $value === '' || is_bool( $value ) ) {
			return $value;
		}

		$string = (string) $value;
		$first  = substr( $string, 0, 1 );

		// Includes a leading "\n" so embedded-newline payloads (which some
		// spreadsheet apps still parse as a formula on the next line) get
		// prefixed too.
		if ( in_array( $first, array( '=', '+', '-', '@', "\t", "\r", "\n" ), true ) ) {
			return "'" . $string;
		}

		return $value;
	}

	/**
	 * Export schedule to CSV
	 */
	public function export( $schedule, $config, $style = '' ) {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/spsg-exports';
		$filename = 'schedule_' . wp_date( 'Y-m-d_H-i-s' ) . '.csv';

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		$filepath = $export_dir . '/' . $filename;

		$file = fopen( $filepath, 'w' );
		if ( ! $file ) {
			return new WP_Error( 'file_creation_failed', __( 'Could not create CSV file', 'sportspress-schedule-generator' ) );
		}

		// Write header
		$headers = array(
			'Date',
			'Start Time',
			'End Time',
			'Duration (min)',
			'Home Team',
			'Away Team',
			'Venue',
			'Division',
			'Home/Away',
			'Inter-Division',
			'Week',
			'Is Makeup',
			'Original Date',
		);
		fputcsv( $file, $headers );

		// Write data
		foreach ( $schedule as $game ) {
			// Normalise to arrays to handle stdClass from transients.
			$g = (array) $game;
			$g['division']  = isset( $g['division'] ) ? (array) $g['division'] : array();
			$g['home_team'] = isset( $g['home_team'] ) ? (array) $g['home_team'] : array();
			$g['away_team'] = isset( $g['away_team'] ) ? (array) $g['away_team'] : array();
			$g['venue']     = isset( $g['venue'] ) ? (array) $g['venue'] : array();

			$is_inter_division = ! empty( $g['is_inter_division'] );
			$home_name = $g['home_team']['name'] ?? $g['home_team']['id'] ?? 'Unknown';
			$away_name = $g['away_team']['name'] ?? $g['away_team']['id'] ?? 'Unknown';
			$venue_name = $g['venue']['name'] ?? $g['venue']['id'] ?? '';
			$division_name = $g['division']['name'] ?? $g['division']['id'] ?? '';
			$home_away = sprintf( '%s (H) vs %s (A)', $home_name, $away_name );

			// Apply csv_safe() to every string cell — not just the user-provided
			// names. Date strings can still start with "-" (negative-year ISO
			// dates) and time strings have been seen with leading whitespace
			// from upstream feeds, both of which are formula-injection vectors.
			$row = array(
				self::csv_safe( $g['date'] ?? '' ),
				self::csv_safe( $g['time_slot'] ?? '' ),
				self::csv_safe( $g['end_time'] ?? '' ),
				$g['match_length'] ?? 60,
				self::csv_safe( $home_name ),
				self::csv_safe( $away_name ),
				self::csv_safe( $venue_name ),
				self::csv_safe( $division_name ),
				self::csv_safe( $home_away ),
				$is_inter_division ? 'Yes' : 'No',
				$g['week_number'] ?? '',
				( ! empty( $g['is_makeup'] ) ) ? 'Yes' : 'No',
				self::csv_safe( $g['original_date'] ?? '' ),
			);
			fputcsv( $file, $row );
		}

		fclose( $file );

		return array(
			'path' => $filepath,
			'url' => $upload_dir['baseurl'] . '/spsg-exports/' . $filename,
			'filename' => $filename,
			'format' => 'csv',
		);
	}

	/**
	 * Get format name
	 */
	public function get_format() {
		return 'CSV';
	}

	/**
	 * Get file extension
	 */
	public function get_extension() {
		return 'csv';
	}

	/**
	 * Get MIME type
	 */
	public function get_mime_type() {
		return 'text/csv';
	}

	/**
	 * Check if format supports styling
	 */
	public function supports_formatting() {
		return false;
	}
}
