<?php
/**
 * Configuration Interface
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Interface for configuration management
 */
interface SPSG_Configuration_Interface {


	/**
	 * Validate configuration data
	 *
	 * @param array $config Configuration array
	 * @return bool|WP_Error True if valid, WP_Error with details if invalid
	 */
	public function validate( $config );

	/**
	 * Sanitize configuration data
	 *
	 * @param array $config Raw configuration data
	 * @return array Sanitized configuration data
	 */
	public function sanitize( $config );

	/**
	 * Get default configuration values
	 *
	 * @return array Default configuration
	 */
	public function get_defaults();

	/**
	 * Save configuration to database
	 *
	 * @param array $config Configuration to save
	 * @return bool Success status
	 */
	public function save( $config );

	/**
	 * Load configuration from database
	 *
	 * @return array Current configuration
	 */
	public function load();
}
