<?php
/**
 * Slot Allocator Class
 *
 * Assigns matchups to specific dates, times, and venues using
 * greedy allocation with backtracking fallback.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slot allocation algorithm implementation
 */
class SPSG_Slot_Allocator {


	/**
	 * Constraint manager
	 */
	private $constraint_manager;

	/**
	 * Maximum backtracking depth.
	 *
	 * Initialised conservatively but raised in {@see allocate()} to be
	 * proportional to the number of matchups so the recursion can actually
	 * reach the end of large schedules. Timeout / cancellation transients
	 * checked by the engine remain the primary safety net.
	 */
	private $max_backtrack_depth = 50;

	/**
	 * Remaining node-visit budget for the backtracking search.
	 *
	 * M53: the depth limit can never bind — `$depth` is incremented once per
	 * successful placement, so it equals the matchup index and is bounded by
	 * count($matchups), while the limit is 5× that. Only the wall clock stopped
	 * an O(slots^n) search, which pins a worker for the full generation timeout
	 * on an infeasible configuration. A node budget makes the search fail fast
	 * and deterministically instead.
	 *
	 * @var int
	 */
	private $backtrack_budget = 0;

	/**
	 * Set when the backtracking search stopped because {@see $backtrack_budget}
	 * ran out rather than because the search space was genuinely exhausted.
	 *
	 * @var bool
	 */
	private $backtrack_budget_exhausted = false;

	/**
	 * Candidate-slot examinations allowed per matchup during backtracking.
	 *
	 * The budget is charged per slot examined rather than per recursion node,
	 * because each node scans the whole slot list — counting nodes alone lets an
	 * infeasible configuration burn minutes inside a "small" node count.
	 */
	const BACKTRACK_VISITS_PER_MATCHUP = 500;

	/**
	 * Floor on the total backtracking budget, so very small schedules still get
	 * a meaningful search.
	 */
	const BACKTRACK_MIN_VISITS = 50000;

	/**
	 * Available slots cache
	 */
	private $available_slots = array();

	/**
	 * Available slots indexed by date (date string => slot[]). Built once per
	 * allocation run to make {@see find_best_slot()} run in O(matchups * slots/day)
	 * rather than O(matchups * total_slots).
	 */
	private $slots_by_date = array();

	/**
	 * Sorted list of dates that have at least one available slot.
	 * {@see find_best_slot()} visits them from the matchup's pace target
	 * outwards, so games land where they belong in the season rather than at
	 * the earliest free date.
	 */
	private $sorted_slot_dates = array();

	/**
	 * Each date's per-venue slot count: [date][venue_id] => slot count. Built
	 * once alongside {@see $slots_by_date}, read by {@see calculate_slot_cost()}
	 * for {@see VENUE_LOAD_COST}.
	 *
	 * @var array<string,array<string,int>>
	 */
	private $venue_capacity_by_date = array();

	/**
	 * ISO week key ("o-W") of every date in {@see $slots_by_date}, and the
	 * reverse index. Built once per allocation run for
	 * {@see breaks_week_feasibility()}.
	 *
	 * @var array<string,string> date => week key
	 */
	private $week_of_date = array();

	/**
	 * @var array<string,string[]> week key => dates with slots that week
	 */
	private $dates_by_week = array();

	/**
	 * Count of games with soft constraint violations
	 */
	private $constraint_violations = 0;

	/**
	 * When false (the default) a pair of teams may not meet twice on the same
	 * date. H15: double round-robin used to emit both meetings of a pair back
	 * to back and the conflict check only rejected time-OVERLAPPING games, so
	 * the rematch landed the same night one slot later.
	 *
	 * {@see allocate()} flips this to true for a final relaxed retry so a
	 * genuinely tight configuration (e.g. a one-day tournament) can still be
	 * scheduled rather than failing outright.
	 *
	 * @var bool
	 */
	private $allow_same_date_rematch = false;

	/**
	 * Set when the strict same-date rematch rule actually rejected a slot during
	 * the current pass. Used to decide whether a relaxed retry is worth running.
	 *
	 * @var bool
	 */
	private $same_date_rematch_blocked = false;

	/**
	 * When false (the default) a team may not play twice in the same real
	 * (Mon-Sun) calendar week -- on more than one date, or twice on the same
	 * date. Previously only discouraged via a soft cost ({@see
	 * SAME_DATE_TEAM_PENALTY}) that other pressures (pacing, date-load) could
	 * outweigh, and only ever looked at the exact same date, so a team could
	 * freely get a Friday AND a Sunday game the same week with nothing to
	 * stop it.
	 *
	 * {@see allocate()} flips this to true for a final relaxed retry so a
	 * genuinely tight configuration can still be scheduled rather than
	 * failing outright.
	 *
	 * @var bool
	 */
	private $allow_same_week_doubleheader = false;

	/**
	 * Set when the strict same-week rule actually rejected a slot during the
	 * current pass. Used to decide whether a relaxed retry is worth running.
	 *
	 * @var bool
	 */
	private $same_week_doubleheader_blocked = false;

	/**
	 * Maximum number of valid candidate slots scored per matchup.
	 *
	 * Candidates are gathered from the dates closest to the matchup's pace
	 * target outwards (see {@see find_best_slot()}), so this bounds the cost
	 * evaluation to the N placeable slots nearest the point in the season where
	 * the game belongs; within that window the lowest-cost slot wins (H14).
	 *
	 * The window used to be walked chronologically from the season start. Once
	 * a division's early dates were occupied, both teams of every remaining
	 * matchup already played on every date inside the window, so the
	 * double-header penalty had nowhere to steer and the game landed on an
	 * early date anyway: a real 272-game season came out with 124 team
	 * double-headers packed into 31 of 49 dates with the last two months empty.
	 */
	const MAX_SLOT_CANDIDATES = 15;

	/**
	 * Cost charged per playing date between a candidate slot and the matchup's
	 * pace target. The k-th of a team's T games belongs roughly k/T of the way
	 * through the season; this term keeps the pick near that point when the
	 * soft constraints alone would not care, while staying small enough that a
	 * clear time-slot or day-balance win can still move a game by a date or two.
	 */
	const PACING_COST_PER_DATE = 20.0;

	/**
	 * Cost charged for how full a candidate date already is, scaled by the
	 * date's load relative to its target (a date carrying its whole target
	 * costs the full amount). Every team's k-th game shares the same pace
	 * target, so without this term all divisions pile onto the target date
	 * until it is full and the dates in between stay nearly empty; with it a
	 * date that already carries several games loses to a lightly loaded
	 * neighbour.
	 *
	 * The target is the day's configured share of the season's games divided
	 * over that day's dates (see {@see $date_target_load}), not the date's raw
	 * capacity: measuring against capacity would silently pull games toward
	 * whichever day has more slots and override the operator's day balance.
	 *
	 * The charge grows with the square of load / target, so a date below its
	 * target stays cheap (a game there is not worth a two-date pace slip) while
	 * a date past its target quickly loses to any neighbour with room.
	 */
	const DATE_LOAD_COST = 60.0;

	/**
	 * Cost charged for how full a candidate VENUE already is on the candidate
	 * date, relative to that venue's own slot capacity that day (2026-09-14,
	 * H: an operator with two venues found one always filled to capacity
	 * before the other's slots were ever touched -- e.g. a 5-Friday-slot
	 * primary venue and a 5-Friday-slot secondary got 0-5 games some weeks
	 * and a full 5 others, purely because the first venue happened to be
	 * listed first in the configuration). Without this term nothing
	 * distinguishes a slot at an already-busy venue from one at an empty
	 * venue on the same date -- they cost the same, so ties always fall to
	 * whichever venue's slots were generated first (get_available_venues_for_date()'s
	 * iteration order over $config->venues), regardless of load.
	 *
	 * Ratio, not raw capacity, matching DATE_LOAD_COST's own reasoning:
	 * measuring against capacity would pull games toward whichever venue has
	 * more slots that day rather than balancing what each venue already has
	 * relative to its OWN capacity. A venue below its own capacity stays
	 * cheap; one already near full quickly loses to a lightly loaded venue on
	 * the same date. Deliberately independent of DATE_LOAD_COST/day-balance --
	 * this only ever compares venues against themselves, never against the
	 * date's overall target, so it cannot fight the day-ratio tuning.
	 *
	 * Only ever consulted via calculate_slot_cost(), which only
	 * find_best_slot() calls, which only greedy_allocate() calls --
	 * backtrack_allocate()'s own search (the fallback when greedy fails)
	 * is a separate, simpler first-fit scan with no cost function of any
	 * kind, venue balance included. A season that needs the backtracking
	 * fallback does not get this term's benefit; that scan is its own,
	 * pre-existing, separate concern.
	 */
	const VENUE_LOAD_COST = 60.0;

	/**
	 * Target number of games per playing date, keyed by date. Built in
	 * {@see allocate()} from the total matchup count and the day shares the
	 * configuration asks for ({@see SPSG_Schedule_Helper::resolve_day_ratios()}).
	 * Empty when find_best_slot() is used without allocate(); the load term
	 * then falls back to the fraction of the date's slots in use.
	 *
	 * @var array<string,float>
	 */
	private $date_target_load = array();

	/**
	 * Total games each team will play, counted from the matchup list handed to
	 * {@see allocate()}. Used to compute pace targets; falls back to the
	 * configuration's games_per_team when a team is missing.
	 *
	 * @var array<string,int>
	 */
	private $team_total_games = array();

	/**
	 * Cost credited to a slot at the home team's preferred venue. Large enough
	 * to dominate the soft-constraint terms so a configured preference is
	 * honoured whenever a preferred-venue slot is among the candidates, which
	 * preserves the previous "return the preferred venue immediately" behaviour.
	 */
	const PREFERRED_VENUE_BONUS = 1000.0;

	/**
	 * Cost charged per game a participating team already has on the candidate
	 * date. The distribution constraint scores days of the *week*, so it cannot
	 * tell "twice this Friday" apart from "once each of two Fridays"; without
	 * this term nothing discourages double-headers (H14). Scaled above the
	 * division-grouping terms so packing a venue never justifies making a team
	 * play twice in one night, but below the preferred-venue credit.
	 */
	const SAME_DATE_TEAM_PENALTY = 250.0;

	/**
	 * Cost credited, per matching `overlap_avoid` restriction group, to a slot
	 * on a date where the OTHER team in that group already has a game.
	 * `overlap_avoid` restrictions exist because the two teams share a
	 * roster player (SPSG_Team_Restriction_Constraint already refuses to
	 * place their games at overlapping/too-close times) -- this is the
	 * separate, softer preference that when both teams play their own
	 * (different) games in the same week, it's nicer for that shared player
	 * if both land on the same day rather than one each on Friday and
	 * Sunday. A preference only: smaller than SAME_DATE_TEAM_PENALTY so it
	 * never argues for a double-header, and well below
	 * PREFERRED_VENUE_BONUS/PACING_COST_PER_DATE's multi-date swing so a
	 * genuinely better pacing/venue choice still wins when the two pull in
	 * different directions.
	 */
	const OVERLAP_AVOID_SAME_DAY_BONUS = 120.0;

	/**
	 * Set true when greedy_allocate() / backtrack_allocate() exited because
	 * of a user-initiated cancellation rather than a genuine "cannot place
	 * this matchup" failure. The caller uses this to skip the backtracking
	 * fallback (which would just hit the same cancel signal) and surface
	 * the cancellation to the engine.
	 *
	 * @var bool
	 */
	private $was_cancelled = false;

	/**
	 * Set true when greedy_allocate() / backtrack_allocate() exited because
	 * the engine-level timeout fired. Tracked independently from
	 * {@see $was_cancelled} so the caller can distinguish user cancel
	 * (409 Conflict) from runaway generation (408 Request Timeout) when
	 * surfacing the error to the UI.
	 *
	 * @var bool
	 */
	private $was_timed_out = false;

	/**
	 * Constructor
	 */
	public function __construct( $constraint_manager = null ) {
		$this->constraint_manager = $constraint_manager ?: new SPSG_Constraint_Manager();
	}

