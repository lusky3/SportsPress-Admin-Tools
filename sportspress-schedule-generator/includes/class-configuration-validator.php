<?php
/**
 * Configuration Validator
 *
 * Handles all validation logic for schedule configurations.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuration Validator class
 */
class SPSG_Configuration_Validator {


	/**
	 * Configuration instance to validate
	 *
	 * @var SPSG_Schedule_Configuration
	 */
	private $config;

	/**
	 * Longest permitted season span, in days (~4 years). Guards every
	 * date-walking routine in the plugin against an absurd season_end.
	 */
	const MAX_SEASON_DAYS = 1500;

	/**
	 * Non-blocking advisories collected during the last validate() run.
	 *
	 * H18: the capacity check used to return its "tight capacity" tier as a
	 * WP_Error, which the generate path treats as a hard failure. Advisories now
	 * live here so callers can surface them without blocking generation.
	 *
	 * @var array
	 */
	private $warnings = array();

	/**
	 * Constructor
	 *
	 * @param SPSG_Schedule_Configuration $config Configuration to validate
	 */
	public function __construct( SPSG_Schedule_Configuration $config ) {
		$this->config = $config;
	}

	/**
	 * Non-blocking warnings raised by the last validate() call.
	 *
	 * @return array List of warning strings.
	 */
	public function get_warnings() {
		return $this->warnings;
	}

	/**
	 * Run all validation checks
	 *
	 * @return bool|WP_Error True if valid, WP_Error with details if invalid
	 */
	public function validate() {
		$errors         = array();
		$this->warnings = array();

		$this->validate_dates( $errors );
		$this->validate_blackout_dates_range( $errors );
		$this->validate_basic_fields( $errors );
		$this->validate_divisions( $errors );
		$this->validate_venue_timeslots( $errors );
		$this->validate_matchup_style_config( $errors );
		$this->validate_home_away_preferences( $errors );
		$this->validate_inter_division_games( $errors );
		$this->validate_capacity( $errors );
		$this->validate_postseason_settings( $errors );

		if ( empty( $errors ) ) {
			return true;
		}

		return new WP_Error( 'validation_failed', __( 'Configuration validation failed', 'sportspress-schedule-generator' ), array( 'errors' => $errors ) );
	}

	/**
	 * Validate season start/end dates
	 */
	private function validate_dates( &$errors ) {
		if ( ! $this->config->season_start ) {
			$errors['season_start'] = __( 'Season start date is required. Please select a valid start date.', 'sportspress-schedule-generator' );
		}

		if ( ! $this->config->season_end ) {
			$errors['season_end'] = __( 'Season end date is required. Please select a valid end date.', 'sportspress-schedule-generator' );
		}

		if ( $this->config->season_start && $this->config->season_end && $this->config->season_start >= $this->config->season_end ) {
			$errors['season_dates'] = sprintf(
				__( 'Season end date (%1$s) must be after start date (%2$s). Please adjust your dates.', 'sportspress-schedule-generator' ),
				$this->config->season_end->format( 'Y-m-d' ),
				$this->config->season_start->format( 'Y-m-d' )
			);
			return;
		}

		// LOW (2026-08): draft saves happily accepted `season_end: 9999-12-31`,
		// and every date-walking routine (slot generation, capacity counting,
		// feasibility) then iterates ~2.9 million days — an admin-triggered DoS.
		// Cap the span at something no real season approaches.
		if ( $this->config->season_start && $this->config->season_end ) {
			$span_days = (int) $this->config->season_start->diff( $this->config->season_end )->days;

			if ( $span_days > self::MAX_SEASON_DAYS ) {
				$errors['season_length'] = sprintf(
					/* translators: 1: season length in days, 2: maximum allowed days */
					__( 'Season spans %1$d days, which exceeds the %2$d-day maximum. Please shorten the season.', 'sportspress-schedule-generator' ),
					$span_days,
					self::MAX_SEASON_DAYS
				);
			}
		}
	}

