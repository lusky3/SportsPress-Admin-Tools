<?php
/**
 * Player Profile Picture Upload
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Player_Profile_Picture {

	private $player_posts_cache = array();

	public function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_profile-picture_endpoint', array( $this, 'display_upload_form' ) );
		add_action( 'init', array( $this, 'handle_upload' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Fix #13: register endpoint + flush during plugin activation only.
	 * Called from the main plugin file's activation hook.
	 */
	public static function activate() {
		add_rewrite_endpoint( 'profile-picture', EP_ROOT | EP_PAGES );
		flush_rewrite_rules();
	}

	public function add_endpoint() {
		add_rewrite_endpoint( 'profile-picture', EP_ROOT | EP_PAGES );
	}

	public function add_query_vars( $vars ) {
		$vars['profile-picture'] = 'profile-picture';
		return $vars;
	}

	/**
	 * The player records linked to a WordPress user.
	 *
	 * Resolves on the sp_user meta link and nothing else. This used to query
	 * 'author' => $user_id, which is a different question: post_author records
	 * who CREATED a record, not who it is about. On rookiehockey.ca that made
	 * the account `codylusk` — which authored exactly one player, "Nick
	 * Prystie" — resolve to Nick Prystie, so the page showed his record as
	 * "your Profile Picture" and Upload called set_post_thumbnail() on it. A
	 * read bug shows you a stranger's face; this one defaced a stranger's
	 * record.
	 *
	 * There is deliberately NO author fallback, even though ~90% of players
	 * carry no sp_user link and the feature is therefore hidden from them until
	 * the link is backfilled. A fallback would put fuzzy identity matching back
	 * into a write path, and a bare author match is precisely what produced the
	 * bug. A hidden feature is recoverable; an overwritten photo is not.
	 *
	 * Read-only by design: registration's link_user_to_player() and the
	 * backfill tool are the only writers of sp_user, so there is one place to
	 * audit how a link is formed. See
	 * docs/superpowers/specs/2026-09-04-player-user-link-design.md.
	 *
	 * @param int $user_id WordPress user id.
	 * @return array Player post ids linked to this user.
	 */
	private function get_user_player_posts( $user_id ) {
		$user_id = (int) $user_id;

		if ( isset( $this->player_posts_cache[ $user_id ] ) ) {
			return $this->player_posts_cache[ $user_id ];
		}

		// A meta_query for user 0 would match every player whose sp_user was
		// stored as an empty string, handing the whole roster to a logged-out
		// request. Answer it without asking.
		if ( $user_id <= 0 ) {
			$this->player_posts_cache[ $user_id ] = array();
			return $this->player_posts_cache[ $user_id ];
		}

		$this->player_posts_cache[ $user_id ] = get_posts(
			array(
				'post_type' => 'sp_player',
				'posts_per_page' => -1,
				'fields' => 'ids',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- the sp_user link is the only way to resolve a player's owner; there is no taxonomy for it.
					array(
						'key' => 'sp_user',
						'value' => $user_id,
					),
				),
			)
		);
		return $this->player_posts_cache[ $user_id ];
	}

	public function add_menu_item( $items ) {
		if ( empty( $this->get_user_player_posts( get_current_user_id() ) ) ) {
			return $items;
		}

		$items['profile-picture'] = __( 'Profile Picture', 'sportspress-player-tools' );
		return $items;
	}

	public function display_upload_form() {
		if ( empty( $this->get_user_player_posts( get_current_user_id() ) ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$player_posts = $this->get_user_player_posts( $user_id );
		$player_count = count( $player_posts );

		?>
		<div class="woocommerce-MyAccount-content">
			<h3><?php esc_html_e( 'Profile Picture', 'sportspress-player-tools' ); ?></h3>
			
			<?php if ( $player_count !== 1 ) : ?>
				<div class="woocommerce-message woocommerce-message--info">
					<?php if ( $player_count === 0 ) : ?>
						<p><?php esc_html_e( 'Your account is not linked to a player record yet, so there is nothing to add a picture to. Please contact the site administrator to have it linked.', 'sportspress-player-tools' ); ?></p>
					<?php else : ?>
						<p><?php esc_html_e( 'More than one player record is linked to your account, so we cannot tell which one is yours. Please contact the site administrator.', 'sportspress-player-tools' ); ?></p>
					<?php endif; ?>
				</div>
				<?php
			else :
				$player_id = $player_posts[0];
				$current_image = get_post_thumbnail_id( $player_id );
				?>
				<?php if ( $current_image ) : ?>
					<div style="margin-bottom: 20px;">
						<?php echo wp_get_attachment_image( $current_image, 'thumbnail' ); ?>
					</div>
				<?php endif; ?>
				
				<form method="post" enctype="multipart/form-data">
					<?php wp_nonce_field( 'spt_upload_profile_picture', 'spt_picture_nonce' ); ?>
					<p>
						<input type="file" name="profile_picture" accept="image/*" required>
					</p>
					<p>
						<button type="submit" name="upload_picture" class="button"><?php esc_html_e( 'Upload Picture', 'sportspress-player-tools' ); ?></button>
					</p>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_upload() {
		if ( ! isset( $_POST['upload_picture'] ) || ! isset( $_POST['spt_picture_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spt_picture_nonce'] ) ), 'spt_upload_profile_picture' ) ) {
			return;
		}

		if ( empty( $this->get_user_player_posts( get_current_user_id() ) ) ) {
			return;
		}

		$user_id = get_current_user_id();
		$player_posts = $this->get_user_player_posts( $user_id );

		if ( count( $player_posts ) !== 1 || ! isset( $_FILES['profile_picture'] ) ) {
			return;
		}

		$player_id = $player_posts[0];

		// Validate file size (max 2MB)
		$max_size = 2 * 1024 * 1024;
		if ( $_FILES['profile_picture']['size'] > $max_size ) {
			wc_add_notice( __( 'File is too large. Maximum size is 2MB.', 'sportspress-player-tools' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'profile-picture' ) );
			exit;
		}

		// Fix #9: do not trust browser-supplied $_FILES['type']. Verify via
		// getimagesize() (real image bytes) plus wp_check_filetype_and_ext().
		$allowed_mime_types = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' );

		$image_info = getimagesize( $_FILES['profile_picture']['tmp_name'] );
		if ( $image_info === false || ! in_array( $image_info['mime'], $allowed_mime_types, true ) ) {
			wc_add_notice( __( 'The uploaded file is not a valid image.', 'sportspress-player-tools' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'profile-picture' ) );
			exit;
		}

		$filename       = isset( $_FILES['profile_picture']['name'] ) ? sanitize_file_name( $_FILES['profile_picture']['name'] ) : '';
		$checked        = wp_check_filetype_and_ext( $_FILES['profile_picture']['tmp_name'], $filename );
		$resolved_mime  = ! empty( $checked['type'] ) ? $checked['type'] : '';
		if ( ! $resolved_mime || ! in_array( $resolved_mime, $allowed_mime_types, true ) ) {
			wc_add_notice( __( 'Invalid file type. Only JPG, PNG, GIF, and WebP images are allowed.', 'sportspress-player-tools' ), 'error' );
			wp_safe_redirect( wc_get_account_endpoint_url( 'profile-picture' ) );
			exit;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'profile_picture', $player_id );

		// LOW (player-tools): a media_handle_upload() failure used to fall straight
		// off the end of this method — no notice, no redirect. The account page
		// simply re-rendered the empty form, so a failed upload (unwritable uploads
		// dir, disk full, a mime the WP allowlist rejects) was indistinguishable
		// from never having pressed the button. Every other exit path here already
		// surfaces a wc_add_notice(); this one now does too.
		if ( is_wp_error( $attachment_id ) ) {
			wc_add_notice(
				sprintf(
					/* translators: %s: error message from WordPress */
					__( 'The picture could not be saved: %s', 'sportspress-player-tools' ),
					$attachment_id->get_error_message()
				),
				'error'
			);
			wp_safe_redirect( wc_get_account_endpoint_url( 'profile-picture' ) );
			exit;
		}

		set_post_thumbnail( $player_id, $attachment_id );
		wc_add_notice( __( 'Profile picture updated.', 'sportspress-player-tools' ), 'success' );
		wp_safe_redirect( wc_get_account_endpoint_url( 'profile-picture' ) );
		exit;
	}
}
