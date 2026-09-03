<?php
/**
 * Standalone tests for notice recipient resolution.
 *
 * Getting this wrong mails a player's disciplinary notice to the wrong
 * person, so the degradation paths are pinned down as tightly as the happy
 * path: no captain, no player-tools sibling, a historical season, and the
 * deliberate absence of the digest's admin_email fallback.
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS because Codacy's
 * PHPMD Superglobals rule flags the latter, and instance properties rather
 * than statics because it flags Class::$prop[...] subscripts as undefined.
 */
class SPLM_Recipients_Test_State {
	/** post_id => array( meta_key => value ). */
	public $meta = array();

	/** Option name => value. */
	public $options = array();

	/** user_id => email. */
	public $users = array();

	/** post_id => post_type, for the sp_list type check. */
	public $types = array();
}

function splm_recipients_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Recipients_Test_State();
	}
	return $state;
}

/**
 * $single is honoured deliberately, and this is load-bearing.
 *
 * WordPress returns an ARRAY when $single is false. A stub that ignored the
 * parameter would let production code that forgot `, true` pass every
 * assertion in this file — and that code is not merely wrong, it is
 * dangerous: absint( array( 77 ) ) evaluates to 1, so a missing $single
 * addresses every player's disciplinary notice to user ID 1, normally the
 * site owner, and records it on the row as correct.
 */
function get_post_meta( $post_id, $key, $single = false ) {
	$meta  = splm_recipients_test_state()->meta;
	$value = isset( $meta[ (int) $post_id ][ $key ] ) ? $meta[ (int) $post_id ][ $key ] : '';

	if ( $single ) {
		return $value;
	}

	return '' === $value ? array() : array( $value );
}

function get_option( $name, $default = false ) {
	$options = splm_recipients_test_state()->options;
	return array_key_exists( $name, $options ) ? $options[ $name ] : $default;
}

function get_post_type( $post_id ) {
	$types = splm_recipients_test_state()->types;
	return isset( $types[ (int) $post_id ] ) ? $types[ (int) $post_id ] : false;
}

function get_userdata( $user_id ) {
	$users = splm_recipients_test_state()->users;
	if ( ! isset( $users[ (int) $user_id ] ) ) {
		return false;
	}
	return (object) array( 'user_email' => $users[ (int) $user_id ] );
}