	/**
	 * Validate blackout dates fall within season range
	 */
	private function validate_blackout_dates_range( &$errors ) {
		if ( ! $this->config->season_start || ! $this->config->season_end || empty( $this->config->blackout_dates ) ) {
			return;
		}

		foreach ( $this->config->blackout_dates as $blackout ) {
			try {
				$blackout_date = new DateTime( $blackout );
				if ( $blackout_date < $this->config->season_start || $blackout_date > $this->config->season_end ) {
					$errors['blackout_dates'] = sprintf(
						__( 'Blackout date %1$s is outside the season range (%2$s to %3$s). Please remove it or adjust your season dates.', 'sportspress-schedule-generator' ),
						$blackout,
						$this->config->season_start->format( 'Y-m-d' ),
						$this->config->season_end->format( 'Y-m-d' )
					);
				}
			} catch ( Exception $e ) {
				$errors['blackout_dates'] = sprintf(
					__( 'Invalid blackout date format: %s. Please use YYYY-MM-DD format.', 'sportspress-schedule-generator' ),
					$blackout
				);
			}
		}
	}

	/**
	 * Validate basic required fields (games_per_team, playing_days, time_slots, venues, match_length)
	 */
	private function validate_basic_fields( &$errors ) {
		if ( $this->config->games_per_team <= 0 ) {
			$errors['games_per_team'] = __( 'Games per team must be a positive number. Please enter a value greater than 0.', 'sportspress-schedule-generator' );
		}

		if ( empty( $this->config->playing_days ) ) {
			$errors['playing_days'] = __( 'At least one playing day must be selected. Please choose which days games can be scheduled.', 'sportspress-schedule-generator' );
		}

		if ( empty( $this->config->time_slots ) ) {
			$errors['time_slots'] = __( 'At least one time slot must be configured. Please add time slots for your playing days.', 'sportspress-schedule-generator' );
		}

		if ( empty( $this->config->venues ) ) {
			$errors['venues'] = __( 'At least one venue must be configured. Please add venues where games can be played.', 'sportspress-schedule-generator' );
		}

		if ( $this->config->match_length < 15 || $this->config->match_length > 240 ) {
			$errors['match_length'] = sprintf(
				__( 'Match length must be between 15 and 240 minutes. Current value: %d minutes.', 'sportspress-schedule-generator' ),
				$this->config->match_length
			);
		}
	}

	/**
	 * Validate divisions have enough teams
	 */
	private function validate_divisions( &$errors ) {
		if ( empty( $this->config->divisions ) ) {
			$errors['divisions'] = __( 'At least one division must be configured. Please add divisions and teams.', 'sportspress-schedule-generator' );
			return;
		}

		foreach ( $this->config->divisions as $division ) {
			if ( empty( $division['teams'] ) || count( $division['teams'] ) < 2 ) {
				$errors['divisions'] = sprintf(
					__( 'Division "%s" must have at least 2 teams. Please add more teams or remove the division.', 'sportspress-schedule-generator' ),
					$division['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' )
				);
				break;
			}
		}
	}

	/**
	 * Validate postseason-only settings: round_robin_weeks must be
	 * achievable for every division, which -- per
	 * SPSG_Postseason_Pairing::cross_round_robin() -- requires an even team
	 * count no smaller than round_robin_weeks * 2. Odd-sized divisions are
	 * out of scope for postseason brackets (see design notes).
	 *
	 * No-op for a regular-season configuration (is_postseason false).
	 */
	private function validate_postseason_settings( &$errors ) {
		if ( ! $this->config->is_postseason ) {
			return;
		}

		if ( $this->config->round_robin_weeks < 1 ) {
			$errors['round_robin_weeks'] = __( 'Round robin weeks must be at least 1.', 'sportspress-schedule-generator' );
			return;
		}

		foreach ( $this->config->divisions as $division ) {
			$error = $this->postseason_division_error( $division );
			if ( $error ) {
				$errors['round_robin_weeks'] = $error;
				return;
			}
		}

		$this->validate_postseason_day( $errors, 'championship_day', $this->config->championship_day['day'] ?? '' );
		$this->validate_postseason_day( $errors, 'consolation_day', $this->config->consolation_day );
		$this->validate_postseason_final_week_capacity( $errors );
	}

