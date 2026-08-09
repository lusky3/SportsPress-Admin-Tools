<?php
/**
 * Test Engine Correctness (August 2026 audit regression guards)
 *
 * Covers:
 *  - H13: hard team restrictions must be enforced on the FIRST game placed on a
 *         date, not skipped by the "no games yet, always valid" short-circuit.
 *  - H14: soft/optimization constraints must influence slot selection — in
 *         particular a team must not be handed two games on one date while
 *         other dates still have room.
 *  - H15: a double round-robin must never put a pair's two meetings on the same
 *         date.
 *  - H17: legacy `*_avoidance` restriction keys must be normalized on load, not
 *         only on import, or the constraint engine silently ignores them.
 *  - H19: a round-robin + inter-division configuration that passes validation
 *         must also generate.
 *  - M52: back-to-back adjacency must be measured in minutes, not by index into
 *         a per-venue slot grid.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------------------------------------------------------------------------
// WP mocks.
// ---------------------------------------------------------------------------
if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); }
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() { return 1; }
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $tag ) {}
}

$GLOBALS['spsg_test_transients'] = array();
$GLOBALS['spsg_test_cache']      = array();

if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $k, $v, $t = 0 ) { $GLOBALS['spsg_test_transients'][ $k ] = $v; return true; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $k ) { return $GLOBALS['spsg_test_transients'][ $k ] ?? false; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $k ) { unset( $GLOBALS['spsg_test_transients'][ $k ] ); return true; }
}
if ( ! function_exists( 'wp_cache_set' ) ) {
	function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['spsg_test_cache'][ $g ][ $k ] = $v; return true; }
}
if ( ! function_exists( 'wp_cache_get' ) ) {
	function wp_cache_get( $k, $g = '' ) { return $GLOBALS['spsg_test_cache'][ $g ][ $k ] ?? false; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['spsg_test_cache'][ $g ][ $k ] ); return true; }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_messages() { return array( $this->message ); }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-registry.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-engine.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-blackout-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-division-grouping-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-team-restriction-constraint.php';

SPSG_Constraint_Registry::register( 'SPSG_Blackout_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Distribution_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Division_Grouping_Constraint' );
SPSG_Constraint_Registry::register( 'SPSG_Team_Restriction_Constraint' );

$passed = 0;
$failed = 0;

function ec_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

function ec_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . ' Team ' . $i );
	}
	return $out;
}

function ec_id( $entity ) {
	return SPSG_Schedule_Helper::extract_id( $entity );
}

/**
 * Build a realistic league configuration. Overrides are merged shallowly.
 */
function ec_config( $overrides = array() ) {
	$data = array_merge(
		array(
			'season_start'       => '2026-09-04',
			'season_end'         => '2027-03-28',
			'games_per_team'     => 10,
			'matchup_style'      => 'double_round_robin',
			'playing_days'       => array( 'friday', 'sunday' ),
			'time_slots'         => array(
				'friday' => array( '19:00', '20:15', '21:30' ),
				'sunday' => array( '14:00', '15:15', '16:30' ),
			),
			'divisions'          => array(
				array( 'id' => 'divA', 'name' => 'Division A', 'teams' => ec_teams( 'a', 6 ) ),
			),
			'venues'             => array(
				array( 'id' => 'v1', 'name' => 'Arena One' ),
				array( 'id' => 'v2', 'name' => 'Arena Two' ),
			),
			'match_length'       => 60,
			'blackout_dates'     => array(),
			'distribution_rules' => array( 'home_away_balance' => true ),
			'division_grouping'  => array( 'enabled' => true, 'priority' => 5 ),
			'team_restrictions'  => array(),
			'timezone'           => 'America/Toronto',
		),
		$overrides
	);

	return new SPSG_Schedule_Configuration( $data );
}

/**
 * Run matchup generation + slot allocation and return the placed games.
 *
 * @return array|WP_Error
 */
function ec_allocate( $config ) {
	SPSG_Abstract_Constraint::reset_validate_cache();
	SPSG_Schedule_Helper::reset_venue_slots_cache();

	$generator = new SPSG_Matchup_Generator();
	$matchups  = $generator->generate( $config );

	$objects = array();
	foreach ( $matchups as $m ) {
		$objects[] = (object) $m;
	}

	$manager   = new SPSG_Constraint_Manager();
	$allocator = new SPSG_Slot_Allocator( $manager );

	return $allocator->allocate( $objects, $config );
}

