<?php if ( 'cli' !== PHP_SAPI ) { http_response_code( 403 ); exit; }
/**
 * Standalone tests for SPLM_Dashboard_Frontend::ensure_page().
 *
 * The dashboard is a page template, so it is useless without a page assigned
 * to it — and for the life of this feature nothing created one. Enabling a
 * league module gave a convener a menu item that redirected to a 404. Both
 * arl.hockey hosts needed the page made by hand.
 *
 * Provisioning has to be idempotent and has to cope with a site that already
 * has a page there, possibly renamed, retemplated or trashed, because it runs
 * on every activation and on every trip to the dashboard.
 *
 * Usage: php test-dashboard-page.php
 */

define( 'ABSPATH', __DIR__ );

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function __( $text, $domain = '' ) { // phpcs:ignore
	return $text;
}

class SPLM_Page_Test_State {
	public $options    = array();
	public $posts      = array();
	public $post_meta  = array();
	public $next_id    = 900;
	public $insert_ok  = true;
	public $inserts    = 0;
	public $update_ok  = true;
}

function splm_page_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Page_Test_State();
	}
	return $state;
}

function splm_page_reset() {
	$state            = splm_page_state();
	$state->options   = array();
	$state->posts     = array();
	$state->post_meta = array();
	$state->next_id   = 900;
	$state->insert_ok = true;
	$state->inserts   = 0;
	$state->update_ok = true;
}

class WP_Error {} // phpcs:ignore
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function get_option( $name, $default = false ) {
	$state = splm_page_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}
function update_option( $name, $value ) {
	splm_page_state()->options[ $name ] = $value;
	return true;
}
function get_post( $id ) {
	$state = splm_page_state();
	return isset( $state->posts[ $id ] ) ? $state->posts[ $id ] : null;
}
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) { // phpcs:ignore
	foreach ( splm_page_state()->posts as $post ) {
		if ( $post->post_name === $path && $post->post_type === $post_type ) {
			return $post;
		}
	}
	return null;
}
function wp_insert_post( $args ) {
	$state = splm_page_state();
	++$state->inserts;
	if ( ! $state->insert_ok ) {
		return new WP_Error();
	}
	$id                  = ++$state->next_id;
	$args['ID']          = $id;
	$state->posts[ $id ] = (object) $args;
	return $id;
}
function wp_update_post( $args, $wp_error = false ) { // phpcs:ignore
	$state = splm_page_state();
	if ( ! $state->update_ok ) {
		return $wp_error ? new WP_Error() : 0;
	}
	$id = (int) $args['ID'];
	foreach ( $args as $key => $value ) {
		$state->posts[ $id ]->$key = $value;
	}
	return $id;
}
function get_post_meta( $id, $key, $single = false ) { // phpcs:ignore
	$state = splm_page_state();
	return isset( $state->post_meta[ $id ][ $key ] ) ? $state->post_meta[ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	splm_page_state()->post_meta[ $id ][ $key ] = $value;
	return true;
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

// The class hooks WordPress in its constructor, which is never invoked here;
// only the static provisioning methods are exercised.
require_once __DIR__ . '/../includes/class-dashboard-frontend.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		++$passed;
	} else {
		echo "✗ FAIL: {$message}\n";
		++$failed;
	}
}

$F  = 'SPLM_Dashboard_Frontend';
$st = splm_page_state();

echo "\n=== a site that has never had a dashboard page ===\n\n";

splm_page_reset();
$id = $F::ensure_page();
assert_test( $id > 0, 'a page is created' );
assert_test( 1 === $st->inserts, '  exactly one' );
assert_test( 'league-dashboard' === $st->posts[ $id ]->post_name, '  at the slug the admin menu redirects to' );
assert_test( 'publish' === $st->posts[ $id ]->post_status, '  published, or the redirect lands on nothing' );
assert_test( SPLM_Dashboard_Frontend::TEMPLATE === $st->post_meta[ $id ]['_wp_page_template'], '  carrying the dashboard template' );
assert_test( $id === (int) $st->options['splm_dashboard_page_id'], '  and remembered by id' );

echo "\n=== calling it again is a no-op ===\n\n";

$again = $F::ensure_page();
assert_test( $id === $again, 'the same page comes back' );
assert_test( 1 === $st->inserts, 'no second page is created — this runs on every activation and every dashboard visit' );

echo "\n=== an install that predates provisioning ===\n\n";