	/**
	 * Allocate all matchups to slots
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|WP_Error Array of game objects or error
	 */
	public function allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$this->log( 'Starting slot allocation' );
		$this->constraint_violations = 0;
		$this->was_cancelled = false;
		$this->was_timed_out = false;
		$this->allow_same_date_rematch   = false;
		$this->same_date_rematch_blocked = false;
		$this->allow_same_week_doubleheader   = false;
		$this->same_week_doubleheader_blocked = false;

		// Scale backtrack depth with the size of the workload — the default
		// of 50 is meaningless for a 200-game season. Engine-level timeout
		// and cancellation transients still bound total runtime.
		$this->max_backtrack_depth = max( 50, count( $matchups ) * 5 );

		// Per-team game totals drive the pace targets in find_best_slot().
		$this->team_total_games = array();
		foreach ( $matchups as $matchup ) {
			foreach ( array( $matchup->home_team, $matchup->away_team ) as $team ) {
				$team_id                            = $this->extract_id( $team );
				$this->team_total_games[ $team_id ] = ( $this->team_total_games[ $team_id ] ?? 0 ) + 1;
			}
		}

		// Generate available slots
		$this->available_slots = $this->generate_available_slots( $config );

		// Build a date → slots index for fast chronological lookups, and each
		// date's per-venue slot count for VENUE_LOAD_COST.
		$this->slots_by_date = array();
		$this->venue_capacity_by_date = array();
		foreach ( $this->available_slots as $slot ) {
			$this->slots_by_date[ $slot->date ][] = $slot;
			$venue_id = $this->extract_id( $slot->venue );
			$this->venue_capacity_by_date[ $slot->date ][ $venue_id ] =
				( $this->venue_capacity_by_date[ $slot->date ][ $venue_id ] ?? 0 ) + 1;
		}
		$this->sorted_slot_dates = array_keys( $this->slots_by_date );
		sort( $this->sorted_slot_dates );

		$this->week_of_date  = array();
		$this->dates_by_week = array();
		foreach ( $this->sorted_slot_dates as $date ) {
			$week = SPSG_Schedule_Helper::iso_week_key( $date );
			if ( null === $week ) {
				continue;
			}
			$this->week_of_date[ $date ]    = $week;
			$this->dates_by_week[ $week ][] = $date;
		}

		$this->date_target_load = $this->build_date_target_load( $matchups, $config );

		if ( empty( $this->available_slots ) ) {
			return new WP_Error(
				'no_available_slots',
				__( 'No available time slots found. Check your configuration.', 'sportspress-schedule-generator' )
			);
		}

		$this->log( sprintf( 'Generated %d available slots', count( $this->available_slots ) ) );