function is_email( $email ) {
	return (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[^@\s]+$/', (string) $email ) ? $email : false;
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function absint( $v ) {
	return abs( (int) $v );
}

require_once __DIR__ . '/../includes/class-discipline-notice-recipients.php';

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

$r     = 'SPLM_Discipline_Notice_Recipients';
$state = splm_recipients_test_state();

echo "\n=== the player address chain ===\n\n";

$state->meta[10] = array( 'spt_email' => 'direct@example.test' );
$found = $r::player_email( 10 );
assert_test( 'direct@example.test' === $found['email'], 'spt_email is used when present' );
assert_test( 'spt_email' === $found['via'], 'the resolution path is recorded' );

$state->meta[11] = array( 'sp_user' => 77 );
$state->users[77] = 'linked@example.test';
$fallback = $r::player_email( 11 );
assert_test( 'linked@example.test' === $fallback['email'], 'the linked user email is the fallback' );
assert_test( 'sp_user' === $fallback['via'], 'the fallback path is recorded distinctly' );

$state->meta[12] = array( 'spt_email' => 'first@example.test', 'sp_user' => 77 );
assert_test( 'spt_email' === $r::player_email( 12 )['via'], 'spt_email wins when both exist' );

echo "\n=== the player address chain degrades ===\n\n";

$none = $r::player_email( 99 );
assert_test( '' === $none['email'], 'a player with neither meta resolves to no address' );
assert_test( '' === $none['via'], 'no address means no recorded path' );

$state->meta[13] = array( 'spt_email' => 'not-an-email' );
assert_test( '' === $r::player_email( 13 )['email'], 'a malformed spt_email is rejected rather than mailed' );

$state->meta[14] = array( 'sp_user' => 4242 );
assert_test( '' === $r::player_email( 14 )['email'], 'an sp_user pointing at a missing user resolves to nothing' );

echo "\n=== the captain chain ===\n\n";

$state->meta[200] = array( 'sp_list' => 300 );
$state->types[300] = 'sp_list';
$state->meta[300] = array( 'spt_captain' => 10 );

assert_test( 'direct@example.test' === $r::captain_email( 200 ), 'a captain resolves through sp_list to an address' );

echo "\n=== the captain chain degrades silently ===\n\n";

$state->meta[201] = array();
assert_test( '' === $r::captain_email( 201 ), 'a team with no sp_list yields no captain' );

$state->meta[202] = array( 'sp_list' => 301 );
$state->types[301] = 'sp_list';
$state->meta[301] = array();
assert_test( '' === $r::captain_email( 202 ), 'a list with no spt_captain yields no captain' );

$state->meta[203] = array( 'sp_list' => 302 );
$state->types[302] = 'post';
$state->meta[302] = array( 'spt_captain' => 10 );
assert_test(
	'' === $r::captain_email( 203 ),
	'a sp_list meta pointing at something that is not an sp_list is refused rather than trusted'
);

$state->meta[204] = array( 'sp_list' => 303 );
$state->types[303] = 'sp_list';
$state->meta[303] = array( 'spt_captain' => 99 );
assert_test( '' === $r::captain_email( 204 ), 'a captain with no address yields nothing rather than an empty send' );

assert_test( '' === $r::captain_email( 0 ), 'no team means no captain' );

echo "\n=== the Bcc list ===\n\n";

$state->options['splm_default_season']                = 500;
$state->options['splm_discipline_digest_recipients']  = 'board@example.test, bad-entry, second@example.test';
$state->options['splm_discipline_notice_cc']          = 'extra@example.test';

$bcc = $r::bcc_for( 500, 200 );

assert_test( in_array( 'board@example.test', $bcc, true ), 'digest recipients are copied' );
assert_test( in_array( 'second@example.test', $bcc, true ), 'a multi-entry digest list is fully parsed' );
assert_test( ! in_array( 'bad-entry', $bcc, true ), 'a malformed digest entry is filtered out' );
assert_test( in_array( 'extra@example.test', $bcc, true ), 'the configurable cc list is copied' );
assert_test( in_array( 'direct@example.test', $bcc, true ), 'the captain is copied for the current season' );

echo "\n=== the captain is scoped to the current season ===\n\n";

$historical = $r::bcc_for( 499, 200 );
assert_test(
	! in_array( 'direct@example.test', $historical, true ),
	'the captain is NOT copied for a past season: sp_list is not season-scoped, so the captain of record may be the wrong person'
);
assert_test(
	in_array( 'board@example.test', $historical, true ),
	'the board is still copied for a past season'
);

echo "\n=== no admin_email fallback ===\n\n";

$state->options['splm_discipline_digest_recipients'] = '';
$state->options['splm_discipline_notice_cc']         = '';
$state->options['admin_email']                       = 'admin@example.test';

$empty = $r::bcc_for( 499, 0 );
assert_test( array() === $empty, 'with nothing configured the Bcc list is empty' );
assert_test(
	! in_array( 'admin@example.test', $empty, true ),
	'admin_email is NOT a fallback here, unlike the digest: silently copying the site admin on a player disciplinary notice is a privacy surprise'
);

echo "\n=== de-duplication ===\n\n";

$state->options['splm_discipline_digest_recipients'] = 'same@example.test, same@example.test';
$state->options['splm_discipline_notice_cc']         = 'same@example.test';
$deduped = $r::bcc_for( 499, 0 );
assert_test( array( 'same@example.test' ) === $deduped, 'an address listed twice is copied once' );

echo "\n=== the meta reads pass \$single ===\n\n";

// Regression guard. Without `, true` WordPress hands back an array, and
// absint( array( 77 ) ) is 1 — so a player with sp_user meta would resolve to
// user ID 1, normally the site owner, and the notice would be addressed to
// them and recorded on the row as correct. This asserts the stub's array
// behaviour is real, so the guard cannot be defeated by relaxing the stub.
assert_test(
	array() === get_post_meta( 999, 'spt_email' ),
	'the stub returns an array when $single is omitted, mirroring WordPress'
);
assert_test(
	array( 'direct@example.test' ) === get_post_meta( 10, 'spt_email' ),
	'a present value is still wrapped in an array when $single is omitted'
);
assert_test( 1 === absint( array( 77 ) ), 'absint() of an array is 1, which is why $single matters' );

echo "\n=== Results ===\n\n";
echo "Passed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
