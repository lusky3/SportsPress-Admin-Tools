<?php
/**
 * Admin Interface Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Admin {

	public function __construct() {
		add_action( 'spat_admin_init_settings', array( $this, 'register_settings' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_admin_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_admin_content' ) );
		add_filter( 'woocommerce_admin_order_actions', array( $this, 'add_rerun_order_action' ), 10, 2 );
		add_action( 'admin_post_spr_rerun_registration', array( $this, 'handle_rerun_registration' ) );

		// Seed the configurable keyword option used by the registration detector.
		add_option( 'spr_registration_keyword', 'registration' );
	}

	public function add_admin_tab() {
		echo '<a href="#player-registration" class="nav-tab">' . esc_html__( 'Player Registration', 'sportspress-player-registration' ) . '</a>';
	}

	public function add_admin_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div id="player-registration" class="tab-content" style="display:none;">';
		$this->admin_page_content();
		echo '</div>';
	}

	public function register_settings() {
		$checkbox_args = array(
			'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
		);
		register_setting( 'spr_settings', 'spr_auto_create', $checkbox_args );
		register_setting( 'spr_settings', 'spr_auto_update', $checkbox_args );
		register_setting( 'spr_settings', 'spr_auto_role', $checkbox_args );
		register_setting(
			'spr_settings',
			'spr_player_role',
			array(
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
		register_setting( 'spr_settings', 'spr_auto_season', $checkbox_args );
	}

	public function sanitize_checkbox( $value ) {
		return $value === '1' ? '1' : '0';
	}

	public function admin_page_content() {
		$auto_create = get_option( 'spr_auto_create', '1' );
		$auto_update = get_option( 'spr_auto_update', '1' );
		$auto_role = get_option( 'spr_auto_role', '1' );
		$player_role = get_option( 'spr_player_role', 'sp_player' );
		$auto_season = get_option( 'spr_auto_season', '1' );
		?>
			<form action="options.php" method="post">
				<input type="hidden" name="current_tab" value="player-registration">
				<?php settings_fields( 'spr_settings' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Player Creation', 'sportspress-player-registration' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="spr_auto_create" value="0" />
								<input type="checkbox" name="spr_auto_create" value="1" <?php checked( $auto_create, '1' ); ?> />
								<?php esc_html_e( 'Automatically create player records from registration orders', 'sportspress-player-registration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Update Player Records', 'sportspress-player-registration' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="spr_auto_update" value="0" />
								<input type="checkbox" name="spr_auto_update" value="1" <?php checked( $auto_update, '1' ); ?> />
								<?php esc_html_e( 'Find and update existing player records by name/email', 'sportspress-player-registration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Role Assignment', 'sportspress-player-registration' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="spr_auto_role" value="0" />
								<input type="checkbox" name="spr_auto_role" value="1" <?php checked( $auto_role, '1' ); ?> />
								<?php esc_html_e( 'Automatically assign player role to registered users', 'sportspress-player-registration' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Player Role', 'sportspress-player-registration' ); ?></th>
						<td>
							<select name="spr_player_role">
								<?php
								$roles = wp_roles()->roles;
								foreach ( $roles as $role_key => $role_data ) {
									echo '<option value="' . esc_attr( $role_key ) . '" ' . selected( $player_role, $role_key, false ) . '>' . esc_html( $role_data['name'] ) . '</option>';
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Select the role to assign to registered users', 'sportspress-player-registration' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic Season Assignment', 'sportspress-player-registration' ); ?></th>
						<td>
							<label>
								<input type="hidden" name="spr_auto_season" value="0" />
								<input type="checkbox" name="spr_auto_season" value="1" <?php checked( $auto_season, '1' ); ?> />
								<?php esc_html_e( 'Automatically assign season taxonomy to player records', 'sportspress-player-registration' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Settings', 'sportspress-player-registration' ), 'primary', 'save_settings' ); ?>
			</form>

			<h2><?php esc_html_e( 'Registration Activity Log', 'sportspress-player-registration' ); ?></h2>
			<?php $this->display_registration_logs(); ?>

			<h2><?php esc_html_e( 'Role Assignment Log', 'sportspress-player-registration' ); ?></h2>
			<?php $this->display_role_logs(); ?>
		<?php
	}

	private function display_registration_logs() {
		$per_page = 50;
		$page     = $this->get_paged_arg( 'reg_page' );
		$offset   = ( $page - 1 ) * $per_page;
		$logs     = SPPR_Database::get_registration_logs( $per_page + 1, $offset );
		$has_next = count( $logs ) > $per_page;
		if ( $has_next ) {
			array_pop( $logs );
		}

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No registration activity yet.', 'sportspress-player-registration' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Customer', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Player', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Season', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'sportspress-player-registration' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$action_text = $log->action;
			if ( $log->action === 'player_found_by_email' ) {
				$action_text = 'Found by Email';
			} elseif ( $log->action === 'player_found_by_name' ) {
				$action_text = 'Found by Name';
			} elseif ( $log->action === 'player_found_by_name_and_email' ) {
				$action_text = 'Found by Name and Email';
			} elseif ( $log->action === 'player_created' ) {
				$action_text = 'Created New Player';
			} elseif ( $log->action === 'multiple_players_found_name_match_requires_email' ) {
				$action_text = 'Multiple Players Found - Email Required';
			} elseif ( $log->action === 'multiple_players_found_email_conflict' ) {
				$action_text = 'Email Conflict - Manual Review Required';
			}

			$order_id_safe  = absint( $log->order_id );
			$player_id_safe = absint( $log->player_id );
			$order_link     = esc_url( admin_url( 'post.php?post=' . $order_id_safe . '&action=edit' ) );

			echo '<tr>';
			echo '<td>' . esc_html( $log->timestamp ) . '</td>';
			echo '<td><a href="' . esc_url( $order_link ) . '">#' . esc_html( (string) $order_id_safe ) . '</a></td>';
			echo '<td>' . esc_html( $log->customer_name ) . '</td>';
			echo '<td>';
			if ( $player_id_safe ) {
				$player_link = esc_url( admin_url( 'post.php?post=' . $player_id_safe . '&action=edit' ) );
				echo '<a href="' . esc_url( $player_link ) . '">' . esc_html( get_the_title( $player_id_safe ) ) . '</a>';
			} else {
				echo '—';
			}
			echo '</td>';
			echo '<td>' . esc_html( $log->season ) . '</td>';
			echo '<td>' . esc_html( $action_text ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->render_pagination( $page, $has_next, 'reg_page' );
	}

	/**
	 * Read a paginated `paged`-style integer arg from $_GET safely.
	 *
	 * Uses `filter_input` to read the value as an int so the taint source
	 * `$_GET[...]` does not flow into rendered HTML.
	 *
	 * @param string $param Query-arg name (e.g. `reg_page`, `role_page`).
	 * @return int Sanitized page number, minimum 1.
	 */
	private function get_paged_arg( $param ) {
		$value = filter_input( INPUT_GET, $param, FILTER_VALIDATE_INT );
		if ( ! is_int( $value ) || $value < 1 ) {
			return 1;
		}
		return $value;
	}

	private function render_pagination( $page, $has_next, $param ) {
		$base = remove_query_arg( $param );
		echo '<div class="tablenav bottom"><div class="tablenav-pages">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $param, $page - 1, $base ) ) . '">&laquo; ' . esc_html__( 'Previous', 'sportspress-player-registration' ) . '</a> ';
		}
		echo '<span class="paging-input">' . esc_html__( 'Page', 'sportspress-player-registration' ) . ' ' . esc_html( $page ) . '</span> ';
		if ( $has_next ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( $param, $page + 1, $base ) ) . '">' . esc_html__( 'Next', 'sportspress-player-registration' ) . ' &raquo;</a>';
		}
		echo '</div></div>';
	}

	private function display_role_logs() {
		$per_page = 50;
		$page     = $this->get_paged_arg( 'role_page' );
		$offset   = ( $page - 1 ) * $per_page;
		$logs     = SPPR_Database::get_role_logs( $per_page + 1, $offset );
		$has_next = count( $logs ) > $per_page;
		if ( $has_next ) {
			array_pop( $logs );
		}

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No role assignment activity yet.', 'sportspress-player-registration' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'User', 'sportspress-player-registration' ) . '</th>';
		echo '<th>' . esc_html__( 'Action', 'sportspress-player-registration' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$action_text = $log->action;
			if ( $log->action === 'role_assigned' ) {
				$action_text = 'Player Role Added';
			} elseif ( $log->action === 'role_already_exists' ) {
				$action_text = 'Role Already Present';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $log->timestamp ) . '</td>';
			echo '<td>';
			if ( $log->user_id ) {
				echo '<a href="' . esc_url( admin_url( 'user-edit.php?user_id=' . intval( $log->user_id ) ) ) . '">' . esc_html( $log->user_name ) . '</a>';
			} else {
				echo esc_html( $log->user_name );
			}
			echo '</td>';
			echo '<td>' . esc_html( $action_text ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
		$this->render_pagination( $page, $has_next, 'role_page' );
	}

	/**
	 * Add a "Re-run player registration" row action for completed orders.
	 *
	 * @param array    $actions Existing actions.
	 * @param WC_Order $order   The order object.
	 * @return array
	 */
	public function add_rerun_order_action( $actions, $order ) {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
			return $actions;
		}
		if ( $order->get_status() !== 'completed' ) {
			return $actions;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return $actions;
		}

		$order_id = $order->get_id();
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=spr_rerun_registration&order_id=' . $order_id ),
			'spr_rerun_registration_' . $order_id
		);

		$actions['spr_rerun_registration'] = array(
			'url'    => $url,
			'name'   => __( 'Re-run player registration', 'sportspress-player-registration' ),
			'action' => 'spr_rerun_registration',
		);

		return $actions;
	}

	/**
	 * Handle the re-run admin-post request: clear processed flag and re-fire the hook.
	 */
	public function handle_rerun_registration() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'sportspress-player-registration' ), '', array( 'response' => 403 ) );
		}

		$order_id = (int) filter_input( INPUT_GET, 'order_id', FILTER_VALIDATE_INT );
		if ( $order_id <= 0 ) {
			wp_die( esc_html__( 'Invalid order ID.', 'sportspress-player-registration' ), '', array( 'response' => 400 ) );
		}

		check_admin_referer( 'spr_rerun_registration_' . $order_id );

		// Double-click guard: short-lived transient prevents two near-simultaneous
		// re-runs from racing each other. Pairs with the wp_cache_add() claim in
		// SPPR_Player_Registration::process_completed_order().
		if ( get_transient( 'spr_rerun_lock_' . $order_id ) ) {
			wp_die( esc_html__( 'Already processing, please refresh.', 'sportspress-player-registration' ), '', array( 'response' => 429 ) );
		}
		set_transient( 'spr_rerun_lock_' . $order_id, 1, 10 );

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_die( esc_html__( 'Order not found.', 'sportspress-player-registration' ), '', array( 'response' => 404 ) );
		}

		$order->delete_meta_data( '_spr_processed' );
		$order->save();

		// Call the registration handler directly instead of re-firing
		// `woocommerce_order_status_completed`, which would fan out to every other
		// listener (emails, inventory, etc.). The instance is exposed by the main
		// plugin file as $GLOBALS['sportspress_player_registration'].
		$bootstrap = isset( $GLOBALS['sportspress_player_registration'] ) ? $GLOBALS['sportspress_player_registration'] : null;
		$registration = ( $bootstrap && method_exists( $bootstrap, 'get_registration' ) ) ? $bootstrap->get_registration() : null;
		if ( $registration && method_exists( $registration, 'process_completed_order' ) ) {
			$registration->process_completed_order( $order_id );
		} else {
			// Refuse to fall back to re-firing the completed-order hook — that
			// would notify every other listener (emails, inventory, etc.) for an
			// admin re-run, which is explicitly not what this action is for.
			wp_die(
				esc_html__( 'Plugin not fully initialized; please retry.', 'sportspress-player-registration' ),
				'',
				array( 'response' => 503 )
			);
		}

		$redirect = wp_get_referer();
		if ( ! $redirect ) {
			$redirect = admin_url( 'edit.php?post_type=shop_order' );
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}
