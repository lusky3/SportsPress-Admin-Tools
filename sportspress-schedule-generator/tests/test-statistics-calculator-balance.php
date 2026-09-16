<?php
/**
 * Test: SPSG_Statistics_Calculator's new per-team balance and restricted-pair
 * reporting -- day_balance_per_team, night_position_per_team,
 * division_grouping and restricted_pairs.
 *
 * These four surface, on the schedule a convener actually gets, exactly the
 * balances a conversation about a real season kept computing by hand:
 * Friday-vs-Sunday spread, early/middle/late-third starts, how tightly a
 * division's own games cluster on a shared night, and how close a pair of
 * teams under an overlap/back-to-back restriction ever actually get.
 *
 * night_position_per_team and division_grouping deliberately do NOT reuse
 * SPSG_Schedule_Helper's timeline/night-position/hour-index helpers (those
 * ship in a separate, not-yet-merged branch) -- the calculator carries its
 * own small equivalents so this feature stands on its own. If that other
 * branch lands first, these could be de-duplicated against it, but this
 * test only pins the calculator's own behaviour either way.
 *
 * Standalone -- no WordPress needed, just the ABSPATH guard the class files
 * check for.
 *
 * Usage: php test-statistics-calculator-balance.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	// $domain is never read by this stub -- dropped entirely rather than
	// declared as an ignored formal parameter.
	function __( $text ) {
		return $text;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-statistics-calculator.php';
require_once SPSG_PLUGIN_PATH . 'includes/models/class-game.php';

$passed = 0;
$failed = 0;

function tsb_assert( $cond, $msg ) {
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

/**
 * @param string $id   Team id.
 * @param string $name Team name.
 * @return object
 */
function tsb_team( $id, $name ) {
	return (object) array( 'id' => $id, 'name' => $name );
}

/**
 * @param string $id   Division id ('' to exercise the name-fallback key).
 * @param string $name Division name.
 * @return object
 */
function tsb_division( $id, $name ) {
	return (object) array( 'id' => $id, 'name' => $name );
}

/**
 * @param string $date      "YYYY-MM-DD", a real Friday or Sunday.
 * @param string $time_slot "HH:MM".
 * @param object $home      Team object.
 * @param object $away      Team object.
 * @param object $division  Division object.
 * @return SPSG_Game
 */
function tsb_game( $date, $time_slot, $home, $away, $division ) {
	return new SPSG_Game(
		array(
			'id' => $home->id . '-' . $away->id . '-' . $date . '-' . $time_slot,
			'date' => $date,
			'time_slot' => $time_slot,
			'home_team' => $home,
			'away_team' => $away,
			'venue' => (object) array( 'id' => 'v1', 'name' => 'Black' ),
			'division' => $division,
		)
	);
}

// Fixture, all on one real Friday/Sunday pair (2026-09-25 is a Friday,
// 2026-09-27 the Sunday right after):
//
// Division A (real id 'div_a'), Friday: Ducks 18:45 vs TeamX, Pylons 21:45 vs
// TeamY -- two hours apart, a deliberately POOR grouping for the division,
// and a real (non-zero) gap for the Ducks/Pylons overlap_avoid pair, since
// Ducks and Pylons play DIFFERENT opponents rather than each other.
// Division B (id === '', exercising the name-fallback key), same Friday:
// Ice Bears 21:00 vs TeamZ, Kings 22:00 vs TeamW -- one hour apart, a
// deliberately GOOD grouping, and the back_to_back_avoid pair's real gap.
// Sunday: TeamX vs TeamY replay, purely so both have a Friday-and-Sunday
// split for day_balance_per_team to report.
$ducks = tsb_team( 'ducks', 'Ducks' );
$pylons = tsb_team( 'pylons', 'Pylons' );
$team_x = tsb_team( 'x', 'Team X' );
$team_y = tsb_team( 'y', 'Team Y' );
$ice_bears = tsb_team( 'ice', 'Ice Bears' );
$kings = tsb_team( 'kings', 'Kings' );
$team_z = tsb_team( 'z', 'Team Z' );
$team_w = tsb_team( 'w', 'Team W' );

