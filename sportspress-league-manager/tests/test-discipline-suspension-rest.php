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
	public $failures   = 0;
	public $total      = 0;
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
	);
	return $titles[ (int) $id ] ?? '';
}
function get_term( $id ) {
	return 5 === (int) $id ? (object) array( 'name' => 'Winter' ) : null;
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
	public static function for_player( $player_id, $include_baseline = false ) {
		return $include_baseline ? array_merge( splm_susp_state()->history, array( (object) array( 'id' => 99, 'status' => 'baseline', 'consequence' => 'none', 'season_id' => 5 ) ) ) : splm_susp_state()->history;
	}
	public static function find( $id ) {
		return splm_susp_state()->rows[ (int) $id ] ?? null;
	}
	public static function insert( array $row ) {
		$state = splm_susp_state();
		if ( ! $state->writable ) {
			throw new RuntimeException( 'preview must not insert' );
		}
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
		return $state->lock_busy ? false : $fn();
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
		return array( 'conv@example.com' );
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

$rest = 'SPLM_Discipline_Suspension_REST';

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
check( 'route list', array_keys( $routes ), array( '/discipline/infractions', '/discipline/history', '/discipline/suspensions/preview', '/discipline/suspensions' ) );
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
	check( "release_kind $scope", $rest::release_kind( (object) array( 'scope' => $scope ) ), $kind );
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
$ok = $rest::release_row( splm_susp_state()->rows[1] );
check( 'release_row success shape', array( $ok->status, $ok->data ), array( 200, array( 'success' => true, 'id' => 1, 'status' => 'sent' ) ) );
check( 'release_row used the amended kind in the subject', splm_susp_state()->mails[0]['subject'], 'Updated suspension notice — Winter' );
$again = $rest::release_row( splm_susp_state()->rows[1] );
check( 'release_row on a sent row is a 409', array( $again->code, $again->data['status'] ), array( 'splm_notice_not_releasable', 409 ) );
check( 'release_row did not re-mail', count( splm_susp_state()->mails ), 2 );
splm_susp_state()->mail_ok = false;
splm_susp_state()->rows[2] = build_manual_row( 'manual', 2 );
$err = $rest::release_row( splm_susp_state()->rows[2] );
check( 'release_row failure carries last_error', array( $err->code, $err->data['status'], $err->message ), array( 'splm_notice_send_failed', 500, 'wp_mail() rejected the message.' ) );

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
foreach ( array( 'finish_create', 'release_row', 'release_extra', 'release_response', 'preview_response', 'team_names' ) as $name ) {
	check( "$name never mentions incident_note", false === strpos( method_source( 'SPLM_Discipline_Suspension_REST', $name ), 'incident_note' ), true );
}
check( 'create_locked copies the note only into the row input', 2 === substr_count( method_source( 'SPLM_Discipline_Suspension_REST', 'create_locked' ), 'incident_note' ), true );
check( 'for_row names incident_note only inside its unset guard', false === strpos( preg_replace( '/unset\([^;]*\);/', '', method_source( 'SPLM_Discipline_Suspension_Context', 'for_row' ) ), 'incident_note' ), true );

$state = splm_susp_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures ? 1 : 0 );
