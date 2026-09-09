<?php
/**
 * Test: a division whose real roster already meets (or exceeds) the generic
 * teams target, but is itself an odd number, still gets exactly one
 * placeholder team injected -- matching the admin UI's own live preview.
 *
 * admin-ui.js's calculateGenericTeams() (the "Will add N generic teams..."
 * summary under Divisions & Teams) computes:
 *   needed = max(0, target - currentTeams)
 *   if ((currentTeams + needed) % 2 !== 0) needed++
 * -- i.e. it always rounds a division up to an EVEN team count, even when
 * the real roster already meets or exceeds the configured target.
 * SPSG_Placeholder_Team_Manager::generate_placeholder_names() (what
 * SPSG_Schedule_Engine::generate_matchups() actually calls during real
 * generation, via inject_into_config()) only did `target - count(teams)`
 * and returned nothing at all once that met zero -- so a division with,
 * say, 7 real teams against a target of 6 got the UI's promised "1 generic
 * team needed" but the ACTUAL generated schedule never added one. Total
 * teams across the season stayed odd, and -- because every game adds 1 to
 * exactly two teams' game counts, so the sum of all teams' counts must be
 * even -- exactly one team then played one game short of games_per_team.
 * Confirmed 2026-09-09 against the real "winter_2026-28_v1" config on
 * Tikal: 31 teams (Division 4 at 7, odd) x games_per_team 17 (odd) is
 * mathematically impossible for every team to satisfy; regenerating
 * against the one duplicate save where Division 4 had 8 teams (32 total,
 * even) gave every team exactly 17.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';

$passed = 0;
$failed = 0;

function ptpr_assert( $cond, $msg ) {
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

echo "=== Testing generate_placeholder_names() parity rounding ===\n\n";

$seven_real_teams = array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' );

$names = SPSG_Placeholder_Team_Manager::generate_placeholder_names( $seven_real_teams, 6, 'Team', 'Division 4' );
ptpr_assert(
	1 === count( $names ),
	'7 real teams against a target of 6 (already met): still gets exactly 1 placeholder, to make the division even (got: ' . count( $names ) . ')'
);

$six_real_teams = array( 'A', 'B', 'C', 'D', 'E', 'F' );
$names_even = SPSG_Placeholder_Team_Manager::generate_placeholder_names( $six_real_teams, 6, 'Team', 'Division 1' );
ptpr_assert(
	0 === count( $names_even ),
	'6 real teams against a target of 6 (already even): no placeholder needed (got: ' . count( $names_even ) . ')'
);

$five_real_teams = array( 'A', 'B', 'C', 'D', 'E' );
$names_below = SPSG_Placeholder_Team_Manager::generate_placeholder_names( $five_real_teams, 6, 'Team', 'Division 2' );
ptpr_assert(
	1 === count( $names_below ),
	'5 real teams against a target of 6: 1 placeholder reaches the target AND lands on an even 6 (got: ' . count( $names_below ) . ')'
);

$four_real_teams = array( 'A', 'B', 'C', 'D' );
$names_below_odd_gap = SPSG_Placeholder_Team_Manager::generate_placeholder_names( $four_real_teams, 7, 'Team', 'Division 3' );
ptpr_assert(
	4 === count( $names_below_odd_gap ),
	'4 real teams against an odd target of 7: the raw gap (3) would land on an odd 7, so one more placeholder is added to land on 8 (got: ' . count( $names_below_odd_gap ) . ')'
);

echo "\n=== Testing inject_into_config() against the real Division 4 shape (7 teams, target 6) ===\n\n";

$config = new SPSG_Schedule_Configuration(
	array(
		'divisions' => array(
			array( 'name' => 'Division 4', 'teams' => $seven_real_teams ),
		),
		'generic_teams' => array(
			'enabled' => true,
			'per_division' => 6,
			'prefix' => 'Team',
		),
	)
);

$injection_info = SPSG_Placeholder_Team_Manager::inject_into_config( $config );

ptpr_assert(
	1 === count( $injection_info ),
	'inject_into_config() reports one division received placeholders'
);
ptpr_assert(
	8 === count( $config->divisions[0]['teams'] ),
	'Division 4 ends up with 8 teams total (7 real + 1 placeholder), an even number (got: ' . count( $config->divisions[0]['teams'] ) . ')'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