	/**
	 * Whether the final week (the trailing 7 days of season_end) has
	 * enough available (venue, time-slot) capacity for every division's
	 * Championship game (on championship_day, within its time window) and
	 * every division's Consolation game (on consolation_day, no window).
	 * Both are single, specific calendar dates -- SPSG_Postseason_Week_Constraint
	 * pins every division's final-week games onto that same one week, so a
	 * bracket with several divisions can outgrow what a single date
	 * offers, which otherwise only surfaces later as a generic
	 * "allocation_failed" error.
	 *
	 * @param array $errors Accumulator, keyed by field name.
	 */
	private function validate_postseason_final_week_capacity( &$errors ) {
		if ( empty( $this->config->season_end ) ) {
			return; // Nothing to check yet.
		}

		list( $championship_games, $consolation_games ) = $this->postseason_final_week_game_counts();

		if ( 0 === $championship_games ) {
			return; // No divisions with enough teams to need a final week at all.
		}

		$championship_day = $this->config->championship_day['day'] ?? '';
		if ( '' !== $championship_day ) {
			$this->check_final_week_capacity( $errors, 'championship_day', $championship_day, $championship_games, $this->championship_time_window() );
		}

		if ( '' !== $this->config->consolation_day && $consolation_games > 0 ) {
			$this->check_final_week_capacity( $errors, 'consolation_day', $this->config->consolation_day, $consolation_games, null );
		}
	}

	/**
	 * Total Championship and Consolation games the final week needs across
	 * all divisions (one Championship game per division with at least 2
	 * teams; every other final-week pairing in that division is
	 * Consolation).
	 *
	 * @return array{0: int, 1: int} [championship_games, consolation_games].
	 */
	private function postseason_final_week_game_counts() {
		$championship_games = 0;
		$consolation_games  = 0;

		foreach ( $this->config->divisions as $division ) {
			$team_count = count( $division['teams'] ?? array() );
			if ( $team_count < 2 ) {
				continue;
			}
			++$championship_games;
			$consolation_games += (int) ( $team_count / 2 ) - 1;
		}

		return array( $championship_games, $consolation_games );
	}

