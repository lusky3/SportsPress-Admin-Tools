<?php
/**
 * Test: the REST API (used by the React dashboard) reads/writes the
 * generated schedule through the same persistent, per-configuration
 * SPSG_Schedule_Draft_Store the classic admin-ajax page uses, instead of
 * the old hour-lived, unscoped transient -- and offers the same "discard
 * without publishing" escape hatch.
 *
 * Covers: spsg_export_xlsx()/spsg_export_csv()/spsg_publish() resolving a
 * schedule_id through the draft store (unknown id -> 404 error, known id ->
 * proceeds); discard_draft_if_publish_finished()'s three guard conditions
 * (mirrors SPSG_Schedule_Generator::discard_draft_if_import_finished() for
 * the classic path); and the new DELETE /configs/{id}/draft route
 * (spsg_discard_draft()).
 *
 * Standalone -- bootstraps WP mocks then loads classes directly. Options
 * are backed by a plain in-memory array (not a transient), matching how
 * SPSG_Schedule_Draft_Store is actually built.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
define( 'SPSG_PLUGIN_PATH', dirname( __FILE__ ) . '/../' );

function __( $s ) { return $s; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function sanitize_text_field( $s ) { return is_string( $s ) ? trim( strip_tags( $s ) ) : ''; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function rest_sanitize_boolean( $v ) { return filter_var( $v, FILTER_VALIDATE_BOOLEAN ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function absint( $v ) { return abs( (int) $v ); } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function add_action() { return true; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
function rest_ensure_response( $data ) { return $data; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $code; public $message; public $data;
		public function __construct( $c = '', $m = '', $d = null ) {
			$this->code = $c; $this->message = $m; $this->data = $d;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
	}
}
function is_wp_error( $t ) { return $t instanceof WP_Error; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

$rds_test_options = array();
function get_option( $name, $default = false ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $rds_test_options;
	return $rds_test_options[ $name ] ?? $default;
}
function update_option( $name, $value ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $rds_test_options;
	$rds_test_options[ $name ] = $value;
	return true;
}
function delete_option( $name ) { // phpcs:ignore Squiz.Commenting.FunctionComment.Missing
	global $rds_test_options;
	unset( $rds_test_options[ $name ] );
	return true;
}
function current_time() { return '2026-09-10 12:00:00'; } // phpcs:ignore Squiz.Commenting.FunctionComment.Missing

/** Minimal stand-in for WP_REST_Request: get_param() plus array access for path params. */
class RDS_Mock_Request implements ArrayAccess {
	private $params;
	public function __construct( $params = array() ) {
		$this->params = $params;
	}
	public function get_param( $key ) {
		return $this->params[ $key ] ?? null;
	}
	public function offsetExists( $key ): bool { return isset( $this->params[ $key ] ); }
	public function offsetGet( $key ): mixed { return $this->params[ $key ] ?? null; }
	public function offsetSet( $key, $value ): void { $this->params[ $key ] = $value; }
	public function offsetUnset( $key ): void { unset( $this->params[ $key ] ); }
}

require_once SPSG_PLUGIN_PATH . 'includes/class-schedule-draft-store.php';
require_once SPSG_PLUGIN_PATH . 'includes/class-rest-api.php';

$passed = 0;
$failed = 0;

function rds_assert( $cond, $msg ) {
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
$schedule = array( (object) array( 'date' => '2026-09-25', 'home_team' => 'A', 'away_team' => 'B' ) );

echo "=== spsg_export_xlsx()/spsg_export_csv(): schedule lookup via the draft store ===\n\n";

$result = $api->spsg_export_xlsx( new RDS_Mock_Request( array( 'schedule_id' => 'nonexistent' ) ) );
rds_assert( is_wp_error( $result ) && 'schedule_not_found' === $result->get_error_code(), 'export xlsx: unknown schedule_id -> schedule_not_found error' );

$result = $api->spsg_export_csv( new RDS_Mock_Request( array( 'schedule_id' => 'nonexistent' ) ) );
rds_assert( is_wp_error( $result ) && 'schedule_not_found' === $result->get_error_code(), 'export csv: unknown schedule_id -> schedule_not_found error' );

// A known schedule_id resolving past this check (into SPSG_Configuration_Manager
// and SPSG_Export_Manager) is exercised by SPSG_Schedule_Draft_Store's own
// get_schedule_by_id() round-trip tests in test-schedule-draft-store.php;
// loading those managers here for this one line would be a lot of unrelated
// setup for no more coverage of the actual change.

echo "\n=== spsg_publish(): schedule lookup via the draft store ===\n\n";

$result = $api->spsg_publish( new RDS_Mock_Request( array( 'schedule_id' => 'nonexistent' ) ) );
rds_assert( is_wp_error( $result ) && 'schedule_not_found' === $result->get_error_code(), 'publish: unknown schedule_id -> schedule_not_found error' );

echo "\n=== discard_draft_if_publish_finished(): only discards when truly finished ===\n\n";

$discard_method = new ReflectionMethod( 'SPSG_REST_API', 'discard_draft_if_publish_finished' );
$discard_method->setAccessible( true );

SPSG_Schedule_Draft_Store::save( 'config_1', $schedule, array() );
$discard_method->invoke( $api, 5, false, 'config_1' );
rds_assert( null !== SPSG_Schedule_Draft_Store::get( 'config_1' ), 'games remaining ($remaining=5): draft is NOT discarded' );

$discard_method->invoke( $api, 0, true, 'config_1' );
rds_assert( null !== SPSG_Schedule_Draft_Store::get( 'config_1' ), 'a dry run: draft is NOT discarded even with nothing remaining' );

$discard_method->invoke( $api, 0, false, '' );
rds_assert( null !== SPSG_Schedule_Draft_Store::get( 'config_1' ), 'no configuration id supplied: draft is NOT discarded' );

$discard_method->invoke( $api, 0, false, 'config_1' );
rds_assert( null === SPSG_Schedule_Draft_Store::get( 'config_1' ), 'nothing remaining, not a dry run, config id supplied: draft IS discarded' );

echo "\n=== spsg_discard_draft(): the DELETE /configs/{id}/draft route ===\n\n";

SPSG_Schedule_Draft_Store::save( 'config_2', $schedule, array() );
$response = $api->spsg_discard_draft( new RDS_Mock_Request( array( 'id' => 'config_2' ) ) );
rds_assert( true === ( $response['discarded'] ?? false ), 'spsg_discard_draft() responds discarded=true' );
rds_assert( null === SPSG_Schedule_Draft_Store::get( 'config_2' ), 'spsg_discard_draft() actually removed the draft' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";

exit( $failed === 0 ? 0 : 1 );
