<?php
/**
 * Standalone tests for SPT_FreeScout_Player_Actions: the player.change_email
 * FreeScout action.
 *
 * SPAT_Player::ids_for_email() is replaced by a fake that applies the same two
 * strategies (spt_email, then sp_user of the WP user with that email) to the
 * in-memory fixture, so a plan computed after the login email changed would
 * lose the sp_user-linked players exactly as it would in WordPress.
 *
 * Usage: php test-freescout-player-actions.php
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t'] = null;

/** Mutable harness state. */
class SPT_FSPA_State {
	public $posts         = array(); // id => object (ID, post_title, post_type, post_status).
	public $meta          = array(); // post id => key => value.
	public $users         = array(); // id => WP_User.
	public $user_meta     = array(); // user id => key => value.
	public $orders        = array(); // Mock_Order list.
	public $filters       = array(); // tag => list of callbacks.
	public $actions       = array(); // tag => list of callbacks.
	public $order_args    = null;
	public $update_calls  = array();
	public $change_filter = null;  // send_email_change_email as seen inside wp_update_user.
	public $pass_filter   = null;  // send_password_change_email as seen inside wp_update_user.
	public $update_error  = false;
	public $super_admins  = array(); // user ids.
}

function st() {
	return $GLOBALS['t'];
}

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
class WP_User {
	public $ID;
	public $user_login;
	public $user_email;
	public $display_name;
	public $roles;
	public $caps;
	public function __construct( $id, $login, $email, $display = '', $roles = array( 'customer' ), $caps = array( 'read' ) ) {
		$this->ID           = $id;
		$this->user_login   = $login;
		$this->user_email   = $email;
		$this->display_name = $display;
		$this->roles        = $roles;
		$this->caps         = $caps;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}
function sanitize_text_field( $text ) {
	return trim( preg_replace( '/[\r\n\t ]+/', ' ', wp_strip_all_tags_fake( (string) $text ) ) );
}
function wp_strip_all_tags_fake( $text ) {
	return strip_tags( $text );
}
function __return_false() {
	return false;
}
function add_filter( $tag, $callback, $priority = 10 ) {
	st()->filters[ $tag ][] = $callback;
	return true;
}
function remove_filter( $tag, $callback, $priority = 10 ) {
	foreach ( st()->filters[ $tag ] ?? array() as $i => $cb ) {
		if ( $cb === $callback ) {
			unset( st()->filters[ $tag ][ $i ] );
			return true;
		}
	}
	return false;
}
function apply_filters( $tag, $value, ...$args ) {
	foreach ( st()->filters[ $tag ] ?? array() as $cb ) {
		$value = call_user_func( $cb, $value, ...$args );
	}
	return $value;
}
function add_action( $tag, $callback ) {
	st()->actions[ $tag ][] = $callback;
	return true;
}
function do_action( $tag, ...$args ) {
	foreach ( st()->actions[ $tag ] ?? array() as $cb ) {
		call_user_func( $cb, ...$args );
	}
}
function get_post( $id ) {
	return st()->posts[ $id ] ?? null;
}
function get_post_meta( $id, $key, $single = false ) {
	return st()->meta[ $id ][ $key ] ?? '';
}
function update_post_meta( $id, $key, $value ) {
	st()->meta[ $id ][ $key ] = $value;
	return true;
}
function get_user_meta( $id, $key, $single = false ) {
	return st()->user_meta[ $id ][ $key ] ?? '';
}
function update_user_meta( $id, $key, $value ) {
	st()->user_meta[ $id ][ $key ] = $value;
	return true;
}
function user_can( $user, $cap ) {
	return in_array( $cap, $user->caps, true );
}
function is_super_admin( $id ) {
	return in_array( $id, st()->super_admins, true );
}
function get_userdata( $id ) {
	return st()->users[ $id ] ?? false;
}
function get_user_by( $field, $value ) {
	foreach ( st()->users as $user ) {
		if ( 'email' === $field && 0 === strcasecmp( $user->user_email, (string) $value ) ) {
			return $user;
		}
	}
	return false;
}
function wp_update_user( $data ) {
	$state                 = st();
	$state->update_calls[] = $data;
	$state->change_filter  = apply_filters( 'send_email_change_email', true, array(), $data );
	$state->pass_filter    = apply_filters( 'send_password_change_email', true, array(), $data );
	if ( $state->update_error ) {
		return new WP_Error( 'existing_user_email', 'Sorry, that email address is already used!' );
	}
	$state->users[ $data['ID'] ]->user_email = $data['user_email'];
	do_action( 'profile_update', $data['ID'] );
	return $data['ID'];
}
function wc_get_orders( $args ) {
	st()->order_args = $args;
	$out             = array();
	foreach ( st()->orders as $order ) {
		if ( 0 === strcasecmp( $order->billing, $args['billing_email'] ) ) {
			$out[] = $order;
		}
	}
	return $out;
}

class Mock_Order {
	public $id;
	public $billing;
	public $saved = 0;
	public $notes = array();
	public $spt_at_save = null; // Player 17's spt_email when this order was saved.
	public function __construct( $id, $billing ) {
		$this->id      = $id;
		$this->billing = $billing;
	}
	public function get_id() {
		return $this->id;
	}
	public function set_billing_email( $email ) {
		$this->billing = $email;
	}
	public function save() {
		++$this->saved;
		$this->spt_at_save = st()->meta[17]['spt_email'] ?? null;
	}
	public function add_order_note( $note, $is_customer = 0, $added_by_user = false ) {
		$this->notes[] = array( $note, $is_customer, $added_by_user );
	}
}

/** Same two strategies as the real SPAT_Player::ids_for_email(), over the fixture. */
class SPAT_Player {
	public static function ids_for_email( string $email ): array {
		$ids = array();
		foreach ( st()->meta as $id => $meta ) {
			if ( isset( $meta['spt_email'] ) && strtolower( $meta['spt_email'] ) === strtolower( $email ) ) {
				$ids[] = $id;
			}
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			foreach ( st()->meta as $id => $meta ) {
				if ( isset( $meta['sp_user'] ) && (string) $meta['sp_user'] === (string) $user->ID ) {
					$ids[] = $id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		sort( $ids );
		return $ids;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-freescout-player-actions.php';

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

function mk_post( $id, $title, $type = 'sp_player', $status = 'publish' ) {
	st()->posts[ $id ] = (object) array(
		'ID'          => $id,
		'post_title'  => $title,
		'post_type'   => $type,
		'post_status' => $status,
	);
}

/**
 * Fixture: Jordan has two players (17 by spt_email in mixed case, 18 linked
 * to Jordan's login only), login user #12 and two orders; one order belongs
 * to someone else.
 */
function fixture() {
	$GLOBALS['t'] = new SPT_FSPA_State();
	mk_post( 17, 'Jordan Smith' );
	mk_post( 18, 'Jordan Smith Jr' );
	mk_post( 30, 'Other Person' );
	mk_post( 40, 'Coach Jordan', 'sp_staff' );
	st()->meta[17]          = array( 'spt_email' => 'Jordan@Example.com' );
	st()->meta[18]          = array( 'sp_user' => '12' );
	st()->meta[30]          = array( 'spt_email' => 'other@example.com' );
	st()->meta[40]          = array( 'sp_user' => '12' );
	st()->users[12]         = new WP_User( 12, 'jordan', 'jordan@example.com', 'Jordan S' );
	st()->users[50]         = new WP_User( 50, 'someone', 'taken@example.com' );
	st()->user_meta[12]     = array( 'billing_email' => 'jordan@example.com' );
	st()->orders            = array(
		new Mock_Order( 5101, 'jordan@example.com' ),
		new Mock_Order( 5102, 'JORDAN@example.com' ),
		new Mock_Order( 5200, 'other@example.com' ),
	);
}

function run_action( $params, $dry_run, $name = 'Cody <b>Agent</b>' ) {
	$actions = apply_filters( 'spat_freescout_actions', array() );
	return call_user_func(
		$actions['player.change_email']['handler'],
		$params,
		$dry_run,
		new WP_User( 1, 'agent', 'agent@x.test' ),
		array(
			'email' => 'agent@x.test',
			'name'  => $name,
		)
	);
}

function err_code( $result ) {
	return is_wp_error( $result ) ? $result->get_error_code() : '';
}
function err_status( $result ) {
	return is_wp_error( $result ) ? ( $result->get_error_data()['status'] ?? null ) : null;
}

echo "=== Registration ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
$registry = apply_filters( 'spat_freescout_actions', array() );
assert_test(
	isset( $registry['player.change_email'] ) && 'manage_options' === $registry['player.change_email']['capability'] && is_callable( $registry['player.change_email']['handler'] ),
	'registers player.change_email with manage_options'
);

echo "\n=== Dry run ===\n";
$res = run_action(
	array(
		'old_email' => ' Jordan@Example.COM ',
		'new_email' => 'J.Smith@Example.com',
	),
	true
);
assert_test( is_array( $res ), 'dry run returns a preview' );
assert_test( 'Change email jordan@example.com → j.smith@example.com' === $res['summary'], 'summary names both lower-cased emails' );
$expected = array(
	'Player #17 Jordan Smith: email → j.smith@example.com',
	'Player #18 Jordan Smith Jr: email → j.smith@example.com',
	'WordPress login of jordan (user #12, customer): email → j.smith@example.com',
	'This changes the address they log in with',
	'2 WooCommerce orders: billing email → j.smith@example.com',
);
assert_test( $expected === $res['changes'], 'changes list players (not staff), the login with warning, and the order count' );
assert_test( array( 'billing_email' => 'jordan@example.com', 'limit' => -1, 'return' => 'objects' ) === st()->order_args, 'orders looked up by old billing email, unlimited' );
assert_test( array() === st()->update_calls && 'Jordan@Example.com' === st()->meta[17]['spt_email'], 'dry run writes nothing' );
assert_test( 0 === st()->orders[0]->saved && array() === st()->orders[0]->notes, 'dry run saves no order' );
assert_test( array( 17, 18 ) === $res['data']['player_ids'] && 12 === $res['data']['user_id'] && array( 5101, 5102 ) === $res['data']['order_ids'], 'data carries ids' );

echo "\n=== Dry run: one order, no login ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
st()->users = array();
st()->orders = array( new Mock_Order( 5101, 'jordan@example.com' ) );
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test(
	array( 'Player #17 Jordan Smith: email → new@example.com', '1 WooCommerce order: billing email → new@example.com' ) === $res['changes'],
	'singular order line; no login lines without a login'
);

echo "\n=== Refusals ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'Taken@Example.com' ), false );
assert_test( 'email_in_use' === err_code( $res ) && 409 === err_status( $res ), 'email_in_use 409 when another WP user holds the new email' );
assert_test( array() === st()->update_calls && 'Jordan@Example.com' === st()->meta[17]['spt_email'] && 0 === st()->orders[0]->saved, 'refused: nothing changed' );

$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'OTHER@example.com' ), false );
assert_test( 'email_in_use' === err_code( $res ) && 409 === err_status( $res ), 'email_in_use 409 when a player outside the old-email set has the new email' );
assert_test( 'other@example.com' === st()->meta[30]['spt_email'] && 'Jordan@Example.com' === st()->meta[17]['spt_email'], 'refused: no player changed' );

$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'not-an-email' ), true );
assert_test( 'email_invalid' === err_code( $res ) && 400 === err_status( $res ), 'email_invalid 400 for a bad new email' );
$res = run_action( array( 'old_email' => '', 'new_email' => 'new@example.com' ), true );
assert_test( 'email_invalid' === err_code( $res ) && 400 === err_status( $res ), 'email_invalid 400 for a missing old email' );
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => array( 'x' ) ), true );
assert_test( 'email_invalid' === err_code( $res ), 'email_invalid for a non-string param' );
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'JORDAN@example.com' ), true );
assert_test( 'email_invalid' === err_code( $res ) && 400 === err_status( $res ), 'email_invalid when new equals old after lower-casing' );