$div_a = tsb_division( 'div_a', 'Division A' );
$div_b = tsb_division( '', 'Division B' ); // id intentionally empty.

$friday = '2026-09-25';
$sunday = '2026-09-27';

$schedule = array(
	tsb_game( $friday, '18:45', $ducks, $team_x, $div_a ),
	tsb_game( $friday, '21:45', $pylons, $team_y, $div_a ),
	tsb_game( $friday, '21:00', $ice_bears, $team_z, $div_b ),
	tsb_game( $friday, '22:00', $kings, $team_w, $div_b ),
	tsb_game( $sunday, '17:00', $team_x, $team_y, $div_a ),
);

$config = (object) array(
	'team_restrictions' => array(
		'overlap_avoid' => array(
			array( 'teams' => array( 'ducks', 'pylons' ), 'buffer_minutes' => 0 ),
		),
		'back_to_back_avoid' => array(
			array( 'teams' => array( 'ice', 'kings' ) ),
		),
	),
);

$calculator = new SPSG_Statistics_Calculator();
$stats = $calculator->calculate( $schedule, $config );

echo "=== day_balance_per_team ===\n\n";

tsb_assert(
	1 === ( $stats['day_balance_per_team']['ducks']['days']['friday'] ?? null )
		&& ! isset( $stats['day_balance_per_team']['ducks']['days']['sunday'] ),
	'Ducks: 1 Friday game, no Sunday entry at all'
);
tsb_assert(
	'Ducks' === ( $stats['day_balance_per_team']['ducks']['team_name'] ?? null ),
	'the per-team row carries the team display name'
);
tsb_assert(
	1 === ( $stats['day_balance_per_team']['x']['days']['friday'] ?? null )
		&& 1 === ( $stats['day_balance_per_team']['x']['days']['sunday'] ?? null ),
	'Team X: 1 Friday and 1 Sunday game counted'
);

echo "\n=== night_position_per_team ===\n\n";

// Friday's timeline is {18:45, 21:00, 21:45, 22:00} -- four distinct starts:
// index<1.0 (last=3,third=1.0) is early (index 0 only), index>2.0 is late
// (index 3 only), the rest (indexes 1,2) are mid.
tsb_assert(
	1 === $stats['night_position_per_team']['ducks']['early']
		&& 1 === $stats['night_position_per_team']['ducks']['first'],
	'Ducks (18:45, index 0 of 4): early and the very first start of the night'
);
tsb_assert(
	1 === $stats['night_position_per_team']['ice']['mid'],
	'Ice Bears (21:00, index 1 of 4): mid'
);
tsb_assert(
	1 === $stats['night_position_per_team']['pylons']['mid'],
	'Pylons (21:45, index 2 of 4): mid'
);
tsb_assert(
	1 === $stats['night_position_per_team']['kings']['late']
		&& 1 === $stats['night_position_per_team']['kings']['last'],
	'Kings (22:00, index 3 of 4): late and the very last start of the night'
);

echo "\n=== division_grouping ===\n\n";

tsb_assert(
	isset( $stats['division_grouping']['per_division']['Division B'] ),
	'Division B (configured with an empty id) is keyed by its NAME, not folded into one blank-id bucket'
);
tsb_assert(
	isset( $stats['division_grouping']['per_division']['div_a'] )
		&& 'Division A' === $stats['division_grouping']['per_division']['div_a']['name'],
	'Division A is keyed by its real id and carries its own name'
);
tsb_assert(
	100.0 === $stats['division_grouping']['per_division']['Division B']['percent'],
	"Division B's games (21:00 and 22:00, one hour apart) are 100% grouped"
);
tsb_assert(
	0.0 === $stats['division_grouping']['per_division']['div_a']['percent'],
	"Division A's Friday games (18:45 and 21:45, three hours apart) are 0% grouped -- got {$stats['division_grouping']['per_division']['div_a']['percent']}%"
);
tsb_assert(
	null !== $stats['division_grouping']['overall_percent'],
	'overall_percent is computed (not null) when there is at least one multi-game division-night'
);

