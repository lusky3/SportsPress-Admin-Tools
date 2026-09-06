<?php
/**
 * Tier 2: a real WooCommerce order completion creates a real sp_player.
 * Exercises the exact path that crashed on sp_team being a post type, not a
 * taxonomy ("a player registered but the order was flagged as failed"), until
 * real order data flowed through it this session.
 *
 * Run via: wp eval-file bin/live-env/smoke-registration.php --path=<wp root> --allow-root
 */

$GLOBALS['failures'] = array();

// wp eval-file executes the whole file inside a method body (its own --help
// says as much: "because code is executed within a method, global variables
// need to be explicitly globalized"), so a plain top-level $failures is a
// local to that invocation, invisible to global $failures inside a nested
// function. $GLOBALS is the only storage both this file's top level and
// check() can see consistently -- used on every reference, not just inside
// check(), so nothing here silently diverges from what the exit check reads.
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

if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'SPPR_Player_Registration' ) ) {
	fwrite( STDERR, "WooCommerce or SPPR_Player_Registration not loaded -- run smoke-activation.php first\n" );
	exit( 2 );
}

// A category whose name contains "Registration" (spr_registration_keyword's
// default), plus the season code embedded in the product TITLE --
// SPAT_Season::from_title() is the primary lookup path, and every real
// fixture on Tikal/Sonic is named this way.
$reg_cat       = get_term_by( 'name', 'Registration', 'product_cat' );
$reg_cat_id    = $reg_cat ? $reg_cat->term_id : wp_insert_term( 'Registration', 'product_cat' )['term_id'];
$player_tag    = get_term_by( 'name', 'Player', 'product_tag' );
$player_tag_id = $player_tag ? $player_tag->term_id : wp_insert_term( 'Player', 'product_tag' )['term_id'];

$product = new WC_Product_Simple();
$product->set_name( 'Player Registration (S2026)' );
$product->set_regular_price( '0' );
$product->set_price( '0' );
$product->set_virtual( true );
$product->set_status( 'publish' );
$product->set_category_ids( array( $reg_cat_id ) );
$product->set_tag_ids( array( $player_tag_id ) );
$product_id = $product->save();

$order = wc_create_order();
$order->add_product( wc_get_product( $product_id ), 1 );
$order->set_billing_email( 'smoke-test@example.test' );
$order->set_billing_first_name( 'Smoke' );
$order->set_billing_last_name( 'Test' );
$order->calculate_totals();
$order->save();
$order->update_status( 'completed' );

$order = wc_get_order( $order->get_id() ); // re-fetch: status hooks may have modified it

check(
	'1' === $order->get_meta( '_spr_processed' ),
	'order not flagged failed (_spr_processed=' . $order->get_meta( '_spr_processed' ) . ')'
);

$players = get_posts(
	array(
		'post_type'      => 'sp_player',
		'posts_per_page' => 1,
		'meta_key'       => 'spt_email', // phpcs:ignore WordPress.DB.SlowDBQuery
		'meta_value'     => 'smoke-test@example.test', // phpcs:ignore WordPress.DB.SlowDBQuery
	)
);
check( ! empty( $players ), "a sp_player was created for the order's billing email" );

if ( ! empty( $players ) ) {
	$player_id = $players[0]->ID;
	$seasons   = wp_get_object_terms( $player_id, 'sp_season', array( 'fields' => 'names' ) );
	check( in_array( 'S2026', (array) $seasons, true ), 'the player carries the S2026 season term' );
}

if ( ! empty( $GLOBALS['failures'] ) ) {
	fwrite( STDERR, "\n" . count( $GLOBALS['failures'] ) . " check(s) failed:\n" );
	foreach ( $GLOBALS['failures'] as $f ) {
		fwrite( STDERR, "  - $f\n" );
	}
	exit( 1 );
}
echo "\nAll registration-flow checks passed.\n";
