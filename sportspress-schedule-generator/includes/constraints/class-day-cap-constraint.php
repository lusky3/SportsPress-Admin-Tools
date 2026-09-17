<?php
/**
 * Day Cap Constraint
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A HARD per-team cap on how many games a team may play on a given day of
 * the week (e.g. "no team plays more than 10 Sundays"), configured via
 * `$config->distribution_rules['day_hard_cap']` (day name => max games).
 *
 * This exists alongside SPSG_Distribution_Constraint's existing soft
 * DAY_BALANCE_COST_PER_GAME_DEVIATION, which only ever nudges the allocator
 * toward a team's day-balance target -- it can lose to other soft costs, and
 * (see SPSG_Slot_Allocator::week_pick_rank()) it never gets a vote in which
 * matchups get first claim on a week's slots at all. This constraint is the
 * blunter alternative: rather than making a team's day balance more likely to
 * land somewhere reasonable, it makes exceeding a configured day cap
 * impossible, at the cost of the allocator having to work harder (more
 * backtracking, and a real chance of allocation failure on a season whose
 * structure can't honour the cap) to find a schedule that respects it.
 *
 * Deliberately opt-in and per-day: a config with no `day_hard_cap` entries
 * (the default) sees no behaviour change at all -- {@see validate()} returns
 * true immediately.
 *
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SPSG_Day_Cap_Constraint extends SPSG_Abstract_Constraint {

	/**
	 * Initialize constraint
	 */
	protected function init() {
		$this->name = 'Day Cap Constraint';
		$this->priority = 82; // High priority - hard constraint, checked alongside team restrictions.
		$this->type = 'hard';
	}

	/**
	 * @return bool Always true: a team's count for a day must be counted
	 *              across the whole season, not just the candidate slot's day.
	 */
	public function wants_full_schedule() {
		return true;
	}

	/**
	 * Reject a placement that would push either team past its configured cap
	 * for the game's day of the week.
	 *
	 * @param object $game     Candidate game.
	 * @param array  $schedule Full season-so-far schedule (flat list) --
	 *                         guaranteed by {@see wants_full_schedule()}.
	 * @param object $config   Schedule configuration.
	 * @return true|WP_Error
	 */
	public function validate( $game, $schedule, $config ) {
		$caps = $config->distribution_rules['day_hard_cap'] ?? array();
		if ( empty( $caps ) ) {
			return true;
		}

		$day = $this->game_day( $game );
		if ( ! isset( $caps[ $day ] ) ) {
			return true;
		}
		$cap = (int) $caps[ $day ];

		foreach ( array( $game->home_team, $game->away_team ) as $team ) {
			$team_id = $this->get_team_id( $team );
			$count   = $this->team_day_count( $team_id, $day, $schedule );

			// +1: $game itself is the candidate being validated, not yet in
			// $schedule.
			if ( $count + 1 > $cap ) {
				return new WP_Error(
					'day_hard_cap_exceeded',
					sprintf(
						/* translators: 1: team name, 2: day of the week, 3: configured cap */
						__( '%1$s would exceed its %2$s cap of %3$d games.', 'sportspress-schedule-generator' ),
						$this->get_team_label( $team ),
						ucfirst( $day ),
						$cap
					)
				);
			}
		}

		return true;
	}

	/**
	 * A game's day of the week, resolved the same way the allocator and
	 * Distribution Constraint do (an explicit `day` property when present,
	 * else derived from `date`).
	 *
	 * @param object $game Game.
	 * @return string Lowercase day name.
	 */
	private function game_day( $game ) {
		return isset( $game->day ) ? $game->day : strtolower( gmdate( 'l', strtotime( $game->date ) ) );
	}

	/**
	 * How many of a team's games so far fall on the given day of the week.
	 *
	 * @param string $team_id  Team id.
	 * @param string $day      Day name.
	 * @param array  $schedule Full season-so-far schedule (flat list).
	 * @return int
	 */
	private function team_day_count( $team_id, $day, $schedule ) {
		$count = 0;
		foreach ( $schedule as $existing ) {
			if ( $this->game_day( $existing ) !== $day ) {
				continue;
			}
			if ( $this->get_team_id( $existing->home_team ) === $team_id || $this->get_team_id( $existing->away_team ) === $team_id ) {
				$count++;
			}
		}
		return $count;
	}
}
