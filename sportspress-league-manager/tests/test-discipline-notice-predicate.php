<?php
/**
 * Standalone tests for the notice table and the re-fire predicate.
 *
 * The clock assertions matter more than they look: the nearest precedent in
 * this plugin (SPLM_Discipline_Database::acknowledge) writes current_time(),
 * which is site-local. Every timestamp on this table is UTC, and these
 * assertions run under a non-UTC timezone so a reach for local time fails.
 */

define( 'ABSPATH', __DIR__ );

// A deliberately non-UTC site timezone. If any production code reaches for
// site-local time instead of UTC, these assertions are what catches it.
date_default_timezone_set( 'America/Toronto' );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Notice_DB_Test_State {
	/** Rows the fake wpdb hands back, keyed by the first bound parameter. */
	public $rows = array();

	/** Every insert() call's data array, in order. */
	public $inserts = array();

	/** Every update() call, as array( id_where, data ). */
	public $updates = array();
}

function splm_notice_db_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Notice_DB_Test_State();
	}
	return $state;
}

class Fake_WPDB {
	public $prefix          = 'wp_';
	public $insert_succeeds = true;
	public $insert_id       = 0;
	private $last_args      = array();

	public function prepare( $query, ...$args ) {
		$this->last_args = $args;
		return $query;
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function get_row() {
		$key = $this->last_args[0] ?? null;
		return isset( splm_notice_db_test_state()->rows[ $key ] ) ? splm_notice_db_test_state()->rows[ $key ] : null;
	}

	public function get_results() {
		return array_values( splm_notice_db_test_state()->rows );
	}

	public function get_var() {
		return 'wp_splm_discipline_notice';
	}

	// $table is core's 1st positional arg and is never consulted here, so it is
	// skipped positionally via func_get_arg() rather than declared as an ignored
	// formal parameter — the convention this repo's waitlist tests established
	// to avoid PHPMD.UnusedFormalParameter without a suppression comment.
	public function insert() { // phpcs:ignore
		splm_notice_db_test_state()->inserts[] = func_get_arg( 1 );
		if ( ! $this->insert_succeeds ) {
			return false;
		}
		$this->insert_id = 701;
		return 1;
	}

	public function update() { // phpcs:ignore
		splm_notice_db_test_state()->updates[] = array( func_get_arg( 2 ), func_get_arg( 1 ) );
		return 1;
	}
}

global $wpdb;
$wpdb = new Fake_WPDB();

function absint( $v ) {
	return abs( (int) $v );
}

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

$db = 'SPLM_Discipline_Notice_Database';

echo "\n=== the clock is UTC, not site-local ===\n\n";

$now = $db::now();

assert_test(
	1 === preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $now ),
	'now() returns a MySQL datetime string'
);
assert_test( $now === gmdate( 'Y-m-d H:i:s' ), 'now() is UTC' );
assert_test(
	$now !== date( 'Y-m-d H:i:s' ),
	'now() differs from local time under a non-UTC timezone, proving it is not date() or current_time()'
);

echo "\n=== statuses ===\n\n";

$statuses = array(
	$db::STATUS_BASELINE,
	$db::STATUS_PENDING,
	$db::STATUS_SENT,
	$db::STATUS_FAILED,
	$db::STATUS_DISCARDED,
	$db::STATUS_SERVED,
);

assert_test(
	array( 'baseline', 'pending', 'sent', 'failed', 'discarded', 'served' ) === $statuses,
	'all six statuses are defined with their documented names'
);
assert_test( 6 === count( array_unique( $statuses ) ), 'no two statuses collide' );

echo "\n=== table name ===\n\n";

assert_test( 'wp_splm_discipline_notice' === $db::table_name(), 'table name is prefixed' );

echo "\n=== insert() stamps its own UTC created_at ===\n\n";

splm_notice_db_test_state()->inserts = array();

$id = $db::insert(
	array(
		'player_id'     => 12,
		'season_id'     => 34,
		'tier_key'      => 'season-critical',
		'ack_key'       => 'season-critical',
		'severity'       => 'critical',
		'consequence'    => 'suspend',
		'games'          => 1,
		'value_at_fire'  => 8,
		'season_at_fire' => 18,
		'status'         => $db::STATUS_PENDING,
	)
);

$written = splm_notice_db_test_state()->inserts[0];

