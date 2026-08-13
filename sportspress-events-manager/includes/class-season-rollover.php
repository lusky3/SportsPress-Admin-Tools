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

require_once __DIR__ . '/class-rollover-teams.php';

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
            source_season: $('#spem-rollover-source').val(),
            season_name: season
        }, function(response) {
            if (response.success) {
                var d = response.data;
                var carrying = {};
                $.each(d.carry_over, function(i, t) { carrying[t.id] = true; });

                var html = '<p><strong>Season:</strong> ' + $('<span>').text(d.season_name).html() + '</p>';
                html += '<p><strong>Carrying forward (' + d.carry_over.length + '):</strong></p>';
                html += '<div class=\"spem-team-list\" style=\"max-height:260px;overflow:auto;border:1px solid #ccd0d4;padding:8px;\">';
                $.each(d.carry_over, function(i, t) {
                    html += '<label style=\"display:block;\"><input type=\"checkbox\" class=\"spem-team\" value=\"' + parseInt(t.id, 10) + '\" checked /> ' + $('<span>').text(t.name).html() + '</label>';
                });
                html += '</div>';

                html += '<p><strong>Add teams not in that season:</strong> <input type=\"search\" id=\"spem-team-filter\" placeholder=\"Filter…\" /></p>';
                html += '<div class=\"spem-team-pool\" style=\"max-height:260px;overflow:auto;border:1px solid #ccd0d4;padding:8px;\">';
                $.each(d.pool, function(i, t) {
                    if (carrying[t.id]) return;
                    var label = t.name + (t.last_season ? ' (last: ' + t.last_season + ')' : '');
                    html += '<label style=\"display:block;\" data-name=\"' + $('<span>').text(t.name.toLowerCase()).html() + '\"><input type=\"checkbox\" class=\"spem-team\" value=\"' + parseInt(t.id, 10) + '\" /> ' + $('<span>').text(label).html() + '</label>';
                });
                html += '</div>';
                html += '<p><strong>Selected: <span id=\"spem-team-count\">' + d.carry_over.length + '</span> teams</strong></p>';

                \$preview.html(html);
                \$execute.show();
            } else {
                \$preview.html('<p class=\"notice notice-error\">' + $('<span>').text(response.data).html() + '</p>');
            }
        });
    });

    // Live count so the operator can sanity-check 31 against an expected 30-34.
    \$section.on('change', '.spem-team', function() {
        $('#spem-team-count').text(\$section.find('.spem-team:checked').length);
    });

    \$section.on('input', '#spem-team-filter', function() {
        var q = $(this).val().toLowerCase();
        \$section.find('.spem-team-pool label').each(function() {
            var \$l = $(this);
            \$l.toggle(!q || \$l.attr('data-name').indexOf(q) !== -1);
        });
    });

    function selectedTeamIds() {
        return \$section.find('.spem-team:checked').map(function() {
            return parseInt(this.value, 10);
        }).get();
    }

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
            if (d.playoff_season) html += '<li>Playoff season created: ' + $('<span>').text(d.playoff_season).html() + '</li>';
            if (d.tables_created > 0) html += '<li>Standings tables generated: ' + d.tables_created + '</li>';
            if (d.tables_created === -1) html += '<li><strong>Standings not generated:</strong> enable the League Table Generator module.</li>';
            if (d.teams_skipped > 0) html += '<li><strong>Teams skipped:</strong> ' + d.teams_skipped + ' (no longer published)</li>';
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

        // team_ids lives in baseParams because archiveLoop() re-posts these
        // verbatim once per 500-event chunk; reading the checkboxes fresh each
        // time would break if the operator touched the list mid-run.
        var baseParams = {
            league: $('#spem-rollover-league').val(),
            season_name: $('#spem-rollover-season').val(),
            team_ids: selectedTeamIds(),
            create_calendars: $('#spem-rollover-calendars').is(':checked') ? 1 : 0,
            create_rosters: $('#spem-rollover-rosters').is(':checked') ? 1 : 0,
            archive_old: $('#spem-rollover-archive').is(':checked') ? 1 : 0,
            create_playoffs: $('#spem-rollover-playoffs').is(':checked') ? 1 : 0,
            create_tables: $('#spem-rollover-tables').is(':checked') ? 1 : 0
        };

        if (!baseParams.team_ids.length) {
            \$result.html('<div class=\"notice notice-error\"><p>Select at least one team for the new season.</p></div>');
            \$btn.prop('disabled', false);
            return;
        }

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
					<th scope="row"><label for="spem-rollover-source"><?php esc_html_e( 'Carry Teams From', 'sportspress-events-manager' ); ?></label></th>
					<td>
						<select id="spem-rollover-source">
							<option value=""><?php esc_html_e( '— Start with no teams —', 'sportspress-events-manager' ); ?></option>
							<?php
							$season_terms = get_terms(
								array(
									'taxonomy'   => 'sp_season',
									'hide_empty' => false,
									'orderby'    => 'term_id',
									'order'      => 'DESC',
								)
							);
							$current_season = absint( get_option( 'spem_current_season_id', 0 ) );
							if ( ! empty( $season_terms ) && ! is_wp_error( $season_terms ) ) :
								foreach ( $season_terms as $season_term ) :
									?>
									<option value="<?php echo esc_attr( $season_term->term_id ); ?>" <?php selected( $season_term->term_id, $current_season ); ?>>
										<?php echo esc_html( $season_term->name ); ?>
									</option>
									<?php
								endforeach;
							endif;
							?>
						</select>
						<p class="description"><?php esc_html_e( 'Its teams are pre-selected below. Untick any that are not returning, then add newcomers.', 'sportspress-events-manager' ); ?></p>
					</td>
				</tr>
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
					<tr>
						<th scope="row"><?php esc_html_e( 'Create Playoff Season', 'sportspress-events-manager' ); ?></th>
						<td><label><input type="checkbox" id="spem-rollover-playoffs" /> <?php esc_html_e( 'Also create a "… Playoffs" season with the same teams', 'sportspress-events-manager' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Generate Standings', 'sportspress-events-manager' ); ?></th>
						<td><label><input type="checkbox" id="spem-rollover-tables" /> <?php esc_html_e( 'Generate a league table for each season created', 'sportspress-events-manager' ); ?></label></td>
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

		$source_season = isset( $_POST['source_season'] ) ? absint( $_POST['source_season'] ) : 0;
		$season_name   = isset( $_POST['season_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) ) : '';

		if ( empty( $season_name ) ) {
			wp_send_json_error( __( 'A new season name is required.', 'sportspress-events-manager' ) );
		}

		if ( ! $this->is_valid_season_name( $season_name ) ) {
			wp_send_json_error( $this->season_name_error_message() );
		}

		// A source season is optional: an operator standing up a brand-new
		// league has nothing to carry forward and builds the list from the pool.
		$carry_over = array();
		if ( $source_season ) {
			$term = get_term( $source_season, 'sp_season' );
			if ( ! $term || is_wp_error( $term ) ) {
				wp_send_json_error( __( 'Invalid source season.', 'sportspress-events-manager' ) );
			}

			foreach ( $this->get_season_teams( $source_season ) as $team ) {
				$carry_over[] = array(
					'id'   => (int) $team->ID,
					'name' => $team->post_title,
				);
			}
		}

		wp_send_json_success(
			array(
				'season_name'   => $season_name,
				'source_season' => $source_season,
				'carry_over'    => $carry_over,
				'pool'          => $this->get_team_pool(),
			)
		);
	}

	/**
	 * AJAX handler: execute the season rollover.
	 *
	 * SPEM_Rollover_Teams is a stateless pure helper with no dependencies —
	 * static access is what lets the standalone test harness exercise it with no
	 * WordPress bootstrap. Injecting an instance purely to satisfy the linter
	 * would buy nothing and cost testability.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function ajax_execute() {
		check_ajax_referer( 'spem_season_rollover' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'sportspress-events-manager' ) );
		}

		$league_id   = isset( $_POST['league'] ) ? absint( $_POST['league'] ) : 0;
		$season_name = isset( $_POST['season_name'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['season_name'] ) ) ) : '';

		// Never trust the posted list: intersect it against published sp_team
		// posts server-side. sanitize_ids() casts every element to int and drops
		// anything not in the whitelist.
		$raw_team_ids = isset( $_POST['team_ids'] ) ? wp_unslash( $_POST['team_ids'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized by sanitize_ids() on the next line.
		$team_ids     = SPEM_Rollover_Teams::sanitize_ids( $raw_team_ids, $this->get_valid_team_ids() );

		$options = array(
			'create_calendars' => ! empty( $_POST['create_calendars'] ),
			'create_rosters'   => ! empty( $_POST['create_rosters'] ),
			'archive_old'      => ! empty( $_POST['archive_old'] ),
			'create_playoffs'  => ! empty( $_POST['create_playoffs'] ),
			'create_tables'    => ! empty( $_POST['create_tables'] ),
		);

		if ( ! $league_id || empty( $season_name ) ) {
			wp_send_json_error( __( 'League and season name are required.', 'sportspress-events-manager' ) );
		}

		if ( ! $this->is_valid_season_name( $season_name ) ) {
			wp_send_json_error( $this->season_name_error_message() );
		}

		if ( empty( $team_ids ) ) {
			wp_send_json_error( __( 'Select at least one team for the new season.', 'sportspress-events-manager' ) );
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
				function () use ( $league_id, $season_name, $team_ids, $options ) {
					return $this->run_rollover( $league_id, $season_name, $team_ids, $options );
				}
			);

			// run_rollover() returns an array or a WP_Error, never false, so a
			// literal false is SPAT_Lock::with()'s "already held" signal.
			if ( false === $result ) {
				wp_send_json_error( __( 'A season rollover is already running for this league. Please wait for it to finish.', 'sportspress-events-manager' ) );
			}
		} else {
			$result = $this->run_rollover( $league_id, $season_name, $team_ids, $options );
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
	 * The caller supplies the team list. Team membership used to be derived from
	 * the league term, but sp_league accumulates over a team's whole history —
	 * Division 4 carries 55 teams of which 13 played S2026 — so derivation
	 * assigned roughly four times too many teams to every new season.
	 *
	 * @param int    $league_id   League term ID (scopes archiving and tagging).
	 * @param string $season_name Validated new season name.
	 * @param int[]  $team_ids    Validated published sp_team IDs.
	 * @param array  $options     create_calendars, create_rosters, archive_old,
	 *                            create_playoffs, create_tables.
	 * @return array|WP_Error Response payload for the wizard, or an error.
	 */
	public function run_rollover( $league_id, $season_name, $team_ids, $options ) {
		$archive_old     = ! empty( $options['archive_old'] );
		$create_playoffs = ! empty( $options['create_playoffs'] );
		$create_tables   = ! empty( $options['create_tables'] );

		// 1. Find or create the new season term
		$season_term_id = $this->resolve_season_term( $season_name );
		if ( is_wp_error( $season_term_id ) ) {
			return $season_term_id;
		}

		// 2. Resolve the selected teams
		$teams = $this->resolve_selected_teams( $team_ids );
		if ( is_wp_error( $teams ) ) {
			return $teams;
		}

		// A selected team deleted or unpublished between preview and execute is
		// an ordinary race; report the count rather than aborting the rollover.
		$teams_skipped = count( array_map( 'intval', (array) $team_ids ) ) - count( $teams );

		// 3/4. Assign the season and optionally scaffold calendars and rosters.
		$counts          = $this->assign_teams_to_season( $teams, $season_name, $season_term_id, $league_id, $options );
		$events_archived = 0;

		// 5. Optionally archive old season events. Capped per call — the UI
		// re-invokes the archive step until `archive_done` returns true.
		$archive_done = true;
		if ( $archive_old ) {
			$archive_result  = $this->archive_old_events( $league_id, $season_term_id );
			$events_archived = $archive_result['count'];
			$archive_done    = $archive_result['done'];
		}

		// 6. Optionally create the playoff season as a child term.
		//
		// Hierarchy matches existing practice — every playoff term in the live
		// data is already a child of its season. The naming is what the standings
		// widget actually keys on: it detects playoffs by "Playoff" in the NAME
		// and pairs them to a season by stripping /-?playoffs?$/i from the SLUG.
		// Deriving both from the season name keeps that pairing intact.
		$playoff_season_id = 0;
		$playoff_name      = '';
		if ( $create_playoffs ) {
			$playoff = $this->resolve_playoff_season( $season_name, $season_term_id, $teams );

			$playoff_season_id = $playoff['id'];
			$playoff_name      = $playoff['name'];
		}

		// 7. Optionally generate standings tables.
		$tables_created = 0;
		if ( $create_tables ) {
			$tables_created = $this->generate_standings_tables(
				$league_id,
				array_filter( array( $season_term_id, $playoff_season_id ) )
			);
		}

		// 8. Update the default season for the dynamic standings shortcode.
		update_option( 'spem_current_season_id', $season_term_id );

		return array(
			'season_name'       => $season_name,
			'playoff_season'    => $playoff_name,
			'teams_updated'     => $counts['teams_updated'],
			'teams_skipped'     => $teams_skipped,
			'calendars_created' => $counts['calendars_created'],
			'rosters_created'   => $counts['rosters_created'],
			'tables_created'    => $tables_created,
			'events_archived'   => $events_archived,
			'archive_done'      => $archive_done,
		);
	}

	/**
	 * Find an existing season term by name, or create it.
	 *
	 * @param string $season_name Validated season name.
	 * @return int|WP_Error Term ID, or the insert error.
	 */
	private function resolve_season_term( $season_name ) {
		$existing = get_term_by( 'name', $season_name, 'sp_season' );
		if ( $existing ) {
			return (int) $existing->term_id;
		}

		$result = wp_insert_term( $season_name, 'sp_season' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (int) $result['term_id'];
	}

	/**
	 * Turn a validated ID list into published team posts, preserving order.
	 *
	 * The IDs have already been intersected against published sp_team posts by
	 * SPEM_Rollover_Teams::sanitize_ids(); this re-queries so the executor works
	 * on live post objects rather than trusting the request.
	 *
	 * @param int[] $team_ids Validated team IDs.
	 * @return WP_Post[]|WP_Error Team posts, or an error when nothing was selected.
	 */
	private function resolve_selected_teams( $team_ids ) {
		$team_ids = array_map( 'intval', (array) $team_ids );

		if ( empty( $team_ids ) ) {
			return new WP_Error( 'no_teams', __( 'No teams were selected for the new season.', 'sportspress-events-manager' ) );
		}

		return get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'post__in'       => $team_ids,
				'orderby'        => 'post__in',
			)
		);
	}

	/**
	 * Assign the new season to each team and scaffold their optional records.
	 *
	 * The season is appended rather than replaced, so a team accumulates its
	 * season history exactly as before.
	 *
	 * @param WP_Post[] $teams          Selected team posts.
	 * @param string    $season_name    New season name.
	 * @param int       $season_term_id New season term ID.
	 * @param int       $league_id      League term ID.
	 * @param array     $options        create_calendars, create_rosters.
	 * @return array{teams_updated:int, calendars_created:int, rosters_created:int}
	 */
	private function assign_teams_to_season( $teams, $season_name, $season_term_id, $league_id, $options ) {
		$create_calendars = ! empty( $options['create_calendars'] );
		$create_rosters   = ! empty( $options['create_rosters'] );

		$counts = array(
			'teams_updated'     => 0,
			'calendars_created' => 0,
			'rosters_created'   => 0,
		);

		foreach ( $teams as $team ) {
			wp_set_object_terms( $team->ID, $season_term_id, 'sp_season', true );
			$counts['teams_updated']++;

			if ( $create_calendars && $this->maybe_create_calendar( $team, $season_name, $season_term_id, $league_id ) ) {
				$counts['calendars_created']++;
			}

			if ( $create_rosters && $this->maybe_create_roster( $team, $season_name, $season_term_id, $league_id ) ) {
				$counts['rosters_created']++;
			}
		}

		return $counts;
	}

	/**
	 * Create a team's calendar for a season unless one already exists.
	 *
	 * Idempotency matters because the wizard re-POSTs the whole execute action
	 * once per 500-event archive chunk.
	 *
	 * @param WP_Post $team           Team post.
	 * @param string  $season_name    New season name (used in the title).
	 * @param int     $season_term_id New season term ID.
	 * @param int     $league_id      League term ID.
	 * @return bool True when a calendar was created.
	 */
	private function maybe_create_calendar( $team, $season_name, $season_term_id, $league_id ) {
		// Avoid serialize() in meta_query — narrow with a LIKE on the serialized
		// fragment, then do a PHP-side team check to defend against false
		// positives.
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

		if ( ! empty( $season_cal_ids ) ) {
			update_meta_cache( 'post', $season_cal_ids );
			foreach ( $season_cal_ids as $cal_id ) {
				$cal_teams = (array) get_post_meta( $cal_id, 'sp_team', true );
				if ( in_array( (int) $team->ID, array_map( 'intval', $cal_teams ), true ) ) {
					return false;
				}
			}
		}

		$cal_id = wp_insert_post(
			array(
				'post_type'   => 'sp_calendar',
				'post_title'  => $team->post_title . ' — ' . $season_name,
				'post_status' => 'publish',
			)
		);

		if ( ! $cal_id || is_wp_error( $cal_id ) ) {
			return false;
		}

		update_post_meta( $cal_id, 'sp_team', array( $team->ID ) );
		wp_set_object_terms( $cal_id, array( $season_term_id ), 'sp_season' );
		wp_set_object_terms( $cal_id, array( $league_id ), 'sp_league' );
		update_post_meta( $cal_id, 'sp_format', get_option( 'spem_calendar_type', 'list' ) );

		return true;
	}

	/**
	 * Create a team's empty roster for a season unless one already exists.
	 *
	 * Note that sp_list stores sp_team as a scalar post ID, so a direct meta
	 * value match is exact — no serialized LIKE needed, unlike the calendar path.
	 *
	 * @param WP_Post $team           Team post.
	 * @param string  $season_name    New season name (used in the title).
	 * @param int     $season_term_id New season term ID.
	 * @param int     $league_id      League term ID.
	 * @return bool True when a roster was created.
	 */
	private function maybe_create_roster( $team, $season_name, $season_term_id, $league_id ) {
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

		if ( ! empty( $existing_list_ids ) ) {
			return false;
		}

		$list_id = wp_insert_post(
			array(
				'post_type'   => 'sp_list',
				'post_title'  => $team->post_title . ' — ' . $season_name . ' Roster',
				'post_status' => 'publish',
			)
		);

		if ( ! $list_id || is_wp_error( $list_id ) ) {
			return false;
		}

		update_post_meta( $list_id, 'sp_team', $team->ID );
		wp_set_object_terms( $list_id, array( $season_term_id ), 'sp_season' );
		wp_set_object_terms( $list_id, array( $league_id ), 'sp_league' );

		return true;
	}

	/**
	 * Find or create the playoff season term for a season.
	 *
	 * Created as a hierarchical child, matching how every playoff term in the
	 * live data is already modelled. Name and slug both come from the season
	 * name so SPEM_Dynamic_Standings can pair them: it detects playoffs by
	 * "Playoff" in the name and strips /-?playoffs?$/i from the slug.
	 *
	 * SPEM_Rollover_Teams is a stateless pure helper with no dependencies —
	 * static access is what lets the standalone test harness exercise it with no
	 * WordPress bootstrap. Injecting an instance purely to satisfy the linter
	 * would buy nothing and cost testability.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string    $season_name    Regular season name.
	 * @param int       $season_term_id Regular season term ID (becomes the parent).
	 * @param WP_Post[] $teams          Teams to assign to the playoff season.
	 * @return array{id:int, name:string} Term ID (0 on failure) and playoff name.
	 */
	private function resolve_playoff_season( $season_name, $season_term_id, $teams ) {
		$playoff_name     = SPEM_Rollover_Teams::playoff_name( $season_name );
		$existing_playoff = get_term_by( 'name', $playoff_name, 'sp_season' );

		if ( $existing_playoff ) {
			$playoff_id = (int) $existing_playoff->term_id;
		} else {
			$playoff_result = wp_insert_term(
				$playoff_name,
				'sp_season',
				array(
					'slug'   => SPEM_Rollover_Teams::playoff_slug( $season_name ),
					'parent' => $season_term_id,
				)
			);

			$playoff_id = is_wp_error( $playoff_result ) ? 0 : (int) $playoff_result['term_id'];
		}

		// Playoff rosters mirror the regular season exactly (S2026 and
		// S2026 Playoffs both carry 22 teams), so assign the same set.
		if ( $playoff_id ) {
			foreach ( $teams as $team ) {
				wp_set_object_terms( $team->ID, $playoff_id, 'sp_season', true );
			}
		}

		return array(
			'id'   => $playoff_id,
			'name' => $playoff_name,
		);
	}

	/**
	 * Generate a standings table per season term.
	 *
	 * The generator is idempotent per league+season, which matters because the
	 * archive loop re-runs the whole rollover once per 500-event chunk.
	 *
	 * @param int   $league_id  League term ID.
	 * @param int[] $season_ids Season term IDs to generate tables for.
	 * @return int Number created, or -1 when the generator module is disabled.
	 */
	private function generate_standings_tables( $league_id, $season_ids ) {
		// Module disabled. Report it rather than fataling — the rest of the
		// rollover is still valid work.
		if ( ! class_exists( 'SPEM_League_Table_Generator' ) ) {
			return -1;
		}

		$generator = new SPEM_League_Table_Generator();
		$created   = 0;

		foreach ( $season_ids as $season_id ) {
			$table = $generator->generate( $league_id, $season_id );
			if ( ! is_wp_error( $table ) && ! empty( $table['created'] ) ) {
				$created++;
			}
		}

		return $created;
	}

	/**
	 * Published teams assigned to a season term.
	 *
	 * The include_children flag is off deliberately: sp_season is hierarchical
	 * and playoff terms are real children of their season (S2026 Playoffs has
	 * parent S2026), so the default would fold a season's playoff roster into
	 * its regular-season roster.
	 *
	 * @param int $season_id sp_season term ID.
	 * @return WP_Post[]
	 */
	private function get_season_teams( $season_id ) {
		return get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => (int) $season_id,
						'include_children' => false,
					),
				),
			)
		);
	}

	/**
	 * Every published team, with the most recent season it played.
	 *
	 * Feeds the "add a team not in the source season" picker. 143 teams on the
	 * production install, so a single unpaginated pass is fine; the last-season
	 * label is what makes a 143-row list usable.
	 *
	 * @return array List of array{id:int, name:string, last_season:string}.
	 */
	private function get_team_pool() {
		$teams = get_posts(
			array(
				'post_type'      => 'sp_team',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$pool = array();

		foreach ( $teams as $team ) {
			// Highest term_id wins as "most recent": season terms are created in
			// chronological order, and there is no date field on a term.
			$seasons     = wp_get_object_terms( $team->ID, 'sp_season' );
			$last_season = '';

			if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
				usort(
					$seasons,
					static function ( $a, $b ) {
						return $b->term_id <=> $a->term_id;
					}
				);
				$last_season = $seasons[0]->name;
			}

			$pool[] = array(
				'id'          => (int) $team->ID,
				'name'        => $team->post_title,
				'last_season' => $last_season,
			);
		}

		return $pool;
	}

	/**
	 * IDs of all published teams — the whitelist for posted selections.
	 *
	 * @return int[]
	 */
	private function get_valid_team_ids() {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => 'sp_team',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'fields'         => 'ids',
				)
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
