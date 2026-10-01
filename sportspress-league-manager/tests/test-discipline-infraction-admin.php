<?php
/**
 * Standalone tests for the Discipline Rules tab's pure pieces: the row
 * builders, the rulebook setting sanitisers and the getters' empty-means-default.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state (a class rather than $GLOBALS).
 */
class SPLM_Inf_Admin_Test_State {
	public $options  = array();
	public $total    = 0;
	public $failures = 0;
}

function splm_inf_admin_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Inf_Admin_Test_State();
	}
	return $state;
}

function check( $label, $actual, $expected ) {
	$state = splm_inf_admin_state();
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

function add_action() {}
function __( $text ) {
	return $text;
}
function esc_html__( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr__( $text ) {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function esc_textarea( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES );
}
function sanitize_text_field( $v ) {
	return trim( strip_tags( (string) $v ) );
}
function esc_url_raw( $url, $protocols = null ) {
	$allowed = $protocols ? $protocols : array( 'http', 'https', 'ftp' );
	$scheme  = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
	return in_array( $scheme, $allowed, true ) ? $url : '';
}
function get_option( $name, $default = false ) {
	$options = splm_inf_admin_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

require_once dirname( __DIR__ ) . '/includes/class-discipline-infraction-admin.php';
require_once dirname( __DIR__ ) . '/includes/class-discipline-suspension-context.php';

$admin = 'SPLM_Discipline_Infraction_Admin';
$ctx   = 'SPLM_Discipline_Suspension_Context';

$row = array(
	'id'            => 7,
	'rule_ref'      => '6.5',
	'title'         => 'Fighting',
	'rule_text'     => 'No fighting.',
	'outcome'       => 'games',
	'default_games' => 3,
	'needs_review'  => 1,
	'active'        => 1,
	'sort_order'    => 70,
);

echo "\n=== render_row_html() ===\n\n";
$html = $admin::render_row_html( $row );
check( 'row is keyed by id', has( $html, 'data-id="7"' ), true );
foreach ( array( 'rule_ref' => 'Rule', 'title' => 'Title', 'rule_text' => 'Rulebook wording', 'outcome' => 'Outcome', 'default_games' => 'Default games', 'needs_review' => 'Needs review', 'active' => 'Active', 'sort_order' => 'Sort' ) as $field => $label ) {
	check( "label for $field", has( $html, '<label class="screen-reader-text" for="splm-inf-7-' . $field . '">' . $label . '</label>' ) && has( $html, 'id="splm-inf-7-' . $field . '"' ), true );
}
check( 'values rendered', has( $html, 'value="Fighting"' ) && has( $html, 'value="6.5"' ) && has( $html, '>No fighting.</textarea>' ), true );
check( 'games outcome selected', has( $html, '<option value="games" selected>' ) && ! has( $html, '<option value="indefinite" selected>' ), true );
check( 'needs_review and active checked', 2 === substr_count( $html, ' checked ' ), true );
check( 'default games enabled for games', has( $html, 'max="20" class="small-text" />' ), true );
check( 'Save button and live regions', has( $html, 'splm-inf-save' ) && has( $html, 'role="status"' ) && has( $html, 'role="alert"' ), true );
check( 'active row is not marked Retired', has( $html, '>Retired<' ), false );
check( 'enabled controls have no disabled attribute', has( $html, 'disabled' ), false );

$off = $admin::render_row_html( array_merge( $row, array( 'needs_review' => 0, 'active' => 0, 'outcome' => 'indefinite', 'default_games' => 0 ) ) );
check( 'indefinite outcome selected', has( $off, '<option value="indefinite" selected>' ), true );
check( 'default games disabled when indefinite', has( $off, 'class="small-text" disabled/>' ), true );
check( 'unchecked flags render unchecked', has( $off, ' checked ' ), false );
check( 'retired row says Retired in text', has( $off, '>Retired</span>' ), true );

$ro = $admin::render_row_html( $row, true );
check( 'readonly disables every control', 8 === substr_count( $ro, 'disabled' ), true );
check( 'readonly drops the Save button', has( $ro, 'splm-inf-save' ), false );

$evil = $admin::render_row_html( array_merge( $row, array( 'title' => '"><script>alert(1)</script>', 'rule_text' => '</textarea><script>x</script>', 'rule_ref' => '<b>' ) ) );
check( 'hostile strings are escaped', has( $evil, '<script' ), false );
check( 'hostile title is present escaped', has( $evil, '&lt;script&gt;alert(1)&lt;/script&gt;' ), true );

$objrow = $admin::render_row_html( (array) (object) $row );
check( 'an object cast to array renders the same', $objrow, $html );

echo "\n=== blank_row_html() ===\n\n";
$blank = $admin::blank_row_html();
check( 'blank row is the add row', has( $blank, 'data-id="new"' ) && has( $blank, 'splm-inf-add' ) && has( $blank, 'Add infraction' ), true );
check( 'blank controls are labelled', has( $blank, 'for="splm-inf-new-title"' ) && has( $blank, 'id="splm-inf-new-title"' ), true );
check( 'blank sort is empty', has( $blank, 'id="splm-inf-new-sort_order" data-field="sort_order" value=""' ), true );
check( 'blank row has no Active control', has( $blank, 'splm-inf-new-active' ), false );

echo "\n=== sanitize_rulebook_url() ===\n\n";
check( 'null is empty', $ctx::sanitize_rulebook_url( null ), '' );
check( 'array is empty', $ctx::sanitize_rulebook_url( array( 'x' ) ), '' );
check( 'blank is empty', $ctx::sanitize_rulebook_url( '   ' ), '' );
check( 'https kept and trimmed', $ctx::sanitize_rulebook_url( ' https://example.com/rules.pdf ' ), 'https://example.com/rules.pdf' );
check( 'http kept', $ctx::sanitize_rulebook_url( 'http://example.com/r' ), 'http://example.com/r' );
check( 'javascript is rejected', $ctx::sanitize_rulebook_url( 'javascript:alert(1)' ), '' );
check( 'ftp is rejected', $ctx::sanitize_rulebook_url( 'ftp://example.com/r' ), '' );
check( 'schemeless text is rejected', $ctx::sanitize_rulebook_url( 'example.com/r' ), '' );

echo "\n=== sanitize_rulebook_rev() ===\n\n";
check( 'null is empty', $ctx::sanitize_rulebook_rev( null ), '' );
check( 'array is empty', $ctx::sanitize_rulebook_rev( array( 'x' ) ), '' );
check( 'plain label kept', $ctx::sanitize_rulebook_rev( 'Rev 20251001' ), 'Rev 20251001' );
check( 'markup stripped', $ctx::sanitize_rulebook_rev( '<b>Rev 2</b>' ), 'Rev 2' );
check( 'truncated to 40', $ctx::sanitize_rulebook_rev( str_repeat( 'x', 60 ) ), str_repeat( 'x', 40 ) );

echo "\n=== getters: empty means default ===\n\n";
check( 'unset URL is the default', $ctx::rulebook_url(), $ctx::DEFAULT_RULEBOOK_URL );
check( 'unset rev is the default', $ctx::rulebook_rev(), $ctx::DEFAULT_RULEBOOK_REV );
splm_inf_admin_state()->options = array(
	'splm_discipline_rulebook_url' => '',
	'splm_discipline_rulebook_rev' => '',
);
check( 'empty saved URL is the default', $ctx::rulebook_url(), $ctx::DEFAULT_RULEBOOK_URL );
check( 'empty saved rev is the default', $ctx::rulebook_rev(), $ctx::DEFAULT_RULEBOOK_REV );
splm_inf_admin_state()->options = array(
	'splm_discipline_rulebook_url' => 'https://example.com/new.pdf',
	'splm_discipline_rulebook_rev' => 'Rev 2',
);
check( 'saved URL wins', $ctx::rulebook_url(), 'https://example.com/new.pdf' );
check( 'saved rev wins', $ctx::rulebook_rev(), 'Rev 2' );

$state = splm_inf_admin_state();
echo "\n{$state->total} checks, {$state->failures} failures\n";
exit( $state->failures > 0 ? 1 : 0 );