	/**
	 * The configured Championship time window, or null if either bound is unset.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function championship_time_window() {
		$start = $this->config->championship_day['start'] ?? '';
		$end   = $this->config->championship_day['end'] ?? '';
		return ( '' !== $start && '' !== $end ) ? array( $start, $end ) : null;
	}

	/**
	 * Whether one final-week day (Championship or Consolation) has enough
	 * slot capacity for the games it needs, recording an error if not.
	 *
	 * @param array      $errors       Accumulator, keyed by field name.
	 * @param string     $error_key    'championship_day' or 'consolation_day'.
	 * @param string     $day_name     The configured day (e.g. 'saturday').
	 * @param int        $games_needed Number of games that must fit on this date.
	 * @param array|null $time_window  [start, end] to restrict counted slots to, or null.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function check_final_week_capacity( &$errors, $error_key, $day_name, $games_needed, $time_window ) {
		if ( isset( $errors[ $error_key ] ) ) {
			return; // Already flagged as not a playing day -- don't clobber with a confusing capacity message.
		}

		$date      = $this->final_week_date_for_day( $day_name );
		$available = SPSG_Schedule_Helper::count_slots_on_date( $this->config, $date, $time_window );

		if ( $available < $games_needed ) {
			$errors[ $error_key ] = sprintf(
				/* translators: 1: the specific date, 2: available slot count, 3: games needed */
				__( 'Only %2$d slot(s) are available on %1$s, but %3$d final-week game(s) need to be scheduled there.', 'sportspress-schedule-generator' ),
				$date,
				$available,
				$games_needed
			);
		}
	}

	/**
	 * The single calendar date within the final (trailing 7-day) week that
	 * falls on $day_name -- matches SPSG_Postseason_Week_Constraint's own
	 * final_week_start() window exactly (the 7 days ending at season_end),
	 * so this is always exactly one date, never zero or more than one.
	 *
	 * @param string $day_name Lowercase day name (e.g. 'saturday').
	 * @return string 'Y-m-d' date.
	 */
	private function final_week_date_for_day( $day_name ) {
		$cursor = clone $this->config->season_end;
		for ( $i = 0; $i < 7; $i++ ) {
			if ( strtolower( $cursor->format( 'l' ) ) === $day_name ) {
				return $cursor->format( 'Y-m-d' );
			}
			$cursor->modify( '-1 day' );
		}
		return $this->config->season_end->format( 'Y-m-d' ); // Unreachable given a valid day name; defensive fallback.
	}

	/**
	 * Whether one postseason day setting (championship_day's day, or
	 * consolation_day) is among the configuration's own playing_days --
	 * both allocator constraints that read it are hard, so a day the
	 * league never actually plays on would otherwise produce an
	 * unschedulable config with no clear error pointing at why.
	 *
	 * @param array  $errors Accumulator, keyed by field name.
	 * @param string $key    Error key to set ('championship_day' or 'consolation_day').
	 * @param string $day    The configured day, or '' if not set yet.
	 */
	private function validate_postseason_day( &$errors, $key, $day ) {
		if ( '' === $day || in_array( $day, $this->config->playing_days, true ) ) {
			return;
		}

		$errors[ $key ] = sprintf(
			/* translators: 1: the configured day, 2: comma-separated list of the league's actual playing days */
			__( '"%1$s" is not one of this league\'s playing days (%2$s).', 'sportspress-schedule-generator' ),
			ucfirst( $day ),
			implode( ', ', array_map( 'ucfirst', $this->config->playing_days ) )
		);
	}

	/**
	 * Whether one division can support this configuration's
	 * round_robin_weeks -- see validate_postseason_settings().
	 *
	 * @param array $division One division's raw data.
	 * @return string|null Error message, or null if the division is fine.
	 */
	private function postseason_division_error( array $division ) {
		$team_count = count( $division['teams'] ?? array() );
		$name       = $division['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' );

		if ( 0 !== $team_count % 2 ) {
			return sprintf(
				__( 'Division "%s" has an odd number of teams; postseason brackets require an even division size.', 'sportspress-schedule-generator' ),
				$name
			);
		}

		if ( $this->config->round_robin_weeks > (int) ( $team_count / 2 ) ) {
			return sprintf(
				__( 'Round robin weeks (%1$d) can\'t exceed half of division "%2$s"\'s team count (%3$d).', 'sportspress-schedule-generator' ),
				$this->config->round_robin_weeks,
				$name,
				$team_count
			);
		}

		return null;
	}

	/**
	 * Validate venue-specific timeslots are not empty
	 */
	private function validate_venue_timeslots( &$errors ) {
		if ( empty( $this->config->venue_timeslots ) ) {
			return;
		}

		foreach ( $this->config->venue_timeslots as $venue_id => $timeslots ) {
			if ( empty( $timeslots ) ) {
				$venue_name = $this->get_venue_name( $venue_id );
				$errors['venue_timeslots'] = sprintf(
					__( 'Venue "%s" has no timeslots configured. Please add timeslots or remove venue-specific restrictions.', 'sportspress-schedule-generator' ),
					$venue_name
				);
			}
		}
	}

	/**
	 * Validate matchup style and compatibility with division sizes
	 */
	private function validate_matchup_style_config( &$errors ) {
		if ( empty( $this->config->matchup_style ) ) {
			return;
		}

		$valid_styles = array( 'single_round_robin', 'double_round_robin', 'custom' );
		if ( ! in_array( $this->config->matchup_style, $valid_styles ) ) {
			$errors['matchup_style'] = sprintf(
				__( 'Invalid matchup style "%1$s". Must be one of: %2$s', 'sportspress-schedule-generator' ),
				$this->config->matchup_style,
				implode( ', ', $valid_styles )
			);
		}

		if ( ! empty( $this->config->divisions ) && in_array( $this->config->matchup_style, array( 'single_round_robin', 'double_round_robin' ) ) ) {
			$matchup_validation = $this->validate_matchup_style_compatibility();
			if ( is_wp_error( $matchup_validation ) ) {
				$errors['matchup_compatibility'] = $matchup_validation->get_error_message();
			}
		}
	}

	/**
	 * Validate home/away venue preferences reference existing venues
	 */
	private function validate_home_away_preferences( &$errors ) {
		if ( empty( $this->config->home_away_preferences ) ) {
			return;
		}

		foreach ( $this->config->home_away_preferences as $team_id => $venue_id ) {
			// An empty value means "no preference set" -- the default state
			// for every team unless the admin explicitly picks one. Treating
			// it as a reference to a nonexistent venue made ANY configuration
			// with even one unset preference fail validation outright: the
			// admin form renders one home_away_preferences[<team>] field per
			// team unconditionally, so a config with 32 teams and zero actual
			// preferences set (the common case -- this is an optional
			// override) submitted 32 empty strings and failed on the first
			// one, while a config built without ever touching this field at
			// all (e.g. written directly via the REST API) had no entries
			// here and never hit this check -- which is why "Save
			// Configuration" could fail validation on a config that the
			// separate "Validate Configuration" button reported as valid.
			if ( '' === $venue_id || null === $venue_id ) {
				continue;
			}

			$venue_exists = false;
			foreach ( $this->config->venues as $venue ) {
				if ( $venue['id'] === $venue_id ) {
					$venue_exists = true;
					break;
				}
			}

			if ( ! $venue_exists ) {
				$errors['home_away_preferences'] = sprintf(
					__( 'Team "%1$s" has preferred home venue "%2$s" which does not exist. Please select a valid venue.', 'sportspress-schedule-generator' ),
					$team_id,
					$venue_id
				);
				break;
			}
		}
	}

	/**
	 * Validate inter-division game counts don't exceed games per team
	 */
	private function validate_inter_division_games( &$errors ) {
		if ( empty( $this->config->inter_division_games ) ) {
			return;
		}

		$total_inter_division = 0;
		foreach ( $this->config->inter_division_games as $game_count ) {
			$total_inter_division += (int) $game_count;
		}

		if ( $total_inter_division > $this->config->games_per_team ) {
			$errors['inter_division_games'] = sprintf(
				__( 'Total inter-division games (%1$d) exceeds games per team (%2$d). Please reduce inter-division games or increase total games.', 'sportspress-schedule-generator' ),
				$total_inter_division,
				$this->config->games_per_team
			);
		}
	}

	/**
	 * Validate resource capacity (time slots vs games needed)
	 */
	private function validate_capacity( &$errors ) {
		if ( empty( $this->config->divisions ) || empty( $this->config->time_slots ) || ! $this->config->season_start || ! $this->config->season_end ) {
			return;
		}

		$capacity_validation = $this->validate_resource_capacity();
		if ( is_wp_error( $capacity_validation ) ) {
			$errors['resource_capacity'] = $capacity_validation->get_error_message();
		}
	}

	/**
	 * Validate matchup style compatibility with division sizes
	 */
	private function validate_matchup_style_compatibility() {
		foreach ( $this->config->divisions as $division ) {
			$team_count = count( $division['teams'] ?? array() );

			if ( $team_count < 2 ) {
				continue; // Already validated elsewhere
			}

			if ( $this->config->matchup_style === 'single_round_robin' ) {
				$expected_games = $team_count - 1;

				if ( $this->config->games_per_team < $expected_games ) {
					return new WP_Error(
						'matchup_incompatible',
						sprintf(
							__( 'Division "%1$s" has %2$d teams. Single round-robin requires at least %3$d games per team, but only %4$d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator' ),
							$division['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' ),
							$team_count,
							$expected_games,
							$this->config->games_per_team
						)
					);
				}
			} elseif ( $this->config->matchup_style === 'double_round_robin' ) {
				$expected_games = ( $team_count - 1 ) * 2;

				if ( $this->config->games_per_team < $expected_games ) {
					return new WP_Error(
						'matchup_incompatible',
						sprintf(
							__( 'Division "%1$s" has %2$d teams. Double round-robin requires at least %3$d games per team, but only %4$d configured. Please increase games per team or change matchup style.', 'sportspress-schedule-generator' ),
							$division['name'] ?? __( 'Unnamed', 'sportspress-schedule-generator' ),
							$team_count,
							$expected_games,
							$this->config->games_per_team
						)
					);
				}
			}
		}

		return true;
	}

	/**
	 * Validate resource capacity (time slots vs games needed)
	 *
	 * H18: the old estimate was `weekly_slots × weeks − blackout_slots`, where
	 * `weekly_slots` counted the global time-slot list exactly once regardless of
	 * how many venues exist — while the slot allocator emits one slot per
	 * (venue, date, time). A three-venue league therefore looked ~3× smaller than
	 * it is and was rejected well below real capacity. Delegating to
	 * SPSG_Schedule_Helper::count_available_slots() reuses the allocator's own
	 * cascade (venue_date_availability → venue_timeslots → time_slots) including
	 * global and per-venue blackouts, so validation and allocation agree.
	 */
	private function validate_resource_capacity() {
		$total_teams = $this->count_total_teams();

		if ( $total_teams === 0 ) {
			return true;
		}

		$total_games_needed = ( $total_teams * $this->config->games_per_team ) / 2;

		if ( $this->count_weekly_slots() === 0 ) {
			return new WP_Error(
				'insufficient_timeslots',
				__( 'No time slots configured for the selected playing days. Please add time slots.', 'sportspress-schedule-generator' )
			);
		}

		$total_slots_available = SPSG_Schedule_Helper::count_available_slots( $this->config );

		if ( $total_slots_available <= 0 ) {
			return new WP_Error(
				'insufficient_timeslots',
				__( 'No time slots configured for the selected playing days. Please add time slots.', 'sportspress-schedule-generator' )
			);
		}

		return $this->check_capacity_thresholds( $total_games_needed, $total_slots_available );
	}

	/**
	 * Count total teams across all divisions
	 */
	private function count_total_teams() {
		$total = 0;
		foreach ( $this->config->divisions as $division ) {
			$total += count( $division['teams'] ?? array() );
		}
		return $total;
	}

	/**
	 * Count available time slots per week
	 */
	private function count_weekly_slots() {
		$slots = 0;
		foreach ( $this->config->playing_days as $day ) {
			if ( isset( $this->config->time_slots[ $day ] ) ) {
				$slots += count( $this->config->time_slots[ $day ] );
			}
		}
		return $slots;
	}

	/**
	 * Check capacity thresholds and return appropriate error/success
	 *
	 * H18: only a genuine shortfall blocks. The "tight capacity" tier is advisory
	 * — it was previously returned as a WP_Error, which the generate path treats
	 * as a hard validation failure, so a perfectly schedulable season was refused
	 * with a warning-worded message.
	 */
	private function check_capacity_thresholds( $total_games_needed, $total_slots_available ) {
		$effective_capacity = $total_slots_available * 0.8;

		if ( $total_games_needed > $effective_capacity ) {
			return new WP_Error(
				'insufficient_capacity',
				sprintf(
					__( 'Insufficient time slots: Need %1$d games but only %2$d effective slots available (%3$.0f slots with 20%% buffer for constraints). Suggestions: Add more time slots, extend season, reduce games per team, or remove blackout dates.', 'sportspress-schedule-generator' ),
					$total_games_needed,
					floor( $effective_capacity ),
					$total_slots_available
				)
			);
		}

		if ( $total_games_needed > ( $total_slots_available * 0.7 ) ) {
			$this->warnings['tight_capacity'] = sprintf(
				__( 'Warning: Schedule capacity is tight. Need %1$d games with only %2$d slots available. Consider adding more time slots or extending the season for better scheduling flexibility.', 'sportspress-schedule-generator' ),
				$total_games_needed,
				$total_slots_available
			);
		}

		return true;
	}

	/**
	 * Get venue name by ID
	 */
	private function get_venue_name( $venue_id ) {
		foreach ( $this->config->venues as $venue ) {
			if ( $venue['id'] === $venue_id ) {
				return $venue['name'] ?? $venue_id;
			}
		}
		return $venue_id;
	}
}
