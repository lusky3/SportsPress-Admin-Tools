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
	 * Saved configuration id (empty for a configuration that has never been
	 * saved). Never touched by SPSG_Configuration_Validator -- this is
	 * storage identity, not something a schedule can be valid or invalid on.
	 *
	 * @var string
	 */
	public $id;

	/**
	 * Configuration name, as entered on the Basic Configuration tab.
	 *
	 * @var string
	 */
	public $name;

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
	 * Whether this configuration is a postseason bracket (as opposed to a
	 * regular-season schedule). See docs/superpowers/specs (kept locally,
	 * not in this repo) for the postseason/playoffs design.
	 *
	 * @var bool
	 */
	public $is_postseason;

	/**
	 * The regular-season SPSG_Schedule_Configuration id this postseason
	 * configuration's divisions/venues were copied from, if any -- empty for
	 * a regular-season configuration. Provenance only; the copy is a
	 * snapshot, freely edited afterward (e.g. re-grouped into custom pools).
	 *
	 * @var string
	 */
	public $postseason_source_config_id;

	/**
	 * The regular season's sp_season term id -- the season initial seeding
	 * is computed from. 0 for a regular-season configuration.
	 *
	 * @var int
	 */
	public $postseason_source_season_id;

	/**
	 * This postseason bracket's own (child) sp_season term id, once created.
	 * 0 until the child season has been created.
	 *
	 * @var int
	 */
	public $postseason_season_id;

	/**
	 * Number of cross round-robin weeks before the final
	 * (championship/consolation) week. Meaningless outside a postseason
	 * configuration.
	 *
	 * @var int
	 */
	public $round_robin_weeks;

	/**
	 * Day-of-week + time window every division's Championship game must land
	 * on, e.g. array( 'day' => 'friday', 'start' => '18:00', 'end' => '21:00' ).
	 *
	 * @var array
	 */
	public $championship_day;

	/**
	 * Day of week every division's Consolation game must land on (no time
	 * restriction).
	 *
	 * @var string
	 */
	public $consolation_day;

	/**
	 * 'manual' (an admin action resolves seed placeholders) or 'automatic'
	 * (resolved as soon as every game in the relevant range has a completed
	 * score).
	 *
	 * @var string
	 */
	public $seed_resolution_mode;

	/**
	 * Non-blocking warnings from the most recent validate() call (H18).
	 *
	 * @var array
	 */
	private $validation_warnings = array();

	/**
	 * Constructor
	 */
	public function __construct( $data = array() ) {
		$this->load_from_array( $data );
	}

	/**
	 * Set storage identity fields (id, name) from raw config data.
	 *
	 * Kept separate from the rest of load_from_array(): these are never
	 * defaulted from $defaults and never round-tripped through the
	 * DateTime/array coercion the rest of that method does. An empty string
	 * (not unset) is the correct value for a configuration that has never
	 * been saved: it's what a brand-new admin form's hidden id field
	 * submits, and what save() checks for to decide whether to mint a new
	 * id.
	 *
	 * @param array $data Raw configuration data.
	 */
	private function load_identity_fields( $data ) {
		$this->id = isset( $data['id'] ) ? (string) $data['id'] : '';
		$this->name = isset( $data['name'] ) ? (string) $data['name'] : '';
	}

	/**
	 * Load configuration from array
	 */
	public function load_from_array( $data ) {
		$this->load_identity_fields( $data );

		$defaults = array(
			'games_per_team' => 0,
			'match_length' => 60,
			'matchup_style' => 'double_round_robin',
			'timezone' => wp_timezone_string(),
			'round_robin_weeks' => 3,
			'seed_resolution_mode' => 'manual',
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
			'championship_day',
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

		$this->load_postseason_fields( $data, $defaults );

		// H17: normalize legacy `*_avoidance` restriction keys on every load, not
		// just on import. Configurations stored before the rename kept rendering
		// their overlap / back-to-back groups in the admin UI (which reads both
		// spellings) while SPSG_Team_Restriction_Constraint only ever looks at the
		// canonical `*_avoid` keys — so shared-player protection was silently off.
		$this->team_restrictions = self::normalize_team_restrictions( $this->team_restrictions );

		$this->resolve_team_display_names();
	}

	/**
	 * Load the postseason-only fields (schema added alongside the
	 * postseason/playoffs design's phase 3 -- design notes kept locally, not
	 * in this repo). Split out of load_from_array() purely to keep that
	 * method's own complexity down; these fields have no other coupling to
	 * the rest of load_from_array()'s work.
	 *
	 * @param array $data     Raw configuration data.
	 * @param array $defaults This load's resolved defaults (round_robin_weeks,
	 *                         seed_resolution_mode).
	 */
	private function load_postseason_fields( $data, $defaults ) {
		$this->is_postseason               = ! empty( $data['is_postseason'] );
		$this->postseason_source_config_id = isset( $data['postseason_source_config_id'] ) ? (string) $data['postseason_source_config_id'] : '';
		$this->postseason_source_season_id = (int) ( $data['postseason_source_season_id'] ?? 0 );
		$this->postseason_season_id        = (int) ( $data['postseason_season_id'] ?? 0 );
		$this->round_robin_weeks           = (int) ( $data['round_robin_weeks'] ?? $defaults['round_robin_weeks'] );
		$this->consolation_day             = isset( $data['consolation_day'] ) ? (string) $data['consolation_day'] : '';
		$this->seed_resolution_mode        = $data['seed_resolution_mode'] ?? $defaults['seed_resolution_mode'];
	}

	/**
	 * Resolve any bare SportsPress team-ID entries in divisions[].teams to
	 * their real team name, in place.
	 *
	 * A division's teams are normally plain name strings (typed by hand, or
	 * embedded by the "Load from SportsPress" picker, which writes the real
	 * name at the time a team is added). A configuration authored directly
	 * through the REST API (bypassing that picker) can instead store a bare
	 * `sp_team` post ID in that slot. Nothing downstream ever looked such an
	 * ID up, so the admin form, the generated schedule, and the SportsPress
	 * import's by-name matching all showed/matched on the raw ID instead of
	 * the team's real name.
	 *
	 * Runs on every load so the fix applies immediately without a one-time
	 * migration; an admin Save afterward persists the resolved names like any
	 * other edit. No-ops entirely outside a full WordPress/SportsPress
	 * runtime (see {@see SPSG_Sports_Press_Integration::resolve_team_name()}).
	 */
	private function resolve_team_display_names() {
		if ( empty( $this->divisions ) || ! class_exists( 'SPSG_Sports_Press_Integration' ) ) {
			return;
		}

		foreach ( $this->divisions as &$division ) {
			self::resolve_division_team_names( $division );
		}
		unset( $division );
	}

	/**
	 * Resolve one division's team entries in place.
	 *
	 * @param array $division Division data (by reference).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function resolve_division_team_names( &$division ) {
		if ( empty( $division['teams'] ) || ! is_array( $division['teams'] ) ) {
			return;
		}
		foreach ( $division['teams'] as &$team ) {
			if ( is_string( $team ) || is_int( $team ) ) {
				$team = SPSG_Sports_Press_Integration::resolve_team_name( $team );
			}
		}
		unset( $team );
	}

	/**
	 * Rename legacy team-restriction keys to their canonical spellings.
	 *
	 * `back_to_back_avoidance` → `back_to_back_avoid`
	 * `overlap_avoidance`      → `overlap_avoid`
	 *
	 * Canonical keys already present win; the legacy key is always removed so no
	 * consumer can read a stale copy.
	 *
	 * @param array $team_restrictions Raw team restrictions.
	 * @return array Normalized team restrictions.
	 */
	public static function normalize_team_restrictions( $team_restrictions ) {
		if ( ! is_array( $team_restrictions ) ) {
			return array();
		}

		$legacy_map = array(
			'back_to_back_avoidance' => 'back_to_back_avoid',
			'overlap_avoidance'      => 'overlap_avoid',
		);

		foreach ( $legacy_map as $legacy_key => $canonical_key ) {
			if ( isset( $team_restrictions[ $legacy_key ] ) ) {
				if ( ! isset( $team_restrictions[ $canonical_key ] ) ) {
					$team_restrictions[ $canonical_key ] = $team_restrictions[ $legacy_key ];
				}
				unset( $team_restrictions[ $legacy_key ] );
			}
		}

		return $team_restrictions;
	}

	/**
	 * Convert to array for storage
	 */
	public function to_array() {
		return array(
			'id' => $this->id,
			'name' => $this->name,
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
			'is_postseason' => $this->is_postseason,
			'postseason_source_config_id' => $this->postseason_source_config_id,
			'postseason_source_season_id' => $this->postseason_source_season_id,
			'postseason_season_id' => $this->postseason_season_id,
			'round_robin_weeks' => $this->round_robin_weeks,
			'championship_day' => $this->championship_day,
			'consolation_day' => $this->consolation_day,
			'seed_resolution_mode' => $this->seed_resolution_mode,
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
		$result    = $validator->validate();

		// H18: capacity advisories are non-blocking. Keep them retrievable via
		// get_validation_warnings() so the admin UI can show them without the
		// validator having to fail the whole configuration.
		$this->validation_warnings = $validator->get_warnings();

		return $result;
	}

	/**
	 * Non-blocking warnings raised by the last validate() call.
	 *
	 * @return array List of warning strings.
	 */
	public function get_validation_warnings() {
		return $this->validation_warnings;
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
