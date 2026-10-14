<?php
/**
 * Standalone tests for SPLM_Discipline_Notice_Labels: the pure label helpers
 * behind the technical queue, and the bulk-release predicate.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS).
 */
class SPLM_Labels_Test_State {
	public $total    = 0;
	public $failures = 0;
}

function splm_labels_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Labels_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_labels_state();
	++$state->total;
	if ( $actual === $expected ) {
		echo "PASS: $label\n";
		return;
	}
	++$state->failures;
	echo "FAIL: $label\n  expected: " . var_export( $expected, true ) . "\n  actual:   " . var_export( $actual, true ) . "\n";
}

function __( $text ) {
	return $text;
}
function _n( $single, $plural, $n ) {
	return 1 === (int) $n ? $single : $plural;
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-notice-labels.php';

$labels = 'SPLM_Discipline_Notice_Labels';

$auto   = array(
	'source'         => 'auto',
	'scope'          => 'season',
	'consequence'    => 'suspend',
	'games'          => 2,
	'status'         => 'pending',
	'value_at_fire'  => 30,
	'season_at_fire' => 45,
);
$legacy = array(
	'consequence'    => 'warn',
	'games'          => 0,
	'status'         => 'failed',
	'value_at_fire'  => 10,
	'season_at_fire' => 12,
);
$manual = array(
	'source'           => 'manual',
	'scope'            => 'manual',
	'consequence'      => 'suspend',
	'outcome'          => 'games',
	'games'            => 2,
	'status'           => 'pending',
	'infraction_title' => 'Fighting (first offence)',
	'rule_ref'         => 'Rule 6.5',
);

// kind_label.
check( 'auto kind is empty', $labels::kind_label( $auto ), '' );
check( 'legacy kind is empty', $labels::kind_label( $legacy ), '' );
check( 'manual issued', $labels::kind_label( $manual ), 'Manual — issued' );
check( 'manual decision', $labels::kind_label( array_merge( $manual, array( 'scope' => 'manual-decided' ) ) ), 'Manual — decision' );
check( 'manual amended', $labels::kind_label( array_merge( $manual, array( 'scope' => 'manual-amended' ) ) ), 'Manual — amended' );
check( 'manual withdrawn', $labels::kind_label( array_merge( $manual, array( 'scope' => 'manual-revoked' ) ) ), 'Manual — withdrawn' );
check( 'unknown manual scope', $labels::kind_label( array_merge( $manual, array( 'scope' => 'weird' ) ) ), 'Manual' );
check( 'manual with no scope', $labels::kind_label( array( 'source' => 'manual' ) ), 'Manual' );

// consequence_text.
check( 'auto consequence keeps the raw wording', $labels::consequence_text( $auto ), 'suspend (2)' );
check( 'auto zero games has no suffix', $labels::consequence_text( array_merge( $auto, array( 'games' => 0, 'consequence' => 'warn' ) ) ), 'warn' );
check( 'legacy consequence', $labels::consequence_text( $legacy ), 'warn' );
check( 'manual warn', $labels::consequence_text( array_merge( $manual, array( 'consequence' => 'warn' ) ) ), 'Warning' );
check( 'manual 2 games', $labels::consequence_text( $manual ), 'Suspension — 2 games' );
check( 'manual 1 game', $labels::consequence_text( array_merge( $manual, array( 'games' => 1 ) ) ), 'Suspension — 1 game' );
check( 'manual indefinite', $labels::consequence_text( array_merge( $manual, array( 'outcome' => 'indefinite', 'games' => 0 ) ) ), 'Suspension — indefinite' );
check( 'manual balance of the game', $labels::consequence_text( array_merge( $manual, array( 'games' => 0 ) ) ), 'Balance of the game' );
check( 'manual correction', $labels::consequence_text( array_merge( $manual, array( 'consequence' => 'none', 'games' => 0 ) ) ), 'Correction (notice withdrawn)' );
check( 'manual suspend missing outcome and games', $labels::consequence_text( array( 'source' => 'manual', 'consequence' => 'suspend' ) ), 'Balance of the game' );

// penalty_text.
check( 'auto penalty', $labels::penalty_text( $auto ), '30 / 45' );
check( 'legacy penalty', $labels::penalty_text( $legacy ), '10 / 12' );
check( 'empty row penalty unchanged', $labels::penalty_text( array() ), '0 / 0' );
check( 'manual penalty is a dash', $labels::penalty_text( $manual ), '—' );

// infraction_text.
check( 'infraction with rule', $labels::infraction_text( $manual ), 'Fighting (first offence) — Rule 6.5' );
check( 'infraction without rule', $labels::infraction_text( array_merge( $manual, array( 'rule_ref' => '' ) ) ), 'Fighting (first offence)' );
check( 'infraction empty title', $labels::infraction_text( array_merge( $manual, array( 'infraction_title' => '' ) ) ), '' );
check( 'infraction on an auto row', $labels::infraction_text( $auto ), '' );
check( 'infraction on a legacy row', $labels::infraction_text( $legacy ), '' );
check( 'infraction manual missing keys', $labels::infraction_text( array( 'source' => 'manual' ) ), '' );

// is_bulk_releasable.
check( 'auto pending', $labels::is_bulk_releasable( $auto ), true );
check( 'auto failed', $labels::is_bulk_releasable( array_merge( $auto, array( 'status' => 'failed' ) ) ), true );
check( 'auto sent', $labels::is_bulk_releasable( array_merge( $auto, array( 'status' => 'sent' ) ) ), false );
check( 'auto baseline', $labels::is_bulk_releasable( array_merge( $auto, array( 'status' => 'baseline' ) ) ), false );
check( 'legacy failed counts as auto', $labels::is_bulk_releasable( $legacy ), true );
check( 'manual pending is excluded', $labels::is_bulk_releasable( $manual ), false );
check( 'manual failed is excluded', $labels::is_bulk_releasable( array_merge( $manual, array( 'status' => 'failed' ) ) ), false );
check( 'row with no status', $labels::is_bulk_releasable( array() ), false );

// Hostile row: the private note never reaches any helper output.
$hostile = array_merge( $manual, array( 'incident_note' => 'SECRET-NOTE' ) );
$out     = implode(
	'|',
	array(
		$labels::kind_label( $hostile ),
		$labels::consequence_text( $hostile ),
		$labels::penalty_text( $hostile ),
		$labels::infraction_text( $hostile ),
	)
);
check( 'incident_note never appears in helper output', false === strpos( $out, 'SECRET-NOTE' ), true );
$source = file_get_contents( dirname( __DIR__ ) . '/includes/class-discipline-notice-labels.php' );
check( 'the labels class never mentions incident_note', false === strpos( $source, 'incident_note' ), true );

$state = splm_labels_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures ? 1 : 0 );
