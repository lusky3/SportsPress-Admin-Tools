<?php
/**
 * Standalone tests for the notice gateway's query scoping: the automatic
 * queue helpers only see automatic rows, and for_player() reads a player's
 * whole history.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';

/** Recording stand-in for $wpdb. */
class Splm_Scope_Wpdb {
	public $prefix  = 'wp_';
	public $queries = array();

	public function prepare( $sql, ...$args ) {
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return 's' === $m[0][1] ? "'" . $v . "'" : (string) (int) $v;
			},
			$sql
		);
	}

	public function get_var( $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES' ) ) {
			return $this->prefix . 'splm_discipline_notice';
		}
		$this->queries[] = $sql;
		return null;
	}

	public function get_row( $sql ) {
		$this->queries[] = $sql;
		return null;
	}

	public function get_results( $sql ) {
		$this->queries[] = $sql;
		return array();
	}
}

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

$db = 'SPLM_Discipline_Notice_Database';

echo "\n=== automatic-queue helpers are scoped to source = auto ===\n\n";
$wpdb = new Splm_Scope_Wpdb();
$db::latest_unreleased( 7, 3 );
$sql = $wpdb->queries[0];
assert_test( false !== strpos( $sql, "source = 'auto'" ), 'latest_unreleased filters source = auto' );
assert_test( false !== strpos( $sql, 'player_id = 7' ) && false !== strpos( $sql, 'season_id = 3' ), 'latest_unreleased keeps player and season' );

$wpdb = new Splm_Scope_Wpdb();
$db::has_suspension_notice( 7, 3 );
$sql = $wpdb->queries[0];
assert_test( false !== strpos( $sql, "source = 'auto'" ), 'has_suspension_notice filters source = auto' );
assert_test( false !== strpos( $sql, 'player_id = 7' ) && false !== strpos( $sql, 'season_id = 3' ), 'has_suspension_notice keeps player and season' );

echo "\n=== for_player() ===\n\n";
$wpdb = new Splm_Scope_Wpdb();
$out  = $db::for_player( 7 );
$sql  = $wpdb->queries[0];
assert_test( array() === $out, 'for_player returns an array' );
assert_test( false !== strpos( $sql, 'player_id = 7' ), 'for_player filters the player' );
assert_test( false !== strpos( $sql, 'ORDER BY id DESC' ) && false !== strpos( $sql, 'LIMIT 200' ), 'for_player orders newest first and caps at 200' );
assert_test( false !== strpos( $sql, "status <> 'baseline'" ), 'baseline excluded by default' );
assert_test( false === strpos( $sql, 'season_id' ), 'for_player spans every season' );

$wpdb = new Splm_Scope_Wpdb();
$db::for_player( 7, true );
assert_test( false === strpos( $wpdb->queries[0], 'baseline' ), 'baseline included on request' );

$wpdb = new Splm_Scope_Wpdb();
assert_test( array() === $db::for_player( 0 ) && array() === $wpdb->queries, 'player 0 returns [] and runs no query' );
assert_test( array() === $db::for_player( -4 ) && array() === $wpdb->queries, 'negative player returns [] and runs no query' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
