<?php
/**
 * Admin Interface Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPET_Admin {


	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_admin_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_admin_content' ) );
		add_action( 'wp_ajax_spet_reveal_webhook_secret', array( $this, 'ajax_reveal_webhook_secret' ) );
	}

	/**
	 * AJAX endpoint to reveal the webhook secret. Gated on manage_options + nonce.
	 */
	public function ajax_reveal_webhook_secret() {
		check_ajax_referer( 'spet_reveal_webhook_secret', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sportspress-etransfer-automation' ) ), 403 );
		}
		wp_send_json_success( array( 'secret' => get_option( 'spet_webhook_secret', '' ) ) );
	}

	public function add_admin_tab() {
		echo '<a href="#etransfer" class="nav-tab">' . esc_html__( 'e-Transfer', 'sportspress-etransfer-automation' ) . '</a>';
	}

	public function add_admin_content() {
		echo '<div id="etransfer" class="tab-content" style="display:none;">';
		$this->admin_page_content();
		echo '</div>';
	}

	public function admin_page_content() {
		if ( isset( $_POST['save_settings'], $_POST['spet_webhook_secret'] ) ) {
			check_admin_referer( 'spet_save_settings' );

			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to access this page.', 'sportspress-etransfer-automation' ) );
			}

			$submitted_secret = sanitize_text_field( wp_unslash( $_POST['spet_webhook_secret'] ) );
			$is_masked_secret = ( $submitted_secret !== '' && preg_match( '/^(?:\xE2\x80\xA2)+$/', $submitted_secret ) );
			$secret_saved = false;

			if ( $is_masked_secret ) {
				// User left the masked placeholder in place; do not overwrite the stored secret.
				$secret_saved = true;
			} elseif ( strlen( $submitted_secret ) < 32 ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Webhook secret must be at least 32 characters long.', 'sportspress-etransfer-automation' ) . '</p></div>';
			} else {
				update_option( 'spet_webhook_secret', $submitted_secret );
				$secret_saved = true;
			}

			// Trusted-proxy allowlist for rate limiting
			if ( isset( $_POST['spet_trusted_proxy_ips'] ) ) {
				update_option( 'spet_trusted_proxy_ips', sanitize_textarea_field( wp_unslash( $_POST['spet_trusted_proxy_ips'] ) ) );
			}

			// PII retention (days)
			if ( isset( $_POST['spet_pii_retention_days'] ) ) {
				$pii_days = max( 1, intval( $_POST['spet_pii_retention_days'] ) );
				update_option( 'spet_pii_retention_days', $pii_days );
			}

			// Validate and sanitize equivalent names
			if ( isset( $_POST['spet_equivalent_names'] ) ) {
				$equivalent_names_input = wp_unslash( $_POST['spet_equivalent_names'] );
				$equivalent_names = $this->validate_equivalent_names( $equivalent_names_input );
				update_option( 'spet_equivalent_names', $equivalent_names );
			}

			SPET_Name_Matcher::clear_cache();
			if ( $secret_saved ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Settings saved.', 'sportspress-etransfer-automation' ) . '</p></div>';
			}
		}

		$webhook_secret = get_option( 'spet_webhook_secret', wp_generate_password( 32, false ) );
		$equivalent_names = get_option( 'spet_equivalent_names', $this->get_default_equivalent_names() );
		$trusted_proxy_ips = get_option( 'spet_trusted_proxy_ips', '' );
		$pii_retention_days = intval( get_option( 'spet_pii_retention_days', 30 ) );
		if ( $pii_retention_days < 1 ) {
			$pii_retention_days = 30;
		}

		if ( empty( get_option( 'spet_webhook_secret' ) ) ) {
			update_option( 'spet_webhook_secret', $webhook_secret );
		}

		if ( empty( get_option( 'spet_equivalent_names' ) ) ) {
			update_option( 'spet_equivalent_names', $equivalent_names );
		}

		// Mask the secret in the rendered HTML. The raw secret is only delivered
		// via the AJAX endpoint gated on manage_options.
		$secret_display = empty( $webhook_secret ) ? '' : str_repeat( "\xE2\x80\xA2", 16 );
		$can_reveal_secret = current_user_can( 'manage_options' );
		$reveal_nonce = $can_reveal_secret ? wp_create_nonce( 'spet_reveal_webhook_secret' ) : '';
		?>
			<form method="post">
				<input type="hidden" name="current_tab" value="etransfer">
				<?php wp_nonce_field( 'spet_save_settings' ); ?>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'sportspress-etransfer-automation' ); ?></th>
						<td>
							<input type="text" value="<?php echo esc_attr( rest_url( 'spet/v1/etransfer-webhook' ) ); ?>" readonly class="regular-text" />
							<p class="description"><?php esc_html_e( 'Use this URL in your email forwarding service.', 'sportspress-etransfer-automation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook Secret', 'sportspress-etransfer-automation' ); ?></th>
						<td>
							<input type="password" id="spet_webhook_secret" name="spet_webhook_secret" value="<?php echo esc_attr( $secret_display ); ?>" class="regular-text" autocomplete="off" />
							<?php if ( $can_reveal_secret ) : ?>
								<button type="button" class="button" id="spet-reveal-secret" data-nonce="<?php echo esc_attr( $reveal_nonce ); ?>"><?php esc_html_e( 'Reveal', 'sportspress-etransfer-automation' ); ?></button>
								<script>
								(function(){
									var btn = document.getElementById('spet-reveal-secret');
									if (!btn) return;
									btn.addEventListener('click', function(){
										var field = document.getElementById('spet_webhook_secret');
										var data = new FormData();
										data.append('action', 'spet_reveal_webhook_secret');
										data.append('nonce', btn.getAttribute('data-nonce'));
										fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
											.then(function(r){ return r.json(); })
											.then(function(res){
												if (res && res.success && res.data && typeof res.data.secret === 'string') {
													field.type = 'text';
													field.value = res.data.secret;
													btn.disabled = true;
												}
											});
									});
								})();
								</script>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'HMAC SHA256 signing secret for webhook security. Minimum 32 characters. Leave bullets in place to keep the existing secret.', 'sportspress-etransfer-automation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Trusted Proxy IPs', 'sportspress-etransfer-automation' ); ?></th>
						<td>
							<textarea name="spet_trusted_proxy_ips" rows="3" class="large-text code"><?php echo esc_textarea( $trusted_proxy_ips ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One IP or CIDR per line. X-Forwarded-For is only honored when the request comes from a listed proxy. Leave blank to use REMOTE_ADDR only.', 'sportspress-etransfer-automation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'PII Retention (days)', 'sportspress-etransfer-automation' ); ?></th>
						<td>
							<input type="number" name="spet_pii_retention_days" min="1" max="365" value="<?php echo esc_attr( $pii_retention_days ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Webhook payload and parsed payment data are cleared from matched rows older than this many days. Row metadata (amount, reference, order ID) is retained for the full 90-day log window.', 'sportspress-etransfer-automation' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Equivalent Names', 'sportspress-etransfer-automation' ); ?></th>
						<td>
							<textarea name="spet_equivalent_names" rows="10" class="large-text code"><?php echo esc_textarea( $equivalent_names ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'List equivalent names for matching, one per line. Format: FullName|Nickname (e.g., Nicholas|Nick)', 'sportspress-etransfer-automation' ); ?><br>
								<?php esc_html_e( 'Only letters, spaces, hyphens, and apostrophes are allowed. Lines starting with # are ignored.', 'sportspress-etransfer-automation' ); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button( __( 'Save Settings', 'sportspress-etransfer-automation' ), 'primary', 'save_settings' ); ?>
			</form>
			
			<h2><?php esc_html_e( 'Webhook Activity Log', 'sportspress-etransfer-automation' ); ?></h2>
			<?php $this->display_webhook_logs(); ?>
		<?php
	}

	private function display_webhook_logs() {
		$logs = SPET_Database::get_etransfer_logs( 50 );

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No webhook activity yet.', 'sportspress-etransfer-automation' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'sportspress-etransfer-automation' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			echo '<tr>';
			echo '<td>' . esc_html( $log->timestamp ) . '</td>';
			echo '<td>' . esc_html( $log->from_name ?: $log->from_email ) . '</td>';
			echo '<td>' . esc_html( '$' . number_format( $log->amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( $log->reference_number ?: 'N/A' ) . '</td>';
			echo '<td>' . ( $log->order_id ? '<a href="' . esc_url( admin_url( 'post.php?post=' . intval( $log->order_id ) . '&action=edit' ) ) . '">#' . esc_html( $log->order_id ) . '</a>' : 'N/A' ) . '</td>';
			echo '<td>' . esc_html( $log->result ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function get_default_equivalent_names() {
		return "# Common name equivalencies - one per line\n# Format: FullName|Nickname|Nickname2\nNicholas|Nick\nRichard|Rich|Rick|Dick\nRobert|Rob|Bob|Bobby\nWilliam|Will|Bill|Billy\nJames|Jim|Jimmy\nMichael|Mike|Mikey\nDavid|Dave|Davey\nJoseph|Joe|Joey\nThomas|Tom|Tommy\nChristopher|Chris\nMatthew|Matt\nAnthony|Tony\nDaniel|Dan|Danny\nSteven|Steve|Stephen\nAndrew|Andy|Drew\nJoshua|Josh\nKenneth|Ken|Kenny\nTimothy|Tim|Timmy\nJonathan|Jon|Johnny\nAlexander|Alex|Al\nBenjamin|Ben|Benny\nZachary|Zach|Zack\nSamuel|Sam|Sammy\nPatrick|Pat|Patty\nJeffrey|Jeff\nGregory|Greg\nEdward|Ed|Eddie|Ted\nRonald|Ron|Ronnie\nDonald|Don|Donnie\nCharles|Charlie|Chuck\nElizabeth|Liz|Beth|Betty\nJennifer|Jen|Jenny\nJessica|Jess|Jessie\nSusan|Sue|Susie\nMargaret|Maggie|Meg|Peggy\nDorothy|Dot|Dottie\nDeborah|Deb|Debbie\nKatherine|Kate|Kathy|Katie\nRebecca|Becky|Becca\nPatricia|Pat|Patty|Tricia\nChristine|Chris|Christie\nSamantha|Sam|Sammy\nKimberly|Kim\nMelissa|Mel|Missy\nMichelle|Shelly\nStephanie|Steph\nAmanda|Mandy\nCatherine|Cathy|Cat\nNicole|Nikki|Nicki\nVictoria|Vicky|Tori\nAlexandra|Alex|Alexa";
	}

	private function validate_equivalent_names( $input ) {
		$input = sanitize_textarea_field( $input );
		$lines = explode( "\n", $input );
		$validated_lines = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Keep empty lines and comments
			if ( empty( $line ) || strpos( $line, '#' ) === 0 ) {
				$validated_lines[] = $line;
				continue;
			}

			// Validate the line format
			$names = explode( '|', $line );
			$valid_names = array();

			foreach ( $names as $name ) {
				$name = trim( $name );
				// Only allow letters (incl. Unicode), spaces, hyphens, apostrophes
				if ( preg_match( '/^[\p{L}\s\-\']+$/u', $name ) && strlen( $name ) <= 50 && strlen( $name ) > 0 ) {
					$valid_names[] = $name;
				}
			}

			// Only keep lines with at least 2 valid names
			if ( count( $valid_names ) >= 2 ) {
				$validated_lines[] = implode( '|', $valid_names );
			}
		}

		return implode( "\n", $validated_lines );
	}
}