echo "=== Testing Engine Correctness (2026-08 audit) ===\n\n";

// ---------------------------------------------------------------------------
// H13 — hard restrictions must fire on a date's FIRST game.
// ---------------------------------------------------------------------------
echo "Test H13: hard day restriction enforced on the first game of each date\n";

// Team a1 may only play on Sundays. Fridays come first chronologically, so the
// opening slot of every Friday is exactly the slot the old short-circuit let
// through unchecked.
$config = ec_config(
	array(
		'team_restrictions' => array(
			'custom' => array(
				array(
					'type'         => 'day_restrictions',
					'teams'        => array( 'a1' ),
					'allowed_days' => array( 'sunday' ),
				),
			),
		),
	)
);

$schedule = ec_allocate( $config );

if ( is_wp_error( $schedule ) ) {
	ec_assert( false, 'H13: allocation should succeed with a day restriction (' . $schedule->get_error_code() . ')' );
} else {
	$violations   = 0;
	$first_of_day = array();
	$a1_games     = 0;

	// Track which game is first on each date so the regression is pinpointed.
	$by_date = array();
	foreach ( $schedule as $g ) {
		$by_date[ $g->date ][] = $g;
	}
	ksort( $by_date );

	foreach ( $by_date as $date => $games ) {
		usort( $games, function ( $x, $y ) { return strcmp( $x->time_slot, $y->time_slot ); } );
		$first_of_day[ $date ] = $games[0];
	}

	foreach ( $schedule as $g ) {
		if ( ec_id( $g->home_team ) !== 'a1' && ec_id( $g->away_team ) !== 'a1' ) {
			continue;
		}
		$a1_games++;
		if ( strtolower( gmdate( 'l', strtotime( $g->date ) ) ) !== 'sunday' ) {
			$violations++;
		}
	}

	ec_assert( $a1_games > 0, 'H13: restricted team actually got games (' . $a1_games . ')' );
	ec_assert( 0 === $violations, 'H13: restricted team never scheduled outside its allowed days (' . $violations . ' violations)' );

	// Direct assertion on the short-circuit itself: an empty date must still be
	// validated against the constraint set.
	$manager   = new SPSG_Constraint_Manager();
	$allocator = new SPSG_Slot_Allocator( $manager );
	$matchup   = (object) array(
		'home_team'         => array( 'id' => 'a1', 'name' => 'A Team 1' ),
		'away_team'         => array( 'id' => 'a2', 'name' => 'A Team 2' ),
		'division'          => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
		'is_inter_division' => false,
	);
	// 2026-09-04 is a Friday.
	$friday_slot = (object) array(
		'date'      => '2026-09-04',
		'day'       => 'friday',
		'time_slot' => '19:00',
		'venue'     => array( 'id' => 'v1', 'name' => 'Arena One' ),
	);
	$sunday_slot = (object) array(
		'date'      => '2026-09-06',
		'day'       => 'sunday',
		'time_slot' => '14:00',
		'venue'     => array( 'id' => 'v1', 'name' => 'Arena One' ),
	);

	SPSG_Abstract_Constraint::reset_validate_cache();
	$friday_valid = $allocator->is_slot_valid( $matchup, $friday_slot, array(), $config );
	SPSG_Abstract_Constraint::reset_validate_cache();
	$sunday_valid = $allocator->is_slot_valid( $matchup, $sunday_slot, array(), $config );

	ec_assert( false === $friday_valid, 'H13: is_slot_valid() rejects a restricted day on an EMPTY date' );
	ec_assert( true === $sunday_valid, 'H13: is_slot_valid() still accepts an allowed day on an empty date' );
}

echo "\n";

// ---------------------------------------------------------------------------
// H15 — double round-robin rematch spacing.
// ---------------------------------------------------------------------------
echo "Test H15: double round-robin never replays a pair on the same date\n";

$config   = ec_config();
$schedule = ec_allocate( $config );

