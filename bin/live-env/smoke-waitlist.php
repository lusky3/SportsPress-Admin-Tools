<?php
/**
 * Tier 3: the waitlist flow this session hand-verified against Tikal three
 * times -- ingest, offer, claim, purchase, tie-back. Exercises the exact
 * category+tag matcher split SPLM_Waitlist_Matcher was fixed to understand
 * this session.
 *
 * Run via: wp eval-file bin/live-env/smoke-waitlist.php --path=<wp root> --allow-root
 */

$GLOBALS['failures'] = array();

// Same wp eval-file scope quirk as smoke-registration.php: the whole file
// runs inside a method body, so $GLOBALS is used uniformly rather than a
// plain top-level $failures that a nested function's `global` could not see.
/**
 * @SuppressWarnings(PHPMD.Superglobals) -- $GLOBALS is required here: wp
 * eval-file executes this whole script inside a method body, so a plain
 * `global $failures;` inside a nested function does not work (see wp help
 * eval-file). $GLOBALS is the only scoping mechanism that reaches back to
 * the file-level $failures array from inside check().
 */
function check( $condition, $message ) {
	echo ( $condition ? 'OK   ' : 'FAIL ' ) . $message . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI diagnostic output (wp eval-file), never rendered to a browser.
	if ( ! $condition ) {
		$GLOBALS['failures'][] = $message;
	}
}

if ( ! class_exists( 'SPLM_Waitlist_Database' ) ) {
	fwrite( STDERR, "SPLM_Waitlist_Database not loaded -- run smoke-activation.php first\n" );
	exit( 2 );
}

$reg_cat         = get_term_by( 'name', 'Registration', 'product_cat' );
$reg_cat_id      = $reg_cat ? $reg_cat->term_id : wp_insert_term( 'Registration', 'product_cat' )['term_id'];
$player_tag      = get_term_by( 'name', 'Player', 'product_tag' );
$player_tag_id   = $player_tag ? $player_tag->term_id : wp_insert_term( 'Player', 'product_tag' )['term_id'];
$waitlist_tag    = get_term_by( 'name', 'Waitlist', 'product_tag' );
$waitlist_tag_id = $waitlist_tag ? $waitlist_tag->term_id : wp_insert_term( 'Waitlist', 'product_tag' )['term_id'];

// S2027, deliberately different from smoke-registration.php's S2026: both
// fixtures publish into the same Registration category, and the matcher's
// select_target() answers ambiguity across same-season products in that
// category with 0 -- sharing a season code here would silently break "the
// matcher resolves..." below.
// Target: a real registration product, same convention as smoke-registration.php.
$target = new WC_Product_Simple();
$target->set_name( 'Player Registration (S2027)' );
$target->set_regular_price( '575' );
$target->set_price( '575' );
$target->set_status( 'publish' );
$target->set_category_ids( array( $reg_cat_id ) );
$target->set_tag_ids( array( $player_tag_id ) );
$target_id = $target->save();

// Waitlist: the SAME registration category (so it carries both markers, the
// way real waitlist products do) plus the Waitlist tag -- the exact split
// SPLM_Waitlist_Matcher was fixed to understand this session.
$waitlist = new WC_Product_Simple();
$waitlist->set_name( 'Player Waitlist (S2027)' );
$waitlist->set_regular_price( '0' );
$waitlist->set_price( '0' );
$waitlist->set_virtual( true );
$waitlist->set_status( 'publish' );
$waitlist->set_category_ids( array( $reg_cat_id ) );
$waitlist->set_tag_ids( array( $player_tag_id, $waitlist_tag_id ) );
$waitlist_id = $waitlist->save();

check(
	$target_id === SPLM_Waitlist_Matcher::find_target_product( 'S2027', 'player' ),
	"the matcher resolves the waitlist product's target from category + tag"
);

$order = wc_create_order();
$order->add_product( wc_get_product( $waitlist_id ), 1 );
$order->set_billing_email( 'waitlist-smoke@example.test' );
$order->calculate_totals();
$order->save();
$order->update_status( 'completed' );

global $wpdb;
$table = SPLM_Waitlist_Database::table_name();
$row   = $wpdb->get_row(
	$wpdb->prepare(
		"SELECT * FROM {$table} WHERE email=%s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value; cannot use a placeholder.
		'waitlist-smoke@example.test'
	)
);
check( null !== $row, 'a waitlist row was ingested from the completed order' );
check( null !== $row && (int) $row->target_product_id === $target_id, "the row's target_product_id points at the real registration product" );
check( null !== $row && 'queued' === $row->status, 'the row starts queued' );

if ( null === $row ) {
	fwrite( STDERR, "\nIngestion failed -- cannot continue to offer/claim.\n" );
	exit( 1 );
}

$offer = SPLM_Waitlist_Offer::offer( $row->id, 48 );
check( ! is_wp_error( $offer ), 'offer() succeeds' );

$row = SPLM_Waitlist_Database::get( $row->id );
check( null !== $row && 64 === strlen( (string) $row->claim_token ), 'a 64-char claim token is generated' );

$pending = false;
foreach ( _get_cron_array() as $hooks ) {
	if ( isset( $hooks['splm_waitlist_expire_offer'] ) ) {
		$pending = true;
	}
}
check( $pending, 'an expiry event is scheduled' );

$claim_url = home_url( '/wp-json/splm/v1/waitlist/claim/' . $row->claim_token );
$response  = wp_remote_get( $claim_url, array( 'redirection' => 0 ) );
check( ! is_wp_error( $response ) && 302 === wp_remote_retrieve_response_code( $response ), 'the claim route redirects (302)' );

$after_claim_view = SPLM_Waitlist_Database::get( $row->id );
check(
	null !== $after_claim_view && $after_claim_view->claim_token === $row->claim_token,
	'viewing the claim link does not consume it (prefetch-safety)'
);

$purchase = wc_create_order();
$purchase->add_product( wc_get_product( $target_id ), 1 );
$purchase->set_billing_email( 'waitlist-smoke@example.test' );
$purchase->save();
// Bind the claim token onto the line item the way the real cart flow does via
// SPLM_Waitlist_Claim's cart-item-data filter -- this script drives
// WooCommerce's order API directly rather than a real cart/checkout session,
// so the binding is done by hand here.
foreach ( $purchase->get_items() as $item ) {
	if ( (int) $item->get_product_id() === (int) $target_id ) {
		$item->add_meta_data( '_splm_waitlist_id', $row->claim_token, true );
		$item->save();
	}
}
$purchase->calculate_totals();
$purchase->save();
$purchase->update_status( 'completed' );

$final = SPLM_Waitlist_Database::get( $row->id );
check( null !== $final && 'claimed' === $final->status, 'the row lands claimed (status=' . ( $final->status ?? 'MISSING' ) . ')' );
check( null !== $final && (int) $final->resolved_order_id === $purchase->get_id(), 'resolved_order_id points at the completing order' );
check( null !== $final && null === $final->claim_token, 'the claim token is cleared' );

if ( ! empty( $GLOBALS['failures'] ) ) {
	fwrite( STDERR, "\n" . count( $GLOBALS['failures'] ) . " check(s) failed:\n" );
	foreach ( $GLOBALS['failures'] as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}
echo "\nAll waitlist-flow checks passed.\n";
