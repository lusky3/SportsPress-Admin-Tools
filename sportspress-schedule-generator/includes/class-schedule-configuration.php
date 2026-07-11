<?php
/**
 * Schedule Configuration Data Model
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule Configuration class
 *
 * Data model for schedule configurations. Delegates validation
 * to SPSG_Configuration_Validator and sanitization to SPSG_Configuration_Sanitizer.
 */
class SPSG_Schedule_Configuration {


	/**
	 * Season start date
	 *
	 * @var DateTime
	 */
	public $season_start;

	/**
	 * Season end date
	 *
	 * @var DateTime
	 */
	public $season_end;

	/**
	 * Number of games per team
	 *
	 * @var int
	 */
	public $games_per_team;

	/**
	 * Playing days (array of day names)
	 *
	 * @var array
	 */
	public $playing_days;

	/**
	 * Time slots keyed by day
	 *
	 * @var array
	 */
	public $time_slots;

	/**
	 * Divisions array
	 *
	 * @var array
	 */
	public $divisions;

	/**
	 * Venues array
	 *
	 * @var array
	 */
	public $venues;

	/**
	 * Venue-specific timeslots mapping
	 *
	 * @var array
	 */
	public $venue_timeslots;

	/**
	 * Venue-specific blackout dates (venue_id => array of dates)
	 *
	 * @var array
	 */
	public $venue_blackout_dates;

	/**
	 * Date-specific venue availability (venue_id => array of date ranges with time slots)
	 * Format: [venue_id => [['start_date' => 'Y-m-d', 'end_date' => 'Y-m-d', 'time_slots' => [...]]]]
	 *
	 * @var array
	 */
	public $venue_date_availability;

	/**
	 * Match length in minutes
	 *
	 * @var int
	 */
	public $match_length;

	/**
	 * Blackout dates
	 *
	 * @var array
	 */
	public $blackout_dates;

	/**
	 * Distribution rules
	 *
	 * @var array
	 */
	public $distribution_rules;

	/**
	 * Team restrictions
	 *
	 * @var array
	 */
	public $team_restrictions;

	/**
	 * Division grouping preferences
	 *
	 * @var array
	 */
	public $division_grouping;

	/**
	 * Timezone for the schedule
	 *
	 * @var string
	 */
	public $timezone;

	/**
	 * Matchup style (single_round_robin, double_round_robin, custom)
	 *
	 * @var string
	 */
	public $matchup_style;

	/**
	 * Home/away preferences (team_id => venue_id mapping)
	 *
	 * @var array
	 */
	public $home_away_preferences;

	/**
	 * Inter-division games configuration (division_pair => game_count)
	 *
	 * @var array
	 */
	public $inter_division_games;

	/**
	 * Generic/placeholder teams configuration
	 *
	 * @var array
	 */
	public $generic_teams;

	/**
	 * Constructor
	 */
	public function __construct( $data = array() ) {
		$this->load_from_array( $data );
	}

	/**
	 * Load configuration from array
	 */
	public function load_from_array( $data ) {
		$defaults = array(
			'games_per_team' => 0,
			'match_length' => 60,
			'matchup_style' => 'double_round_robin',
			'timezone' => wp_timezone_string(),
		);

		$array_fields = array(
			'playing_days',
			'time_slots',
			'divisions',
			'venues',
			'blackout_dates',
			'distribution_rules',
			'team_restrictions',
			'division_grouping',
			'venue_timeslots',
			'venue_blackout_dates',
			'venue_date_availability',
			'home_away_preferences',
			'inter_division_games',
			'generic_teams',
		);

		// Load date fields with error handling
		try {
			$this->season_start = isset( $data['season_start'] ) && $data['season_start'] !== '' ? new DateTime( $data['season_start'] ) : null;
		} catch ( Exception $e ) {
			$this->season_start = null;
		}
		try {
			$this->season_end = isset( $data['season_end'] ) && $data['season_end'] !== '' ? new DateTime( $data['season_end'] ) : null;
		} catch ( Exception $e ) {
			$this->season_end = null;
		}

		// Load integer fields
		$this->games_per_team = (int) ( $data['games_per_team'] ?? $defaults['games_per_team'] );
		$this->match_length = (int) ( $data['match_length'] ?? $defaults['match_length'] );

		// Load string fields with defaults
		$this->matchup_style = $data['matchup_style'] ?? $defaults['matchup_style'];
		$this->timezone = $data['timezone'] ?? $defaults['timezone'];

		// Load array fields
		foreach ( $array_fields as $field ) {
			$this->$field = isset( $data[ $field ] ) ? (array) $data[ $field ] : array();
		}
	}

	/**
	 * Convert to array for storage
	 */
	public function to_array() {
		return array(
			'season_start' => $this->season_start ? $this->season_start->format( 'Y-m-d' ) : '',
			'season_end' => $this->season_end ? $this->season_end->format( 'Y-m-d' ) : '',
			'games_per_team' => $this->games_per_team,
			'playing_days' => $this->playing_days,
			'time_slots' => $this->time_slots,
			'divisions' => $this->divisions,
			'venues' => $this->venues,
			'blackout_dates' => $this->blackout_dates,
			'distribution_rules' => $this->distribution_rules,
			'team_restrictions' => $this->team_restrictions,
			'division_grouping' => $this->division_grouping,
			'timezone' => $this->timezone,
			'venue_timeslots' => $this->venue_timeslots,
			'venue_blackout_dates' => $this->venue_blackout_dates,
			'venue_date_availability' => $this->venue_date_availability,
			'match_length' => $this->match_length,
			'matchup_style' => $this->matchup_style,
			'home_away_preferences' => $this->home_away_preferences,
			'inter_division_games' => $this->inter_division_games,
			'generic_teams' => $this->generic_teams,
		);
	}

	/**
	 * Validate configuration
	 *
	 * Delegates to SPSG_Configuration_Validator.
	 *
	 * @return bool|WP_Error True if valid, WP_Error with details if invalid
	 */
	public function validate() {
		$validator = new SPSG_Configuration_Validator( $this );
		return $validator->validate();
	}

	/**
	 * Sanitize configuration data
	 *
	 * Delegates to SPSG_Configuration_Sanitizer.
	 *
	 * @param array $data Raw configuration data
	 * @return array Sanitized configuration data
	 */
	public function sanitize( $data ) {
		$sanitizer = new SPSG_Configuration_Sanitizer();
		return $sanitizer->sanitize( $data );
	}
}
