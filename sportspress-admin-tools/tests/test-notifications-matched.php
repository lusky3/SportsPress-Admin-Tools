<?php
/**
 * Standalone tests for SPAT_Notifications::notify_payment_matched() and its
 * hook registration: manual matches never send the admin notification.
 *
 * Usage: php test-notifications-matched.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

$GLOBALS['t_options'] = array( 'spat_notifications_enabled' => '1', 'spat_notify_payment_matched' => '1' );
$GLOBALS['t_mails']   = array();
$GLOBALS['t_actions'] = array();

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['t_options'] ) ? $GLOBALS['t_options'][ $key ] : $default;
}
function add_action( $tag, $cb, $priority = 10, $args = 1 ) {
	$GLOBALS['t_actions'][ $tag ] = array( $cb, $priority, $args );
}
function __( $text ) {
	return $text;
}
function esc_html( $t ) {
	return $t;
}
function esc_html__( $t ) {
	return $t;
}
function esc_url( $t ) {
	return $t;
}
function get_bloginfo() {
	return 'Site';
}
function admin_url() {
	return 'http://example.test/wp-admin/';
}
function wp_mail( $to, $subject ) {
	$GLOBALS['t_mails'][] = $subject;
	return true;
}

require_once dirname( __DIR__ ) . '/includes/class-notifications.php';

$passed = 0;
$failed = 0;
function assert_test( $cond, $name ) {
	global $passed, $failed;
	if ( $cond ) {
		++$passed;
		echo "PASS: $name\n";
	} else {
		++$failed;
		echo "FAIL: $name\n";
	}
}

$n = new SPAT_Notifications();

echo "=== Registration ===\n";
assert_test( isset( $GLOBALS['t_actions']['spat_payment_matched'] ) && 4 === $GLOBALS['t_actions']['spat_payment_matched'][2], 'payment_matched listener accepts 4 args' );

echo "\n=== Behaviour ===\n";
$n->notify_payment_matched( 'Pat', 25.0, 5, array( 'manual' => true, 'log_id' => 1 ) );
assert_test( array() === $GLOBALS['t_mails'], 'a manual match sends no admin notification' );

$n->notify_payment_matched( 'Pat', 25.0, 5, array( 'manual' => false, 'log_id' => 1 ) );
assert_test( 1 === count( $GLOBALS['t_mails'] ), 'an automatic match still notifies' );

$GLOBALS['t_mails'] = array();
$n->notify_payment_matched( 'Pat', 25.0, 5 );
assert_test( 1 === count( $GLOBALS['t_mails'] ), 'a 3-argument call still notifies' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
