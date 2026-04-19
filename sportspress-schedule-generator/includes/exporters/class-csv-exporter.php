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
			$g['division']  = isset( $g['division'] )  ? (array) $g['division']  : array();
			$g['home_team'] = isset( $g['home_team'] ) ? (array) $g['home_team'] : array();
			$g['away_team'] = isset( $g['away_team'] ) ? (array) $g['away_team'] : array();
			$g['venue']     = isset( $g['venue'] )     ? (array) $g['venue']     : array();

			$is_inter_division = ! empty( $g['is_inter_division'] );
			$home_name = $g['home_team']['name'] ?? $g['home_team']['id'] ?? 'Unknown';
			$away_name = $g['away_team']['name'] ?? $g['away_team']['id'] ?? 'Unknown';
			$home_away = sprintf( '%s (H) vs %s (A)', $home_name, $away_name );

			$row = array(
				$g['date'] ?? '',
				$g['time_slot'] ?? '',
				$g['end_time'] ?? '',
				$g['match_length'] ?? 60,
				$home_name,
				$away_name,
				$g['venue']['name'] ?? $g['venue']['id'] ?? '',
				$g['division']['name'] ?? $g['division']['id'] ?? '',
				$home_away,
				$is_inter_division ? 'Yes' : 'No',
				$g['week_number'] ?? '',
				( ! empty( $g['is_makeup'] ) ) ? 'Yes' : 'No',
				$g['original_date'] ?? '',
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