		// Round-first pass: when every matchup is intra-division, assign each
		// division's rounds to weeks before touching a single slot, so the
		// one-game-per-team-per-week structure is built in rather than
		// searched for. Falls through to the game-by-game passes below when
		// the season doesn't have that shape.
		$schedule = $this->round_based_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );
		if ( $this->was_cancelled ) {
			return $this->build_cancellation_error( count( $matchups ) );
		}
		if ( $this->was_timed_out ) {
			return $this->build_timeout_error( count( $matchups ) );
		}
		if ( false !== $schedule ) {
			$this->log( 'Round-based allocation succeeded' );
			return $schedule;
		}

		// Try greedy allocation first (fast)
		$schedule = $this->greedy_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $schedule !== false ) {
			$this->log( 'Greedy allocation succeeded' );
			return $schedule;
		}

		// If greedy exited because the user cancelled or the engine timed
		// out, don't bother trying backtracking — the same signal will
		// still be true and we'd waste another budget of work to fail.
		if ( $this->was_cancelled ) {
			return $this->build_cancellation_error( count( $matchups ) );
		}
		if ( $this->was_timed_out ) {
			return $this->build_timeout_error( count( $matchups ) );
		}

		$this->log( 'Greedy allocation failed, trying backtracking' );

		// Greedy failed, try backtracking (slower but more thorough)
		$schedule = $this->backtrack_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $this->was_cancelled ) {
			return $this->build_cancellation_error( count( $matchups ) );
		}
		if ( $this->was_timed_out ) {
			return $this->build_timeout_error( count( $matchups ) );
		}

		if ( $schedule === false ) {
			// H15/{@see $allow_same_week_doubleheader}: these are hard rules
			// during the normal passes, but neither must be the sole reason a
			// season cannot be generated at all (a one-day tournament
			// legitimately replays pairs; a genuinely tight config may need a
			// double-header). If either actually blocked slots, retry greedily
			// with just the rules that were blamed relaxed, before surfacing
			// a failure.
			$relaxed = $this->retry_with_relaxed_rules( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

			if ( $this->was_cancelled ) {
				return $this->build_cancellation_error( count( $matchups ) );
			}
			if ( $this->was_timed_out ) {
				return $this->build_timeout_error( count( $matchups ) );
			}
			if ( $relaxed !== false ) {
				return $relaxed;
			}

			return new WP_Error(
				'allocation_failed',
				__( 'Could not allocate all games. Try adjusting time slots, venues, or blackout dates.', 'sportspress-schedule-generator' ),
				array(
					'total_matchups' => count( $matchups ),
					'available_slots' => count( $this->available_slots ),
				)
			);
		}

		$this->log( 'Backtracking allocation succeeded' );
		return $schedule;
	}

	/**
	 * Retry greedy allocation with whichever hard spacing rules actually
	 * blocked a slot during the failed strict passes relaxed -- and only
	 * those. See {@see $allow_same_date_rematch} and
	 * {@see $allow_same_week_doubleheader}.
	 *
	 * @param array                       $matchups Array of matchup objects.
	 * @param SPSG_Schedule_Configuration $config Configuration.
	 * @param callable|null               $progress_callback Callback for progress updates.
	 * @param callable|null               $cancellation_callback Callback to check for cancellation.
	 * @param callable|null               $timeout_callback Callback to check for timeout.
	 * @return array|false Array of games, or false when no rule needed relaxing or the retry also failed.
	 */
	private function retry_with_relaxed_rules( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback ) {
		if ( ! $this->relax_blocked_spacing_rules() ) {
			return false;
		}

		$this->log( 'Allocation failed with strict spacing; retrying relaxed' );
		$schedule = $this->greedy_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $schedule !== false ) {
			$this->log( 'Relaxed allocation succeeded' );
		}

		return $schedule;
	}

	/**
	 * Round-based allocation: rounds first, slots second.
	 *
	 * A season of intra-division matchups under the same-week rule has a
	 * shape the game-by-game passes can't see: each week, a division either
	 * plays a full round (every one of its teams once) or sits out, and a
	 * week's capacity decides how many divisions fit. Searching for that
	 * structure one game at a time is hopeless at real sizes -- a 272-game
	 * season into exactly 272 slots stalled the greedy pass at game 38 and
	 * exhausted backtracking, even though every division's matchups do
	 * decompose into full rounds. So build it directly:
	 *
	 *  1. Split each division's matchups into rounds (perfect matchings of
	 *     its teams, each pair used exactly as often as the matchup list
	 *     says).
	 *  2. Walk the weeks in order deciding which divisions play: a division
	 *     that has exactly as many rounds left as weeks it can still fit in
	 *     must play; otherwise it plays when it is "owed" a game, so its idle
	 *     weeks spread evenly over the season rather than bunching at either
	 *     end, and a short week takes whichever owed divisions fit its
	 *     capacity (so the teams a short week couldn't take are the ones
	 *     owed a game by the next one).
	 *  3. Place each week's games into that week's slots with the same
	 *     validity and cost scoring as {@see find_best_slot()}, so
	 *     restrictions, venue/day balance and preferences all still apply.
	 *
	 * Returns false -- leaving the game-by-game passes to run exactly as
	 * before -- when any matchup is inter-division, a division's matchups
	 * don't split into rounds, the week plan can't fit every round, or a
	 * week's games can't all be placed in its slots.
	 *
	 * @return array|false Complete schedule, or false to fall through.
	 */
	private function round_based_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback ) {
		$divisions = $this->rounds_by_division( $matchups );
		if ( null === $divisions ) {
			return false;
		}

		$plan = $this->plan_division_weeks( $divisions );
		if ( null === $plan ) {
			$this->log( 'Round-based allocation: no week plan fits every round' );
			return false;
		}

		$schedule         = array();
		$used_slots       = array();
		$schedule_by_date = array();

		foreach ( $plan as $week => $games ) {
			if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
				$this->was_cancelled = true;
				return false;
			}
			if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
				$this->was_timed_out = true;
				return false;
			}

			$placed = $this->place_week_games( $games, $this->dates_by_week[ $week ], $used_slots, $schedule_by_date, $config );
			if ( null === $placed ) {
				$this->log( sprintf( 'Round-based allocation: could not place week %s', $week ) );
				return false;
			}

			foreach ( $placed as $game ) {
				$same_day_games = array_filter(
					$schedule_by_date[ $game->date ],
					function ( $existing ) use ( $game ) {
						return $existing->id !== $game->id;
					}
				);
				if ( $this->constraint_manager->calculate_violation_cost( $game, $same_day_games, $config, $schedule_by_date ) > 0 ) {
					$this->constraint_violations++;
				}
				$schedule[] = $game;
			}

			if ( $progress_callback ) {
				call_user_func( $progress_callback, count( $schedule ) );
			}
		}

		return $schedule;
	}

	/**
	 * Each division's matchups split into rounds.
	 *
	 * @param array $matchups Matchup objects.
	 * @return array<string,array{size:int,rounds:array<int,object[]>}>|null
	 *         Keyed by division; null when the season isn't purely
	 *         intra-division or some division doesn't decompose.
	 */
	private function rounds_by_division( $matchups ) {
		$by_division = array();
		foreach ( $matchups as $matchup ) {
			if ( ! empty( $matchup->is_inter_division ) ) {
				return null;
			}
			$by_division[ $this->division_key( $matchup->division ) ][] = $matchup;
		}

		$divisions = array();
		foreach ( $by_division as $key => $division_matchups ) {
			$rounds = $this->decompose_into_rounds( $division_matchups );
			if ( null === $rounds ) {
				$this->log( sprintf( 'Round-based allocation: division %s does not split into full rounds', $key ) );
				return null;
			}
			$divisions[ $key ] = array(
				'size'   => count( $rounds[0] ),
				'rounds' => $rounds,
			);
		}

		return $divisions;
	}

	/**
	 * Split one division's matchups into rounds: perfect matchings of the
	 * division's teams that together use each matchup exactly once.
	 *
	 * Depth-first over the division's perfect matchings with the pair
	 * multiplicities as the budget. Fast for real division sizes (15
	 * matchings for 6 teams, 105 for 8, 945 for 10); above 12 teams the
	 * matching list is too large and the division is declined.
	 *
	 * @param object[] $matchups This division's matchups.
	 * @return array<int,object[]>|null Rounds, or null when no decomposition exists.
	 */
	private function decompose_into_rounds( $matchups ) {
		$teams    = array();
		$by_pair  = array();
		foreach ( $matchups as $matchup ) {
			$home = $this->extract_id( $matchup->home_team );
			$away = $this->extract_id( $matchup->away_team );
			$teams[ $home ] = true;
			$teams[ $away ] = true;
			$by_pair[ $this->pair_key( $home, $away ) ][] = $matchup;
		}
		$teams = array_keys( $teams );
		sort( $teams );

		if ( count( $teams ) % 2 !== 0 || count( $teams ) > 12 ) {
			return null;
		}
		$round_size = count( $teams ) / 2;
		if ( count( $matchups ) % $round_size !== 0 ) {
			return null;
		}

		$matchings = $this->perfect_matchings( $teams );
		$remaining = array_map( 'count', $by_pair );
		$chosen    = array();
		if ( ! $this->pick_rounds( $matchings, $remaining, count( $matchups ) / $round_size, 0, $chosen ) ) {
			return null;
		}

		$rounds = array();
		foreach ( $chosen as $matching ) {
			$round = array();
			foreach ( $matching as $pair ) {
				$round[] = array_pop( $by_pair[ $pair ] );
			}
			$rounds[] = $round;
		}
		return $rounds;
	}

	/**
	 * Depth-first choice of $needed matchings (repeats allowed) that exactly
	 * consume $remaining, the per-pair matchup counts.
	 *
	 * @param array<int,string[]> $matchings All perfect matchings, as lists of pair keys.
	 * @param array<string,int>   $remaining Pair key => matchups still to place.
	 * @param int                 $needed    Rounds still to choose.
	 * @param int                 $from      First matching index to consider (repeats are non-decreasing).
	 * @param array<int,string[]> $chosen    Output: the chosen matchings.
	 * @return bool
	 */
	private function pick_rounds( $matchings, &$remaining, $needed, $from, &$chosen ) {
		if ( 0 === $needed ) {
			return 0 === array_sum( $remaining );
		}
		$count = count( $matchings );
		for ( $i = $from; $i < $count; $i++ ) {
			foreach ( $matchings[ $i ] as $pair ) {
				if ( ( $remaining[ $pair ] ?? 0 ) <= 0 ) {
					continue 2;
				}
			}
			foreach ( $matchings[ $i ] as $pair ) {
				$remaining[ $pair ]--;
			}
			$chosen[] = $matchings[ $i ];
			if ( $this->pick_rounds( $matchings, $remaining, $needed - 1, $i, $chosen ) ) {
				return true;
			}
			array_pop( $chosen );
			foreach ( $matchings[ $i ] as $pair ) {
				$remaining[ $pair ]++;
			}
		}
		return false;
	}

	/**
	 * Every perfect matching of an even-sized team list, as lists of pair keys.
	 *
	 * @param string[] $teams Team ids.
	 * @return array<int,string[]>
	 */
	private function perfect_matchings( $teams ) {
		if ( empty( $teams ) ) {
			return array( array() );
		}
		$first  = array_shift( $teams );
		$result = array();
		foreach ( $teams as $index => $partner ) {
			$rest = $teams;
			unset( $rest[ $index ] );
			foreach ( $this->perfect_matchings( array_values( $rest ) ) as $matching ) {
				array_unshift( $matching, $this->pair_key( $first, $partner ) );
				$result[] = $matching;
			}
		}
		return $result;
	}

	/**
	 * Grouping key for a matchup's division. Divisions authored in the admin
	 * carry an EMPTY id (the config stores `'id' => ''`), which
	 * {@see extract_id()}'s null-coalescing would return as-is and so fold
	 * every division into one; fall back to the name in that case.
	 */
	private function division_key( $division ) {
		$id = $this->extract_id( $division );
		if ( '' !== (string) $id ) {
			return (string) $id;
		}
		if ( is_object( $division ) ) {
			return (string) ( $division->name ?? '' );
		}
		return (string) ( is_array( $division ) ? ( $division['name'] ?? '' ) : $division );
	}

	/**
	 * Order-independent key for a pair of team ids.
	 */
	private function pair_key( $team_a, $team_b ) {
		return $team_a < $team_b ? $team_a . '|' . $team_b : $team_b . '|' . $team_a;
	}

	/**
	 * Decide which divisions play in each week -- see step 2 of
	 * {@see round_based_allocate()}.
	 *
	 * @param array $divisions Output of {@see rounds_by_division()}.
	 * @return array<string,object[]>|null Week key => that week's games, or null if
	 *                                     some division's rounds can't all be fitted.
	 */
	private function plan_division_weeks( $divisions ) {
		$weeks    = array_keys( $this->dates_by_week );
		$capacity = $this->free_slots_by_week( array() );

		$fits_from = $this->weeks_fitting_from( $divisions, $weeks, $capacity );
		if ( null === $fits_from ) {
			return null;
		}
		list( $max_count, $idle_per_full_week ) = $this->week_idle_plan( $divisions, $weeks, $capacity, $fits_from );

		$left = array();
		$owed = array();
		foreach ( $divisions as $key => $division ) {
			$left[ $key ] = count( $division['rounds'] );
			$owed[ $key ] = 0.0;
		}

		$plan            = array();
		$idle_balance    = 0.0;
		$last_short_idle = array();
		foreach ( $weeks as $index => $week ) {
			$allowed   = $max_count[ $week ];
			$full_week = $allowed === count( $divisions );
			if ( $full_week ) {
				$idle_balance += $idle_per_full_week;
				if ( $idle_balance >= 1.0 ) {
					$idle_balance -= 1.0;
					$allowed--;
				}
			}

			$playing = $this->divisions_for_week(
				$divisions,
				$capacity[ $week ],
				$index,
				$fits_from,
				$left,
				$owed,
				$allowed,
				$full_week ? array() : $last_short_idle
			);
			if ( null === $playing ) {
				return null;
			}
			if ( ! $full_week ) {
				$last_short_idle = array_values( array_diff( array_keys( $divisions ), $playing ) );
			}

			$plan[ $week ] = array();
			foreach ( $playing as $key ) {
				$left[ $key ]--;
				foreach ( $divisions[ $key ]['rounds'][ $left[ $key ] ] as $matchup ) {
					$plan[ $week ][] = $matchup;
				}
			}
		}

		return array_sum( $left ) === 0 ? $plan : null;
	}

	/**
	 * For each division, how many weeks from each week index onwards its
	 * round fits in -- the "weeks left I can still play" figure the must-play
	 * rule needs. Null when some division can't fit all its rounds at all.
	 *
	 * @return array<string,array<int,int>>|null Division => week index => weeks fitting from there.
	 */
	private function weeks_fitting_from( $divisions, $weeks, $capacity ) {
		$fits_from = array();
		foreach ( $divisions as $key => $division ) {
			$running = 0;
			for ( $i = count( $weeks ) - 1; $i >= 0; $i-- ) {
				if ( $capacity[ $weeks[ $i ] ] >= $division['size'] ) {
					$running++;
				}
				$fits_from[ $key ][ $i ] = $running;
			}
			if ( $running < count( $division['rounds'] ) ) {
				return null;
			}
		}
		return $fits_from;
	}

	/**
	 * How many divisions each week can hold at once, and how many spare idle
	 * division-weeks to spend per full week.
	 *
	 * The season forces some idle weeks (a short week that can't fit every
	 * division); the rest are spare. Spending the spare ones one per full
	 * week on a fixed cadence keeps every week as full as the season allows
	 * -- otherwise divisions that all start level go idle in the SAME weeks
	 * and a week that could hold 13 games holds 6.
	 *
	 * @return array{0: array<string,int>, 1: float} [week => max divisions at once, spare idles per full week].
	 */
	private function week_idle_plan( $divisions, $weeks, $capacity, $fits_from ) {
		$max_count   = array();
		$forced_idle = 0;
		$full_weeks  = 0;
		foreach ( $weeks as $week ) {
			$sizes = array();
			foreach ( $divisions as $division ) {
				if ( $capacity[ $week ] >= $division['size'] ) {
					$sizes[] = $division['size'];
				}
			}
			sort( $sizes );
			$count = 0;
			$used  = 0;
			foreach ( $sizes as $size ) {
				if ( $used + $size > $capacity[ $week ] ) {
					break;
				}
				$used += $size;
				$count++;
			}
			$max_count[ $week ] = $count;
			$forced_idle       += count( $sizes ) - $count;
			if ( $count === count( $divisions ) ) {
				$full_weeks++;
			}
		}

		$spare_idle = 0;
		foreach ( $divisions as $key => $division ) {
			$spare_idle += $fits_from[ $key ][0] - count( $division['rounds'] );
		}
		$spare_idle = max( 0, $spare_idle - $forced_idle );

		return array( $max_count, $full_weeks > 0 ? $spare_idle / $full_weeks : 0.0 );
	}

	/**
	 * The divisions that play in one week.
	 *
	 * A division with exactly as many rounds left as weeks it can still fit
	 * in must play. The rest accrue (rounds left / weeks left) of a game per
	 * week and spend one when they play, and are admitted most-owed first
	 * until the week's capacity or $allowed count is used -- a division that
	 * didn't fit keeps its balance and is first in line next week. In a
	 * short week the divisions that sat out the PREVIOUS short week go to
	 * the front of the line regardless, so the teams one short week couldn't
	 * take are the ones the next short week does.
	 *
	 * @param array               $divisions  Output of {@see rounds_by_division()}.
	 * @param int                 $capacity   Slots in the week.
	 * @param int                 $index      Week index.
	 * @param array               $fits_from  Division => week index => weeks from there on it fits.
	 * @param array<string,int>   $left       Rounds left per division (read only here).
	 * @param array<string,float> $owed       Games owed per division (updated).
	 * @param int                 $allowed    Most divisions that may play this week.
	 * @param string[]            $rotate_in  Divisions owed a short week (sat out the last one).
	 * @return string[]|null Division keys playing this week; null when a must-play doesn't fit.
	 */
	private function divisions_for_week( $divisions, $capacity, $index, $fits_from, $left, &$owed, $allowed, $rotate_in ) {
		$must    = array();
		$willing = array();
		foreach ( $divisions as $key => $division ) {
			if ( $left[ $key ] <= 0 || $capacity < $division['size'] ) {
				continue;
			}
			$fits          = $fits_from[ $key ][ $index ];
			$owed[ $key ] += $left[ $key ] / $fits;
			if ( $left[ $key ] >= $fits ) {
				$must[] = $key;
			} else {
				$willing[] = $key;
			}
		}

		$playing = $must;
		$used    = 0;
		foreach ( $must as $key ) {
			$used += $divisions[ $key ]['size'];
		}
		if ( $used > $capacity ) {
			return null;
		}

		$playing = array_merge(
			$playing,
			$this->best_fitting_subset( $willing, $divisions, $capacity - $used, $allowed - count( $playing ), array_fill_keys( $rotate_in, true ), $owed )
		);

		foreach ( $playing as $key ) {
			$owed[ $key ] -= 1.0;
		}
		return $playing;
	}

	/**
	 * The subset of $candidates (at most $max_count of them, total size at
	 * most $room) that fills the most of $room; ties go to the subset with
	 * more divisions owed a short week, then to the one owed the most games.
	 *
	 * Enumerated outright -- a league has a handful of divisions -- because
	 * a greedy fill picks wrong in exactly the case that matters: a 4-slot
	 * night must take the 8-team division (4 games), not a 6-team one (3),
	 * or the season no longer closes.
	 *
	 * @param string[]            $candidates Division keys in a stable order.
	 * @param array               $divisions  Output of {@see rounds_by_division()}.
	 * @param int                 $room       Slots left in the week.
	 * @param int                 $max_count  Most divisions to admit.
	 * @param array<string,true>  $priority   Divisions owed a short week.
	 * @param array<string,float> $owed       Games owed per division.
	 * @return string[]
	 */
	private function best_fitting_subset( $candidates, $divisions, $room, $max_count, $priority, $owed ) {
		$best       = array();
		$best_score = null;
		$n          = count( $candidates );

		for ( $mask = 1; $mask < ( 1 << $n ); $mask++ ) {
			$subset = array();
			$size   = 0;
			$rotate = 0;
			$due    = 0.0;
			for ( $i = 0; $i < $n; $i++ ) {
				if ( ! ( $mask & ( 1 << $i ) ) ) {
					continue;
				}
				$key     = $candidates[ $i ];
				$size   += $divisions[ $key ]['size'];
				$rotate += isset( $priority[ $key ] ) ? 1 : 0;
				$due    += $owed[ $key ];
				$subset[] = $key;
			}
			if ( $size > $room || count( $subset ) > $max_count ) {
				continue;
			}
			$score = array( $size, $rotate, $due );
			if ( null === $best_score || $score > $best_score ) {
				$best       = $subset;
				$best_score = $score;
			}
		}

		return $best;
	}

	/**
	 * Place one week's games into that week's slots: most constrained game
	 * first, cheapest valid slot first, backtracking within the week only.
	 *
	 * @param object[] $games            Matchups planned for the week.
	 * @param string[] $dates            The week's dates.
	 * @param array    $used_slots       Slot keys already taken (updated on success).
	 * @param array    $schedule_by_date Schedule indexed by date (updated on success).
	 * @param object   $config           Schedule configuration.
	 * @return object[]|null The placed games, or null if the week can't be completed.
	 */
	private function place_week_games( $games, $dates, &$used_slots, &$schedule_by_date, $config ) {
		$placed = array();
		if ( ! $this->place_week_recursive( $games, $dates, $used_slots, $schedule_by_date, $config, $placed ) ) {
			return null;
		}
		return $placed;
	}

	/**
	 * Recursive helper for {@see place_week_games()}.
	 */
	private function place_week_recursive( $games, $dates, &$used_slots, &$schedule_by_date, $config, &$placed ) {
		if ( empty( $games ) ) {
			return true;
		}

		$pick       = null;
		$pick_slots = null;
		foreach ( $games as $position => $matchup ) {
			$slots = $this->valid_week_slots( $matchup, $dates, $used_slots, $schedule_by_date, $config, null === $pick_slots ? PHP_INT_MAX : count( $pick_slots ) );
			if ( empty( $slots ) ) {
				return false;
			}
			if ( null === $pick_slots || count( $slots ) < count( $pick_slots ) ) {
				$pick       = $position;
				$pick_slots = $slots;
			}
		}

		$matchup = $games[ $pick ];
		unset( $games[ $pick ] );

		foreach ( $pick_slots as $slot ) {
			$slot_key = $this->get_slot_key( $slot );
			$game     = $this->create_game( $matchup, $slot, $config );

			$used_slots[ $slot_key ]           = true;
			$schedule_by_date[ $game->date ][] = $game;
			$placed[]                          = $game;

			if ( $this->place_week_recursive( $games, $dates, $used_slots, $schedule_by_date, $config, $placed ) ) {
				return true;
			}

			array_pop( $placed );
			array_pop( $schedule_by_date[ $game->date ] );
			unset( $used_slots[ $slot_key ] );
		}

		return false;
	}

	/**
	 * A matchup's valid slots on the given dates, cheapest first. Stops
	 * collecting once $limit valid slots are found (the caller only needs to
	 * know it isn't the most constrained game).
	 *
	 * @return object[]
	 */
	private function valid_week_slots( $matchup, $dates, $used_slots, $schedule_by_date, $config, $limit ) {
		$home_id            = $this->extract_id( $matchup->home_team );
		$preferred_venue_id = empty( $config->home_away_preferences ) ? null : ( $config->home_away_preferences[ $home_id ] ?? null );

		$scored = array();
		foreach ( $dates as $date ) {
			foreach ( $this->slots_by_date[ $date ] as $slot ) {
				if ( isset( $used_slots[ $this->get_slot_key( $slot ) ] ) ) {
					continue;
				}
				$game = $this->create_game( $matchup, $slot, $config );
				if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config, $game ) ) {
					continue;
				}
				$scored[] = array(
					'slot' => $slot,
					'cost' => $this->calculate_slot_cost( $game, $slot, $schedule_by_date, $config, $preferred_venue_id ),
				);
				if ( count( $scored ) >= $limit ) {
					break 2;
				}
			}
		}

		usort(
			$scored,
			function ( $a, $b ) {
				return $a['cost'] <=> $b['cost'];
			}
		);
		return array_column( $scored, 'slot' );
	}

	/**
	 * Flip whichever hard spacing rules were actually blamed for the strict
	 * passes' failure -- and only those.
	 *
	 * @return bool Whether anything was relaxed (i.e. a retry is worth attempting).
	 */
	private function relax_blocked_spacing_rules() {
		$relaxed_anything = false;

		if ( $this->same_week_rule_was_blocked() ) {
			$this->allow_same_week_doubleheader = true;
			$relaxed_anything = true;
		}
		if ( $this->same_date_rule_was_blocked() ) {
			$this->allow_same_date_rematch = true;
			$relaxed_anything = true;
		}

		return $relaxed_anything;
	}

	/**
	 * @return bool Whether the same-week rule is still strict and actually rejected a slot.
	 */
	private function same_week_rule_was_blocked() {
		if ( $this->allow_same_week_doubleheader ) {
			return false;
		}
		return $this->same_week_doubleheader_blocked;
	}

	/**
	 * @return bool Whether the same-date rematch rule is still strict and actually rejected a slot.
	 */
	private function same_date_rule_was_blocked() {
		if ( $this->allow_same_date_rematch ) {
			return false;
		}
		return $this->same_date_rematch_blocked;
	}

	/**
	 * Generate all available time slots
	 *
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return array Array of slot objects
	 */
	public function generate_available_slots( $config ) {
		$slots = array();

		// Resolve timezone from config
		$tz = ! empty( $config->timezone ) ? new DateTimeZone( $config->timezone ) : wp_timezone();

		// Handle both string and DateTime objects
		if ( $config->season_start instanceof DateTime ) {
			$season_start = clone $config->season_start;
		} else {
			$season_start = new DateTime( $config->season_start, $tz );
		}

		if ( $config->season_end instanceof DateTime ) {
			$season_end = clone $config->season_end;
		} else {
			$season_end = new DateTime( $config->season_end, $tz );
		}

		$current_date = clone $season_start;

		// Get blackout dates for filtering
		$blackout_dates = $config->blackout_dates ?? array();

		while ( $current_date <= $season_end ) {
			$date_str = $current_date->format( 'Y-m-d' );
			$day_name = strtolower( $current_date->format( 'l' ) );

			// Skip if not a playing day
			if ( ! in_array( $day_name, $config->playing_days ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			// Skip blackout dates
			if ( in_array( $date_str, $blackout_dates ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			// Get available venues for this specific date
			$available_venues = $this->get_available_venues_for_date( $date_str, $day_name, $config );

			// Generate slots for each venue and its available time slots
			foreach ( $available_venues as $venue_data ) {
				$venue = $venue_data['venue'];
				$time_slots = $venue_data['time_slots'];

				foreach ( $time_slots as $time_slot ) {
					$slots[] = (object) array(
						'date' => $date_str,
						'day' => $day_name,
						'time_slot' => $time_slot,
						'venue' => $venue,
					);
				}
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		return $slots;
	}

	/**
	 * Greedy allocation algorithm
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|false Array of games or false on failure
	 */
	public function greedy_allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$schedule = array();
		$used_slots = array();
		$games_scheduled = 0;
		$check_counter = 0;

		// Optimization: indexed schedule for O(1) lookups by date.
		$schedule_by_date = array();

		foreach ( $matchups as $matchup ) {
			$check_counter++;

			// Check for cancellation/timeout every 25 matchups
			if ( $check_counter % 25 === 0 ) {
				if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
					// Returning a partial schedule here used to fool the caller
					// into treating cancellation as success. Mark it as a
					// cancellation and return false so backtracking is skipped.
					$this->was_cancelled = true;
					return false;
				}

				if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
					// Distinguish timeout from user cancellation so the caller
					// can surface the right error code / HTTP status.
					$this->was_timed_out = true;
					return false;
				}
			}

			$best_slot = $this->find_best_slot( $matchup, $used_slots, $schedule_by_date, $config );

			if ( ! $best_slot ) {
				// Greedy allocation failed
				return false;
			}

			// Create game and add to schedule
			$game = $this->create_game( $matchup, $best_slot, $config );
			$schedule[] = $game;
			$games_scheduled++;

			// Track soft constraint violations. Pass the full date-indexed schedule
			// so cross-day soft constraints (distribution) see the entire run.
			$same_day_games = $schedule_by_date[ $game->date ] ?? array();
			$cost = $this->constraint_manager->calculate_violation_cost( $game, $same_day_games, $config, $schedule_by_date );
			if ( $cost > 0 ) {
				$this->constraint_violations++;
			}

			// Index by date for fast lookups.
			if ( ! isset( $schedule_by_date[ $game->date ] ) ) {
				$schedule_by_date[ $game->date ] = array();
			}
			$schedule_by_date[ $game->date ][] = $game;

			// Mark slot as used
			$slot_key = $this->get_slot_key( $best_slot );
			$used_slots[ $slot_key ] = true;

			// Update progress every 10 games
			if ( $progress_callback && $games_scheduled % 10 === 0 ) {
				call_user_func( $progress_callback, $games_scheduled );
			}
		}

		// Final progress update
		if ( $progress_callback ) {
			call_user_func( $progress_callback, $games_scheduled );
		}

		return $schedule;
	}

	/**
	 * Backtracking allocation algorithm
	 *
	 * @param array                       $matchups Array of matchup objects
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @param callable|null               $progress_callback Callback for progress updates
	 * @param callable|null               $cancellation_callback Callback to check for cancellation
	 * @param callable|null               $timeout_callback Callback to check for timeout
	 * @return array|false Array of games or false on failure
	 */
	public function backtrack_allocate( $matchups, $config, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		$schedule = array();
		$used_slots = array();
		$schedule_by_date = array();

		// M53: bound the search by work done, not just by the wall clock.
		//
		// The per-matchup constant alone ignores how many slots exist to
		// search through. A single clean pass with zero backtracking still
		// visits, in the worst case, one slot examination per (matchup,
		// candidate-before-the-valid-one) pair, which grows with the slot
		// list size — a large season (many slots) could exhaust the old flat
		// count($matchups) * BACKTRACK_VISITS_PER_MATCHUP budget before
		// backtracking got any real room to retry a choice, independent of
		// whether the configuration was actually infeasible. (This was NOT
		// what made the W2026-27 season fail at 17 games/team — that was a
		// hidden 15-minute same-venue buffer that halved real slot capacity,
		// fixed separately; with it gone, greedy allocation alone succeeds
		// and this budget is never exercised on that config. This fix stands
		// on its own: it makes the budget scale with the actual search space
		// instead of only the matchup count, which is still correct for any
		// season where greedy fails and backtracking has real work to do.)
		// Scale the per-matchup allowance by the slot list size too, so the
		// budget always covers several full passes' worth of search, not
		// less than one.
		$per_matchup_allowance = max( self::BACKTRACK_VISITS_PER_MATCHUP, count( $this->available_slots ) * 3 );
		$this->backtrack_budget           = max(
			self::BACKTRACK_MIN_VISITS,
			count( $matchups ) * $per_matchup_allowance
		);
		$this->backtrack_budget_exhausted = false;

		// Incrementally maintained view of the search state, so choosing the
		// next matchup and its candidate weeks is O(teams x weeks) per node
		// instead of a rescan of the schedule.
		$state = array(
			'team_weeks'  => array(),
			'team_placed' => array(),
			'free'        => $this->free_slots_by_week( array() ),
		);

		$result = $this->backtrack_recursive( $matchups, array_keys( $matchups ), $state, $schedule, $used_slots, $schedule_by_date, $config, 0, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $this->backtrack_budget_exhausted ) {
			$this->log( 'Backtracking gave up: node budget exhausted' );
		}

		return $result ? $schedule : false;
	}

	/**
	 * Recursive backtracking helper.
	 *
	 * Places the most constrained remaining matchup first -- the one with the
	 * fewest weeks both its teams are still free in -- and tries its candidate
	 * slots cheapest first. This used to walk the matchup list in its original
	 * (division-grouped) order and every slot in chronological order, which
	 * made the search blind in exactly the case it exists for: a season sized
	 * close to its slot count, where the games with only one possible week
	 * left sit at the END of the list behind dozens of flexible ones. The
	 * depth-first search then burned its whole budget re-shuffling flexible
	 * games while the doomed choice sat far up the stack. Picking the tightest
	 * matchup next means a dead end (a matchup with no open week at all) is
	 * found the moment it's created, not after the rest of the list has been
	 * tried against it.
	 *
	 * @param array $matchups  All matchups, by original index.
	 * @param int[] $remaining Indexes into $matchups not yet placed.
	 * @param array $state     See {@see backtrack_allocate()}.
	 */
	private function backtrack_recursive( $matchups, $remaining, &$state, &$schedule, &$used_slots, &$schedule_by_date, $config, $depth, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		if ( $this->backtrack_must_stop( $depth, $cancellation_callback, $timeout_callback ) ) {
			return false;
		}
		if ( empty( $remaining ) ) {
			return true;
		}

		if ( $progress_callback && $depth % 10 === 0 ) {
			call_user_func( $progress_callback, $depth );
		}

		$pick = $this->most_constrained_matchup( $matchups, $remaining, $state );
		if ( null === $pick ) {
			return false;
		}
		$matchup = $matchups[ $remaining[ $pick ] ];
		unset( $remaining[ $pick ] );
		$remaining = array_values( $remaining );

		foreach ( $this->rank_candidate_slots( $matchup, $used_slots, $schedule_by_date, $state, $config ) as $slot ) {
			$slot_key = $this->get_slot_key( $slot );
			$game     = $this->create_game( $matchup, $slot, $config );
			$this->push_backtrack_game( $game, $slot_key, $state, $schedule, $used_slots, $schedule_by_date );

			if ( $this->backtrack_recursive( $matchups, $remaining, $state, $schedule, $used_slots, $schedule_by_date, $config, $depth + 1, $progress_callback, $cancellation_callback, $timeout_callback ) ) {
				return true;
			}

			$this->pop_backtrack_game( $game, $slot_key, $state, $schedule, $used_slots, $schedule_by_date );
		}

		return false;
	}

	/**
	 * Whether the backtracking search has to give up at this node: the user
	 * cancelled, the engine timed out, the depth guard tripped, or (M53) the
	 * node budget is spent.
	 */
	private function backtrack_must_stop( $depth, $cancellation_callback, $timeout_callback ) {
		if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
			$this->was_cancelled = true;
			return true;
		}
		if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
			$this->was_timed_out = true;
			return true;
		}
		if ( $depth > $this->max_backtrack_depth ) {
			return true;
		}
		if ( $this->backtrack_budget <= 0 ) {
			$this->backtrack_budget_exhausted = true;
			return true;
		}
		return false;
	}

	/**
	 * Position in $remaining of the matchup with the fewest open weeks, or
	 * null when some remaining matchup has none (a dead end). Earlier
	 * position wins ties, preserving the original order where nothing
	 * distinguishes two matchups.
	 *
	 * @param array $matchups  All matchups, by original index.
	 * @param int[] $remaining Indexes into $matchups not yet placed.
	 * @param array $state     Search state (see {@see backtrack_allocate()}).
	 * @return int|null
	 */
	private function most_constrained_matchup( $matchups, $remaining, $state ) {
		$pick      = null;
		$pick_open = null;

		foreach ( $remaining as $position => $index ) {
			$open = count( $this->open_weeks_for( $matchups[ $index ], $state ) );
			if ( 0 === $open ) {
				return null;
			}
			if ( null === $pick_open || $open < $pick_open ) {
				$pick      = $position;
				$pick_open = $open;
				if ( 1 === $open ) {
					break;
				}
			}
		}

		return $pick;
	}

	/**
	 * Weeks that still have a free slot and that neither team of the matchup
	 * plays in yet. Once the same-week rule is relaxed every week with room
	 * qualifies.
	 *
	 * @param object $matchup Matchup object.
	 * @param array  $state   Search state (see {@see backtrack_allocate()}).
	 * @return string[] Week keys.
	 */
	private function open_weeks_for( $matchup, $state ) {
		$home_id = $this->extract_id( $matchup->home_team );
		$away_id = $this->extract_id( $matchup->away_team );
		$weeks   = array();

		foreach ( $state['free'] as $week => $free_slots ) {
			if ( $free_slots <= 0 ) {
				continue;
			}
			if ( ! $this->allow_same_week_doubleheader
				&& ( ! empty( $state['team_weeks'][ $home_id ][ $week ] ) || ! empty( $state['team_weeks'][ $away_id ][ $week ] ) ) ) {
				continue;
			}
			$weeks[] = $week;
		}

		return $weeks;
	}

	/**
	 * The matchup's valid slots in its open weeks, cheapest first -- the same
	 * scoring {@see find_best_slot()} uses, so a backtracked schedule keeps
	 * the greedy pass's placement quality rather than falling back to
	 * first-fit.
	 *
	 * Charges {@see $backtrack_budget} per slot validated; returns whatever
	 * was ranked so far once it runs out (the caller checks the exhausted
	 * flag before recursing further).
	 *
	 * @param object $matchup          Matchup object.
	 * @param array  $used_slots       Slot keys already taken.
	 * @param array  $schedule_by_date Schedule indexed by date.
	 * @param array  $state            Search state (see {@see backtrack_allocate()}).
	 * @param object $config           Schedule configuration.
	 * @return object[] Slots, best first.
	 */
	private function rank_candidate_slots( $matchup, $used_slots, $schedule_by_date, $state, $config ) {
		$home_id = $this->extract_id( $matchup->home_team );

		$preferred_venue_id = null;
		if ( ! empty( $config->home_away_preferences ) ) {
			$preferred_venue_id = $config->home_away_preferences[ $home_id ] ?? null;
		}

		$pacing     = $this->pacing_cost_by_date( $matchup, $state, $config );
		$open_weeks = array_fill_keys( $this->open_weeks_for( $matchup, $state ), true );
		$ranked     = array();

		foreach ( $this->sorted_slot_dates as $date ) {
			if ( empty( $open_weeks[ $this->week_of_date[ $date ] ?? '' ] ) ) {
				continue;
			}
			foreach ( $this->score_free_slots_on_date( $matchup, $date, $used_slots, $schedule_by_date, $config, $preferred_venue_id ) as $entry ) {
				$entry['cost'] += $pacing[ $date ] ?? 0.0;
				$ranked[]       = $entry;
			}
			if ( $this->backtrack_budget_exhausted ) {
				break;
			}
		}

		usort(
			$ranked,
			function ( $a, $b ) {
				return $a['cost'] <=> $b['cost'];
			}
		);

		return $this->cheapest_slot_per_date( $ranked );
	}

	/**
	 * {@see find_best_slot()}'s pacing term for every date, keyed by date.
	 *
	 * @param object $matchup Matchup object.
	 * @param array  $state   Search state (see {@see backtrack_allocate()}).
	 * @param object $config  Schedule configuration.
	 * @return array<string,float>
	 */
	private function pacing_cost_by_date( $matchup, $state, $config ) {
		$home_id = $this->extract_id( $matchup->home_team );
		$away_id = $this->extract_id( $matchup->away_team );
		$placed  = array(
			$home_id => (int) ( $state['team_placed'][ $home_id ] ?? 0 ),
			$away_id => (int) ( $state['team_placed'][ $away_id ] ?? 0 ),
		);

		$pacing = array();
		foreach ( $this->order_dates_by_pace( $this->sorted_slot_dates, $placed, $config ) as $entry ) {
			$pacing[ $entry['date'] ] = $entry['distance'] * self::PACING_COST_PER_DATE;
		}
		return $pacing;
	}

	/**
	 * Every unused, valid slot on $date with its soft-constraint cost.
	 * Charges {@see $backtrack_budget} per slot validated and stops early,
	 * flagging exhaustion, when it runs out.
	 *
	 * @return array<int,array{slot: object, cost: float}>
	 */
	private function score_free_slots_on_date( $matchup, $date, $used_slots, $schedule_by_date, $config, $preferred_venue_id ) {
		$scored = array();

		foreach ( $this->slots_by_date[ $date ] as $slot ) {
			if ( isset( $used_slots[ $this->get_slot_key( $slot ) ] ) ) {
				continue;
			}
			if ( --$this->backtrack_budget <= 0 ) {
				$this->backtrack_budget_exhausted = true;
				break;
			}
			$game = $this->create_game( $matchup, $slot, $config );
			if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config, $game ) ) {
				continue;
			}
			$scored[] = array(
				'slot' => $slot,
				'cost' => $this->calculate_slot_cost( $game, $slot, $schedule_by_date, $config, $preferred_venue_id ),
			);
		}

		return $scored;
	}

	/**
	 * One candidate per date: the cheapest valid slot on it.
	 *
	 * Which DATE a game lands on is the decision that interacts with every
	 * other game (through the same-week rule and week capacity); which of
	 * that date's remaining slots it takes is interchangeable for that
	 * purpose. Branching over every slot made the search re-try a doomed
	 * date choice once per slot permutation below it -- 16 slots a week
	 * meant a wrong week was revisited 16 x 15 x 14 ... times before the
	 * search ever moved the game to a different week, and a 64-game season
	 * that this pass solves in about a second would not finish in five
	 * minutes. The cost is that a later game blocked on this date only by a
	 * time-of-day rule (an overlap-avoid pair, a back-to-back rule) is not
	 * rescued by moving THIS game to another time on the same date; that
	 * needs a date already full enough to have no other slot clear of the
	 * conflict, and the relaxed retry in allocate() remains the fallback.
	 *
	 * @param array<int,array{slot: object, cost: float}> $ranked Cheapest first.
	 * @return object[] Slots, still cheapest first.
	 */
	private function cheapest_slot_per_date( $ranked ) {
		$by_date = array();
		foreach ( $ranked as $entry ) {
			$date = $entry['slot']->date;
			if ( ! isset( $by_date[ $date ] ) ) {
				$by_date[ $date ] = $entry['slot'];
			}
		}
		return array_values( $by_date );
	}

	/**
	 * Record a placed game in every structure the backtracking search keeps.
	 */
	private function push_backtrack_game( $game, $slot_key, &$state, &$schedule, &$used_slots, &$schedule_by_date ) {
		$schedule[]                        = $game;
		$used_slots[ $slot_key ]           = true;
		$schedule_by_date[ $game->date ][] = $game;

		$week = $this->week_of_date[ $game->date ] ?? null;
		if ( null !== $week ) {
			$state['free'][ $week ]--;
		}
		foreach ( array( $this->extract_id( $game->home_team ), $this->extract_id( $game->away_team ) ) as $team_id ) {
			$state['team_placed'][ $team_id ] = ( $state['team_placed'][ $team_id ] ?? 0 ) + 1;
			if ( null !== $week ) {
				$state['team_weeks'][ $team_id ][ $week ] = ( $state['team_weeks'][ $team_id ][ $week ] ?? 0 ) + 1;
			}
		}
	}

	/**
	 * Exact inverse of {@see push_backtrack_game()}.
	 */
	private function pop_backtrack_game( $game, $slot_key, &$state, &$schedule, &$used_slots, &$schedule_by_date ) {
		array_pop( $schedule );
		unset( $used_slots[ $slot_key ] );
		array_pop( $schedule_by_date[ $game->date ] );

		$week = $this->week_of_date[ $game->date ] ?? null;
		if ( null !== $week ) {
			$state['free'][ $week ]++;
		}
		foreach ( array( $this->extract_id( $game->home_team ), $this->extract_id( $game->away_team ) ) as $team_id ) {
			$state['team_placed'][ $team_id ]--;
			if ( null !== $week ) {
				$state['team_weeks'][ $team_id ][ $week ]--;
			}
		}
	}

	/**
	 * Find best available slot for matchup
	 *
	 * Uses date-indexed schedule for O(1) conflict checks and caps
	 * cost evaluation at {@see MAX_SLOT_CANDIDATES} valid slots for
	 * performance.
	 *
	 * H14: this used to return the first valid slot outright, which meant the
	 * soft (distribution) and optimization (division grouping) constraints never
	 * influenced placement at all. The lowest-cost candidate within a bounded
	 * window is used instead.
	 *
	 * Candidate selection:
	 *
	 *  1. Dates are visited from the matchup's pace target outwards. The k-th
	 *     of a team's T games belongs about k/T of the way through the season;
	 *     the target is the average of the two teams' positions. Walking dates
	 *     chronologically instead meant the window only ever saw the first few
	 *     dates of the season, and once those were occupied every remaining
	 *     game became a double-header on one of them.
	 *  2. Dates on which either team already plays are held back and only
	 *     scored when no other date can take the game, so a team plays twice on
	 *     one date only when the configuration genuinely leaves no alternative.
	 *  3. Within the window the constraint-manager cost decides, plus a small
	 *     pacing term ({@see PACING_COST_PER_DATE}) so the pick stays near the
	 *     target unless a soft constraint has a real reason to move it.
	 *
	 * @param object                      $matchup Matchup object
	 * @param array                       $used_slots Already used slots
	 * @param array                       $schedule_by_date Schedule indexed by date
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return object|null Best slot or null
	 */
	public function find_best_slot( $matchup, $used_slots, $schedule_by_date, $config ) {
		$home_id = $this->extract_id( $matchup->home_team );
		$away_id = $this->extract_id( $matchup->away_team );

		// Resolve home team's preferred venue (if configured)
		$preferred_venue_id = null;
		if ( ! empty( $config->home_away_preferences ) ) {
			$preferred_venue_id = $config->home_away_preferences[ $home_id ] ?? null;
		}

		$dates = ! empty( $this->sorted_slot_dates ) ? $this->sorted_slot_dates : array_keys( $this->slots_by_date );
		if ( empty( $dates ) ) {
			return null;
		}

		// Games already placed for each team, and the dates either team is
		// already playing on. One pass over the schedule, O(games).
		$placed     = array(
			$home_id => 0,
			$away_id => 0,
		);
		$busy_dates = array();
		foreach ( $schedule_by_date as $date => $games ) {
			foreach ( $games as $existing_game ) {
				if ( ! $this->has_team_conflict( $existing_game, $home_id, $away_id ) ) {
					continue;
				}
				$busy_dates[ $date ] = true;

				$existing_home = $this->extract_id( $existing_game->home_team );
				$existing_away = $this->extract_id( $existing_game->away_team );
				if ( $existing_home === $home_id || $existing_away === $home_id ) {
					$placed[ $home_id ]++;
				}
				if ( $existing_home === $away_id || $existing_away === $away_id ) {
					$placed[ $away_id ]++;
				}
			}
		}

		$ordered_dates = $this->order_dates_by_pace( $dates, $placed, $config );

		// Pass 1: dates neither team plays on. Pass 2 (double-headers) only
		// runs when pass 1 found nothing placeable at all.
		foreach ( array( false, true ) as $allow_busy ) {
			$best_slot          = null;
			$best_cost          = null;
			$candidates_checked = 0;

			foreach ( $ordered_dates as $entry ) {
				$date = $entry['date'];
				if ( isset( $busy_dates[ $date ] ) !== $allow_busy ) {
					continue;
				}

				$pacing_cost = $entry['distance'] * self::PACING_COST_PER_DATE;

				foreach ( $this->slots_by_date[ $date ] ?? array() as $slot ) {
					$slot_key = $this->get_slot_key( $slot );

					if ( isset( $used_slots[ $slot_key ] ) ) {
						continue;
					}

					// Build the game once and reuse it for validation and scoring.
					$game = $this->create_game( $matchup, $slot, $config );

					if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config, $game ) ) {
						continue;
					}

					$cost = $this->calculate_slot_cost( $game, $slot, $schedule_by_date, $config, $preferred_venue_id ) + $pacing_cost;

					if ( null === $best_cost || $cost < $best_cost ) {
						$best_cost = $cost;
						$best_slot = $slot;
					}

					$candidates_checked++;
					if ( $candidates_checked >= self::MAX_SLOT_CANDIDATES ) {
						break 2;
					}
				}
			}

			if ( null !== $best_slot ) {
				return $best_slot;
			}
		}

		return null;
	}

	/**
	 * Order the season's playing dates by distance from the matchup's pace
	 * target, nearest first (earlier date wins ties).
	 *
	 * When neither team's total is known (a caller that bypassed allocate()
	 * with a configuration that has no games_per_team) the dates come back in
	 * chronological order with a zero distance, i.e. the pre-pacing behaviour.
	 *
	 * @param array                       $dates  Chronologically sorted dates.
	 * @param array<string,int>           $placed Games already placed per team id (both teams).
	 * @param SPSG_Schedule_Configuration $config Configuration.
	 * @return array List of ['date' => string, 'distance' => float] entries.
	 */
	private function order_dates_by_pace( $dates, $placed, $config ) {
		$date_count = count( $dates );
		$targets    = array();

		foreach ( $placed as $team_id => $games_placed ) {
			$target = $this->pace_target_index( $team_id, $games_placed, $date_count, $config );
			if ( null !== $target ) {
				$targets[] = $target;
			}
		}

		$ordered = array();

		if ( empty( $targets ) ) {
			foreach ( $dates as $date ) {
				$ordered[] = array(
					'date'     => $date,
					'distance' => 0.0,
				);
			}
			return $ordered;
		}

		$ideal = array_sum( $targets ) / count( $targets );

		foreach ( $dates as $index => $date ) {
			$ordered[] = array(
				'date'     => $date,
				'distance' => abs( $index - $ideal ),
				'index'    => $index,
			);
		}

		usort(
			$ordered,
			function ( $a, $b ) {
				if ( $a['distance'] === $b['distance'] ) {
					return $a['index'] <=> $b['index'];
				}
				return $a['distance'] <=> $b['distance'];
			}
		);

		return $ordered;
	}

	/**
	 * Build the per-date target load: the day's configured share of all games
	 * spread evenly over that day's playing dates.
	 *
	 * With Fri/Sun play, 272 games and a 62/38 split over 25 Fridays and 24
	 * Sundays, a Friday targets ~6.7 games and a Sunday ~4.3. Dates whose day
	 * has a 0 share get a 0 target.
	 *
	 * @param array                       $matchups All matchups being allocated.
	 * @param SPSG_Schedule_Configuration $config   Configuration.
	 * @return array<string,float> date => target games.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function build_date_target_load( $matchups, $config ) {
		$total_games = count( $matchups );
		if ( $total_games <= 0 || empty( $this->slots_by_date ) ) {
			return array();
		}

		$ratios                        = SPSG_Schedule_Helper::resolve_day_ratios( $config );
		list( $day_of_date, $dates_per_day ) = $this->index_dates_by_day();

		return $this->distribute_target_load( $day_of_date, $dates_per_day, $ratios, $total_games );
	}

	/**
	 * Group {@see $slots_by_date}'s dates by the day of the week they fall on.
	 *
	 * @return array{0: array<string,string>, 1: array<string,int>} [date => day, day => date count].
	 */
	private function index_dates_by_day() {
		$day_of_date   = array();
		$dates_per_day = array();

		foreach ( $this->slots_by_date as $date => $slots ) {
			$day = $slots[0]->day ?? strtolower( gmdate( 'l', strtotime( $date ) ) );

			$day_of_date[ $date ]  = $day;
			$dates_per_day[ $day ] = ( $dates_per_day[ $day ] ?? 0 ) + 1;
		}

		return array( $day_of_date, $dates_per_day );
	}

	/**
	 * Spread each day's configured share of the season's games evenly over
	 * that day's dates.
	 *
	 * @param array<string,string> $day_of_date   Date => day of the week.
	 * @param array<string,int>    $dates_per_day Day of the week => date count.
	 * @param array<string,float>  $ratios        Day of the week => target share.
	 * @param int                  $total_games   Total games being allocated.
	 * @return array<string,float> Date => target games.
	 */
	private function distribute_target_load( $day_of_date, $dates_per_day, $ratios, $total_games ) {
		$targets = array();

		foreach ( $day_of_date as $date => $day ) {
			$share           = (float) ( $ratios[ $day ] ?? 0.0 );
			$targets[ $date ] = $share * $total_games / $dates_per_day[ $day ];
		}

		return $targets;
	}

	/**
	 * Where in the season (as a fractional index into the sorted date list) a
	 * team's next game belongs.
	 *
	 * A team with T games spread evenly over D dates plays its k-th game
	 * (0-based) at fraction (k + 0.5) / T of the season, i.e. around date index
	 * (k + 0.5) / T * D - 0.5.
	 *
	 * @param string                      $team_id      Team id.
	 * @param int                         $games_placed Games already scheduled for the team.
	 * @param int                         $date_count   Number of playing dates in the season.
	 * @param SPSG_Schedule_Configuration $config       Configuration (games_per_team fallback).
	 * @return float|null Target index, or null when the team's total is unknown.
	 */
	private function pace_target_index( $team_id, $games_placed, $date_count, $config ) {
		$total = (int) ( $this->team_total_games[ $team_id ] ?? 0 );
		if ( $total <= 0 ) {
			$total = (int) ( $config->games_per_team ?? 0 );
		}
		if ( $total <= 0 || $date_count <= 0 ) {
			return null;
		}

		return ( ( $games_placed + 0.5 ) / $total ) * $date_count - 0.5;
	}

	/**
	 * Calculate slot cost using constraint manager
	 *
	 * Uses soft/optimization constraints to calculate a violation cost.
	 * Lower costs are better (0 is perfect).
	 *
	 * Hard constraints are already known to pass (the caller only scores slots
	 * that cleared {@see is_slot_valid()}) and their validate() result is
	 * memoized, so the aggregate here is effectively the soft/optimization sum.
	 *
	 * @param object                      $game               Pre-built game object for this placement.
	 * @param object                      $slot               Slot object.
	 * @param array                       $schedule_by_date   Schedule indexed by date.
	 * @param SPSG_Schedule_Configuration $config             Configuration.
	 * @param string|null                 $preferred_venue_id Home team's preferred venue, if any.
	 * @return float Cost (lower is better)
	 */
	private function calculate_slot_cost( $game, $slot, $schedule_by_date, $config, $preferred_venue_id = null ) {
		$same_day_games = $schedule_by_date[ $slot->date ] ?? array();

		$cost = (float) $this->constraint_manager->calculate_violation_cost( $game, $same_day_games, $config, $schedule_by_date );

		// Discourage double-headers: charge for each game either team already
		// has on this date.
		if ( ! empty( $same_day_games ) ) {
			$home_team_id = $this->extract_id( $game->home_team );
			$away_team_id = $this->extract_id( $game->away_team );

			foreach ( $same_day_games as $existing_game ) {
				if ( $this->has_team_conflict( $existing_game, $home_team_id, $away_team_id ) ) {
					$cost += self::SAME_DATE_TEAM_PENALTY;
				}
			}
		}

		// Spread load across dates: prefer a date with room over one that is
		// already busy, measured against the date's target share of the season
		// (see DATE_LOAD_COST). A date whose day is meant to carry no games at
		// all is discouraged for every game, first one included.
		if ( ! empty( $same_day_games ) || array_key_exists( $slot->date, $this->date_target_load ) ) {
			$games_on_date = count( $same_day_games );
			if ( array_key_exists( $slot->date, $this->date_target_load ) ) {
				$target = $this->date_target_load[ $slot->date ];
				$cost  += $target > 0
					? self::DATE_LOAD_COST * pow( $games_on_date / $target, 2 )
					: self::DATE_LOAD_COST * pow( 2 + $games_on_date, 2 );
			} else {
				$date_capacity = count( $this->slots_by_date[ $slot->date ] ?? array() );
				if ( $date_capacity > 0 ) {
					$cost += self::DATE_LOAD_COST * pow( $games_on_date / $date_capacity, 2 );
				}
			}
		}

		$cost += $this->venue_load_cost( $slot, $same_day_games );

		// A configured home-venue preference outweighs the soft terms, matching
		// the previous behaviour of returning a preferred-venue slot on sight.
		if ( $preferred_venue_id && $this->extract_id( $slot->venue ) === $preferred_venue_id ) {
			$cost -= self::PREFERRED_VENUE_BONUS;
		}

		$cost -= $this->overlap_avoid_same_day_bonus( $game, $same_day_games, $config );

		return $cost;
	}

	/**
	 * VENUE_LOAD_COST: how full $slot's own venue already is on $slot's date,
	 * relative to that venue's own slot capacity that day -- see the
	 * constant's docblock for why ratio-against-self, not raw count or
	 * against another venue's capacity.
	 *
	 * @param object $slot           Candidate slot (date, venue).
	 * @param array  $same_day_games Games already scheduled on $slot's date.
	 * @return float Cost to add (0.0 when the venue's capacity for this date is unknown/zero).
	 */
	private function venue_load_cost( $slot, $same_day_games ) {
		$venue_id = $this->extract_id( $slot->venue );
		$capacity = $this->venue_capacity_by_date[ $slot->date ][ $venue_id ] ?? 0;
		if ( $capacity <= 0 ) {
			return 0.0;
		}

		$games_at_venue = 0;
		foreach ( $same_day_games as $existing_game ) {
			if ( $this->extract_id( $existing_game->venue ) === $venue_id ) {
				$games_at_venue++;
			}
		}

		return self::VENUE_LOAD_COST * pow( $games_at_venue / $capacity, 2 );
	}

	/**
	 * Credit for placing $game on a date where the OTHER team in one of its
	 * `overlap_avoid` restriction groups already has a (different) game --
	 * see {@see OVERLAP_AVOID_SAME_DAY_BONUS}. One credit per matching
	 * restriction group; a group with more than two teams only needs one of
	 * the other members present to count.
	 *
	 * @param object                      $game            Candidate game being placed.
	 * @param array                       $same_day_games  Games already scheduled on the candidate date.
	 * @param SPSG_Schedule_Configuration $config          Configuration (for team_restrictions).
	 * @return float Total bonus (as a positive number the caller subtracts).
	 */
	private function overlap_avoid_same_day_bonus( $game, $same_day_games, $config ) {
		if ( empty( $same_day_games ) || empty( $config->team_restrictions['overlap_avoid'] ) ) {
			return 0.0;
		}

		$game_teams = array( $this->extract_id( $game->home_team ), $this->extract_id( $game->away_team ) );
		$bonus = 0.0;

		foreach ( $config->team_restrictions['overlap_avoid'] as $restriction ) {
			$restricted_teams = (array) ( $restriction['teams'] ?? array() );
			$this_teams = array_intersect( $game_teams, $restricted_teams );
			if ( empty( $this_teams ) ) {
				continue;
			}

			$partner_teams = array_diff( $restricted_teams, $this_teams );
			if ( $this->partner_already_playing( $same_day_games, $partner_teams ) ) {
				$bonus += self::OVERLAP_AVOID_SAME_DAY_BONUS;
			}
		}

		return $bonus;
	}

	/**
	 * Whether any of $same_day_games already involves one of $partner_teams.
	 *
	 * @param array $same_day_games Games already scheduled on the candidate date.
	 * @param array $partner_teams  Team IDs to look for among those games.
	 * @return bool
	 */
	private function partner_already_playing( $same_day_games, $partner_teams ) {
		if ( empty( $partner_teams ) ) {
			return false;
		}

		foreach ( $same_day_games as $existing_game ) {
			$existing_teams = array( $this->extract_id( $existing_game->home_team ), $this->extract_id( $existing_game->away_team ) );
			if ( array_intersect( $existing_teams, $partner_teams ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get available venues for a specific date with their time slots
	 *
	 * Checks date-specific availability first, then falls back to global timeslots
	 *
	 * @param string                      $date Date in YYYY-MM-DD format
	 * @param string                      $day_name Day name (lowercase)
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return array Array of venue data with time slots
	 */
	private function get_available_venues_for_date( $date, $day_name, $config ) {
		$available = array();

		foreach ( $config->venues as $venue ) {
			$venue_id = $this->extract_id( $venue );

			// Check venue-specific blackout dates first
			if ( ! empty( $config->venue_blackout_dates[ $venue_id ] ) && in_array( $date, $config->venue_blackout_dates[ $venue_id ] ) ) {
				continue;
			}

			$time_slots = $this->resolve_venue_time_slots( $venue_id, $date, $day_name, $config );

			if ( ! empty( $time_slots ) ) {
				$available[] = array(
					'venue' => $venue,
					'time_slots' => $time_slots,
				);
			}
		}

		return $available;
	}

	/**
	 * Resolve time slots for a venue on a given date with priority fallback.
	 *
	 * Delegates to SPSG_Schedule_Helper so feasibility pre-checks and the
	 * live allocator share a single cascade implementation.
	 */
	private function resolve_venue_time_slots( $venue_id, $date, $day_name, $config ) {
		return SPSG_Schedule_Helper::resolve_venue_slots( $venue_id, $date, $day_name, $config );
	}



	/**
	 * Cache of time_slot -> end_time calculations.
	 */
	private $end_time_cache = array();

	/**
	 * Create game object from matchup and slot
	 *
	 * @param object                      $matchup Matchup object
	 * @param object                      $slot Slot object
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return object Game object
	 */
	private function create_game( $matchup, $slot, $config ) {
		$match_length = $config->match_length ?? 60;

		// Cache end_time calculation to avoid DateTime allocation per call.
		$cache_key = $slot->time_slot . '|' . $match_length;
		if ( ! isset( $this->end_time_cache[ $cache_key ] ) ) {
			try {
				$start = new DateTime( $slot->time_slot );
				$start->add( new DateInterval( 'PT' . $match_length . 'M' ) );
				$this->end_time_cache[ $cache_key ] = $start->format( 'H:i' );
			} catch ( Exception $e ) {
				$this->end_time_cache[ $cache_key ] = null;
			}
		}

		// Stable game ID: deterministic across reruns so that preload and
		// conflict-skip paths can match generated games to existing events.
		// Includes venue so two games with the same teams/date/time at
		// different venues don't collide on the same id (legal in multi-venue
		// scheduling where conflict resolution treats them as distinct).
		$home_id  = $this->extract_id( $matchup->home_team );
		$away_id  = $this->extract_id( $matchup->away_team );
		$venue_id = $this->extract_id( $slot->venue );
		$game_id  = md5( $home_id . '|' . $away_id . '|' . $slot->date . '|' . $slot->time_slot . '|' . $venue_id );

		return (object) array(
			'id'                => $game_id,
			'date'              => $slot->date,
			'day'               => $slot->day ?? strtolower( gmdate( 'l', strtotime( $slot->date ) ) ),
			'time_slot'         => $slot->time_slot,
			'end_time'          => $this->end_time_cache[ $cache_key ],
			'match_length'      => $match_length,
			'home_team'         => $matchup->home_team,
			'away_team'         => $matchup->away_team,
			// $slot->venue is always a raw $config->venues[] array here (never
			// normalized, unlike home_team/away_team above and division
			// below) -- object-cast it so `$game->venue->id`/`->name` (the
			// import path: map_venue(), create_event_from_game(),
			// update_event()) work the same as the matchup generator's own
			// team/division normalization already does. Callers that expect
			// an array (the preview renderer, exporters) already (array)-cast
			// before reading it, so this is safe both ways.
			'venue'             => is_array( $slot->venue ) ? (object) $slot->venue : $slot->venue,
			'division'          => $matchup->division,
			'is_inter_division' => $matchup->is_inter_division ?? false,
			'is_makeup'         => false,
		);
	}

	/**
	 * Get unique key for a slot
	 *
	 * @param object $slot Slot object
	 * @return string Unique key
	 */
	private function get_slot_key( $slot ) {
		$venue_id = $this->extract_id( $slot->venue );
		return $slot->date . '|' . $slot->time_slot . '|' . $venue_id;
	}

	/**
	 * Check if slot is valid for matchup
	 *
	 * Uses date-indexed schedule for O(1) date filtering instead of
	 * scanning the full schedule array.
	 *
	 * @param object                      $matchup Matchup object
	 * @param object                      $slot Slot object
	 * @param array                       $schedule_by_date Schedule indexed by date
	 * @param SPSG_Schedule_Configuration $config Configuration
	 * @return bool True if valid
	 */
	public function is_slot_valid( $matchup, $slot, $schedule_by_date, $config, $game = null ) {
		$match_length = $config->match_length ?? 60;

		// Two games at one venue conflict only when their match intervals
		// genuinely overlap. This used to pad the check with a hardcoded
		// 15-minute buffer, which with the usual hourly grid (19:00, 20:00,
		// 21:00 ...) and 60-minute matches made a 19:00 game "occupy"
		// 19:00–20:15 and rejected the 20:00 slot at the same venue — so every
		// other configured slot was silently unusable, real capacity was about
		// half of what the operator configured, and the feasibility pre-check
		// (which counts configured slots) disagreed with the allocator. A
		// 32-team season needing 272 of 514 configured slots failed with
		// `allocation_failed` while validation reported 56% utilisation. The
		// per-venue slot grid is the operator's statement of how games fit at
		// that venue; any turnover time belongs in match_length or the grid
		// spacing, not in a constant the configuration cannot see.
		$buffer_time = 0;

		// Only check games on the same date (O(1) lookup vs O(n) scan).
		$same_day_games = $schedule_by_date[ $slot->date ] ?? array();

		$venue_id_slot = $this->extract_id( $slot->venue );
		$home_team_id = $this->extract_id( $matchup->home_team );
		$away_team_id = $this->extract_id( $matchup->away_team );

		// Cheap same-day conflict screen. This loop is a no-op when the date is
		// still empty; the constraint validation below runs either way. H13: an
		// early `return true` for the first game of a date used to bypass every
		// hard constraint, so schedule-independent restrictions (day / venue /
		// preferred-time) never fired on each playing day's opening slot.
		foreach ( $same_day_games as $existing_game ) {
			// Check venue/time conflict
			if ( $this->extract_id( $existing_game->venue ) === $venue_id_slot
				&& $this->times_overlap( $existing_game->time_slot, $slot->time_slot, $match_length, $buffer_time ) ) {
				return false;
			}

			// Check team conflicts (teams can't play multiple games at same time)
			if ( $this->has_team_conflict( $existing_game, $home_team_id, $away_team_id )
				&& $this->times_overlap( $existing_game->time_slot, $slot->time_slot, $match_length, 0 ) ) {
				return false;
			}

			// H15: the same two teams must not meet twice on one date. The old
			// team-conflict check above only rejected time-OVERLAPPING games, so
			// a double round-robin happily put A-vs-B at 19:00 and again at 20:00.
			if ( ! $this->allow_same_date_rematch
				&& $this->is_same_pairing( $existing_game, $home_team_id, $away_team_id ) ) {
				$this->same_date_rematch_blocked = true;
				return false;
			}
		}

		// A team must not play twice in the same real (Mon-Sun) calendar
		// week -- see {@see $allow_same_week_doubleheader}.
		if ( $this->violates_same_week_rule( $slot->date, $home_team_id, $away_team_id, $schedule_by_date, $config ) ) {
			return false;
		}

		if ( $this->breaks_week_feasibility( $slot->date, $home_team_id, $away_team_id, $schedule_by_date ) ) {
			$this->same_week_doubleheader_blocked = true;
			return false;
		}

		// Validate with constraint manager - reuse pre-created game or create one.
		// Forward the full date-indexed schedule so cross-day soft constraints
		// (distribution) score the whole run, not just same-day games.
		if ( ! $game ) {
			$game = $this->create_game( $matchup, $slot, $config );
		}
		$validation = $this->constraint_manager->validate_game( $game, $same_day_games, $config, $schedule_by_date );

		return $validation === true;
	}

	/**
	 * Whether placing this candidate would break the same-week rule -- i.e.
	 * the rule is currently enforced AND $home_team_id or $away_team_id
	 * already has a game anywhere else in the real calendar week $slot_date
	 * falls in. Sets {@see $same_week_doubleheader_blocked} when the rule is
	 * enforced and actually the reason for the violation, so a relaxed retry
	 * knows it's worth attempting.
	 *
	 * @param string $slot_date        Candidate slot's date (Y-m-d).
	 * @param string $home_team_id     Candidate home team id.
	 * @param string $away_team_id     Candidate away team id.
	 * @param array  $schedule_by_date Schedule indexed by date.
	 * @param object $config           Schedule configuration.
	 * @return bool
	 */
	private function violates_same_week_rule( $slot_date, $home_team_id, $away_team_id, $schedule_by_date, $config ) {
		if ( $this->allow_same_week_doubleheader ) {
			return false;
		}
		if ( ! $this->has_same_week_team_conflict( $slot_date, $home_team_id, $away_team_id, $schedule_by_date, $config ) ) {
			return false;
		}
		$this->same_week_doubleheader_blocked = true;
		return true;
	}

	/**
	 * Whether placing $home_team_id vs $away_team_id on $slot_date would leave
	 * the rest of the matchup list impossible to place under the same-week
	 * rule -- i.e. this candidate is a dead end the search should not enter.
	 *
	 * The same-week rule makes every team's remaining games compete for
	 * distinct weeks, which turns allocation into a counting problem the
	 * per-slot checks above can't see. Three necessary conditions, evaluated
	 * on the state AFTER the hypothetical placement:
	 *
	 *  1. A team with N games left needs N distinct weeks it hasn't played in
	 *     that still have a free slot.
	 *  2. A team with exactly as many open weeks as games left must play in
	 *     every one of them, so a week can't have more such "must-play" teams
	 *     than it has team places (2 per free slot).
	 *  3. Each week can host at most min(free slots, open teams / 2) more
	 *     games, and those per-week ceilings must add up to at least the
	 *     games still unplaced.
	 *
	 * Without this, a season sized to exactly its slot count (e.g. 64 games
	 * into 64 slots over 5 weeks, two of them short weeks) failed outright:
	 * greedy spent the short weeks on teams that could afford to skip a full
	 * week, leaving teams that couldn't with nowhere to go, and backtracking
	 * exhausted its budget before unwinding far enough. Each condition only
	 * ever rejects a placement that could not have led to a complete
	 * schedule, so seasons with real slack are unaffected. Skipped entirely
	 * once the relaxed retry allows same-week double-headers, since the
	 * premise (one game per team per week) no longer holds.
	 *
	 * @param string $slot_date        Candidate slot's date (Y-m-d).
	 * @param string $home_team_id     Candidate home team id.
	 * @param string $away_team_id     Candidate away team id.
	 * @param array  $schedule_by_date Schedule indexed by date.
	 * @return bool
	 */
	private function breaks_week_feasibility( $slot_date, $home_team_id, $away_team_id, $schedule_by_date ) {
		if ( $this->allow_same_week_doubleheader || empty( $this->week_of_date ) || empty( $this->team_total_games ) ) {
			return false;
		}
		$slot_week = $this->week_of_date[ $slot_date ] ?? null;
		if ( null === $slot_week ) {
			return false;
		}

		$free = $this->free_slots_by_week( $schedule_by_date );
		$free[ $slot_week ]--;

		list( $placed, $weeks_played ) = $this->team_placement_state( $schedule_by_date );
		foreach ( array( $home_team_id, $away_team_id ) as $team_id ) {
			$placed[ $team_id ]                     = ( $placed[ $team_id ] ?? 0 ) + 1;
			$weeks_played[ $team_id ][ $slot_week ] = true;
		}

		$demand = $this->team_week_demand( $free, $placed, $weeks_played );

		return null === $demand || ! $this->week_capacity_holds( $free, $demand );
	}

	/**
	 * Condition 1 of {@see breaks_week_feasibility()}, plus the per-team /
	 * per-week bookkeeping conditions 2 and 3 need.
	 *
	 * @param array<string,int>                $free         Free slots per week.
	 * @param array<string,int>                $placed       Games placed per team.
	 * @param array<string,array<string,true>> $weeks_played Weeks each team plays in.
	 * @return array{open_teams_by_week: array<string,string[]>, slack_by_team: array<string,int>, remaining_games: float}|null
	 *         Null when some team has more games left than open weeks.
	 */
	private function team_week_demand( $free, $placed, $weeks_played ) {
		$open_teams_by_week = array();
		$slack_by_team      = array();
		$remaining          = 0;

		foreach ( $this->team_total_games as $team_id => $total ) {
			$left = $total - ( $placed[ $team_id ] ?? 0 );
			if ( $left <= 0 ) {
				continue;
			}
			$open = 0;
			foreach ( $free as $week => $free_slots ) {
				if ( $free_slots > 0 && empty( $weeks_played[ $team_id ][ $week ] ) ) {
					$open++;
					$open_teams_by_week[ $week ][] = $team_id;
				}
			}
			if ( $left > $open ) {
				return null;
			}
			$slack_by_team[ $team_id ] = $open - $left;
			$remaining                += $left;
		}

		return array(
			'open_teams_by_week' => $open_teams_by_week,
			'slack_by_team'      => $slack_by_team,
			'remaining_games'    => $remaining / 2,
		);
	}

	/**
	 * Conditions 2 and 3 of {@see breaks_week_feasibility()}.
	 *
	 * @param array<string,int> $free   Free slots per week.
	 * @param array             $demand Output of {@see team_week_demand()}.
	 * @return bool
	 */
	private function week_capacity_holds( $free, $demand ) {
		$capacity = 0;

		foreach ( $free as $week => $free_slots ) {
			if ( $free_slots <= 0 ) {
				continue;
			}
			$open      = $demand['open_teams_by_week'][ $week ] ?? array();
			$must_play = 0;
			foreach ( $open as $team_id ) {
				if ( 0 === $demand['slack_by_team'][ $team_id ] ) {
					$must_play++;
				}
			}
			if ( $must_play > 2 * $free_slots ) {
				return false;
			}
			$capacity += min( $free_slots, intdiv( count( $open ), 2 ) );
		}

		return $demand['remaining_games'] <= $capacity;
	}

	/**
	 * Free (unused) slot count per week for the given schedule state.
	 *
	 * @param array $schedule_by_date Schedule indexed by date.
	 * @return array<string,int> week key => free slots
	 */
	private function free_slots_by_week( $schedule_by_date ) {
		$free = array();
		foreach ( $this->dates_by_week as $week => $dates ) {
			$free[ $week ] = 0;
			foreach ( $dates as $date ) {
				$free[ $week ] += count( $this->slots_by_date[ $date ] ) - count( $schedule_by_date[ $date ] ?? array() );
			}
		}
		return $free;
	}

	/**
	 * Games placed per team and the weeks each team already plays in.
	 *
	 * @param array $schedule_by_date Schedule indexed by date.
	 * @return array{0: array<string,int>, 1: array<string,array<string,true>>}
	 */
	private function team_placement_state( $schedule_by_date ) {
		$placed       = array();
		$weeks_played = array();
		foreach ( $schedule_by_date as $date => $games ) {
			$week = $this->week_of_date[ $date ] ?? null;
			foreach ( $games as $game ) {
				foreach ( array( $this->extract_id( $game->home_team ), $this->extract_id( $game->away_team ) ) as $team_id ) {
					$placed[ $team_id ] = ( $placed[ $team_id ] ?? 0 ) + 1;
					if ( null !== $week ) {
						$weeks_played[ $team_id ][ $week ] = true;
					}
				}
			}
		}
		return array( $placed, $weeks_played );
	}

	/**
	 * Whether $home_team_id or $away_team_id already has a game anywhere in
	 * the real calendar week $slot_date falls in -- on any of that week's
	 * configured playing dates, the candidate slot's own date included.
	 *
	 * @param string $slot_date        Candidate slot's date (Y-m-d).
	 * @param string $home_team_id     Candidate home team id.
	 * @param string $away_team_id     Candidate away team id.
	 * @param array  $schedule_by_date Schedule indexed by date.
	 * @param object $config           Schedule configuration.
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function has_same_week_team_conflict( $slot_date, $home_team_id, $away_team_id, $schedule_by_date, $config ) {
		$week_key = SPSG_Schedule_Helper::iso_week_key( $slot_date );
		if ( null === $week_key ) {
			return false;
		}

		foreach ( SPSG_Schedule_Helper::get_week_playing_dates( $week_key, $config ) as $entry ) {
			foreach ( $schedule_by_date[ $entry['date'] ] ?? array() as $existing_game ) {
				if ( $this->has_team_conflict( $existing_game, $home_team_id, $away_team_id ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Extract ID from an object or array
	 */
	private function extract_id( $entity ) {
		if ( is_string( $entity ) ) {
			return $entity;
		}
		if ( is_object( $entity ) ) {
			return $entity->id ?? $entity->name ?? '';
		}
		return $entity['id'] ?? $entity['name'] ?? '';
	}

	/**
	 * Check if an existing game involves either of the given team IDs
	 */
	private function has_team_conflict( $existing_game, $home_team_id, $away_team_id ) {
		$existing_home_id = $this->extract_id( $existing_game->home_team );
		$existing_away_id = $this->extract_id( $existing_game->away_team );

		return $existing_home_id === $home_team_id
			|| $existing_away_id === $home_team_id
			|| $existing_home_id === $away_team_id
			|| $existing_away_id === $away_team_id;
	}

	/**
	 * Check whether an existing game is the same (unordered) pairing.
	 *
	 * @param object $existing_game Already-scheduled game.
	 * @param string $home_team_id  Candidate home team ID.
	 * @param string $away_team_id  Candidate away team ID.
	 * @return bool True when both games are between the same two teams.
	 */
	private function is_same_pairing( $existing_game, $home_team_id, $away_team_id ) {
		$existing_home_id = $this->extract_id( $existing_game->home_team );
		$existing_away_id = $this->extract_id( $existing_game->away_team );

		return ( $existing_home_id === $home_team_id && $existing_away_id === $away_team_id )
			|| ( $existing_home_id === $away_team_id && $existing_away_id === $home_team_id );
	}

	/**
	 * Check if two time slots overlap considering match length and buffer
	 *
	 * @param string|int $time1 First time slot (string "HH:MM" or minutes since midnight)
	 * @param string|int $time2 Second time slot (string "HH:MM" or minutes since midnight)
	 * @param int        $match_length Match length in minutes
	 * @param int        $buffer_time Buffer time in minutes
	 * @return bool True if times overlap
	 */
	private function times_overlap( $time1, $time2, $match_length, $buffer_time = 0 ) {
		$t1 = is_numeric( $time1 ) ? intval( $time1 ) : $this->time_to_minutes( $time1 );
		$t2 = is_numeric( $time2 ) ? intval( $time2 ) : $this->time_to_minutes( $time2 );

		$start1 = $t1;
		$end1 = $t1 + $match_length + $buffer_time;

		$start2 = $t2;
		$end2 = $t2 + $match_length + $buffer_time;

		return $start1 < $end2 && $start2 < $end1;
	}

	/**
	 * Convert time string to minutes since midnight
	 *
	 * @param string $time Time string (HH:MM)
	 * @return int Minutes
	 */
	private function time_to_minutes( $time ) {
		$parts = explode( ':', $time );
		return intval( $parts[0] ) * 60 + intval( $parts[1] );
	}

	/**
	 * Get the number of games with soft constraint violations
	 *
	 * @return int
	 */
	public function get_constraint_violations() {
		return $this->constraint_violations;
	}

	/**
	 * Whether the most recent allocate() run aborted because the user
	 * cancelled generation.
	 *
	 * @return bool
	 */
	public function was_cancelled() {
		return $this->was_cancelled;
	}

	/**
	 * Whether the most recent allocate() run aborted because the
	 * engine-level timeout fired.
	 *
	 * @return bool
	 */
	public function was_timed_out() {
		return $this->was_timed_out;
	}

	/**
	 * Build the user-cancellation WP_Error. Centralised so the greedy and
	 * backtracking return paths cannot drift out of sync.
	 *
	 * @param int $total_matchups Size of the input matchup list.
	 * @return WP_Error
	 */
	private function build_cancellation_error( $total_matchups ) {
		return new WP_Error(
			'allocation_cancelled',
			__( 'Schedule generation cancelled by user.', 'sportspress-schedule-generator' ),
			array(
				'status'          => 409,
				'total_matchups'  => $total_matchups,
				'available_slots' => count( $this->available_slots ),
			)
		);
	}

	/**
	 * Build the timeout WP_Error. Returned with HTTP 408 so REST clients
	 * can branch on it cleanly.
	 *
	 * @param int $total_matchups Size of the input matchup list.
	 * @return WP_Error
	 */
	private function build_timeout_error( $total_matchups ) {
		return new WP_Error(
			'allocation_timed_out',
			__( 'Schedule generation timed out. Try reducing constraints or splitting into smaller runs.', 'sportspress-schedule-generator' ),
			array(
				'status'          => 408,
				'total_matchups'  => $total_matchups,
				'available_slots' => count( $this->available_slots ),
			)
		);
	}

	/**
	 * Log message
	 *
	 * @param string $message Message to log
	 */
	private function log( $message ) {
		if ( get_option( 'spsg_enable_debug_logging', '0' ) === '1' ) {
			error_log( sprintf( '[SPSG Slot Allocator] %s', $message ) );
		}
	}
}
