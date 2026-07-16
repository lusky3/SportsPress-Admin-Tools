<?php
/**
 * League Table Generator
 *
 * Creates a SportsPress League Table (sp_table) for a given league + season,
 * pre-populated with that league/season's teams and the standard standings
 * columns — the manual "Tables → Add New" flow, automated.
 *
 * This class is the single canonical implementation. It is exposed in two
 * places that share this one method so they can never drift:
 *   - wp-admin: a "League Table Generator" section on the Events Manager
 *     settings tab (rendered/handled here).
 *   - The League Manager React dashboard (optional add-on): its
 *     /splm/v1/standings/generate route delegates here when this plugin is
 *     present, falling back to its own copy only when it is not.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_League_Table_Generator {

	/**
	 * Standard standings columns applied to a generated table. Mirrors the
	 * SportsPress default league-table layout.
	 *
	 * @var string[]
	 */
	private static $default_columns = array( 'pos', 'name', 'p', 'w', 'd', 'l', 'f', 'a', 'gd', 'pts' );

	public function __construct() {
		// wp-admin surface: add a section to the Events Manager settings tab and
		// handle its form submission. Guarded by is_admin() at the caller.
		add_action( 'spem_admin_tab_content', array( $this, 'render_admin_section' ) );
	}

	/**
	 * Create an sp_table for the given league + season, populated with the
	 * teams in that league/season and the standard standings columns.
	 *
	 * @param int $league_id sp_league term id.
	 * @param int $season_id sp_season term id.
	 * @return array|WP_Error { table_id, title, teams } on success.
	 */
	public function generate( $league_id, $season_id ) {
		$league_id = absint( $league_id );
		$season_id = absint( $season_id );

		if ( ! $league_id || ! $season_id ) {
			return new WP_Error( 'missing_params', __( 'A league and a season are both required.', 'sportspress-events-manager' ), array( 'status' => 400 ) );
		}

		$league = get_term( $league_id, 'sp_league' );
		$season = get_term( $season_id, 'sp_season' );
		if ( ! $league || is_wp_error( $league ) || ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_params', __( 'Invalid league or season.', 'sportspress-events-manager' ), array( 'status' => 400 ) );
		}

		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					'relation' => 'AND',
					array(
						'taxonomy' => 'sp_league',
						'terms'    => $league_id,
					),
					array(
						'taxonomy' => 'sp_season',
						'terms'    => $season_id,
					),
				),
			)
		);

		$title    = $league->name . ' — ' . $season->name;
		$table_id = wp_insert_post(
			array(
				'post_type'   => 'sp_table',
				'post_title'  => $title,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $table_id ) ) {
			return $table_id;
		}

		wp_set_object_terms( $table_id, $league_id, 'sp_league' );
		wp_set_object_terms( $table_id, $season_id, 'sp_season' );

		// New post has no sp_team rows, but clear defensively so the meta stays
		// canonical if this is ever run against an existing table id.
		delete_post_meta( $table_id, 'sp_team' );
		foreach ( $teams as $team_id ) {
			add_post_meta( $table_id, 'sp_team', (int) $team_id );
		}

		update_post_meta( $table_id, 'sp_columns', self::$default_columns );

		return array(
			'table_id' => (int) $table_id,
			'title'    => $title,
			'teams'    => count( $teams ),
		);
	}

	/* ── wp-admin surface ── */

	/**
	 * Render the generator section on the Events Manager settings tab, and
	 * process its submission. Hooked to spem_admin_tab_content.
	 */
	public function render_admin_section() {
		$result = $this->handle_admin_submission();

		echo '<h2>' . esc_html__( 'League Table Generator', 'sportspress-events-manager' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Create a standings table for a league and season, pre-filled with that group\'s teams and the standard columns.', 'sportspress-events-manager' ) . '</p>';

		if ( is_wp_error( $result ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $result->get_error_message() ) . '</p></div>';
		} elseif ( is_array( $result ) ) {
			$edit_link = admin_url( 'post.php?post=' . $result['table_id'] . '&action=edit' );
			echo '<div class="notice notice-success"><p>' . sprintf(
				/* translators: 1: table title link, 2: team count */
				esc_html__( 'Created league table %1$s with %2$d teams.', 'sportspress-events-manager' ),
				'<a href="' . esc_url( $edit_link ) . '" target="_blank">' . esc_html( $result['title'] ) . '</a>',
				(int) $result['teams']
			) . '</p></div>';
		}

		$leagues = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);
		$seasons = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $leagues ) || is_wp_error( $seasons ) || empty( $leagues ) || empty( $seasons ) ) {
			echo '<p>' . esc_html__( 'At least one league and one season must exist before a table can be generated.', 'sportspress-events-manager' ) . '</p>';
			return;
		}
		?>
		<form method="post">
			<?php wp_nonce_field( 'spem_generate_table', 'spem_generate_table_nonce' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="spem_ltg_league"><?php esc_html_e( 'League', 'sportspress-events-manager' ); ?></label></th>
					<td>
						<select id="spem_ltg_league" name="spem_ltg_league" required>
							<option value=""><?php esc_html_e( 'Select a league…', 'sportspress-events-manager' ); ?></option>
							<?php foreach ( $leagues as $league ) : ?>
								<option value="<?php echo esc_attr( $league->term_id ); ?>"><?php echo esc_html( $league->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spem_ltg_season"><?php esc_html_e( 'Season', 'sportspress-events-manager' ); ?></label></th>
					<td>
						<select id="spem_ltg_season" name="spem_ltg_season" required>
							<option value=""><?php esc_html_e( 'Select a season…', 'sportspress-events-manager' ); ?></option>
							<?php foreach ( $seasons as $season ) : ?>
								<option value="<?php echo esc_attr( $season->term_id ); ?>"><?php echo esc_html( $season->name ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate League Table', 'sportspress-events-manager' ), 'primary', 'spem_generate_table' ); ?>
		</form>
		<?php
	}

	/**
	 * Process the generator form POST. Returns the generate() result
	 * (array|WP_Error) when this form was submitted, or null otherwise.
	 *
	 * @return array|WP_Error|null
	 */
	private function handle_admin_submission() {
		if ( ! isset( $_POST['spem_generate_table'] ) ) {
			return null;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to generate tables.', 'sportspress-events-manager' ) );
		}

		check_admin_referer( 'spem_generate_table', 'spem_generate_table_nonce' );

		$league_id = isset( $_POST['spem_ltg_league'] ) ? absint( wp_unslash( $_POST['spem_ltg_league'] ) ) : 0;
		$season_id = isset( $_POST['spem_ltg_season'] ) ? absint( wp_unslash( $_POST['spem_ltg_season'] ) ) : 0;

		return $this->generate( $league_id, $season_id );
	}
}
