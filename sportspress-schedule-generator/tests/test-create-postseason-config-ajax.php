<?php
/**
 * Test SPSG_Admin_Ajax::ajax_create_postseason_config()
 *
 * The classic WP Admin "Schedule Generator" page had no postseason UI at
 * all -- SPSG_Configuration_Manager::create_postseason_configuration() was
 * fully built and already exposed via the React dashboard's REST routes,
 * but nothing on the classic page could reach it. This is the AJAX handler
 * that closes that gap (mirroring ajax_clone_config's own pattern), plus
 * the classic page's "Create Postseason Configuration" button/panel.
 *
 * Standalone -- bootstraps WP mocks then loads classes directly, matching
 * this repo's existing standalone-PHP-test convention (run-all-tests.sh),
 * not the separate WP_UnitTestCase/docker suite.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

/**
 * Mutable harness state.
 */
class SPSG_Create_Postseason_Ajax_Test_State {
	/** option_name => value. */
	public $options = array();

	/** Capability the "current user" holds. */
	public $can_manage_options = true;

	/** Every check_ajax_referer() call's action, for asserting the right nonce is checked. */
	public $referer_checks = array();
}

function cpa_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPSG_Create_Postseason_Ajax_Test_State();
	}
	return $state;
}

/**
 * Thrown by the wp_send_json_* stubs below instead of the real functions'
 * wp_die() exit, so a caught response can be asserted on without ending the
 * test script.
 */
class CPA_Test_Ajax_Response extends Exception {
	public $payload;
	public function __construct( $payload ) {
		parent::__construct();
		$this->payload = $payload;
	}
}

// --- WordPress function stubs -----------------------------------------

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

function sanitize_text_field( $s ) { return trim( (string) $s ); }
function absint( $n ) { return abs( (int) $n ); }
function wp_unslash( $s ) { return $s; }
function wp_timezone_string() { return 'America/Toronto'; }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function current_time( $type ) { return '2026-09-13 00:00:00'; }

