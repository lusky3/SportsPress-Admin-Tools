<?php
/**
 * Season Rollover Class
 *
 * Provides a guided workflow for transitioning from one season to the next.
 * Creates new season terms, assigns them to teams, and optionally creates
 * calendars, rosters, and archives old season data.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Season_Rollover {

	public function __construct() {
		add_action( 'wp_ajax_spem_season_rollover_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_spem_season_rollover_execute', array( $this, 'ajax_execute' ) );
		add_action( 'spat_admin_page_content', array( $this, 'render_ui' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue inline JS for the rollover wizard on the SPAT admin page.
	 */
	public function enqueue_scripts( $hook ) {
		if ( strpos( $hook, 'spat' ) === false && strpos( $hook, 'sportspress' ) === false ) {
			return;
		}

		wp_enqueue_script( 'jquery' );

		$js = "
jQuery(document).ready(function($) {
    var \$section = $('#spem-season-rollover');
    if (!\$section.length) return;

    var \$preview = $('#spem-rollover-preview');
    var \$execute = $('#spem-rollover-execute');
    var \$result  = $('#spem-rollover-result');

    $('#spem-rollover-preview-btn').on('click', function() {
        var league = $('#spem-rollover-league').val();
        var season = $('#spem-rollover-season').val();
        if (!league || !season) {
            alert('Please select a league and enter a season name.');
            return;
        }
        \$preview.html('<p>Loading preview...</p>');
        $.post(ajaxurl, {
            action: 'spem_season_rollover_preview',
            _ajax_nonce: $('#spem_rollover_nonce').val(),
            league: league,
            season_name: season
        }, function(response) {
            if (response.success) {
                var d = response.data;
                var html = '<p><strong>Season:</strong> ' + $('<span>').text(d.season_name).html() + '</p>';
                html += '<p><strong>Teams (' + d.teams.length + '):</strong></p><ul>';
                $.each(d.teams, function(i, t) {
                    html += '<li>' + $('<span>').text(t.name).html() + '</li>';
                });
                html += '</ul>';
                \$preview.html(html);
                \$execute.show();
            } else {
                \$preview.html('<p class=\"notice notice-error\">' + $('<span>').text(response.data).html() + '</p>');
            }
        });
    });

    $('#spem-rollover-execute-btn').on('click', function() {
        if (!confirm('Execute season rollover? This cannot be undone.')) return;
        var \$btn = $(this);
        \$btn.prop('disabled', true);
        \$result.html('<p>Processing...</p>');
        $.post(ajaxurl, {
            action: 'spem_season_rollover_execute',
            _ajax_nonce: $('#spem_rollover_nonce').val(),
            league: $('#spem-rollover-league').val(),
            season_name: $('#spem-rollover-season').val(),
            create_calendars: $('#spem-rollover-calendars').is(':checked') ? 1 : 0,
            create_rosters: $('#spem-rollover-rosters').is(':checked') ? 1 : 0,
            archive_old: $('#spem-rollover-archive').is(':checked') ? 1 : 0
        }, function(response) {
            \$btn.prop('disabled', false);
            if (response.success) {
                var d = response.data;
                var html = '<div class=\"notice notice-success\"><p>Season rollover complete.</p><ul>';
                html += '<li>Season created: ' + $('<span>').text(d.season_name).html() + '</li>';
                html += '<li>Teams updated: ' + d.teams_updated + '</li>';
                if (d.calendars_created > 0) html += '<li>Calendars created: ' + d.calendars_created + '</li>';
                if (d.rosters_created > 0) html += '<li>Rosters created: ' + d.rosters_created + '</li>';
                if (d.events_archived > 0) html += '<li>Events archived: ' + d.events_archived + '</li>';
                html += '</ul></div>';
                \$result.html(html);
            } else {
                \$result.html('<div class=\"notice notice-error\"><p>' + $('<span>').text(response.data).html() + '</p></div>');
            }
        });
    });
});
";
		wp_add_inline_script( 'jquery', $js );
	}

	/**
	 * Render the Season Rollover UI section inside the Events Manager admin tab.
	 */
	public function render_ui() {
		$leagues = get_terms(
			array(
				'taxonomy'   => 'sp_league',
				'hide_empty' => false,
			)
		);
		?>
		<div id="spem-season-rollover" style="margin-top:20px;">
			<h2><?php esc_html_e( 'Season Rollover', 'sportspress-events-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Transition teams from one season to the next. Creates a new season and optionally sets up calendars and rosters.', 'sportspress-events-manager' ); ?></p>

			<?php wp_nonce_field( 'spem_season_rollover', 'spem_rollover_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row"><label for="spem-rollover-league"><?php esc_html_e( 'League', 'sportspress-events-manager' ); ?></label></th>
					<td>
						<select id="spem-rollover-league">
							<option value=""><?php esc_html_e( '— Select League —', 'sportspress-events-manager' ); ?></option>
							<?php if ( ! empty( $leagues ) && ! is_wp_error( $leagues ) ) : ?>
								<?php foreach ( $leagues as $league ) : ?>
									<option value="<?php echo esc_attr( $league->term_id ); ?>"><?php echo esc_html( $league->name ); ?></option>
								<?php endforeach; ?>
							<?php endif; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="spem-rollover-season"><?php esc_html_e( 'New Season Name', 'sportspress-events-manager' ); ?></label></th>
					<td>
						<input type="text" id="spem-rollover-season" class="regular-text" placeholder="W2025, S2025-26" />
						<p class="description"><?php esc_html_e( 'Format hint: W2025 (winter), S2025-26 (spanning years)', 'sportspress-events-manager' ); ?></p>
					</td>
				</tr>
			</table>

			<p><button type="button" id="spem-rollover-preview-btn" class="button button-secondary"><?php esc_html_e( 'Preview Teams', 'sportspress-events-manager' ); ?></button></p>

			<div id="spem-rollover-preview"></div>

			<div id="spem-rollover-execute" style="display:none;">
				<h3><?php esc_html_e( 'Options', 'sportspress-events-manager' ); ?></h3>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Create Calendars', 'sportspress-events-manager' ); ?></th>
						<td><label><input type="checkbox" id="spem-rollover-calendars" checked /> <?php esc_html_e( 'Create a new calendar for each team for the new season', 'sportspress-events-manager' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Create Rosters', 'sportspress-events-manager' ); ?></th>
						<td><label><input type="checkbox" id="spem-rollover-rosters" /> <?php esc_html_e( 'Create an empty player list (roster) for each team', 'sportspress-events-manager' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Archive Old Season', 'sportspress-events-manager' ); ?></th>
						<td><label><input type="checkbox" id="spem-rollover-archive" /> <?php esc_html_e( 'Mark old season events in this league as past', 'sportspress-events-manager' ); ?></label></td>
					</tr>
				</table>
				<p><button type="button" id="spem-rollover-execute-btn" class="button button-primary"><?php esc_html_e( 'Execute Rollover', 'sportspress-events-manager' ); ?></button></p>
			</div>

			<div id="spem-rollover-result"></div>
		</div>
		<?php
	}

	/**
	 * AJAX handler: preview teams for the selected league.
	 */
	public function ajax_preview() {
		check_ajax_referer( 'spem_season_rollover' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-events-manager' ) );
		}

		$league_id   = isset( $_POST['league'] ) ? absint( $_POST['league'] ) : 0;
		$season_name = isset( $_POST['season_name'] ) ? sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) : '';

		if ( ! $league_id || empty( $season_name ) ) {
			wp_send_json_error( __( 'League and season name are required.', 'sportspress-events-manager' ) );
		}

		$teams = $this->get_league_teams( $league_id );
		if ( empty( $teams ) ) {
			wp_send_json_error( __( 'No teams found in the selected league.', 'sportspress-events-manager' ) );
		}

		$team_list = array();
		foreach ( $teams as $team ) {
			$team_list[] = array(
				'id' => $team->ID,
				'name' => $team->post_title,
			);
		}

		wp_send_json_success(
			array(
				'season_name' => $season_name,
				'teams'       => $team_list,
			)
		);
	}

	/**
	 * AJAX handler: execute the season rollover.
	 */
	public function ajax_execute() {
		check_ajax_referer( 'spem_season_rollover' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-events-manager' ) );
		}

		$league_id        = isset( $_POST['league'] ) ? absint( $_POST['league'] ) : 0;
		$season_name      = isset( $_POST['season_name'] ) ? sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) : '';
		$create_calendars = ! empty( $_POST['create_calendars'] );
		$create_rosters   = ! empty( $_POST['create_rosters'] );
		$archive_old      = ! empty( $_POST['archive_old'] );

		if ( ! $league_id || empty( $season_name ) ) {
			wp_send_json_error( __( 'League and season name are required.', 'sportspress-events-manager' ) );
		}

		// 1. Create new season term
		$existing = get_term_by( 'name', $season_name, 'sp_season' );
		if ( $existing ) {
			$season_term_id = $existing->term_id;
		} else {
			$result = wp_insert_term( $season_name, 'sp_season' );
			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}
			$season_term_id = $result['term_id'];
		}

		// 2. Get teams and assign new season
		$teams = $this->get_league_teams( $league_id );
		if ( empty( $teams ) ) {
			wp_send_json_error( __( 'No teams found in the selected league.', 'sportspress-events-manager' ) );
		}

		$teams_updated     = 0;
		$calendars_created = 0;
		$rosters_created   = 0;
		$events_archived   = 0;

		foreach ( $teams as $team ) {
			// Append (not replace) the new season to the team
			wp_set_object_terms( $team->ID, $season_term_id, 'sp_season', true );
			$teams_updated++;

			// 3. Optionally create calendar
			if ( $create_calendars ) {
				$cal_title = $team->post_title . ' — ' . $season_name;
				$cal_id = wp_insert_post(
					array(
						'post_type'   => 'sp_calendar',
						'post_title'  => $cal_title,
						'post_status' => 'publish',
					)
				);
				if ( $cal_id && ! is_wp_error( $cal_id ) ) {
					update_post_meta( $cal_id, 'sp_team', array( $team->ID ) );
					wp_set_object_terms( $cal_id, array( $season_term_id ), 'sp_season' );
					wp_set_object_terms( $cal_id, array( $league_id ), 'sp_league' );
					update_post_meta( $cal_id, 'sp_format', get_option( 'spem_calendar_type', 'list' ) );
					$calendars_created++;
				}
			}

			// 4. Optionally create empty roster (player list)
			if ( $create_rosters ) {
				$list_title = $team->post_title . ' — ' . $season_name . ' Roster';
				$list_id = wp_insert_post(
					array(
						'post_type'   => 'sp_list',
						'post_title'  => $list_title,
						'post_status' => 'publish',
					)
				);
				if ( $list_id && ! is_wp_error( $list_id ) ) {
					update_post_meta( $list_id, 'sp_team', $team->ID );
					wp_set_object_terms( $list_id, array( $season_term_id ), 'sp_season' );
					wp_set_object_terms( $list_id, array( $league_id ), 'sp_league' );
					$rosters_created++;
				}
			}
		}

		// 5. Optionally archive old season events
		if ( $archive_old ) {
			$events_archived = $this->archive_old_events( $league_id, $season_term_id );
		}

		wp_send_json_success(
			array(
				'season_name'       => $season_name,
				'teams_updated'     => $teams_updated,
				'calendars_created' => $calendars_created,
				'rosters_created'   => $rosters_created,
				'events_archived'   => $events_archived,
			)
		);
	}

	/**
	 * Get all published teams in a league.
	 *
	 * @param int $league_id League term ID.
	 * @return WP_Post[] Array of team posts.
	 */
	private function get_league_teams( $league_id ) {
		return get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_league',
						'field'    => 'term_id',
						'terms'    => $league_id,
					),
				),
			)
		);
	}

	/**
	 * Archive events in a league that do NOT belong to the new season.
	 * Sets their post status to 'past' (a SportsPress convention for completed events).
	 *
	 * @param int $league_id      League term ID.
	 * @param int $new_season_id  The newly created season term ID to exclude.
	 * @return int Number of events archived.
	 */
	private function archive_old_events( $league_id, $new_season_id ) {
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
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
						'terms'    => $new_season_id,
						'operator' => 'NOT IN',
					),
				),
			)
		);

		$count = 0;
		foreach ( $events as $event_id ) {
			wp_update_post(
				array(
					'ID'          => $event_id,
					'post_status' => 'past',
				)
			);
			$count++;
		}

		return $count;
	}
}
