<?php
/**
 * Constraint Interface
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface for scheduling constraints
 */
interface SPSG_Constraint_Interface {


	/**
	 * Validate a game against this constraint
	 *
	 * @param SPSG_Game                  $game The game to validate
	 * @param array                      $schedule Current schedule state
	 * @param SPSG_Configuration_Manager $config Schedule configuration
	 * @return bool|WP_Error True if valid, WP_Error if constraint violated
	 */
	public function validate( $game, $schedule, $config );

	/**
	 * Get constraint priority (higher number = higher priority)
	 *
	 * @return int Priority level
	 */
	public function get_priority();

	/**
	 * Get constraint type
	 *
	 * @return string 'hard', 'soft', or 'optimization'
	 */
	public function get_type();

	/**
	 * Calculate violation cost for optimization constraints
	 *
	 * @param SPSG_Game                  $game The game to evaluate
	 * @param array                      $schedule Current schedule state
	 * @param SPSG_Configuration_Manager $config Schedule configuration
	 * @return float Violation cost (0 = no violation)
	 */
	public function get_violation_cost( $game, $schedule, $config );

	/**
	 * Get constraint name for display
	 *
	 * @return string Human-readable constraint name
	 */
	public function get_name();
}