$low_grouping_divisions = array();
foreach ( $stats['imbalances'] as $issue ) {
	if ( 'division_grouping_low' === $issue['type'] ) {
		$low_grouping_divisions[] = $issue['details']['division_id'];
	}
}
tsb_assert( in_array( 'div_a', $low_grouping_divisions, true ), "Division A's 0% grouping is flagged as an imbalance" );
tsb_assert( ! in_array( 'Division B', $low_grouping_divisions, true ), "Division B's 100% grouping is NOT flagged" );

echo "\n=== restricted_pairs ===\n\n";

tsb_assert( 2 === count( $stats['restricted_pairs'] ), 'one report per configured restricted pair (overlap_avoid + back_to_back_avoid)' );

$ducks_pylons = null;
$ice_kings = null;
foreach ( $stats['restricted_pairs'] as $pair ) {
	if ( in_array( 'Ducks', $pair['teams'], true ) ) {
		$ducks_pylons = $pair;
	}
	if ( in_array( 'Ice Bears', $pair['teams'], true ) ) {
		$ice_kings = $pair;
	}
}

tsb_assert( null !== $ducks_pylons, 'Ducks/Pylons pair (overlap_avoid) is present' );
tsb_assert(
	array( 'Ducks', 'Pylons' ) === $ducks_pylons['teams'] || array( 'Pylons', 'Ducks' ) === $ducks_pylons['teams'],
	'the pair names both teams'
);
tsb_assert( 1 === ( $ducks_pylons['shared_nights'] ?? null ), 'Ducks and Pylons share exactly 1 night (Friday) -- neither plays the other, each has their own game' );
tsb_assert( 180 === ( $ducks_pylons['min_gap_minutes'] ?? null ), 'Ducks (18:45) and Pylons (21:45) are 180 minutes apart' );

tsb_assert( null !== $ice_kings, 'Ice Bears/Kings pair (back_to_back_avoid) is present' );
tsb_assert( 1 === ( $ice_kings['shared_nights'] ?? null ), 'Ice Bears and Kings share exactly 1 night (Friday)' );
tsb_assert( 60 === ( $ice_kings['min_gap_minutes'] ?? null ), 'Ice Bears (21:00) and Kings (22:00) are 60 minutes apart' );

echo "\n=== restricted_pairs: no config, or a config with no restrictions ===\n\n";

$calc2 = new SPSG_Statistics_Calculator();
$stats_no_config = $calc2->calculate( $schedule );
tsb_assert( array() === $stats_no_config['restricted_pairs'], 'restricted_pairs is empty when calculate() is called with no config at all' );

$config_no_restrictions = (object) array( 'team_restrictions' => array() );
$stats_no_restrictions = $calc2->calculate( $schedule, $config_no_restrictions );
tsb_assert( array() === $stats_no_restrictions['restricted_pairs'], 'restricted_pairs is empty when the config declares no restrictions' );

echo "\n=== empty schedule ===\n\n";

$empty_stats = ( new SPSG_Statistics_Calculator() )->calculate( array() );
tsb_assert( array() === $empty_stats['day_balance_per_team'], 'day_balance_per_team is empty for an empty schedule' );
tsb_assert( array() === $empty_stats['night_position_per_team'], 'night_position_per_team is empty for an empty schedule' );
tsb_assert( null === $empty_stats['division_grouping']['overall_percent'], 'division_grouping.overall_percent is null (not 0 or NAN) for an empty schedule' );
tsb_assert( array() === $empty_stats['restricted_pairs'], 'restricted_pairs is empty for an empty schedule' );

echo "\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
