<?php
/**
 * Standalone tests for the manual-suspension REST class (part 1): route
 * registration and args, infractions, history, and the write-free preview.
 *
 * plan_preview() is pure and tested directly; the handlers run against fakes
 * whose write methods throw, which is how "preview writes nothing" is proven.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS; see
 * test-discipline-notice-recipients.php).
 *
 * TooManyFields: test fixture state holder.
 *
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class SPLM_Susp_Rest_Test_State {
	public $options    = array();
	public $routes     = array();
	public $infraction = array();
	public $history    = array();
	public $player_ok  = true;
	public $elig       = array();
	public $email      = array();
	public $teams      = array();
	public $writable   = false;
	public $rows       = array();
	public $inserted   = array();
	public $next_id    = 100;
	public $locks      = array();
	public $lock_busy  = false;
	public $mails      = array();
	public $mail_ok    = true;
	public $season     = 5;
	public $calls      = array();
	public $busy_prefix = '';
	public $failures   = 0;
	public $total      = 0;
	public $writes     = array();
	public $updates    = array();
	public $elig_args  = array();
	public $fail_update = array();
	public $bcc_args   = array();
}

function splm_susp_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Susp_Rest_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_susp_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function add_action() {}
function __( $text ) {
	return $text;
}
function _n( $single, $plural, $n ) {
	return 1 === (int) $n ? $single : $plural;
}
function absint( $v ) {
	return abs( (int) $v );
}
function sanitize_textarea_field( $v ) {
	return trim( (string) $v );
}
function wp_mail( $to, $subject, $body ) {
	splm_susp_state()->calls[]  = 'mail:' . $to;
	splm_susp_state()->writes[] = 'mail:' . $to;
	splm_susp_state()->mails[] = array(
		'to'      => $to,
		'subject' => $subject,
		'body'    => $body,
	);
	return splm_susp_state()->mail_ok;
}
function wp_json_encode( $v ) {
	return json_encode( $v );
}
function get_current_user_id() {
	return 7;
}
function register_rest_route( $ns, $route, $args ) {
	splm_susp_state()->routes[ $route ] = array_merge( array( 'ns' => $ns ), $args );
}
function get_option( $name, $default = false ) {
	$options = array_merge(
		array(
			'date_format' => 'F j, Y',
			'time_format' => 'g:i a',
		),
		splm_susp_state()->options
	);
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}
function get_post_type( $id ) {
	if ( 11 === (int) $id ) {
		return splm_susp_state()->player_ok ? 'sp_player' : 'page';
	}
	return 31 === (int) $id ? 'sp_event' : 'page';
}
function get_post( $id ) {
	return 31 === (int) $id ? (object) array(
		'post_title' => 'Red vs Green',
		'post_date'  => '2026-10-03 21:15:00',
	) : null;
}
function get_the_title( $id ) {
	$titles = array(
		11 => 'Jane Doe',
		21 => 'Red &amp; Blue',
		22 => 'Green',
		23 => str_repeat( 'x', 300 ),
	);
	return $titles[ (int) $id ] ?? '';
}
function get_term( $id ) {
	return 5 === (int) $id ? (object) array( 'name' => 'Winter' ) : null;
}
function get_date_from_gmt( $date ) {
	// A fixed UTC-5 site, so a late-evening UTC time lands on the previous local day.
	return gmdate( 'Y-m-d', strtotime( $date . ' UTC' ) - 5 * 3600 );
}
function current_time() {
	return '2026-10-01';
}
function mysql2date( $format, $date ) {
	return $format . '@' . $date;
}
function wp_date( $format, $ts ) {
	return $format . '#' . gmdate( 'Y-m-d', $ts );
}
function wp_specialchars_decode( $s ) {
	return html_entity_decode( $s, ENT_QUOTES );
}
function esc_url_raw( $url ) {
	return $url;
}
function is_wp_error( $v ) {
	return $v instanceof WP_Error;
}
function is_email( $e ) {
	return false !== strpos( $e, '@' ) ? $e : false;
}
function splm_rest_list_response( array $items, $total = null ) {
	$total = null === $total ? count( $items ) : $total;
	return array(
		'data'        => array_values( $items ),
		'total'       => $total,
		'page'        => 1,
		'total_pages' => $total > 0 ? 1 : 0,
	);
}

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data = $data;
	}
}

class WP_REST_Response {
	public $data;
	public $status;
	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}
}

class WP_REST_Request {
	private $params;
	public function __construct( array $params ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
}

// Fakes for everything the handlers reach into. The write methods throw: a
// preview that calls them fails loudly.
class SPLM_Discipline_Notice_REST {
	const REST_NAMESPACE = 'splm/v1';
	public static function gate() {
		return true;
	}
	public static function row_to_response( $row, $include_note = false ) {
		return array(
			'id'           => (int) $row->id,
			'status'       => (string) ( $row->status ?? '' ),
			'include_note' => $include_note,
		);
	}
}
class SPLM_Discipline_Notice_Database {
	const STATUS_SENT   = 'sent';
	const STATUS_FAILED = 'failed';
	public static function now() {
		return '2026-10-01 12:00:00';
	}
	public static function for_player() {
		splm_susp_state()->calls[] = 'for_player';
		$include_baseline = isset( func_get_args()[1] ) && func_get_args()[1];
		return $include_baseline ? array_merge( splm_susp_state()->history, array( (object) array( 'id' => 99, 'status' => 'baseline', 'consequence' => 'none', 'season_id' => 5 ) ) ) : splm_susp_state()->history;
	}
	public static function find( $id ) {
		return splm_susp_state()->rows[ (int) $id ] ?? null;
	}
	public static function children_of( $parent_id ) {
		$state          = splm_susp_state();
		$state->calls[] = 'children_of';
		$out            = array();
		foreach ( array_merge( $state->history, $state->rows ) as $row ) {
			if ( (int) ( $row->parent_id ?? 0 ) === (int) $parent_id && 'discarded' !== (string) ( $row->status ?? '' ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}
	public static function insert( array $row ) {
		$state = splm_susp_state();
		if ( ! $state->writable ) {
			throw new RuntimeException( 'preview must not insert' );
		}
		$state->calls[]    = 'insert';
		$state->writes[]   = 'insert';
		$state->inserted[] = $row;
		if ( ! $state->next_id ) {
			return 0;
		}
		$id                 = $state->next_id++;
		$state->rows[ $id ] = (object) array_merge( $row, array( 'id' => $id, 'created_at' => '2026-10-01 12:00:00' ) );
		return $id;
	}
	public static function update( $id, array $fields ) {
		$state = splm_susp_state();
		if ( ! $state->writable ) {
			throw new RuntimeException( 'preview must not update' );
		}
		if ( in_array( (int) $id, $state->fail_update, true ) ) {
			return false;
		}
		$state->writes[]  = 'update:' . $id . ':' . implode( ',', array_keys( $fields ) );
		$state->updates[] = array( (int) $id, $fields );
		foreach ( $fields as $key => $value ) {
			$state->rows[ (int) $id ]->$key = $value;
		}
		return true;
	}
}
class SPAT_Lock {
	public static function with( $key, $ttl, $fn ) {
		$state          = splm_susp_state();
		$state->locks[] = array( $key, $ttl );
		$state->calls[] = 'lock:' . $key;
		$busy           = $state->lock_busy || ( '' !== $state->busy_prefix && 0 === strpos( $key, $state->busy_prefix ) );
		return $busy ? false : $fn();
	}
}
class SPLM_Discipline_Infraction {
	const MAX_GAMES = 20;
	public static function all() {
		return array(
			(object) array(
				'id'            => '3',
				'rule_ref'      => '6.5',
				'title'         => 'Fighting',
				'rule_text'     => 'No fighting.',
				'outcome'       => 'games',
				'default_games' => '3',
				'needs_review'  => '1',
				'active'        => '1',
			),
		);
	}
	public static function find( $id ) {
		return splm_susp_state()->infraction[ (int) $id ] ?? null;
	}
}
class SPLM_Discipline_Eligibility {
	public static function next_eligible() {
		splm_susp_state()->elig_args[] = func_get_args();
		return splm_susp_state()->elig;
	}
	public static function player_team_ids() {
		return splm_susp_state()->teams;
	}
}
class SPLM_Discipline_Notice_Recipients {
	public static function player_email() {
		return splm_susp_state()->email;
	}
	public static function captain_email( $team_id ) {
		return 21 === (int) $team_id ? 'cap@example.com' : '';
	}
	public static function bcc_for() {
		// Mimics the real current-season behaviour: a non-zero team id adds that
		// team's captain to the Bcc list.
		$args = func_get_args();
		splm_susp_state()->bcc_args[] = $args;
		return ( (int) ( $args[1] ?? 0 ) ) ? array( 'conv@example.com', 'cap@example.com' ) : array( 'conv@example.com' );
	}
}
class SPLM_SportsPress_Data {
	public static function default_season_id() {
		return splm_susp_state()->season;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-captain-mail.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-body.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-context.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-actions.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-release.php';

$actions = 'SPLM_Discipline_Suspension_Actions';
$release = 'SPLM_Discipline_Suspension_Release';

$rest = 'SPLM_Discipline_Suspension_REST';
$rest_suspension = 'SPLM_Discipline_Suspension';

function inf( $over = array() ) {
	return (object) array_merge(
		array(
			'id'            => 3,
			'rule_ref'      => '6.5',
			'title'         => 'Fighting',
			'rule_text'     => 'No fighting.',
			'outcome'       => 'games',
			'default_games' => 3,
		),
		$over
	);
}
function in( $over = array() ) {
	return array_merge(
		array(
			'player_id'         => 11,
			'infraction_id'     => 3,
			'season_id'         => 5,
			'incident_event_id' => 0,
			'games'             => null,
		),
		$over
	);
}
function elig( $over = array() ) {
	return array_merge(
		array(
			'date'      => '2026-10-17 21:00:00',
			'event_id'  => 77,
			'team_id'   => 21,
			'remaining' => 0,
		),
		$over
	);
}
function row( $over = array() ) {
	return (object) array_merge(
		array(
			'id'                => 1,
			'player_id'         => 11,
			'season_id'         => 5,
			'source'            => 'manual',
			'consequence'       => 'suspend',
			'status'            => 'sent',
			'infraction_id'     => 9,
			'incident_event_id' => 0,
			'games'             => 2,
			'created_at'        => '2026-09-01 10:00:00',
		),
		$over
	);
}
$good_email = array(
	'email' => 'jane@example.com',
	'via'   => 'spt_email',
);
$caps_ok    = array(
	array(
		'team_id' => 21,
		'team'    => 'Red',
		'email'   => 'cap@example.com',
	),
);
$today      = '2026-10-01';

// resolve_games.
check( 'games default', $rest::resolve_games( inf(), null ), 3 );
check( 'games empty string is default', $rest::resolve_games( inf(), '' ), 3 );
check( 'games override', $rest::resolve_games( inf(), 5 ), 5 );
check( 'games override 0', $rest::resolve_games( inf(), 0 ), 0 );
check( 'games clamp high', $rest::resolve_games( inf(), 99 ), 20 );
check( 'games clamp negative', $rest::resolve_games( inf(), -4 ), 0 );
check( 'indefinite ignores override', $rest::resolve_games( inf( array( 'outcome' => 'indefinite' ) ), 7 ), 0 );
check( 'null infraction games', $rest::resolve_games( null, 7 ), 0 );

// plan_preview: invalid infraction.
$plan = $rest::plan_preview( in(), null, elig(), $good_email, $caps_ok, array(), $today );
check( 'invalid infraction not ok', $plan['ok'], false );
check( 'invalid infraction code', $plan['error_code'], 'invalid_infraction' );

// plan_preview: happy path, no warnings.
$plan = $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array(), $today );
check( 'ok', $plan['ok'], true );
check( 'no warnings', $plan['warnings'], array() );
check( 'games from infraction', $plan['games'], 3 );
check( 'outcome games', $plan['outcome'], 'games' );
check( 'eligible_on trimmed to date', $plan['eligible_on'], '2026-10-17' );
check( 'remaining', $plan['remaining'], 0 );
check( 'no duplicate', $plan['duplicate_of'], 0 );

// games override and clamp via input.
check( 'input override', $rest::plan_preview( in( array( 'games' => 6 ) ), inf(), elig(), $good_email, $caps_ok, array(), $today )['games'], 6 );
check( 'input clamp', $rest::plan_preview( in( array( 'games' => 500 ) ), inf(), elig(), $good_email, $caps_ok, array(), $today )['games'], 20 );
$ind = $rest::plan_preview( in( array( 'games' => 6 ) ), inf( array( 'outcome' => 'indefinite' ) ), elig(), $good_email, $caps_ok, array(), $today );
check( 'indefinite games 0', $ind['games'], 0 );
check( 'indefinite outcome', $ind['outcome'], 'indefinite' );
check( 'indefinite no eligible_on', $ind['eligible_on'], null );
check( 'indefinite remaining 0', $ind['remaining'], 0 );
$zero = $rest::plan_preview( in( array( 'games' => 0 ) ), inf(), elig(), $good_email, $caps_ok, array(), $today );
check( '0-game outcome no eligible_on', $zero['eligible_on'], null );
$short = $rest::plan_preview( in(), inf(), elig( array( 'date' => null, 'remaining' => 2 ) ), $good_email, $caps_ok, array(), $today );
check( 'no schedule: null eligible_on', $short['eligible_on'], null );
check( 'no schedule: remaining passed', $short['remaining'], 2 );

// Contact warnings.
$none = array(
	'email' => '',
	'via'   => '',
);
check( 'no_player_email', $rest::plan_preview( in(), inf(), elig(), $none, $caps_ok, array(), $today )['warnings'], array( 'no_player_email' ) );
$cap_blank = array(
	array(
		'team_id' => 22,
		'team'    => 'Green',
		'email'   => '',
	),
);
check( 'captain_no_email names the team', $rest::plan_preview( in(), inf(), elig(), $good_email, $cap_blank, array(), $today )['warnings'], array( 'captain_no_email:Green' ) );
$no_team = $rest::plan_preview( in(), inf(), elig(), $good_email, array(), array(), $today );
check( 'no_team_for_season', $no_team['warnings'], array( 'no_team_for_season' ) );
check( 'no_team_for_season is not blocking', $no_team['ok'], true );

// History warnings.
$prior = $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row() ), $today );
check( 'prior suspension (sent)', $prior['warnings'], array( 'prior_suspension_this_season' ) );
foreach ( array( 'served', 'pending' ) as $status ) {
	check( "prior suspension ($status)", in_array( 'prior_suspension_this_season', $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'status' => $status ) ) ), $today )['warnings'], true ), true );
}
foreach ( array( 'revoked', 'discarded', 'baseline', 'failed' ) as $status ) {
	check( "no prior warning ($status)", in_array( 'prior_suspension_this_season', $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'status' => $status ) ) ), $today )['warnings'], true ), false );
}
check( 'no prior warning: other season', $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'season_id' => 4 ) ) ), $today )['warnings'], array() );
check( 'no prior warning: automatic row', $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'source' => 'auto' ) ) ), $today )['warnings'], array() );
check( 'no prior warning: a warn row', $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'consequence' => 'warn' ) ) ), $today )['warnings'], array() );

// Revoked and replaced rows are not in force: no prior-suspension warning, no duplicate.
$rev_root  = row( array( 'id' => 1, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'revoked' ) );
$rev_corr  = row( array( 'id' => 2, 'parent_id' => 1, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'sent', 'consequence' => 'none' ) );
$revoked_p = $rest::plan_preview( in( array( 'incident_event_id' => 31 ) ), inf(), elig(), $good_email, $caps_ok, array( $rev_corr, $rev_root ), $today );
check( 'revoked suspension: no prior-suspension warning and no duplicate', array( $revoked_p['warnings'], $revoked_p['duplicate_of'] ), array( array(), 0 ) );
$chain_a   = row( array( 'id' => 1, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'sent' ) );
$chain_b   = row( array( 'id' => 2, 'parent_id' => 1, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'revoked' ) );
$chain_c   = row( array( 'id' => 3, 'parent_id' => 2, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'sent', 'consequence' => 'none' ) );
$tip_gone  = $rest::plan_preview( in( array( 'incident_event_id' => 31 ) ), inf(), elig(), $good_email, $caps_ok, array( $chain_c, $chain_b, $chain_a ), $today );
check( 'amended chain with the tip revoked: nothing in force', array( $tip_gone['warnings'], $tip_gone['duplicate_of'] ), array( array(), 0 ) );
$chain_live = $rest::plan_preview( in( array( 'incident_event_id' => 31 ) ), inf(), elig(), $good_email, $caps_ok, array( row( array( 'id' => 2, 'parent_id' => 1, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'sent' ) ), $chain_a ), $today );
check( 'live amended chain: warns once and the newest row is the duplicate', array( $chain_live['warnings'], $chain_live['duplicate_of'] ), array( array( 'prior_suspension_this_season', 'duplicate' ), 2 ) );

$dup = $rest::plan_preview( in( array( 'incident_event_id' => 31 ) ), inf(), elig(), $good_email, $caps_ok, array( row( array( 'id' => 42, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'pending' ) ) ), $today );
check( 'duplicate warning', in_array( 'duplicate', $dup['warnings'], true ), true );
check( 'duplicate_of is the existing id', $dup['duplicate_of'], 42 );
$dup_same_day = $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'id' => 43, 'infraction_id' => 3, 'created_at' => '2026-10-01 08:00:00' ) ) ), $today );
check( 'duplicate same-day without incident', $dup_same_day['duplicate_of'], 43 );
$not_dup = $rest::plan_preview( in(), inf(), elig(), $good_email, $caps_ok, array( row( array( 'infraction_id' => 3, 'created_at' => '2026-09-01 08:00:00' ) ) ), $today );
check( 'no duplicate on another day', $not_dup['duplicate_of'], 0 );

// Routes and args.
$instance = new SPLM_Discipline_Suspension_REST();
$instance->register_routes();
$routes = splm_susp_state()->routes;
check(
	'route list',
	array_keys( $routes ),
	array(
		'/discipline/infractions',
		'/discipline/history',
		'/discipline/suspensions/preview',
		'/discipline/suspensions',
		'/discipline/suspensions/(?P<id>\\d+)/decide',
		'/discipline/suspensions/(?P<id>\\d+)/amend',
		'/discipline/suspensions/(?P<id>\\d+)/revoke',
		'/discipline/suspensions/(?P<id>\\d+)/recalculate',
	)
);
foreach ( $routes as $path => $def ) {
	check( "namespace $path", $def['ns'], 'splm/v1' );
	check( "permission is the static gate $path", $def['permission_callback'], array( 'SPLM_Discipline_Notice_REST', 'gate' ) );
	foreach ( $def['args'] ?? array() as $name => $arg ) {
		check( "validate_callback $path:$name", $arg['validate_callback'], 'rest_validate_request_arg' );
		check( "sanitize_callback declared $path:$name", isset( $arg['sanitize_callback'] ), true );
	}
}
check( 'infractions GET', $routes['/discipline/infractions']['methods'], 'GET' );
check( 'history GET', $routes['/discipline/history']['methods'], 'GET' );
check( 'preview POST', $routes['/discipline/suspensions/preview']['methods'], 'POST' );
$hist_args = $routes['/discipline/history']['args'];
check( 'history player required min 1', array( $hist_args['player']['required'], $hist_args['player']['minimum'] ), array( true, 1 ) );
check( 'include_baseline boolean default false', array( $hist_args['include_baseline']['type'], $hist_args['include_baseline']['default'] ), array( 'boolean', false ) );
$prev_args = $routes['/discipline/suspensions/preview']['args'];
check( 'preview args', array_keys( $prev_args ), array( 'player', 'infraction', 'season', 'incident_event', 'games' ) );
check( 'preview player/infraction required', array( $prev_args['player']['required'], $prev_args['infraction']['required'] ), array( true, true ) );
check( 'preview games 0-20', array( $prev_args['games']['minimum'], $prev_args['games']['maximum'] ), array( 0, 20 ) );
check( 'preview season/incident optional', array( $prev_args['season']['required'], $prev_args['incident_event']['required'] ), array( false, false ) );

// Infractions handler.
$res = $instance->get_infractions();
check( 'infractions status', $res->status, 200 );
check(
	'infractions shape',
	$res->data['data'],
	array(
		array(
			'id'            => 3,
			'rule_ref'      => '6.5',
			'title'         => 'Fighting',
			'rule_text'     => 'No fighting.',
			'outcome'       => 'games',
			'default_games' => 3,
			'needs_review'  => 1,
		),
	)
);
check( 'infractions total', $res->data['total'], 1 );

// History handler.
$hres = $instance->get_history( new WP_REST_Request( array( 'player' => 11 ) ) );
check( 'empty history data', $hres->data['data'], array() );
check( 'empty history summary', $hres->data['summary'], 'No disciplinary record.' );
check( 'empty history paging', array( $hres->data['total'], $hres->data['page'], $hres->data['total_pages'] ), array( 0, 1, 0 ) );
splm_susp_state()->history = array( row() );
$hres = $instance->get_history( new WP_REST_Request( array( 'player' => 11 ) ) );
check( 'history rows include note for managers', $hres->data['data'], array( array( 'id' => 1, 'status' => 'sent', 'include_note' => true ) ) );
check( 'history summary counts the suspension', 0 === strpos( $hres->data['summary'], '1 suspension (2 games)' ), true );
$hres = $instance->get_history( new WP_REST_Request( array( 'player' => 11, 'include_baseline' => true ) ) );
check( 'baseline rows only when asked', count( $hres->data['data'] ), 2 );
splm_susp_state()->history = array();

// Preview handler.
splm_susp_state()->infraction = array( 3 => inf() );
splm_susp_state()->elig       = elig();
splm_susp_state()->email      = $good_email;
splm_susp_state()->teams      = array( 21, 22 );

function preview_request( $over = array() ) {
	return new WP_REST_Request( array_merge( array( 'player' => 11, 'infraction' => 3 ), $over ) );
}

// captain_recipients() asks the eligibility fake for teams; give it real titles.
$pres = $instance->preview( preview_request() );
check( 'preview status', $pres->status, 200 );
$body = $pres->data;
check( 'preview eligible_on', $body['eligible_on'], '2026-10-17' );
check( 'preview outcome and games', array( $body['outcome'], $body['games'], $body['remaining'] ), array( 'games', 3, 0 ) );
check( 'preview player_email', $body['player_email'], $good_email );
check(
	'preview captains with covered_by',
	$body['captains'],
	array(
		array(
			'team'       => 'Red &amp; Blue',
			'email'      => 'cap@example.com',
			'covered_by' => '',
		),
		array(
			'team'       => 'Green',
			'email'      => '',
			'covered_by' => '',
		),
	)
);
check( 'preview warnings', $body['warnings'], array( 'captain_no_email:Green' ) );
check( 'player subject', $body['player_subject'], 'Suspension notice — Winter' );
check( 'player body mentions the player', false !== strpos( $body['player_body'], 'Jane Doe' ), true );
check( 'captain body mentions the player', false !== strpos( $body['captain_body'], 'Jane Doe' ), true );
check( 'preview never leaks a note key', array_key_exists( 'incident_note', $body ), false );

// Preview and delivery agree on who is covered by the Bcc.
splm_susp_state()->bcc_args = array();
$parity   = $instance->preview( preview_request() );
$delivery = $rest_suspension::plan_captain_mail( $rest_suspension::captain_recipients( 11, 5 ), 'jane@example.com', $rest_suspension::delivery_bcc( 5, 'jane@example.com' ) );
check( 'preview covered_by matches plan_captain_mail on the delivery Bcc', array_column( $parity->data['captains'], 'covered_by' ), array_map( static function ( $entry ) { return (string) $entry['covered_by']; }, $delivery ) );
check( 'a captain is not reported as Bcc-covered by the preview', $parity->data['captains'][0]['covered_by'], '' );
check( 'the preview asks bcc_for for the convener list (team 0)', splm_susp_state()->bcc_args[0], array( 5, 0 ) );
check( 'delivery_bcc removes the player case-insensitively', $rest_suspension::delivery_bcc( 5, 'CONV@example.com' ), array() );
check( 'delivery_bcc keeps the convener when the player is someone else', $rest_suspension::delivery_bcc( 5, 'jane@example.com' ), array( 'conv@example.com' ) );

// A captain who is also the player is already covered by the player's mail.
splm_susp_state()->email = array(
	'email' => 'CAP@example.com',
	'via'   => 'spt_email',
);
$self = $instance->preview( preview_request() );
check( 'captain who is the player is covered_by player', $self->data['captains'][0]['covered_by'], 'player' );
splm_susp_state()->email = $good_email;

// Errors.
$bad = $instance->preview( preview_request( array( 'infraction' => 404 ) ) );
check( 'unknown infraction is a 400 WP_Error', array( $bad instanceof WP_Error, $bad->code, $bad->data['status'] ), array( true, 'invalid_infraction', 400 ) );
splm_susp_state()->player_ok = false;
$bad = $instance->preview( preview_request() );
check( 'unknown player is a 400 WP_Error', array( $bad instanceof WP_Error, $bad->code, $bad->data['status'] ), array( true, 'invalid_player', 400 ) );
splm_susp_state()->player_ok = true;

// Season defaults, games override, incident date.
$over = $instance->preview( preview_request( array( 'games' => 8, 'season' => 5, 'incident_event' => 31 ) ) );
check( 'override accepted', array( $over->status, $over->data['games'] ), array( 200, 8 ) );
splm_susp_state()->infraction = array( 3 => inf( array( 'outcome' => 'indefinite' ) ) );
$indef = $instance->preview( preview_request( array( 'games' => 8 ) ) );
check( 'indefinite preview ignores games', array( $indef->data['games'], $indef->data['eligible_on'], $indef->data['outcome'] ), array( 0, null, 'indefinite' ) );

// Create route registration.
check( 'create POST', $routes['/discipline/suspensions']['methods'], 'POST' );
$create_args = $routes['/discipline/suspensions']['args'];
check( 'create args', array_keys( $create_args ), array( 'player', 'infraction', 'season', 'incident_event', 'games', 'incident_note', 'mode' ) );
check( 'create player/infraction required', array( $create_args['player']['required'], $create_args['infraction']['required'] ), array( true, true ) );
check( 'create games 0-20', array( $create_args['games']['minimum'], $create_args['games']['maximum'] ), array( 0, 20 ) );
check( 'incident_note sanitised as a textarea', $create_args['incident_note']['sanitize_callback'], 'sanitize_textarea_field' );
check( 'mode enum send|draft default send', array( $create_args['mode']['enum'], $create_args['mode']['default'] ), array( array( 'send', 'draft' ), 'send' ) );

// create_error: pure validation order.
check( 'valid create has no error', $rest::create_error( true, true, inf(), 5 ), '' );
check( 'event checked first', $rest::create_error( false, false, null, 0 ), 'invalid_incident_event' );
check( 'player second', $rest::create_error( true, false, null, 0 ), 'invalid_player' );
check( 'infraction third', $rest::create_error( true, true, null, 0 ), 'invalid_infraction' );
check( 'season last', $rest::create_error( true, true, inf(), 0 ), 'invalid_season' );

// release_kind: scope to kind.
foreach ( array( 'manual' => 'issued', 'manual-decided' => 'decided', 'manual-amended' => 'amended', 'manual-revoked' => 'revoked', 'season' => 'issued' ) as $scope => $kind ) {
	check( "release_kind $scope", $release::release_kind( (object) array( 'scope' => $scope ) ), $kind );
}

function create_request( $over = array() ) {
	return new WP_REST_Request( array_merge( array( 'player' => 11, 'infraction' => 3, 'incident_note' => 'SECRET-NOTE' ), $over ) );
}
function reset_create() {
	$state             = splm_susp_state();
	$state->writable   = true;
	$state->rows       = array();
	$state->inserted   = array();
	$state->next_id    = 100;
	$state->locks      = array();
	$state->lock_busy  = false;
	$state->mails      = array();
	$state->mail_ok    = true;
	$state->calls      = array();
	$state->writes     = array();
	$state->updates    = array();
	$state->elig_args  = array();
	$state->fail_update = array();
	$state->busy_prefix = '';
	$state->season     = 5;
	$state->history    = array();
	$state->player_ok  = true;
	$state->infraction = array( 3 => inf() );
	$state->elig       = elig();
	$state->email      = array( 'email' => 'jane@example.com', 'via' => 'spt_email' );
	$state->teams      = array( 21, 22 );
}
function build_manual_row( $scope, $id ) {
	return (object) array(
		'id'                => $id,
		'player_id'         => 11,
		'season_id'         => 5,
		'scope'             => $scope,
		'consequence'       => 'suspend',
		'games'             => 3,
		'outcome'           => 'games',
		'status'            => 'pending',
		'source'            => 'manual',
		'rule_ref'          => '6.5',
		'infraction_title'  => 'Fighting',
		'rule_text'         => 'No fighting.',
		'incident_event_id' => 0,
		'incident_note'     => 'SECRET-NOTE',
		'eligible_on'       => '2026-10-17',
		'team'              => 'Red',
		'division'          => '',
		'ack_key'           => 'manual:3',
		'severity'          => 'high',
		'infraction_id'     => 3,
		'created_at'        => '2026-09-20 15:00:00',
	);
}

// Create handler: validation.
reset_create();
$bad = $instance->create( create_request( array( 'incident_event' => 99 ) ) );
check( 'non-event incident is a 400 and takes no lock', array( $bad->code, $bad->data['status'], splm_susp_state()->locks ), array( 'invalid_incident_event', 400, array() ) );
splm_susp_state()->player_ok = false;
$bad = $instance->create( create_request() );
check( 'create: unknown player', array( $bad->code, $bad->data['status'] ), array( 'invalid_player', 400 ) );
splm_susp_state()->player_ok = true;
$bad = $instance->create( create_request( array( 'infraction' => 404 ) ) );
check( 'create: unknown infraction', array( $bad->code, $bad->data['status'] ), array( 'invalid_infraction', 400 ) );
splm_susp_state()->season = 0;
$bad = $instance->create( create_request() );
check( 'create: no season', array( $bad->code, $bad->data['status'], splm_susp_state()->inserted ), array( 'invalid_season', 400, array() ) );
splm_susp_state()->season = 5;
$bad = $instance->create( create_request( array( 'season' => 99 ) ) );
check( 'create: season that is not an sp_season term', array( $bad->code, $bad->data['status'], splm_susp_state()->inserted ), array( 'invalid_season', 400, array() ) );
$bad = $instance->preview( preview_request( array( 'season' => 99 ) ) );
check( 'preview: season that is not an sp_season term', array( $bad->code, $bad->data['status'] ), array( 'invalid_season', 400 ) );

splm_susp_state()->lock_busy = true;
$bad = $instance->create( create_request() );
check( 'create: busy lock is a 409', array( $bad->code, $bad->data['status'], splm_susp_state()->locks ), array( 'splm_notice_busy', 409, array( array( 'splm_discipline_suspension_11', 60 ) ) ) );
check( 'nothing inserted while busy', splm_susp_state()->inserted, array() );
splm_susp_state()->lock_busy = false;

// Duplicate, read inside the lock.
splm_susp_state()->history = array( row( array( 'id' => 42, 'infraction_id' => 3, 'incident_event_id' => 31, 'status' => 'pending' ) ) );
$dup = $instance->create( create_request( array( 'incident_event' => 31 ) ) );
check( 'duplicate is a 409 naming the existing row', array( $dup->code, $dup->data['status'], $dup->data['duplicate_of'] ), array( 'splm_suspension_duplicate', 409, 42 ) );
check( 'duplicate inserts and mails nothing', array( splm_susp_state()->inserted, splm_susp_state()->mails ), array( array(), array() ) );

// Draft.
reset_create();
$draft = $instance->create( create_request( array( 'mode' => 'draft', 'games' => 4 ) ) );
check( 'draft is a 201', $draft->status, 201 );
check( 'draft: not sent, no captains, no mail', array( $draft->data['sent'], $draft->data['captains'], splm_susp_state()->mails ), array( false, array(), array() ) );
check( 'draft: notice is fresh and shown with the note', $draft->data['notice'], array( 'id' => 100, 'status' => 'pending', 'include_note' => true ) );
$saved = splm_susp_state()->inserted[0];
check( 'draft row: manual, pending, 4 games', array( $saved['source'], $saved['status'], $saved['games'], $saved['infraction_id'] ), array( 'manual', 'pending', 4, 3 ) );
check( 'draft row: team snapshot decoded and joined, no division', array( $saved['team'], $saved['division'] ), array( 'Red & Blue, Green', '' ) );
check( 'draft row: note stored on the row', $saved['incident_note'], 'SECRET-NOTE' );
check( 'draft row: season defaulted', $saved['season_id'], 5 );
check( 'draft takes the per-player lock', splm_susp_state()->locks, array( array( 'splm_discipline_suspension_11', 60 ) ) );

// Send.
reset_create();
$sent = $instance->create( create_request( array( 'incident_event' => 31 ) ) );
check( 'send is a 201 and sent', array( $sent->status, $sent->data['sent'] ), array( 201, true ) );
check( 'send: row is sent when re-read', $sent->data['notice']['status'], 'sent' );
check( 'send: player and one captain mailed', count( splm_susp_state()->mails ), 2 );
check( 'send: subject is the issued subject', splm_susp_state()->mails[0]['subject'], 'Suspension notice — Winter' );
$leak = false;
foreach ( splm_susp_state()->mails as $mail ) {
	$leak = $leak || false !== strpos( $mail['subject'] . $mail['body'], 'SECRET-NOTE' );
}
check( 'the incident note is in no email', $leak, false );
check( 'send: incident label comes from the event', false !== strpos( splm_susp_state()->mails[0]['body'], 'Red vs Green' ), true );

// A failed send is still a 201 the modal can read.
reset_create();
splm_susp_state()->mail_ok = false;
$failed = $instance->create( create_request() );
check( 'failed send is still a 201', array( $failed->status, $failed->data['sent'], $failed->data['notice']['status'] ), array( 201, false, 'failed' ) );

// Insert failure.
reset_create();
splm_susp_state()->next_id = 0;
$bad = $instance->create( create_request() );
check( 'insert failure is a 500', array( $bad->code, $bad->data['status'], splm_susp_state()->mails ), array( 'splm_notice_write_failed', 500, array() ) );

// release_row (the manual branch of the notice release route).
reset_create();
splm_susp_state()->rows[1] = build_manual_row( 'manual-amended', 1 );
$ok = $release::release_row( splm_susp_state()->rows[1] );
check( 'release_row success shape', array( $ok->status, $ok->data ), array( 200, array( 'success' => true, 'id' => 1, 'status' => 'sent' ) ) );
check( 'release_row used the amended kind in the subject', splm_susp_state()->mails[0]['subject'], 'Updated suspension notice — Winter' );
$again = $release::release_row( splm_susp_state()->rows[1] );
check( 'release_row on a sent row is a 409', array( $again->code, $again->data['status'] ), array( 'splm_notice_not_releasable', 409 ) );
check( 'release_row did not re-mail', count( splm_susp_state()->mails ), 2 );
splm_susp_state()->mail_ok = false;
splm_susp_state()->rows[2] = build_manual_row( 'manual', 2 );
$err = $release::release_row( splm_susp_state()->rows[2] );
check( 'release_row failure carries last_error', array( $err->code, $err->data['status'], $err->message ), array( 'splm_notice_send_failed', 500, 'wp_mail() rejected the message.' ) );

// Retired infractions are treated as unknown.
splm_susp_state()->infraction[4] = inf( array( 'id' => 4, 'active' => '0' ) );
splm_susp_state()->infraction[5] = inf( array( 'id' => 5, 'active' => '1' ) );
$bad = $instance->preview( preview_request( array( 'infraction' => 4 ) ) );
check( 'preview rejects a retired infraction', array( $bad instanceof WP_Error, $bad->code ), array( true, 'invalid_infraction' ) );
check( 'preview accepts an active infraction', $instance->preview( preview_request( array( 'infraction' => 5 ) ) )->status, 200 );
$bad = $instance->create( create_request( array( 'infraction' => 4 ) ) );
check( 'create rejects a retired infraction', array( $bad->code, $bad->data['status'] ), array( 'invalid_infraction', 400 ) );

// Pure release helpers.
check( 'prior_games from the parent of an amended row', $release::prior_games_extra( 'manual-amended', (object) array( 'games' => 4 ) ), array( 'prior_games' => 4 ) );
check( 'no prior_games for other scopes', array( $release::prior_games_extra( 'manual', (object) array( 'games' => 4 ) ), $release::prior_games_extra( 'manual-decided', (object) array( 'games' => 4 ) ) ), array( array(), array() ) );
check( 'no prior_games without a parent', $release::prior_games_extra( 'manual-amended', null ), array() );
check( 'decided counts from the decision date', $release::count_from_date( 'manual-decided', '2026-09-01', '2026-09-20', '', '2026-10-01' ), '2026-09-20' );
check( 'decided without a decision date counts from today', $release::count_from_date( 'manual-decided', '2026-09-01', '', '', '2026-10-01' ), '2026-10-01' );
check( 'amended counts from the incident date', $release::count_from_date( 'manual-amended', '2026-09-01', '2026-09-20', '', '2026-10-01' ), '2026-09-01' );
check( 'issued counts from the incident date', $release::count_from_date( 'manual', '2026-09-01', '2026-09-20', '', '2026-10-01' ), '2026-09-01' );
check( 'issued without an incident counts from today', $release::count_from_date( 'manual', '', '2026-09-20', '', '2026-10-01' ), '2026-10-01' );
splm_susp_state()->rows[20]            = build_manual_row( 'manual-decided', 20 );
splm_susp_state()->rows[20]->created_at = '2026-10-02 02:00:00';
splm_susp_state()->rows[21]            = build_manual_row( 'manual-amended', 21 );
splm_susp_state()->rows[21]->parent_id = 20;
splm_susp_state()->rows[22]            = build_manual_row( 'manual', 22 );
splm_susp_state()->rows[22]->incident_event_id = 31;
check( 'a decided row counts from its decision date in site-local time', $release::count_from( splm_susp_state()->rows[20] ), '2026-10-01' );
check( 'an amended decision keeps the decision date', $release::count_from( splm_susp_state()->rows[21] ), '2026-10-01' );
check( 'an issued row counts from its incident', $release::count_from( splm_susp_state()->rows[22] ), '2026-10-03' );

// Create ordering: exact call sequence.
reset_create();
$instance->create( create_request() );
check(
	'send order: player lock, re-read, insert, notice lock, mails',
	splm_susp_state()->calls,
	array( 'lock:splm_discipline_suspension_11', 'for_player', 'insert', 'lock:splm_discipline_notice_100', 'mail:jane@example.com', 'mail:cap@example.com' )
);
reset_create();
$instance->create( create_request( array( 'mode' => 'draft' ) ) );
check( 'draft order: no notice lock, no mail', splm_susp_state()->calls, array( 'lock:splm_discipline_suspension_11', 'for_player', 'insert' ) );
reset_create();
splm_susp_state()->busy_prefix = 'splm_discipline_notice_';
$busy = $instance->create( create_request() );
check( 'busy notice lock is a 409 and nothing is mailed', array( $busy->code, $busy->data['status'], splm_susp_state()->mails ), array( 'splm_notice_busy', 409, array() ) );

// Length limits.
check( 'incident_note arg maxLength 2000', $create_args['incident_note']['maxLength'], 2000 );
reset_create();
$instance->create( create_request( array( 'mode' => 'draft', 'incident_note' => str_repeat( 'é', 2500 ) ) ) );
check( 'stored note truncated to 2000 characters', mb_strlen( splm_susp_state()->inserted[0]['incident_note'] ), 2000 );
reset_create();
splm_susp_state()->teams = array( 23 );
$instance->create( create_request( array( 'mode' => 'draft' ) ) );
check( 'team snapshot truncated to 200', strlen( splm_susp_state()->inserted[0]['team'] ), 200 );

// A retried amendment still knows the length it replaced.
reset_create();
splm_susp_state()->rows[8]        = build_manual_row( 'manual', 8 );
splm_susp_state()->rows[8]->games = 4;
splm_susp_state()->rows[9]        = build_manual_row( 'manual-amended', 9 );
splm_susp_state()->rows[9]->games = 2;
splm_susp_state()->rows[9]->parent_id = 8;
$release::release_row( splm_susp_state()->rows[9] );
check( 'retried amend body says changed from 4 games to 2 games', false !== strpos( splm_susp_state()->mails[0]['body'], 'changed from 4 games to 2 games' ), true );
check( 'retried amend body is not the plain length line', false === strpos( splm_susp_state()->mails[0]['body'], 'Length: 2 games.' ), true );

// Structural: the preview path never writes or locks.
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-discipline-suspension-rest.php' );
foreach ( array( 'preview', 'preview_input', 'gather_facts', 'after_date', 'preview_response', 'row_input', 'team_names', 'captain_summary', 'plan_preview', 'duplicate_of', 'contact_warnings', 'history_warnings', 'has_prior_suspension', 'resolve_games', 'get_history', 'get_infractions' ) as $name ) {
	$ref   = new ReflectionMethod( 'SPLM_Discipline_Suspension_REST', $name );
	$lines = array_slice( explode( "\n", $source ), $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 );
	$code  = implode( "\n", $lines );
	check( "$name makes no write or lock calls", 1 === preg_match( '/::(insert|update|delete)\s*\(|SPAT_Lock|update_option|wp_insert|wp_mail|set_transient/', $code ), false );
}

// Structural: incident_note never reaches an email-building path.
function method_source( $class, $name ) {
	$ref   = new ReflectionMethod( $class, $name );
	$lines = file( $ref->getFileName() );
	$code  = implode( '', array_slice( $lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1 ) );
	return preg_replace( '/^\s*(\*|\/\/|\/\*).*$/m', '', $code );
}
foreach ( array( 'finish_create', 'preview_response', 'team_names' ) as $name ) {
	check( "$name never mentions incident_note", false === strpos( method_source( 'SPLM_Discipline_Suspension_REST', $name ), 'incident_note' ), true );
}
foreach ( array( 'release_row', 'release_extra', 'release_response' ) as $name ) {
	check( "$name never mentions incident_note", false === strpos( method_source( 'SPLM_Discipline_Suspension_Release', $name ), 'incident_note' ), true );
}
check( 'create_locked copies the note only into the row input', 2 === substr_count( method_source( 'SPLM_Discipline_Suspension_REST', 'create_locked' ), 'incident_note' ), true );
check( 'for_row names incident_note only inside its unset guard', false === strpos( preg_replace( '/unset\([^;]*\);/', '', method_source( 'SPLM_Discipline_Suspension_Context', 'for_row' ) ), 'incident_note' ), true );

// Structural: the notice release route hands manual rows to release_row()
// after its releasable-status check, before the automatic send.
$notice_src = file_get_contents( dirname( __DIR__ ) . '/includes/class-discipline-notice-rest.php' );
$status_pos = strpos( $notice_src, "'splm_notice_not_releasable'" );
$branch_pos = strpos( $notice_src, "'manual' === (string) ( \$row->source ?? 'auto' )" );
$hand_off   = strpos( $notice_src, 'SPLM_Discipline_Suspension_Release::release_row( $row )' );
$auto_pos   = strpos( $notice_src, 'SPLM_Discipline_Notice_Mail::send(' );
check( 'release_locked branches on manual after the status check and before the automatic send', false !== $status_pos && $status_pos < $branch_pos && $branch_pos < $hand_off && $hand_off < $auto_pos, true );

// Action routes: registration.
$action_routes = array(
	'decide'      => array( 'games' ),
	'amend'       => array( 'games' ),
	'revoke'      => array( 'notify' ),
	'recalculate' => array(),
);
foreach ( $action_routes as $action => $extra_args ) {
	$path = '/discipline/suspensions/(?P<id>\d+)/' . $action;
	$def  = $routes[ $path ];
	check( "$action is a POST to the instance handler", array( $def['methods'], $def['callback'] ), array( 'POST', array( 'SPLM_Discipline_Suspension_Actions', $action ) ) );
	check( "$action args", array_keys( $def['args'] ), array_merge( array( 'id' ), $extra_args ) );
	check( "$action id required min 1", array( $def['args']['id']['required'], $def['args']['id']['minimum'] ), array( true, 1 ) );
}
$decide_args = $routes['/discipline/suspensions/(?P<id>\d+)/decide']['args'];
$amend_args  = $routes['/discipline/suspensions/(?P<id>\d+)/amend']['args'];
$revoke_args = $routes['/discipline/suspensions/(?P<id>\d+)/revoke']['args'];
check( 'decide games required 1-20', array( $decide_args['games']['required'], $decide_args['games']['minimum'], $decide_args['games']['maximum'] ), array( true, 1, 20 ) );
check( 'amend games required 0-20', array( $amend_args['games']['required'], $amend_args['games']['minimum'], $amend_args['games']['maximum'] ), array( true, 0, 20 ) );
check( 'revoke notify boolean default true', array( $revoke_args['notify']['type'], $revoke_args['notify']['default'], $revoke_args['notify']['required'] ), array( 'boolean', true, false ) );

// Pure precondition planners.
function parent_row( $over = array() ) {
	return (object) array_merge( (array) build_manual_row( 'manual', 1 ), array( 'status' => 'sent' ), $over );
}
check( 'missing parent', $actions::parent_error( null ), 'splm_notice_not_found' );
check( 'automatic parent', $actions::parent_error( parent_row( array( 'source' => 'auto' ) ) ), 'splm_suspension_not_manual' );
check( 'row without source is automatic', $actions::parent_error( (object) array( 'id' => 1 ) ), 'splm_suspension_not_manual' );
check( 'manual parent is fine', $actions::parent_error( parent_row() ), '' );

check( 'decide ok (sent)', $actions::decide_error( parent_row( array( 'outcome' => 'indefinite' ) ), false ), '' );
check( 'decide ok (served)', $actions::decide_error( parent_row( array( 'outcome' => 'indefinite', 'status' => 'served' ) ), false ), '' );
check( 'decide missing', $actions::decide_error( null, false ), 'splm_notice_not_found' );
check( 'decide not manual', $actions::decide_error( parent_row( array( 'source' => 'auto' ) ), false ), 'splm_suspension_not_manual' );
check( 'decide games outcome not decidable', $actions::decide_error( parent_row(), false ), 'splm_suspension_not_decidable' );
foreach ( array( 'pending', 'failed', 'revoked', 'discarded' ) as $status ) {
	check( "decide $status not decidable", $actions::decide_error( parent_row( array( 'outcome' => 'indefinite', 'status' => $status ) ), false ), 'splm_suspension_not_decidable' );
}
check( 'decide already decided', $actions::decide_error( parent_row( array( 'outcome' => 'indefinite' ) ), true ), 'splm_suspension_already_decided' );

check( 'amend ok', $actions::amend_error( parent_row(), 1, false ), '' );
check( 'amend to 0 ok', $actions::amend_error( parent_row(), 0, false ), '' );
check( 'amend missing', $actions::amend_error( null, 1, false ), 'splm_notice_not_found' );
check( 'amend not manual', $actions::amend_error( parent_row( array( 'source' => 'auto' ) ), 1, false ), 'splm_suspension_not_manual' );
check( 'amend indefinite not amendable', $actions::amend_error( parent_row( array( 'outcome' => 'indefinite' ) ), 1, false ), 'splm_suspension_not_amendable' );
foreach ( array( 'pending', 'failed', 'served', 'revoked', 'discarded' ) as $status ) {
	check( "amend $status not amendable", $actions::amend_error( parent_row( array( 'status' => $status ) ), 1, false ), 'splm_suspension_not_amendable' );
}
check( 'amend unchanged', $actions::amend_error( parent_row(), 3, false ), 'splm_suspension_unchanged' );
check( 'amend already amended', $actions::amend_error( parent_row(), 1, true ), 'splm_suspension_already_amended' );
check( 'amend unchanged beats already amended', $actions::amend_error( parent_row(), 3, true ), 'splm_suspension_unchanged' );

check( 'revoke missing', $actions::revoke_error( null, false ), 'splm_notice_not_found' );
check( 'revoke not manual', $actions::revoke_error( parent_row( array( 'source' => 'auto' ) ), false ), 'splm_suspension_not_manual' );
foreach ( array( 'pending', 'failed', 'sent' ) as $status ) {
	check( "revoke $status ok", $actions::revoke_error( parent_row( array( 'status' => $status ) ), false ), '' );
}
foreach ( array( 'revoked', 'discarded', 'served', 'baseline' ) as $status ) {
	check( "revoke $status not revocable", $actions::revoke_error( parent_row( array( 'status' => $status ) ), false ), 'splm_suspension_not_revocable' );
}

check( 'recalc missing', $actions::recalculate_error( null, false ), 'splm_notice_not_found' );
check( 'recalc not manual', $actions::recalculate_error( parent_row( array( 'source' => 'auto' ) ), false ), 'splm_suspension_not_manual' );
check( 'recalc indefinite not recalculable', $actions::recalculate_error( parent_row( array( 'outcome' => 'indefinite' ) ), false ), 'splm_suspension_not_recalculable' );
foreach ( array( 'pending', 'sent' ) as $status ) {
	check( "recalc $status ok", $actions::recalculate_error( parent_row( array( 'status' => $status ) ), false ), '' );
}
foreach ( array( 'failed', 'served', 'revoked', 'discarded' ) as $status ) {
	check( "recalc $status not recalculable", $actions::recalculate_error( parent_row( array( 'status' => $status ) ), false ), 'splm_suspension_not_recalculable' );
}

check( 'revoke of a superseded row', $actions::revoke_error( parent_row(), true ), 'splm_suspension_superseded' );
check( 'revoke not revocable beats superseded', $actions::revoke_error( parent_row( array( 'status' => 'revoked' ) ), true ), 'splm_suspension_not_revocable' );
check( 'recalc of a superseded row', $actions::recalculate_error( parent_row(), true ), 'splm_suspension_superseded' );
check( 'recalc not recalculable beats superseded', $actions::recalculate_error( parent_row( array( 'status' => 'failed' ) ), true ), 'splm_suspension_not_recalculable' );
$correction = parent_row( array( 'scope' => 'manual-revoked', 'consequence' => 'none' ) );
check( 'a correction row is not a suspension', $actions::parent_error( $correction ), 'splm_suspension_not_a_suspension' );
check( 'not manual beats not a suspension', $actions::parent_error( parent_row( array( 'source' => 'auto', 'consequence' => 'none' ) ) ), 'splm_suspension_not_manual' );
foreach ( array( 'games', 'indefinite' ) as $outcome ) {
	$corr = parent_row( array( 'scope' => 'manual-revoked', 'consequence' => 'none', 'outcome' => $outcome ) );
	check( "decide rejects a correction row ($outcome)", $actions::decide_error( $corr, false ), 'splm_suspension_not_a_suspension' );
	check( "amend rejects a correction row ($outcome)", $actions::amend_error( $corr, 1, false ), 'splm_suspension_not_a_suspension' );
	check( "revoke rejects a correction row ($outcome)", $actions::revoke_error( $corr, false ), 'splm_suspension_not_a_suspension' );
	check( "recalculate rejects a correction row ($outcome)", $actions::recalculate_error( $corr, false ), 'splm_suspension_not_a_suspension' );
}
check(
	'action error statuses',
	array_map(
		static function ( $code ) use ( $actions ) {
			return $actions::action_error( $code )->data['status'];
		},
		array( 'splm_notice_not_found', 'splm_suspension_unchanged', 'splm_suspension_not_manual', 'splm_suspension_already_decided', 'splm_suspension_superseded', 'splm_suspension_not_a_suspension' )
	),
	array( 404, 400, 409, 409, 409, 409 )
);

function action_request( $over = array() ) {
	return new WP_REST_Request( $over );
}
function reset_action() {
	reset_create();
	splm_susp_state()->rows[1] = parent_row();
}

// Decide.
reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
splm_susp_state()->rows[1]->games   = 0;
$res = $actions::decide( action_request( array( 'id' => 1, 'games' => 3 ) ) );
check( 'decide is a 200 and sent', array( $res->status, $res->data['sent'] ), array( 200, true ) );
check( 'decide responds with the child, note shown', $res->data['notice'], array( 'id' => 100, 'status' => 'sent', 'include_note' => true ) );
$child = splm_susp_state()->inserted[0];
check( 'decide child row', array( $child['scope'], $child['parent_id'], $child['outcome'], $child['games'], $child['eligible_on'], $child['source'] ), array( 'manual-decided', 1, 'games', 3, '2026-10-17', 'manual' ) );
check( 'decide counts from today', splm_susp_state()->elig_args, array( array( 11, 5, '2026-10-01', 3 ) ) );
check( 'decide uses the decided subject', splm_susp_state()->mails[0]['subject'], 'Suspension Decision — Winter' );
check(
	'decide order: parent lock, child check, insert, child lock, mails',
	splm_susp_state()->calls,
	array( 'lock:splm_discipline_notice_1', 'children_of', 'insert', 'lock:splm_discipline_notice_100', 'mail:jane@example.com', 'mail:cap@example.com' )
);

reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
splm_susp_state()->history          = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'sent' ) );
$dup = $actions::decide( action_request( array( 'id' => 1, 'games' => 3 ) ) );
check( 'decide twice is a 409', array( $dup->code, $dup->data['status'], splm_susp_state()->inserted, splm_susp_state()->mails ), array( 'splm_suspension_already_decided', 409, array(), array() ) );
splm_susp_state()->history          = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'discarded' ) );
splm_susp_state()->rows[1]->outcome = 'indefinite';
check( 'a discarded child does not block a decision', $actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) )->status, 200 );
reset_action();
check( 'decide a games parent is a 409', $actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) )->code, 'splm_suspension_not_decidable' );
check( 'decide a missing notice is a 404', $actions::decide( action_request( array( 'id' => 77, 'games' => 2 ) ) )->data['status'], 404 );
splm_susp_state()->rows[2] = parent_row( array( 'id' => 2, 'source' => 'auto', 'outcome' => 'indefinite' ) );
check( 'decide an automatic notice is a 409', $actions::decide( action_request( array( 'id' => 2, 'games' => 2 ) ) )->code, 'splm_suspension_not_manual' );
splm_susp_state()->busy_prefix = 'splm_discipline_notice_';
$busy = $actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) );
check( 'decide busy lock is a 409', array( $busy->code, $busy->data['status'], splm_susp_state()->inserted ), array( 'splm_notice_busy', 409, array() ) );
reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
splm_susp_state()->next_id          = 0;
$bad = $actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) );
check( 'decide insert failure is a 500', array( $bad->code, $bad->data['status'], splm_susp_state()->mails ), array( 'splm_notice_write_failed', 500, array() ) );

// Amend.
reset_action();
splm_susp_state()->rows[1]->incident_event_id = 31;
$res = $actions::amend( action_request( array( 'id' => 1, 'games' => 2 ) ) );
check( 'amend is a 200 and sent', array( $res->status, $res->data['sent'], $res->data['notice']['id'] ), array( 200, true, 100 ) );
$child = splm_susp_state()->inserted[0];
check( 'amend child row', array( $child['scope'], $child['parent_id'], $child['games'], $child['outcome'], $child['eligible_on'] ), array( 'manual-amended', 1, 2, 'games', '2026-10-17' ) );
check( 'amend counts from the incident date', splm_susp_state()->elig_args, array( array( 11, 5, '2026-10-03', 2 ) ) );
check( 'amend body says changed from 3 games to 2 games', false !== strpos( splm_susp_state()->mails[0]['body'], 'changed from 3 games to 2 games' ), true );
check( 'amend order', splm_susp_state()->calls, array( 'lock:splm_discipline_notice_1', 'children_of', 'insert', 'lock:splm_discipline_notice_100', 'mail:jane@example.com', 'mail:cap@example.com' ) );
reset_action();
$actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) );
check( 'amend without an incident counts from the issued date (created_at when never sent), not today', splm_susp_state()->elig_args[0][2], '2026-09-20' );
reset_action();
$res = $actions::amend( action_request( array( 'id' => 1, 'games' => 0 ) ) );
check( 'amend to 0 games stores no eligibility date', array( $res->status, splm_susp_state()->inserted[0]['games'], splm_susp_state()->inserted[0]['eligible_on'] ), array( 200, 0, null ) );
reset_action();
$same = $actions::amend( action_request( array( 'id' => 1, 'games' => 3 ) ) );
check( 'amend unchanged is a 400', array( $same->code, $same->data['status'], splm_susp_state()->inserted ), array( 'splm_suspension_unchanged', 400, array() ) );
splm_susp_state()->history = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'pending' ) );
$twice = $actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) );
check( 'amend twice is a 409', array( $twice->code, $twice->data['status'], splm_susp_state()->inserted ), array( 'splm_suspension_already_amended', 409, array() ) );
reset_action();
splm_susp_state()->rows[1]->status = 'pending';
check( 'amend a draft is a 409', $actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) )->code, 'splm_suspension_not_amendable' );
splm_susp_state()->busy_prefix = 'splm_discipline_notice_';
check( 'amend busy lock is a 409', $actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) )->code, 'splm_notice_busy' );

// Revoke: a sent parent writes the child, then the parent, then mails.
reset_action();
$res = $actions::revoke( action_request( array( 'id' => 1, 'notify' => true ) ) );
check( 'revoke sent: 200, sent, parent shown revoked', array( $res->status, $res->data['sent'], $res->data['notice'] ), array( 200, true, array( 'id' => 1, 'status' => 'revoked', 'include_note' => true ) ) );
check( 'revoke writes child, then parent, then mail', array_slice( splm_susp_state()->writes, 0, 3 ), array( 'insert', 'update:1:status', 'mail:jane@example.com' ) );
check( 'revoke full call order: parent lock, child check, insert, child lock, player and captain mail', splm_susp_state()->calls, array( 'lock:splm_discipline_notice_1', 'children_of', 'insert', 'lock:splm_discipline_notice_100', 'mail:jane@example.com', 'mail:cap@example.com' ) );
check( 'revoke full write order', splm_susp_state()->writes, array( 'insert', 'update:1:status', 'mail:jane@example.com', 'update:100:status,sent_at,recipient,recipient_via,bcc,released_by,last_error', 'mail:cap@example.com', 'update:100:captains_notified' ) );
$child = splm_susp_state()->inserted[0];
check( 'revoke child row', array( $child['scope'], $child['parent_id'], $child['consequence'], $child['games'], $child['status'] ), array( 'manual-revoked', 1, 'none', 0, 'pending' ) );
check( 'revoke child ends sent', splm_susp_state()->rows[100]->status, 'sent' );

reset_action();
$res = $actions::revoke( action_request( array( 'id' => 1, 'notify' => false ) ) );
check( 'revoke notify=false: no mail, child discarded', array( $res->status, $res->data['sent'], $res->data['captains'], splm_susp_state()->mails, splm_susp_state()->inserted[0]['status'] ), array( 200, false, array(), array(), 'discarded' ) );
check( 'revoke notify=false: parent revoked, no notice lock', array( splm_susp_state()->rows[1]->status, splm_susp_state()->calls ), array( 'revoked', array( 'lock:splm_discipline_notice_1', 'children_of', 'insert' ) ) );

foreach ( array( 'pending', 'failed' ) as $status ) {
	reset_action();
	splm_susp_state()->rows[1]->status = $status;
	$res                               = $actions::revoke( action_request( array( 'id' => 1, 'notify' => true ) ) );
	check( "revoke $status draft: discarded, no child, no mail", array( $res->status, $res->data['notice']['status'], $res->data['sent'], $res->data['captains'], splm_susp_state()->inserted, splm_susp_state()->mails, splm_susp_state()->updates ), array( 200, 'discarded', false, array(), array(), array(), array( array( 1, array( 'status' => 'discarded' ) ) ) ) );
}
reset_action();
splm_susp_state()->rows[1]->status = 'revoked';
$again = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke twice is a 409', array( $again->code, $again->data['status'], splm_susp_state()->inserted ), array( 'splm_suspension_not_revocable', 409, array() ) );
reset_action();
splm_susp_state()->next_id = 0;
$bad = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke insert failure is a 500 and leaves the parent untouched', array( $bad->code, $bad->data['status'], splm_susp_state()->rows[1]->status, splm_susp_state()->updates ), array( 'splm_notice_write_failed', 500, 'sent', array() ) );
check( 'revoke a missing notice is a 404', $actions::revoke( action_request( array( 'id' => 77 ) ) )->data['status'], 404 );
splm_susp_state()->busy_prefix = 'splm_discipline_notice_';
check( 'revoke busy lock is a 409', $actions::revoke( action_request( array( 'id' => 1 ) ) )->code, 'splm_notice_busy' );

// Recalculate: only eligible_on moves, nothing is mailed.
reset_action();
splm_susp_state()->rows[1]->incident_event_id = 31;
splm_susp_state()->rows[1]->eligible_on       = '2026-09-01';
$res = $actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'recalculate: 200, not sent, row returned', array( $res->status, $res->data['sent'], $res->data['captains'], $res->data['notice']['id'] ), array( 200, false, array(), 1 ) );
check( 'recalculate updates ONLY eligible_on', splm_susp_state()->updates, array( array( 1, array( 'eligible_on' => '2026-10-17' ) ) ) );
check( 'recalculate counts from the incident date and sends nothing', array( splm_susp_state()->elig_args, splm_susp_state()->mails, splm_susp_state()->inserted ), array( array( array( 11, 5, '2026-10-03', 3 ) ), array(), array() ) );
reset_action();
splm_susp_state()->elig = elig( array( 'date' => null, 'remaining' => 2 ) );
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'recalculate with no schedule clears the date', splm_susp_state()->updates, array( array( 1, array( 'eligible_on' => null ) ) ) );
reset_action();
splm_susp_state()->rows[1]->status = 'failed';
check( 'recalculate a failed row is a 409', $actions::recalculate( action_request( array( 'id' => 1 ) ) )->code, 'splm_suspension_not_recalculable' );
splm_susp_state()->busy_prefix = 'splm_discipline_notice_';
check( 'recalculate busy lock is a 409', $actions::recalculate( action_request( array( 'id' => 1 ) ) )->code, 'splm_notice_busy' );

// Chains: only the newest row of a chain can be revoked or recalculated.
reset_action();
$actions::amend( action_request( array( 'id' => 1, 'games' => 2 ) ) );
$before = count( splm_susp_state()->writes );
$sup    = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke of an amended parent is a 409', array( $sup->code, $sup->data['status'], count( splm_susp_state()->writes ) ), array( 'splm_suspension_superseded', 409, $before ) );
$sup = $actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'recalculate of an amended parent is a 409', $sup->code, 'splm_suspension_superseded' );
$ok = $actions::revoke( action_request( array( 'id' => 100 ) ) );
check( 'revoking the newest row works', array( $ok->status, $ok->data['notice']['id'], $ok->data['notice']['status'], splm_susp_state()->inserted[1]['scope'], splm_susp_state()->inserted[1]['parent_id'] ), array( 200, 100, 'revoked', 'manual-revoked', 100 ) );

reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
splm_susp_state()->rows[1]->games   = 0;
$actions::decide( action_request( array( 'id' => 1, 'games' => 3 ) ) );
$sup = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke of a decided parent is a 409', array( $sup->code, $sup->data['status'] ), array( 'splm_suspension_superseded', 409 ) );
check( 'revoking the decided child works', $actions::revoke( action_request( array( 'id' => 100 ) ) )->status, 200 );

// A revoke's correction row is never acted on as a suspension.
foreach ( array( 'games', 'indefinite' ) as $outcome ) {
	reset_action();
	splm_susp_state()->rows[5]          = build_manual_row( 'manual-revoked', 5 );
	splm_susp_state()->rows[5]->status  = 'sent';
	splm_susp_state()->rows[5]->consequence = 'none';
	splm_susp_state()->rows[5]->outcome = $outcome;
	$results = array(
		$actions::decide( action_request( array( 'id' => 5, 'games' => 2 ) ) )->code,
		$actions::amend( action_request( array( 'id' => 5, 'games' => 1 ) ) )->code,
		$actions::revoke( action_request( array( 'id' => 5 ) ) )->code,
		$actions::recalculate( action_request( array( 'id' => 5 ) ) )->code,
	);
	check( "every action rejects a correction row ($outcome)", array( $results, splm_susp_state()->inserted, splm_susp_state()->updates ), array( array_fill( 0, 4, 'splm_suspension_not_a_suspension' ), array(), array() ) );
}

// A failed child still blocks; a discarded one does not.
reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
splm_susp_state()->history          = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'failed' ) );
check( 'a failed child blocks a second decision', $actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) )->code, 'splm_suspension_already_decided' );
reset_action();
splm_susp_state()->history = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'failed' ) );
check( 'a failed child blocks an amend', $actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) )->code, 'splm_suspension_already_amended' );
reset_action();
splm_susp_state()->history = array( (object) array( 'id' => 50, 'parent_id' => 1, 'status' => 'discarded' ) );
check( 'a discarded child does not block an amend', $actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) )->status, 200 );

// Decided rows count from the decision date, in site-local time.
reset_action();
splm_susp_state()->rows[1]                    = parent_row( array( 'scope' => 'manual-decided', 'incident_event_id' => 31, 'created_at' => '2026-09-20 15:00:00' ) );
splm_susp_state()->rows[1]->eligible_on       = '2026-10-17';
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'recalculate of a decided row counts from the decision, not the incident', splm_susp_state()->elig_args[0][2], '2026-09-20' );
reset_action();
splm_susp_state()->rows[1] = parent_row( array( 'scope' => 'manual-decided', 'incident_event_id' => 31, 'created_at' => '2026-09-20 15:00:00' ) );
$actions::amend( action_request( array( 'id' => 1, 'games' => 1 ) ) );
check( 'amend of a decided row counts from the decision date', splm_susp_state()->elig_args[0][2], '2026-09-20' );
reset_action();
splm_susp_state()->rows[1] = parent_row( array( 'scope' => 'manual-decided', 'incident_event_id' => 31, 'created_at' => '2026-09-20 15:00:00' ) );
$release::release_row( (object) array_merge( (array) splm_susp_state()->rows[1], array( 'status' => 'pending' ) ) );
check( 'a retried release of a decided row counts from the decision date', splm_susp_state()->elig_args[0][2], '2026-09-20' );
reset_action();
splm_susp_state()->rows[20]            = build_manual_row( 'manual-decided', 20 );
splm_susp_state()->rows[21]            = build_manual_row( 'manual-amended', 21 );
splm_susp_state()->rows[21]->parent_id = 20;
splm_susp_state()->rows[21]->status    = 'pending';
splm_susp_state()->rows[21]->incident_event_id = 31;
$release::release_row( splm_susp_state()->rows[21] );
check( 'an amended decision releases from the decision date', splm_susp_state()->elig_args[0][2], '2026-09-20' );
reset_action();
splm_susp_state()->rows[1]->outcome = 'indefinite';
$actions::decide( action_request( array( 'id' => 1, 'games' => 2 ) ) );
check( 'a new decision counts from today (decision date is today)', splm_susp_state()->elig_args[0][2], '2026-10-01' );

// A parent update that fails after the child insert discards the child and sends nothing.
reset_action();
splm_susp_state()->fail_update = array( 1 );
$bad = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke: parent update failure is a 500, child discarded, nothing mailed', array( $bad->code, $bad->data['status'], splm_susp_state()->rows[100]->status, splm_susp_state()->mails ), array( 'splm_notice_write_failed', 500, 'discarded', array() ) );
reset_action();
splm_susp_state()->rows[1]->status = 'pending';
splm_susp_state()->fail_update     = array( 1 );
$bad = $actions::revoke( action_request( array( 'id' => 1 ) ) );
check( 'revoke of a draft: update failure is a 500', array( $bad->code, $bad->data['status'], splm_susp_state()->rows[1]->status ), array( 'splm_notice_write_failed', 500, 'pending' ) );
reset_action();
splm_susp_state()->fail_update = array( 1 );
$bad = $actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'recalculate: update failure is a 500', array( $bad->code, $bad->data['status'] ), array( 'splm_notice_write_failed', 500 ) );

// A no-incident suspension keeps the date it was issued on, however often it is amended or recalculated.
function noinc_parent( $over = array() ) {
	return parent_row( array_merge( array( 'incident_event_id' => 0, 'sent_at' => '2026-09-12 14:00:00', 'created_at' => '2026-09-12 13:00:00' ), $over ) );
}
check( 'pure: no incident counts from the issued date', $release::count_from_date( 'manual', '', '', '2026-09-12', '2026-10-01' ), '2026-09-12' );
check( 'pure: the incident date wins over the issued date', $release::count_from_date( 'manual', '2026-09-01', '', '2026-09-12', '2026-10-01' ), '2026-09-01' );
check( 'pure: amended without an incident counts from the issued date', $release::count_from_date( 'manual-amended', '', '', '2026-09-12', '2026-10-01' ), '2026-09-12' );
check( 'pure: nothing known counts from today', $release::count_from_date( 'manual', '', '', '', '2026-10-01' ), '2026-10-01' );
check( 'pure: a decision ignores the issued date', $release::count_from_date( 'manual-decided', '', '2026-09-20', '2026-09-12', '2026-10-01' ), '2026-09-20' );

reset_action();
splm_susp_state()->rows[1] = noinc_parent();
$actions::amend( action_request( array( 'id' => 1, 'games' => 2 ) ) );
check( 'amend 19 days later keeps the original count-from date', splm_susp_state()->elig_args[0][2], '2026-09-12' );

reset_action();
splm_susp_state()->rows[1] = noinc_parent();
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'repeated recalculate does not drift', array( splm_susp_state()->elig_args[0][2], splm_susp_state()->elig_args[1][2] ), array( '2026-09-12', '2026-09-12' ) );

reset_action();
splm_susp_state()->rows[1] = noinc_parent( array( 'incident_event_id' => 99 ) );
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'a trashed or invalid incident event behaves like no incident', splm_susp_state()->elig_args[0][2], '2026-09-12' );

reset_action();
splm_susp_state()->rows[1] = noinc_parent( array( 'sent_at' => null ) );
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'without sent_at the created_at date is used', splm_susp_state()->elig_args[0][2], '2026-09-12' );

reset_action();
splm_susp_state()->rows[1] = noinc_parent( array( 'incident_event_id' => 31 ) );
$actions::recalculate( action_request( array( 'id' => 1 ) ) );
check( 'a valid incident still counts from the incident date', splm_susp_state()->elig_args[0][2], '2026-10-03' );

reset_action();
splm_susp_state()->rows[1]                    = noinc_parent();
splm_susp_state()->rows[2]                    = parent_row( array( 'id' => 2, 'scope' => 'manual-amended', 'parent_id' => 1, 'incident_event_id' => 0, 'sent_at' => '2026-09-25 10:00:00', 'created_at' => '2026-09-25 09:00:00' ) );
$actions::recalculate( action_request( array( 'id' => 2 ) ) );
check( 'an amended row follows the chain to the root issued row', splm_susp_state()->elig_args[0][2], '2026-09-12' );

// Releasing a draft recomputes eligible_on first, and the email carries the same date.
reset_action();
splm_susp_state()->rows[7]                = build_manual_row( 'manual', 7 );
splm_susp_state()->rows[7]->eligible_on   = '2026-01-01';
splm_susp_state()->rows[7]->created_at    = '2026-09-12 13:00:00';
splm_susp_state()->rows[7]->incident_event_id = 0;
$res = $release::release_row( splm_susp_state()->rows[7] );
check( 'a no-incident draft counts from today at release', splm_susp_state()->elig_args[0][2], '2026-10-01' );
check( 'release persists only eligible_on, before any mail', array( splm_susp_state()->writes[0], splm_susp_state()->updates[0] ), array( 'update:7:eligible_on', array( 7, array( 'eligible_on' => '2026-10-17' ) ) ) );
check( 'the email carries the recomputed date, not the stale one', array( false !== strpos( splm_susp_state()->mails[0]['body'], '2026-10-17' ), false === strpos( splm_susp_state()->mails[0]['body'], '2026-01-01' ) ), array( true, true ) );
check( 'release still succeeds', $res->status, 200 );

reset_action();
splm_susp_state()->rows[7]       = build_manual_row( 'manual', 7 );
splm_susp_state()->fail_update   = array( 7 );
$bad = $release::release_row( splm_susp_state()->rows[7] );
check( 'a failed eligibility write is a 500 and nothing is mailed', array( $bad->code, $bad->data['status'], splm_susp_state()->mails ), array( 'splm_notice_write_failed', 500, array() ) );

reset_action();
splm_susp_state()->rows[7]          = build_manual_row( 'manual', 7 );
splm_susp_state()->rows[7]->outcome = 'indefinite';
splm_susp_state()->rows[7]->games   = 0;
$release::release_row( splm_susp_state()->rows[7] );
check( 'an indefinite draft computes and writes no eligibility', array( splm_susp_state()->elig_args, in_array( 'update:7:eligible_on', splm_susp_state()->writes, true ) ), array( array(), false ) );

// No incident_note in any mail built by the action routes.
reset_action();
splm_susp_state()->rows[1]->incident_event_id = 31;
$actions::amend( action_request( array( 'id' => 1, 'games' => 2 ) ) );
$actions::revoke( action_request( array( 'id' => 100 ) ) );
$leak = false;
foreach ( splm_susp_state()->mails as $mail ) {
	$leak = $leak || false !== strpos( $mail['subject'] . $mail['body'], 'SECRET-NOTE' );
}
check( 'the action mails were sent (amend, then the revoke of its child)', count( splm_susp_state()->mails ), 4 );
check( 'the incident note is in no action mail', $leak, false );
foreach ( array( 'decide', 'amend', 'revoke', 'recalculate', 'revoke_sent', 'decide_locked', 'amend_locked', 'revoke_locked', 'recalculate_locked', 'issue_child', 'deliver_child', 'action_response' ) as $name ) {
	check( "$name never mentions incident_note", false === strpos( method_source( 'SPLM_Discipline_Suspension_Actions', $name ), 'incident_note' ), true );
}

$state = splm_susp_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures ? 1 : 0 );
