<?php
/**
 * Email Notifications for SportsPress Admin Tools
 *
 * Sends WordPress emails when key events occur across child plugins.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Notifications {

	public function __construct() {
		// Register settings via parent admin hook
		add_action( 'spat_admin_init_settings', array( $this, 'register_settings' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'render_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'render_tab_content' ) );

		// Hook into child plugin events
		add_action( 'spat_payment_matched', array( $this, 'notify_payment_matched' ), 10, 3 );
		add_action( 'spat_payment_unmatched', array( $this, 'notify_payment_unmatched' ), 10, 3 );
		add_action( 'spat_player_registered', array( $this, 'notify_player_registered' ), 10, 3 );
		add_action( 'spat_schedule_generated', array( $this, 'notify_schedule_generated' ), 10, 2 );
	}

	/**
	 * Register notification settings
	 */
	public function register_settings() {
		$settings = array(
			'spat_notifications_enabled'       => array( 'sanitize_callback' => array( $this, 'sanitize_toggle' ) ),
			'spat_notification_email'          => array( 'sanitize_callback' => 'sanitize_email' ),
			'spat_notify_payment_matched'      => array( 'sanitize_callback' => array( $this, 'sanitize_toggle' ) ),
			'spat_notify_payment_unmatched'    => array( 'sanitize_callback' => array( $this, 'sanitize_toggle' ) ),
			'spat_notify_player_registered'    => array( 'sanitize_callback' => array( $this, 'sanitize_toggle' ) ),
			'spat_notify_schedule_generated'   => array( 'sanitize_callback' => array( $this, 'sanitize_toggle' ) ),
		);

		foreach ( $settings as $name => $args ) {
			register_setting( 'spat_notification_settings', $name, $args );
		}

		add_settings_section(
			'spat_notification_section',
			__( 'Notification Settings', 'sportspress-admin-tools' ),
			function () {
				echo '<p>' . esc_html__( 'Configure email notifications for key events across child plugins.', 'sportspress-admin-tools' ) . '</p>';
			},
			'spat_notification_settings'
		);

		add_settings_field( 'spat_notifications_enabled', __( 'Enable Notifications', 'sportspress-admin-tools' ), array( $this, 'render_master_toggle' ), 'spat_notification_settings', 'spat_notification_section' );
		add_settings_field( 'spat_notification_email', __( 'Recipient Email', 'sportspress-admin-tools' ), array( $this, 'render_email_field' ), 'spat_notification_settings', 'spat_notification_section' );
		add_settings_field( 'spat_notify_payment_matched', __( 'Payment Matched', 'sportspress-admin-tools' ), array( $this, 'render_toggle_payment_matched' ), 'spat_notification_settings', 'spat_notification_section' );
		add_settings_field( 'spat_notify_payment_unmatched', __( 'Payment Unmatched', 'sportspress-admin-tools' ), array( $this, 'render_toggle_payment_unmatched' ), 'spat_notification_settings', 'spat_notification_section' );
		add_settings_field( 'spat_notify_player_registered', __( 'Player Registered', 'sportspress-admin-tools' ), array( $this, 'render_toggle_player_registered' ), 'spat_notification_settings', 'spat_notification_section' );
		add_settings_field( 'spat_notify_schedule_generated', __( 'Schedule Generated', 'sportspress-admin-tools' ), array( $this, 'render_toggle_schedule_generated' ), 'spat_notification_settings', 'spat_notification_section' );
	}

	public function sanitize_toggle( $value ) {
		return $value === '1' ? '1' : '0';
	}

	// --- Settings field renderers ---

	public function render_master_toggle() {
		$enabled = get_option( 'spat_notifications_enabled', '0' );
		echo '<input type="checkbox" name="spat_notifications_enabled" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html__( 'Master toggle for all email notifications.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function render_email_field() {
		$email = get_option( 'spat_notification_email', get_option( 'admin_email' ) );
		echo '<input type="email" name="spat_notification_email" value="' . esc_attr( $email ) . '" class="regular-text">';
		echo '<p class="description">' . esc_html__( 'Email address to receive notifications. Defaults to admin email.', 'sportspress-admin-tools' ) . '</p>';
	}

	public function render_toggle_payment_matched() {
		$this->render_toggle( 'spat_notify_payment_matched', __( 'Notify when an e-transfer payment is matched to an order.', 'sportspress-admin-tools' ) );
	}

	public function render_toggle_payment_unmatched() {
		$this->render_toggle( 'spat_notify_payment_unmatched', __( 'Notify when an e-transfer webhook arrives but no order matches.', 'sportspress-admin-tools' ) );
	}

	public function render_toggle_player_registered() {
		$this->render_toggle( 'spat_notify_player_registered', __( 'Notify when a new player is created via registration.', 'sportspress-admin-tools' ) );
	}

	public function render_toggle_schedule_generated() {
		$this->render_toggle( 'spat_notify_schedule_generated', __( 'Notify when schedule generation completes.', 'sportspress-admin-tools' ) );
	}

	private function render_toggle( $option, $description ) {
		// Per-event notifications are opt-in (default off) so enabling the master
		// toggle alone never silently fires categories the operator didn't choose.
		// Must match the default used in is_enabled().
		$enabled = get_option( $option, '0' );
		echo '<input type="checkbox" name="' . esc_attr( $option ) . '" value="1" ' . checked( $enabled, '1', false ) . '>';
		echo '<p class="description">' . esc_html( $description ) . '</p>';
	}

	// --- Tab rendering ---

	public function render_tab() {
		echo '<a href="#notifications" class="nav-tab">' . esc_html__( 'Notifications', 'sportspress-admin-tools' ) . '</a>';
	}

	public function render_tab_content() {
		?>
		<div id="notifications" class="tab-content" style="display:none;">
			<form action="options.php" method="post">
				<input type="hidden" name="current_tab" value="notifications">
				<?php
				settings_fields( 'spat_notification_settings' );
				do_settings_sections( 'spat_notification_settings' );
				submit_button( __( 'Save Settings', 'sportspress-admin-tools' ) );
				?>
			</form>
		</div>
		<?php
	}

	// --- Notification handlers ---

	/**
	 * Payment matched notification
	 *
	 * @param string $player_name Customer/player name
	 * @param float  $amount      Payment amount
	 * @param int    $order_id    WooCommerce order ID
	 */
	public function notify_payment_matched( $player_name, $amount, $order_id ) {
		if ( ! $this->is_enabled( 'spat_notify_payment_matched' ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] Payment Matched - Order #%d',
			get_bloginfo( 'name' ),
			$order_id
		);

		$body = $this->build_email(
			__( 'Payment Matched', 'sportspress-admin-tools' ),
			sprintf(
				'<p>' . __( 'An e-transfer payment has been matched to an order.', 'sportspress-admin-tools' ) . '</p>'
				. '<table>%s</table>',
				$this->row( __( 'Player', 'sportspress-admin-tools' ), $player_name )
				. $this->row( __( 'Amount', 'sportspress-admin-tools' ), '$' . number_format( (float) $amount, 2 ) )
				. $this->row( __( 'Order ID', 'sportspress-admin-tools' ), '#' . intval( $order_id ) )
			)
		);

		$this->send( $subject, $body );
	}

	/**
	 * Payment unmatched notification
	 *
	 * @param string $sender_name     Sender name from e-transfer
	 * @param float  $amount          Payment amount
	 * @param string $reference_number Reference number
	 */
	public function notify_payment_unmatched( $sender_name, $amount, $reference_number ) {
		if ( ! $this->is_enabled( 'spat_notify_payment_unmatched' ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] Payment Unmatched - %s',
			get_bloginfo( 'name' ),
			$reference_number
		);

		$body = $this->build_email(
			__( 'Payment Unmatched', 'sportspress-admin-tools' ),
			sprintf(
				'<p>' . __( 'An e-transfer webhook was received but no matching order was found.', 'sportspress-admin-tools' ) . '</p>'
				. '<table>%s</table>',
				$this->row( __( 'Sender', 'sportspress-admin-tools' ), $sender_name )
				. $this->row( __( 'Amount', 'sportspress-admin-tools' ), '$' . number_format( (float) $amount, 2 ) )
				. $this->row( __( 'Reference', 'sportspress-admin-tools' ), $reference_number )
			)
		);

		$this->send( $subject, $body );
	}

	/**
	 * Player registered notification
	 *
	 * @param string $player_name Player name
	 * @param string $team        Team name
	 * @param string $season      Season identifier
	 */
	public function notify_player_registered( $player_name, $team, $season ) {
		if ( ! $this->is_enabled( 'spat_notify_player_registered' ) ) {
			return;
		}

		$subject = sprintf(
			'[%s] Player Registered - %s',
			get_bloginfo( 'name' ),
			$player_name
		);

		$body = $this->build_email(
			__( 'Player Registered', 'sportspress-admin-tools' ),
			sprintf(
				'<p>' . __( 'A new player has been created via registration.', 'sportspress-admin-tools' ) . '</p>'
				. '<table>%s</table>',
				$this->row( __( 'Player', 'sportspress-admin-tools' ), $player_name )
				. $this->row( __( 'Team', 'sportspress-admin-tools' ), $team ?: '—' )
				. $this->row( __( 'Season', 'sportspress-admin-tools' ), $season ?: '—' )
			)
		);

		$this->send( $subject, $body );
	}

	/**
	 * Schedule generated notification
	 *
	 * @param string $schedule_id Schedule identifier
	 * @param array  $stats       Generation statistics
	 */
	public function notify_schedule_generated( $schedule_id, $stats ) {
		if ( ! $this->is_enabled( 'spat_notify_schedule_generated' ) ) {
			return;
		}

		$game_count = isset( $stats['games_scheduled'] ) ? intval( $stats['games_scheduled'] ) : 0;
		$date_range = '';
		if ( ! empty( $stats['first_date'] ) && ! empty( $stats['last_date'] ) ) {
			$date_range = $stats['first_date'] . ' — ' . $stats['last_date'];
		}

		$subject = sprintf(
			'[%s] Schedule Generated - %d games',
			get_bloginfo( 'name' ),
			$game_count
		);

		$body = $this->build_email(
			__( 'Schedule Generated', 'sportspress-admin-tools' ),
			sprintf(
				'<p>' . __( 'A new schedule has been generated.', 'sportspress-admin-tools' ) . '</p>'
				. '<table>%s</table>',
				$this->row( __( 'Games', 'sportspress-admin-tools' ), intval( $game_count ) )
				. ( $date_range ? $this->row( __( 'Date Range', 'sportspress-admin-tools' ), $date_range ) : '' )
				. $this->row( __( 'Schedule ID', 'sportspress-admin-tools' ), $schedule_id )
			)
		);

		$this->send( $subject, $body );
	}

	// --- Helpers ---

	private function is_enabled( $option ) {
		// Per-event default off — must match render_toggle() so an unsaved
		// per-event setting is treated as "not opted in", not "on".
		return get_option( 'spat_notifications_enabled', '0' ) === '1'
			&& get_option( $option, '0' ) === '1';
	}

	private function get_recipient() {
		$email = get_option( 'spat_notification_email', '' );
		return ! empty( $email ) ? $email : get_option( 'admin_email' );
	}

	private function send( $subject, $body ) {
		$to = $this->get_recipient();
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		wp_mail( $to, $subject, $body, $headers );
	}

	private function row( $label, $value ) {
		return '<tr><td style="padding:6px 12px;font-weight:bold;color:#333;">' . esc_html( $label ) . '</td>'
			 . '<td style="padding:6px 12px;color:#555;">' . esc_html( $value ) . '</td></tr>';
	}

	private function build_email( $title, $content ) {
		$site_name = get_bloginfo( 'name' );
		return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;">'
			. '<table width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;padding:20px 0;">'
			. '<tr><td align="center">'
			. '<table width="600" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:6px;overflow:hidden;border:1px solid #ddd;">'
			. '<tr><td style="background:#0073aa;padding:16px 24px;color:#fff;font-size:18px;font-weight:bold;">'
			. esc_html( $site_name ) . ' — ' . esc_html( $title )
			. '</td></tr>'
			. '<tr><td style="padding:24px;">' . $content . '</td></tr>'
			. '<tr><td style="padding:12px 24px;background:#f9f9f9;color:#999;font-size:12px;border-top:1px solid #eee;">'
			. esc_html__( 'This is an automated notification from SportsPress Admin Tools.', 'sportspress-admin-tools' )
			. '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