if ( is_wp_error( $schedule ) ) {
	ec_assert( false, 'H15: allocation should succeed (' . $schedule->get_error_code() . ')' );
} else {
	$pair_dates = array();
	foreach ( $schedule as $g ) {
		$h = ec_id( $g->home_team );
		$a = ec_id( $g->away_team );
		$k = $h < $a ? "$h:$a" : "$a:$h";
		$pair_dates[ $k ][] = $g->date;
	}

	$same_date = 0;
	$min_gap   = PHP_INT_MAX;
	$pairs     = 0;

	foreach ( $pair_dates as $dates ) {
		sort( $dates );
		for ( $i = 1; $i < count( $dates ); $i++ ) {
			$pairs++;
			$gap = (int) ( ( strtotime( $dates[ $i ] ) - strtotime( $dates[ $i - 1 ] ) ) / 86400 );
			if ( 0 === $gap ) {
				$same_date++;
			}
			$min_gap = min( $min_gap, $gap );
		}
	}

	ec_assert( $pairs > 0, 'H15: schedule contains rematches to check (' . $pairs . ' pairs)' );
	ec_assert( 0 === $same_date, 'H15: no pair meets twice on the same date (' . $same_date . ' same-date rematches)' );
	ec_assert( $min_gap >= 7, 'H15: rematches are at least a week apart (min gap ' . $min_gap . ' days)' );

	// Round interleaving happens in the matchup generator itself — assert it
	// there too so the guard survives a change in allocation strategy.
	$generator = new SPSG_Matchup_Generator();
	$matchups  = $generator->generate( ec_config() );
	$seen      = array();
	$adjacent  = 0;
	foreach ( $matchups as $idx => $m ) {
		$h = ec_id( $m['home_team'] );
		$a = ec_id( $m['away_team'] );
		$k = $h < $a ? "$h:$a" : "$a:$h";
		if ( isset( $seen[ $k ] ) && ( $idx - $seen[ $k ] ) < 2 ) {
			$adjacent++;
		}
		$seen[ $k ] = $idx;
	}
	ec_assert( 0 === $adjacent, 'H15: matchup list never emits a pair twice back-to-back (' . $adjacent . ' adjacent)' );
}

echo "\n";

// ---------------------------------------------------------------------------
// H14 — soft/optimization constraints influence slot selection.
// ---------------------------------------------------------------------------
echo "Test H14: cost-based slot selection avoids same-date double-headers\n";

$config   = ec_config();
$schedule = ec_allocate( $config );

if ( is_wp_error( $schedule ) ) {
	ec_assert( false, 'H14: allocation should succeed (' . $schedule->get_error_code() . ')' );
} else {
	$team_dates = array();
	foreach ( $schedule as $g ) {
		foreach ( array( $g->home_team, $g->away_team ) as $t ) {
			$tid = ec_id( $t );
			$team_dates[ $tid ][ $g->date ] = ( $team_dates[ $tid ][ $g->date ] ?? 0 ) + 1;
		}
	}

	$doubles = 0;
	$worst   = 0;
	foreach ( $team_dates as $dates ) {
		foreach ( $dates as $count ) {
			if ( $count > 1 ) {
				$doubles++;
			}
			$worst = max( $worst, $count );
		}
	}

	// 6 teams / 12 games per date of capacity: the season has ample room, so a
	// cost-aware selector should produce (almost) no double-headers. Before the
	// fix this config produced dozens.
	ec_assert( $doubles <= 2, 'H14: at most 2 team-dates carry more than one game (' . $doubles . ')' );
	ec_assert( $worst <= 2, 'H14: no team plays 3+ games in one day (worst ' . $worst . ')' );

	$dates_used = count( array_unique( array_map( function ( $g ) { return $g->date; }, $schedule ) ) );
	ec_assert( $dates_used >= 10, 'H14: games spread over at least 10 dates (' . $dates_used . ')' );
}

echo "\n";

// ---------------------------------------------------------------------------
// H17 — legacy restriction keys normalized on load.
// ---------------------------------------------------------------------------
echo "Test H17: legacy *_avoidance keys normalized on load\n";

