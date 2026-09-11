<?php
/**
 * Postseason Championship Time Window Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restricts every division's Championship game (and only the Championship
 * game -- Consolation games are unaffected) to a configured start/end time
 * window. A no-op outside a postseason configuration, for any game that
 * isn't a final-week Championship matchup, or when no window is configured.
 */
class SPSG_Championship_Time_Window_Constraint extends SPSG_Abstract_Constraint {

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name     = 'Championship Time Window';
		$this->priority = 95; // Hard constraint, evaluated alongside the other postseason-specific rules.
		$this->type     = 'hard';
	}

	/**
	 * Validate that a Championship game's time slot falls within the
	 * configured window.
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

		$window = self::required_window( $config, SPSG_Postseason_Bracket_Detector::final_week_info( $game ) );
		if ( null === $window || empty( $game->time_slot ) ) {
			return true; // Not a Championship game, or nothing configured/scheduled yet.
		}

		list( $start, $end ) = $window;

		if ( $game->time_slot < $start || $game->time_slot > $end ) {
			return new WP_Error(
				'championship_time_window',
				sprintf(
					/* translators: 1: scheduled time, 2: window start, 3: window end */
					__( 'Championship games must be played between %2$s and %3$s, not %1$s.', 'sportspress-schedule-generator' ),
					$game->time_slot,
					$start,
					$end
				)
			);
		}

		return true;
	}

	/**
	 * The configured Championship time window, if this game is even a
	 * Championship game and a window has been configured for it.
	 *
	 * @param SPSG_Schedule_Configuration $config Postseason configuration.
	 * @param array|null                  $final  SPSG_Postseason_Bracket_Detector::final_week_info() result for the game.
	 * @return array{0: string, 1: string}|null [start, end], or null if this constraint doesn't apply.
	 */
	private static function required_window( $config, $final ) {
		if ( null === $final || ! $final['is_championship'] ) {
			return null; // Not a final-week matchup, or a Consolation one.
		}

		$start = $config->championship_day['start'] ?? '';
		$end   = $config->championship_day['end'] ?? '';

		if ( '' === $start || '' === $end ) {
			return null; // Nothing configured yet.
		}

		return array( $start, $end );
	}
}
