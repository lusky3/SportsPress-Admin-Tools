<?php
/**
 * Test: SPSG_Distribution_Constraint's weighted() helper scales
 * DAY_BALANCE_COST_PER_GAME_DEVIATION / TIME_OF_NIGHT_COST_PER_GAME /
 * EXTREME_SLOT_COST_PER_GAME by their Advanced-settings multipliers
 * (default 1.0, i.e. unchanged). Also covers the `time_slot_balance` gating
 * fix: this flag was saved, sanitized, and exposed over REST, but the
 * time-of-night cost constants applied unconditionally regardless of it --
 * calculate_time_slot_distribution_cost() must now return 0.0 when it's off.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

$GLOBALS['dcw_test_options'] = array();
function get_option( $name, $default = false ) {
	return $GLOBALS['dcw_test_options'][ $name ] ?? $default;
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $t ) { return $t instanceof WP_Error; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/abstract-constraint.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-distribution-constraint.php';

$passed = 0;
$failed = 0;

function dcw_assert( $cond, $msg ) {
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

$constraint = new SPSG_Distribution_Constraint();
$weighted   = new ReflectionMethod( 'SPSG_Distribution_Constraint', 'weighted' );
$weighted->setAccessible( true );

echo "=== Testing SPSG_Distribution_Constraint::weighted() ===\n\n";

$GLOBALS['dcw_test_options'] = array();
dcw_assert(
	50.0 === $weighted->invoke( $constraint, 50.0, 'day_balance' ),
	'option unset: returns the base constant unchanged (default multiplier 1.0)'
);

$GLOBALS['dcw_test_options'] = array( 'spsg_weight_day_balance' => 0.5 );
dcw_assert(
	25.0 === $weighted->invoke( $constraint, 50.0, 'day_balance' ),
	'a 0.5 multiplier halves the base constant'
);

echo "\n=== Testing the time_slot_balance gating fix ===\n\n";

$game = (object) array(
	'date'      => '2026-10-16',
	'day'       => 'friday',
	'time_slot' => '19:00',
	'home_team' => (object) array( 'id' => 't1', 'name' => 't1' ),
	'away_team' => (object) array( 'id' => 't2', 'name' => 't2' ),
);

$config_disabled = (object) array(
	'playing_days'       => array( 'friday' ),
	'time_slots'         => array( 'friday' => array( '18:00', '19:00', '20:00' ) ),
	'match_length'       => 60,
	'distribution_rules' => array(
		'day_balance'       => array( 'friday' => 1.0 ),
		'time_slot_balance' => false,
	),
);
$config_enabled = (object) array(
	'playing_days'       => $config_disabled->playing_days,
	'time_slots'         => $config_disabled->time_slots,
	'match_length'       => 60,
	'distribution_rules' => array(
		'day_balance'       => array( 'friday' => 1.0 ),
		'time_slot_balance' => true,
	),
);
$config_unset = (object) array(
	'playing_days'       => $config_disabled->playing_days,
	'time_slots'         => $config_disabled->time_slots,
	'match_length'       => 60,
	'distribution_rules' => array( 'day_balance' => array( 'friday' => 1.0 ) ),
);

// A lopsided schedule so the enabled case has a non-zero time-of-night cost
// to prove the disabled case is actually skipping it, not coincidentally zero.
$schedule = array(
	(object) array(
		'date' => '2026-10-09', 'day' => 'friday', 'time_slot' => '20:00',
		'home_team' => (object) array( 'id' => 't1', 'name' => 't1' ),
		'away_team' => (object) array( 'id' => 't3', 'name' => 't3' ),
	),
);

$cost_disabled = $constraint->get_violation_cost( $game, $schedule, $config_disabled );
$cost_enabled  = $constraint->get_violation_cost( $game, $schedule, $config_enabled );
$cost_unset    = $constraint->get_violation_cost( $game, $schedule, $config_unset );

dcw_assert(
	$cost_enabled > $cost_disabled,
	sprintf( 'time_slot_balance=false costs less than =true for the same lopsided schedule (enabled %.1f, disabled %.1f)', $cost_enabled, $cost_disabled )
);
dcw_assert(
	$cost_unset === $cost_enabled,
	'time_slot_balance left unset falls back to the same behaviour as explicitly true (matches the sanitizer\'s own ?? true default)'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
