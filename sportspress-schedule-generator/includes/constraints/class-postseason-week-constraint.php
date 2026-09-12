<?php
/**
 * Postseason Week Position Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pins every postseason Championship/Consolation game to the config's
 * final (trailing 7-day) week, and every cross-round-robin game to
 * strictly before it. Fast-follow fix for a slot-allocator pacing issue:
 * pacing targets a team's games by game count, not array order, so a
 * placeholder team playing exactly one game (every RR-Seed team, in the
 * final week) paces to the middle of the season rather than the end --
 * this constraint makes correct placement a hard requirement instead of
 * relying on pacing behavior. A no-op outside a postseason configuration,
 * when season_end isn't set yet, or for any non-postseason-placeholder
 * game.
 */
class SPSG_Postseason_Week_Constraint extends SPSG_Abstract_Constraint {

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name     = 'Postseason Week Position';
		$this->priority = 95; // Hard constraint, evaluated alongside the other postseason-specific rules.
		$this->type     = 'hard';
	}

	/**
	 * Validate that a postseason game is scheduled in the right week.
	 *
	 * @param SPSG_Game                   $game     Game being validated.
	 * @param array                       $schedule Schedule slice (unused -- this constraint only looks at $game).
	 * @param SPSG_Schedule_Configuration $config   Postseason configuration.
	 * @return true|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function validate( $game, $schedule, $config ) {
		if ( empty( $config->is_postseason ) || empty( $config->season_end ) || empty( $game->date ) ) {
			return true;
		}

		$final_week_start = self::final_week_start( $config );
		$game_date         = new DateTime( $game->date );

		if ( null !== SPSG_Postseason_Bracket_Detector::final_week_info( $game ) ) {
			if ( $game_date < $final_week_start ) {
				return new WP_Error(
					'postseason_week_position',
					__( 'Championship/Consolation games must be scheduled in the postseason\'s final week.', 'sportspress-schedule-generator' )
				);
			}
			return true;
		}

		if ( null !== SPSG_Postseason_Bracket_Detector::cross_round_robin_division( $game ) ) {
			if ( $game_date >= $final_week_start ) {
				return new WP_Error(
					'postseason_week_position',
					__( 'Round-robin games must be scheduled before the postseason\'s final week.', 'sportspress-schedule-generator' )
				);
			}
		}

		return true;
	}

	/**
	 * The first date of the postseason's final (Championship/Consolation)
	 * week -- a rolling 7-day window ending at season_end. Matches
	 * SPSG_Configuration_Manager::postseason_season_end()'s own formula
	 * (round_robin_weeks calendar weeks, then a final week of exactly 7 days).
	 *
	 * @param SPSG_Schedule_Configuration $config Postseason configuration.
	 * @return DateTime
	 */
	private static function final_week_start( $config ) {
		$start = new DateTime( $config->season_end );
		$start->modify( '-6 days' );
		return $start;
	}
}