function get_option( $name, $default = false ) {
	$state = cpa_test_state();
	return $state->options[ $name ] ?? $default;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function update_option( $name, $value, $autoload = null ) {
	$state = cpa_test_state();
	$state->options[ $name ] = $value;
	return true;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function check_ajax_referer( $action, $arg = false ) {
	$state = cpa_test_state();
	$state->referer_checks[] = $action;
	return true;
}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function current_user_can( $capability ) {
	return cpa_test_state()->can_manage_options;
}

function add_action() {}

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_add( $key, $value, $group = '', $ttl = 0 ) { return true; }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function wp_cache_delete( $key, $group = '' ) { return true; }
function wp_timezone() { return new DateTimeZone( 'America/Toronto' ); }
/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function do_action( $tag, ...$args ) {}

function wp_send_json_success( $data = null ) {
	throw new CPA_Test_Ajax_Response( array( 'success' => true, 'data' => $data ) );
}

function wp_send_json_error( $data = null ) {
	throw new CPA_Test_Ajax_Response( array( 'success' => false, 'data' => $data ) );
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
		$this->message = $message;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-sanitizer.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-helper.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-validator.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-error-handler.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-admin-ajax.php';

$passed = 0;
$failed = 0;

function cpa_assert( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: $message\n";
		$passed++;
		return true;
	}
	echo "✗ FAIL: $message\n";
	$failed++;
	return false;
}

/**
 * Runs ajax_create_postseason_config() and returns its response payload,
 * whatever the outcome -- mirrors calling the real endpoint and reading
 * back its JSON body, without the process actually exiting.
 *
 * @SuppressWarnings(PHPMD.Superglobals)
 */
function cpa_run( $manager, $post ) {
	$_POST = $post;
	$ajax = new SPSG_Admin_Ajax( $manager );
	try {
		$ajax->ajax_create_postseason_config();
	} catch ( CPA_Test_Ajax_Response $response ) {
		return $response->payload;
	}
	return null; // Unreachable in practice -- every code path sends a JSON response.
}

$state = cpa_test_state();
$manager = new SPSG_Configuration_Manager();

echo "=== ajax_create_postseason_config(): permissions and validation ===\n\n";

$state->options = array();
$state->can_manage_options = false;

$response = cpa_run( $manager, array( 'config_id' => 'anything' ) );
cpa_assert( false === $response['success'], 'a non-admin gets a failure response' );
cpa_assert(
	false !== strpos( $response['data'], 'Insufficient permissions' ),
	'...specifically mentioning insufficient permissions'
);

$state->can_manage_options = true;

$response = cpa_run( $manager, array() );
cpa_assert( false === $response['success'], 'a missing config_id gets a failure response' );
cpa_assert(
	false !== strpos( $response['data'], 'No configuration ID provided' ),
	'...specifically mentioning the missing configuration ID'
);

$response = cpa_run( $manager, array( 'config_id' => 'config_does_not_exist' ) );
cpa_assert( false === $response['success'], 'an unknown config_id gets a failure response' );
cpa_assert(
	false !== strpos( $response['data'], 'Source configuration not found' ),
	'...surfacing SPSG_Configuration_Manager::create_postseason_configuration()\'s own WP_Error message'
);

echo "\n=== ajax_create_postseason_config(): success path ===\n\n";

$state->options['spsg_configurations'] = array(
	'config_regular123' => array(
		'id'           => 'config_regular123',
		'name'         => 'W2026-27',
		'divisions'    => array( array( 'id' => 'd1', 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C', 'D', 'E', 'F' ) ) ),
		'venues'       => array( array( 'id' => 'v1', 'name' => 'Rink 1', 'capacity' => 4, 'available_days' => array( 'friday', 'sunday' ) ) ),
		'playing_days' => array( 'friday', 'sunday' ),
		'time_slots'   => array(
			'friday' => array( '18:00', '19:00', '20:00' ),
			'sunday' => array( '10:00', '11:00', '12:00' ),
		),
		'timezone'     => 'America/Toronto',
		'created'      => '2026-08-01 00:00:00',
		'modified'     => '2026-08-01 00:00:00',
	),
);

$response = cpa_run(
	$manager,
	array(
		'config_id' => 'config_regular123',
		'season_start' => '2027-02-01',
		'round_robin_weeks' => '3',
		// The source config's venue only has slots on friday/sunday (its own
		// playing_days) -- championship/consolation must land on one of
		// those or SPSG_Configuration_Validator's final-week capacity check
		// (a real, unrelated constraint) fails with nowhere to schedule them.
		'championship_day' => array( 'day' => 'sunday', 'start' => '10:00', 'end' => '12:00' ),
		'consolation_day' => 'friday',
	)
);

cpa_assert( true === $response['success'], 'a valid request succeeds' );
cpa_assert( isset( $response['data']['new_config_id'] ) && '' !== $response['data']['new_config_id'], 'the response carries the new configuration id' );

$new_id = $response['data']['new_config_id'];
$stored = $state->options['spsg_configurations'];

cpa_assert( isset( $stored[ $new_id ] ), 'the new postseason configuration was actually persisted' );
cpa_assert( true === $stored[ $new_id ]['is_postseason'], 'the stored configuration is marked is_postseason' );
cpa_assert( 3 === $stored[ $new_id ]['round_robin_weeks'], 'round_robin_weeks was passed through from the request' );
cpa_assert( 'sunday' === $stored[ $new_id ]['championship_day']['day'], 'championship_day was passed through from the request' );
cpa_assert( 'friday' === $stored[ $new_id ]['consolation_day'], 'consolation_day was passed through from the request' );
cpa_assert(
	'config_regular123' === $stored[ $new_id ]['postseason_source_config_id'],
	'the new configuration links back to its source'
);
cpa_assert(
	isset( $stored['config_regular123'] ) && 2 === count( $stored ),
	'the original source configuration is untouched, sitting alongside the new one'
);

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
