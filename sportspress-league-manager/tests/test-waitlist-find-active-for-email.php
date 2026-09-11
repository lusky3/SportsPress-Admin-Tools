<?php
/**
 * Standalone tests for SPLM_Waitlist_Database::find_active_for_email().
 *
 * find_active() (covered elsewhere) answers "does this exact
 * season+position already have an active row for this email" — a
 * duplicate-entry guard. This method answers a different question: "what
 * is this email's status everywhere" — the question the FreeScout
 * integration asks. The two must not be confused: this one deliberately
 * ignores season/position and includes 'claimed', which find_active()
 * does not.
 *
 * Usage: php test-waitlist-find-active-for-email.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function __( $text, $domain = 'default' ) { // phpcs:ignore
	return $text;
}

/**
 * Captures the exact SQL SPLM_Waitlist_Database builds, and answers back
 * a fixed row set — this test is about the WHERE/ORDER BY clause, not
 * real SQL execution.
 */
class SPLM_Email_Lookup_Mock_WPDB {
	public $prefix = 'wp_';
	public $last_prepare_sql = '';
	public $last_prepare_args = array();
	/** @var object[] */
	public $rows = array();

	public function prepare( $sql, ...$args ) {
		$this->last_prepare_sql  = $sql;
		$this->last_prepare_args = $args;
		return $sql;
	}

	public function get_results( $sql, $output = 'OBJECT' ) {
		return $this->rows;
	}
}

$GLOBALS['wpdb'] = new SPLM_Email_Lookup_Mock_WPDB();

require_once __DIR__ . '/../includes/class-waitlist-database.php';

$passed = 0;
$failed = 0;
function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

function splm_row( $overrides = array() ) {
	return (object) array_merge(
		array(
			'id'         => 1,
			'season'     => 'S2026',
			'position'   => 'player',
			'email'      => 'person@example.com',
			'status'     => 'queued',
			'offered_at' => null,
			'expires_at' => null,
		),
		$overrides
	);
}

echo "=== find_active_for_email(): query shape ===\n\n";

$wpdb       = $GLOBALS['wpdb'];
$wpdb->rows = array();
SPLM_Waitlist_Database::find_active_for_email( 'Person@Example.com' );

assert_test(
	false !== strpos( $wpdb->last_prepare_sql, 'WHERE email = %s AND status IN (%s, %s, %s)' ),
	'queries by email and the three active statuses, with no season/position filter'
);
assert_test(
	false !== strpos( $wpdb->last_prepare_sql, 'ORDER BY season ASC, id ASC' ),
	'orders by season, then id'
);
assert_test(
	'person@example.com' === $wpdb->last_prepare_args[0],
	"lower-cases the email before querying, matching find_active()'s convention"
);
assert_test(
	array( 'queued', 'offered', 'claimed' ) === array_slice( $wpdb->last_prepare_args, 1, 3 ),
	'active statuses are queued, offered, claimed — NOT expired/cancelled'
);

echo "\n=== find_active_for_email(): row passthrough ===\n\n";

$wpdb->rows = array( splm_row( array( 'id' => 5, 'status' => 'offered' ) ) );
$result     = SPLM_Waitlist_Database::find_active_for_email( 'person@example.com' );
assert_test( 1 === count( $result ), 'returns every row $wpdb->get_results() hands back' );
assert_test( 'offered' === $result[0]->status, 'returns the actual row objects, untouched' );

$wpdb->rows = array();
$result     = SPLM_Waitlist_Database::find_active_for_email( 'nobody@example.com' );
assert_test( array() === $result, 'no rows — an empty array, not null' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
