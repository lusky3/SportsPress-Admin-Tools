<?php
/**
 * Admin review screen: the human-in-the-loop gate. Shows the uploaded image
 * beside the extracted, editable values (flagged fields highlighted); on
 * confirm, maps the reviewed data to the SportsPress writer. Nothing reaches
 * SportsPress until an admin confirms here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Review_Admin {

	const PAGE_SLUG = 'spss-review';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_hidden_page' ) );
		add_action( 'admin_post_spss_confirm_sheet', array( $this, 'handle_confirm' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add_hidden_page() {
		// Registered under the Score Sheets menu but reached via the queue's
		// "Review" button; not shown as its own menu item.
		add_submenu_page(
			SPSS_Admin::MENU_SLUG,
			__( 'Review Sheet', 'sportspress-score-sheets' ),
			__( 'Review Sheet', 'sportspress-score-sheets' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
		remove_submenu_page( SPSS_Admin::MENU_SLUG, self::PAGE_SLUG );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'spss-admin', SPSS_PLUGIN_URL . 'assets/css/admin.css', array(), SPSS_VERSION );
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sheet_id = isset( $_GET['sheet_id'] ) ? absint( $_GET['sheet_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$sheet    = $sheet_id ? SPSS_Database::get_sheet( $sheet_id ) : null;

		if ( ! $sheet ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Review Sheet', 'sportspress-score-sheets' ) . '</h1><p>' . esc_html__( 'Sheet not found.', 'sportspress-score-sheets' ) . '</p></div>';
			return;
		}

		$data = json_decode( (string) $sheet->extracted_json, true );
		$data = is_array( $data ) ? $data : array();

		// Event selection: from the sheet, or overridden via the query string.
		$event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : (int) $sheet->event_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$teams    = $event_id ? array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) ) : array();
		$rosters  = $event_id ? SPSS_Ingest_Service::build_context( $event_id )['rosters'] : array(
			'home' => array(),
			'away' => array(),
		);

		$flags_by_index = array();
		foreach ( (array) ( $data['flags'] ?? array() ) as $f ) {
			if ( isset( $f['player_index'] ) && null !== $f['player_index'] ) {
				$flags_by_index[ (int) $f['player_index'] ][] = $f['detail'] ?? $f['type'];
			}
		}
		?>
		<div class="wrap spss-review">
			<h1><?php esc_html_e( 'Review Score Sheet', 'sportspress-score-sheets' ); ?></h1>

			<?php if ( ! empty( $data['flags'] ) ) : ?>
				<div class="notice notice-warning"><p><strong><?php esc_html_e( 'Please check the highlighted items:', 'sportspress-score-sheets' ); ?></strong></p>
				<ul class="spss-flags">
					<?php foreach ( $data['flags'] as $f ) : ?>
						<li><?php echo esc_html( ( $f['type'] ?? '' ) . ( ! empty( $f['detail'] ) ? ' — ' . $f['detail'] : '' ) ); ?></li>
					<?php endforeach; ?>
				</ul></div>
			<?php endif; ?>

			<div class="spss-review-grid">
				<div class="spss-review-image">
					<?php if ( ! empty( $sheet->image_path ) ) : ?>
						<img src="<?php echo esc_url( SPSS_File_Server::image_url( $sheet_id ) ); ?>" alt="<?php esc_attr_e( 'Uploaded score sheet', 'sportspress-score-sheets' ); ?>" />
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Image no longer stored.', 'sportspress-score-sheets' ); ?></p>
					<?php endif; ?>
				</div>

				<div class="spss-review-form">
					<?php if ( ! $event_id ) : ?>
						<form method="get">
							<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
							<input type="hidden" name="sheet_id" value="<?php echo esc_attr( $sheet_id ); ?>" />
							<p><label><strong><?php esc_html_e( 'Select the game this sheet belongs to:', 'sportspress-score-sheets' ); ?></strong></label></p>
							<?php $this->event_dropdown( 0 ); ?>
							<?php submit_button( __( 'Load rosters', 'sportspress-score-sheets' ), 'secondary' ); ?>
						</form>
					<?php else : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="spss_confirm_sheet" />
							<input type="hidden" name="sheet_id" value="<?php echo esc_attr( $sheet_id ); ?>" />
							<input type="hidden" name="event_id" value="<?php echo esc_attr( $event_id ); ?>" />
							<?php wp_nonce_field( 'spss_confirm_sheet_' . $sheet_id, 'spss_confirm_nonce' ); ?>

							<p><strong><?php esc_html_e( 'Game', 'sportspress-score-sheets' ); ?>:</strong> <?php echo esc_html( get_the_title( $event_id ) . ' — ' . get_the_date( '', $event_id ) ); ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&sheet_id=' . $sheet_id ) ); ?>"><?php esc_html_e( '(change)', 'sportspress-score-sheets' ); ?></a></p>

							<table class="form-table" role="presentation">
								<tr>
									<th><?php echo esc_html( $teams[0] ? get_the_title( $teams[0] ) : __( 'Home', 'sportspress-score-sheets' ) ); ?> <?php esc_html_e( 'score', 'sportspress-score-sheets' ); ?></th>
									<td><input type="number" min="0" name="home_score" value="<?php echo esc_attr( $data['teams']['home']['final_score'] ?? '' ); ?>" /></td>
								</tr>
								<tr>
									<th><?php echo esc_html( $teams[1] ? get_the_title( $teams[1] ) : __( 'Away', 'sportspress-score-sheets' ) ); ?> <?php esc_html_e( 'score', 'sportspress-score-sheets' ); ?></th>
									<td><input type="number" min="0" name="away_score" value="<?php echo esc_attr( $data['teams']['away']['final_score'] ?? '' ); ?>" /></td>
								</tr>
								<tr>
									<th><?php esc_html_e( 'Overtime / shootout loss', 'sportspress-score-sheets' ); ?></th>
									<td>
										<select name="ot_loss_side">
											<option value=""><?php esc_html_e( '— None (regulation) —', 'sportspress-score-sheets' ); ?></option>
											<option value="home"><?php echo esc_html( sprintf( /* translators: %s: team */ __( '%s lost in OT/SO', 'sportspress-score-sheets' ), $teams[0] ? get_the_title( $teams[0] ) : 'Home' ) ); ?></option>
											<option value="away"><?php echo esc_html( sprintf( /* translators: %s: team */ __( '%s lost in OT/SO', 'sportspress-score-sheets' ), $teams[1] ? get_the_title( $teams[1] ) : 'Away' ) ); ?></option>
										</select>
									</td>
								</tr>
							</table>

							<h2><?php esc_html_e( 'Player stats', 'sportspress-score-sheets' ); ?></h2>
							<table class="widefat striped spss-players">
								<thead><tr>
									<th><?php esc_html_e( 'Side', 'sportspress-score-sheets' ); ?></th>
									<th><?php esc_html_e( 'Jersey', 'sportspress-score-sheets' ); ?></th>
									<th><?php esc_html_e( 'Player', 'sportspress-score-sheets' ); ?></th>
									<th><?php esc_html_e( 'Goals', 'sportspress-score-sheets' ); ?></th>
									<th><?php esc_html_e( 'Assists', 'sportspress-score-sheets' ); ?></th>
									<th><?php esc_html_e( 'PIM', 'sportspress-score-sheets' ); ?></th>
								</tr></thead>
								<tbody>
								<?php foreach ( (array) ( $data['players'] ?? array() ) as $i => $p ) : ?>
									<?php
									$flagged = isset( $flags_by_index[ $i ] );
									$side = ( 'away' === ( $p['team'] ?? 'home' ) ) ? 'away' : 'home';
									?>
									<tr class="<?php echo $flagged ? 'spss-flagged' : ''; ?>"<?php echo $flagged ? ' title="' . esc_attr( implode( '; ', $flags_by_index[ $i ] ) ) . '"' : ''; ?>>
										<td>
											<select name="players[<?php echo (int) $i; ?>][side]">
												<option value="home" <?php selected( $side, 'home' ); ?>><?php echo esc_html( $teams[0] ? get_the_title( $teams[0] ) : 'Home' ); ?></option>
												<option value="away" <?php selected( $side, 'away' ); ?>><?php echo esc_html( $teams[1] ? get_the_title( $teams[1] ) : 'Away' ); ?></option>
											</select>
										</td>
										<td><?php echo esc_html( $p['jersey_written'] ?? '' ); ?></td>
										<td><?php $this->player_dropdown( $i, $rosters, $side, (int) ( $p['matched_player_id'] ?? 0 ) ); ?></td>
										<td><input type="number" min="0" name="players[<?php echo (int) $i; ?>][g]" value="<?php echo esc_attr( $p['goals'] ?? '' ); ?>" size="3" /></td>
										<td><input type="number" min="0" name="players[<?php echo (int) $i; ?>][a]" value="<?php echo esc_attr( $p['assists'] ?? '' ); ?>" size="3" /></td>
										<td><input type="number" min="0" name="players[<?php echo (int) $i; ?>][pim]" value="<?php echo esc_attr( $p['pim'] ?? '' ); ?>" size="3" /></td>
									</tr>
								<?php endforeach; ?>
								</tbody>
							</table>

							<p class="description"><?php esc_html_e( 'Rows with no player selected are skipped. Confirming writes the score, outcomes, and player stats to the event.', 'sportspress-score-sheets' ); ?></p>
							<?php submit_button( __( 'Confirm &amp; apply to event', 'sportspress-score-sheets' ) ); ?>
						</form>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	private function event_dropdown( $selected ) {
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		echo '<select name="event_id">';
		echo '<option value="0">' . esc_html__( '— Select a game —', 'sportspress-score-sheets' ) . '</option>';
		foreach ( $events as $ev ) {
			printf( '<option value="%d" %s>%s</option>', (int) $ev->ID, selected( $selected, $ev->ID, false ), esc_html( get_the_title( $ev ) . ' — ' . get_the_date( '', $ev ) ) );
		}
		echo '</select>';
	}

	private function player_dropdown( $i, $rosters, $side, $selected_id ) {
		$roster = $rosters[ $side ] ?? array();
		printf( '<select name="players[%d][player_id]">', (int) $i );
		echo '<option value="0">' . esc_html__( '— skip —', 'sportspress-score-sheets' ) . '</option>';
		foreach ( $roster as $r ) {
			printf(
				'<option value="%d" %s>%s</option>',
				(int) $r['player_id'],
				selected( $selected_id, (int) $r['player_id'], false ),
				esc_html( ( '' !== $r['number'] ? '#' . $r['number'] . ' ' : '' ) . $r['name'] )
			);
		}
		echo '</select>';
	}

	public function handle_confirm() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-score-sheets' ), '', array( 'response' => 403 ) );
		}
		$sheet_id = isset( $_POST['sheet_id'] ) ? absint( $_POST['sheet_id'] ) : 0;
		check_admin_referer( 'spss_confirm_sheet_' . $sheet_id, 'spss_confirm_nonce' );

		$event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;
		$teams    = $event_id ? array_values( array_filter( array_map( 'intval', (array) get_post_meta( $event_id, 'sp_team', false ) ) ) ) : array();
		$home_id  = $teams[0] ?? 0;
		$away_id  = $teams[1] ?? 0;

		$players_raw = isset( $_POST['players'] ) && is_array( $_POST['players'] ) ? wp_unslash( $_POST['players'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$players     = array();
		foreach ( $players_raw as $row ) {
			$pid = isset( $row['player_id'] ) ? absint( $row['player_id'] ) : 0;
			if ( ! $pid ) {
				continue; // skipped row
			}
			$side     = ( isset( $row['side'] ) && 'away' === $row['side'] ) ? 'away' : 'home';
			$team_id  = ( 'away' === $side ) ? $away_id : $home_id;
			$players[] = array(
				'team_id'   => $team_id,
				'player_id' => $pid,
				'stats'     => array(
					'g'   => isset( $row['g'] ) && '' !== $row['g'] ? absint( $row['g'] ) : 0,
					'a'   => isset( $row['a'] ) && '' !== $row['a'] ? absint( $row['a'] ) : 0,
					'pim' => isset( $row['pim'] ) && '' !== $row['pim'] ? absint( $row['pim'] ) : 0,
				),
			);
		}

		$confirmed = array(
			'event_id'     => $event_id,
			'home_team_id' => $home_id,
			'away_team_id' => $away_id,
			'home_score'   => isset( $_POST['home_score'] ) && '' !== $_POST['home_score'] ? absint( $_POST['home_score'] ) : 0,
			'away_score'   => isset( $_POST['away_score'] ) && '' !== $_POST['away_score'] ? absint( $_POST['away_score'] ) : 0,
			'ot_loss_side' => ( isset( $_POST['ot_loss_side'] ) && in_array( $_POST['ot_loss_side'], array( 'home', 'away' ), true ) ) ? sanitize_key( wp_unslash( $_POST['ot_loss_side'] ) ) : '',
			'players'      => $players,
		);

		$result   = SPSS_Ingest_Service::apply_confirmed( $sheet_id, $confirmed );
		$queue_url = admin_url( 'admin.php?page=' . SPSS_Admin::MENU_SLUG );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'sheet_id' => $sheet_id,
						'spss_err' => rawurlencode( $result->get_error_message() ),
					),
					admin_url( 'admin.php?page=' . self::PAGE_SLUG )
				)
			);
			exit;
		}
		wp_safe_redirect( add_query_arg( 'spss_notice', 'applied', $queue_url ) );
		exit;
	}
}
