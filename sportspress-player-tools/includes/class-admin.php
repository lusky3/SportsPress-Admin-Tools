<?php
/**
 * Admin Interface Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Admin {

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_admin_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_admin_content' ) );
	}

	public function add_admin_tab() {
		echo '<a href="#player-tools" class="nav-tab">' . esc_html( 'Player Tools' ) . '</a>';
	}

	public function add_admin_content() {
		echo '<div id="player-tools" class="tab-content" style="display:none;">';
		$this->admin_page_content();
		echo '</div>';
	}

	public function admin_page_content() {
		if ( isset( $_POST['save_settings'] ) && check_admin_referer( 'spt_settings_save', 'spt_settings_nonce' ) ) {
			update_option( 'spt_email_meta', isset( $_POST['spt_email_meta'] ) ? '1' : '0' );
			update_option( 'spt_captain_role', isset( $_POST['spt_captain_role'] ) ? '1' : '0' );
			update_option( 'spt_stats_enabler', isset( $_POST['spt_stats_enabler'] ) ? '1' : '0' );
			update_option( 'spt_batch_list_creator', isset( $_POST['spt_batch_list_creator'] ) ? '1' : '0' );
			update_option( 'spt_skill_level_enabled', isset( $_POST['spt_skill_level_enabled'] ) ? '1' : '0' );
			if ( isset( $_POST['spt_skill_min_games'] ) ) {
				update_option( 'spt_skill_min_games', max( 1, absint( $_POST['spt_skill_min_games'] ) ) );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'sportspress-player-tools' ) . '</p></div>';
		}

		$email_meta = get_option( 'spt_email_meta', '1' );
		$captain_role = get_option( 'spt_captain_role', '1' );
		$stats_enabler = get_option( 'spt_stats_enabler', '1' );
		$batch_list = get_option( 'spt_batch_list_creator', '1' );
		$skill_enabled = get_option( 'spt_skill_level_enabled', '0' );
		?>
			<form method="post">
				<?php wp_nonce_field( 'spt_settings_save', 'spt_settings_nonce' ); ?>
				<input type="hidden" name="current_tab" value="player-tools">
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Email Meta Box', 'sportspress-player-tools' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="spt_email_meta" value="1" <?php checked( $email_meta, '1' ); ?> />
								<?php esc_html_e( 'Add email meta box to player edit pages', 'sportspress-player-tools' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Captain Role Selection', 'sportspress-player-tools' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="spt_captain_role" value="1" <?php checked( $captain_role, '1' ); ?> />
								<?php esc_html_e( 'Add captain selection to player lists with "C" display on frontend', 'sportspress-player-tools' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Statistics Enabler', 'sportspress-player-tools' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="spt_stats_enabler" value="1" <?php checked( $stats_enabler, '1' ); ?> />
								<?php esc_html_e( 'Automatically enable frontend statistics display for players', 'sportspress-player-tools' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Batch List Creator', 'sportspress-player-tools' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="spt_batch_list_creator" value="1" <?php checked( $batch_list, '1' ); ?> />
								<?php esc_html_e( 'Enable CSV upload for batch player list creation', 'sportspress-player-tools' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Skill Level Tracking', 'sportspress-player-tools' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="spt_skill_level_enabled" value="1" <?php checked( $skill_enabled, '1' ); ?> />
								<?php esc_html_e( 'Enable admin-only skill ratings with auto-calculation from stats', 'sportspress-player-tools' ); ?>
							</label>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Settings', 'sportspress-player-tools' ), 'primary', 'save_settings' ); ?>
			</form>
			
			<hr>
			<h2><?php esc_html_e( 'Upload Player Lists', 'sportspress-player-tools' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="spt_upload_list_csv">
				<?php wp_nonce_field( 'spt_batch_list_upload', 'spt_batch_list_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th><?php esc_html_e( 'CSV File', 'sportspress-player-tools' ); ?></th>
						<td>
							<input type="file" name="csv_file" accept=".csv" required>
							<p class="description"><?php esc_html_e( 'CSV must have Team and Name columns', 'sportspress-player-tools' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Upload & Preview', 'sportspress-player-tools' ) ); ?>
			</form>

			<?php
			if ( class_exists( 'SPT_Player_Skill_Level' ) && get_option( 'spt_skill_level_enabled', '0' ) === '1' ) {
				SPT_Player_Skill_Level::render_settings();
			}
			?>
		<?php
	}
}
