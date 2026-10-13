<?php
/**
 * Standalone tests for the weekly digest's reminder sections.
 */

define( 'ABSPATH', __DIR__ );

class T_State {
	public $rows     = array();
	public $replaced = array();
	public $mail     = array();
	public $watch    = array();
	public $options  = array( 'splm_discipline_digest_enabled' => 1 );
	public $titles   = array();
}
function t_state() {
	static $s = null;
	if ( null === $s ) {
		$s = new T_State();
	}
	return $s;
}

function __( $t ) { return $t; } // phpcs:ignore
function esc_html__( $t ) { return htmlspecialchars( $t, ENT_QUOTES ); } // phpcs:ignore
function esc_html( $t ) { return htmlspecialchars( (string) $t, ENT_QUOTES ); } // phpcs:ignore
function add_action() {}
function add_filter() {}
function get_option( $k, $d = false ) { $o = t_state()->options; return array_key_exists( $k, $o ) ? $o[ $k ] : $d; } // phpcs:ignore
function get_the_title( $id ) { return t_state()->titles[ $id ] ?? 'Player ' . $id; } // phpcs:ignore
function wp_date( $fmt, $ts = null, $tz = null ) { return gmdate( $fmt, $ts ); } // phpcs:ignore
function is_email( $e ) { return false !== strpos( $e, '@' ); } // phpcs:ignore
function is_wp_error() { return false; } // phpcs:ignore
function get_term() { return (object) array( 'name' => '2026-27' ); } // phpcs:ignore
function wp_mail( $to, $subject, $body ) { t_state()->mail[] = compact( 'to', 'subject', 'body' ); return true; } // phpcs:ignore

class SPAT_Lock { public static function with( $k, $t, $cb ) { return $cb(); } } // phpcs:ignore
class SPLM_SportsPress_Data { public static function default_season_id() { return 9; } } // phpcs:ignore
class SPLM_Leaders_REST { public static function build_watch() { return t_state()->watch; } } // phpcs:ignore

class T_Wpdb {
	public $prefix = 'wp_';
	public function prepare( $q ) { return $q; }
	public function get_var() { return 'wp_splm_discipline_notice'; }
	public function get_results() { return t_state()->rows; }
	public function get_col() { return t_state()->replaced; }
}
$wpdb = new T_Wpdb();

require_once __DIR__ . '/../includes/class-discipline-notice-database.php';
require_once __DIR__ . '/../includes/class-discipline-digest-reminders.php';
require_once __DIR__ . '/../includes/class-discipline-digest.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) { echo "✓ PASS: {$message}\n"; $passed++; } else { echo "✗ FAIL: {$message}\n"; $failed++; }
}

function row( $id, $over = array() ) {
	return (object) array_merge(
		array(
			'id' => $id, 'player_id' => $id, 'status' => 'sent', 'source' => 'manual', 'scope' => 'manual',
			'outcome' => 'games', 'consequence' => 'suspend', 'infraction_title' => 'Fighting ' . $id,
			'eligible_on' => '2026-10-01', 'sent_at' => '2026-09-20 12:00:00', 'created_at' => '2026-09-20 11:00:00',
			'incident_note' => 'SECRET-NOTE',
		),
		$over
	);
}
function reset_state() {
	$s = t_state();
	$s->rows = array(); $s->replaced = array(); $s->mail = array(); $s->watch = array(); $s->titles = array();
}
function names( $list ) { return array_column( $list['rows'], 'player' ); }

$R     = 'SPLM_Discipline_Digest_Reminders';
$today = '2026-10-15';

echo "\n=== overdue list ===\n\n";
reset_state();
t_state()->rows = array(
	row( 1 ),
	row( 2, array( 'eligible_on' => '2026-12-01' ) ),
	row( 3, array( 'status' => 'served' ) ),
	row( 4, array( 'status' => 'revoked' ) ),
	row( 5, array( 'status' => 'discarded' ) ),
	row( 6, array( 'eligible_on' => null ) ),
	row( 7, array( 'source' => 'auto', 'scope' => 'x' ) ),
);
t_state()->replaced = array( '7' );
$d = $R::load( 9, $today );
assert_test( array( 'Player 1' ) === names( $d['overdue'] ), 'only the overdue, sent, unreplaced row is listed' );

echo "\n=== indefinite list ===\n\n";
reset_state();
t_state()->rows = array(
	row( 1, array( 'outcome' => 'indefinite', 'eligible_on' => null ) ),
	row( 2, array( 'outcome' => 'indefinite', 'eligible_on' => null ) ),
	row( 3, array( 'outcome' => 'indefinite', 'eligible_on' => null, 'status' => 'revoked' ) ),
	row( 4, array( 'outcome' => 'indefinite', 'eligible_on' => null, 'scope' => 'manual-amended' ) ),
);
t_state()->replaced = array( 2 );
$d = $R::load( 9, $today );
assert_test( array( 'Player 1' ) === names( $d['indefinite'] ), 'awaiting indefinite listed; decided, revoked, child rows excluded' );
assert_test( array() === $d['overdue']['rows'], 'indefinite rows with no eligible date are not overdue' );

echo "\n=== body ===\n\n";
reset_state();
t_state()->titles = array( 1 => '<script>x</script>' );
t_state()->rows   = array( row( 1 ), row( 2, array( 'outcome' => 'indefinite', 'eligible_on' => null ) ) );
$d    = $R::load( 9, $today );
$body = SPLM_Discipline_Digest::build_body( array(), '2026-27', $d );
assert_test( false === strpos( $body, '<script>' ) && false !== strpos( $body, '&lt;script&gt;' ), 'hostile player name escaped' );
assert_test( false === strpos( $body, 'SECRET-NOTE' ), 'incident_note never appears' );
assert_test( false !== strpos( $body, '2026-10-01' ) && false !== strpos( $body, '2026-09-20' ), 'eligible and issue dates shown' );
assert_test( false === strpos( $body, 'over a penalty threshold' ), 'no watch table without watch rows' );
$watch = array( array( 'player' => 'W', 'team' => 'T', 'division' => 'D', 'season_pim' => 20, 'window_pim' => 10, 'flags' => array( array( 'tier_key' => 'k' ) ) ) );
assert_test( false !== strpos( SPLM_Discipline_Digest::build_body( $watch, '2026-27' ), 'over a penalty threshold' ), 'build_body stays backward compatible' );

echo "\n=== cap ===\n\n";
reset_state();
$many = array();
for ( $i = 1; $i <= 30; $i++ ) { $many[] = row( $i ); }
t_state()->rows = $many;
$d = $R::load( 9, $today );
assert_test( 25 === count( $d['overdue']['rows'] ) && 5 === $d['overdue']['more'], 'list capped at 25 with 5 more' );
assert_test( false !== strpos( $R::render( $d ), '+5 more — see the dashboard Notices page' ), 'overflow line rendered' );

echo "\n=== run() ===\n\n";
reset_state();
t_state()->rows = array( row( 1, array( 'eligible_on' => '2000-01-01' ) ) );
assert_test( true === SPLM_Discipline_Digest::run(), 'reminders-only week sends' );
assert_test( 'Penalty watch — 2026-27' === t_state()->mail[0]['subject'], 'subject unchanged' );
reset_state();
assert_test( false === SPLM_Discipline_Digest::run() && array() === t_state()->mail, 'fully quiet week sends nothing' );

echo "\n{$passed} passed, {$failed} failed\n";
exit( $failed ? 1 : 0 );
