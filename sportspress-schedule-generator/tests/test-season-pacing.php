<?php
/**
 * Test: games are paced across the whole season and teams do not play twice
 * on one date while other dates have room.
 *
 * SPSG_Slot_Allocator::find_best_slot() used to gather the first
 * MAX_SLOT_CANDIDATES valid slots in chronological order and pick the
 * cheapest of those. Once a division's early dates were occupied, both teams
 * of every remaining matchup already played on every date inside that short
 * window, so the double-header penalty had nowhere to steer and the game
 * landed on an early date anyway. A real 272-game season came out with 124
 * team double-headers packed into 31 of 49 dates, leaving the last two months
 * empty.
 *
 * Candidates are now gathered around each team's pace target (the k-th of a
 * team's T games belongs roughly k/T of the way through the season), dates on
 * which either team already plays are only considered when nothing else is
 * placeable, and a pacing term keeps the pick near the target within the
 * window.
 *
 * Standalone — bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	/**
	 * Stub mirroring the WordPress signature; the unused argument is deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
if ( ! function_exists( 'get_option' ) ) {
	/**
	 * Stub mirroring the WordPress signature; the unused argument is deliberate.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
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
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
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
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-registry.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-constraint-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-slot-allocator.php';
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

function sp_assert( $cond, $msg ) {
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

function sp_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . ' Team ' . $i );
	}
	return $out;
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function sp_id( $entity ) {
	return SPSG_Schedule_Helper::extract_id( $entity );
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
function sp_allocate( $config ) {
	SPSG_Abstract_Constraint::reset_validate_cache();
	SPSG_Schedule_Helper::reset_venue_slots_cache();

	$generator = new SPSG_Matchup_Generator();
	$matchups  = array_map( function ( $m ) { return (object) $m; }, $generator->generate( $config ) );

	$allocator = new SPSG_Slot_Allocator( new SPSG_Constraint_Manager() );
	return $allocator->allocate( $matchups, $config );
}

/**
 * Summarise a schedule: team double-headers, dates used, per-team largest gap
 * (in playing dates) between consecutive games, and the last date used.
 */
function sp_summarise( $schedule, $all_dates ) {
	$date_index = array_flip( $all_dates );
	$team_dates = array();
	$used_dates = array();

	foreach ( $schedule as $g ) {
		$used_dates[ $g->date ] = true;
		foreach ( array( $g->home_team, $g->away_team ) as $t ) {
			$team_dates[ sp_id( $t ) ][] = $g->date;
		}
	}

	$double_headers = 0;
	$max_gap        = 0;
	foreach ( $team_dates as $dates ) {
		$counts = array_count_values( $dates );
		foreach ( $counts as $c ) {
			if ( $c > 1 ) {
				$double_headers += $c - 1;
			}
		}
		$idx = array_map( function ( $d ) use ( $date_index ) { return $date_index[ $d ]; }, array_keys( $counts ) );
		sort( $idx );
		// Gap from season start to first game and from last game to season end
		// count too: a team parked in the first half of the season has a huge
		// trailing gap.
		$prev = -1;
		foreach ( $idx as $i ) {
			$max_gap = max( $max_gap, $i - $prev );
			$prev    = $i;
		}
		$max_gap = max( $max_gap, count( $all_dates ) - $prev );
	}

	$used = array_keys( $used_dates );
	sort( $used );

	return array(
		'double_headers' => $double_headers,
		'dates_used'     => count( $used ),
		'last_date'      => end( $used ),
		'max_gap'        => $max_gap,
	);
}

echo "=== Testing season pacing ===\n\n";

// ---------------------------------------------------------------------------
// 1. One division, generous capacity: 6 teams x 10 games = 30 games over 20
//    Fridays with 6 hourly slots each (120 slots). A chronological fill puts
//    all 30 games on the first 5 dates with every team playing twice a night.
// ---------------------------------------------------------------------------
echo "Test 1: a 30-game season is spread across 20 Fridays with no double-headers\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-10-02',
		'season_end'         => '2027-02-12',
		'games_per_team'     => 10,
		'matchup_style'      => 'custom',
		'playing_days'       => array( 'friday' ),
		'time_slots'         => array( 'friday' => array( '18:00', '19:00', '20:00', '21:00', '22:00', '23:00' ) ),
		'divisions'          => array( array( 'id' => 'd1', 'name' => 'D1', 'teams' => sp_teams( 't', 6 ) ) ),
		'venues'             => array( array( 'id' => 'rink', 'name' => 'Rink' ) ),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array( 'home_away_balance' => true ),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
		'timezone'           => 'America/Toronto',
	)
);

$all_dates = array();
$cursor    = new DateTime( '2026-10-02' );
while ( $cursor <= new DateTime( '2027-02-12' ) ) {
	$all_dates[] = $cursor->format( 'Y-m-d' );
	$cursor->modify( '+7 days' );
}
sp_assert( 20 === count( $all_dates ), 'fixture spans 20 playing dates (' . count( $all_dates ) . ')' );

$schedule = sp_allocate( $config );

