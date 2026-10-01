<?php
/**
 * Standalone tests for SPLM_Discipline_Player_Metabox: registration gating and
 * the pure row renderer behind the read-only discipline record box.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS).
 */
class SPLM_Metabox_Test_State {
	public $options  = array();
	public $can      = false;
	public $boxes    = array();
	public $total    = 0;
	public $failures = 0;
}

function splm_metabox_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Metabox_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_metabox_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function has( $haystack, $needle ) {
	return false !== strpos( $haystack, $needle );
}

function __( $text ) {
	return $text;
}
function _n( $single, $plural, $n ) {
	return 1 === (int) $n ? $single : $plural;
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function add_action() {}
function esc_html__( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function get_the_title() {
	return 'Player';
}
function current_user_can() {
	return splm_metabox_state()->can;
}
function get_option( $name, $default = false ) {
	$options = splm_metabox_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}
// Site timezone stub: five hours behind UTC.
function get_date_from_gmt( $utc ) {
	return gmdate( 'Y-m-d H:i:s', strtotime( $utc . ' UTC' ) - 5 * 3600 );
}
function mysql2date( $format, $date ) {
	return gmdate( $format, strtotime( $date . ' UTC' ) );
}
function add_meta_box( $id, $title, $callback, $screen, $context, $priority ) {
	splm_metabox_state()->boxes[] = array( $id, $title, $screen, $context, $priority, $callback );
}

require_once dirname( __DIR__ ) . '/includes/class-capabilities.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-notice-labels.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-notice-rest.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-player-metabox.php';

$box = 'SPLM_Discipline_Player_Metabox';

function splm_metabox_row( array $over = array() ) {
	return (object) array_merge(
		array(
			'id'               => 1,
			'player_id'        => 5,
			'season_id'        => 2,
			'tier_key'         => 'manual',
			'ack_key'          => 'manual:1',
			'scope'            => 'manual',
			'severity'         => 'critical',
			'consequence'      => 'suspend',
			'games'            => 2,
			'value_at_fire'    => 0,
			'season_at_fire'   => 0,
			'team'             => 'Wolves',
			'division'         => 'A',
			'status'           => 'sent',
			'recipient'        => '',
			'recipient_via'    => '',
			'bcc'              => '',
			'sent_at'          => '2026-03-01 02:00:00',
			'served_at'        => '',
			'released_by'      => 0,
			'last_error'       => '',
			'note'             => '',
			'created_at'       => '2026-02-20 12:00:00',
			'source'           => 'manual',
			'infraction_title' => 'Fighting',
			'rule_ref'         => '6.5',
			'outcome'          => 'games',
			'incident_note'    => '',
			'eligible_on'      => '2026-03-10',
			'parent_id'        => 0,
		),
		$over
	);
}

echo "\n=== registration ===\n\n";
$state = splm_metabox_state();
$inst  = new $box();
$inst->add_meta_box();
check( 'not registered without the capability', count( $state->boxes ), 0 );
$state->can = true;
$inst->add_meta_box();
check( 'not registered when the module is off', count( $state->boxes ), 0 );
$state->options['spat_enabled_modules'] = array( 'league_discipline' );
$inst->add_meta_box();
check( 'registered for conveners with the module on', count( $state->boxes ), 1 );
check( 'box id and title', array( $state->boxes[0][0], $state->boxes[0][1] ), array( 'splm_discipline_record', 'Discipline record (conveners only)' ) );
check( 'screen, context and priority mirror Player Notes', array_slice( $state->boxes[0], 2, 3 ), array( 'sp_player', 'normal', 'default' ) );

echo "\n=== render_rows_html() ===\n\n";
check( 'no rows is the empty state', $box::render_rows_html( array() ), '<p>No disciplinary record.</p>' );

$html = $box::render_rows_html( array( splm_metabox_row() ) );
check( 'date comes from sent_at in site time', has( $html, '<td>2026-02-28</td>' ), true );
check( 'consequence cell', has( $html, '<td>Suspension — 2 games</td>' ), true );
check( 'infraction cell', has( $html, '<td>Fighting — 6.5</td>' ), true );
check( 'status label', has( $html, '<td>Sent</td>' ), true );
check( 'eligible date is a plain local date', has( $html, '<td>2026-03-10</td>' ), true );
check( 'no private note line without a note', has( $html, 'Private note' ), false );

$created = $box::render_rows_html( array( splm_metabox_row( array( 'sent_at' => null, 'status' => 'pending' ) ) ) );
check( 'date falls back to created_at', has( $created, '<td>2026-02-20</td>' ), true );
check( 'pending reads Waiting for release', has( $created, '<td>Waiting for release</td>' ), true );

$no_eligible = $box::render_rows_html( array( splm_metabox_row( array( 'eligible_on' => null ) ) ) );
check( 'missing eligible date is a dash', has( $no_eligible, '<td>—</td>' ), true );

$statuses = array(
	'pending'   => 'Waiting for release',
	'sent'      => 'Sent',
	'failed'    => 'Could not send',
	'discarded' => 'Discarded',
	'served'    => 'Served',
	'revoked'   => 'Withdrawn',
	'baseline'  => 'On record',
	'mystery'   => 'mystery',
);
foreach ( $statuses as $word => $label ) {
	check( "status $word", has( $box::render_rows_html( array( splm_metabox_row( array( 'status' => $word ) ) ) ), '<td>' . $label . '</td>' ), true );
}

$noted = $box::render_rows_html( array( splm_metabox_row( array( 'incident_note' => 'Hit from behind' ) ) ) );
check( 'private note line shown', has( $noted, 'Private note:</strong> Hit from behind' ), true );

$replaced = $box::render_rows_html( array( splm_metabox_row(), splm_metabox_row( array( 'id' => 2 ) ) ), array( 1 ) );
check( 'replaced row is classed', substr_count( $replaced, 'splm-disc-replaced' ), 1 );
check( 'replaced row says Replaced', has( $replaced, '<td>Sent (Replaced)</td>' ), true );
check( 'the other row is untouched', substr_count( $replaced, 'Replaced' ), 1 );

$legacy = (object) array(
	'id'             => 9,
	'player_id'      => 5,
	'season_id'      => 2,
	'tier_key'       => 'season',
	'ack_key'        => 'k',
	'scope'          => 'season',
	'severity'       => 'warn',
	'consequence'    => 'suspend',
	'games'          => 1,
	'value_at_fire'  => 30,
	'season_at_fire' => 45,
	'team'           => '',
	'division'       => '',
	'status'         => 'sent',
	'recipient'      => '',
	'recipient_via'  => '',
	'bcc'            => '',
	'sent_at'        => '2026-03-01 02:00:00',
	'served_at'      => '',
	'released_by'    => 0,
	'last_error'     => '',
	'note'           => '',
	'created_at'     => '2026-02-20 12:00:00',
);
$old = $box::render_rows_html( array( $legacy ) );
check( 'legacy automatic row renders its consequence', has( $old, '<td>suspend (1)</td>' ), true );
check( 'legacy row has a blank infraction and a dash for eligible', has( $old, '<td></td><td>Sent</td><td>—</td>' ), true );
check( 'legacy row shows no private note', has( $old, 'Private note' ), false );

$evil    = '"><script>alert(1)</script>';
$hostile = $box::render_rows_html(
	array(
		splm_metabox_row(
			array(
				'infraction_title' => $evil,
				'rule_ref'         => '<i>',
				'incident_note'    => $evil,
				'status'           => '<b>x</b>',
			)
		),
		splm_metabox_row(
			array(
				'source'      => 'auto',
				'consequence' => $evil,
			)
		),
	)
);
check( 'hostile strings never produce a tag', has( $hostile, '<script' ) || has( $hostile, '<i>' ) || has( $hostile, '<b>' ), false );
check( 'hostile strings are present escaped', has( $hostile, '&lt;script&gt;alert(1)&lt;/script&gt;' ), true );
check( 'hostile note is escaped', has( $hostile, 'Private note:</strong> &quot;&gt;&lt;script&gt;' ), true );

$state = splm_metabox_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures > 0 ? 1 : 0 );
