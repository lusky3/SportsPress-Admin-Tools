<?php
/**
 * Player Skill Level Tracking
 *
 * Provides admin-only skill ratings (1-10) for players, with manual input
 * and auto-calculation from SportsPress statistics.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Player_Skill_Level {

	/** Goalie position slugs to detect. */
	/**
	 * Position slugs treated as goalies.
	 *
	 * SportsPress default position slugs are prefixed with menu order, so a
	 * goalie is commonly '1-goalie' (not '0-goalie'). term_is_goalie() also falls
	 * back to matching the term NAME so slug numbering can't mis-classify a goalie.
	 *
	 * @var string[]
	 */
	private static $goalie_slugs = array( 'goalie', 'goaltender', 'goalkeeper', 'g', '0-goalie', '1-goalie' );

	/**
	 * M32: hard ceiling on the number of events pulled into one calculation.
	 *
	 * Matches the PT-7 cap used by SPPT_REST_API for roster reads. The default
	 * calculation scope is all-time, so an unbounded query loaded every event in
	 * the site's history (plus each one's meta) into a synchronous admin request.
	 */
	const MAX_EVENTS = 5000;

	/**
	 * M32: chunk size for cache priming and batched term lookups.
	 */
	const CHUNK_SIZE = 200;

	/**
	 * H28: raw scores are floats, so tie detection compares with a tolerance
	 * rather than ===. Scores are points-per-game / GAA magnitudes, so 1e-9 is
	 * far below any meaningful difference.
	 */
	const TIE_EPSILON = 0.000000001;

	public function __construct() {
		// Phase 1: Meta box + admin column.
		add_action( 'add_meta_boxes_sp_player', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_sp_player', array( $this, 'save_meta' ) );
		add_filter( 'manage_sp_player_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_sp_player_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-sp_player_sortable_columns', array( $this, 'sortable_column' ) );
		add_action( 'pre_get_posts', array( $this, 'sort_by_skill' ) );

		// Phase 2: Bulk calculate via admin-post.
		add_action( 'admin_post_spt_bulk_calculate_skill', array( $this, 'handle_bulk_calculate' ) );

		// Phase 4: CSV export + bulk edit.
		add_action( 'admin_post_spt_export_skill_csv', array( $this, 'handle_export_csv' ) );
		add_filter( 'bulk_actions-edit-sp_player', array( $this, 'register_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-sp_player', array( $this, 'handle_bulk_action' ), 10, 3 );

		// Show bulk calculate result notice.
		add_action( 'admin_notices', array( $this, 'show_calc_notice' ) );

		// Fix #14: invalidate distribution cache whenever skill_level meta changes.
		add_action( 'updated_post_meta', array( __CLASS__, 'maybe_flush_distribution_cache' ), 10, 4 );
		add_action( 'added_post_meta', array( __CLASS__, 'maybe_flush_distribution_cache' ), 10, 4 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'maybe_flush_distribution_cache' ), 10, 4 );
	}

	/** Per-request guard so we only flush the distribution transient once. */
	private static $flush_pending = false;

	/**
	 * Fix #14: drop the distribution transient whenever spt_skill_level meta changes.
	 *
	 * Core has no per-key dynamic action for {added,updated,deleted}_post_meta,
	 * so this fires on every meta write site-wide. Keep the key compare as the
	 * very first statement and short-circuit re-entry within the same request.
	 *
	 * @param int    $meta_id    Meta ID (unused).
	 * @param int    $object_id  Post ID (unused).
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value (unused).
	 */
	public static function maybe_flush_distribution_cache( $meta_id, $object_id, $meta_key, $meta_value ) {
		if ( 'spt_skill_level' !== $meta_key ) {
			return;
		}
		if ( self::$flush_pending ) {
			return;
		}
		self::$flush_pending = true;
		delete_transient( 'spt_skill_distribution' );
	}

	/**
	 * Display the bulk calculation result as a top-level admin notice.
	 */
	public function show_calc_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display trigger.
		if ( ! empty( $_GET['spt_calc_done'] ) ) {
			$result = get_transient( 'spt_skill_calc_result' );
			if ( $result ) {
				delete_transient( 'spt_skill_calc_result' );
				$msg = sprintf(
					/* translators: %1$d: updated, %2$d: manual skipped, %3$d: low GP skipped */
					esc_html__( 'Skill calculation complete: %1$d players updated, %2$d manual overrides skipped, %3$d below minimum games.', 'sportspress-player-tools' ),
					intval( $result['updated'] ),
					intval( $result['skipped_manual'] ),
					intval( $result['skipped_low_gp'] )
				);
				if ( 0 === intval( $result['updated'] ) && intval( $result['skipped_low_gp'] ) > 0 ) {
					$msg .= ' ' . esc_html__( 'Try lowering the Minimum Games threshold or selecting a season with more game data.', 'sportspress-player-tools' );
				}
				// M31: goalies with no goals-against recorded anywhere are excluded
				// rather than scored as a 0.00 GAA. Say so instead of leaving the
				// operator to wonder why their goalies were not rated.
				$no_data = isset( $result['skipped_no_data'] ) ? intval( $result['skipped_no_data'] ) : 0;
				if ( $no_data > 0 ) {
					$msg .= ' ' . sprintf(
						/* translators: %d: number of goalies with no goals-against data */
						esc_html(
							_n(
								'%d goalie was skipped because no goals-against were recorded for them.',
								'%d goalies were skipped because no goals-against were recorded for them.',
								$no_data,
								'sportspress-player-tools'
							)
						),
						$no_data
					);
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display trigger, value sanitized with absint().
		if ( ! empty( $_GET['spt_bulk_updated'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$count = absint( $_GET['spt_bulk_updated'] );
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %d: number of players */
					esc_html__( 'Skill level updated for %d player(s).', 'sportspress-player-tools' ),
					$count
				)
			);
		}
	}

	// ------------------------------------------------------------------
	// Phase 1: Meta box
	// ------------------------------------------------------------------

	public function add_meta_box() {
		add_meta_box(
			'spt_skill_level',
			__( 'Skill Level', 'sportspress-player-tools' ),
			array( $this, 'render_meta_box' ),
			'sp_player',
			'side',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'spt_skill_level_save', 'spt_skill_level_nonce' );

		$level   = get_post_meta( $post->ID, 'spt_skill_level', true );
		$source  = get_post_meta( $post->ID, 'spt_skill_source', true );
		$updated = get_post_meta( $post->ID, 'spt_skill_updated', true );

		$display_level = $level !== '' ? absint( $level ) : '';
		?>
		<p>
			<label for="spt_skill_level_input"><?php esc_html_e( 'Rating (1–10):', 'sportspress-player-tools' ); ?></label><br>
			<input type="number" id="spt_skill_level_input" name="spt_skill_level" value="<?php echo esc_attr( $display_level ); ?>" min="1" max="10" step="1" style="width:60px;" />
			<span class="description">/ 10</span>
		</p>
		<?php if ( $source ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: source label */
					esc_html__( 'Source: %s', 'sportspress-player-tools' ),
					'<strong>' . esc_html( ucfirst( $source ) ) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $updated ) : ?>
			<p class="description">
				<?php
				printf(
					/* translators: %s: date */
					esc_html__( 'Updated: %s', 'sportspress-player-tools' ),
					esc_html( date_i18n( get_option( 'date_format' ), strtotime( $updated ) ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( 'manual' === $source ) : ?>
			<p>
				<label>
					<input type="checkbox" name="spt_skill_reset_auto" value="1" />
					<?php esc_html_e( 'Reset to Auto', 'sportspress-player-tools' ); ?>
				</label>
			</p>
		<?php endif; ?>
		<?php
		// Phase 4: Show skill history.
		$history = get_post_meta( $post->ID, 'spt_skill_history', true );
		if ( is_array( $history ) && ! empty( $history ) ) :
			$recent = array_slice( array_reverse( $history ), 0, 5 );
			?>
			<hr>
			<p><strong><?php esc_html_e( 'History', 'sportspress-player-tools' ); ?></strong></p>
			<ul style="margin:0;font-size:12px;">
				<?php foreach ( $recent as $entry ) : ?>
					<li>
						<?php
						echo esc_html(
							date_i18n( 'M j', strtotime( $entry['date'] ) )
							. ' — ' . $entry['level']
							. ' (' . $entry['source'] . ')'
							. ( ! empty( $entry['season'] ) ? ' [' . $entry['season'] . ']' : '' )
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	public function save_meta( $post_id ) {
		if ( ! isset( $_POST['spt_skill_level_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spt_skill_level_nonce'] ) ), 'spt_skill_level_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Reset to auto.
		if ( ! empty( $_POST['spt_skill_reset_auto'] ) ) {
			delete_post_meta( $post_id, 'spt_skill_level' );
			update_post_meta( $post_id, 'spt_skill_source', 'auto' );
			update_post_meta( $post_id, 'spt_skill_updated', current_time( 'c' ) );
			return;
		}

		$current = get_post_meta( $post_id, 'spt_skill_level', true );
		$raw     = isset( $_POST['spt_skill_level'] ) ? sanitize_text_field( wp_unslash( $_POST['spt_skill_level'] ) ) : '';

		if ( '' === $raw || ! is_numeric( $raw ) ) {
			// Only clear if there was a value; an empty field on an unrelated
			// save of an unrated player is a no-op.
			if ( '' !== $current ) {
				delete_post_meta( $post_id, 'spt_skill_level' );
				delete_post_meta( $post_id, 'spt_skill_source' );
				delete_post_meta( $post_id, 'spt_skill_updated' );
			}
			return;
		}

		$level = min( 10, max( 1, absint( $raw ) ) );

		// The meta box pre-fills the input with the current level, so an unrelated
		// player save resubmits the same value. Only treat it as a manual override
		// when the value actually CHANGED — otherwise saving a player for any other
		// reason would silently flip an auto rating to manual and exclude the player
		// from future bulk recalculations.
		if ( (string) $level === (string) $current ) {
			return;
		}

		update_post_meta( $post_id, 'spt_skill_level', $level );
		update_post_meta( $post_id, 'spt_skill_source', 'manual' );
		update_post_meta( $post_id, 'spt_skill_updated', current_time( 'c' ) );
		self::record_history( $post_id, $level, 'manual' );
	}

	// ------------------------------------------------------------------
	// Phase 1: Admin column
	// ------------------------------------------------------------------

	public function add_column( $columns ) {
		$columns['spt_skill'] = __( 'Skill', 'sportspress-player-tools' );
		return $columns;
	}

	public function render_column( $column, $post_id ) {
		if ( 'spt_skill' !== $column ) {
			return;
		}
		$level = get_post_meta( $post_id, 'spt_skill_level', true );
		echo $level !== '' ? esc_html( $level ) : '—';
	}

	public function sortable_column( $columns ) {
		$columns['spt_skill'] = 'spt_skill_level';
		return $columns;
	}

	public function sort_by_skill( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		if ( 'spt_skill_level' === $query->get( 'orderby' ) ) {
			$query->set( 'meta_key', 'spt_skill_level' );
			$query->set( 'orderby', 'meta_value_num' );
		}
	}

	// ------------------------------------------------------------------
	// Phase 2: Auto-calculation
	// ------------------------------------------------------------------

	public function handle_bulk_calculate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-player-tools' ) );
		}
		check_admin_referer( 'spt_bulk_calculate_skill' );

		$league_id = absint( $_POST['spt_calc_league'] ?? 0 );
		$season_id = absint( $_POST['spt_calc_season'] ?? 0 );

		// Save min_games if submitted with the bulk calculate form.
		if ( isset( $_POST['spt_skill_min_games'] ) ) {
			update_option( 'spt_skill_min_games', max( 1, absint( $_POST['spt_skill_min_games'] ) ) );
		}
		$min_games = absint( get_option( 'spt_skill_min_games', 3 ) );

		$result = $this->calculate_skill_levels( $league_id, $season_id, $min_games );

		set_transient( 'spt_skill_calc_result', $result, 60 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'           => 'sportspress-admin-tools',
					'tab'            => 'player-tools',
					'spt_calc_done'  => '1',
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Calculate skill levels for all eligible players.
	 *
	 * @param int $league_id League term ID (0 = all).
	 * @param int $season_id Season term ID (0 = all).
	 * @param int $min_games Minimum games played.
	 * @return array { updated: int, skipped_manual: int, skipped_low_gp: int, skipped_no_data: int, total_eligible: int }
	 */
	public function calculate_skill_levels( $league_id, $season_id, $min_games = null ) {
		// Default to the admin-configured threshold so callers that don't pass it
		// (e.g. the league dashboard's Calculate Skills) match the wp-admin tool.
		if ( null === $min_games ) {
			$min_games = absint( get_option( 'spt_skill_min_games', 3 ) );
		}
		$min_games = max( 1, (int) $min_games );
		// Stats come from the per-event box scores (sp_players), NOT the aggregated
		// sp_statistics meta — SportsPress does not roll imported/dashboard-entered
		// box scores up into that meta, so it is empty on this data. A "season" scope
		// includes the season term AND its children (e.g. regular + its playoffs);
		// season 0 means all-time across every season.
		$season_terms = array();
		if ( $season_id ) {
			$season_terms[] = (int) $season_id;
			$children = get_terms(
				array(
					'taxonomy'   => 'sp_season',
					'parent'     => $season_id,
					'hide_empty' => false,
					'fields'     => 'ids',
				)
			);
			if ( ! is_wp_error( $children ) ) {
				$season_terms = array_merge( $season_terms, array_map( 'intval', $children ) );
			}
		}

		// M32: was posts_per_page => -1. "All seasons" is the default scope, so the
		// unbounded query pulled every published event in the site's history — and
		// then read each one's meta individually — inside one synchronous admin
		// request. Cap at the plugin-wide PT-7 ceiling.
		$args = array(
			'post_type'      => 'sp_event',
			'posts_per_page' => self::MAX_EVENTS,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);
		$tax = array();
		if ( ! empty( $season_terms ) ) {
			$tax[] = array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => $season_terms,
			);
		}
		if ( $league_id ) {
			$tax[] = array(
				'taxonomy' => 'sp_league',
				'field'    => 'term_id',
				'terms'    => $league_id,
			);
		}
		if ( count( $tax ) > 1 ) {
			$tax['relation'] = 'AND';
		}
		if ( ! empty( $tax ) ) {
			$args['tax_query'] = $tax;
		}
		$event_ids = get_posts( $args );

		// Aggregate appearances + goals/assists/pim/goals-against per player.
		// M32: prime the postmeta cache one chunk at a time so the per-event
		// get_post_meta() below is served from cache — previously this issued one
		// SELECT per event (N+1) on top of the unbounded event query.
		$agg = array();
		foreach ( array_chunk( $event_ids, self::CHUNK_SIZE ) as $event_chunk ) {
			update_meta_cache( 'post', $event_chunk );
			foreach ( $event_chunk as $eid ) {
				$agg = self::aggregate_event_players( get_post_meta( $eid, 'sp_players', true ), $agg );
			}
		}

		// M32: resolve goalie status for the entire candidate set in one batched
		// term query per chunk instead of a wp_get_post_terms() call per player.
		$goalie_ids = self::get_goalie_ids( array_keys( $agg ) );

		$pools = self::build_score_pools( $agg, $min_games, $goalie_ids );

		$total          = count( $pools['skaters'] ) + count( $pools['goalies'] );
		$updated        = 0;
		$skipped_manual = 0;

		// Percentile-map each pool independently to 1–10 (H28: tie-aware).
		$skill_of = self::map_scores_to_skills( $pools['skaters'] )
			+ self::map_scores_to_skills( $pools['goalies'] );

		foreach ( $skill_of as $pid => $skill ) {
			$source = get_post_meta( $pid, 'spt_skill_source', true );
			if ( 'manual' === $source ) {
				++$skipped_manual;
				continue;
			}

			update_post_meta( $pid, 'spt_skill_level', $skill );
			update_post_meta( $pid, 'spt_skill_source', 'auto' );
			update_post_meta( $pid, 'spt_skill_updated', current_time( 'c' ) );
			self::record_history( $pid, $skill, 'auto', $season_id );
			++$updated;
		}

		return array(
			'updated'         => $updated,
			'skipped_manual'  => $skipped_manual,
			'skipped_low_gp'  => $pools['skipped_low_gp'],
			// M31: goalies with no goals-against data anywhere in scope. Reported
			// rather than silently scored as a perfect 0.00 GAA.
			'skipped_no_data' => $pools['skipped_no_data'],
			'total_eligible'  => $total,
		);
	}

	/**
	 * Fold one event's `sp_players` box score into a running aggregate.
	 *
	 * Pure helper (no WordPress calls) so the aggregation rules are unit-testable.
	 *
	 * M30: SportsPress reserves player key `0` inside each team's `sp_players` row
	 * set for the team TOTALS line. It is an array like any player row, so the
	 * is_array() guard let it through as a phantom "player" carrying the whole
	 * team's goals and assists — always the top scorer, dragging every real
	 * player's percentile down. Any non-positive key is now skipped.
	 *
	 * M31: track whether a goals-against value was ever actually recorded. An
	 * absent (or blank) `ga` key means "not tracked", which is NOT the same as a
	 * genuine shutout (an explicit 0) and must not be scored as a 0.00 GAA.
	 *
	 * @param mixed $players The `sp_players` meta value for one event.
	 * @param array $agg     Running aggregate keyed by player post ID.
	 * @return array Updated aggregate.
	 */
	public static function aggregate_event_players( $players, $agg = array() ) {
		if ( ! is_array( $players ) ) {
			return $agg;
		}
		foreach ( $players as $rows ) {
			if ( ! is_array( $rows ) ) {
				continue;
			}
			foreach ( $rows as $pid => $st ) {
				if ( ! is_array( $st ) ) {
					continue;
				}
				$pid = (int) $pid;
				// M30: skip the reserved team-totals row (and any junk key).
				if ( $pid <= 0 ) {
					continue;
				}
				if ( ! isset( $agg[ $pid ] ) ) {
					$agg[ $pid ] = array(
						'gp'     => 0,
						'g'      => 0,
						'a'      => 0,
						'pim'    => 0,
						'ga'     => 0,
						'has_ga' => false,
					);
				}
				$agg[ $pid ]['gp']++;
				$agg[ $pid ]['g']   += (int) ( $st['g'] ?? 0 );
				$agg[ $pid ]['a']   += (int) ( $st['a'] ?? 0 );
				$agg[ $pid ]['pim'] += (int) ( $st['pim'] ?? 0 );
				$agg[ $pid ]['ga']  += (int) ( $st['ga'] ?? 0 );
				// M31: '0' / 0 is real data (a shutout). '' / null / missing is not.
				if ( array_key_exists( 'ga', $st ) && null !== $st['ga'] && '' !== trim( (string) $st['ga'] ) ) {
					$agg[ $pid ]['has_ga'] = true;
				}
			}
		}
		return $agg;
	}

	/**
	 * Split an aggregate into the skater and goalie scoring pools.
	 *
	 * Skaters and goalies are ranked in SEPARATE pools: a skater's score is
	 * points-rate (positive) and a goalie's is −GAA (negative), so ranking them
	 * together always sorted every goalie to the bottom (→ skill 1). Each pool is
	 * percentile-mapped to its own full 1–10 range by map_scores_to_skills().
	 *
	 * Goals are weighted heavier than assists: assists are noisier and far more
	 * linemate/scorekeeper dependent (the league also sees inflated assist
	 * credit), so they shouldn't drive a rating the way goals do. Ranking uses
	 * goals×2 + assists×0.5; 'p' in the filter payload still reports true points.
	 *
	 * @param array $agg        Aggregate from aggregate_event_players().
	 * @param int   $min_games  Minimum games played to be scored.
	 * @param array $goalie_ids Set of player IDs that are goalies (id => true).
	 * @return array { skaters: array, goalies: array, skipped_low_gp: int, skipped_no_data: int }
	 */
	public static function build_score_pools( array $agg, $min_games, array $goalie_ids ) {
		$skaters         = array();
		$goalies         = array();
		$low_gp          = 0;
		$skipped_no_data = 0;

		$min_games = max( 1, (int) $min_games );

		// The weights take no per-player arguments, so they are resolved once
		// instead of on every iteration (previously inside the loop).
		$goal_weight   = (float) apply_filters( 'spt_skill_goal_weight', 2 );
		$assist_weight = (float) apply_filters( 'spt_skill_assist_weight', 0.5 );

		foreach ( $agg as $pid => $s ) {
			$gp = (int) ( $s['gp'] ?? 0 );
			if ( $gp < $min_games ) {
				++$low_gp;
				continue;
			}

			$is_goalie = ! empty( $goalie_ids[ $pid ] );

			// M31: a goalie with no goals-against recorded anywhere in scope has a
			// GAA of 0 only because the stat is untracked — which used to rank them
			// ABOVE every goalie who actually posted a good GAA, and hand them a 10.
			// Exclude them from the pool entirely; the caller reports the count.
			if ( $is_goalie && empty( $s['has_ga'] ) ) {
				++$skipped_no_data;
				continue;
			}

			$ga       = (int) ( $s['ga'] ?? 0 );
			$goals    = (int) ( $s['g'] ?? 0 );
			$assists  = (int) ( $s['a'] ?? 0 );
			$gaa      = $gp > 0 ? $ga / $gp : 0;
			$points   = $goals + $assists;
			$weighted = ( $goal_weight * $goals ) + ( $assist_weight * $assists );

			if ( $is_goalie ) {
				// Negative so lower GAA = higher score (0 GAA → score 0, best rank).
				$raw_score = -$gaa;
			} else {
				$raw_score = $weighted / $gp;
			}

			$player_stats = array(
				'gp'     => $gp,
				'g'      => $goals,
				'a'      => $assists,
				'pim'    => (int) ( $s['pim'] ?? 0 ),
				'ga'     => $ga,
				'p'      => $points,
				'gaatwo' => $gaa,
			);

			/**
			 * Filter the raw score used for skill level ranking.
			 *
			 * @param float $raw_score    Calculated raw score.
			 * @param int   $pid          Player post ID.
			 * @param array $player_stats Stat values for this player.
			 * @param bool  $is_goalie    Whether the player is a goalie.
			 */
			$raw_score = (float) apply_filters( 'spt_skill_calculate_raw_score', $raw_score, $pid, $player_stats, $is_goalie );

			if ( $is_goalie ) {
				$goalies[ $pid ] = $raw_score;
			} else {
				$skaters[ $pid ] = $raw_score;
			}
		}

		return array(
			'skaters'         => $skaters,
			'goalies'         => $goalies,
			'skipped_low_gp'  => $low_gp,
			'skipped_no_data' => $skipped_no_data,
		);
	}

	/**
	 * Percentile-map one pool of raw scores onto the 1–10 skill scale.
	 *
	 * H28 — TIE HANDLING. The previous implementation walked the sorted pool and
	 * incremented a counter, so every player got a distinct rank even when their
	 * raw scores were identical: 30 pointless skaters in a 60-skater pool fanned
	 * out across skills ~5 down to 1 purely by array encounter order, and these
	 * ratings feed team balancing. Ranking is now tie-aware.
	 *
	 * Equal raw score ⇒ equal rank ⇒ equal percentile ⇒ equal skill. The shared
	 * rank is the MEAN of the sorted positions the tie group occupies (a midrank,
	 * a.k.a. fractional ranking), NOT the group's best position (standard
	 * competition ranking).
	 *
	 * That choice is what makes the boundary behave. With competition ranking a
	 * pool where EVERY score is identical would collapse to rank 1 for everyone —
	 * percentile 1.0, skill 10 for the whole league, which is exactly the "no data
	 * ⇒ everyone elite" failure mode. With midranks an all-tied pool lands on the
	 * middle of the scale (skill 6), the 30-tied-at-the-bottom case lands near the
	 * bottom, and 30-tied-at-the-top lands near the top — the group is placed
	 * where the group actually sits in the distribution.
	 *
	 * Mapping is unchanged for strictly-ordered pools: best ⇒ percentile 1 ⇒ 10,
	 * worst ⇒ percentile 0 ⇒ 1, and a single-entry pool ⇒ 0.5 ⇒ 6.
	 *
	 * @param array $pool Map of player ID => raw score.
	 * @return array Map of player ID => skill level (1–10).
	 */
	public static function map_scores_to_skills( array $pool ) {
		$skills = array();
		$n      = count( $pool );
		if ( 0 === $n ) {
			return $skills;
		}

		arsort( $pool );
		$pids = array_keys( $pool );

		$i = 0;
		while ( $i < $n ) {
			$score = (float) $pool[ $pids[ $i ] ];

			// Extend the group over every following entry with the same score.
			$j = $i + 1;
			while ( $j < $n && abs( (float) $pool[ $pids[ $j ] ] - $score ) <= self::TIE_EPSILON ) {
				++$j;
			}

			// Sorted positions are 1-based: this group occupies ($i + 1) .. $j.
			$rank       = ( ( $i + 1 ) + $j ) / 2;
			$percentile = $n > 1 ? ( $n - $rank ) / ( $n - 1 ) : 0.5;
			$skill      = max( 1, min( 10, (int) round( $percentile * 9 ) + 1 ) );

			for ( $k = $i; $k < $j; $k++ ) {
				$skills[ $pids[ $k ] ] = $skill;
			}

			$i = $j;
		}

		return $skills;
	}

	// LOW (player-tools): the ~50-line private extract_stats() helper that used to
	// live here was dead code. It read the aggregated `sp_statistics` meta, which
	// this class deliberately does NOT use — see the comment in
	// calculate_skill_levels(): SportsPress never rolls imported/dashboard-entered
	// box scores up into that meta, so it is empty on this data. Nothing called it.

	/**
	 * Resolve which of the given players are goalies, in batched term queries.
	 *
	 * M32: this replaces a per-player wp_get_post_terms() call inside the scoring
	 * loop (one query per player). `wp_get_object_terms()` accepts an array of
	 * object IDs, so the whole candidate set costs one query per chunk.
	 *
	 * @param array $player_ids Player post IDs.
	 * @return array Set of goalie player IDs, keyed by ID (id => true).
	 */
	private static function get_goalie_ids( array $player_ids ) {
		$goalies = array();
		if ( empty( $player_ids ) ) {
			return $goalies;
		}

		foreach ( array_chunk( array_map( 'intval', $player_ids ), self::CHUNK_SIZE ) as $chunk ) {
			$terms = wp_get_object_terms(
				$chunk,
				'sp_position',
				array( 'fields' => 'all_with_object_id' )
			);
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$object_id = isset( $term->object_id ) ? (int) $term->object_id : 0;
				if ( ! $object_id || isset( $goalies[ $object_id ] ) ) {
					continue;
				}
				if ( self::term_is_goalie( $term ) ) {
					$goalies[ $object_id ] = true;
				}
			}
		}

		return $goalies;
	}

	/**
	 * Whether an sp_position term denotes a goalie.
	 *
	 * @param object $term Term object with slug/name.
	 * @return bool
	 */
	private static function term_is_goalie( $term ) {
		$slug = isset( $term->slug ) ? strtolower( (string) $term->slug ) : '';
		if ( in_array( $slug, self::$goalie_slugs, true ) ) {
			return true;
		}
		// Name fallback: catches any slug numbering (e.g. "1-goalie") as long
		// as the label reads Goalie/Goaltender/Goalkeeper.
		$name = isset( $term->name ) ? (string) $term->name : '';
		return (bool) preg_match( '/goal(ie|tender|keeper)/i', $name );
	}

	// ------------------------------------------------------------------
	// ------------------------------------------------------------------
	// Phase 4: Skill history
	// ------------------------------------------------------------------

	/**
	 * Record a skill level change in the player's history.
	 *
	 * @param int    $player_id Player post ID.
	 * @param int    $level     New skill level.
	 * @param string $source    'manual' or 'auto'.
	 * @param int    $season_id Optional season term ID.
	 */
	public static function record_history( $player_id, $level, $source, $season_id = 0 ) {
		$history = get_post_meta( $player_id, 'spt_skill_history', true );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$entry = array(
			'level'  => $level,
			'source' => $source,
			'date'   => current_time( 'c' ),
		);
		if ( $season_id ) {
			$term = get_term( $season_id, 'sp_season' );
			$entry['season'] = $term && ! is_wp_error( $term ) ? $term->name : '';
		}

		$history[] = $entry;

		// Keep last 50 entries to avoid unbounded growth.
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}

		update_post_meta( $player_id, 'spt_skill_history', $history );
	}

	// ------------------------------------------------------------------
	// Phase 4: CSV export
	// ------------------------------------------------------------------

	/**
	 * Sanitize a value for safe CSV output by prefixing formula-triggering characters with a single quote.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	private static function sanitize_csv_value( $value ) {
		if ( is_string( $value ) && isset( $value[0] ) && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Handle CSV export of player skill data.
	 */
	public function handle_export_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-player-tools' ) );
		}
		check_admin_referer( 'spt_export_skill_csv' );

		$players = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_key'       => 'spt_skill_level',
				'orderby'        => 'meta_value_num',
				'order'          => 'DESC',
			)
		);

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=player-skill-levels-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Player ID', 'Player Name', 'Skill Level', 'Source', 'Last Updated', 'Teams', 'Leagues' ) );

		foreach ( $players as $player ) {
			$level   = get_post_meta( $player->ID, 'spt_skill_level', true );
			$source  = get_post_meta( $player->ID, 'spt_skill_source', true );
			$updated = get_post_meta( $player->ID, 'spt_skill_updated', true );

			$teams   = wp_get_post_terms( $player->ID, 'sp_team', array( 'fields' => 'names' ) );
			$leagues = wp_get_post_terms( $player->ID, 'sp_league', array( 'fields' => 'names' ) );

			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'sanitize_csv_value' ),
					array(
						$player->ID,
						$player->post_title,
						$level,
						$source,
						$updated ? date_i18n( 'Y-m-d', strtotime( $updated ) ) : '',
						is_array( $teams ) ? implode( ', ', $teams ) : '',
						is_array( $leagues ) ? implode( ', ', $leagues ) : '',
					)
				)
			);
		}

		fclose( $out );
		exit;
	}

	// ------------------------------------------------------------------
	// Phase 4: Bulk edit from player list
	// ------------------------------------------------------------------

	/**
	 * Register bulk actions on the player list table.
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array
	 */
	public function register_bulk_actions( $actions ) {
		for ( $i = 1; $i <= 10; $i++ ) {
			/* translators: %d: skill level number */
			$actions[ 'spt_set_skill_' . $i ] = sprintf( __( 'Set Skill → %d', 'sportspress-player-tools' ), $i );
		}
		$actions['spt_clear_skill'] = __( 'Clear Skill Level', 'sportspress-player-tools' );
		return $actions;
	}

	/**
	 * Handle bulk skill level actions.
	 *
	 * @param string $redirect_url Redirect URL.
	 * @param string $action       The action being taken.
	 * @param array  $post_ids     Selected post IDs.
	 * @return string
	 */
	public function handle_bulk_action( $redirect_url, $action, $post_ids ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $redirect_url;
		}

		if ( 'spt_clear_skill' === $action ) {
			foreach ( $post_ids as $pid ) {
				delete_post_meta( $pid, 'spt_skill_level' );
				delete_post_meta( $pid, 'spt_skill_source' );
				delete_post_meta( $pid, 'spt_skill_updated' );
			}
			return add_query_arg( 'spt_bulk_updated', count( $post_ids ), $redirect_url );
		}

		if ( preg_match( '/^spt_set_skill_(\d+)$/', $action, $m ) ) {
			$level = min( 10, max( 1, absint( $m[1] ) ) );
			foreach ( $post_ids as $pid ) {
				update_post_meta( $pid, 'spt_skill_level', $level );
				update_post_meta( $pid, 'spt_skill_source', 'manual' );
				update_post_meta( $pid, 'spt_skill_updated', current_time( 'c' ) );
				self::record_history( $pid, $level, 'manual' );
			}
			return add_query_arg( 'spt_bulk_updated', count( $post_ids ), $redirect_url );
		}

		return $redirect_url;
	}

	// ------------------------------------------------------------------
	// Phase 2: Settings UI (called from SPT_Admin)
	// ------------------------------------------------------------------

	/**
	 * Render the skill level settings section.
	 */
	public static function render_settings() {
		$min_games = get_option( 'spt_skill_min_games', '3' );

		$leagues = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
				'orderby'    => 'name',
			)
		);

		$seasons = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			)
		);
		?>
		<hr>
		<h2><?php esc_html_e( 'Skill Level Tracking', 'sportspress-player-tools' ); ?></h2>

		<h3><?php esc_html_e( 'Bulk Calculate', 'sportspress-player-tools' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Calculate skill levels from Points Per Game (skaters) and GAA (goalies). Players with manual ratings will be skipped.', 'sportspress-player-tools' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="spt_bulk_calculate_skill">
			<?php wp_nonce_field( 'spt_bulk_calculate_skill' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Minimum Games', 'sportspress-player-tools' ); ?></th>
					<td>
						<input type="number" name="spt_skill_min_games" value="<?php echo esc_attr( $min_games ); ?>" min="1" max="50" step="1" style="width:60px;" />
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'League', 'sportspress-player-tools' ); ?></th>
					<td>
						<select name="spt_calc_league">
							<option value="0"><?php esc_html_e( 'All Leagues', 'sportspress-player-tools' ); ?></option>
							<?php foreach ( $leagues as $league ) : ?>
								<option value="<?php echo esc_attr( $league->term_id ); ?>"><?php echo esc_html( $league->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Season', 'sportspress-player-tools' ); ?></th>
					<td>
						<select name="spt_calc_season">
							<option value="0"><?php esc_html_e( 'All Seasons (Aggregate)', 'sportspress-player-tools' ); ?></option>
							<?php foreach ( $seasons as $season ) : ?>
								<option value="<?php echo esc_attr( $season->term_id ); ?>"><?php echo esc_html( $season->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Calculate Skill Levels', 'sportspress-player-tools' ), 'secondary', 'spt_calc_submit' ); ?>
		</form>

		<hr>
		<h3><?php esc_html_e( 'Export', 'sportspress-player-tools' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Download a CSV of all players with skill levels.', 'sportspress-player-tools' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="spt_export_skill_csv">
			<?php wp_nonce_field( 'spt_export_skill_csv' ); ?>
			<?php submit_button( __( 'Export CSV', 'sportspress-player-tools' ), 'secondary', 'spt_export_submit' ); ?>
		</form>

		<hr>
		<h3><?php esc_html_e( 'Skill Distribution', 'sportspress-player-tools' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Number of rated players at each skill level, grouped by league.', 'sportspress-player-tools' ); ?></p>
		<?php self::render_distribution( $leagues ); ?>
		<?php
	}

	/**
	 * Render the skill distribution table.
	 *
	 * @param array $leagues WP_Term objects for sp_league.
	 */
	private static function render_distribution( $leagues ) {
		global $wpdb;

		// Fix #14: cache the bucketed result for 5 minutes; bucket in SQL so we
		// don't ship every (player, skill) row over the wire on large sites.
		$dist = get_transient( 'spt_skill_distribution' );
		if ( false === $dist ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT COALESCE(t.name, '') AS league_name,
					        CAST(pm.meta_value AS UNSIGNED) AS skill,
					        COUNT(*) AS cnt
					 FROM {$wpdb->postmeta} pm
					 JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'sp_player' AND p.post_status = 'publish'
					 LEFT JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
					 LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'sp_league'
					 LEFT JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
					 WHERE pm.meta_key = %s
					   AND pm.meta_value REGEXP '^[0-9]+$'
					 GROUP BY t.name, CAST(pm.meta_value AS UNSIGNED)",
					'spt_skill_level'
				)
			);

			$dist = array();
			foreach ( (array) $rows as $row ) {
				$league = $row->league_name !== '' ? $row->league_name : __( '(No League)', 'sportspress-player-tools' );
				$skill  = (int) $row->skill;
				if ( $skill < 1 || $skill > 10 ) {
					continue;
				}
				if ( ! isset( $dist[ $league ] ) ) {
					$dist[ $league ] = array_fill( 1, 10, 0 );
				}
				$dist[ $league ][ $skill ] += (int) $row->cnt;
			}
			ksort( $dist );

			set_transient( 'spt_skill_distribution', $dist, 5 * MINUTE_IN_SECONDS );
		}

		if ( empty( $dist ) ) {
			echo '<p>' . esc_html__( 'No players have been rated yet. Run a bulk calculation first.', 'sportspress-player-tools' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'League', 'sportspress-player-tools' ); ?></th>
					<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
						<th style="text-align:center;"><?php echo esc_html( $i ); ?></th>
					<?php endfor; ?>
					<th style="text-align:center;"><?php esc_html_e( 'Total', 'sportspress-player-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $dist as $league_name => $counts ) : ?>
					<tr>
						<td><?php echo esc_html( $league_name ); ?></td>
						<?php for ( $i = 1; $i <= 10; $i++ ) : ?>
							<td style="text-align:center;"><?php echo esc_html( $counts[ $i ] ); ?></td>
						<?php endfor; ?>
						<td style="text-align:center;font-weight:bold;"><?php echo esc_html( array_sum( $counts ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
