<?php
/**
 * Player Notes — Meta box, AJAX handlers, and frontend display
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Player_Notes {

	public function __construct() {
		// Create table on first load if needed.
		$this->maybe_create_table();

		// Backend: meta box on player edit screen.
		add_action( 'add_meta_boxes_sp_player', array( $this, 'add_meta_box' ) );

		// Backend: enqueue scripts on player edit screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Frontend: notes panel on player single pages (admin only).
		if ( ! is_admin() && ! defined( 'WP_CLI' ) ) {
			add_filter( 'the_content', array( $this, 'append_frontend_notes' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend' ) );
		}
	}

	/**
	 * Create the table if it doesn't exist yet.
	 */
	private function maybe_create_table() {
		if ( get_option( 'splm_notes_db_version' ) !== '1.0' ) {
			SPLM_Player_Notes_Database::create_table();
			update_option( 'splm_notes_db_version', '1.0' );
		}
	}

	// ------------------------------------------------------------------
	// Backend: Meta box
	// ------------------------------------------------------------------

	public function add_meta_box() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		add_meta_box(
			'splm_player_notes',
			__( 'Player Notes', 'sportspress-league-manager' ),
			array( $this, 'render_meta_box' ),
			'sp_player',
			'normal',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		?>
		<div id="splm-notes-app" data-player-id="<?php echo esc_attr( $post->ID ); ?>">
			<div id="splm-notes-list" class="splm-notes-list">
				<p class="splm-notes-loading"><?php esc_html_e( 'Loading notes…', 'sportspress-league-manager' ); ?></p>
			</div>
			<div class="splm-notes-form">
				<textarea id="splm-note-input" rows="3" maxlength="1000" placeholder="<?php esc_attr_e( 'Add a note…', 'sportspress-league-manager' ); ?>" class="widefat"></textarea>
				<div class="splm-notes-form-row">
					<input type="text" id="splm-note-category" placeholder="<?php esc_attr_e( 'Category (optional)', 'sportspress-league-manager' ); ?>" maxlength="50" />
					<button type="button" id="splm-note-submit" class="button button-primary"><?php esc_html_e( 'Add Note', 'sportspress-league-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		if ( get_post_type() !== 'sp_player' || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'splm-player-notes',
			SPLM_PLUGIN_URL . 'assets/js/player-notes.js',
			array( 'jquery' ),
			SPLM_VERSION,
			true
		);
		wp_localize_script(
			'splm-player-notes',
			'splmNotesData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'splm_ajax_nonce' ),
				'userId'    => get_current_user_id(),
				'editLimit' => 24 * 60 * 60,
				'i18n'      => array(
					'confirmDelete' => __( 'Delete this note?', 'sportspress-league-manager' ),
					'saving'        => __( 'Saving…', 'sportspress-league-manager' ),
					'noNotes'       => __( 'No notes yet.', 'sportspress-league-manager' ),
				),
			)
		);
		wp_enqueue_style(
			'splm-player-notes',
			SPLM_PLUGIN_URL . 'assets/css/player-notes.css',
			array(),
			SPLM_VERSION
		);
	}

	// ------------------------------------------------------------------
	// Frontend: Admin-only notes panel
	// ------------------------------------------------------------------

	public function maybe_enqueue_frontend() {
		if ( ! is_singular( 'sp_player' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		wp_enqueue_script(
			'splm-player-notes',
			SPLM_PLUGIN_URL . 'assets/js/player-notes.js',
			array( 'jquery' ),
			SPLM_VERSION,
			true
		);
		wp_localize_script(
			'splm-player-notes',
			'splmNotesData',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'splm_ajax_nonce' ),
				'userId'    => get_current_user_id(),
				'editLimit' => 24 * 60 * 60,
				'i18n'      => array(
					'confirmDelete' => __( 'Delete this note?', 'sportspress-league-manager' ),
					'saving'        => __( 'Saving…', 'sportspress-league-manager' ),
					'noNotes'       => __( 'No notes yet.', 'sportspress-league-manager' ),
				),
			)
		);
		wp_enqueue_style(
			'splm-player-notes',
			SPLM_PLUGIN_URL . 'assets/css/player-notes.css',
			array(),
			SPLM_VERSION
		);
	}

	/**
	 * Append notes panel to player post content (frontend, admin-only).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_frontend_notes( $content ) {
		if ( ! is_singular( 'sp_player' ) || ! current_user_can( 'manage_options' ) ) {
			return $content;
		}

		$player_id = get_the_ID();
		ob_start();
		?>
		<div class="splm-frontend-notes" id="splm-notes-app" data-player-id="<?php echo esc_attr( $player_id ); ?>">
			<h4><?php esc_html_e( 'Admin Notes', 'sportspress-league-manager' ); ?></h4>
			<div id="splm-notes-list" class="splm-notes-list">
				<p class="splm-notes-loading"><?php esc_html_e( 'Loading notes…', 'sportspress-league-manager' ); ?></p>
			</div>
			<div class="splm-notes-form">
				<textarea id="splm-note-input" rows="2" maxlength="1000" placeholder="<?php esc_attr_e( 'Add a note…', 'sportspress-league-manager' ); ?>" style="width:100%;"></textarea>
				<div class="splm-notes-form-row">
					<input type="text" id="splm-note-category" placeholder="<?php esc_attr_e( 'Category (optional)', 'sportspress-league-manager' ); ?>" maxlength="50" />
					<button type="button" id="splm-note-submit" class="button"><?php esc_html_e( 'Add Note', 'sportspress-league-manager' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		return $content . ob_get_clean();
	}
}
