<?php
/**
 * (A2) custom style + inter-division games must total games_per_team, and
 * (A4) every team's inter-division count must stay within floor/ceil of
 * total/size — the range SPSG_Schedule_Engine::validate_matchups() demands.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );
define( 'SPSG_VERSION', '1.0.0' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) { define( 'HOUR_IN_SECONDS', 3600 ); }

if ( ! function_exists( '__' ) ) { function __( $s, $d = null ) { return $s; } }
if ( ! function_exists( 'wp_rand' ) ) { function wp_rand( $min = 0, $max = 0 ) { return $max ? rand( $min, $max ) : rand(); } }
if ( ! function_exists( 'get_option' ) ) { function get_option( $key, $default = false ) { return $default; } }
if ( ! function_exists( 'wp_parse_args' ) ) { function wp_parse_args( $args, $defaults = array() ) { return array_merge( $defaults, (array) $args ); } }
if ( ! function_exists( 'wp_timezone_string' ) ) { function wp_timezone_string() { return 'America/Toronto'; } }
if ( ! function_exists( 'wp_timezone' ) ) { function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( $type = 'mysql' ) { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'get_current_user_id' ) ) { function get_current_user_id() { return 1; } }
if ( ! function_exists( 'do_action' ) ) { function do_action( $tag ) {} }
$GLOBALS['spsg_test_transients'] = array();
$GLOBALS['spsg_test_cache']      = array();
if ( ! function_exists( 'set_transient' ) ) { function set_transient( $k, $v, $t = 0 ) { $GLOBALS['spsg_test_transients'][ $k ] = $v; return true; } }
if ( ! function_exists( 'get_transient' ) ) { function get_transient( $k ) { return $GLOBALS['spsg_test_transients'][ $k ] ?? false; } }
if ( ! function_exists( 'delete_transient' ) ) { function delete_transient( $k ) { unset( $GLOBALS['spsg_test_transients'][ $k ] ); return true; } }
if ( ! function_exists( 'wp_cache_set' ) ) { function wp_cache_set( $k, $v, $g = '', $t = 0 ) { $GLOBALS['spsg_test_cache'][ $g ][ $k ] = $v; return true; } }
if ( ! function_exists( 'wp_cache_get' ) ) { function wp_cache_get( $k, $g = '' ) { return $GLOBALS['spsg_test_cache'][ $g ][ $k ] ?? false; } }
if ( ! function_exists( 'wp_cache_delete' ) ) { function wp_cache_delete( $k, $g = '' ) { unset( $GLOBALS['spsg_test_cache'][ $g ][ $k ] ); return true; } }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) { $this->code = $c; $this->message = $m; $this->data = $d; }
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_messages() { return array( $this->message ); }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) { function is_wp_error( $t ) { return $t instanceof WP_Error; } }

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
function _assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}
function ids_teams( $prefix, $n ) {
	$out = array();
	for ( $i = 1; $i <= $n; $i++ ) { $out[] = array( 'id' => $prefix . $i, 'name' => strtoupper( $prefix ) . $i ); }
	return $out;
}
function ids_config( $style, $gpt, $na, $nb, $inter ) {
	return new SPSG_Schedule_Configuration(
		array(
			'season_start'         => '2026-09-04',
			'season_end'           => '2027-03-28',
			'games_per_team'       => $gpt,
			'matchup_style'        => $style,
			'playing_days'         => array( 'friday', 'sunday' ),
			'time_slots'           => array( 'friday' => array( '18:00', '19:00', '20:00', '21:00' ), 'sunday' => array( '14:00', '15:00', '16:00', '17:00' ) ),
			'divisions'            => array(
				array( 'id' => 'divA', 'name' => 'A', 'teams' => ids_teams( 'a', $na ) ),
				array( 'id' => 'divB', 'name' => 'B', 'teams' => ids_teams( 'b', $nb ) ),
			),
			'inter_division_games' => array( 'divA:divB' => $inter ),
			'venues'               => array( array( 'id' => 'v1', 'name' => 'Arena One' ), array( 'id' => 'v2', 'name' => 'Arena Two' ) ),
			'match_length'         => 60,
			'distribution_rules'   => array( 'home_away_balance' => true ),
			'division_grouping'    => array( 'enabled' => true, 'priority' => 5 ),
			'timezone'             => 'America/Toronto',
		)
	);
}
function team_counts( $matchups, $only_inter ) {
	$counts = array();
	foreach ( $matchups as $m ) {
		if ( $only_inter && empty( $m['is_inter_division'] ) ) { continue; }
		foreach ( array( $m['home_team'], $m['away_team'] ) as $t ) {
			$id = SPSG_Schedule_Helper::extract_id( $t );
			$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
		}
	}
	return $counts;
}

echo "=== A2: custom style + inter-division totals games_per_team ===\n\n";

$config   = ids_config( 'custom', 6, 4, 4, 4 );
$validate = ( new SPSG_Configuration_Validator( $config ) )->validate();
_assert( true === $validate, 'validator accepts custom + inter-division' );

$matchups = ( new SPSG_Matchup_Generator() )->generate( $config );
$totals   = team_counts( $matchups, false );
_assert( 6 === max( $totals ), 'no team exceeds games_per_team (max ' . max( $totals ) . ')' );
_assert( min( $totals ) >= 5, 'no team is more than one game under games_per_team (min ' . min( $totals ) . ')' );

$result = ( new SPSG_Schedule_Engine() )->generate_schedule( $config );
_assert( ! is_wp_error( $result ), 'engine generates the custom + inter-division season' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );

echo "\n=== A2: odd division sizes still hit games_per_team per team ===\n\n";

$odd_cases = array(
	array( 4, 2, 3, 1 ),
	array( 12, 3, 3, 2 ),
	array( 10, 5, 4, 6 ),
);
foreach ( $odd_cases as $case ) {
	list( $gpt, $na, $nb, $inter ) = $case;
	$label  = "gpt=$gpt na=$na nb=$nb inter=$inter";
	$config = ids_config( 'custom', $gpt, $na, $nb, $inter );
	_assert( true === ( new SPSG_Configuration_Validator( $config ) )->validate(), "validator accepts custom + inter-division ($label)" );

	$matchups = ( new SPSG_Matchup_Generator() )->generate( $config );
	$totals   = team_counts( $matchups, false );
	_assert( max( $totals ) <= $gpt, "no team exceeds games_per_team ($label, max " . max( $totals ) . ')' );
	_assert( min( $totals ) >= $gpt - 1, "no team is more than one game under games_per_team ($label, min " . min( $totals ) . ')' );

	if ( 12 === $gpt && 3 === $na && 3 === $nb && 2 === $inter ) {
		$result = ( new SPSG_Schedule_Engine() )->generate_schedule( $config );
		_assert( ! is_wp_error( $result ), "engine generates the custom + inter-division season ($label)" . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );
	}
}

echo "\n=== A2: sweep of custom + inter-division sizing across validator-accepted configs ===\n\n";

$accepted   = 0;
$overcounts = 0;
$deficits   = 0;
for ( $na = 2; $na <= 6; $na++ ) {
	for ( $nb = 2; $nb <= 6; $nb++ ) {
		for ( $gpt = 4; $gpt <= 10; $gpt++ ) {
			for ( $inter = 1; $inter <= 8; $inter++ ) {
				$config = ids_config( 'custom', $gpt, $na, $nb, $inter );
				if ( true !== ( new SPSG_Configuration_Validator( $config ) )->validate() ) {
					continue;
				}
				$accepted++;

				$matchups = ( new SPSG_Matchup_Generator() )->generate( $config );
				$totals   = team_counts( $matchups, false );
				foreach ( $totals as $count ) {
					if ( $count > $gpt ) {
						$overcounts++;
					} elseif ( $count < $gpt - 1 ) {
						$deficits++;
					}
				}
			}
		}
	}
}
_assert( 0 === $overcounts, "no team ever exceeds games_per_team across $accepted accepted configs ($overcounts overcounts)" );
_assert( 0 === $deficits, "no team ever falls more than one game short across $accepted accepted configs ($deficits deficits)" );

echo "\n=== A4: inter-division per-team counts stay within floor/ceil(total/size) ===\n\n";

$violations = 0;
$cases      = 0;
$first      = '';
for ( $na = 2; $na <= 8; $na++ ) {
	for ( $nb = 2; $nb <= 8; $nb++ ) {
		for ( $total = 1; $total <= 40; $total++ ) {
			$cases++;
			$m      = ( new SPSG_Matchup_Generator() )->generate( ids_config( 'single_round_robin', 99, $na, $nb, $total ) );
			$counts = team_counts( $m, true );
			$bad    = false;
			foreach ( array( 'a' => $na, 'b' => $nb ) as $p => $n ) {
				$lo = (int) floor( $total / $n );
				$hi = (int) ceil( $total / $n );
				for ( $i = 1; $i <= $n; $i++ ) {
					$v = $counts[ $p . $i ] ?? 0;
					if ( $v < $lo || $v > $hi ) { $bad = true; }
				}
			}
			if ( $bad ) {
				$violations++;
				if ( '' === $first ) { $first = "na=$na nb=$nb total=$total " . json_encode( $counts ); }
			}
		}
	}
}
_assert( 0 === $violations, "every team within floor/ceil range across $cases size/total combinations ($violations violations" . ( $first ? "; first: $first" : '' ) . ')' );

// The case the audit reproduced end-to-end.
$result = ( new SPSG_Schedule_Engine() )->generate_schedule( ids_config( 'single_round_robin', 5, 3, 3, 6 ) );
_assert( ! is_wp_error( $result ), '3v3 teams with 6 inter-division games generates' . ( is_wp_error( $result ) ? ' (' . $result->get_error_code() . ')' : '' ) );

// Inter-division pairs still spread across distinct pairings when balance allows.
$m     = ( new SPSG_Matchup_Generator() )->generate( ids_config( 'single_round_robin', 99, 4, 4, 8 ) );
$pairs = array();
foreach ( $m as $x ) {
	if ( empty( $x['is_inter_division'] ) ) { continue; }
	$a = SPSG_Schedule_Helper::extract_id( $x['home_team'] );
	$b = SPSG_Schedule_Helper::extract_id( $x['away_team'] );
	$pairs[ $a < $b ? "$a:$b" : "$b:$a" ] = true;
}
_assert( 8 === count( $pairs ), '8 inter-division games over 4v4 use 8 distinct pairings (' . count( $pairs ) . ')' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
