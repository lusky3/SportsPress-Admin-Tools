<?php
/**
 * Standalone tests for SPLM_Discipline_Incident_Games: the pure merge and the
 * per-team query that feeds the incident-match picker.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS).
 */
class SPLM_Inc_Games_Test_State {
	public $teams    = array();
	public $queries  = array();
	public $by_team  = array();
	public $total    = 0;
	public $failures = 0;
}

function splm_inc_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Inc_Games_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_inc_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function current_time() {
	return '2026-10-01';
}
function get_option() {
	return 'F j, Y';
}
function mysql2date( $format, $date ) {
	return $format . '@' . substr( $date, 0, 10 );
}
function wp_specialchars_decode( $s ) {
	return html_entity_decode( $s, ENT_QUOTES );
}
function get_posts( $args ) {
	$state            = splm_inc_state();
	$state->queries[] = $args;
	$team             = $args['meta_query'][0]['value'];
	return $state->by_team[ $team ] ?? array();
}
function get_post( $id ) {
	$posts = array(
		1 => array( 'Red &amp; Blue vs Green', '2026-09-20 20:00:00' ),
		2 => array( 'Red vs Green', '2026-09-27 20:00:00' ),
		3 => array( 'Red vs Gold', '2026-09-27 20:00:00' ),
	);
	return isset( $posts[ $id ] ) ? (object) array(
		'post_title' => $posts[ $id ][0],
		'post_date'  => $posts[ $id ][1],
	) : null;
}

class SPLM_Discipline_Eligibility {
	public static function player_team_ids() {
		return splm_inc_state()->teams;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-context.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-incident-games.php';

$games = 'SPLM_Discipline_Incident_Games';

function ev( $id, $date, $title = 't' ) {
	return array(
		'id'    => $id,
		'title' => $title,
		'date'  => $date,
	);
}

// merge_events: de-dup, order, cap.
$merged = $games::merge_events(
	array(
		array( ev( 1, '2026-09-20 20:00:00' ), ev( 2, '2026-09-27 20:00:00' ) ),
		array( ev( 2, '2026-09-27 20:00:00' ), ev( 3, '2026-09-27 20:00:00' ) ),
	),
	10
);
check( 'merge de-dups and orders date desc then id desc', array_column( $merged, 'id' ), array( 3, 2, 1 ) );
check( 'merge caps to the limit', array_column( $games::merge_events( array( array( ev( 1, '2026-09-20 20:00:00' ), ev( 2, '2026-09-27 20:00:00' ) ) ), 1 ), 'id' ), array( 2 ) );
check( 'merge of nothing', $games::merge_events( array(), 5 ), array() );

// for_player: no teams -> [] without querying.
check( 'no teams returns empty', $games::for_player( 11, 5, 40 ), array() );
check( 'no teams runs no query', splm_inc_state()->queries, array() );

// for_player with two teams.
splm_inc_state()->teams   = array( 21, 22 );
splm_inc_state()->by_team = array(
	21 => array( 1, 2 ),
	22 => array( 2, 3 ),
);
$out = $games::for_player( 11, 5, 40 );
check( 'two team queries', count( splm_inc_state()->queries ), 2 );
check( 'ids newest first', array_column( $out, 'id' ), array( 3, 2, 1 ) );
check(
	'item shape with decoded title and local date',
	$out[2],
	array(
		'id'    => 1,
		'title' => 'Red & Blue vs Green',
		'date'  => '2026-09-20',
		'label' => 'Red & Blue vs Green — F j, Y@2026-09-20',
	)
);

$q = splm_inc_state()->queries[0];
check( 'post type', $q['post_type'], 'sp_event' );
check( 'status publish and future', $q['post_status'], array( 'publish', 'future' ) );
check( 'per-team cap is the limit', $q['posts_per_page'], 40 );
check( 'ids only', $q['fields'], 'ids' );
check(
	'query is scoped to the season',
	$q['tax_query'] ?? null,
	array(
		array(
			'taxonomy'         => 'sp_season',
			'field'            => 'term_id',
			'terms'            => 5,
			'include_children' => false,
		),
	)
);
check( 'orderby date then id desc', $q['orderby'], array( 'date' => 'DESC', 'ID' => 'DESC' ) );
check( 'date_query up to end of today inclusive', $q['date_query'], array( array( 'before' => '2026-10-01 23:59:59', 'inclusive' => true ) ) );
check(
	'meta_query: team plus postponed/cancelled exclusion',
	$q['meta_query'],
	array(
		'relation' => 'AND',
		array(
			'key'   => 'sp_team',
			'value' => 21,
		),
		array(
			'relation' => 'OR',
			array(
				'key'     => 'sp_status',
				'value'   => array( 'postponed', 'cancelled' ),
				'compare' => 'NOT IN',
			),
			array(
				'key'     => 'sp_status',
				'compare' => 'NOT EXISTS',
			),
		),
	)
);
check( 'second team query targets team 22', splm_inc_state()->queries[1]['meta_query'][0]['value'], 22 );

$capped = $games::for_player( 11, 5, 2 );
check( 'result is capped to the limit', array_column( $capped, 'id' ), array( 3, 2 ) );

$state = splm_inc_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures ? 1 : 0 );
