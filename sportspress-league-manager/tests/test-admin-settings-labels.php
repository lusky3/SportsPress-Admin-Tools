<?php
/**
 * Guard: every League Manager settings field is programmatically labelled.
 *
 * Source scan of includes/class-admin.php. A single control must carry an id that
 * matches its row's label_for (add_field() defaults label_for to the field id); a
 * grouped control (radios, checkbox lists, tables) opts out with 'label_for' => ''
 * and must supply a fieldset legend or per-input aria-labels instead.
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

$source = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-admin.php' );

check( 'add_field defaults label_for to the field id', false !== strpos( $source, "\$args['label_for'] = \$id;" ), true );

// Option constants used as add_field ids, resolved to their string values.
$constants = array(
	'SPLM_Discipline_Notice::OPTION_MODE_WARNING'    => 'splm_discipline_notice_mode_warning',
	'SPLM_Discipline_Notice::OPTION_MODE_SUSPENSION' => 'splm_discipline_notice_mode_suspension',
	'SPLM_Waitlist_REST::SECRET_OPTION'              => 'splm_freescout_secret',
	'SPLM_Waitlist_Notify::OPTION_ENABLED'           => 'splm_waitlist_notify_enabled',
	'SPLM_Waitlist_Notify::OPTION'                   => 'splm_waitlist_notify_email',
	'SPLM_Waitlist_Notify::OPTION_EVENTS'            => 'splm_waitlist_notify_events',
);

preg_match_all( '/\$this->add_field\( ([^,]+),(.*)\n/', $source, $fields, PREG_SET_ORDER );
check( 'add_field calls were found', count( $fields ) > 20, true );

$grouped = array();
$single  = array();
foreach ( $fields as $f ) {
	$id = trim( $f[1], " '" );
	$id = $constants[ $id ] ?? $id;
	if ( false !== strpos( $f[2], "'label_for' => ''" ) ) {
		$grouped[] = $id;
	} else {
		$single[] = $id;
	}
}

check( 'grouped controls are the seven expected ones', count( $grouped ), 7 );

foreach ( $single as $id ) {
	$has_id = false !== strpos( $source, 'id="' . $id . '"' ) || false !== strpos( $source, "id=\"' . esc_attr( " ) && in_array( $id, $constants, true );
	check( "single control $id has a matching id", $has_id, true );
}

check( 'fee source radios sit in a fieldset with a legend', 1 === preg_match( '/<fieldset><legend[^>]*>.{0,120}Fee Integration Source/s', $source ), true );
check( 'stat checkbox lists take a legend', false !== strpos( $source, 'render_stat_checkboxes( $name, $selected, $legend )' ), true );
check( 'notice mode radios take a legend', false !== strpos( $source, 'render_notice_mode( string $option, string $description, string $legend )' ), true );
check( 'waitlist category checkboxes sit in a legended fieldset', 1 === preg_match( '/<fieldset><legend[^>]*>.{0,80}Categories/s', $source ), true );
check( 'threshold table inputs carry aria-labels', 3 <= substr_count( $source, 'aria-label=' ), true );

$state = splm_labels_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures > 0 ? 1 : 0 );
