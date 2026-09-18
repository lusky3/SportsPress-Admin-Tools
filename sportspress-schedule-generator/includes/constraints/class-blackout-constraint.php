<?php
/**
 * Blackout Date Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prevents scheduling on blackout dates
 */
class SPSG_Blackout_Constraint extends SPSG_Abstract_Constraint {

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Blackout Date Constraint';
		$this->priority = 100; // High priority - hard constraint
		$this->type = 'hard';
	}

	/**
	 * Validate game against blackout dates
	 */
	public function validate( $game, $schedule, $config ) {
		if ( ! isset( $game->date ) ) {
			return new WP_Error( 'missing_date', __( 'Game must have a date', 'sportspress-schedule-generator' ) );
		}

		$game_date = new DateTime( $game->date );

		// Check if game date is in blackout dates
		foreach ( $config->blackout_dates as $blackout_date ) {
			$blackout = new DateTime( $blackout_date );

			if ( $game_date->format( 'Y-m-d' ) === $blackout->format( 'Y-m-d' ) ) {
				$this->log( sprintf( 'Game blocked by blackout date: %s', $blackout->format( 'Y-m-d' ) ) );

				return new WP_Error(
					'blackout_date',
					sprintf(
						__( 'Cannot schedule game on blackout date: %s', 'sportspress-schedule-generator' ),
						$blackout->format( 'Y-m-d' )
					)
				);
			}
		}

		return true;
	}
}
