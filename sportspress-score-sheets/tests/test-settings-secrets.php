<?php
/**
 * Standalone tests for the Score Sheets settings page: secret handling (C1) and
 * the registered-options / form-fields invariant (H6).
 *
 * Usage: php test-settings-secrets.php
 *
 * No WordPress, no HTTP, no database. We define ABSPATH plus enough WordPress
 * shims (options, escaping, register_setting) that the REAL class-admin.php and
 * the REAL recognition providers load, then:
 *
 *   (a) Replay what options.php does on "Save Changes" — run every registered
 *       option's sanitize_callback over the value the browser actually submits
 *       and write the result back — and assert no stored secret is destroyed.
 *       Secret fields render value="" with the mask only as a placeholder, so
 *       the submitted value is ALWAYS '' unless the operator retypes the key.
 *       Treating that '' as a new value wiped every API key and token on every
 *       save (C1).
 *
 *   (b) Assert every option registered in the settings group has a matching
 *       input on the rendered form. options.php calls update_option($opt, null)
 *       for whitelisted options absent from the POST, so a registered option
 *       with no field is nulled on every save (H6: spss_webhook_secret).
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

// ── In-memory option store ───────────────────────────────────────────────────

$GLOBALS['spss_options']    = array();
$GLOBALS['spss_registered'] = array(); // option => register_setting args

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['spss_options'] )
		? $GLOBALS['spss_options'][ $name ]
		: $default;
}
function update_option( $name, $value ) {
	$GLOBALS['spss_options'][ $name ] = $value;
	return true;
}
function add_option( $name, $value ) {
	if ( ! array_key_exists( $name, $GLOBALS['spss_options'] ) ) {
		$GLOBALS['spss_options'][ $name ] = $value;
	}
	return true;
}

// ── WordPress shims ──────────────────────────────────────────────────────────

function add_action() {}
function add_filter() {}
function apply_filters( $tag, $value ) { return $value; }
function __( $text, $domain = 'default' ) { return $text; }
function _n( $single, $plural, $number, $domain = 'default' ) { return 1 === (int) $number ? $single : $plural; }
function esc_html__( $text, $domain = 'default' ) { return $text; }
function esc_attr__( $text, $domain = 'default' ) { return $text; }
function esc_html_e( $text, $domain = 'default' ) { echo $text; }
function esc_attr_e( $text, $domain = 'default' ) { echo $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES ); }
function esc_url( $url ) { return (string) $url; }
function wp_kses_post( $text ) { return $text; }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function esc_url_raw( $url ) { return trim( (string) $url ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function wp_unslash( $value ) { return $value; }
function absint( $n ) { return abs( (int) $n ); }
function trailingslashit( $s ) { return rtrim( (string) $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( (string) $s, '/' ); }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . ltrim( (string) $path, '/' ); }
function wp_nonce_url( $url, $action = -1 ) { return $url . '&_wpnonce=test'; }
function current_user_can( $cap ) { return true; }
function get_current_user_id() { return 1; }
// No "Test connection" result is ever pending in this harness — render_test_result()
// must see a consistent "nothing to show" rather than fatal on an unstubbed function.
function get_transient() { return false; }
function delete_transient() { return true; }
function settings_fields( $group ) { echo '<input type="hidden" name="option_page" value="' . $group . '" />'; }
function submit_button( $text = null ) { echo '<p class="submit"><input type="submit" /></p>'; }
function checked( $checked, $current = true, $echo = true ) {
	$out = ( (string) $checked === (string) $current ) ? " checked='checked'" : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}
function selected( $selected, $current = true, $echo = true ) {
	$out = ( (string) $selected === (string) $current ) ? " selected='selected'" : '';
	if ( $echo ) {
		echo $out;
	}
	return $out;
}

/** register_setting recorder — this is what options.php later walks. */
function register_setting( $group, $option, $args = array() ) {
	$GLOBALS['spss_registered'][ $option ] = $args;
}

// ── Load the real classes under test ─────────────────────────────────────────

require_once dirname( __FILE__ ) . '/../includes/class-budget.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/interface-recognition-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-extraction-result.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-abstract-llm-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-claude-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-gemini-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-openai-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-openrouter-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-selfhosted-provider.php';
require_once dirname( __FILE__ ) . '/../includes/recognition/class-recognition-manager.php';
require_once dirname( __FILE__ ) . '/../includes/class-admin.php';

// ── Test helpers ─────────────────────────────────────────────────────────────

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		++$passed;
	} else {
		echo "✗ FAIL: $message\n";
		++$failed;
	}
}

/**
 * Every secret option the settings page can write, with a realistic stored value.
 * This is the exact blast radius of C1.
 */
