<?php
/**
 * Standalone tests for the manual-suspension email context builder.
 *
 * The central guarantee: the convener's incident note never reaches the
 * context, whatever the row holds.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS; see
 * test-discipline-notice-recipients.php).
 */
class SPLM_Context_Test_State {
	public $options = array();
}

function splm_context_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Context_Test_State();
	}
	return $state;
}

function get_option( $name, $default = false ) {
	$options = array_merge(
		array(
			'date_format' => 'F j, Y',
			'time_format' => 'g:i a',
		),
		splm_context_test_state()->options
	);
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function get_the_title( $id ) {
	$titles = array(
		11 => 'Jane &amp; &#039;JJ&#039; Doe',
		21 => 'Red &amp; Blue',
	);
	return $titles[ (int) $id ] ?? '';
}

function get_term( $id ) {
	return 5 === (int) $id ? (object) array( 'name' => 'Winter &amp; Spring' ) : null;
}

function get_post( $id ) {
	return 31 === (int) $id ? (object) array(
		'post_title' => 'Red &amp; Blue vs Green',
		'post_date'  => '2026-10-03 21:15:00',
	) : null;
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

function is_wp_error( $v ) {
	return $v instanceof stdClass && isset( $v->is_error );
}

function is_email( $e ) {
	return false !== strpos( $e, '@' ) ? $e : false;
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-context.php';

$failures = 0;
$total    = 0;

function check( $label, $actual, $expected ) {
	global $failures, $total;
	++$total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function make_row( $over = array() ) {
	return (object) array_merge(
		array(
			'player_id'         => 11,
			'season_id'         => 5,
			'scope'             => 'manual',
			'rule_ref'          => '12.3',
			'infraction_title'  => 'Fighting',
			'rule_text'         => 'No fighting.',
			'outcome'           => 'games',
			'games'             => 3,
			'incident_event_id' => 31,
			'eligible_on'       => '2026-10-17',
			'incident_note'     => 'SECRET convener note',
			'note'              => 'SECRET other note',
		),
		$over
	);
}

// kind_for_scope.
check( 'scope manual', SPLM_Discipline_Suspension_Context::kind_for_scope( 'manual' ), 'issued' );
check( 'scope decided', SPLM_Discipline_Suspension_Context::kind_for_scope( 'manual-decided' ), 'decided' );
check( 'scope amended', SPLM_Discipline_Suspension_Context::kind_for_scope( 'manual-amended' ), 'amended' );
check( 'scope revoked', SPLM_Discipline_Suspension_Context::kind_for_scope( 'manual-revoked' ), 'revoked' );
check( 'scope other', SPLM_Discipline_Suspension_Context::kind_for_scope( 'whatever' ), 'issued' );

// format_label.
check( 'label both', SPLM_Discipline_Suspension_Context::format_label( 'A vs B', 'Oct 3' ), 'A vs B — Oct 3' );
check( 'label title only', SPLM_Discipline_Suspension_Context::format_label( 'A vs B', '' ), 'A vs B' );
check( 'label when only', SPLM_Discipline_Suspension_Context::format_label( '', 'Oct 3' ), 'Oct 3' );
check( 'label neither', SPLM_Discipline_Suspension_Context::format_label( '', '' ), '' );

// eligible_label.
check( 'eligible null', SPLM_Discipline_Suspension_Context::eligible_label( null, 'games' ), '' );
check( 'eligible empty', SPLM_Discipline_Suspension_Context::eligible_label( '', 'games' ), '' );
check( 'eligible indefinite', SPLM_Discipline_Suspension_Context::eligible_label( '2026-10-17', 'indefinite' ), '' );
check( 'eligible date', SPLM_Discipline_Suspension_Context::eligible_label( '2026-10-17', 'games' ), 'F j, Y#2026-10-17' );

// Options.
check( 'rulebook url default', SPLM_Discipline_Suspension_Context::rulebook_url(), 'https://www.rookiehockey.ca/wp-content/uploads/2024/10/RuleBook-Rev20241009.pdf' );
check( 'rulebook rev default', SPLM_Discipline_Suspension_Context::rulebook_rev(), 'Rev 20241009' );
check( 'contact empty when nothing set', SPLM_Discipline_Suspension_Context::contact(), '' );
splm_context_test_state()->options = array( 'admin_email' => 'admin@example.com' );
check( 'contact admin email', SPLM_Discipline_Suspension_Context::contact(), 'admin@example.com' );
splm_context_test_state()->options = array(
	'admin_email'                  => 'admin@example.com',
	'splm_discipline_notice_cc'    => 'bogus, conv@example.com, other@example.com',
	'splm_discipline_rulebook_url' => 'https://example.com/rb.pdf',
	'splm_discipline_rulebook_rev' => 'Rev X',
);
check( 'contact first valid cc', SPLM_Discipline_Suspension_Context::contact(), 'conv@example.com' );
check( 'rulebook url override', SPLM_Discipline_Suspension_Context::rulebook_url(), 'https://example.com/rb.pdf' );
check( 'rulebook rev override', SPLM_Discipline_Suspension_Context::rulebook_rev(), 'Rev X' );

// for_row.
$ctx      = SPLM_Discipline_Suspension_Context::for_row( make_row() );
$expected = array(
	'kind',
	'player_name',
	'season_name',
	'rule_ref',
	'infraction_title',
	'rule_text',
	'outcome',
	'games',
	'incident_label',
	'eligible_label',
	'projected',
	'remaining',
	'rulebook_url',
	'rulebook_rev',
	'contact',
);
check( 'allow-listed keys only', array_keys( $ctx ), $expected );
check( 'hostile note not copied', false !== strpos( wp_json_encode_stub( $ctx ), 'SECRET' ), false );
check( 'player decoded', $ctx['player_name'], "Jane & 'JJ' Doe" );
check( 'season decoded', $ctx['season_name'], 'Winter & Spring' );
check( 'incident label', $ctx['incident_label'], 'Red & Blue vs Green — F j, Y g:i a@2026-10-03 21:15:00' );
check( 'projected true', $ctx['projected'], true );
check( 'remaining default', $ctx['remaining'], 0 );
check( 'games int', $ctx['games'], 3 );

function wp_json_encode_stub( $v ) {
	return json_encode( $v );
}

$ind = SPLM_Discipline_Suspension_Context::for_row( make_row( array( 'outcome' => 'indefinite', 'games' => 0, 'scope' => 'manual-revoked' ) ) );
check( 'indefinite not projected', $ind['projected'], false );
check( 'indefinite no eligible label', $ind['eligible_label'], '' );
check( 'revoked kind', $ind['kind'], 'revoked' );

$none = SPLM_Discipline_Suspension_Context::for_row( make_row( array( 'eligible_on' => null, 'incident_event_id' => 0 ) ) );
check( 'null eligible label', $none['eligible_label'], '' );
check( 'null eligible not projected', $none['projected'], false );
check( 'no event label empty', $none['incident_label'], '' );

$extra = SPLM_Discipline_Suspension_Context::for_row( make_row(), array( 'prior_games' => 2, 'team_names' => array( 'Red & Blue' ), 'remaining' => 4 ) );
check( 'extra prior_games', $extra['prior_games'], 2 );
check( 'extra team_names', $extra['team_names'], array( 'Red & Blue' ) );
check( 'extra remaining overrides', $extra['remaining'], 4 );
check( 'extra keys last', array_slice( array_keys( $extra ), -2 ), array( 'prior_games', 'team_names' ) );

echo "\n$total checks, $failures failures\n";
exit( $failures ? 1 : 0 );
