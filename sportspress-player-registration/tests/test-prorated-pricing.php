<?php
/**
 * Standalone tests for SPPR_Prorated_Pricing.
 *
 * The worked example from the request this feature was built for: 18 total
 * game-dates at $180 regular price, 3 remaining -> $30. Also covers: not
 * tagged, no season match, no schedule yet (never discount blind), season
 * fully elapsed (floor at one game's worth), and the sale-price slot only
 * activating when there's an actual discount.
 *
 * Usage: php test-prorated-pricing.php
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}

// ---------------------------------------------------------------------------
// Mock state
// ---------------------------------------------------------------------------

$GLOBALS['pp_test_today']        = '2026-11-15';
$GLOBALS['pp_test_product_tags'] = array(); // product_id => array( tag names )
$GLOBALS['pp_test_product_cats'] = array(); // product_id => array( cat names )
$GLOBALS['pp_test_titles']       = array(); // product_id => title
$GLOBALS['pp_test_season_terms'] = array(); // season code => term_id
$GLOBALS['pp_test_event_dates']  = array(); // term_id => array( 'Y-m-d', ... )
$GLOBALS['pp_test_transients']   = array();

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( ...$args ) {}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type = 'mysql' ) {
		return $GLOBALS['pp_test_today'];
	}
}

if ( ! function_exists( 'get_the_title' ) ) {
	function get_the_title( $id ) {
		return $GLOBALS['pp_test_titles'][ $id ] ?? '';
	}
}

if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
		if ( 'product_tag' === $taxonomy ) {
			$names = $GLOBALS['pp_test_product_tags'][ $id ] ?? array();
			return ( 'names' === ( $args['fields'] ?? '' ) )
				? $names
				: array_map( function ( $n ) { return (object) array( 'name' => $n ); }, $names );
		}
		if ( 'product_cat' === $taxonomy ) {
			$names = $GLOBALS['pp_test_product_cats'][ $id ] ?? array();
			return array_map( function ( $n ) { return (object) array( 'name' => $n ); }, $names );
		}
		return array();
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( $code = '', $message = '' ) {}
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		if ( 'name' === $field && 'sp_season' === $taxonomy && isset( $GLOBALS['pp_test_season_terms'][ $value ] ) ) {
			return (object) array( 'term_id' => $GLOBALS['pp_test_season_terms'][ $value ], 'name' => $value );
		}
		return false;
	}
}

if ( ! function_exists( 'get_posts' ) ) {
	function get_posts( $args ) {
		$term_id = null;
		foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
			if ( ( $clause['taxonomy'] ?? '' ) === 'sp_season' ) {
				$term_id = $clause['terms'];
			}
		}
		if ( null === $term_id || empty( $GLOBALS['pp_test_event_dates'][ $term_id ] ) ) {
			return array();
		}
		// One fake post id per date is enough: the class only reads
		// get_post_field('post_date', $id) back out, keyed 1:1 here.
		return array_keys( $GLOBALS['pp_test_event_dates'][ $term_id ] );
	}
}

if ( ! function_exists( 'get_post_field' ) ) {
	function get_post_field( $field, $id ) {
		foreach ( $GLOBALS['pp_test_event_dates'] as $dates_by_fake_id ) {
			if ( isset( $dates_by_fake_id[ $id ] ) ) {
				return $dates_by_fake_id[ $id ] . ' 19:00:00';
			}
		}
		return '';
	}
}

if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return $GLOBALS['pp_test_transients'][ $key ] ?? false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $ttl = 0 ) {
		$GLOBALS['pp_test_transients'][ $key ] = $value;
		return true;
	}
}

/** Minimal WC_Product stand-in: only get_id()/get_regular_price() are read. */
class PP_Fake_Product {
	private $id;
	private $regular_price;
	public function __construct( $id, $regular_price ) {
		$this->id            = $id;
		$this->regular_price = $regular_price;
	}
	public function get_id() { return $this->id; }
	public function get_regular_price() { return $this->regular_price; }
}

/**
 * Seed one season's worth of fake sp_event dates under a fake term_id, and
 * register the season code -> term_id mapping get_term_by() reads.
 */
function pp_seed_season( $season_code, $term_id, array $dates ) {
	$GLOBALS['pp_test_season_terms'][ $season_code ] = $term_id;
	$by_fake_id = array();
	foreach ( $dates as $i => $date ) {
		$by_fake_id[ 900000 + $term_id * 100 + $i ] = $date;
	}
	$GLOBALS['pp_test_event_dates'][ $term_id ] = $by_fake_id;
}

require_once dirname( __FILE__ ) . '/../../sportspress-admin-tools/includes/class-season.php';
require_once dirname( __FILE__ ) . '/../includes/class-prorated-pricing.php';

