<?php
/**
 * Season and position parsing for WooCommerce registration products.
 *
 * These conventions are edited by hand by the league every season — the
 * season code format and the goalie tag name — so they live in exactly one
 * place. Both SPPR_Player_Registration (which registers paid players) and
 * SPLM_Waitlist (which queues them) call this; the regexes below were
 * extracted verbatim from SPPR's formerly-private methods, and
 * tests/test-season-helper.php asserts that parity.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Season {

	/**
	 * Season code embedded anywhere in a product title.
	 *
	 * @param string $title Product title.
	 * @return string|null Season code, or null when absent.
	 */
	public static function from_title( $title ) {
		if ( preg_match( '/\b([WS]\d{4}(?:-\d{2})?)\b/', (string) $title, $matches ) ) {
			return $matches[1];
		}
		return null;
	}

	/**
	 * Season code when a category name IS a season code.
	 *
	 * Anchored deliberately: a category merely containing a code ("S2026
	 * Registration") is a registration category, not a season category.
	 *
	 * @param string $name Category name.
	 * @return string|null Season code, or null.
	 */
	public static function from_category_name( $name ) {
		if ( preg_match( '/^[WS]\d{4}(-\d{2})?$/', (string) $name ) ) {
			return (string) $name;
		}
		return null;
	}

	/**
	 * Whether a product tag name marks a goalie product.
	 *
	 * Exact match, not a substring: "goalies" must not match, because that is
	 * how SPPR has always behaved and a league tag rename should be a
	 * deliberate edit here rather than a silent behaviour change.
	 *
	 * @param string $name Tag name.
	 * @return bool
	 */
	public static function is_goalie_tag_name( $name ) {
		return strtolower( trim( (string) $name ) ) === 'goalie';
	}

	/**
	 * Season for a product: title first, then its categories.
	 *
	 * @param int $product_id Product post ID.
	 * @return string|null Season code, or null.
	 */
	public static function from_product( $product_id ) {
		$from_title = self::from_title( get_the_title( $product_id ) );
		if ( null !== $from_title ) {
			return $from_title;
		}

		$categories = wp_get_post_terms( $product_id, 'product_cat' );
		if ( is_array( $categories ) ) {
			foreach ( $categories as $category ) {
				$code = self::from_category_name( $category->name );
				if ( null !== $code ) {
					return $code;
				}
			}
		}

		return null;
	}

	/**
	 * Position for a product, from its product tags.
	 *
	 * The spr_is_goalie_tag filter keeps its original three arguments so any
	 * existing consumer registered against SPPR's version still works.
	 *
	 * @param int   $product_id Product post ID.
	 * @param mixed $product    Optional WC_Product, passed to the filter.
	 * @return string 'goalie' or 'player'.
	 */
	public static function position_from_product( $product_id, $product = null ) {
		$tags = wp_get_post_terms( $product_id, 'product_tag' );
		if ( is_array( $tags ) ) {
			foreach ( $tags as $tag ) {
				$matched = self::is_goalie_tag_name( $tag->name );
				if ( apply_filters( 'spr_is_goalie_tag', $matched, $tag, $product ) ) {
					return 'goalie';
				}
			}
		}

		return 'player';
	}
}
