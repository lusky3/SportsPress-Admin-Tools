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

		// Build a date → slots index for fast chronological lookups.
		$this->slots_by_date = array();
		foreach ( $this->available_slots as $slot ) {
			$this->slots_by_date[ $slot->date ][] = $slot;
		}
		$this->sorted_slot_dates = array_keys( $this->slots_by_date );
		sort( $this->sorted_slot_dates );

		$this->date_target_load = $this->build_date_target_load( $matchups, $config );

		if ( empty( $this->available_slots ) ) {
			return new WP_Error(
				'no_available_slots',
				__( 'No available time slots found. Check your configuration.', 'sportspress-schedule-generator' )
			);
		}

		$this->log( sprintf( 'Generated %d available slots', count( $this->available_slots ) ) );

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
			// H15: the same-date rematch rule is a hard rule during the normal
			// passes, but it must never be the sole reason a season cannot be
			// generated (a one-day tournament legitimately replays pairs). If it
			// actually blocked slots, retry greedily with the rule relaxed before
			// surfacing a failure.
			if ( ! $this->allow_same_date_rematch && $this->same_date_rematch_blocked ) {
				$this->log( 'Allocation failed with strict rematch spacing; retrying relaxed' );
				$this->allow_same_date_rematch = true;

				$schedule = $this->greedy_allocate( $matchups, $config, $progress_callback, $cancellation_callback, $timeout_callback );

				if ( $this->was_cancelled ) {
					return $this->build_cancellation_error( count( $matchups ) );
				}
				if ( $this->was_timed_out ) {
					return $this->build_timeout_error( count( $matchups ) );
				}

				if ( $schedule !== false ) {
					$this->log( 'Relaxed allocation succeeded (pairs may meet twice on one date)' );
					return $schedule;
				}
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

		$result = $this->backtrack_recursive( $matchups, 0, $schedule, $used_slots, $schedule_by_date, $config, 0, $progress_callback, $cancellation_callback, $timeout_callback );

		if ( $this->backtrack_budget_exhausted ) {
			$this->log( 'Backtracking gave up: node budget exhausted' );
		}

		return $result ? $schedule : false;
	}

	/**
	 * Recursive backtracking helper
	 */
	private function backtrack_recursive( $matchups, $index, &$schedule, &$used_slots, &$schedule_by_date, $config, $depth, $progress_callback = null, $cancellation_callback = null, $timeout_callback = null ) {
		if ( $cancellation_callback && call_user_func( $cancellation_callback ) ) {
			$this->was_cancelled = true;
			return false;
		}
		if ( $timeout_callback && call_user_func( $timeout_callback ) ) {
			$this->was_timed_out = true;
			return false;
		}
		if ( $depth > $this->max_backtrack_depth ) {
			return false;
		}
		if ( $index >= count( $matchups ) ) {
			return true;
		}

		// M53: fail fast once the search budget is spent.
		if ( $this->backtrack_budget <= 0 ) {
			$this->backtrack_budget_exhausted = true;
			return false;
		}

		if ( $progress_callback && $index % 10 === 0 ) {
			call_user_func( $progress_callback, $index );
		}

		$matchup = $matchups[ $index ];

		foreach ( $this->available_slots as $slot ) {
			$slot_key = $this->get_slot_key( $slot );

			if ( isset( $used_slots[ $slot_key ] ) ) {
				continue;
			}

			// M53: charge budget only for slots that reach real constraint
			// validation. Skipping an already-used slot above is an O(1) hash
			// lookup, not the search work this budget is meant to bound —
			// charging it anyway made the budget scale with total slot COUNT
			// rather than remaining search effort. As a season fills up, most
			// of $available_slots is already used, so a large slot list could
			// exhaust the budget almost entirely on cheap skips before any
			// real backtracking happened — worse, adding MORE slots made this
			// effect stronger, the opposite of what more real capacity should
			// do. Independent of {@see backtrack_allocate()}'s budget-sizing
			// fix above: that scales how much budget is granted, this scales
			// what each visit actually costs.
			if ( --$this->backtrack_budget <= 0 ) {
				$this->backtrack_budget_exhausted = true;
				return false;
			}

			if ( ! $this->is_slot_valid( $matchup, $slot, $schedule_by_date, $config ) ) {
				continue;
			}

			$game = $this->create_game( $matchup, $slot, $config );
			$schedule[] = $game;
			$used_slots[ $slot_key ] = true;
			$schedule_by_date[ $game->date ][] = $game;

			if ( $this->backtrack_recursive( $matchups, $index + 1, $schedule, $used_slots, $schedule_by_date, $config, $depth + 1, $progress_callback, $cancellation_callback, $timeout_callback ) ) {
				return true;
			}

			// Backtrack
			array_pop( $schedule );
			unset( $used_slots[ $slot_key ] );
			array_pop( $schedule_by_date[ $game->date ] );
		}

		return false;
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
	 */
	private function build_date_target_load( $matchups, $config ) {
		$total_games = count( $matchups );
		if ( $total_games <= 0 || empty( $this->slots_by_date ) ) {
			return array();
		}

		$ratios        = SPSG_Schedule_Helper::resolve_day_ratios( $config );
		$dates_per_day = array();
		$day_of_date   = array();

		foreach ( $this->slots_by_date as $date => $slots ) {
			$day = $slots[0]->day ?? strtolower( gmdate( 'l', strtotime( $date ) ) );

			$day_of_date[ $date ]   = $day;
			$dates_per_day[ $day ] = ( $dates_per_day[ $day ] ?? 0 ) + 1;
		}

		$targets = array();
		foreach ( $day_of_date as $date => $day ) {
			$share             = (float) ( $ratios[ $day ] ?? 0.0 );
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

		// A configured home-venue preference outweighs the soft terms, matching
		// the previous behaviour of returning a preferred-venue slot on sight.
		if ( $preferred_venue_id && $this->extract_id( $slot->venue ) === $preferred_venue_id ) {
			$cost -= self::PREFERRED_VENUE_BONUS;
		}

		return $cost;
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
			'venue'             => $slot->venue,
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
