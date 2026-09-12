<?php
/**
 * Test: REST API postseason routes (phase 6) -- spsg_create_postseason_config(),
 * spsg_mint_postseason_placeholders(), spsg_resolve_postseason_seeds(), and the
 * postseason_config() guard the latter two share.
 *
 * The underlying business logic (SPSG_Configuration_Manager::create_postseason_configuration(),
 * SPSG_Postseason_Seed_Resolver::mint_division_placeholders()/resolve_seeds()) is already fully
 * tested in test-postseason-config.php and test-postseason-seed-resolver.php; this file covers
 * only what these REST wrappers add on top -- delegation, the not_found/not_postseason guard,
 * and malformed-body rejection -- matching test-rest-api-draft-store.php's own scoping (see its
 * header comment for the same reasoning).
 *
 * Standalone -- bootstraps minimal WP mocks then loads classes directly. Since the one
 * create_postseason_config() case tested here (unknown source id) returns before
 * SPSG_Configuration_Manager ever reaches sanitize()/validate()/save(), those heavier
 * dependencies (and their own WP stubs, already exercised in test-postseason-config.php) are
 * deliberately not loaded here.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

function rest_ensure_response( $data ) { return $data; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function add_action() { return true; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

/**
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $s, $d = null ) { return $s; }

class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = null ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}

function is_wp_error( $thing ) { return $thing instanceof WP_Error; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

$rap_test_options = array();
function get_option( $name, $default = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $rap_test_options;
	return $rap_test_options[ $name ] ?? $default;
}
function update_option( $name, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $rap_test_options;
	$rap_test_options[ $name ] = $value;
	return true;
}

/** Minimal stand-in for WP_REST_Request: get_param()/get_json_params() plus array access for path params. */
class RAP_Mock_Request implements ArrayAccess {
	private $params;
	private $json;
	public function __construct( $params = array(), $json = array() ) {
		$this->params = $params;
		$this->json   = $json;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
	public function get_json_params() {
		return $this->json;
	}
	public function offsetExists( $key ): bool { return isset( $this->params[ $key ] ); }
	public function offsetGet( $key ): mixed { return $this->params[ $key ] ?? null; }
	public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}

/**
 * Test double for SPSG_Placeholder_Team_Manager -- same static-method
 * signatures as the real class (matches test-postseason-seed-resolver.php's
 * own double), simplified since this file only needs the resolver's
 * REST-level delegation, not its own idempotency/failure-handling behavior
 * (already covered in test-postseason-seed-resolver.php).
 */
class SPSG_Placeholder_Team_Manager {
	public static $next_id = 900;
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function create_placeholder_team( $team_name, $config_id = '', $division = '' ) {
		return self::$next_id++;
	}
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function is_placeholder( $team_id ) {
		return true;
	}
	/**
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 */
	public static function replace_team( $placeholder_id, $replacement_id, $delete_placeholder = true ) {
		return array( 'events_updated' => 0, 'placeholder_status' => 'trashed', 'errors' => array() );
	}
}

require_once SPSG_PLUGIN_PATH . 'includes/interfaces/interface-configuration.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-configuration-manager.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-postseason-seed-resolver.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-rest-api.php';

$passed = 0;
$failed = 0;

function rap_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) {
		echo "✓ PASS: $msg\n";
		$passed++;
	} else {
		echo "✗ FAIL: $msg\n";
		$failed++;
	}
}

$api = new SPSG_REST_API();

echo "=== spsg_create_postseason_config(): delegates to SPSG_Configuration_Manager, passes through its errors ===\n\n";

$result = $api->spsg_create_postseason_config( new RAP_Mock_Request( array( 'id' => 'nonexistent' ), array() ) );
rap_assert(
	is_wp_error( $result ) && 'config_not_found' === $result->get_error_code(),
	'unknown source config id -> config_not_found error, passed straight through'
);

echo "\n=== postseason_config() guard (shared by the placeholder/resolve-seeds routes) ===\n\n";

$result = $api->spsg_mint_postseason_placeholders( new RAP_Mock_Request( array( 'id' => 'nonexistent' ) ) );
rap_assert( is_wp_error( $result ) && 'not_found' === $result->get_error_code(), 'unknown config id -> not_found error' );

global $rap_test_options;
$rap_test_options['spsg_configurations'] = array(
	'config_regular'   => array( 'id' => 'config_regular', 'is_postseason' => false ),
	'config_playoffs'  => array(
		'id' => 'config_playoffs',
		'is_postseason' => true,
		'divisions' => array(
			array( 'name' => 'Div 1', 'teams' => array( 'A', 'B', 'C' ) ),
			array( 'name' => '', 'teams' => array( 'X', 'Y' ) ), // no name -- skipped
			array( 'name' => 'Div 2', 'teams' => array() ), // no teams -- skipped
		),
	),
);

$result = $api->spsg_mint_postseason_placeholders( new RAP_Mock_Request( array( 'id' => 'config_regular' ) ) );
rap_assert(
	is_wp_error( $result ) && 'not_postseason' === $result->get_error_code(),
	'a regular-season config id -> not_postseason error, even though it exists'
);

echo "\n=== spsg_mint_postseason_placeholders(): reads divisions off the config itself, delegates to SPSG_Postseason_Seed_Resolver::mint_division_placeholders() per division ===\n\n";

$result = $api->spsg_mint_postseason_placeholders( new RAP_Mock_Request( array( 'id' => 'config_playoffs' ) ) );
rap_assert(
	array( 'Div 1' ) === array_keys( $result ),
	'only the one division with both a name and teams is minted -- the unnamed and empty ones are skipped'
);
rap_assert(
	3 === count( $result['Div 1']['seed'] ) && 3 === count( $result['Div 1']['rr_seed'] ),
	'mints 3 Seed + 3 RR-Seed placeholders for a 3-team division, via the real resolver'
);

echo "\n=== spsg_resolve_postseason_seeds(): delegates to SPSG_Postseason_Seed_Resolver::resolve_seeds() ===\n\n";

$result = $api->spsg_resolve_postseason_seeds(
	new RAP_Mock_Request(
		array(
			'id'              => 'config_playoffs',
			'placeholder_ids' => array( 1 => 501, 2 => 502 ),
			'ranked_team_ids' => array( 1 => 901, 2 => 902 ),
		)
	)
);
rap_assert( 2 === count( $result ), 'resolves both seeds via the real resolver, returning one result per seed' );

$result = $api->spsg_resolve_postseason_seeds(
	new RAP_Mock_Request(
		array(
			'id'              => 'config_playoffs',
			'placeholder_ids' => 'not-an-array',
			'ranked_team_ids' => array( 1 => 901 ),
		)
	)
);
rap_assert(
	is_wp_error( $result ) && 'invalid_data' === $result->get_error_code(),
	'a non-array placeholder_ids body is rejected before reaching the resolver'
);

$result = $api->spsg_resolve_postseason_seeds(
	new RAP_Mock_Request( array( 'id' => 'nonexistent', 'placeholder_ids' => array(), 'ranked_team_ids' => array() ) )
);
rap_assert(
	is_wp_error( $result ) && 'not_found' === $result->get_error_code(),
	'unknown config id -> not_found error (the shared guard runs before the body-shape check)'
);

echo "\n=== Test Summary ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo 'Total: ' . ( $passed + $failed ) . "\n";

if ( 0 === $failed ) {
	echo "\n✓ All tests passed!\n";
	exit( 0 );
} else {
	echo "\n✗ Some tests failed\n";
	exit( 1 );
}