function spss_test_secret_options() {
	return array(
		'spss_claude_api_key'        => 'sk-ant-live-CLAUDE-0001',
		'spss_gemini_api_key'        => 'AIza-live-GEMINI-0002',
		'spss_openai_api_key'        => 'sk-live-OPENAI-0003',
		'spss_openrouter_api_key'    => 'sk-or-live-ROUTER-0004',
		'spss_selfhosted_key'        => 'bearer-live-SELF-0005',
		'spss_twilio_auth_token'     => 'twilio-live-TOKEN-0006',
		'spss_whatsapp_app_secret'   => 'meta-live-APPSECRET-0007',
		'spss_whatsapp_access_token' => 'meta-live-ACCESSTOK-0008',
	);
}

/** Seed the option store the way a fully-configured install looks. */
function spss_test_seed_options() {
	$GLOBALS['spss_options'] = array_merge(
		spss_test_secret_options(),
		array(
			'spss_webhook_secret'         => 'shared-webhook-secret-value',
			'spss_twilio_account_sid'     => 'AC0123456789',
			'spss_whatsapp_verify_token'  => 'my-verify-token',
			'spss_whatsapp_graph_version' => 'v21.0',
			'spss_retention_days'         => 30,
			'spss_primary_chain'          => array( 'claude' ),
			'spss_confirmation_providers' => array(),
		)
	);
}

/**
 * Replay a "Save Changes" the way wp-admin/options.php does: for every option
 * registered in the settings group, take the POSTed value (null when absent),
 * run its sanitize_callback, and write the result back.
 *
 * @param array $post Simulated $_POST for the settings form.
 */
function spss_test_save_settings( array $post ) {
	$_POST = $post;
	foreach ( $GLOBALS['spss_registered'] as $option => $args ) {
		$value = array_key_exists( $option, $post ) ? $post[ $option ] : null;
		if ( ! empty( $args['sanitize_callback'] ) ) {
			$value = call_user_func( $args['sanitize_callback'], $value );
		}
		update_option( $option, $value );
	}
	$_POST = array();
}

/**
 * The exact payload the settings form submits when the operator changes only a
 * non-secret field: every secret input is present but blank, because they all
 * render value="" with the mask as a placeholder.
 *
 * @param array $overrides Extra/replacement POST keys.
 */
function spss_test_form_post( array $overrides = array() ) {
	$post = array(
		'option_page'                 => SPSS_Admin::SETTINGS_GROUP,
		'spss_primary_chain'          => array( '', 'claude' ),
		'spss_confirmation_providers' => array( '' ),
		'spss_twilio_account_sid'     => 'AC0123456789',
		'spss_whatsapp_verify_token'  => 'my-verify-token',
		'spss_whatsapp_graph_version' => 'v21.0',
		'spss_retention_days'         => '45', // the field the operator actually changed
	);
	// Every secret input posts as an empty string.
	foreach ( array_keys( spss_test_secret_options() ) as $option ) {
		$post[ $option ] = '';
	}
	// Non-secret provider text fields post their rendered values.
	$post['spss_claude_model']         = 'claude-sonnet-5';
	$post['spss_gemini_model']         = 'gemini-2.5-flash';
	$post['spss_openai_model']         = 'gpt-4o';
	$post['spss_openrouter_model']     = 'openai/gpt-4o';
	$post['spss_openrouter_base_url']  = '';
	$post['spss_selfhosted_endpoint']  = 'http://127.0.0.1:8000';
	$post['spss_selfhosted_model']     = '';

	return array_merge( $post, $overrides );
}

$admin = new SPSS_Admin();
$admin->register_settings();

// ═══════════════════════════════════════════════════════════════════════════
echo "=== C1: saving the settings page must not erase stored secrets ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

spss_test_seed_options();
spss_test_save_settings( spss_test_form_post() );

foreach ( spss_test_secret_options() as $option => $expected ) {
	assert_test(
		get_option( $option, '' ) === $expected,
		sprintf( 'save with blank secret fields keeps %s intact', $option )
	);
}

assert_test(
	'45' === (string) get_option( 'spss_retention_days', '' ),
	'save still applies the non-secret field the operator actually changed'
);

// Ten consecutive saves must be just as harmless as one.
spss_test_seed_options();
for ( $i = 0; $i < 10; $i++ ) {
	spss_test_save_settings( spss_test_form_post() );
}
$survived = true;
foreach ( spss_test_secret_options() as $option => $expected ) {
	$survived = $survived && ( get_option( $option, '' ) === $expected );
}
assert_test( $survived, 'ten repeated saves still leave every secret intact' );

// ── The masked placeholder echoed back is likewise "keep" ────────────────────

