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

	/**
	 * How long after posting a note its author may still edit the text.
	 *
	 * M24: this window was advertised to the browser as `editLimit` and enforced
	 * ONLY in player-notes.js — ajax_update_player_note() rewrote any note by id
	 * for any notes-capable user, forever. Notes are management commentary kept
	 * as an audit trail (deletes are soft, never destructive), so an unlogged,
	 * unattributed rewrite of someone else's words is precisely what that trail
	 * is supposed to prevent. The constant is the single source of truth for both
	 * the localized value and the server check.
	 */
	const EDIT_WINDOW = 24 * HOUR_IN_SECONDS;

	public function __construct() {
		// Create table on first load if needed.
		$this->maybe_create_table();

		// Backend: meta box on player edit screen.
		add_action( 'add_meta_boxes_sp_player', array( $this, 'add_meta_box' ) );

		// Backend: enqueue scripts on player edit screens.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		// Frontend: notes panel on player single pages (SportsPress managers only).
		if ( ! is_admin() && ! defined( 'WP_CLI' ) ) {
			add_filter( 'the_content', array( $this, 'append_frontend_notes' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_frontend' ) );
		}

		// AJAX handlers for player notes.
		add_action( 'wp_ajax_splm_get_player_notes', array( $this, 'ajax_get_player_notes' ) );
		add_action( 'wp_ajax_splm_add_player_note', array( $this, 'ajax_add_player_note' ) );
		add_action( 'wp_ajax_splm_delete_player_note', array( $this, 'ajax_delete_player_note' ) );
		add_action( 'wp_ajax_splm_update_player_note', array( $this, 'ajax_update_player_note' ) );
	}

	/**
	 * Create the table if it doesn't exist yet.
	 */
	private function maybe_create_table() {
		if ( get_option( 'splm_notes_db_version' ) !== '1.0' ) {
			// create_table() now verifies the table exists after dbDelta. Only
			// stamp the version on a confirmed success, so a failed migration is
			// retried on the next load instead of being recorded as done.
			if ( true === SPLM_Player_Notes_Database::create_table() ) {
				update_option( 'splm_notes_db_version', '1.0' );
			}
		}
	}

	// ------------------------------------------------------------------
	// Backend: Meta box
	// ------------------------------------------------------------------

	public function add_meta_box() {
		if ( ! SPLM_Capabilities::can_access_notes() ) {
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
		if ( get_post_type() !== 'sp_player' || ! SPLM_Capabilities::can_access_notes() ) {
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
				'editLimit' => self::EDIT_WINDOW,
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
	// Frontend: manager-only notes panel
	// ------------------------------------------------------------------

	public function maybe_enqueue_frontend() {
		if ( ! is_singular( 'sp_player' ) || ! SPLM_Capabilities::can_access_notes() ) {
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
				'editLimit' => self::EDIT_WINDOW,
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
	// AJAX handlers
	// ------------------------------------------------------------------

	/**
	 * AJAX: Get all notes for a player.
	 */
	public function ajax_get_player_notes() {
		check_ajax_referer( 'splm_ajax_nonce', '_ajax_nonce' );

		if ( ! SPLM_Capabilities::can_access_notes() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$player_id = absint( $_POST['player_id'] ?? 0 );
		if ( ! $player_id ) {
			wp_send_json_error( array( 'message' => 'Invalid player ID.' ) );
		}

		$notes = SPLM_Player_Notes_Database::get_notes( $player_id );
		wp_send_json_success( array( 'notes' => $notes ) );
	}

	/**
	 * AJAX: Add a note for a player.
	 */
	public function ajax_add_player_note() {
		check_ajax_referer( 'splm_ajax_nonce', '_ajax_nonce' );

		if ( ! SPLM_Capabilities::can_access_notes() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$player_id = absint( $_POST['player_id'] ?? 0 );
		$note      = sanitize_textarea_field( $_POST['note'] ?? '' );
		$category  = sanitize_text_field( $_POST['category'] ?? 'general' );

		if ( ! $player_id || empty( $note ) ) {
			wp_send_json_error( array( 'message' => 'Player ID and note are required.' ) );
		}

		$insert_id = SPLM_Player_Notes_Database::insert( $player_id, get_current_user_id(), $note, $category );

		if ( ! $insert_id ) {
			wp_send_json_error( array( 'message' => 'Failed to save note.' ) );
		}

		wp_send_json_success( array( 'id' => $insert_id ) );
	}

	/**
	 * AJAX: Delete (soft-delete) a note.
	 */
	public function ajax_delete_player_note() {
		check_ajax_referer( 'splm_ajax_nonce', '_ajax_nonce' );

		if ( ! SPLM_Capabilities::can_access_notes() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$note_id = absint( $_POST['note_id'] ?? 0 );
		if ( ! $note_id ) {
			wp_send_json_error( array( 'message' => 'Invalid note ID.' ) );
		}

		$deleted = SPLM_Player_Notes_Database::soft_delete( $note_id );

		if ( ! $deleted ) {
			wp_send_json_error( array( 'message' => 'Failed to delete note.' ) );
		}

		wp_send_json_success();
	}

	/**
	 * Whether the current user may rewrite the text of an existing note.
	 *
	 * M24 policy — editing is author-only, within EDIT_WINDOW of posting, with no
	 * capability override. This is deliberately NARROWER than the notes trust
	 * tier and it does NOT lock managers out of moderation:
	 *
	 *   - Removing an inappropriate note is the moderation action, and
	 *     ajax_delete_player_note() / DELETE /notes/{id} stay open to every
	 *     notes-capable user (SPLM_Capabilities::can_access_notes()). That path
	 *     soft-deletes, so the row and its authorship survive for audit.
	 *   - Editing silently replaces another manager's words under their name,
	 *     with no history kept (Notes_Database::update() overwrites in place).
	 *     An admin who needs different wording on the record can delete and
	 *     re-add under their own byline, which leaves both versions traceable.
	 *
	 * The 24h window is the one already advertised to the browser via the
	 * localized `editLimit`; it is now actually enforced.
	 *
	 * @param object $note Row from SPLM_Player_Notes_Database::get_note().
	 * @return true|string True when allowed, else a reason for the client.
	 */
	private function can_edit_note( $note ) {
		if ( (int) $note->author_id !== get_current_user_id() ) {
			return __( 'You can only edit notes you wrote. Delete the note instead if it needs to be retracted.', 'sportspress-league-manager' );
		}

		// created_at is stored/compared as UTC — Notes_Database::update() writes
		// current_time( 'mysql', true ) and player-notes.js parses it with a "Z"
		// suffix, so keep the same basis here.
		$created = strtotime( $note->created_at . ' UTC' );
		if ( ! $created ) {
			return __( 'This note has no readable timestamp and can no longer be edited.', 'sportspress-league-manager' );
		}
		if ( ( time() - $created ) > self::EDIT_WINDOW ) {
			return __( 'The 24-hour window for editing this note has passed. Delete the note instead if it needs to be retracted.', 'sportspress-league-manager' );
		}

		return true;
	}

	/**
	 * AJAX: Update a note's text.
	 */
	public function ajax_update_player_note() {
		check_ajax_referer( 'splm_ajax_nonce', '_ajax_nonce' );

		if ( ! SPLM_Capabilities::can_access_notes() ) {
			wp_send_json_error( array( 'message' => 'Unauthorized.' ), 403 );
		}

		$note_id = absint( $_POST['note_id'] ?? 0 );
		$note    = sanitize_textarea_field( $_POST['note'] ?? '' );

		if ( ! $note_id || empty( $note ) ) {
			wp_send_json_error( array( 'message' => 'Note ID and text are required.' ) );
		}

		// M24: enforce authorship + the advertised 24h window server-side. The
		// browser already hides the Edit control under the same rule, but the
		// AJAX action accepted any note id from any notes-capable user.
		// get_note() only returns rows with is_deleted = 0, so an unknown or
		// already-deleted id reports 404 instead of silently succeeding.
		$existing = SPLM_Player_Notes_Database::get_note( $note_id );
		if ( ! $existing ) {
			wp_send_json_error( array( 'message' => 'Note not found.' ), 404 );
		}

		$allowed = $this->can_edit_note( $existing );
		if ( true !== $allowed ) {
			wp_send_json_error( array( 'message' => $allowed ), 403 );
		}

		$updated = SPLM_Player_Notes_Database::update( $note_id, $note );

		if ( ! $updated ) {
			wp_send_json_error( array( 'message' => 'Failed to update note.' ) );
		}

		wp_send_json_success();
	}

	// ------------------------------------------------------------------
	// Frontend: manager-only notes panel
	// ------------------------------------------------------------------

	/**
	 * Append notes panel to player post content (frontend, managers only).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function append_frontend_notes( $content ) {
		if ( ! is_singular( 'sp_player' ) || ! SPLM_Capabilities::can_access_notes() ) {
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
