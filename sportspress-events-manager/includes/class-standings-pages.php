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
		$result = array(
			'archived' => '',
			'updated'  => 0,
			'skipped'  => '',
		);

		$pages = $this->resolve_pages();
		if ( isset( $pages['error'] ) ) {
			$result['skipped'] = $pages['error'];

			return $result;
		}

		$outgoing = $this->resolve_outgoing( $pages['standings'], $pages['playoffs'] );
		if ( isset( $outgoing['error'] ) ) {
			$result['skipped'] = $outgoing['error'];

			return $result;
		}

		$result['archived'] = $this->maybe_archive( $outgoing );

		$result['updated'] = $this->repoint( $pages, $regular_ids, $playoff_ids );

		return $result;
	}

	/**
	 * Load the configured pages.
	 *
	 * @return array{standings:WP_Post, playoffs:WP_Post|null}|array{error:string}
	 */
	private function resolve_pages() {
		$standings_id = (int) get_option( 'spem_standings_page_id', 0 );

		if ( ! $standings_id ) {
			return array( 'error' => __( 'No standings page is configured.', 'sportspress-events-manager' ) );
		}

		$standings = get_post( $standings_id );
		if ( ! $standings ) {
			return array( 'error' => __( 'The configured standings page no longer exists.', 'sportspress-events-manager' ) );
		}

		$playoffs_id = (int) get_option( 'spem_playoffs_page_id', 0 );

		return array(
			'standings' => $standings,
			'playoffs'  => $playoffs_id ? get_post( $playoffs_id ) : null,
		);
	}

	/**
	 * Work out which season the live pages are currently showing.
	 *
	 * Read from the tables they render rather than their titles, and refused
	 * when the two disagree — see update()'s note on half-finished moves.
	 *
	 * @param WP_Post      $standings Standings page.
	 * @param WP_Post|null $playoffs  Playoffs page, when configured.
	 * @return array{season:?array, regular:int[], playoff:int[]}|array{error:string}
	 *
	 * SPEM_Naming and SPEM_Standings_Content are stateless pure helpers with no
	 * dependencies — static access is exactly what lets the standalone harness
	 * exercise them with no WordPress bootstrap. Injecting instances purely to
	 * satisfy the linter would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function resolve_outgoing( $standings, $playoffs ) {
		$regular_tables = SPEM_Standings_Content::extract_table_ids( $standings->post_content );
		$playoff_tables = $playoffs ? SPEM_Standings_Content::extract_table_ids( $playoffs->post_content ) : array();

		$regular_season = $this->season_of_tables( $regular_tables );
		$playoff_season = $this->season_of_tables( $playoff_tables, true );

		$mismatch = $regular_season && $playoff_season && $regular_season['id'] !== $playoff_season['id'];

		if ( $mismatch ) {
			return array(
				'error' => sprintf(
					/* translators: 1: standings page season, 2: playoffs page season */
					__( 'The standings page shows %1$s but the playoffs page shows %2$s. Reconcile them before rolling over, or the archive would capture two different seasons.', 'sportspress-events-manager' ),
					$regular_season['name'],
					$playoff_season['name']
				),
			);
		}

		return array(
			'season'  => $regular_season ? $regular_season : $playoff_season,
			'regular' => $regular_tables,
			'playoff' => $playoff_tables,
		);
	}

	/**
	 * Archive the outgoing season, when there is one and somewhere to put it.
	 *
	 * @param array $outgoing Output of resolve_outgoing().
	 * @return string Archived season name, or ''.
	 */
	private function maybe_archive( array $outgoing ) {
		$archive_id = (int) get_option( 'spem_standings_archive_parent_id', 0 );

		if ( ! $archive_id || empty( $outgoing['season'] ) ) {
			return '';
		}

		if ( ! $outgoing['regular'] && ! $outgoing['playoff'] ) {
			return '';
		}

		$archived = $this->archive( $outgoing['season']['name'], $archive_id, $outgoing['regular'], $outgoing['playoff'] );

		return $archived ? $outgoing['season']['name'] : '';
	}

	/**
	 * Write the new table IDs onto both live pages.
	 *
	 * @param array $pages       Output of resolve_pages().
	 * @param int[] $regular_ids New regular-season table IDs.
	 * @param int[] $playoff_ids New playoff table IDs.
	 * @return int Pages written.
	 *
	 * SPEM_Naming and SPEM_Standings_Content are stateless pure helpers with no
	 * dependencies — static access is exactly what lets the standalone harness
	 * exercise them with no WordPress bootstrap. Injecting instances purely to
	 * satisfy the linter would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function repoint( array $pages, array $regular_ids, array $playoff_ids ) {
		$written = 0;

		wp_update_post(
			array(
				'ID'           => $pages['standings']->ID,
				'post_content' => SPEM_Standings_Content::replace_table_ids( $pages['standings']->post_content, $regular_ids ),
			)
		);
		$written++;

		if ( $pages['playoffs'] ) {
			wp_update_post(
				array(
					'ID'           => $pages['playoffs']->ID,
					'post_content' => SPEM_Standings_Content::replace_table_ids( $pages['playoffs']->post_content, $playoff_ids ),
				)
			);
			$written++;
		}

		return $written;
	}

	/**
	 * Create or refresh the archive page for a finished season.
	 *
	 * @param string $season_name Season being archived.
	 * @param int    $parent_id   Archive parent page ID.
	 * @param int[]  $regular_ids Regular-season table IDs.
	 * @param int[]  $playoff_ids Playoff table IDs.
	 * @return int Archive page ID, or 0 on failure.
	 *
	 * SPEM_Naming and SPEM_Standings_Content are stateless pure helpers with no
	 * dependencies — static access is exactly what lets the standalone harness
	 * exercise them with no WordPress bootstrap. Injecting instances purely to
	 * satisfy the linter would cost testability and buy nothing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
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
