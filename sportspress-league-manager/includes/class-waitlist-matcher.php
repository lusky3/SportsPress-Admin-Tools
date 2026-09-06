<?php
/**
 * Identifying waitlist products and their real counterparts.
 *
 * The league marks a season full by publishing a waitlist counterpart of the
 * registration product. The marker is a term whose name contains a keyword,
 * matched the same case-insensitive-substring way SPPR matches its own
 * registration category — but it is looked for in BOTH product_cat and
 * product_tag, because on the live store the waitlist marker is a tag while
 * the registration marker is a category. See MARKER_TAXONOMIES.
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
	 * Taxonomies a keyword may be applied through.
	 *
	 * The design assumed the marker was always a product category. The store
	 * disagrees: every waitlist product this league has ever published — eight
	 * of them, S2024 through W2026-27 — carries a `Waitlist` product *tag* and
	 * sits in the ordinary `Registration` category, and no waitlist category
	 * has ever existed. Reading only `product_cat` therefore made
	 * is_waitlist_product() constantly false, so ingestion never fired and a
	 * waitlist SKU could match itself as its own target.
	 *
	 * Both taxonomies are consulted rather than swapping one for the other,
	 * because the convention is edited by hand each season and has already
	 * changed once (naming convention, then tag). Order matters only for
	 * short-circuiting.
	 *
	 * @var string[]
	 */
	const MARKER_TAXONOMIES = array( 'product_cat', 'product_tag' );

	/**
	 * Term ids in one taxonomy whose name matches a keyword.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @param string $keyword  Configured keyword.
	 * @return int[]
	 */
	public static function term_ids_for_keyword( $taxonomy, $keyword ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
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
	 * product_cat term ids whose name matches a keyword.
	 *
	 * @param string $keyword Configured keyword.
	 * @return int[]
	 */
	public static function category_ids_for_keyword( $keyword ): array {
		return self::term_ids_for_keyword( 'product_cat', $keyword );
	}

	/**
	 * Matching term ids for a keyword, keyed by taxonomy.
	 *
	 * Resolved once per query rather than per product: has_marker() runs
	 * across every registration-categorised product in find_target_product(),
	 * and re-deriving the term list inside that loop would multiply the term
	 * queries by the size of the catalogue.
	 *
	 * Taxonomies with no matching term are omitted, so an empty map means the
	 * keyword marks nothing anywhere.
	 *
	 * @param string $keyword Configured keyword.
	 * @return array<string,int[]>
	 */
	public static function marker_terms( $keyword ): array {
		$map = array();
		foreach ( self::MARKER_TAXONOMIES as $taxonomy ) {
			$ids = self::term_ids_for_keyword( $taxonomy, $keyword );
			if ( ! empty( $ids ) ) {
				$map[ $taxonomy ] = $ids;
			}
		}
		return $map;
	}

	/**
	 * Whether a product carries any of the marker terms.
	 *
	 * An empty map is not a match — a keyword that names no term must not
	 * mark every product.
	 *
	 * @param int                 $product_id Product post ID.
	 * @param array<string,int[]> $markers    Map from marker_terms().
	 * @return bool
	 */
	public static function has_marker( $product_id, array $markers ): bool {
		foreach ( $markers as $taxonomy => $ids ) {
			if ( has_term( $ids, $taxonomy, (int) $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a product is marked as a waitlist product.
	 *
	 * @param int $product_id Product post ID.
	 * @return bool
	 */
	public static function is_waitlist_product( $product_id ): bool {
		return self::has_marker( $product_id, self::marker_terms( self::keyword() ) );
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
		// Registration stays CATEGORY-ONLY while the waitlist marker is read
		// from either taxonomy, and the asymmetry is deliberate rather than an
		// oversight to tidy up.
		//
		// The two markers do opposite things to the candidate set. A waitlist
		// marker EXCLUDES a product, so finding one in an extra taxonomy can
		// only ever narrow the search — worst case a season has no target and
		// the dashboard flags the row for a human. A registration marker
		// INCLUDES a product, so widening it admits candidates: one ordinary
		// product tagged `Registration` for the same season and position is
		// enough to make the real product ambiguous, and select_target()
		// answers ambiguity with 0 — which refuses every offer for that
		// season. That is the exact failure this matcher was just repaired
		// for, reintroduced from the other side.
		//
		// On this store `Registration` is a category and `Waitlist` is a tag,
		// so each keyword is read where it actually lives.
		$registration_ids = self::category_ids_for_keyword( self::registration_keyword() );
		if ( empty( $registration_ids ) ) {
			return 0;
		}
		$waitlist_markers = self::marker_terms( self::keyword() );

		$product_ids = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				// Password-protected products are excluded. This league keeps a
				// second registration product per season for late signups
				// (117085, 116120, 113061 on the live store) and protects it
				// with a post password, which is what "closed to the public"
				// means here. Counting it made every recent season ambiguous,
				// so select_target() returned 0 and every offer was refused —
				// and had it won instead, the claim link would have landed the
				// invitee on WordPress's password form rather than a checkout.
				'has_password'   => false,
				// Unbounded: the tax_query already constrains to registration-categorised
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
				'is_waitlist' => self::has_marker( (int) $product_id, $waitlist_markers ),
			);
		}

		return self::select_target( $candidates, $season, $position );
	}
}
