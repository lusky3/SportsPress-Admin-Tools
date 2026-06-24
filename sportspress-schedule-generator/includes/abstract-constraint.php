<?php
/**
 * Abstract Base Constraint Class
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

/**
 * Abstract base class for all constraints
 */
abstract class SPSG_Abstract_Constraint implements SPSG_Constraint_Interface {


	/**
	 * Constraint name
	 */
	protected $name;

	/**
	 * Constraint priority
	 */
	protected $priority = 10;

	/**
	 * Constraint type
	 */
	protected $type = 'hard';

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->init();
	}

	/**
	 * Initialize constraint (override in child classes)
	 */
	protected function init() {
		// Override in child classes
	}

	/**
	 * Get constraint priority
	 */
	public function get_priority() {
		return $this->priority;
	}

	/**
	 * Set constraint priority
	 *
	 * @param int $priority Priority value
	 */
	public function set_priority( $priority ) {
		$this->priority = (int) $priority;
	}

	/**
	 * Get constraint type
	 */
	public function get_type() {
		return $this->type;
	}

	/**
	 * Get constraint name
	 */
	public function get_name() {
		return $this->name ?: get_class( $this );
	}

	/**
	 * Per-request memoization for validate() results.
	 *
	 * Keyed by spl_object_hash( $constraint ) . '-' . $game_id . '-' . $schedule_hash,
	 * so a cached result is only reused when the same (game, constraint) pair is
	 * evaluated against the *same* schedule slice. This avoids a redundant second
	 * validate() inside get_violation_cost for hard constraints, which the slot
	 * allocator just checked via is_slot_valid.
	 *
	 * SG-1: the schedule-slice component is essential for correctness under
	 * backtracking. A game id is md5(home|away|date|time|venue), so the same
	 * matchup re-placed at the same slot after an array_pop carries the *same*
	 * id, but the surrounding schedule has changed. Without hashing the schedule
	 * slice into the key, a stale validate() result from an earlier, smaller (or
	 * different) schedule could be reused and let a hard-constraint-violating game
	 * pass. Hashing the slice makes any state change yield a distinct key, so the
	 * greedy fast-path still hits the cache (identical state → identical key) while
	 * backtracking can never reuse a result computed against a different state.
	 *
	 * @var array<string,true|WP_Error>
	 */
	protected static $validate_cache = array();

	/**
	 * Reset the per-request validate cache. Should be called by the engine at
	 * the start of each generation attempt to avoid stale state across runs.
	 */
	public static function reset_validate_cache() {
		self::$validate_cache = array();
	}

	/**
	 * Build the memoization key for a (constraint, game, schedule-slice) tuple.
	 *
	 * The schedule slice is fingerprinted so a cached result is never reused
	 * against a different schedule state (see SG-1 note on $validate_cache).
	 * Only the fields that a constraint's validate() can observe — the game
	 * identity of each scheduled game, its date/time/venue — feed the hash, so
	 * the fingerprint is stable across reruns with identical inputs.
	 *
	 * @param SPSG_Abstract_Constraint $constraint Constraint instance.
	 * @param object                   $game       Game being validated.
	 * @param array                    $schedule   Schedule slice passed to validate().
	 * @return string Cache key.
	 */
	private static function build_cache_key( $constraint, $game, $schedule ) {
		$game_id = isset( $game->id ) ? $game->id : spl_object_hash( $game );
		return spl_object_hash( $constraint ) . '-' . $game_id . '-' . self::hash_schedule_slice( $schedule );
	}

	/**
	 * Produce a stable fingerprint of the schedule slice handed to validate().
	 *
	 * @param mixed $schedule Array of game objects (flat or date-indexed) or any
	 *                        other value a caller might pass.
	 * @return string Fingerprint (empty schedule → '0').
	 */
	private static function hash_schedule_slice( $schedule ) {
		if ( empty( $schedule ) || ! is_array( $schedule ) ) {
			return '0';
		}
		$parts = array();
		foreach ( $schedule as $g ) {
			if ( is_object( $g ) ) {
				$gid = isset( $g->id ) ? $g->id : spl_object_hash( $g );
				$parts[] = $gid;
			} elseif ( is_array( $g ) ) {
				// Date-indexed bucket ('YYYY-MM-DD' => game[]); descend one level.
				$parts[] = self::hash_schedule_slice( $g );
			}
		}
		// Order matters for the slice the constraint sees; do not sort.
		return md5( implode( '|', $parts ) );
	}

	/**
	 * Prime the per-request validate cache with an externally computed result.
	 *
	 * Called by the constraint manager after running validate() so a subsequent
	 * get_violation_cost() does not re-run validate() for the same pair *against
	 * the same schedule slice*.
	 *
	 * @param SPSG_Abstract_Constraint $constraint Constraint instance.
	 * @param object                   $game       Game just validated.
	 * @param true|WP_Error            $result     The validate() result.
	 * @param array                    $schedule   Schedule slice validate() saw.
	 */
	public static function prime_validate_cache( $constraint, $game, $result, $schedule = array() ) {
		if ( ! ( $constraint instanceof self ) ) {
			return;
		}
		$key = self::build_cache_key( $constraint, $game, $schedule );
		self::$validate_cache[ $key ] = $result;
	}

	/**
	 * Validate with memoization. Use this from get_violation_cost so a hard
	 * constraint already validated by the allocator is not re-run — but only
	 * when the schedule slice is identical to the one already validated.
	 *
	 * @param object $game     Game being checked.
	 * @param array  $schedule Schedule slice.
	 * @param object $config   Configuration.
	 * @return true|WP_Error
	 */
	protected function validate_cached( $game, $schedule, $config ) {
		$key = self::build_cache_key( $this, $game, $schedule );
		if ( array_key_exists( $key, self::$validate_cache ) ) {
			return self::$validate_cache[ $key ];
		}
		$result = $this->validate( $game, $schedule, $config );
		self::$validate_cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Default violation cost implementation
	 */
	public function get_violation_cost( $game, $schedule, $config ) {
		if ( $this->type === 'hard' ) {
			$result = $this->validate_cached( $game, $schedule, $config );
			if ( is_wp_error( $result ) ) {
				return PHP_FLOAT_MAX; // Hard constraints have infinite cost when violated
			}
			return 0.0;
		}

		// Override in child classes for soft/optimization constraints
		return 0.0;
	}

	/**
	 * Log constraint activity (if debug enabled)
	 */
	protected function log( $message ) {
		if ( get_option( 'spsg_enable_debug_logging', '0' ) === '1' ) {
			error_log( sprintf( '[SPSG Constraint %s] %s', $this->get_name(), $message ) );
		}
	}

	/**
	 * Helper to safely get team ID from object or array
	 */
	protected function get_team_id( $team ) {
		return is_array( $team ) ? $team['id'] : $team->id;
	}

	/**
	 * Helper to safely get venue ID from object or array
	 */
	protected function get_venue_id( $venue ) {
		return is_array( $venue ) ? $venue['id'] : $venue->id;
	}

	/**
	 * Helper to safely get venue name from object or array
	 */
	protected function get_venue_name( $venue ) {
		return is_array( $venue ) ? $venue['name'] : $venue->name;
	}
}
