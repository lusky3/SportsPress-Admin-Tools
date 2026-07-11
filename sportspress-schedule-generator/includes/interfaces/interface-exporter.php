<?php
/**
 * Exporter Interface
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for schedule exporters
 */
interface SPSG_Exporter_Interface {

	/**
	 * Export schedule to specific format
	 *
	 * @param array  $schedule Array of SPSG_Game objects
	 * @param mixed  $config Schedule configuration (can be null)
	 * @param string $style Export style variant (e.g. 'compact', 'detailed')
	 * @return array|WP_Error Array with path, url, filename, format on success, WP_Error on failure
	 */
	public function export( $schedule, $config, $style = '' );

	/**
	 * Get export format name
	 *
	 * @return string Format name (e.g., 'CSV', 'XLSX')
	 */
	public function get_format();

	/**
	 * Get file extension for this format
	 *
	 * @return string File extension (e.g., 'csv', 'xlsx')
	 */
	public function get_extension();

	/**
	 * Get MIME type for this format
	 *
	 * @return string MIME type
	 */
	public function get_mime_type();

	/**
	 * Check if format supports styling/formatting
	 *
	 * @return bool True if supports visual formatting
	 */
	public function supports_formatting();
}
