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

	public $col_result = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
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

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return $this->col_result;
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

echo "\n=== replaced_ids() ===\n\n";
$wpdb             = new Splm_Scope_Wpdb();
$wpdb->col_result = array( '4', '9' );
$out              = $db::replaced_ids( array( 4, 9, '9', 12, 0, -3, 'x' ) );
$sql              = $wpdb->queries[0];
assert_test( array( 4, 9 ) === $out, 'replaced_ids maps the parent ids to ints' );
assert_test( 1 === count( $wpdb->queries ), 'replaced_ids runs a single query' );
assert_test( false !== strpos( $sql, 'parent_id IN (4,9,12)' ), 'ids are de-duplicated, positive-only, one placeholder each' );
assert_test( false !== strpos( $sql, "status <> 'discarded'" ), 'discarded children do not count' );
assert_test( false !== strpos( $sql, 'SELECT DISTINCT parent_id' ), 'selects distinct parent ids' );
$wpdb = new Splm_Scope_Wpdb();
assert_test( array() === $db::replaced_ids( array() ) && array() === $wpdb->queries, 'empty input returns [] with no query' );
assert_test( array() === $db::replaced_ids( array( 0, -1, 'abc' ) ) && array() === $wpdb->queries, 'no valid ids returns [] with no query' );
$wpdb->col_result = null;
assert_test( array() === $db::replaced_ids( array( 5 ) ), 'a non-array result is treated as none' );

echo "\n=== children_of() ===\n\n";
$wpdb = new Splm_Scope_Wpdb();
$out  = $db::children_of( 9 );
$sql  = $wpdb->queries[0];
assert_test( array() === $out, 'children_of returns an array' );
assert_test( false !== strpos( $sql, 'parent_id = 9' ), 'children_of filters on the parent id' );
assert_test( false !== strpos( $sql, "status <> 'discarded'" ), 'children_of skips discarded rows' );
assert_test( false !== strpos( $sql, 'ORDER BY id ASC' ) && false !== strpos( $sql, 'LIMIT 20' ), 'children_of orders by id and caps at 20' );
assert_test( 1 === count( $wpdb->queries ), 'children_of runs a single query' );
$wpdb = new Splm_Scope_Wpdb();
assert_test( array() === $db::children_of( 0 ) && array() === $wpdb->queries, 'parent 0 returns [] and runs no query' );
assert_test( array() === $db::children_of( -2 ) && array() === $wpdb->queries, 'negative parent returns [] and runs no query' );

echo "\nPassed: {$passed}  Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
