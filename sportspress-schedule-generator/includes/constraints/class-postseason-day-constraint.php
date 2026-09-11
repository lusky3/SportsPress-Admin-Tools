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
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public function validate( $game, $schedule, $config ) {
		if ( empty( $config->is_postseason ) ) {
			return true;
		}

		$requirement = self::day_requirement( $config, SPSG_Postseason_Bracket_Detector::final_week_info( $game ) );

		if ( null === $requirement || empty( $game->date ) ) {
			return true; // Not a final-week matchup, or nothing configured/scheduled yet.
		}

		list( $required_day, $stage_label ) = $requirement;
		$game_day                           = strtolower( ( new DateTime( $game->date ) )->format( 'l' ) );

		if ( $game_day !== strtolower( $required_day ) ) {
			return new WP_Error(
				'postseason_day_mismatch',
				sprintf(
					/* translators: 1: Championship or Consolation, 2: required day, 3: the day actually scheduled */
					__( '%1$s games must be played on %2$s, not %3$s.', 'sportspress-schedule-generator' ),
					$stage_label,
					ucfirst( $required_day ),
					ucfirst( $game_day )
				)
			);
		}

		return true;
	}

	/**
	 * The day of the week a final-week game is required to be played on,
	 * plus its "Championship"/"Consolation" label for the error message --
	 * or null if this constraint doesn't govern the game at all (not a
	 * final-week matchup, or nothing configured for its stage yet).
	 *
	 * @param SPSG_Schedule_Configuration $config Postseason configuration.
	 * @param array|null                  $final  SPSG_Postseason_Bracket_Detector::final_week_info() result for the game.
	 * @return array{0: string, 1: string}|null [required_day, stage_label].
	 */
	private static function day_requirement( $config, $final ) {
		if ( null === $final ) {
			return null;
		}

		if ( $final['is_championship'] ) {
			$day   = self::value( $config->championship_day, 'day' );
			$label = __( 'Championship', 'sportspress-schedule-generator' );
		} else {
			$day   = (string) $config->consolation_day;
			$label = __( 'Consolation', 'sportspress-schedule-generator' );
		}

		return '' === $day ? null : array( $day, $label );
	}

	/**
	 * One key of an array, or a default when absent -- extracted so callers
	 * don't need an inline null-coalesce.
	 *
	 * @param array  $arr     Array to read from.
	 * @param string $key     Key to look up.
	 * @param string $default Value to return if $key is absent.
	 * @return string
	 */
	private static function value( $arr, $key, $default = '' ) {
		return $arr[ $key ] ?? $default;
	}
}