$res = run_action( array( 'old_email' => 'nobody@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( 'nothing_to_change' === err_code( $res ) && 404 === err_status( $res ), 'nothing_to_change 404 when nothing uses the old email' );

echo "\n=== Two logins: old on A, new on B linked to an old-email player ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
st()->users[60]         = new WP_User( 60, 'jordan2', 'new@example.com' );
st()->meta[17]['sp_user'] = '60';
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( 'email_in_use' === err_code( $res ) && 409 === err_status( $res ), 'dry run: email_in_use 409 when both logins exist, even if B is linked' );
assert_test( 'new@example.com already belongs to WordPress login #60. Merge the accounts in WordPress first.' === $res->get_error_message(), 'message names the other login and says to merge' );
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false );
assert_test( 'email_in_use' === err_code( $res ) && array() === st()->update_calls && 'Jordan@Example.com' === st()->meta[17]['spt_email'] && 0 === st()->orders[0]->saved, 'real run: refused before any write' );

echo "\n=== Staff logins are protected ===\n";
// Capability sets as registered by SportsPress 2.7.31 (sp_player), WooCommerce
// 11.0.1 (customer) and WordPress core (subscriber).
$role_caps  = array(
	'sp_player'  => array(
		'level_1',
		'level_0',
		'read',
		'delete_posts',
		'edit_posts',
		'upload_files',
		'edit_sp_player',
		'read_sp_player',
		'edit_sp_players',
		'edit_published_sp_players',
		'assign_sp_player_terms',
		'edit_sp_event',
		'read_sp_event',
		'edit_sp_events',
		'edit_published_sp_events',
		'assign_sp_event_terms',
		'edit_sp_team',
		'read_sp_team',
		'edit_sp_teams',
		'edit_published_sp_teams',
		'assign_sp_team_terms',
	),
	'customer'   => array( 'read' ),
	'subscriber' => array( 'read', 'level_0' ),
);
$staff_caps = array( 'edit_others_posts', 'manage_woocommerce', 'list_users', 'manage_sportspress', 'edit_others_sp_players', 'edit_others_sp_events', 'promote_users' );
foreach ( $staff_caps as $cap ) {
	fixture();
	SPT_FreeScout_Player_Actions::register();
	st()->users[12]->roles = array( 'custom' );
	st()->users[12]->caps  = array( 'read', $cap );
	$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false );
	assert_test(
		'account_protected' === err_code( $res ) && 409 === err_status( $res ) && 'jordan is a staff account. Change its email in WordPress instead.' === $res->get_error_message(),
		"old-email login with $cap: account_protected 409"
	);
	assert_test( array() === st()->update_calls && 'Jordan@Example.com' === st()->meta[17]['spt_email'] && 0 === st()->orders[0]->saved, "$cap: nothing written" );

	fixture();
	SPT_FreeScout_Player_Actions::register();
	st()->users[12]->roles = array( 'sp_player' );
	st()->users[12]->caps  = array_merge( $role_caps['sp_player'], array( $cap ) );
	$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
	assert_test( 'account_protected' === err_code( $res ), "sp_player plus $cap: account_protected" );
}

