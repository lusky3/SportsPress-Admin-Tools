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
	private static $goalie_slugs = array( 'goalie', 'goalkeeper', 'g', '0-goalie' );

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

		// Show bulk calculate result notice.
		add_action( 'admin_notices', array( $this, 'show_calc_notice' ) );
	}

	/**
	 * Display the bulk calculation result as a top-level admin notice.
	 */
	public function show_calc_notice() {
		if ( empty( $_GET['spt_calc_done'] ) ) {
			return;
		}
		$result = get_transient( 'spt_skill_calc_result' );
		if ( ! $result ) {
			return;
		}
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
		echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
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

		$raw = $_POST['spt_skill_level'] ?? '';
		if ( '' === $raw || ! is_numeric( $raw ) ) {
			delete_post_meta( $post_id, 'spt_skill_level' );
			delete_post_meta( $post_id, 'spt_skill_source' );
			delete_post_meta( $post_id, 'spt_skill_updated' );
			return;
		}

		$level = min( 10, max( 1, absint( $raw ) ) );
		update_post_meta( $post_id, 'spt_skill_level', $level );
		update_post_meta( $post_id, 'spt_skill_source', 'manual' );
		update_post_meta( $post_id, 'spt_skill_updated', current_time( 'c' ) );
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
	 * @return array { updated: int, skipped_manual: int, skipped_low_gp: int }
	 */
	public function calculate_skill_levels( $league_id, $season_id, $min_games = 3 ) {
		$args = array(
			'post_type'      => 'sp_player',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'fields'         => 'ids',
		);

		if ( $league_id ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'sp_league',
					'field'    => 'term_id',
					'terms'    => $league_id,
				),
			);
		}

		$player_ids = get_posts( $args );
		$scores     = array();
		$low_gp     = 0;

		foreach ( $player_ids as $pid ) {
			$stats = get_post_meta( $pid, 'sp_statistics', true );
			if ( ! is_array( $stats ) ) {
				continue;
			}

			$player_stats = $this->extract_stats( $stats, $league_id, $season_id );
			$gp           = intval( $player_stats['gp'] ?? 0 );

			if ( $gp < $min_games ) {
				++$low_gp;
				continue;
			}

			$is_goalie = $this->is_goalie( $pid );

			if ( $is_goalie ) {
				$gaa = floatval( $player_stats['gaatwo'] ?? 0 );
				// Negative so lower GAA = higher score (0 GAA → score 0, best rank).
				$scores[ $pid ] = -$gaa;
			} else {
				$p              = intval( $player_stats['p'] ?? 0 );
				$scores[ $pid ] = $p / $gp;
			}
		}

		// Rank and map to 1-10.
		arsort( $scores );
		$total          = count( $scores );
		$rank           = 0;
		$updated        = 0;
		$skipped_manual = 0;

		foreach ( $scores as $pid => $raw ) {
			++$rank;
			$percentile = $total > 1 ? ( $total - $rank ) / ( $total - 1 ) : 0.5;
			$skill      = max( 1, min( 10, (int) round( $percentile * 9 ) + 1 ) );

			$source = get_post_meta( $pid, 'spt_skill_source', true );
			if ( 'manual' === $source ) {
				++$skipped_manual;
				continue;
			}

			update_post_meta( $pid, 'spt_skill_level', $skill );
			update_post_meta( $pid, 'spt_skill_source', 'auto' );
			update_post_meta( $pid, 'spt_skill_updated', current_time( 'c' ) );
			++$updated;
		}

		return array(
			'updated'        => $updated,
			'skipped_manual' => $skipped_manual,
			'skipped_low_gp' => $low_gp,
			'total_eligible' => $total,
		);
	}

	/**
	 * Extract the best matching stats for a player given league/season filters.
	 *
	 * SportsPress stores stats as: league_id => season_id => { g, a, pim, p, gp, ... }
	 * Season 0 = totals across all seasons for that league.
	 * League 0 = totals across all leagues.
	 *
	 * @param array $stats    The sp_statistics meta value.
	 * @param int   $league_id Target league (0 = aggregate).
	 * @param int   $season_id Target season (0 = aggregate).
	 * @return array Stat values.
	 */
	private function extract_stats( $stats, $league_id, $season_id ) {
		// Exact match first.
		if ( $league_id && $season_id && isset( $stats[ $league_id ][ $season_id ] ) ) {
			return $stats[ $league_id ][ $season_id ];
		}

		// Specific league, aggregate season.
		if ( $league_id && isset( $stats[ $league_id ][0] ) ) {
			return $stats[ $league_id ][0];
		}

		// Specific season across all leagues — sum up.
		if ( $season_id ) {
			$merged = array();
			foreach ( $stats as $lid => $seasons ) {
				if ( ! is_array( $seasons ) || ! isset( $seasons[ $season_id ] ) ) {
					continue;
				}
				foreach ( $seasons[ $season_id ] as $key => $val ) {
					if ( ! isset( $merged[ $key ] ) ) {
						$merged[ $key ] = 0;
					}
					$merged[ $key ] += floatval( $val );
				}
			}
			if ( ! empty( $merged ) ) {
				return $merged;
			}
		}

		// Fallback: aggregate across everything (league 0, season 0).
		if ( isset( $stats[0][0] ) ) {
			return $stats[0][0];
		}

		// Last resort: first non-empty entry.
		foreach ( $stats as $lid => $seasons ) {
			if ( ! is_array( $seasons ) ) {
				continue;
			}
			foreach ( $seasons as $sid => $data ) {
				if ( is_array( $data ) && intval( $data['gp'] ?? 0 ) > 0 ) {
					return $data;
				}
			}
		}

		return array();
	}

	/**
	 * Check if a player is a goalie.
	 */
	private function is_goalie( $player_id ) {
		$positions = wp_get_post_terms( $player_id, 'sp_position', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $positions ) ) {
			return false;
		}
		foreach ( $positions as $slug ) {
			if ( in_array( strtolower( $slug ), self::$goalie_slugs, true ) ) {
				return true;
			}
		}
		return false;
	}

	// ------------------------------------------------------------------
	// Phase 2: Settings UI (called from SPT_Admin)
	// ------------------------------------------------------------------

	/**
	 * Render the skill level settings section.
	 */
	public static function render_settings() {
		$min_games = get_option( 'spt_skill_min_games', '3' );
		?>
		<hr>
		<h2><?php esc_html_e( 'Skill Level Tracking', 'sportspress-player-tools' ); ?></h2>
			'taxonomy'   => 'sp_league',
			'hide_empty' => false,
			'orderby'    => 'name',
		) );

		$seasons = get_terms( array(
			'taxonomy'   => 'sp_season',
			'hide_empty' => false,
			'orderby'    => 'term_id',
			'order'      => 'DESC',
		) );
		?>
		<hr>
		<h2><?php esc_html_e( 'Skill Level Tracking', 'sportspress-player-tools' ); ?></h2>

		<?php
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
		<?php
	}
}
