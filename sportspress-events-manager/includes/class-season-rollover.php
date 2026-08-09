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

	/**
	 * TTL, in seconds, of the per-league rollover mutex. Sized for one wizard
	 * request: the full season/team/calendar/roster pass plus a single 500-event
	 * archive chunk, not the whole multi-chunk wizard run.
	 */
	const ROLLOVER_LOCK_TTL = 300;

	public function __construct() {
		add_action( 'wp_ajax_spem_season_rollover_preview', array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_spem_season_rollover_execute', array( $this, 'ajax_execute' ) );
		add_action( 'spem_admin_tab_content', array( $this, 'render_ui' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue inline JS for the rollover wizard on the SPAT admin page.
	 */
	public function enqueue_scripts( $hook ) {
		// Only load the rollover wizard JS on the SPAT settings page. Loose
		// substring matches previously fired on unrelated SportsPress screens.
		if ( 'settings_page_sportspress-admin-tools' !== $hook ) {
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

    // Hard cap on continuation chunks. archive_old_events processes 500 events
    // per call, so the default of 50 iterations covers 25,000 events — well past
    // any realistic league size. Prevents runaway recursion on a malformed server
    // response. Override via the `spem_max_archive_chunks` filter if a site
    // genuinely needs more.
    var MAX_ARCHIVE_CHUNKS = " . (int) apply_filters( 'spem_max_archive_chunks', 50 ) . ";

    function executeRollover(params) {
        return $.post(ajaxurl, $.extend({
            action: 'spem_season_rollover_execute',
            _ajax_nonce: $('#spem_rollover_nonce').val()
        }, params));
    }

    // The first call does the full rollover (season/teams/calendars/rosters)
    // plus the first archive chunk. Subsequent calls re-run the whole rollover
    // handler, but the team/calendar/roster steps are idempotent (they check
    // for existing records) and the archive step filters out events already
    // stamped with _spem_archived, so only fresh events get processed.
    function archiveLoop(baseParams, firstResponse, \$btn) {
        var totalArchived = firstResponse.events_archived || 0;
        var chunks = 1;

        function step(prevRes) {
            if (prevRes.archive_done !== false) {
                finish(prevRes);
                return;
            }
            if (chunks >= MAX_ARCHIVE_CHUNKS) {
                \$result.html('<div class=\"notice notice-warning\"><p>Archive stopped after ' + totalArchived + ' events (chunk cap reached). Re-run rollover to continue archiving remaining events.</p></div>');
                \$btn.prop('disabled', false);
                return;
            }
            \$result.html('<p>Archiving events: ' + totalArchived + ' archived so far…</p>');
            chunks++;
            executeRollover(baseParams).done(function(response) {
                if (!response.success) {
                    \$result.html('<div class=\"notice notice-error\"><p>' + $('<span>').text(response.data).html() + '</p></div>');
                    \$btn.prop('disabled', false);
                    return;
                }
                totalArchived += (response.data.events_archived || 0);
                step(response.data);
            }).fail(function() {
                \$result.html('<div class=\"notice notice-error\"><p>Archive request failed after ' + totalArchived + ' events archived.</p></div>');
                \$btn.prop('disabled', false);
            });
        }

        function finish(finalRes) {
            var d = finalRes;
            var html = '<div class=\"notice notice-success\"><p>Season rollover complete.</p><ul>';
            html += '<li>Season created: ' + $('<span>').text(d.season_name).html() + '</li>';
            html += '<li>Teams updated: ' + d.teams_updated + '</li>';
            if (d.calendars_created > 0) html += '<li>Calendars created: ' + d.calendars_created + '</li>';
            if (d.rosters_created > 0) html += '<li>Rosters created: ' + d.rosters_created + '</li>';
            if (totalArchived > 0) html += '<li>Events archived: ' + totalArchived + '</li>';
            html += '</ul></div>';
            \$result.html(html);
            \$btn.prop('disabled', false);
        }

        step(firstResponse);
    }

    $('#spem-rollover-execute-btn').on('click', function() {
        if (!confirm('Execute season rollover? This cannot be undone.')) return;
        var \$btn = $(this);
        \$btn.prop('disabled', true);
        \$result.html('<p>Processing...</p>');

        var baseParams = {
            league: $('#spem-rollover-league').val(),
            season_name: $('#spem-rollover-season').val(),
            create_calendars: $('#spem-rollover-calendars').is(':checked') ? 1 : 0,
            create_rosters: $('#spem-rollover-rosters').is(':checked') ? 1 : 0,
            archive_old: $('#spem-rollover-archive').is(':checked') ? 1 : 0
        };

        executeRollover(baseParams).done(function(response) {
            if (!response.success) {
                \$result.html('<div class=\"notice notice-error\"><p>' + $('<span>').text(response.data).html() + '</p></div>');
                \$btn.prop('disabled', false);
                return;
            }
            archiveLoop(baseParams, response.data, \$btn);
        }).fail(function() {
            \$result.html('<div class=\"notice notice-error\"><p>Rollover request failed.</p></div>');
            \$btn.prop('disabled', false);
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
		$season_name = isset( $_POST['season_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) ) : '';

		if ( ! $league_id || empty( $season_name ) ) {
			wp_send_json_error( __( 'League and season name are required.', 'sportspress-events-manager' ) );
		}

		if ( ! $this->is_valid_season_name( $season_name ) ) {
			wp_send_json_error( $this->season_name_error_message() );
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
		$season_name      = isset( $_POST['season_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) ) : '';
		$create_calendars = ! empty( $_POST['create_calendars'] );
		$create_rosters   = ! empty( $_POST['create_rosters'] );
		$archive_old      = ! empty( $_POST['archive_old'] );

		if ( ! $league_id || empty( $season_name ) ) {
			wp_send_json_error( __( 'League and season name are required.', 'sportspress-events-manager' ) );
		}

		if ( ! $this->is_valid_season_name( $season_name ) ) {
			wp_send_json_error( $this->season_name_error_message() );
		}

		// Serialize per league. The season/team/calendar/roster steps are
		// check-then-insert, not atomic, so two admins (or a double-clicked
		// button) running a rollover on the same league concurrently mint
		// duplicate sp_calendar / sp_list posts (M13).
		//
		// This does NOT break the wizard's chunked archive flow: the JS issues
		// each 500-event continuation only from the previous request's `done`
		// callback, so the chunks are strictly sequential and each one acquires
		// and releases the lock within its own request. That also requires the
		// JSON response to be emitted OUTSIDE the critical section — wp_send_json_*
		// calls exit(), which would skip the release and strand the lock for its
		// whole TTL, blocking the very next chunk.
		if ( class_exists( 'SPAT_Lock' ) ) {
			$result = SPAT_Lock::with(
				'spem_rollover_' . $league_id,
				self::ROLLOVER_LOCK_TTL,
				function () use ( $league_id, $season_name, $create_calendars, $create_rosters, $archive_old ) {
					return $this->run_rollover( $league_id, $season_name, $create_calendars, $create_rosters, $archive_old );
				}
			);

			// run_rollover() returns an array or a WP_Error, never false, so a
			// literal false is SPAT_Lock::with()'s "already held" signal.
			if ( false === $result ) {
				wp_send_json_error( __( 'A season rollover is already running for this league. Please wait for it to finish.', 'sportspress-events-manager' ) );
			}
		} else {
			$result = $this->run_rollover( $league_id, $season_name, $create_calendars, $create_rosters, $archive_old );
		}

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Perform the rollover. Runs inside the per-league mutex, so it must never
	 * emit output or exit — every failure path returns a WP_Error instead.
	 *
	 * @param int    $league_id        League term ID.
	 * @param string $season_name      Validated new season name.
	 * @param bool   $create_calendars Whether to create per-team calendars.
	 * @param bool   $create_rosters   Whether to create per-team player lists.
	 * @param bool   $archive_old      Whether to archive a chunk of old events.
	 * @return array|WP_Error Response payload for the wizard, or an error.
	 */
	private function run_rollover( $league_id, $season_name, $create_calendars, $create_rosters, $archive_old ) {
		// 1. Create new season term
		$existing = get_term_by( 'name', $season_name, 'sp_season' );
		if ( $existing ) {
			$season_term_id = $existing->term_id;
		} else {
			$result = wp_insert_term( $season_name, 'sp_season' );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$season_term_id = $result['term_id'];
		}

		// 2. Get teams and assign new season
		$teams = $this->get_league_teams( $league_id );
		if ( empty( $teams ) ) {
			return new WP_Error( 'no_teams', __( 'No teams found in the selected league.', 'sportspress-events-manager' ) );
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
				// Idempotency: skip if calendar already exists for this
				// team+season. Avoid serialize() in meta_query — narrow with
				// a LIKE on the serialized fragment, then do a PHP-side team
				// check to defend against false positives.
				$season_cal_ids = get_posts(
					array(
						'post_type'      => 'sp_calendar',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'fields'         => 'ids',
						'tax_query'      => array(
							array(
								'taxonomy' => 'sp_season',
								'field'    => 'term_id',
								'terms'    => $season_term_id,
							),
						),
						'meta_query'     => array(
							array(
								'key'     => 'sp_team',
								'value'   => sprintf( 'i:%d;', (int) $team->ID ),
								'compare' => 'LIKE',
							),
						),
					)
				);

				$existing_cal = array();
				if ( ! empty( $season_cal_ids ) ) {
					update_meta_cache( 'post', $season_cal_ids );
					foreach ( $season_cal_ids as $cal_id ) {
						$cal_teams = (array) get_post_meta( $cal_id, 'sp_team', true );
						if ( in_array( (int) $team->ID, array_map( 'intval', $cal_teams ), true ) ) {
							$existing_cal[] = $cal_id;
							break;
						}
					}
				}

				if ( empty( $existing_cal ) ) {
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
			}

			// 4. Optionally create empty roster (player list)
			if ( $create_rosters ) {
				// Idempotency: skip if a roster already exists for this
				// team+season. The wizard re-POSTs this whole action once per
				// 500-event archive chunk, so without this check a >500-event
				// league would get a duplicate roster set per chunk (H5).
				// sp_list stores sp_team as a scalar post ID (see below), so a
				// direct meta value match is exact — no serialized LIKE needed.
				$existing_list_ids = get_posts(
					array(
						'post_type'      => 'sp_list',
						'post_status'    => 'any',
						'posts_per_page' => 1,
						'fields'         => 'ids',
						'tax_query'      => array(
							array(
								'taxonomy' => 'sp_season',
								'field'    => 'term_id',
								'terms'    => $season_term_id,
							),
						),
						'meta_query'     => array(
							array(
								'key'   => 'sp_team',
								'value' => (int) $team->ID,
							),
						),
					)
				);

				if ( empty( $existing_list_ids ) ) {
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
		}

		// 5. Optionally archive old season events. Capped per call — the UI
		// re-invokes the archive step until `archive_done` returns true.
		$archive_done = true;
		if ( $archive_old ) {
			$archive_result   = $this->archive_old_events( $league_id, $season_term_id );
			$events_archived  = $archive_result['count'];
			$archive_done     = $archive_result['done'];
		}

		// 6. Update the default season for the dynamic standings shortcode.
		update_option( 'spem_current_season_id', $season_term_id );

		return array(
			'season_name'       => $season_name,
			'teams_updated'     => $teams_updated,
			'calendars_created' => $calendars_created,
			'rosters_created'   => $rosters_created,
			'events_archived'   => $events_archived,
			'archive_done'      => $archive_done,
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
	 *
	 * Marks them with the `_spem_archived` meta flag rather than the
	 * unregistered 'past' post_status, which WordPress doesn't honor in
	 * queries and which previously hid events from the editor.
	 *
	 * Capped at 500 events per call so a 5000-event league doesn't blow the
	 * AJAX request's max_execution_time / memory_limit. The query filters out
	 * already-archived events so callers can re-invoke (offset 0) until
	 * `done` is true.
	 *
	 * @param int $league_id      League term ID.
	 * @param int $new_season_id  The newly created season term ID to exclude.
	 * @return array { count: int, done: bool, next_offset: int }
	 */
	private function archive_old_events( $league_id, $new_season_id ) {
		$chunk_size = 500;

		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => 'publish',
				'posts_per_page' => $chunk_size,
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
				// Filter out events we already stamped; that lets callers
				// re-invoke at offset 0 until the query goes empty.
				'meta_query'     => array(
					array(
						'key'     => '_spem_archived',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$count = 0;
		foreach ( $events as $event_id ) {
			update_post_meta( $event_id, '_spem_archived', 1 );
			$count++;
		}

		// Done when fewer rows came back than the cap — there's nothing left
		// to stamp on a follow-up call.
		$done = $count < $chunk_size;

		return array(
			'count'       => $count,
			'done'        => $done,
			'next_offset' => $done ? 0 : $count,
		);
	}

	/**
	 * Validate a season name string. Allowed forms:
	 *   W2025, W2025-26, S2025, S2025-26, W2025 Playoffs, W2025-26 Playoffs
	 *
	 * @param string $name Candidate season name.
	 * @return bool
	 */
	private function is_valid_season_name( $name ) {
		return (bool) preg_match( '/^[A-Za-z]\d{4}(-\d{2})?( Playoffs)?$/', $name );
	}

	/**
	 * Localized error message describing the accepted season-name format.
	 *
	 * @return string
	 */
	private function season_name_error_message() {
		return __( 'Invalid season name. Examples: W2025, W2025-26, S2025-26 Playoffs.', 'sportspress-events-manager' );
	}

	/**
	 * One-time migration: convert legacy post_status='past' sp_event posts to
	 * the new `_spem_archived` meta flag and republish them so they appear in
	 * queries and the editor again.
	 *
	 * Safe to run repeatedly — it's a no-op once no past-status events remain.
	 */
	public static function migrate_past_status_to_meta_flag() {
		// Direct $wpdb is required because 'past' is not a registered status
		// and get_posts() refuses to return it.
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
				'sp_event',
				'past'
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $event_id ) {
			update_post_meta( (int) $event_id, '_spem_archived', 1 );
			wp_update_post(
				array(
					'ID'          => (int) $event_id,
					'post_status' => 'publish',
				)
			);
		}

		return count( $ids );
	}
}