// SportsPress 2.7.31 sp_event_manager role, exactly as registered.
fixture();
SPT_FreeScout_Player_Actions::register();
st()->users[12]->roles = array( 'sp_event_manager' );
st()->users[12]->caps  = array(
	'level_1',
	'level_0',
	'read',
	'delete_posts',
	'edit_posts',
	'upload_files',
	'manage_categories',
	'edit_sp_event',
	'read_sp_event',
	'delete_sp_event',
	'edit_sp_events',
	'edit_others_sp_events',
	'publish_sp_events',
	'delete_sp_events',
	'delete_published_sp_events',
	'edit_published_sp_events',
	'manage_sp_event_terms',
	'edit_sp_event_terms',
	'delete_sp_event_terms',
	'assign_sp_event_terms',
	'edit_sp_team',
	'read_sp_team',
	'edit_sp_teams',
	'edit_published_sp_teams',
	'assign_sp_team_terms',
	'edit_sp_player',
	'read_sp_player',
	'edit_sp_players',
	'edit_published_sp_players',
	'assign_sp_player_terms',
	'edit_sp_staff',
	'read_sp_staff',
	'edit_sp_staffs',
	'edit_published_sp_staffs',
	'assign_sp_staff_terms',
);
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false );
assert_test( 'account_protected' === err_code( $res ) && array() === st()->update_calls, 'sp_event_manager role caps: account_protected, nothing written' );

