<?php
/**
 * Player Modifications Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Player_Modifications {

	public function __construct() {
		// Fix #16: the local spt_email_meta / spt_captain_role gates are gone — if
		// this class is instantiated, the player_modifications module is enabled in
		// spat_enabled_modules and both features run.
		add_action( 'add_meta_boxes', array( $this, 'add_email_meta_box' ) );
		// PT2/F13: use the post-type-specific save_post_* hook so this handler only
		// fires for sp_player saves; the generic save_post hook runs for every post
		// type and forces a needless meta check on unrelated posts.
		add_action( 'save_post_sp_player', array( $this, 'save_email_meta' ) );

		add_action( 'add_meta_boxes', array( $this, 'add_captain_meta_box' ) );
		// PT2/F13: same fix for the captain meta box, which only applies to sp_list.
		add_action( 'save_post_sp_list', array( $this, 'save_captain_meta' ) );
		add_filter( 'sportspress_list_player_name', array( $this, 'add_captain_indicator' ), 10, 3 );
		// SportsPress builds a player list's name cells with get_the_title() and does
		// not fire the filter above, so the badge is also added to the table data
		// the player list template does filter.
		add_filter( 'sportspress_player_list_data', array( $this, 'add_captain_to_list_data' ), 10, 2 );
		// For themes that print their own roster markup (and strip tags from the
		// name): they ask for the badge here instead of relying on either hook above.
		add_filter( 'spt_roster_captain_indicator', array( $this, 'roster_captain_indicator' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'add_captain_css' ) );

		// PT3/F3: surface invalid-email feedback after save_email_meta() rejects a write.
		add_action( 'admin_notices', array( $this, 'maybe_render_email_invalid_notice' ) );

		// PT-7: warn (without blocking) when the Title being typed for an sp_player
		// matches an existing published player, before the record is created.
		// Enqueued only on the post edit screens, and only for sp_player.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_duplicate_name_check' ) );
	}

	public function add_email_meta_box() {
		add_meta_box(
			'spt_player_email',
			__( 'Player Email', 'sportspress-player-tools' ),
			array( $this, 'email_meta_box_callback' ),
			'sp_player',
			'side',
			'default'
		);
	}

	public function email_meta_box_callback( $post ) {
		wp_nonce_field( 'spt_email_meta', 'spt_email_meta_nonce' );
		$email = get_post_meta( $post->ID, 'spt_email', true );
		echo '<input type="email" name="spt_email" value="' . esc_attr( $email ) . '" class="widefat" />';
	}

	public function save_email_meta( $post_id ) {
		if ( ! isset( $_POST['spt_email_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spt_email_meta_nonce'] ) ), 'spt_email_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['spt_email'] ) ) {
			$raw       = wp_unslash( $_POST['spt_email'] );
			$sanitized = sanitize_email( $raw );

			// PT3/F3: mirror the REST endpoint's validation. sanitize_email() happily
			// strips characters and returns a string that is_email() rejects; without
			// this check the meta box silently writes garbage on top of a real
			// address. Allow explicit blanking, reject anything else that fails
			// is_email(), and surface a one-shot admin notice via a transient so the
			// editor knows nothing was saved.
			if ( '' === $sanitized || is_email( $sanitized ) ) {
				update_post_meta( $post_id, 'spt_email', $sanitized );
			} else {
				$user_id = get_current_user_id();
				if ( $user_id ) {
					set_transient( 'spt_email_invalid_' . $user_id, 1, 30 );
				}
			}
		}
	}

	/**
	 * PT3/F3: render the one-shot "invalid email skipped" notice queued by
	 * save_email_meta(). Hooked from the constructor in admin context.
	 */
	public function maybe_render_email_invalid_notice() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return;
		}
		$flag = get_transient( 'spt_email_invalid_' . $user_id );
		if ( ! $flag ) {
			return;
		}
		delete_transient( 'spt_email_invalid_' . $user_id );
		echo '<div class="notice notice-warning is-dismissible"><p>' .
			esc_html__( 'The email address you entered for this player was invalid and was not saved.', 'sportspress-player-tools' ) .
			'</p></div>';
	}

	/**
	 * PT-7: load the script that checks the Title field, on blur, against
	 * SPPT_REST_API::check_duplicate_player_name() and shows an inline
	 * (non-blocking) notice when another published player already has that
	 * exact name. Only on the post edit screens, only for sp_player, and only
	 * for users who can edit posts.
	 *
	 * @param string $hook_suffix Current admin page.
	 */
	public function enqueue_duplicate_name_check( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'sp_player' !== $screen->post_type || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		wp_enqueue_script(
			'spt-duplicate-name-check',
			SPT_PLUGIN_URL . 'assets/js/duplicate-name-check.js',
			array( 'jquery' ),
			SPT_VERSION,
			true
		);
		wp_localize_script(
			'spt-duplicate-name-check',
			'sptDuplicateName',
			array(
				'restUrl' => esc_url_raw( rest_url( 'splm/v1/players/check-duplicate-name' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'i18n'    => array(
					'prefix' => __( 'A player named', 'sportspress-player-tools' ),
					'suffix' => __( 'already exists.', 'sportspress-player-tools' ),
					'link'   => __( 'View existing player', 'sportspress-player-tools' ),
				),
			)
		);
	}

	public function add_captain_meta_box() {
		add_meta_box(
			'spt_captain_selection',
			__( 'Captain Selection', 'sportspress-player-tools' ),
			array( $this, 'captain_meta_box_callback' ),
			'sp_list',
			'side',
			'default'
		);
	}

	public function captain_meta_box_callback( $post ) {
		wp_nonce_field( 'spt_captain_meta', 'spt_captain_meta_nonce' );
		$captain_id = get_post_meta( $post->ID, 'spt_captain', true );

		// Get players in this list
		$players = get_post_meta( $post->ID, 'sp_player', false );

		echo '<select name="spt_captain" class="widefat">';
		echo '<option value="">' . esc_html__( 'Select Captain', 'sportspress-player-tools' ) . '</option>';

		foreach ( $players as $player_id ) {
			if ( $player_id ) {
				$player_name = get_the_title( $player_id );
				echo '<option value="' . esc_attr( $player_id ) . '" ' . selected( $captain_id, $player_id, false ) . '>' . esc_html( $player_name ) . '</option>';
			}
		}

		echo '</select>';
		// What the box showed, so saving the list without touching the box leaves
		// the captain alone (see save_captain_meta()).
		echo '<input type="hidden" name="spt_captain_shown" value="' . esc_attr( (string) $captain_id ) . '" />';
	}

	public function save_captain_meta( $post_id ) {
		if ( ! isset( $_POST['spt_captain_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['spt_captain_meta_nonce'] ) ), 'spt_captain_meta' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! isset( $_POST['spt_captain'] ) ) {
			return;
		}

		$captain_id = intval( $_POST['spt_captain'] );

		if ( $this->captain_box_untouched( $captain_id, wp_unslash( $_POST['spt_captain_shown'] ?? null ) ) ) {
			return;
		}

		if ( $captain_id ) {
			update_post_meta( $post_id, 'spt_captain', $captain_id );
		} else {
			delete_post_meta( $post_id, 'spt_captain' );
		}
		// A captain chosen or cleared by hand is final: never carried over again.
		update_post_meta( $post_id, '_spt_captain_carried', '1' );
	}

	/**
	 * Whether the Captain Selection box was saved with the value it showed.
	 *
	 * An untouched box is not a decision. SportsPress re-adds every player on the
	 * same save, which can carry last season's captain onto the list
	 * (SPT_Captain_Carryover); a blank box rendered before that must not wipe it.
	 * Forms without the field (older cached screens) count as touched, which keeps
	 * the old behaviour.
	 *
	 * @param int   $captain_id Captain ID submitted (0 for none).
	 * @param mixed $shown      The box's raw `spt_captain_shown` value, null when absent.
	 * @return bool
	 */
	private function captain_box_untouched( $captain_id, $shown ) {
		return null !== $shown && intval( sanitize_text_field( (string) $shown ) ) === $captain_id;
	}

	/**
	 * Put the captain badge on the captain's row of a player list's table data.
	 *
	 * @param array $data    Rows keyed by player ID (row 0 holds the column labels).
	 * @param int   $list_id The sp_list being rendered.
	 * @return array
	 */
	public function add_captain_to_list_data( $data, $list_id ) {
		$captain_id = (int) get_post_meta( $list_id, 'spt_captain', true );

		if ( $captain_id > 0 && is_array( $data ) && isset( $data[ $captain_id ]['name'] ) ) {
			$data[ $captain_id ]['name'] = $this->add_captain_indicator( $data[ $captain_id ]['name'], $captain_id, $list_id );
		}

		return $data;
	}

	/**
	 * The captain badge for one player on one list, for a theme's own roster markup.
	 *
	 * A theme prints `apply_filters( 'spt_roster_captain_indicator', '', $player_id, $list_id )`
	 * next to the player's name; this answers with the badge when that player is the
	 * list's captain, and with the value it was given otherwise.
	 *
	 * @param string $html      Markup so far (normally '').
	 * @param int    $player_id Player post ID.
	 * @param int    $list_id   The sp_list the roster row belongs to.
	 * @return string
	 */
	public function roster_captain_indicator( $html, $player_id, $list_id ) {
		$badge = trim( $this->add_captain_indicator( '', $player_id, $list_id ) );

		return '' === $badge ? $html : $html . $badge;
	}

	public function add_captain_indicator( $name, $player_id, $list_id ) {
		$captain_id = get_post_meta( $list_id, 'spt_captain', true );

		// A name that already carries the badge (both hooks fired) is left alone.
		if ( $captain_id && (int) $captain_id === (int) $player_id && false === strpos( $name, 'spt-captain-indicator' ) ) {
			$indicator_text = apply_filters( 'spt_captain_indicator_text', 'C' );
			$name .= ' <span class="spt-captain-indicator" title="' . esc_attr__( 'Captain', 'sportspress-player-tools' ) . '">' . esc_html( $indicator_text ) . '</span>';

		}

		return $name;
	}

	public function add_captain_css() {
		wp_register_style( 'spt-captain', false );
		wp_enqueue_style( 'spt-captain' );
		wp_add_inline_style( 'spt-captain', '.spt-captain-indicator { background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 0.8em; font-weight: bold; margin-left: 5px; }' );
	}
}