$passed = 0;
$failed = 0;
function pp_assert( $cond, $msg ) {
	global $passed, $failed;
	if ( $cond ) { echo "✓ PASS: $msg\n"; $passed++; return true; }
	echo "✗ FAIL: $msg\n"; $failed++; return false;
}

$pricing = new SPPR_Prorated_Pricing();

// Build an 18-date season, 3 of them today-or-later (today counts as
// remaining -- a game dated today hasn't been played yet at the moment this
// price is computed), matching the request's own worked example
// (18 games/$180, 3 left/$30).
$eighteen_dates = array_merge(
	array( '2026-01-01', '2026-02-01', '2026-03-01', '2026-04-01', '2026-05-01', '2026-06-01', '2026-07-01', '2026-08-01', '2026-09-01', '2026-09-15', '2026-10-01', '2026-10-15', '2026-10-22', '2026-10-29', '2026-11-08' ), // 15 already-elapsed (strictly before today, 2026-11-15)
	array( '2026-11-15', '2026-11-22', '2026-11-29' ) // 3 remaining (today or later)
);
pp_seed_season( 'W2026-27', 1001, $eighteen_dates );

echo "=== The worked example: 18 games / \$180, 3 remaining -> \$30 ===\n\n";
$GLOBALS['pp_test_product_tags'][501] = array( 'Late Registration' );
$GLOBALS['pp_test_titles'][501]       = 'W2026-27 Player Registration - Late';
$product = new PP_Fake_Product( 501, '180.00' );

$price = $pricing->filter_price( '180.00', $product );
pp_assert( '30' === $price || '30.00' === $price || 30.0 === (float) $price, "price is \$30 (got $price)" );

$sale = $pricing->filter_sale_price( '', $product );
pp_assert( 30.0 === (float) $sale, 'sale price slot is also populated, so is_on_sale() would be true' );

echo "\n=== Not tagged -> untouched ===\n\n";
$GLOBALS['pp_test_product_tags'][502] = array(); // no tag
$GLOBALS['pp_test_titles'][502]       = 'W2026-27 Player Registration - Late';
$untagged = new PP_Fake_Product( 502, '180.00' );
pp_assert( '180.00' === $pricing->filter_price( '180.00', $untagged ), 'an untagged product\'s price is returned unchanged' );
pp_assert( '' === $pricing->filter_sale_price( '', $untagged ), 'an untagged product never gets a sale price' );

echo "\n=== Tagged but no resolvable season -> untouched ===\n\n";
$GLOBALS['pp_test_product_tags'][503] = array( 'Late Registration' );
$GLOBALS['pp_test_titles'][503]       = 'Random Merchandise';
$GLOBALS['pp_test_product_cats'][503] = array();
$no_season = new PP_Fake_Product( 503, '180.00' );
pp_assert( '180.00' === $pricing->filter_price( '180.00', $no_season ), 'a product with no season in its title/category is left alone' );

echo "\n=== Season exists but has no schedule yet -> never discount blind ===\n\n";
$GLOBALS['pp_test_product_tags'][504] = array( 'Late Registration' );
$GLOBALS['pp_test_titles'][504]       = 'S2027 Player Registration - Late';
pp_seed_season( 'S2027', 1002, array() ); // registers the term but zero events
$no_schedule = new PP_Fake_Product( 504, '150.00' );
$full_price  = $pricing->filter_price( '150.00', $no_schedule );
pp_assert( 150.0 === (float) $full_price, "full price when the season has no events yet (got $full_price)" );
pp_assert( '' === $pricing->filter_sale_price( '', $no_schedule ), 'not marked on sale when there is nothing to discount' );

echo "\n=== Season fully elapsed -> floor at one game's worth, never \$0 ===\n\n";
$GLOBALS['pp_test_product_tags'][505] = array( 'Late Registration' );
$GLOBALS['pp_test_titles'][505]       = 'S2026 Player Registration - Late';
pp_seed_season( 'S2026', 1003, array( '2026-01-01', '2026-01-08', '2026-01-15', '2026-01-22' ) ); // all in the past relative to 2026-11-15
$elapsed = new PP_Fake_Product( 505, '200.00' );
$floor_price = $pricing->filter_price( '200.00', $elapsed );
pp_assert( 50.0 === (float) $floor_price, "floors at 200/4 = \$50 (one game's worth), got $floor_price" );

echo "\n=== A different tag name doesn't opt a product in ===\n\n";
$GLOBALS['pp_test_product_tags'][506] = array( 'Waitlist' );
$GLOBALS['pp_test_titles'][506]       = 'W2026-27 Player Registration';
$waitlist = new PP_Fake_Product( 506, '180.00' );
pp_assert( '180.00' === $pricing->filter_price( '180.00', $waitlist ), 'the Waitlist tag alone does not trigger prorated pricing' );

echo "\n=== Results ===\nPassed: $passed\nFailed: $failed\n";
exit( $failed === 0 ? 0 : 1 );
