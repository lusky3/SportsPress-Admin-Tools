<?php
/**
 * Inter-division matchups must carry {id,name} team objects even when the
 * configuration stores teams as plain strings (which the admin sanitizer does).
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

if ( ! function_exists( '__' ) ) {
	function __( $s, $d = null ) { return $s; }
}
if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $default; }
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); }
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
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
require_once SPSG_PLUGIN_PATH . 'includes/class-placeholder-team-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-matchup-generator.php';
require_once SPSG_PLUGIN_PATH . 'includes/constraints/class-team-restriction-constraint.php';

$passed = 0;
$failed = 0;
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

$config = new SPSG_Schedule_Configuration(
	array(
		'season_start'         => '2026-09-04',
		'season_end'           => '2027-03-28',
		'games_per_team'       => 8,
		'matchup_style'        => 'single_round_robin',
		'playing_days'         => array( 'friday' ),
		'time_slots'           => array( 'friday' => array( '19:00', '20:00' ) ),
		'divisions'            => array(
			array( 'id' => 'divA', 'name' => 'A', 'teams' => array( 'Lions', 'Tigers', 'Bears', 'Wolves' ) ),
			array( 'id' => 'divB', 'name' => 'B', 'teams' => array( 'Hawks', 'Eagles', 'Owls', 'Crows' ) ),
		),
		'inter_division_games' => array( 'divA:divB' => 4 ),
		'venues'               => array( array( 'id' => 'v1', 'name' => 'Arena' ) ),
		'team_restrictions'    => array(
			'custom' => array(
				array( 'type' => 'day_restrictions', 'teams' => array( 'Lions' ), 'allowed_days' => array( 'sunday' ) ),
			),
		),
	)
);

echo "=== Inter-division matchups with string teams ===\n\n";

$matchups = ( new SPSG_Matchup_Generator() )->generate( $config );
$inter    = array_values( array_filter( $matchups, function ( $m ) { return ! empty( $m['is_inter_division'] ); } ) );
$intra    = array_values( array_filter( $matchups, function ( $m ) { return empty( $m['is_inter_division'] ); } ) );

_assert( 4 === count( $inter ), 'four inter-division matchups generated (' . count( $inter ) . ')' );

$all_objects = true;
foreach ( $matchups as $m ) {
	foreach ( array( 'team_a', 'team_b', 'home_team', 'away_team' ) as $k ) {
		if ( ! is_object( $m[ $k ] ) || ! isset( $m[ $k ]->id, $m[ $k ]->name ) ) {
			$all_objects = false;
		}
	}
}
_assert( $all_objects, 'every matchup (intra AND inter) carries {id,name} team objects' );
$known_ids = array( 'Lions', 'Tigers', 'Bears', 'Wolves', 'Hawks', 'Eagles', 'Owls', 'Crows' );
$ids_ok    = true;
foreach ( $matchups as $m ) {
	foreach ( array( 'home_team', 'away_team' ) as $k ) {
		if ( ! is_object( $m[ $k ] ) || ! in_array( $m[ $k ]->id ?? null, $known_ids, true ) || ( $m[ $k ]->id ?? null ) !== ( $m[ $k ]->name ?? null ) ) {
			$ids_ok = false;
		}
	}
}
_assert( $ids_ok, 'string teams keep their name as both id and name' );

// A hard day restriction on a string-named team must fire on an inter-division game too.
$lions_inter = null;
foreach ( $inter as $m ) {
	if ( 'Lions' === $m['home_team']->id || 'Lions' === $m['away_team']->id ) {
		$lions_inter = $m;
		break;
	}
}
_assert( null !== $lions_inter, 'Lions appear in at least one inter-division matchup' );

if ( $lions_inter ) {
	$game = (object) array(
		'id'        => 'g1',
		'date'      => '2026-09-04', // a Friday
		'day'       => 'friday',
		'time_slot' => '19:00',
		'home_team' => $lions_inter['home_team'],
		'away_team' => $lions_inter['away_team'],
		'venue'     => (object) array( 'id' => 'v1', 'name' => 'Arena' ),
		'division'  => $lions_inter['division'],
	);
	$result = ( new SPSG_Team_Restriction_Constraint() )->validate( $game, array(), $config );
	_assert( is_wp_error( $result ) && 'day_restriction' === $result->get_error_code(), 'day restriction rejects the Friday inter-division game for Lions' );
}

// Defence in depth: the abstract helper tolerates a bare string.
$probe = new class() extends SPSG_Team_Restriction_Constraint {
	public function id_of( $team ) { return $this->get_team_id( $team ); }
};
_assert( 'Lions' === $probe->id_of( 'Lions' ), 'get_team_id() returns a bare string unchanged' );
_assert( 'x1' === $probe->id_of( array( 'id' => 'x1', 'name' => 'X' ) ), 'get_team_id() reads array id' );
_assert( 'x2' === $probe->id_of( (object) array( 'id' => 'x2' ) ), 'get_team_id() reads object id' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
