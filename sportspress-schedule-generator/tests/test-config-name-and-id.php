<?php
/**
 * Test: the admin form's "id" field round-trips correctly so Save updates a
 * configuration in place instead of always creating a new one, and the
 * Basic Configuration tab renders both the loaded config's name and id.
 *
 * The form never carried a hidden "id" input, so every Save (there is no
 * server-side "save_as_new" handling either -- that hidden field the JS
 * appended was never read) submitted data with no `id` key at all. The
 * sanitizer only ever kept `id` when the key was present, so
 * SPSG_Configuration_Manager::save() always took its "new configuration"
 * branch and minted a fresh random id, silently duplicating the entry on
 * every re-save. Once a real hidden "id" field is always present in the
 * form, it is present but *empty* on a brand-new, never-saved
 * configuration -- `isset( $data['id'] )` is true for '', so the sanitizer
 * had to switch to `! empty()` or an intentionally-blank id would itself
 * get treated as "id is set", again skipping new-id generation.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_html_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function esc_attr_e( $s, $d = null ) { echo htmlspecialchars( $s, ENT_QUOTES ); }

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $s ) { return trim( (string) $s ); }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $n ) { return abs( (int) $n ); }
}
if ( ! function_exists( 'selected' ) ) {
	function selected( $a, $b = true, $echo = true ) {
		$result = ( (string) $a === (string) $b ) ? ' selected="selected"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( $a, $b = true, $echo = true ) {
		$result = ( (string) $a === (string) $b ) ? ' checked="checked"' : '';
		if ( $echo ) { echo $result; }
		return $result;
	}
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string() { return 'America/Toronto'; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $name, $default = false ) {
		global $spsg_test_options;
		return $spsg_test_options[ $name ] ?? $default;
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/class-sportspress-integration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-renderer.php';

$passed = 0;
$failed = 0;

function cni_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $msg\n";
	$failed++;
	return false;
}

echo "=== Testing sanitizer id handling (empty vs set) ===\n\n";

$sanitizer = new SPSG_Configuration_Sanitizer();

$sanitized_no_id = $sanitizer->sanitize( array( 'name' => 'Winter League' ) );
cni_assert(
	! isset( $sanitized_no_id['id'] ),
	'no "id" key submitted: sanitized output has no id (save() will mint one)'
);

$sanitized_empty_id = $sanitizer->sanitize(
	array(
		'id' => '',
		'name' => 'Winter League',
	)
);
cni_assert(
	! isset( $sanitized_empty_id['id'] ),
	'"id" submitted as an empty string (a brand-new config\'s hidden field): treated the same as absent, not as a real blank id'
);

$sanitized_real_id = $sanitizer->sanitize(
	array(
		'id' => 'config_deadbeefcafebabe',
		'name' => 'Winter League',
	)
);
cni_assert(
	isset( $sanitized_real_id['id'] ) && 'config_deadbeefcafebabe' === $sanitized_real_id['id'],
	'a real "id" submitted (editing an existing config): preserved so save() updates in place'
);

echo "\n=== Testing Basic Configuration tab renders the loaded config's id and name ===\n\n";

class SPSG_Test_Config_Manager_Stub {
	public function get_all_configurations() {
		return array(
			'config_aaa' => array( 'name' => 'Fall League', 'modified' => '2026-08-01 10:00:00' ),
			'config_bbb' => array( 'name' => 'Winter League', 'modified' => '2026-09-01 10:00:00' ),
		);
	}

	public function list_presets() {
		return array();
	}
}

$renderer = new SPSG_Admin_Renderer( new SPSG_Test_Config_Manager_Stub() );

$loaded_config = new SPSG_Schedule_Configuration(
	array(
		'id' => 'config_bbb',
		'name' => 'Winter League',
	)
);

ob_start();
$renderer->render_basic_config_tab( $loaded_config );
$html = ob_get_clean();

cni_assert(
	1 === preg_match( '/<input type="hidden" name="id" id="spsg-config-id" value="config_bbb"/', $html ),
	'hidden #spsg-config-id field carries the loaded config\'s id'
);
cni_assert(
	false !== strpos( $html, 'id="spsg-config-name" value="Winter League"' ),
	'Configuration Name field shows the loaded config\'s name'
);
preg_match( '/<select id="spsg-config-selector".*?<\/select>/s', $html, $loaded_selector_match );
$config_selector_html = $loaded_selector_match[0] ?? '';

cni_assert(
	1 === preg_match( '/<option value="config_bbb"\s+selected="selected">/', $config_selector_html ),
	'the Configuration Management dropdown shows the loaded config as selected'
);
cni_assert(
	1 === preg_match( '/<option value="config_aaa"\s+>/', $config_selector_html ),
	'a different saved configuration in the dropdown is not marked selected'
);

echo "\n--- Brand-new (never-saved) configuration: blank id, nothing selected ---\n";

$new_config = new SPSG_Schedule_Configuration( array() );

ob_start();
$renderer->render_basic_config_tab( $new_config );
$new_html = ob_get_clean();

cni_assert(
	1 === preg_match( '/<input type="hidden" name="id" id="spsg-config-id" value=""/', $new_html ),
	'a brand-new configuration renders an empty hidden id (so sanitize() mints one on save)'
);
preg_match( '/<select id="spsg-config-selector".*?<\/select>/s', $new_html, $selector_match );
cni_assert(
	isset( $selector_match[0] ) && false === strpos( $selector_match[0], 'selected="selected"' ),
	'a brand-new configuration leaves every option in the Configuration Management dropdown unselected'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