$legacy = new SPSG_Schedule_Configuration(
	array(
		'team_restrictions' => array(
			'overlap_avoidance'      => array( array( 'teams' => array( 'a1', 'a2' ), 'buffer_minutes' => 30 ) ),
			'back_to_back_avoidance' => array( array( 'teams' => array( 'a3', 'a4' ) ) ),
		),
	)
);

ec_assert(
	isset( $legacy->team_restrictions['overlap_avoid'] ) && ! isset( $legacy->team_restrictions['overlap_avoidance'] ),
	'H17: overlap_avoidance renamed to overlap_avoid on load'
);
ec_assert(
	isset( $legacy->team_restrictions['back_to_back_avoid'] ) && ! isset( $legacy->team_restrictions['back_to_back_avoidance'] ),
	'H17: back_to_back_avoidance renamed to back_to_back_avoid on load'
);
ec_assert(
	30 === ( $legacy->team_restrictions['overlap_avoid'][0]['buffer_minutes'] ?? null ),
	'H17: normalized restriction keeps its payload'
);

// Canonical key present already must win and the legacy copy must disappear.
$both = new SPSG_Schedule_Configuration(
	array(
		'team_restrictions' => array(
			'overlap_avoid'     => array( array( 'teams' => array( 'x' ) ) ),
			'overlap_avoidance' => array( array( 'teams' => array( 'y' ) ) ),
		),
	)
);
ec_assert(
	array( 'x' ) === ( $both->team_restrictions['overlap_avoid'][0]['teams'] ?? null )
		&& ! isset( $both->team_restrictions['overlap_avoidance'] ),
	'H17: canonical key wins over the legacy copy, legacy key removed'
);

// The constraint engine must now actually fire for a legacy-stored config.
$legacy_config = ec_config(
	array(
		'divisions'         => array(
			array( 'id' => 'divA', 'name' => 'Division A', 'teams' => ec_teams( 'a', 4 ) ),
		),
		'team_restrictions' => array(
			'overlap_avoidance' => array(
				array( 'teams' => array( 'a1', 'a3' ), 'buffer_minutes' => 0 ),
			),
		),
	)
);

$constraint = new SPSG_Team_Restriction_Constraint();
$game       = (object) array(
	'date'      => '2026-09-04',
	'time_slot' => '19:00',
	'home_team' => array( 'id' => 'a1', 'name' => 'A Team 1' ),
	'away_team' => array( 'id' => 'a2', 'name' => 'A Team 2' ),
	'venue'     => array( 'id' => 'v1', 'name' => 'Arena One' ),
	'division'  => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
);
$existing = (object) array(
	'date'      => '2026-09-04',
	'time_slot' => '19:00',
	'home_team' => array( 'id' => 'a3', 'name' => 'A Team 3' ),
	'away_team' => array( 'id' => 'a4', 'name' => 'A Team 4' ),
	'venue'     => array( 'id' => 'v2', 'name' => 'Arena Two' ),
	'division'  => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
);

$result = $constraint->validate( $game, array( $existing ), $legacy_config );
ec_assert( is_wp_error( $result ), 'H17: overlap restriction from a legacy-stored config actually fires' );

echo "\n";

// ---------------------------------------------------------------------------
// M52 — back-to-back adjacency measured in minutes.
// ---------------------------------------------------------------------------
echo "Test M52: back-to-back adjacency is time based, not slot-index based\n";

$b2b_config = ec_config(
	array(
		// Deliberately mismatched per-venue grids: the old positional check
		// compared indexes across these two lists and silently missed.
		'venue_timeslots'   => array(
			'v1' => array( 'friday' => array( '19:00', '20:15', '21:30' ) ),
			'v2' => array( 'friday' => array( '19:40', '20:55' ) ),
		),
		'team_restrictions' => array(
			'back_to_back_avoid' => array(
				array( 'teams' => array( 'a1', 'a2' ) ),
			),
		),
	)
);

