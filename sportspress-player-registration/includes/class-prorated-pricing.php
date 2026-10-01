<?php
/**
 * Prorated Late-Registration Pricing
 *
 * Automatically discounts a product tagged "Late Registration" as the season
 * it belongs to progresses: price = regular_price * (games remaining / total
 * games), counted from the real, already-generated schedule rather than any
 * manually-maintained counter. Never discounts a season with no schedule yet
 * (proration = 1.0), and never lets a fully-elapsed season's product go to
 * $0 -- it floors at one game's worth.
 *
 * "Games" here means distinct scheduled DATES, not sp_event post count: on
 * this store's shared calendar many divisions play concurrently on the same
 * date, so counting posts would overcount what a single team's season looks
 * like. Playoffs are excluded on purpose -- a late-reg buyer is paying for
 * remaining REGULAR-season games, not a playoff spot they may not reach, and
 * SportsPress gives postseason events their own child sp_season term (e.g.
 * "W2026-27 Playoffs" under "W2026-27"), so include_children => false is
 * enough to keep them out without any extra bookkeeping.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Prorated_Pricing {

	/**
	 * The product_tag name that opts a product into this pricing. Mirrors
	 * this store's existing 'Waitlist' tag convention (see SPAT_Season's
	 * docblock and arl-store-conventions) rather than the site's separate
	 * "password protected = closed to the public" convention, which marks a
	 * different thing and would silently start discounting some future
	 * unrelated password-protected product.
	 */
	const LATE_REGISTRATION_TAG = 'Late Registration';

	/**
	 * How long a season's proration is cached. The value only changes once a
	 * day (when "today" crosses into the next scheduled date), so this is
	 * pure cost hygiene, not correctness -- the cache key bakes in today's
	 * date, so a stale hit is never served past midnight regardless of TTL.
	 */
	const CACHE_TTL = HOUR_IN_SECONDS;

	public function __construct() {
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 10, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 10, 2 );
	}

	/**
	 * The active price a customer pays. Always returns the prorated amount
	 * for an eligible product -- not min($price, prorated) -- because the
	 * stored `_price` meta was only ever correct at the moment the product
	 * was last saved and does not itself change day to day.
	 */
	public function filter_price( $price, $product ) {
		if ( ! $this->is_eligible( $product ) ) {
			return $price;
		}
		$prorated = $this->prorated_price( $product );
		return null === $prorated ? $price : $prorated;
	}

	/**
	 * Reusing WooCommerce's own sale-price slot (rather than writing a custom
	 * price_html filter) makes is_on_sale() and get_price_html() do the right
	 * thing for free: the regular price the admin entered still renders in
	 * <del>, and the prorated amount renders in <ins>, in cart, checkout, and
	 * everywhere else Woo already knows how to show a sale. Returns '' (not
	 * on sale) once proration is back at 1.0, so a season with no schedule
	 * yet doesn't show a same-price "sale" badge.
	 */
	public function filter_sale_price( $price, $product ) {
		if ( ! $this->is_eligible( $product ) ) {
			return $price;
		}
		$proration = $this->proration_for_product( $product );
		if ( null === $proration || $proration >= 1.0 ) {
			return '';
		}
		$prorated = $this->prorated_price( $product );
		return null === $prorated ? $price : $prorated;
	}

	/**
	 * @param WC_Product $product Product being priced.
	 * @return string|null Prorated price as a WooCommerce-shaped decimal
	 *                      string, or null when there's nothing sane to
	 *                      prorate against (no regular price set).
	 */
	private function prorated_price( $product ) {
		$proration = $this->proration_for_product( $product );
		if ( null === $proration ) {
			return null;
		}
		$regular = $product->get_regular_price();
		if ( '' === $regular || (float) $regular <= 0 ) {
			return null;
		}
		return (string) round( (float) $regular * $proration, 2 );
	}

	/**
	 * @param WC_Product $product Product being priced.
	 * @return float|null Proration factor in [1/total_dates, 1.0], or null
	 *                     when the product isn't tied to a resolvable season.
	 */
	private function proration_for_product( $product ) {
		$season = SPAT_Season::from_product( $product->get_id() );
		if ( null === $season ) {
			return null;
		}
		return $this->proration_for_season( $season );
	}

	/**
	 * @param WC_Product $product Product to check.
	 * @return bool Whether this product carries the LATE_REGISTRATION_TAG and
	 *              resolves to a real season code.
	 */
	private function is_eligible( $product ) {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return false;
		}
		$tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		if ( ! is_array( $tags ) || ! in_array( self::LATE_REGISTRATION_TAG, $tags, true ) ) {
			return false;
		}
		return null !== SPAT_Season::from_product( $product->get_id() );
	}

	/**
	 * @param string $season_code e.g. "W2026-27".
	 * @return float
	 */
	private function proration_for_season( $season_code ) {
		$cache_key = 'sppr_proration_' . md5( $season_code ) . '_' . current_time( 'Y-m-d' );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return (float) $cached;
		}

		$proration = $this->compute_proration( $season_code );
		set_transient( $cache_key, $proration, self::CACHE_TTL );
		return $proration;
	}

	private function compute_proration( $season_code ) {
		$term = get_term_by( 'name', $season_code, 'sp_season' );
		if ( ! $term || is_wp_error( $term ) ) {
			// No such season term exists at all -- never discount blind.
			return 1.0;
		}

		$dates = $this->distinct_event_dates( $term->term_id );
		$total = count( $dates );
		if ( 0 === $total ) {
			// The season's schedule hasn't been generated/published yet.
			return 1.0;
		}

		$today     = current_time( 'Y-m-d' );
		$remaining = count(
			array_filter(
				$dates,
				function ( $date ) use ( $today ) {
					return $date >= $today;
				}
			)
		);

		if ( $remaining > 0 ) {
			return $remaining / $total;
		}

		// The season is over: floor at one game's worth rather than $0.
		return 1 / $total;
	}

	/**
	 * Distinct 'Y-m-d' match dates for a season, excluding its postseason
	 * child term. Match date is the event's own post_date -- schedule
	 * generation sets it directly (game date + time slot) rather than a
	 * separate meta key.
	 *
	 * Queried post_status is ('publish', 'future'), not just 'publish':
	 * WordPress silently downgrades a post inserted with post_status
	 * 'publish' but a post_date in the future to 'future' instead (only
	 * flipping it to 'publish' itself once that date arrives, via its own
	 * publish cron) -- confirmed live on staging, where a schedule
	 * generated ahead of time left most of the season's own events sitting
	 * as 'future' for months. Excluding that status would make total_dates
	 * collapse to only whatever has already been played, undercounting
	 * both the total and (for a season still in progress) the remaining
	 * count it most needs to be right for.
	 *
	 * @param int $term_id sp_season term id (the REGULAR season, not its
	 *                      "... Playoffs" child).
	 * @return string[] Sorted-by-nothing-in-particular list of 'Y-m-d' dates.
	 */
	private function distinct_event_dates( $term_id ) {
		$ids = get_posts(
			array(
				'post_type'              => 'sp_event',
				'post_status'            => array( 'publish', 'future' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array(
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => $term_id,
						'include_children' => false,
					),
				),
			)
		);

		$dates = array();
		foreach ( (array) $ids as $id ) {
			$date = get_post_field( 'post_date', $id );
			if ( $date ) {
				$dates[ substr( $date, 0, 10 ) ] = true;
			}
		}
		return array_keys( $dates );
	}
}