if ( sp_assert( ! is_wp_error( $schedule ), 'allocation succeeds' . ( is_wp_error( $schedule ) ? ' (' . $schedule->get_error_code() . ')' : '' ) ) ) {
	sp_assert( 30 === count( $schedule ), 'all 30 games placed (' . count( $schedule ) . ')' );

	$s = sp_summarise( $schedule, $all_dates );
	sp_assert( 0 === $s['double_headers'], 'no team plays twice on one date (' . $s['double_headers'] . ' double-headers)' );
	sp_assert( $s['dates_used'] >= 16, 'games use at least 16 of the 20 dates (' . $s['dates_used'] . ')' );
	sp_assert( $s['last_date'] >= '2027-01-29', 'the season is used to its end (last game ' . $s['last_date'] . ')' );
	// 10 games over 20 dates is a game every 2 dates; allow twice that.
	sp_assert( $s['max_gap'] <= 4, 'no team waits more than 4 playing dates between games (worst ' . $s['max_gap'] . ')' );
}

// ---------------------------------------------------------------------------
// 2. Several divisions competing for the same dates still pace: 3 divisions
//    of 6 over Fri+Sun with two rinks. 3 x 6 x 8 / 2 = 72 games, capacity
//    16 dates x 2 rinks x 4 slots = 128.
// ---------------------------------------------------------------------------
echo "\nTest 2: three divisions sharing two rinks still pace and avoid double-headers\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'       => '2026-10-02',
		'season_end'         => '2026-11-22',
		'games_per_team'     => 8,
		'matchup_style'      => 'custom',
		'playing_days'       => array( 'friday', 'sunday' ),
		'time_slots'         => array(
			'friday' => array( '19:00', '20:00', '21:00', '22:00' ),
			'sunday' => array( '16:00', '17:00', '18:00', '19:00' ),
		),
		'divisions'          => array(
			array( 'id' => 'a', 'name' => 'A', 'teams' => sp_teams( 'a', 6 ) ),
			array( 'id' => 'b', 'name' => 'B', 'teams' => sp_teams( 'b', 6 ) ),
			array( 'id' => 'c', 'name' => 'C', 'teams' => sp_teams( 'c', 6 ) ),
		),
		'venues'             => array(
			array( 'id' => 'black', 'name' => 'Black' ),
			array( 'id' => 'red', 'name' => 'Red' ),
		),
		'match_length'       => 60,
		'blackout_dates'     => array(),
		'distribution_rules' => array( 'home_away_balance' => true ),
		'division_grouping'  => array( 'enabled' => false ),
		'team_restrictions'  => array(),
		'timezone'           => 'America/Toronto',
	)
);

$all_dates = array();
$cursor    = new DateTime( '2026-10-02' );
while ( $cursor <= new DateTime( '2026-11-22' ) ) {
	$day = strtolower( $cursor->format( 'l' ) );
	if ( 'friday' === $day || 'sunday' === $day ) {
		$all_dates[] = $cursor->format( 'Y-m-d' );
	}
	$cursor->modify( '+1 day' );
}
sp_assert( 16 === count( $all_dates ), 'fixture spans 16 playing dates (' . count( $all_dates ) . ')' );

$schedule = sp_allocate( $config );

if ( sp_assert( ! is_wp_error( $schedule ), 'allocation succeeds' . ( is_wp_error( $schedule ) ? ' (' . $schedule->get_error_code() . ')' : '' ) ) ) {
	sp_assert( 72 === count( $schedule ), 'all 72 games placed (' . count( $schedule ) . ')' );

	$s = sp_summarise( $schedule, $all_dates );
	sp_assert( 0 === $s['double_headers'], 'no team plays twice on one date (' . $s['double_headers'] . ' double-headers)' );
	sp_assert( 16 === $s['dates_used'], 'every playing date carries games (' . $s['dates_used'] . ' of 16)' );
	// 8 games over 16 dates is a game every 2 dates; allow twice that.
	sp_assert( $s['max_gap'] <= 4, 'no team waits more than 4 playing dates between games (worst ' . $s['max_gap'] . ')' );

	$per_date = array_count_values( array_map( function ( $g ) { return $g->date; }, $schedule ) );
	sp_assert( max( $per_date ) - min( $per_date ) <= 3, 'per-date load is even (min ' . min( $per_date ) . ', max ' . max( $per_date ) . ')' );
}

// ---------------------------------------------------------------------------
// 3. The configured day split steers the whole schedule, not just each team.
//    Same fixture as test 2 with day_balance 75/25 Friday/Sunday: 54 of the
//    72 games belong on the 8 Fridays (6.75 of 8 slots each) and 18 on the 8
//    Sundays. Spreading load by raw capacity would pull this back to 50/50.
// ---------------------------------------------------------------------------
echo "\nTest 3: day_balance 75/25 is reflected in the overall Friday share\n";

$config->distribution_rules = array(
	'day_balance'       => array( 'friday' => 0.75, 'sunday' => 0.25 ),
	'time_slot_balance' => true,
	'home_away_balance' => true,
);

$schedule = sp_allocate( $config );

if ( sp_assert( ! is_wp_error( $schedule ), 'allocation succeeds' . ( is_wp_error( $schedule ) ? ' (' . $schedule->get_error_code() . ')' : '' ) ) ) {
	$friday = 0;
	foreach ( $schedule as $g ) {
		if ( 'friday' === $g->day ) {
			$friday++;
		}
	}
	$share = 100 * $friday / count( $schedule );
	sp_assert( $share >= 65 && $share <= 85, sprintf( 'Friday carries roughly 75%% of games (%.0f%%)', $share ) );

	$s = sp_summarise( $schedule, $all_dates );
	sp_assert( 0 === $s['double_headers'], 'still no double-headers (' . $s['double_headers'] . ')' );
	sp_assert( $s['max_gap'] <= 5, 'pacing holds under a skewed day split (worst gap ' . $s['max_gap'] . ')' );
}

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
