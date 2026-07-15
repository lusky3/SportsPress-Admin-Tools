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
		echo '<a href="#player-tools" class="nav-tab">' . esc_html__( 'Player Tools', 'sportspress-player-tools' ) . '</a>';
	}

	public function add_admin_content() {
		echo '<div id="player-tools" class="tab-content" style="display:none;">';
		$this->admin_page_content();
		// Other Player Tools features (e.g. Email Sync) render inside this panel.
		do_action( 'spt_player_tools_content' );
		echo '</div>';
	}

	public function admin_page_content() {
		// Fix #16: only persist settings the parent doesn't already manage.
		// The local feature checkboxes were duplicates of spat_enabled_modules and
		// have been removed; the only setting saved here is the skill-level min-games threshold.
		if ( isset( $_POST['save_settings'] ) && check_admin_referer( 'spt_settings_save', 'spt_settings_nonce' ) ) {
			if ( isset( $_POST['spt_skill_min_games'] ) ) {
				update_option( 'spt_skill_min_games', max( 1, absint( $_POST['spt_skill_min_games'] ) ) );
			}
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'sportspress-player-tools' ) . '</p></div>';
		}

		$enabled_modules = (array) get_option( 'spat_enabled_modules', array() );
		?>
			<p class="description">
				<?php esc_html_e( 'Player Tools features are enabled via the parent plugin\'s module toggles.', 'sportspress-player-tools' ); ?>
			</p>

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
			if ( class_exists( 'SPT_Player_Skill_Level' ) && in_array( 'player_skill_level', $enabled_modules, true ) ) {
				SPT_Player_Skill_Level::render_settings();
			}
			?>
		<?php
	}
}