// Both arl.hockey hosts: page made by hand, nothing recorded in options.
splm_page_reset();
$st->posts[500] = (object) array(
	'ID'          => 500,
	'post_type'   => 'page',
	'post_name'   => 'league-dashboard',
	'post_status' => 'publish',
);
$adopted = $F::ensure_page();
assert_test( 500 === $adopted, 'an existing page is adopted, not duplicated' );
assert_test( 0 === $st->inserts, '  with no insert at all' );
assert_test( SPLM_Dashboard_Frontend::TEMPLATE === $st->post_meta[500]['_wp_page_template'], '  and its template assignment repaired' );

echo "\n=== a page someone trashed ===\n\n";

splm_page_reset();
$st->posts[501] = (object) array(
	'ID'          => 501,
	'post_type'   => 'page',
	'post_name'   => 'league-dashboard',
	'post_status' => 'trash',
);
$restored = $F::ensure_page();
assert_test( 501 === $restored, 'the trashed page is reused' );
assert_test( 'publish' === $st->posts[501]->post_status, '  and republished rather than left in the trash behind a working menu item' );
assert_test( 0 === $st->inserts, '  with no duplicate created alongside it' );

echo "\n=== the remembered page is gone ===\n\n";

splm_page_reset();
$st->options['splm_dashboard_page_id'] = 777;   // hard-deleted since
$made                                  = $F::ensure_page();
assert_test( $made > 0 && 777 !== $made, 'a new page is created when the remembered one no longer exists' );
assert_test( $made === (int) $st->options['splm_dashboard_page_id'], '  and the stored id is updated' );

// A stored id pointing at something that is not a page must not be adopted.
splm_page_reset();
$st->posts[600]                        = (object) array(
	'ID'          => 600,
	'post_type'   => 'product',
	'post_name'   => 'something-else',
	'post_status' => 'publish',
);
$st->options['splm_dashboard_page_id'] = 600;
$safe                                  = $F::ensure_page();
assert_test( 600 !== $safe, 'a stored id pointing at a non-page is not adopted' );
assert_test( '' === get_post_meta( 600, '_wp_page_template', true ), '  and that post is left untouched' );

echo "\n=== provisioning fails ===\n\n";

splm_page_reset();
$st->insert_ok = false;
assert_test( 0 === $F::ensure_page(), 'a failed insert reports 0 rather than a WP_Error the caller would redirect to' );

echo "\n=== a page that exists but is not published ===\n\n";

// draft, pending, future and private all leave the pretty permalink
// unresolvable, so adopting one and handing back its id sends the caller to
// the same 404 this method exists to prevent.
foreach ( array( 'draft', 'pending', 'future', 'private' ) as $status ) {
	splm_page_reset();
	$st->posts[520] = (object) array(
		'ID'          => 520,
		'post_type'   => 'page',
		'post_name'   => 'league-dashboard',
		'post_status' => $status,
	);
	$adopted = $F::ensure_page();
	assert_test( 520 === $adopted && 'publish' === $st->posts[520]->post_status, "a {$status} page found by slug is published before it is used" );
}

// Same again for the remembered id, which takes a different branch.
foreach ( array( 'draft', 'private' ) as $status ) {
	splm_page_reset();
	$st->posts[521]                        = (object) array(
		'ID'          => 521,
		'post_type'   => 'page',
		'post_name'   => 'renamed-dashboard',
		'post_status' => $status,
	);
	$st->options['splm_dashboard_page_id'] = 521;
	$adopted                               = $F::ensure_page();
	assert_test( 521 === $adopted && 'publish' === $st->posts[521]->post_status, "a remembered {$status} page is published before it is used" );
}

// An already-published page is left alone rather than rewritten on every call.
splm_page_reset();
$st->posts[522] = (object) array(
	'ID'          => 522,
	'post_type'   => 'page',
	'post_name'   => 'league-dashboard',
	'post_status' => 'publish',
);
$st->update_ok  = false;   // any wp_update_post() call would now fail
assert_test( 522 === $F::ensure_page(), 'a published page is adopted without being rewritten' );

echo "\n=== the page cannot be published ===\n\n";

splm_page_reset();
$st->posts[523] = (object) array(
	'ID'          => 523,
	'post_type'   => 'page',
	'post_name'   => 'league-dashboard',
	'post_status' => 'draft',
);
$st->update_ok  = false;
assert_test( 0 === $F::ensure_page(), 'a page that will not publish is reported as a provisioning failure, not handed back unusable' );
assert_test( ! isset( $st->post_meta[523]['_wp_page_template'] ), '  and it is not claimed as the dashboard on the way out' );

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