spss_test_seed_options();
$masked_post = spss_test_form_post(
	array(
		'spss_claude_api_key'    => str_repeat( '•', 8 ) . '0001',
		'spss_twilio_auth_token' => str_repeat( '•', 8 ) . '0006',
	)
);
spss_test_save_settings( $masked_post );
assert_test(
	'sk-ant-live-CLAUDE-0001' === get_option( 'spss_claude_api_key', '' ),
	'submitting the mask back keeps the stored API key (never stores bullets)'
);
assert_test(
	'twilio-live-TOKEN-0006' === get_option( 'spss_twilio_auth_token', '' ),
	'submitting the mask back keeps the stored Twilio token'
);

// ── A real new value still replaces the stored one ───────────────────────────

spss_test_seed_options();
spss_test_save_settings(
	spss_test_form_post(
		array(
			'spss_claude_api_key'      => 'sk-ant-live-ROTATED-9999',
			'spss_whatsapp_app_secret' => '  meta-live-ROTATED-8888  ', // trimmed
		)
	)
);
assert_test(
	'sk-ant-live-ROTATED-9999' === get_option( 'spss_claude_api_key', '' ),
	'a retyped API key replaces the stored one'
);
assert_test(
	'meta-live-ROTATED-8888' === get_option( 'spss_whatsapp_app_secret', '' ),
	'a retyped secret is trimmed and stored'
);
assert_test(
	'AIza-live-GEMINI-0002' === get_option( 'spss_gemini_api_key', '' ),
	'rotating one key leaves the others untouched'
);

// ── The explicit "Clear" checkbox is the one way to erase a secret ───────────

spss_test_seed_options();
spss_test_save_settings(
	spss_test_form_post(
		array(
			'spss_clear_secret' => array( 'spss_openai_api_key' ),
		)
	)
);
assert_test(
	'' === get_option( 'spss_openai_api_key', '' ),
	'the Clear checkbox erases exactly the secret it names'
);
assert_test(
	'sk-ant-live-CLAUDE-0001' === get_option( 'spss_claude_api_key', '' )
		&& 'meta-live-ACCESSTOK-0008' === get_option( 'spss_whatsapp_access_token', '' ),
	'the Clear checkbox does not touch any other secret'
);

// ── The rendered secret fields never leak the stored value ───────────────────

spss_test_seed_options();
ob_start();
$admin->render_settings();
$html = ob_get_clean();

$leaked = array();
foreach ( spss_test_secret_options() as $option => $value ) {
	if ( false !== strpos( $html, $value ) ) {
		$leaked[] = $option;
	}
}
assert_test(
	empty( $leaked ),
	'no stored secret value appears anywhere in the rendered settings page'
);
assert_test(
	false !== strpos( $html, 'name="spss_clear_secret[]"' ),
	'a Clear checkbox is rendered for stored secrets'
);

// ═══════════════════════════════════════════════════════════════════════════
echo "\n=== H6: every registered option must have a form field ===\n\n";
// ═══════════════════════════════════════════════════════════════════════════

// options.php calls update_option( $option, null ) for every whitelisted option
// missing from the POST, so a registered option with no input is wiped on save.
preg_match_all( '/name="([^"]+)"/', $html, $matches );
$field_names = array();
foreach ( $matches[1] as $name ) {
	$field_names[] = str_replace( '[]', '', $name );
}
$field_names = array_unique( $field_names );

$orphans = array();
foreach ( array_keys( $GLOBALS['spss_registered'] ) as $option ) {
	if ( ! in_array( $option, $field_names, true ) ) {
		$orphans[] = $option;
	}
}
assert_test(
	empty( $orphans ),
	'every registered setting has a matching form input (orphans: ' . ( $orphans ? implode( ', ', $orphans ) : 'none' ) . ')'
);

assert_test(
	! array_key_exists( 'spss_webhook_secret', $GLOBALS['spss_registered'] ),
	'spss_webhook_secret is NOT registered in the settings group'
);

spss_test_seed_options();
spss_test_save_settings( spss_test_form_post() );
assert_test(
	'shared-webhook-secret-value' === get_option( 'spss_webhook_secret', '' ),
	'saving the settings page leaves the webhook HMAC secret intact'
);

// It is still displayed (read-only) so an operator can copy it into the Worker.
assert_test(
	false !== strpos( $html, 'shared-webhook-secret-value' ),
	'the webhook secret is still shown read-only on the settings page'
);
assert_test(
	false === strpos( $html, 'name="spss_webhook_secret"' ),
	'the webhook secret display input carries no name (never submitted)'
);

// ── Summary ─────────────────────────────────────────────────────────────────

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
