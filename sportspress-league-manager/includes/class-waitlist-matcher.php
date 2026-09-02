<?php
/**
 * Identifying waitlist products and their real counterparts.
 *
 * The league marks a season full by swapping a registration product's
 * category to a waitlist one, so the category is the signal (it used to be a
 * naming convention, which was less reliable). Matching mirrors how SPPR
 * matches its own registration category: a case-insensitive substring test
 * against a configurable keyword.
 *
 * select_target() is pure and carries the logic worth testing. The queries
 * that feed it are thin, and are verified against staging.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Matcher {

	/**
	 * Category keyword that marks a waitlist product.
	 *
	 * @return string
	 */
	public static function keyword(): string {
		return (string) get_option( 'splm_waitlist_keyword', 'waitlist' );
	}

	/**
	 * Category keyword that marks a real registration product.
	 *
	 * Deliberately reads SPPR's existing option rather than introducing a
	 * second one: if a convener renames the registration category, the
	 * registration path and the waitlist path must agree about it.
	 *
	 * @return string
	 */
	public static function registration_keyword(): string {
		return (string) get_option( 'spr_registration_keyword', 'registration' );
	}

	/**
	 * Case-insensitive substring match, matching SPPR's category test.
	 *
	 * An empty keyword never matches. Without that guard a blanked-out option
	 * would make stripos() match every product name and treat the entire
	 * catalogue as waitlist products.
	 *
	 * @param string $name    Term name.
	 * @param string $keyword Configured keyword.
	 * @return bool
	 */
	public static function matches_keyword( $name, $keyword ): bool {
		$name    = (string) $name;
		$keyword = (string) $keyword;
		if ( '' === $keyword || '' === $name ) {
			return false;
		}
		return stripos( $name, $keyword ) !== false;
	}

	/**
	 * Whether a single candidate qualifies as the target for a season and
	 * position.
	 *
	 * Owns the per-candidate rules that select_target() used to inline:
	 *
	 * - Candidates flagged `is_waitlist` are excluded. The waitlist SKU
	 *   shares its season and position with the product being searched
	 *   for, so without this exclusion it would match itself and the claim
	 *   link would loop back to the waitlist instead of a real product.
	 * - Season must match exactly; a candidate with no detectable season
	 *   (null) never matches, even against an empty `$season` — that guard
	 *   lives in select_target() so it is enforced once, not per candidate.
	 * - Position must match exactly.
	 *
	 * Pure: no WordPress calls, just array reads and comparisons.
	 *
	 * @param array  $candidate Single id/season/position/is_waitlist map.
	 * @param string $season    Season code to match.
	 * @param string $position  'player' or 'goalie'.
	 * @return bool
	 */
	private static function candidate_matches( array $candidate, $season, $position ): bool {
		if ( ! empty( $candidate['is_waitlist'] ) ) {
			return false;
		}
		if ( ( $candidate['season'] ?? null ) !== $season ) {
			return false;
		}
		return ( $candidate['position'] ?? '' ) === $position;
	}

	/**
	 * The single real product matching a season and position.
	 *
	 * Pure. Ambiguity resolves to 0 rather than a guess: the dashboard can ask
	 * a convener which product was meant, but a silently wrong target sends a
	 * player to the wrong season's checkout and cannot be undone.
	 *
	 * Per-candidate qualification (the waitlist exclusion and the season and
	 * position equality checks) lives in candidate_matches(); this method
	 * owns what a set of qualifying candidates means — the empty-season
	 * guard, the dedupe-by-id, and the exactly-one rule.
	 *
	 * @param array  $candidates List of id/season/position/is_waitlist maps.
	 * @param string $season     Season code to match.
	 * @param string $position   'player' or 'goalie'.
	 * @return int Product id, or 0 when there is not exactly one match.
	 */
	public static function select_target( array $candidates, $season, $position ): int {
		if ( '' === (string) $season ) {
			return 0;
		}

		$matches = array();
		foreach ( $candidates as $candidate ) {
			if ( ! self::candidate_matches( $candidate, $season, $position ) ) {
				continue;
			}
			// Keyed by id so the same product listed twice is one match and
			// cannot fake an ambiguity.
			$matches[ (int) $candidate['id'] ] = (int) $candidate['id'];
		}

		return count( $matches ) === 1 ? (int) reset( $matches ) : 0;
	}

	/**
	 * product_cat term ids whose name matches a keyword.
	 *
	 * @param string $keyword Configured keyword.
	 * @return int[]
	 */
	public static function category_ids_for_keyword( $keyword ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$ids = array();
		foreach ( $terms as $term ) {
			if ( self::matches_keyword( $term->name, $keyword ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		return $ids;
	}

	/**
	 * Whether a product carries the waitlist category.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_waitlist_product( $product_id ): bool {
		$ids = self::category_ids_for_keyword( self::keyword() );
		if ( empty( $ids ) ) {
			return false;
		}
		return (bool) has_term( $ids, 'product_cat', (int) $product_id );
	}

	/**
	 * The real registration product for a season and position.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $season   Season code.
	 * @param string $position 'player' or 'goalie'.
	 * @return int Product id, or 0 when ambiguous or absent.
	 */
	public static function find_target_product( $season, $position ): int {
		$registration_ids = self::category_ids_for_keyword( self::registration_keyword() );
		if ( empty( $registration_ids ) ) {
			return 0;
		}
		$waitlist_ids = self::category_ids_for_keyword( self::keyword() );

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				// Unbounded: the tax_query already constrains to registration-category
				// products (~dozen on this league's store). A cap would make truncation
				// indistinguishable from a genuinely absent pairing, corrupting the
				// ambiguity signal select_target() exists to produce.
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery
					array(
						'taxonomy' => 'product_cat',
						'field'    => 'term_id',
						'terms'    => $registration_ids,
					),
				),
			)
		);

		$candidates = array();
		foreach ( (array) $product_ids as $product_id ) {
			$candidates[] = array(
				'id'          => (int) $product_id,
				'season'      => SPAT_Season::from_product( (int) $product_id ),
				'position'    => SPAT_Season::position_from_product( (int) $product_id ),
				'is_waitlist' => ! empty( $waitlist_ids ) && has_term( $waitlist_ids, 'product_cat', (int) $product_id ),
			);
		}

		return self::select_target( $candidates, $season, $position );
	}
}
