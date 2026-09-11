<?php
/**
 * Postseason Championship/Consolation Day Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carves out one specific day of the week for every division's
 * Championship game, and one (possibly different) day for every other
 * final-week (Consolation) game -- design notes kept locally, not in this
 * repo. A no-op outside a postseason configuration, and for any game that
 * isn't a final-week matchup at all.
 */
class SPSG_Postseason_Day_Constraint extends SPSG_Abstract_Constraint {

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name     = 'Postseason Championship/Consolation Day';
		$this->priority = 95; // Hard constraint, evaluated alongside the other postseason-specific rules.
		$this->type     = 'hard';
	}

	/**
	 * Validate that a final-week game lands on its configured day.
	 *
	 * @param SPSG_Game                   $game     Game being validated.
	 * @param array                       $schedule Schedule slice (unused -- this constraint only looks at $game).
	 * @param SPSG_Schedule_Configuration $config   Postseason configuration.
	 * @return true|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function validate( $game, $schedule, $config ) {
		if ( empty( $config->is_postseason ) ) {
			return true;
		}

		$final = SPSG_Postseason_Bracket_Detector::final_week_info( $game );
		if ( null === $final ) {
			return true; // Not a final-week matchup -- this constraint doesn't govern it.
		}

		$required_day = $final['is_championship']
			? ( $config->championship_day['day'] ?? '' )
			: $config->consolation_day;

		if ( '' === $required_day || empty( $game->date ) ) {
			return true; // Nothing configured, or nothing to check yet.
		}

		$game_day = strtolower( ( new DateTime( $game->date ) )->format( 'l' ) );

		if ( $game_day !== strtolower( $required_day ) ) {
			return new WP_Error(
				'postseason_day_mismatch',
				sprintf(
					/* translators: 1: Championship or Consolation, 2: required day, 3: the day actually scheduled */
					__( '%1$s games must be played on %2$s, not %3$s.', 'sportspress-schedule-generator' ),
					$final['is_championship'] ? __( 'Championship', 'sportspress-schedule-generator' ) : __( 'Consolation', 'sportspress-schedule-generator' ),
					ucfirst( $required_day ),
					ucfirst( $game_day )
				)
			);
		}

		return true;
	}
}
