<?php
/**
 * Standings page automation for the season rollover.
 *
 * The live site keeps three things in step by hand every season:
 *
 *   /standings                     the current season's division tables
 *   /standings/playoffs            the current season's playoff tables
 *   /standings/past-standings/...  one archive page per finished season,
 *                                  combining that season's regular and playoff
 *                                  tables into a single page
 *
 * Each of those hardcodes a [team_standings <id>] shortcode per division —
 * twelve of them for W2025-26 — so a rollover currently means rebuilding a page
 * by hand from table IDs that the rollover itself just produced.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-standings-content.php';

class SPEM_Standings_Pages {

	/**
	 * Update the live standings pages and archive the outgoing season.
	 *
	 * The two live pages move together. They are meant to describe one season,
	 * and the only reason they ever disagree is a half-finished manual move —
	 * on staging right now /standings shows S2026 while /standings/playoffs
	 * still shows W2025-26. Rather than bake that mismatch into an archive, this
	 * refuses to run when the outgoing pages point at different seasons and says
	 * which ones, leaving the operator to reconcile them first.
	 *
	 * @param int[] $regular_ids New regular-season table IDs, in division order.
	 * @param int[] $playoff_ids New playoff table IDs.
	 * @return array{archived:string, updated:int, skipped:string} Result summary.
	 */
	public function update( array $regular_ids, array $playoff_ids ) {
		$standings_id = (int) get_option( 'spem_standings_page_id', 0 );
		$playoffs_id  = (int) get_option( 'spem_playoffs_page_id', 0 );
		$archive_id   = (int) get_option( 'spem_standings_archive_parent_id', 0 );

		$result = array(
			'archived' => '',
			'updated'  => 0,
			'skipped'  => '',
		);

		if ( ! $standings_id ) {
			$result['skipped'] = __( 'No standings page is configured.', 'sportspress-events-manager' );

			return $result;
		}

		$standings = get_post( $standings_id );
		if ( ! $standings ) {
			$result['skipped'] = __( 'The configured standings page no longer exists.', 'sportspress-events-manager' );

			return $result;
		}

		$playoffs = $playoffs_id ? get_post( $playoffs_id ) : null;

		// Work out which season the pages are currently showing, from the tables
		// they render rather than from their titles.
		$outgoing_regular = SPEM_Standings_Content::extract_table_ids( $standings->post_content );
		$outgoing_playoff = $playoffs ? SPEM_Standings_Content::extract_table_ids( $playoffs->post_content ) : array();

		$regular_season = $this->season_of_tables( $outgoing_regular );
		$playoff_season = $this->season_of_tables( $outgoing_playoff, true );

		if ( $regular_season && $playoff_season && $regular_season['id'] !== $playoff_season['id'] ) {
			$result['skipped'] = sprintf(
				/* translators: 1: standings page season, 2: playoffs page season */
				__( 'The standings page shows %1$s but the playoffs page shows %2$s. Reconcile them before rolling over, or the archive would capture two different seasons.', 'sportspress-events-manager' ),
				$regular_season['name'],
				$playoff_season['name']
			);

			return $result;
		}

		$outgoing = $regular_season ? $regular_season : $playoff_season;

		// Archive whatever the pages were showing, if there was anything.
		if ( $archive_id && $outgoing && ( $outgoing_regular || $outgoing_playoff ) ) {
			$archived = $this->archive( $outgoing['name'], $archive_id, $outgoing_regular, $outgoing_playoff );
			if ( $archived ) {
				$result['archived'] = $outgoing['name'];
			}
		}

		// Repoint both live pages together.
		wp_update_post(
			array(
				'ID'           => $standings_id,
				'post_content' => SPEM_Standings_Content::replace_table_ids( $standings->post_content, $regular_ids ),
			)
		);
		$result['updated']++;

		if ( $playoffs ) {
			wp_update_post(
				array(
					'ID'           => $playoffs_id,
					'post_content' => SPEM_Standings_Content::replace_table_ids( $playoffs->post_content, $playoff_ids ),
				)
			);
			$result['updated']++;
		}

		return $result;
	}

	/**
	 * Create or refresh the archive page for a finished season.
	 *
	 * @param string $season_name Season being archived.
	 * @param int    $parent_id   Archive parent page ID.
	 * @param int[]  $regular_ids Regular-season table IDs.
	 * @param int[]  $playoff_ids Playoff table IDs.
	 * @return int Archive page ID, or 0 on failure.
	 */
	private function archive( $season_name, $parent_id, array $regular_ids, array $playoff_ids ) {
		$title   = sprintf( 'Standings | %s', $season_name );
		$content = SPEM_Standings_Content::build_archive_content( $season_name, $regular_ids, $playoff_ids );

		// Re-running a rollover must not mint a second archive page for the same
		// season — the wizard re-POSTs this handler once per archive chunk.
		$existing = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_parent'    => $parent_id,
				'title'          => $title,
			)
		);

		if ( ! empty( $existing ) ) {
			wp_update_post(
				array(
					'ID'           => (int) $existing[0],
					'post_content' => $content,
				)
			);

			return (int) $existing[0];
		}

		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => $title,
				'post_name'    => sanitize_title( $title ),
				'post_parent'  => $parent_id,
				'post_content' => $content,
			)
		);

		return is_wp_error( $page_id ) ? 0 : (int) $page_id;
	}

	/**
	 * Identify the season a set of standings tables belongs to.
	 *
	 * Playoff pages carry the child term, so the parent is resolved for
	 * comparison — otherwise a correctly-paired standings and playoffs page
	 * would look like a mismatch.
	 *
	 * @param int[] $table_ids     Standings table post IDs.
	 * @param bool  $resolve_parent Resolve a playoff child to its parent season.
	 * @return array{id:int, name:string}|null
	 */
	private function season_of_tables( array $table_ids, $resolve_parent = false ) {
		foreach ( $table_ids as $table_id ) {
			$terms = wp_get_object_terms( (int) $table_id, 'sp_season' );

			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}

			$term = $terms[0];

			if ( $resolve_parent && $term->parent ) {
				$parent = get_term( $term->parent, 'sp_season' );
				if ( $parent && ! is_wp_error( $parent ) ) {
					$term = $parent;
				}
			}

			return array(
				'id'   => (int) $term->term_id,
				'name' => $term->name,
			);
		}

		return null;
	}
}