$constraint = new SPSG_Team_Restriction_Constraint();
$game_v1    = (object) array(
	'date'      => '2026-09-04',
	'time_slot' => '19:00',
	'home_team' => array( 'id' => 'a1', 'name' => 'A Team 1' ),
	'away_team' => array( 'id' => 'a5', 'name' => 'A Team 5' ),
	'venue'     => array( 'id' => 'v1', 'name' => 'Arena One' ),
	'division'  => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
);
$adjacent_v2 = (object) array(
	'date'      => '2026-09-04',
	'time_slot' => '19:40', // 40 minutes later at another venue — clearly back to back.
	'home_team' => array( 'id' => 'a2', 'name' => 'A Team 2' ),
	'away_team' => array( 'id' => 'a6', 'name' => 'A Team 6' ),
	'venue'     => array( 'id' => 'v2', 'name' => 'Arena Two' ),
	'division'  => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
);
$far_v2 = (object) array(
	'date'      => '2026-09-04',
	'time_slot' => '22:30', // 3.5h later — not back to back.
	'home_team' => array( 'id' => 'a2', 'name' => 'A Team 2' ),
	'away_team' => array( 'id' => 'a6', 'name' => 'A Team 6' ),
	'venue'     => array( 'id' => 'v2', 'name' => 'Arena Two' ),
	'division'  => (object) array( 'id' => 'divA', 'name' => 'Division A' ),
);

SPSG_Abstract_Constraint::reset_validate_cache();
$near = $constraint->validate( $game_v1, array( $adjacent_v2 ), $b2b_config );
SPSG_Abstract_Constraint::reset_validate_cache();
$far = $constraint->validate( $game_v1, array( $far_v2 ), $b2b_config );

ec_assert( is_wp_error( $near ), 'M52: back-to-back fires across venues with different slot grids' );
ec_assert( true === $far, 'M52: games hours apart are not treated as back-to-back' );

echo "\n";

// ---------------------------------------------------------------------------
// H19 — round-robin + inter-division validates AND generates.
// ---------------------------------------------------------------------------
echo "Test H19: a validating round-robin + inter-division config also generates\n";

// 6 + 5 teams: the inter-division total (8) divides evenly into neither
// division, so the per-team counts CANNOT all equal games_per_team.
$rr_inter = ec_config(
	array(
		'games_per_team'       => 14,
		'matchup_style'        => 'double_round_robin',
		'divisions'            => array(
			array( 'id' => 'divA', 'name' => 'Division A', 'teams' => ec_teams( 'a', 6 ) ),
			array( 'id' => 'divB', 'name' => 'Division B', 'teams' => ec_teams( 'b', 5 ) ),
		),
		'inter_division_games' => array( 'divA:divB' => 8 ),
	)
);

$validation = $rr_inter->validate();
ec_assert( true === $validation, 'H19: configuration passes validation' );

if ( true === $validation ) {
	$engine = new SPSG_Schedule_Engine();
	$result = $engine->generate_schedule( $rr_inter );

	if ( is_wp_error( $result ) ) {
		$data    = $result->get_error_data();
		$details = is_array( $data ) && ! empty( $data['errors'] ) ? ' | ' . implode( ' ; ', array_slice( $data['errors'], 0, 3 ) ) : '';
		ec_assert( false, 'H19: generation must not fail after validation passed (' . $result->get_error_code() . $details . ')' );
	} else {
		ec_assert( true, 'H19: generation succeeded (' . count( $result['schedule'] ) . ' games)' );

		// Intra-division play must still be exact for every team.
		$intra = array();
		foreach ( $result['schedule'] as $g ) {
			if ( ! empty( $g->is_inter_division ) ) {
				continue;
			}
			$intra[ ec_id( $g->home_team ) ] = ( $intra[ ec_id( $g->home_team ) ] ?? 0 ) + 1;
			$intra[ ec_id( $g->away_team ) ] = ( $intra[ ec_id( $g->away_team ) ] ?? 0 ) + 1;
		}

		$a_ok = true;
		$b_ok = true;
		foreach ( $intra as $tid => $count ) {
			if ( 0 === strpos( $tid, 'a' ) && 10 !== $count ) { $a_ok = false; }
			if ( 0 === strpos( $tid, 'b' ) && 8 !== $count ) { $b_ok = false; }
		}
		ec_assert( $a_ok, 'H19: 6-team division plays an exact double round-robin (10 intra games each)' );
		ec_assert( $b_ok, 'H19: 5-team division plays an exact double round-robin (8 intra games each)' );
	}
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed > 0 ? 1 : 0 );
