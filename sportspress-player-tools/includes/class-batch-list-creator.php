<?php
/**
 * Batch Player List Creator
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Batch_List_Creator {

	const PER_PAGE      = 50;
	const MAX_UPLOAD    = 5242880; // 5 MB
	const MAX_ROWS      = 5000;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_tools_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'all_admin_notices', array( $this, 'add_upload_button' ) );
		add_action( 'admin_post_spt_upload_list_csv', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_spt_process_list_batch', array( $this, 'process_batch' ) );
		add_action( 'admin_post_spt_mark_page_reviewed', array( $this, 'mark_page_reviewed' ) );
		add_action( 'admin_notices', array( $this, 'success_notice' ) );
		add_action( 'wp_ajax_spt_search_teams', array( $this, 'ajax_search_teams' ) );
		add_action( 'wp_ajax_spt_search_players', array( $this, 'ajax_search_players' ) );
		add_action( 'spt_cleanup_old_temp_data', array( $this, 'cleanup_old_temp_data' ) );
		if ( ! wp_next_scheduled( 'spt_cleanup_old_temp_data' ) ) {
			wp_schedule_event( time(), 'daily', 'spt_cleanup_old_temp_data' );
		}
	}

	public function add_tools_page() {
		add_management_page(
			__( 'Upload Player Lists', 'sportspress-player-tools' ),
			__( 'Upload Player Lists', 'sportspress-player-tools' ),
			'manage_options',
			'spt_upload_lists',
			array( $this, 'tools_page' )
		);
	}

	public function add_upload_button() {
		$screen = get_current_screen();
		if ( ! $screen || $screen->post_type !== 'sp_list' || $screen->base !== 'edit' ) {
			return;
		}

		$url = esc_url( admin_url( 'tools.php?page=spt_upload_lists' ) );
		$label = esc_html__( 'Upload Player Lists', 'sportspress-player-tools' );
		?>
		<script>
		jQuery(document).ready(function($) {
			var link = '<a href="<?php echo $url; ?>" class="page-title-action"><?php echo $label; ?></a>';
			$('.wrap .page-title-action').first().after(link);
		});
		</script>
		<?php
	}

	public function success_notice() {
		if ( ! isset( $_GET['spt_batch_created'] ) || sanitize_text_field( wp_unslash( $_GET['spt_batch_created'] ) ) !== '1' ) {
			return;
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Player lists created successfully.', 'sportspress-player-tools' ) . '</p></div>';

		// PT3/F5: pull the one-shot locked-teams transient set by process_batch() and
		// turn it into a warning notice so admins know which rosters they need to
		// rerun after the conflicting editor finishes.
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$locked = get_transient( 'spt_batch_locked_teams_' . $user_id );
		if ( empty( $locked ) || ! is_array( $locked ) ) {
			return;
		}
		delete_transient( 'spt_batch_locked_teams_' . $user_id );

		$count = count( $locked );
		$names = implode( ', ', array_map( 'sanitize_text_field', $locked ) );
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: %1$d team count, %2$s comma-separated team names */
				_n(
					'Skipped %1$d team that was being edited by another admin: %2$s',
					'Skipped %1$d teams that were being edited by other admins: %2$s',
					$count,
					'sportspress-player-tools'
				),
				$count,
				$names
			) )
		);
	}

	public function enqueue_scripts( $hook ) {
		$screen = get_current_screen();

		// Use parent plugin's bundled Slim Select
		$slimselect_js = plugins_url( 'sportspress-admin-tools/assets/lib/slimselect/slimselect.min.js' );
		$slimselect_css = plugins_url( 'sportspress-admin-tools/assets/lib/slimselect/slimselect.min.css' );

		// Load on sp_list edit page
		if ( $hook === 'edit.php' && $screen && $screen->post_type === 'sp_list' ) {
			if ( get_option( 'spat_use_select2', '0' ) === '1' ) {
				wp_enqueue_script( 'slimselect', $slimselect_js, array(), '3.4.3', true );
				wp_enqueue_style( 'slimselect', $slimselect_css, array(), '3.4.3' );
			}
		}

		// Load on tools page
		if ( $hook === 'tools_page_spt_upload_lists' ) {
			wp_enqueue_script( 'slimselect', $slimselect_js, array(), '3.4.3', true );
			wp_enqueue_style( 'slimselect', $slimselect_css, array(), '3.4.3' );
		}
	}



	public function tools_page() {
		// Show preview if data exists
		if ( isset( $_GET['preview'] ) && sanitize_text_field( $_GET['preview'] ) === '1' ) {
			$this->show_preview();
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Upload Player Lists', 'sportspress-player-tools' ); ?></h1>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="spt-upload-form">
				<input type="hidden" name="action" value="spt_upload_list_csv">
				<?php wp_nonce_field( 'spt_batch_list_upload', 'spt_batch_list_nonce' ); ?>

				<div id="spt-drop-zone" style="border: 2px dashed #ccc; padding: 40px; text-align: center; margin: 20px 0; background: #fafafa;">
					<p style="font-size: 16px; margin-bottom: 10px;"><?php esc_html_e( 'Drag and drop CSV file here', 'sportspress-player-tools' ); ?></p>
					<p style="margin-bottom: 20px;"><?php esc_html_e( 'or', 'sportspress-player-tools' ); ?></p>
					<input type="file" name="csv_file" id="csv_file" accept=".csv" required style="display:none;">
					<button type="button" class="button button-primary" onclick="document.getElementById('csv_file').click();"><?php esc_html_e( 'Select CSV File', 'sportspress-player-tools' ); ?></button>
					<p id="file-name" style="margin-top: 15px; font-weight: bold;"></p>
				</div>

				<p class="submit">
					<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Upload & Preview', 'sportspress-player-tools' ); ?>" id="submit-btn" disabled>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sp_list' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sportspress-player-tools' ); ?></a>
				</p>
			</form>
		</div>

		<script>
		jQuery(document).ready(function($) {
			var dropZone = $('#spt-drop-zone');
			var fileInput = $('#csv_file');
			var fileName = $('#file-name');
			var submitBtn = $('#submit-btn');

			dropZone.on('dragover', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css('background', '#e8f5e9');
			});

			dropZone.on('dragleave', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css('background', '#fafafa');
			});

			dropZone.on('drop', function(e) {
				e.preventDefault();
				e.stopPropagation();
				$(this).css('background', '#fafafa');

				var files = e.originalEvent.dataTransfer.files;
				if (files.length > 0) {
					fileInput[0].files = files;
					fileName.text(files[0].name);
					submitBtn.prop('disabled', false);
				}
			});

			fileInput.on('change', function() {
				if (this.files.length > 0) {
					fileName.text(this.files[0].name);
					submitBtn.prop('disabled', false);
				}
			});
		});
		</script>
		<?php
	}

	public function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['spt_batch_list_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spt_batch_list_nonce'] ) ), 'spt_batch_list_upload' ) ) {
			wp_die( __( 'Invalid request', 'sportspress-player-tools' ) );
		}

		if ( ! isset( $_FILES['csv_file'] ) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK ) {
			wp_die( __( 'File upload failed', 'sportspress-player-tools' ) );
		}

		// Fix #3: enforce 5MB cap before reading.
		if ( ! isset( $_FILES['csv_file']['size'] ) || (int) $_FILES['csv_file']['size'] > self::MAX_UPLOAD ) {
			wp_die( esc_html__( 'File is too large. Maximum size is 5 MB.', 'sportspress-player-tools' ) );
		}

		// Validate file type
		$file_ext = strtolower( pathinfo( $_FILES['csv_file']['name'], PATHINFO_EXTENSION ) );
		if ( $file_ext !== 'csv' ) {
			wp_die( __( 'Please upload a CSV file', 'sportspress-player-tools' ) );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime_type = finfo_file( $finfo, $_FILES['csv_file']['tmp_name'] );
		finfo_close( $finfo );

		$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel' );
		if ( ! in_array( $mime_type, $allowed_mimes ) ) {
			wp_die( __( 'Invalid file type. Please upload a CSV file.', 'sportspress-player-tools' ) );
		}

		// Fix #3: stream the CSV with fgetcsv instead of file() loading the whole thing.
		$fh = fopen( $_FILES['csv_file']['tmp_name'], 'r' );
		if ( ! $fh ) {
			wp_die( __( 'Unable to read uploaded file.', 'sportspress-player-tools' ) );
		}

		$header = fgetcsv( $fh );
		if ( ! is_array( $header ) ) {
			fclose( $fh );
			wp_die( __( 'CSV is empty or unreadable.', 'sportspress-player-tools' ) );
		}
		$header = array_map( 'strtolower', array_map( 'trim', $header ) );

		$team_col = array_search( 'team', $header, true );
		$name_col = array_search( 'name', $header, true );

		if ( $team_col === false || $name_col === false ) {
			fclose( $fh );
			wp_die( __( 'CSV must have Team and Name columns', 'sportspress-player-tools' ) );
		}

		$data = array();
		while ( ( $row = fgetcsv( $fh ) ) !== false ) {
			if ( count( $data ) >= self::MAX_ROWS ) {
				break;
			}
			if ( ! isset( $row[ $team_col ] ) || ! isset( $row[ $name_col ] ) ) {
				continue;
			}

			$team = trim( (string) $row[ $team_col ] );
			$name = trim( (string) $row[ $name_col ] );
			if ( '' === $team || '' === $name ) {
				continue;
			}

			// Remove (C), (G), (A) or any single letter prefix
			$name = preg_replace( '/^\([A-Z]\)\s*/i', '', $name );
			// Remove numbers in parentheses at end
			$name = preg_replace( '/\s*\(\d+\)\s*$/', '', $name );
			$name = trim( $name );

			if ( '' === $name ) {
				continue;
			}

			$data[] = array(
				'team' => $team,
				'name' => $name,
			);
		}
		fclose( $fh );

		if ( empty( $data ) ) {
			wp_die( __( 'No valid data found in CSV. Please check the file format.', 'sportspress-player-tools' ) );
		}

		// Fix #11: precompute auto-match defaults once at upload time using cached lookups.
		$team_objects   = $this->get_team_objects();
		$player_objects = $this->get_player_objects();

		$matches = array();
		foreach ( $data as $idx => $row ) {
			$team_amb   = false;
			$player_amb = false;
			$matches[ $idx ] = array(
				'team'             => $this->find_closest( $row['team'], $team_objects, $team_amb ),
				'player'           => $this->find_closest( $row['name'], $player_objects, $player_amb ),
				'team_ambiguous'   => $team_amb,
				'player_ambiguous' => $player_amb,
			);
		}

		// Fix #1: track reviewed pages alongside CSV data inside spat_temp_data.
		$payload = array(
			'rows'            => $data,
			'matches'         => $matches,
			'reviewed_pages'  => array(),
			'overrides'       => array(),
		);

		// Store in SPAT database table.
		// PT2/F5: use REPLACE INTO so the (user_id, data_type) row is updated atomically.
		// Paired with the UNIQUE KEY user_data (user_id, data_type) on spat_temp_data
		// defined in sportspress-admin-tools/includes/class-database.php (spat_db_version 1.0.2).
		global $wpdb;
		$table   = $wpdb->prefix . 'spat_temp_data';
		$user_id = get_current_user_id();

		$json_data = wp_json_encode( $payload );

		$result = $wpdb->query(
			$wpdb->prepare(
				"REPLACE INTO {$wpdb->prefix}spat_temp_data (user_id, data_type, data_value, created_at) VALUES (%d, %s, %s, %s)",
				$user_id,
				'batch_list',
				$json_data,
				current_time( 'mysql' )
			)
		);

		if ( $result === false && get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
			error_log( 'SPT: Failed to insert batch list data - ' . $wpdb->last_error );
		}
		wp_safe_redirect( admin_url( 'tools.php?page=spt_upload_lists&preview=1' ) );
		exit;
	}

	/**
	 * Cached fetch of all sp_team posts.
	 * Fix #11: avoid repeated -1 queries.
	 */
	private function get_team_objects() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$cache = get_posts(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return $cache;
	}

	/**
	 * Cached fetch of all sp_player posts.
	 */
	private function get_player_objects() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$cache = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		return $cache;
	}

	/**
	 * Load the stored batch payload for the current user.
	 *
	 * @return array|null
	 */
	private function get_payload() {
		global $wpdb;
		$table   = $wpdb->prefix . 'spat_temp_data';
		$user_id = get_current_user_id();

		$stored = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT data_value FROM $table WHERE user_id = %d AND data_type = %s",
				$user_id,
				'batch_list'
			)
		);
		if ( ! $stored ) {
			return null;
		}
		$decoded = json_decode( $stored, true );
		if ( ! is_array( $decoded ) ) {
			return null;
		}
		// Backwards compatibility: previous schema stored bare array of rows.
		if ( isset( $decoded[0]['team'] ) && ! isset( $decoded['rows'] ) ) {
			return array(
				'rows'           => $decoded,
				'matches'        => array(),
				'reviewed_pages' => array(),
				'overrides'      => array(),
			);
		}
		$decoded += array(
			'rows'           => array(),
			'matches'        => array(),
			'reviewed_pages' => array(),
			'overrides'      => array(),
		);
		return $decoded;
	}

	/**
	 * Persist payload changes back to the temp table.
	 *
	 * @param array $payload
	 */
	private function save_payload( $payload ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'spat_temp_data';
		$user_id = get_current_user_id();

		$wpdb->update(
			$table,
			array( 'data_value' => wp_json_encode( $payload ) ),
			array(
				'user_id'   => $user_id,
				'data_type' => 'batch_list',
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Fix #1: AJAX-friendly admin-post that records a page number as reviewed
	 * along with any per-row team/player overrides selected on that page.
	 */
	public function mark_page_reviewed() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied', 'sportspress-player-tools' ) ), 403 );
		}
		check_ajax_referer( 'spt_batch_process', '_wpnonce' );

		$page = isset( $_POST['page'] ) ? max( 1, absint( $_POST['page'] ) ) : 0;
		if ( ! $page ) {
			wp_send_json_error( array( 'message' => __( 'Missing page', 'sportspress-player-tools' ) ), 400 );
		}

		$payload = $this->get_payload();
		if ( null === $payload ) {
			wp_send_json_error( array( 'message' => __( 'No batch data', 'sportspress-player-tools' ) ), 404 );
		}

		// Bound check: $page must be a real page index for the stored CSV.
		$row_count   = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? count( $payload['rows'] ) : 0;
		$total_pages = (int) ceil( $row_count / self::PER_PAGE );
		if ( $page < 1 || $page > $total_pages ) {
			wp_send_json_error( array( 'message' => __( 'Page out of range', 'sportspress-player-tools' ) ), 400 );
		}

		// Compute the row-index range covered by this page so the override fields
		// we record can be verified to belong here. The page-reviewed flag is only
		// recorded when at least one override for an in-range row index is supplied
		// — preventing scripted replays that POST page=N with no body from satisfying
		// the all_reviewed gate.
		$page_start = ( $page - 1 ) * self::PER_PAGE;
		$page_end   = min( $row_count - 1, $page_start + self::PER_PAGE - 1 );

		if ( ! isset( $payload['overrides'] ) || ! is_array( $payload['overrides'] ) ) {
			$payload['overrides'] = array();
		}

		$in_range_overrides = 0;
		foreach ( $_POST as $key => $value ) {
			$idx = null;
			$kind = null;
			if ( strpos( $key, 'team_' ) === 0 ) {
				$idx  = (int) substr( $key, 5 );
				$kind = 'team';
			} elseif ( strpos( $key, 'player_' ) === 0 ) {
				$idx  = (int) substr( $key, 7 );
				$kind = 'player';
			}
			if ( null === $kind || $idx < $page_start || $idx > $page_end ) {
				continue;
			}
			$payload['overrides'][ $idx ][ $kind ] = absint( $value );
			$in_range_overrides++;
		}

		if ( 0 === $in_range_overrides ) {
			wp_send_json_error(
				array(
					'message' => __( 'No in-range row data supplied for this page.', 'sportspress-player-tools' ),
				),
				400
			);
		}

		$reviewed = isset( $payload['reviewed_pages'] ) && is_array( $payload['reviewed_pages'] )
			? array_map( 'intval', $payload['reviewed_pages'] )
			: array();
		if ( ! in_array( $page, $reviewed, true ) ) {
			$reviewed[]                = $page;
			$payload['reviewed_pages'] = $reviewed;
		}

		$this->save_payload( $payload );

		wp_send_json_success(
			array(
				'reviewed_pages' => $reviewed,
				'total_pages'    => $total_pages,
				'all_reviewed'   => count( $reviewed ) >= $total_pages,
			)
		);
	}

	public function process_batch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( __( 'Permission denied', 'sportspress-player-tools' ) );
		}

		check_admin_referer( 'spt_batch_process' );

		$payload = $this->get_payload();
		if ( null === $payload || empty( $payload['rows'] ) ) {
			wp_die( __( 'No stored batch data found. Please re-upload the CSV.', 'sportspress-player-tools' ) );
		}

		$full_data = $payload['rows'];

		// Fix #7: defensive isset on every $_POST read.
		$list_name = isset( $_POST['list_name'] ) ? sanitize_text_field( wp_unslash( $_POST['list_name'] ) ) : '';
		$season_id = isset( $_POST['season'] ) ? absint( $_POST['season'] ) : 0;
		if ( '' === $list_name || ! $season_id ) {
			wp_die( esc_html__( 'Missing required fields (list name, season).', 'sportspress-player-tools' ) );
		}

		$columns = isset( $_POST['columns'] ) && is_array( $_POST['columns'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['columns'] ) )
			: array( 'number', 'position' );
		$action  = isset( $_POST['list_action'] ) ? sanitize_text_field( wp_unslash( $_POST['list_action'] ) ) : 'create';

		// Validate action value
		if ( ! in_array( $action, array( 'create', 'update' ), true ) ) {
			$action = 'create';
		}

		// Fix #11: pull overrides from stored payload (collected via mark_page_reviewed),
		// then merge any current-page $_POST overrides on top.
		$overrides = isset( $payload['overrides'] ) && is_array( $payload['overrides'] ) ? $payload['overrides'] : array();
		foreach ( $_POST as $key => $value ) {
			if ( strpos( $key, 'team_' ) === 0 ) {
				$idx = (int) substr( $key, 5 );
				$overrides[ $idx ]['team'] = absint( $value );
			} elseif ( strpos( $key, 'player_' ) === 0 ) {
				$idx = (int) substr( $key, 7 );
				$overrides[ $idx ]['player'] = absint( $value );
			}
		}

		// Fix #1: server-side check that every page was reviewed.
		$total_pages    = (int) ceil( count( $full_data ) / self::PER_PAGE );
		$reviewed_pages = isset( $payload['reviewed_pages'] ) && is_array( $payload['reviewed_pages'] )
			? array_unique( array_map( 'intval', $payload['reviewed_pages'] ) )
			: array();
		// Treat the current submission page as reviewed for the page that was actually shown.
		$current_page = isset( $_POST['current_page'] ) ? max( 1, absint( $_POST['current_page'] ) ) : 0;
		if ( $current_page && ! in_array( $current_page, $reviewed_pages, true ) ) {
			$reviewed_pages[] = $current_page;
		}
		if ( count( $reviewed_pages ) < $total_pages ) {
			wp_die(
				esc_html(
					sprintf(
						/* translators: %1$d reviewed, %2$d total */
						__( 'Cannot submit: only %1$d of %2$d pages have been reviewed. Open each page before submitting.', 'sportspress-player-tools' ),
						count( $reviewed_pages ),
						$total_pages
					)
				)
			);
		}

		// Fix #11/#12: iterate stored row indices, not raw $_POST keys; merge cached
		// matches with explicit overrides.
		// PT2/F6: the cached payload may have been stored hours/days ago. Validate every
		// resolved team_id / player_id against the live post type before using it so a
		// stale or deleted post doesn't get silently treated as a roster member.
		$matches = isset( $payload['matches'] ) && is_array( $payload['matches'] ) ? $payload['matches'] : array();
		$teams   = array();
		$players = array();
		foreach ( $full_data as $idx => $row ) {
			$default_team   = isset( $matches[ $idx ]['team'] ) ? absint( $matches[ $idx ]['team'] ) : 0;
			$default_player = isset( $matches[ $idx ]['player'] ) ? absint( $matches[ $idx ]['player'] ) : 0;

			$resolved_team   = isset( $overrides[ $idx ]['team'] ) ? absint( $overrides[ $idx ]['team'] ) : $default_team;
			$resolved_player = isset( $overrides[ $idx ]['player'] ) ? absint( $overrides[ $idx ]['player'] ) : $default_player;

			// PT2/F6: discard cached IDs whose post type has changed or whose post was deleted.
			if ( $resolved_team && get_post_type( $resolved_team ) !== 'sp_team' ) {
				$resolved_team = 0;
			}
			if ( $resolved_player && get_post_type( $resolved_player ) !== 'sp_player' ) {
				$resolved_player = 0;
			}

			$teams[ $idx ]   = $resolved_team;
			$players[ $idx ] = $resolved_player;
		}

		if ( empty( $teams ) || empty( $players ) ) {
			wp_die( __( 'No team or player data received', 'sportspress-player-tools' ) );
		}

		// Get season and children
		$season_ids = array( $season_id );
		$child_seasons = get_terms(
			array(
				'taxonomy' => 'sp_season',
				'parent' => $season_id,
				'hide_empty' => false,
			)
		);
		if ( ! empty( $child_seasons ) && ! is_wp_error( $child_seasons ) ) {
			foreach ( $child_seasons as $child ) {
				$season_ids[] = $child->term_id;
			}
		}

		// Group by team
		$team_players = array();
		foreach ( $teams as $idx => $team_id ) {
			if ( ! $team_id ) {
				continue;
			}
			if ( ! isset( $team_players[ $team_id ] ) ) {
				$team_players[ $team_id ] = array();
			}
			if ( isset( $players[ $idx ] ) && $players[ $idx ] ) {
				$team_players[ $team_id ][] = $players[ $idx ];
			}
		}

		// Get season name
		$season_term = get_term( $season_id, 'sp_season' );
		$season_name = ( $season_term && ! is_wp_error( $season_term ) ) ? $season_term->name : '';

		// Clean up temp data
		global $wpdb;
		$table = $wpdb->prefix . 'spat_temp_data';
		$wpdb->delete(
			$table,
			array(
				'user_id' => get_current_user_id(),
				'data_type' => 'batch_list',
			)
		);

		// PT3/F5: collect team names whose list was locked by another editor so the
		// success notice can surface what got skipped instead of silently no-oping.
		$locked_teams      = array();
		$processed_count   = 0;

		// Process lists
		foreach ( $team_players as $team_id => $player_ids ) {
			$team_name = get_the_title( $team_id );
			$title = str_replace( array( '{team}', '{season}' ), array( $team_name, $season_name ), $list_name );

			if ( $action === 'update' ) {
				// Find existing list with matching team and season
				$existing = get_posts(
					array(
						'post_type' => 'sp_list',
						'posts_per_page' => 1,
						'tax_query' => array(
							'relation' => 'AND',
							array(
								'taxonomy' => 'sp_team',
								'field' => 'term_id',
								'terms' => $team_id,
							),
							array(
								'taxonomy' => 'sp_season',
								'field' => 'term_id',
								'terms' => $season_id,
							),
						),
					)
				);

				if ( ! empty( $existing ) ) {
					$list_id = $existing[0]->ID;

					// PT2/F11: skip lists that another admin is currently editing so we
					// don't blow away their unsaved players. wp_check_post_lock() returns
					// the locking user_id when the post is locked, false otherwise.
					if ( ! function_exists( 'wp_check_post_lock' ) ) {
						require_once ABSPATH . 'wp-admin/includes/post.php';
					}
					$lock_user = wp_check_post_lock( $list_id );
					if ( $lock_user ) {
						// PT3/F5: remember the team so the post-redirect notice can warn
						// the admin which rosters their batch left untouched. The silent
						// error_log path stays for verbose-debug operators.
						$locked_teams[] = $team_name;
						if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
							error_log( sprintf( 'SPT: skipping locked list %d (locked by user %d)', $list_id, (int) $lock_user ) );
						}
						continue;
					}

					// Remove all existing players
					delete_post_meta( $list_id, 'sp_player' );
				} else {
					// No existing list, create new
					$list_id = wp_insert_post(
						array(
							'post_type' => 'sp_list',
							'post_title' => $title,
							'post_status' => 'publish',
						)
					);
				}
			} else {
				// Create new list
				$list_id = wp_insert_post(
					array(
						'post_type' => 'sp_list',
						'post_title' => $title,
						'post_status' => 'publish',
					)
				);
			}

			if ( $list_id && ! is_wp_error( $list_id ) ) {
				wp_set_object_terms( $list_id, array( $team_id ), 'sp_team' );
				wp_set_object_terms( $list_id, $season_ids, 'sp_season' );

				foreach ( $player_ids as $player_id ) {
					add_post_meta( $list_id, 'sp_player', $player_id );
				}

				update_post_meta( $list_id, 'sp_columns', $columns );
				update_post_meta( $list_id, 'sp_format', 'list' );
				update_post_meta( $list_id, 'sp_orderby', 'number' );
				update_post_meta( $list_id, 'sp_order', 'ASC' );

				// PT2/F7: sp_list is a multi-value meta on sp_team. update_post_meta()
				// without a fourth arg replaces ALL rows, wiping every other list this
				// team owns. Use add_post_meta with a presence check so the new list is
				// appended only if it isn't already associated.
				//
				// PT3/F6: the presence check is a classic read-modify-write — two
				// concurrent batch runs targeting the same team would both observe
				// "list missing", both append, and produce duplicate rows. Serialize
				// the whole check-then-append under a per-team lock so the second
				// caller sees the first caller's insert.
				if ( class_exists( 'SPAT_Lock' ) ) {
					SPAT_Lock::with(
						'splm_team_list_' . (int) $team_id,
						30,
						function () use ( $team_id, $list_id ) {
							$existing_lists = get_post_meta( $team_id, 'sp_list', false );
							$existing_lists = is_array( $existing_lists ) ? array_map( 'intval', $existing_lists ) : array();
							if ( ! in_array( (int) $list_id, $existing_lists, true ) ) {
								add_post_meta( $team_id, 'sp_list', $list_id, false );
							}
						}
					);
				} else {
					// SPAT_Lock should always be loaded with the parent plugin; fall back
					// to the unguarded path so the feature still works if the lock helper
					// is missing rather than dropping the meta entirely.
					$existing_lists = get_post_meta( $team_id, 'sp_list', false );
					$existing_lists = is_array( $existing_lists ) ? array_map( 'intval', $existing_lists ) : array();
					if ( ! in_array( (int) $list_id, $existing_lists, true ) ) {
						add_post_meta( $team_id, 'sp_list', $list_id, false );
					}
				}

				$processed_count++;
			}
		}

		// PT3/F5: stash the locked-team list in a per-user transient so the redirect
		// target can render a warning alongside the success notice. The transient is
		// one-shot — success_notice() deletes it after rendering.
		if ( ! empty( $locked_teams ) ) {
			$user_id = get_current_user_id();
			if ( $user_id ) {
				set_transient( 'spt_batch_locked_teams_' . $user_id, $locked_teams, 5 * MINUTE_IN_SECONDS );
			}
		}

		$redirect = add_query_arg(
			array(
				'post_type'          => 'sp_list',
				'spt_batch_created'  => 1,
				'spt_batch_skipped'  => count( $locked_teams ),
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	private function show_preview() {
		$payload = $this->get_payload();
		if ( null === $payload || empty( $payload['rows'] ) ) {
			echo '<div class="wrap"><p>' . esc_html__( 'No data found. Please upload a CSV file.', 'sportspress-player-tools' ) . '</p></div>';
			return;
		}

		$data = $payload['rows'];

		// Pagination
		$per_page     = self::PER_PAGE;
		$current_page = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
		$total_items  = count( $data );
		$total_pages  = (int) ceil( $total_items / $per_page );
		$offset       = ( $current_page - 1 ) * $per_page;
		$data_page    = array_slice( $data, $offset, $per_page, false );

		// Fix #11: use cached lookups; do NOT recompute matches per render.
		$team_objects   = $this->get_team_objects();
		$player_objects = $this->get_player_objects();
		$matches        = isset( $payload['matches'] ) && is_array( $payload['matches'] ) ? $payload['matches'] : array();
		$overrides      = isset( $payload['overrides'] ) && is_array( $payload['overrides'] ) ? $payload['overrides'] : array();
		$reviewed_pages = isset( $payload['reviewed_pages'] ) && is_array( $payload['reviewed_pages'] )
			? array_map( 'intval', $payload['reviewed_pages'] )
			: array();
		$all_reviewed   = count( array_unique( $reviewed_pages ) ) >= $total_pages;

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Preview & Confirm Player Lists', 'sportspress-player-tools' ); ?></h1>
			<p><?php printf( esc_html__( 'Showing %1$d-%2$d of %3$d entries', 'sportspress-player-tools' ), $offset + 1, min( $offset + $per_page, $total_items ), $total_items ); ?></p>

			<?php if ( $total_pages > 1 ) : ?>
				<div class="notice notice-info" style="padding:10px 12px;">
					<p>
						<?php
						printf(
							esc_html(
								/* translators: %1$d reviewed, %2$d total */
								_n(
									'%1$d of %2$d page reviewed. You must open every page before submitting.',
									'%1$d of %2$d pages reviewed. You must open every page before submitting.',
									$total_pages,
									'sportspress-player-tools'
								)
							),
							count( array_unique( $reviewed_pages ) ),
							$total_pages
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="batch-form">
				<input type="hidden" name="action" value="spt_process_list_batch">
				<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'spt_batch_process' ) ); ?>">
				<input type="hidden" name="current_page" value="<?php echo esc_attr( $current_page ); ?>">



				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'List Name Template', 'sportspress-player-tools' ); ?></th>
						<td>
							<input type="text" name="list_name" value="{team} Roster" class="regular-text">
							<p class="description"><?php esc_html_e( 'Use {team} for team name, {season} for season name', 'sportspress-player-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Season', 'sportspress-player-tools' ); ?></th>
						<td>
							<?php
							$default_season = get_option( 'sportspress_season' );
							$seasons = get_terms(
								array(
									'taxonomy' => 'sp_season',
									'parent' => 0,
									'hide_empty' => false,
								)
							);
							?>
							<select name="season" required>
								<?php
								if ( ! is_wp_error( $seasons ) ) :
									foreach ( $seasons as $season ) :
										?>
									<option value="<?php echo esc_attr( $season->term_id ); ?>" <?php selected( $default_season, $season->term_id ); ?>>
										<?php echo esc_html( $season->name ); ?>
									</option>
																	<?php
								endforeach;
endif;
								?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Action', 'sportspress-player-tools' ); ?></th>
						<td>
							<label><input type="radio" name="list_action" value="create" checked> <?php esc_html_e( 'Create new player lists', 'sportspress-player-tools' ); ?></label><br>
							<label><input type="radio" name="list_action" value="update"> <?php esc_html_e( 'Update existing player lists (replace players)', 'sportspress-player-tools' ); ?></label>
							<p class="description"><?php esc_html_e( 'Update will find existing lists with matching team and season, then replace their players.', 'sportspress-player-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Display Options', 'sportspress-player-tools' ); ?></th>
						<td>
							<div style="margin-bottom: 10px;">
								<strong><?php esc_html_e( 'Basic:', 'sportspress-player-tools' ); ?></strong><br>
								<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="number" checked> <?php esc_html_e( 'Squad Number', 'sportspress-player-tools' ); ?></label>
								<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="team"> <?php esc_html_e( 'Team', 'sportspress-player-tools' ); ?></label>
								<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="position" checked> <?php esc_html_e( 'Position', 'sportspress-player-tools' ); ?></label>
								<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="birthday"> <?php esc_html_e( 'Date of Birth', 'sportspress-player-tools' ); ?></label>
								<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="age"> <?php esc_html_e( 'Age', 'sportspress-player-tools' ); ?></label>
							</div>

							<?php
							$metrics = get_posts(
								array(
									'post_type' => 'sp_metric',
									'posts_per_page' => -1,
									'orderby' => 'menu_order',
									'order' => 'ASC',
								)
							);
							if ( ! empty( $metrics ) ) :
								?>
							<div style="margin-bottom: 10px;">
								<strong><?php esc_html_e( 'Metrics:', 'sportspress-player-tools' ); ?></strong><br>
								<?php foreach ( $metrics as $metric ) : ?>
									<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr( $metric->post_name ); ?>"> <?php echo esc_html( $metric->post_title ); ?></label>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>

							<?php
							$performances = get_posts(
								array(
									'post_type' => 'sp_performance',
									'posts_per_page' => -1,
									'orderby' => 'menu_order',
									'order' => 'ASC',
								)
							);
							if ( ! empty( $performances ) ) :
								?>
							<div style="margin-bottom: 10px;">
								<strong><?php esc_html_e( 'Performance:', 'sportspress-player-tools' ); ?></strong><br>
								<?php foreach ( $performances as $perf ) : ?>
									<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr( $perf->post_name ); ?>" <?php checked( get_post_meta( $perf->ID, 'sp_visible', true ) == 1 ); ?>> <?php echo esc_html( $perf->post_title ); ?></label>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>

							<?php
							$statistics = get_posts(
								array(
									'post_type' => 'sp_statistic',
									'posts_per_page' => -1,
									'orderby' => 'menu_order',
									'order' => 'ASC',
								)
							);
							if ( ! empty( $statistics ) ) :
								?>
							<div style="margin-bottom: 10px;">
								<strong><?php esc_html_e( 'Statistics:', 'sportspress-player-tools' ); ?></strong><br>
								<?php foreach ( $statistics as $stat ) : ?>
									<label style="display: inline-block; margin-right: 15px;"><input type="checkbox" name="columns[]" value="<?php echo esc_attr( $stat->post_name ); ?>" <?php checked( get_post_meta( $stat->ID, 'sp_visible', true ) == 1 ); ?>> <?php echo esc_html( $stat->post_title ); ?></label>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<table class="wp-list-table widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'CSV Team', 'sportspress-player-tools' ); ?></th>
							<th><?php esc_html_e( 'Matched Team', 'sportspress-player-tools' ); ?></th>
							<th><?php esc_html_e( 'CSV Player', 'sportspress-player-tools' ); ?></th>
							<th><?php esc_html_e( 'Matched Player', 'sportspress-player-tools' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$global_idx = $offset;
						foreach ( $data_page as $row ) :
							$match            = isset( $matches[ $global_idx ] ) ? $matches[ $global_idx ] : array();
							$team_ambiguous   = ! empty( $match['team_ambiguous'] );
							$player_ambiguous = ! empty( $match['player_ambiguous'] );
							$matched_team     = isset( $overrides[ $global_idx ]['team'] ) ? (int) $overrides[ $global_idx ]['team'] : (int) ( $match['team'] ?? 0 );
							$matched_player   = isset( $overrides[ $global_idx ]['player'] ) ? (int) $overrides[ $global_idx ]['player'] : (int) ( $match['player'] ?? 0 );
							?>

						<tr>
							<td><?php echo esc_html( $row['team'] ); ?></td>
							<td style="<?php echo $team_ambiguous ? esc_attr( 'background-color: #fff3cd; border-left: 3px solid #ff9800;' ) : ''; ?>">
								<select name="team_<?php echo esc_attr( $global_idx ); ?>" class="spt-team-select" style="width: 100%;" required>
									<?php foreach ( $team_objects as $team ) : ?>
										<option value="<?php echo esc_attr( $team->ID ); ?>" <?php selected( $matched_team, $team->ID ); ?>>
											<?php echo esc_html( $team->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td style="<?php echo $player_ambiguous ? esc_attr( 'background-color: #fff3cd; border-left: 3px solid #ff9800;' ) : ''; ?>">
								<select name="player_<?php echo esc_attr( $global_idx ); ?>" class="spt-player-select" style="width: 100%;" required>
									<?php foreach ( $player_objects as $player ) : ?>
										<option value="<?php echo esc_attr( $player->ID ); ?>" <?php selected( $matched_player, $player->ID ); ?>>
											<?php echo esc_html( $player->post_title ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
							<?php
							$global_idx++;
						endforeach;
						?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav">
					<div class="tablenav-pages">
						<span class="displaying-num"><?php printf( esc_html__( '%s items', 'sportspress-player-tools' ), number_format_i18n( $total_items ) ); ?></span>
						<span class="pagination-links">
							<?php if ( $current_page > 1 ) : ?>
								<a class="prev-page button" href="#" data-page="<?php echo esc_attr( $current_page - 1 ); ?>">&laquo;</a>
							<?php endif; ?>
							<span class="paging-input">
								<span class="tablenav-paging-text"><?php echo esc_html( $current_page ); ?> of <?php echo esc_html( $total_pages ); ?></span>
							</span>
							<?php if ( $current_page < $total_pages ) : ?>
								<a class="next-page button" href="#" data-page="<?php echo esc_attr( $current_page + 1 ); ?>">&raquo;</a>
							<?php endif; ?>
						</span>
					</div>
				</div>

				<?php endif; ?>

				<p class="submit">
					<button type="button" class="button button-primary" id="test-submit" <?php disabled( ! $all_reviewed ); ?>>
						<?php esc_html_e( 'Create Player Lists', 'sportspress-player-tools' ); ?>
					</button>
					<span id="status"></span>
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=sp_list' ) ); ?>" class="button"><?php esc_html_e( 'Cancel', 'sportspress-player-tools' ); ?></a>
				</p>
			</form>
			<script>
			jQuery(document).ready(function($) {
				<?php if ( get_option( 'spat_use_select2', '0' ) === '1' ) : ?>
				document.querySelectorAll('.spt-team-select, .spt-player-select').forEach(function(el) {
					new SlimSelect({ select: el });
				});
				<?php endif; ?>

				var ajaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-post.php' ) ); ?>;
				var nonce      = <?php echo wp_json_encode( wp_create_nonce( 'spt_batch_process' ) ); ?>;
				var currentPg  = <?php echo esc_html( (int) $current_page ); ?>;
				var totalPages = <?php echo esc_html( (int) $total_pages ); ?>;
				var reviewed   = <?php echo wp_json_encode( array_values( array_unique( array_map( 'intval', $reviewed_pages ) ) ) ); ?>;
				var listAction = function() { return $('input[name="list_action"]:checked').val(); };

				function buildPayload(extra) {
					var data = $.extend({
						action: 'spt_mark_page_reviewed',
						_wpnonce: nonce,
						page: currentPg
					}, extra || {});
					$('select[name^="team_"], select[name^="player_"]').each(function() {
						data[this.name] = this.value;
					});
					return data;
				}

				function markPageReviewed(cb) {
					$.post(ajaxUrl, buildPayload(), function(resp) {
						if (resp && resp.success && resp.data) {
							reviewed = resp.data.reviewed_pages || reviewed;
							if (resp.data.all_reviewed) {
								$('#test-submit').prop('disabled', false);
							}
						}
						if (cb) cb();
					}).fail(function() { if (cb) cb(); });
				}

				// Auto-record the current page as reviewed on load.
				markPageReviewed();

				$('#test-submit').on('click', function(e) {
					e.preventDefault();
					if (reviewed.length < totalPages) {
						alert(<?php echo wp_json_encode( __( 'You must open every page before submitting.', 'sportspress-player-tools' ) ); ?>);
						return;
					}
					if (listAction() === 'update') {
						var msg = <?php echo wp_json_encode( __( 'Update mode will REPLACE the player roster on each matched list. Are you sure you want to continue?', 'sportspress-player-tools' ) ); ?>;
						if (!window.confirm(msg)) return;
					}
					$('#batch-form').submit();
				});

				// Pagination with form preservation
				$('.prev-page, .next-page').on('click', function(e) {
					e.preventDefault();
					var page = $(this).data('page');
					var url = new URL(window.location.href);
					url.searchParams.set('paged', page);

					// Persist overrides server-side before navigating away.
					markPageReviewed(function() {
						window.location.href = url.toString();
					});
				});
			});
			</script>
		</div>

		<style>
		.wp-list-table th:nth-child(1), .wp-list-table td:nth-child(1) { width: 20%; }
		.wp-list-table th:nth-child(2), .wp-list-table td:nth-child(2) { width: 30%; }
		.wp-list-table th:nth-child(3), .wp-list-table td:nth-child(3) { width: 20%; }
		.wp-list-table th:nth-child(4), .wp-list-table td:nth-child(4) { width: 30%; }
		.wp-list-table select { width: 100%; max-width: 100%; }
		</style>


		<?php
	}

	private function find_closest( $name, $posts, &$is_ambiguous = false ) {
		$name_lower = strtolower( trim( $name ) );
		$best = null;
		$best_dist = PHP_INT_MAX;
		$second_best_dist = PHP_INT_MAX;

		foreach ( $posts as $post ) {
			$title = trim( $post->post_title );
			$title_lower = strtolower( $title );

			// Remove sponsor text in brackets for comparison
			$title_clean = preg_replace( '/\s*\([^)]+\)\s*/', ' ', $title );
			$title_clean_lower = strtolower( trim( $title_clean ) );

			// Exact match gets highest priority
			if ( $name_lower === $title_lower || $name_lower === $title_clean_lower ) {
				return $post->ID;
			}

			// Calculate distance using levenshtein (O(N²) vs O(N³) for similar_text)
			$dist = levenshtein( $name_lower, $title_clean_lower );

			// Bonus for containing the search term
			if ( strpos( $title_clean_lower, $name_lower ) !== false ) {
				$dist = max( 0, $dist - 10 );
			}

			if ( $dist < $best_dist ) {
				$second_best_dist = $best_dist;
				$best_dist = $dist;
				$best = $post->ID;
			} elseif ( $dist < $second_best_dist ) {
				$second_best_dist = $dist;
			}
		}

		// Mark as ambiguous if second best is close to best
		$is_ambiguous = ( $second_best_dist < PHP_INT_MAX && ( $second_best_dist - $best_dist ) < 3 );

		return $best;
	}

	public function ajax_search_teams() {
		check_ajax_referer( 'spt_search', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-player-tools' ) );
		}

		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page = isset( $_GET['page'] ) ? intval( $_GET['page'] ) : 1;
		$per_page = 50;

		$args = array(
			'post_type' => 'sp_team',
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => 'title',
			'order' => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$teams = get_posts( $args );

		$results = array();
		foreach ( $teams as $team ) {
			$results[] = array(
				'id' => $team->ID,
				'text' => $team->post_title,
			);
		}

		wp_send_json(
			array(
				'results' => $results,
				'more' => count( $results ) === $per_page,
			)
		);
	}

	public function ajax_search_players() {
		check_ajax_referer( 'spt_search', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Insufficient permissions', 'sportspress-player-tools' ) );
		}

		$search = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
		$page = isset( $_GET['page'] ) ? intval( $_GET['page'] ) : 1;
		$per_page = 50;

		$args = array(
			'post_type' => 'sp_player',
			'posts_per_page' => $per_page,
			'paged' => $page,
			'orderby' => 'title',
			'order' => 'ASC',
		);

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$players = get_posts( $args );

		$results = array();
		foreach ( $players as $player ) {
			$results[] = array(
				'id' => $player->ID,
				'text' => $player->post_title,
			);
		}

		wp_send_json(
			array(
				'results' => $results,
				'more' => count( $results ) === $per_page,
			)
		);
	}

	public function cleanup_old_temp_data() {
		global $wpdb;
		$table = $wpdb->prefix . 'spat_temp_data';
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $table WHERE data_type = 'batch_list' AND created_at < DATE_SUB(NOW(), INTERVAL %d HOUR)",
				24
			)
		);
	}
}
