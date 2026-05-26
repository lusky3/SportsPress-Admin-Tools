<?php
/**
 * League Table Generator Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_League_Table_Generator {

	public function __construct() {
		add_action( 'wp_ajax_generate_league_table', array( $this, 'ajax_generate_league_table' ) );
		add_action( 'admin_footer', array( $this, 'add_league_table_modal' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue the league-table-generator script on the SPAT settings screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'settings_page_sportspress-admin-tools' !== $hook ) {
			return;
		}

		$plugin_url = plugins_url( '', __DIR__ . '/..' ) . '/';
		wp_enqueue_script(
			'spem-league-table-generator',
			$plugin_url . 'assets/js/league-table-generator.js',
			array( 'jquery' ),
			SPEM_VERSION,
			true
		);
		wp_localize_script(
			'spem-league-table-generator',
			'spemLeagueTable',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'generate_league_table' ),
			)
		);
	}

	public function ajax_generate_league_table() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'generate_league_table' ) ) {
			wp_send_json_error( __( 'Security check failed.', 'sportspress-events-manager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions.', 'sportspress-events-manager' ) );
		}

		$league_id  = isset( $_POST['league_id'] ) ? intval( $_POST['league_id'] ) : 0;
		$season_id  = isset( $_POST['season_id'] ) ? intval( $_POST['season_id'] ) : 0;
		$table_name = isset( $_POST['table_name'] ) ? sanitize_text_field( $_POST['table_name'] ) : '';

		if ( ! $league_id || ! $season_id || ! $table_name ) {
			wp_send_json_error( __( 'Missing required parameters.', 'sportspress-events-manager' ) );
		}

		// Reject term IDs that don't actually refer to real sp_league /
		// sp_season terms — wp_set_object_terms silently no-ops on bad input
		// and leaves the user with a broken-but-published sp_table.
		if ( ! term_exists( $league_id, 'sp_league' ) ) {
			wp_send_json_error( __( 'Invalid league.', 'sportspress-events-manager' ) );
		}
		if ( ! term_exists( $season_id, 'sp_season' ) ) {
			wp_send_json_error( __( 'Invalid season.', 'sportspress-events-manager' ) );
		}

		$table_id = $this->create_league_table( $league_id, $season_id, $table_name );

		if ( $table_id ) {
			wp_send_json_success(
				array(
					'message'  => __( 'League table created successfully!', 'sportspress-events-manager' ),
					'edit_url' => admin_url( 'post.php?post=' . intval( $table_id ) . '&action=edit' ),
				)
			);
		} else {
			wp_send_json_error( __( 'Failed to create league table.', 'sportspress-events-manager' ) );
		}
	}

	private function create_league_table( $league_id, $season_id, $table_name ) {
		$table_id = wp_insert_post(
			array(
				'post_type'   => 'sp_table',
				'post_title'  => $table_name,
				'post_status' => 'publish',
			)
		);

		if ( ! $table_id || is_wp_error( $table_id ) ) {
			return false;
		}

		wp_set_object_terms( $table_id, array( $league_id ), 'sp_league' );
		wp_set_object_terms( $table_id, array( $season_id ), 'sp_season' );

		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $league_id,
					),
					array(
						'taxonomy' => 'sp_season',
						'field'    => 'term_id',
						'terms'    => $season_id,
					),
				),
			)
		);

		if ( ! empty( $teams ) ) {
			$team_ids = wp_list_pluck( $teams, 'ID' );
			update_post_meta( $table_id, 'sp_team', $team_ids );
		}

		$columns = array( 'pos', 'name', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts' );
		update_post_meta( $table_id, 'sp_columns', $columns );

		return $table_id;
	}

	/**
	 * Output the league table generation modal on the admin tools settings page.
	 */
	public function add_league_table_modal() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->base !== 'settings_page_sportspress-admin-tools' ) {
			return;
		}

		$leagues = get_terms(
			array(
				'taxonomy' => 'sp_league',
				'hide_empty' => false,
			)
		);
		$seasons = get_terms(
			array(
				'taxonomy' => 'sp_season',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $leagues ) ) {
			$leagues = array();
		}
		if ( is_wp_error( $seasons ) ) {
			$seasons = array();
		}

		?>
		<div id="league-table-modal" style="display:none;">
			<div style="background:white; padding:20px; border-radius:5px; max-width:500px; margin:50px auto;">
				<h3><?php esc_html_e( 'Generate League Table', 'sportspress-events-manager' ); ?></h3>
				<form id="league-table-form">
					<table class="form-table">
						<tr>
							<th><label for="league_select"><?php esc_html_e( 'League', 'sportspress-events-manager' ); ?></label></th>
							<td>
								<select id="league_select" name="league_id" required>
									<option value=""><?php esc_html_e( 'Select League', 'sportspress-events-manager' ); ?></option>
									<?php foreach ( $leagues as $league ) : ?>
										<option value="<?php echo esc_attr( $league->term_id ); ?>"><?php echo esc_html( $league->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="season_select"><?php esc_html_e( 'Season', 'sportspress-events-manager' ); ?></label></th>
							<td>
								<select id="season_select" name="season_id" required>
									<option value=""><?php esc_html_e( 'Select Season', 'sportspress-events-manager' ); ?></option>
									<?php foreach ( $seasons as $season ) : ?>
										<option value="<?php echo esc_attr( $season->term_id ); ?>"><?php echo esc_html( $season->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
						<tr>
							<th><label for="table_name"><?php esc_html_e( 'Table Name', 'sportspress-events-manager' ); ?></label></th>
							<td><input type="text" id="table_name" name="table_name" class="regular-text" required /></td>
						</tr>
					</table>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Generate Table', 'sportspress-events-manager' ); ?></button>
						<button type="button" class="button" onclick="closeLeagueTableModal()"><?php esc_html_e( 'Cancel', 'sportspress-events-manager' ); ?></button>
					</p>
				</form>
			</div>
		</div>
		<?php
	}
}
