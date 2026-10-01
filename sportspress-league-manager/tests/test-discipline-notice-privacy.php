<?php
/**
 * Standalone tests for the discipline notice privacy exporter/eraser field
 * lists: the erase column map and the export field list are pure, so they are
 * checked without a database.
 */

define( 'ABSPATH', __DIR__ );

if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( '_n' ) ) {
	function _n( $single, $plural, $number ) {
		return 1 === $number ? $single : $plural;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-notice-privacy.php';

class SPLM_Privacy_Test_Harness {
	public static $pass = 0;
	public static $fail = 0;

	public static function check( $cond, $label ) {
		if ( $cond ) {
			++self::$pass;
			echo "  PASS: $label\n";
		} else {
			++self::$fail;
			echo "  FAIL: $label\n";
		}
	}
}

function splm_privacy_row( array $over = array() ) {
	return (object) array_merge(
		array(
			'created_at'       => '2026-10-01 10:00:00',
			'tier_key'         => 'manual',
			'consequence'      => 'suspend',
			'games'            => 2,
			'value_at_fire'    => 0,
			'status'           => 'sent',
			'recipient'        => 'p@example.com',
			'sent_at'          => '2026-10-01 10:05:00',
			'infraction_title' => '',
			'rule_ref'         => '',
			'outcome'          => '',
			'eligible_on'      => '',
		),
		$over
	);
}

function splm_privacy_labels( array $fields ) {
	return array_column( $fields, 'name' );
}

echo "Eraser column map\n";
$map = SPLM_Discipline_Notice_Privacy::erase_assignments();
SPLM_Privacy_Test_Harness::check( array_key_exists( 'incident_note', $map ) && null === $map['incident_note'], 'incident_note is set to NULL' );
SPLM_Privacy_Test_Harness::check( array_key_exists( 'captains_notified', $map ) && null === $map['captains_notified'], 'captains_notified is set to NULL' );
SPLM_Privacy_Test_Harness::check( array_key_exists( 'bcc', $map ) && null === $map['bcc'], 'bcc still cleared' );
SPLM_Privacy_Test_Harness::check( array_key_exists( 'note', $map ) && null === $map['note'], 'note still cleared' );
SPLM_Privacy_Test_Harness::check( isset( $map['recipient'] ) && '' !== $map['recipient'], 'recipient still redacted' );
SPLM_Privacy_Test_Harness::check( 0 === ( $map['player_id'] ?? null ), 'player_id still zeroed' );
foreach ( array( 'id', 'status', 'created_at', 'rule_text', 'rule_ref', 'infraction_title', 'outcome', 'eligible_on', 'parent_id', 'source' ) as $kept ) {
	SPLM_Privacy_Test_Harness::check( ! array_key_exists( $kept, $map ), "$kept is not touched" );
}

echo "Export fields\n";
$base_labels = splm_privacy_labels( SPLM_Discipline_Notice_Privacy::export_fields( splm_privacy_row() ) );
SPLM_Privacy_Test_Harness::check(
	array( 'Recorded', 'Threshold', 'Consequence', 'Penalty minutes at the time', 'Status', 'Sent to', 'Sent at' ) === $base_labels,
	'empty optional fields: only the original seven, in order'
);

$full   = splm_privacy_row(
	array(
		'infraction_title' => 'Fighting',
		'rule_ref'         => '5.10.1',
		'outcome'          => 'suspended',
		'eligible_on'      => '2026-10-15',
	)
);
$fields = SPLM_Discipline_Notice_Privacy::export_fields( $full );
$labels = splm_privacy_labels( $fields );
SPLM_Privacy_Test_Harness::check( array_slice( $labels, 0, 7 ) === $base_labels, 'original fields keep their order' );
SPLM_Privacy_Test_Harness::check( array_slice( $labels, 7 ) === array( 'Infraction', 'Rule', 'Outcome', 'Projected eligible date' ), 'four new labels appended when set' );
$by_label = array_column( $fields, 'value', 'name' );
SPLM_Privacy_Test_Harness::check( 'Fighting' === $by_label['Infraction'] && '5.10.1' === $by_label['Rule'] && 'suspended' === $by_label['Outcome'] && '2026-10-15' === $by_label['Projected eligible date'], 'new fields carry the row values' );

$consequence = static function ( $value ) {
	return array_column( SPLM_Discipline_Notice_Privacy::export_fields( splm_privacy_row( array( 'consequence' => $value ) ) ), 'value', 'name' )['Consequence'];
};
SPLM_Privacy_Test_Harness::check( 'Correction (notice withdrawn)' === $consequence( 'none' ), 'a correction row is labelled a correction, not a warning' );
SPLM_Privacy_Test_Harness::check( 'Warning' === $consequence( 'warn' ), 'a warn row stays a warning' );
SPLM_Privacy_Test_Harness::check( 0 === strpos( $consequence( 'suspend' ), 'Suspension' ), 'a suspend row stays a suspension' );

$partial = splm_privacy_labels( SPLM_Discipline_Notice_Privacy::export_fields( splm_privacy_row( array( 'rule_ref' => '5.10.1' ) ) ) );
SPLM_Privacy_Test_Harness::check( in_array( 'Rule', $partial, true ) && ! in_array( 'Infraction', $partial, true ), 'only non-empty new fields appear' );

$hostile  = splm_privacy_row(
	array(
		'infraction_title'  => 'Fighting',
		'incident_note'     => 'convener-only text',
		'captains_notified' => 'cap@example.com',
	)
);
$exported = SPLM_Discipline_Notice_Privacy::export_fields( $hostile );
$flat     = wp_json_encode_stub( $exported );
SPLM_Privacy_Test_Harness::check( ! in_array( 'incident_note', splm_privacy_labels( $exported ), true ) && ! in_array( 'captains_notified', splm_privacy_labels( $exported ), true ), 'no incident_note / captains_notified label' );
SPLM_Privacy_Test_Harness::check( false === strpos( $flat, 'convener-only text' ) && false === strpos( $flat, 'cap@example.com' ), 'hostile row values never exported' );

echo "Legacy rows (new columns absent)\n";
$legacy = splm_privacy_row();
unset( $legacy->infraction_title, $legacy->rule_ref, $legacy->outcome, $legacy->eligible_on );
$legacy_labels = splm_privacy_labels( SPLM_Discipline_Notice_Privacy::export_fields( $legacy ) );
SPLM_Privacy_Test_Harness::check( $base_labels === $legacy_labels, 'legacy row exports only the original seven fields, no notices' );
SPLM_Privacy_Test_Harness::check( array() === array_intersect( array( 'Infraction', 'Rule', 'Outcome', 'Projected eligible date' ), $legacy_labels ), 'legacy row has none of the new labels' );

echo "Erase SET clause\n";
list( $set_sql, $set_values ) = SPLM_Discipline_Notice_Privacy::erase_set_clause();
SPLM_Privacy_Test_Harness::check( false !== strpos( $set_sql, 'incident_note = NULL' ), 'incident_note = NULL literal' );
SPLM_Privacy_Test_Harness::check( false !== strpos( $set_sql, 'captains_notified = NULL' ), 'captains_notified = NULL literal' );
SPLM_Privacy_Test_Harness::check( preg_match_all( '/%[sd]/', $set_sql ) === count( $set_values ), 'placeholder count equals value count' );
SPLM_Privacy_Test_Harness::check( false === strpos( $set_sql, 'bcc = %' ) && false === strpos( $set_sql, 'note = %' ), 'NULL columns are literals, not placeholders' );

function wp_json_encode_stub( $data ) {
	return json_encode( $data );
}

echo "\nPassed: " . SPLM_Privacy_Test_Harness::$pass . ', Failed: ' . SPLM_Privacy_Test_Harness::$fail . "\n";
exit( SPLM_Privacy_Test_Harness::$fail ? 1 : 0 );