fixture();
SPT_FreeScout_Player_Actions::register();
st()->super_admins = array( 12 );
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( 'account_protected' === err_code( $res ), 'super admin: account_protected' );

fixture();
SPT_FreeScout_Player_Actions::register();
// The old email is only on player 17's spt_email; its linked login is staff
// under another address.
st()->users[12]->user_email = 'coach@club.test';
st()->users[12]->caps       = array( 'read', 'edit_others_posts' );
st()->meta[17]['sp_user']   = '12';
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( 'account_protected' === err_code( $res ) && 409 === err_status( $res ), 'staff login linked through sp_user: account_protected' );

foreach ( $role_caps as $role => $caps ) {
	fixture();
	SPT_FreeScout_Player_Actions::register();
	st()->users[12]->roles = array( $role );
	st()->users[12]->caps  = $caps;
	$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false );
	assert_test(
		is_array( $res ) && in_array( "WordPress login of jordan (user #12, $role): email → new@example.com", $res['changes'], true ) && 'new@example.com' === st()->users[12]->user_email,
		"$role with exactly its role caps is allowed (login line shows the role)"
	);
}

echo "\n=== Trashed players are marked ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
st()->posts[18]->post_status = 'trash';
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( 'Player #18 Jordan Smith Jr (trashed): email → new@example.com' === $res['changes'][1] && 'Player #17 Jordan Smith: email → new@example.com' === $res['changes'][0], 'trashed player line carries (trashed)' );