assert_test( 701 === $id, 'insert() returns the new row id' );
assert_test( isset( $written['created_at'] ), 'insert() stamps created_at rather than trusting a column default' );
assert_test( $written['created_at'] === gmdate( 'Y-m-d H:i:s' ), 'created_at is UTC' );
assert_test( 12 === $written['player_id'], 'the caller fields survive' );
assert_test( 'pending' === $written['status'], 'the status survives' );
assert_test(
	8 === $written['value_at_fire'] && 18 === $written['season_at_fire'],
	'the two value columns are stored separately: value_at_fire is the figure that crossed the threshold, season_at_fire is what the predicate compares'
);

echo "\n=== insert() failure is reported, not swallowed ===\n\n";

$wpdb->insert_succeeds = false;
assert_test( 0 === $db::insert( array( 'player_id' => 1, 'season_id' => 1, 'ack_key' => 'x' ) ), 'a failed insert returns 0' );
$wpdb->insert_succeeds = true;

require_once __DIR__ . '/../includes/class-discipline-notice.php';

$notice = 'SPLM_Discipline_Notice';
$row    = function ( $status, $season_at_fire ) {
	return (object) array(
		'status'         => $status,
		'season_at_fire' => $season_at_fire,
	);
};
$match  = function ( $value ) {
	return array(
		'tier_key'    => 'season-critical',
		'scope'       => 'season',
		'severity'    => 'critical',
		'minutes'     => 18,
		'value'       => $value,
		'consequence' => 'suspend',
		'games'       => 1,
	);
};

echo "\n=== the re-fire predicate ===\n\n";

assert_test( $notice::should_fire( $match( 18 ), null, 18 ), 'with no prior row, a match fires' );

// 'pending' is excluded on purpose and tested separately below.
foreach ( array( 'baseline', 'sent', 'discarded', 'served', 'failed' ) as $status ) {
	assert_test(
		! $notice::should_fire( $match( 18 ), $row( $status, 18 ), 18 ),
		"a {$status} row at the same season total suppresses the match"
	);
	assert_test(
		$notice::should_fire( $match( 19 ), $row( $status, 18 ), 19 ),
		"a {$status} row does not suppress once the player earns more"
	);
}

assert_test(
	! $notice::should_fire( $match( 18 ), $row( 'baseline', 25 ), 18 ),
	'a baseline above the current total still suppresses, so a mid-season switch-on cannot mail anyone'
);
assert_test(
	! $notice::should_fire( $match( 18 ), $row( 'failed', 18 ), 18 ),
	'a failed row does not duplicate: it stays actionable in the queue and is retried through release instead'
);

echo "\n=== the predicate compares the SEASON total, not the matched value ===\n\n";

// This is the window-tier bug the season comparison exists to prevent. A
// rolling window is not monotonic: the same 8 minutes stay inside the window
// for four weeks, so a value-based comparison would re-fire the suspension
// every week — four emails for one incident.
$window_match = function ( $window_value ) {
	return array(
		'tier_key'    => 'window-critical',
		'scope'       => 'window',
		'severity'    => 'critical',
		'minutes'     => 8,
		'value'       => $window_value,
		'consequence' => 'suspend',
		'games'       => 1,
	);
};

assert_test(
	! $notice::should_fire( $window_match( 8 ), $row( 'sent', 8 ), 8 ),
	'a window tier does not re-fire next week while the season total is unchanged, even though the window still shows the same 8 minutes'
);
assert_test(
	$notice::should_fire( $window_match( 8 ), $row( 'sent', 8 ), 16 ),
	'a genuine later offence fires again: the window figure is identical but the season total has grown'
);
assert_test(
	! $notice::should_fire( $window_match( 12 ), $row( 'sent', 20 ), 20 ),
	'a rising window figure alone does not fire when the season total has not moved'
);

echo "\n=== a pending notice is revised, not duplicated ===\n\n";

assert_test(
	! $notice::should_fire( $match( 20 ), $row( 'pending', 18 ), 20 ),
	'a pending row does not fire a second notice when the total grows: releasing a stack would mail several suspensions for one escalation'
);
assert_test(
	$notice::needs_refresh( $row( 'pending', 18 ), 20 ),
	'that pending row is flagged for revision instead'
);
assert_test(
	! $notice::needs_refresh( $row( 'pending', 18 ), 18 ),
	'an unchanged total needs no revision'
);
assert_test(
	! $notice::needs_refresh( $row( 'sent', 18 ), 20 ),
	'only a pending row is revisable; a sent one is history'
);
assert_test( ! $notice::needs_refresh( null, 20 ), 'no row means nothing to revise' );

echo "\n=== only consequence-bearing matches can fire ===\n\n";

$inert = $match( 18 );
$inert['consequence'] = 'none';

assert_test( ! $notice::should_fire( $inert, null, 18 ), 'a tier with no consequence never fires a notice' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