echo "\n=== Allowed: new email already on the linked login ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
// Player 17 is linked to user #12, whose login is already the new address.
st()->meta[17]['sp_user'] = '12';
st()->users[12]->user_email = 'new@example.com';
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), true );
assert_test( is_array( $res ) && in_array( 'Player #17 Jordan Smith: email → new@example.com', $res['changes'], true ), 'the linked user holding the new email is not a conflict' );
assert_test( 0 === $res['data']['user_id'] && ! in_array( 'This changes the address they log in with', $res['changes'], true ), 'no login change when no login uses the old email' );

echo "\n=== Real run ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
// Something listening on profile_update rewrites the linked player's
// spt_email from the login (the sandbox symptom). The end state must still
// be the new email.
add_action(
	'profile_update',
	function () {
		st()->meta[18]['spt_email'] = 'stale@example.com';
		st()->meta[17]['spt_email'] = 'stale@example.com';
	}
);
$res = run_action( array( 'old_email' => 'Jordan@Example.com', 'new_email' => 'J.Smith@Example.COM' ), false );
assert_test( is_array( $res ) && 'Change email jordan@example.com → j.smith@example.com' === $res['summary'], 'real run succeeds' );
assert_test( 'j.smith@example.com' === st()->meta[17]['spt_email'] && 'j.smith@example.com' === st()->meta[18]['spt_email'], 'every player ends on the new, lower-cased email even after a profile_update listener rewrote it' );
assert_test( ! isset( st()->meta[40]['spt_email'] ), 'non-player post linked to the login is not given spt_email' );
assert_test( array( array( 'ID' => 12, 'user_email' => 'j.smith@example.com' ) ) === st()->update_calls, 'wp_update_user called once with the new email' );
assert_test( 'j.smith@example.com' === st()->users[12]->user_email, 'login email changed' );
assert_test( false === st()->change_filter, 'send_email_change_email returned false during the call' );
assert_test( false === st()->pass_filter, 'send_password_change_email returned false during the call' );
assert_test( true === apply_filters( 'send_email_change_email', true ) && true === apply_filters( 'send_password_change_email', true ), 'suppression removed after the call' );
assert_test( 'j.smith@example.com' === st()->user_meta[12]['billing_email'], 'billing_email user meta updated' );
foreach ( array( 0, 1 ) as $i ) {
	$order = st()->orders[ $i ];
	assert_test( 'j.smith@example.com' === $order->billing && 1 === $order->saved, "order {$order->id}: billing email set and saved" );
	assert_test(
		array( array( 'Billing email changed from jordan@example.com to j.smith@example.com from FreeScout by Cody Agent', 0, true ) ) === $order->notes,
		"order {$order->id}: attributed note names the sanitized agent"
	);
}
assert_test( 'other@example.com' === st()->orders[2]->billing && 0 === st()->orders[2]->saved, 'unrelated order untouched' );
assert_test( 'j.smith@example.com' === st()->orders[0]->spt_at_save, 'orders are written after the players' );
assert_test( 'other@example.com' === st()->meta[30]['spt_email'], 'unrelated player untouched' );

echo "\n=== Real run: agent name fallback ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
st()->users  = array();
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false, '' );
assert_test( 'Billing email changed from jordan@example.com to new@example.com from FreeScout by agent@x.test' === st()->orders[0]->notes[0][0], 'empty agent name falls back to the agent email' );

echo "\n=== Real run: login update fails ===\n";
fixture();
SPT_FreeScout_Player_Actions::register();
st()->update_error = true;
$res = run_action( array( 'old_email' => 'jordan@example.com', 'new_email' => 'new@example.com' ), false );
assert_test( 'update_failed' === err_code( $res ) && 500 === err_status( $res ), 'update_failed 500 when wp_update_user fails' );
assert_test( 'Jordan@Example.com' === st()->meta[17]['spt_email'] && 0 === st()->orders[0]->saved && 'jordan@example.com' === st()->user_meta[12]['billing_email'], 'nothing else written when the login update fails' );
assert_test( true === apply_filters( 'send_email_change_email', true ), 'suppression removed after a failed call' );

echo "\n=== Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
exit( $failed > 0 ? 1 : 0 );
