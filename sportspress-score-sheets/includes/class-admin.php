<?php
/**
 * Admin: settings, the upload form, and the ingest queue list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Admin {

	const MENU_SLUG     = 'spss-score-sheets';
	const SETTINGS_SLUG = 'spss-settings';
	const SETTINGS_GROUP = 'spss_settings';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_spss_upload_sheet', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_spss_regen_secret', array( $this, 'regenerate_secret' ) );
	}

	public function add_menu() {
		add_menu_page(
			__( 'Score Sheets', 'sportspress-score-sheets' ),
			__( 'Score Sheets', 'sportspress-score-sheets' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_queue' ),
			'dashicons-media-spreadsheet',
			58
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Queue', 'sportspress-score-sheets' ),
			__( 'Queue', 'sportspress-score-sheets' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_queue' )
		);
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Settings', 'sportspress-score-sheets' ),
			__( 'Settings', 'sportspress-score-sheets' ),
			'manage_options',
			self::SETTINGS_SLUG,
			array( $this, 'render_settings' )
		);
	}

	public function register_settings() {
		// Ordered recognition (failover) chain + confirmation set — arrays of provider ids.
		register_setting(
			self::SETTINGS_GROUP,
			'spss_primary_chain',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_provider_ids' ),
				'default'           => array(),
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_confirmation_providers',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_provider_ids' ),
				'default'           => array(),
			)
		);
		// Per-provider monthly budget cap + estimated cost per sheet.
		foreach ( array_keys( SPSS_Recognition_Manager::get_providers() ) as $pid ) {
			register_setting(
				self::SETTINGS_GROUP,
				"spss_{$pid}_monthly_budget",
				array(
					'type' => 'number',
					'sanitize_callback' => array( $this, 'sanitize_money' ),
					// Never-configured providers get a runaway guard, not "unlimited";
					// an explicit 0 (blank field) still means unlimited.
					'default' => SPSS_Budget::DEFAULT_MONTHLY_BUDGET,
				)
			);
			register_setting(
				self::SETTINGS_GROUP,
				"spss_{$pid}_cost_per_sheet",
				array(
					'type' => 'number',
					'sanitize_callback' => array( $this, 'sanitize_money' ),
					'default' => 0,
				)
			);
		}

		// Per-provider settings fields (API key, model, endpoint, base URL, …).
		// Driven by each provider's settings_fields() so a filter-registered
		// provider self-describes and its options are registered without hardcoding
		// provider taxonomy here.
		foreach ( SPSS_Recognition_Manager::get_providers() as $provider ) {
			foreach ( (array) $provider->settings_fields() as $field ) {
				if ( empty( $field['option'] ) ) {
					continue;
				}
				$option = (string) $field['option'];
				if ( ! empty( $field['secret'] ) ) {
					$sanitize = function ( $value ) use ( $option ) {
						return $this->preserve_masked_key( $value, $option );
					};
				} elseif ( 'url' === ( $field['type'] ?? '' ) ) {
					$sanitize = 'esc_url_raw';
				} else {
					$sanitize = 'sanitize_text_field';
				}
				register_setting(
					self::SETTINGS_GROUP,
					$option,
					array(
						'type'              => 'string',
						'sanitize_callback' => $sanitize,
						'default'           => '',
						// Provider config (incl. secret API keys) is only read in
						// admin/cron/REST, never on the public front end — keep it out
						// of the autoloaded alloptions blob.
						'autoload'          => false,
					)
				);
			}
		}

		// Remote intake (email Worker / custom webhook + Twilio SMS/MMS).
		//
		// NOTE: `spss_webhook_secret` is deliberately NOT registered in this group.
		// It has no form field (the settings page shows it read-only and rotates it
		// through its own admin-post button), and options.php calls
		// update_option( $option, null ) for every whitelisted option absent from
		// the POST — which would wipe the shared secret on every "Save Changes"
		// and 503 the ingest endpoint. It is written only by regenerate_secret()
		// and plugin activation.
		register_setting(
			self::SETTINGS_GROUP,
			'spss_twilio_account_sid',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_twilio_auth_token',
			array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_twilio_token' ),
				'default' => '',
				'autoload' => false,
			)
		);

		// WhatsApp Cloud API (Meta, direct). App secret + access token are secrets
		// (masked); the verify token is an operator-chosen handshake nonce shown in
		// plaintext so it can be copied into the Meta console; graph version is text.
		register_setting(
			self::SETTINGS_GROUP,
			'spss_whatsapp_app_secret',
			array(
				'type' => 'string',
				'sanitize_callback' => function ( $value ) {
					return $this->preserve_masked_key( $value, 'spss_whatsapp_app_secret' );
				},
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_whatsapp_access_token',
			array(
				'type' => 'string',
				'sanitize_callback' => function ( $value ) {
					return $this->preserve_masked_key( $value, 'spss_whatsapp_access_token' );
				},
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_whatsapp_verify_token',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_whatsapp_graph_version',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => 'v21.0',
				'autoload' => false,
			)
		);

		// Cloudflare Access service token (optional). Attached to recognition
		// requests whose host matches spss_cf_access_host, so a provider endpoint
		// behind Cloudflare Access (e.g. a self-hosted gateway) can be reached
		// non-interactively. The client secret is masked; it may also be supplied
		// via the SPSS_CF_ACCESS_CLIENT_SECRET constant (which takes precedence).
		register_setting(
			self::SETTINGS_GROUP,
			'spss_cf_access_host',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_cf_access_client_id',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
				'autoload' => false,
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_cf_access_client_secret',
			array(
				'type' => 'string',
				'sanitize_callback' => function ( $value ) {
					return $this->preserve_masked_key( $value, 'spss_cf_access_client_secret' );
				},
				'default' => '',
				'autoload' => false,
			)
		);

		register_setting(
			self::SETTINGS_GROUP,
			'spss_retention_days',
			array(
				'type' => 'integer',
				'sanitize_callback' => 'absint',
				'default' => 30,
			)
		);
	}

	/**
	 * Keep a stored secret unchanged unless the operator actually typed a new one.
	 *
	 * Secret fields render with value="" and the mask only as a *placeholder*, so
	 * the browser submits '' on every save unless the key is retyped — which is
	 * exactly the "A key is stored. Leave blank to keep it." contract the UI
	 * promises. Treating '' as a new (empty) value made every "Save Changes"
	 * silently erase every stored API key and token. A submitted value containing
	 * the mask character is likewise never a real key.
	 *
	 * Erasing a stored secret therefore needs the explicit per-field "Clear the
	 * stored value" checkbox rendered next to it.
	 */
	private function preserve_masked_key( $value, $option ) {
		$value = trim( (string) $value );

		// Explicit opt-in erase.
		if ( $this->clear_requested( $option ) ) {
			return '';
		}

		// Blank (left alone) or the mask echoed back == keep what is stored.
		if ( '' === $value || false !== strpos( $value, '•' ) ) {
			return (string) get_option( $option, '' );
		}

		return sanitize_text_field( $value );
	}

	/**
	 * Whether the settings form asked for this secret to be erased.
	 *
	 * The checkbox posts the option name into `spss_clear_secret[]`, which is not
	 * itself a registered setting — options.php ignores it and only this sanitize
	 * callback reads it. options.php has already verified the settings-group nonce
	 * and the manage_options capability before any sanitize callback runs, so no
	 * further nonce check belongs here.
	 *
	 * @param string $option Option name being sanitized.
	 * @return bool
	 */
	private function clear_requested( $option ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		if ( empty( $_POST['spss_clear_secret'] ) || ! is_array( $_POST['spss_clear_secret'] ) ) {
			return false;
		}
		$clear = array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['spss_clear_secret'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		return in_array( (string) $option, $clear, true );
	}

	public function sanitize_twilio_token( $value ) {
		return $this->preserve_masked_key( $value, 'spss_twilio_auth_token' );
	}

	/**
	 * Rotate the webhook secret (its own admin-post button, since the WP Settings
	 * API can't both display and regenerate a secret cleanly).
	 */
	public function regenerate_secret() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-score-sheets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'spss_regen_secret' );
		update_option( 'spss_webhook_secret', wp_generate_password( 40, false ) );
		wp_safe_redirect( add_query_arg( 'spss_notice', 'secret', admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) ) );
		exit;
	}

	private function masked( $option ) {
		$key = (string) get_option( $option, '' );
		return '' !== $key ? str_repeat( '•', 8 ) . substr( $key, -4 ) : '';
	}

	/**
	 * Render the "Clear the stored value" checkbox that accompanies a secret
	 * field. Submitting a secret field blank keeps the stored value, so this is
	 * the only way to erase one. Rendered only when something is actually stored.
	 *
	 * @param string $option Option name of the secret.
	 */
	private function render_clear_checkbox( $option ) {
		if ( '' === $this->masked( $option ) ) {
			return;
		}
		?>
		<p><label for="<?php echo esc_attr( $option ); ?>_clear"><input type="checkbox" name="spss_clear_secret[]" id="<?php echo esc_attr( $option ); ?>_clear" value="<?php echo esc_attr( $option ); ?>" /> <?php esc_html_e( 'Clear the stored value', 'sportspress-score-sheets' ); ?></label></p>
		<?php
	}

	/**
	 * Sanitize a checkbox-group array of provider ids down to known providers.
	 */
	public function sanitize_provider_ids( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$valid = array_keys( SPSS_Recognition_Manager::get_providers() );
		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $valid ) );
	}

	/** Clamp a money field to a non-negative float. */
	public function sanitize_money( $value ) {
		return max( 0, (float) $value );
	}

	/**
	 * Render the per-provider monthly-budget + estimated-cost-per-sheet rows,
	 * shared by every provider block.
	 */
	private function render_budget_rows( $id ) {
		$provider     = SPSS_Recognition_Manager::get_provider( $id );
		$default_cost = ( $provider && method_exists( $provider, 'estimated_cost_per_sheet' ) ) ? (float) $provider->estimated_cost_per_sheet() : 0.0;
		// Show what SPSS_Budget will actually enforce: a provider whose budget was
		// never configured is capped at the default, not unlimited.
		$budget = get_option( "spss_{$id}_monthly_budget", null );
		if ( null === $budget || false === $budget || '' === $budget ) {
			$budget = SPSS_Budget::DEFAULT_MONTHLY_BUDGET;
		}
		$cost = get_option( "spss_{$id}_cost_per_sheet", '' );
		?>
		<tr>
			<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_monthly_budget"><?php esc_html_e( 'Monthly budget ($)', 'sportspress-score-sheets' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" name="spss_<?php echo esc_attr( $id ); ?>_monthly_budget" id="spss_<?php echo esc_attr( $id ); ?>_monthly_budget" value="<?php echo esc_attr( '' === $budget || (float) $budget <= 0 ? '' : $budget ); ?>" />
				<p class="description"><?php esc_html_e( 'Blank / 0 = unlimited. When the estimated spend would exceed this in a calendar month, recognition fails over to the next provider in the chain.', 'sportspress-score-sheets' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet"><?php esc_html_e( 'Est. cost per sheet ($)', 'sportspress-score-sheets' ); ?></label></th>
			<td>
				<input type="number" step="0.001" min="0" name="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet" id="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet" value="<?php echo esc_attr( '' === $cost || (float) $cost <= 0 ? '' : $cost ); ?>" placeholder="<?php echo esc_attr( number_format( $default_cost, 3 ) ); ?>" />
				<p class="description"><?php esc_html_e( 'Used only to meter the budget above (spend is estimated, not billed). Blank uses the provider default shown.', 'sportspress-score-sheets' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$providers = SPSS_Recognition_Manager::get_providers();
		$chain   = SPSS_Recognition_Manager::get_primary_chain();
		$confirm = SPSS_Recognition_Manager::get_confirmation_ids();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Score Sheets — Settings', 'sportspress-score-sheets' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Score-sheet images are sent to the selected recognition provider for transcription. No values are written to SportsPress until an admin reviews and confirms them.', 'sportspress-score-sheets' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( self::SETTINGS_GROUP ); ?>

				<h2><?php esc_html_e( 'Recognition chain (lead + failover)', 'sportspress-score-sheets' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Providers to try', 'sportspress-score-sheets' ); ?></th>
						<td>
							<input type="hidden" name="spss_primary_chain[]" value="" />
							<?php foreach ( $providers as $id => $p ) : ?>
								<label><input type="checkbox" name="spss_primary_chain[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $chain, true ) ); ?> /> <?php echo esc_html( $p->get_label() ); ?><?php echo $p->is_configured() ? '' : ' <span class="description">' . esc_html__( '(not configured)', 'sportspress-score-sheets' ) . '</span>'; ?></label><br />
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'The lead result comes from the first checked provider that is configured, within budget, and succeeds; the others are automatic fallbacks, tried in the order listed above.', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Confirmation (cross-check)', 'sportspress-score-sheets' ); ?></th>
						<td>
							<input type="hidden" name="spss_confirmation_providers[]" value="" />
							<?php foreach ( $providers as $id => $p ) : ?>
								<label><input type="checkbox" name="spss_confirmation_providers[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $confirm, true ) ); ?> /> <?php echo esc_html( $p->get_label() ); ?></label><br />
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Each checked provider also reads the sheet; any disagreement with the lead result is flagged for review. Uses extra API calls and budget.', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
				</table>

				<?php foreach ( $providers as $id => $p ) : ?>
					<?php $fields = method_exists( $p, 'settings_fields' ) ? (array) $p->settings_fields() : array(); ?>
					<h2><?php echo esc_html( $p->get_label() ); ?></h2>
					<table class="form-table" role="presentation">
						<?php foreach ( $fields as $field ) : ?>
							<?php
							$option     = (string) ( $field['option'] ?? '' );
							$ftype      = (string) ( $field['type'] ?? 'text' );
							$is_secret  = ! empty( $field['secret'] );
							$input_type = in_array( $ftype, array( 'password', 'url', 'number', 'text' ), true ) ? $ftype : 'text';
							if ( $is_secret ) {
								$value       = '';
								$placeholder = $this->masked( $option );
								$desc        = '' !== $this->masked( $option ) ? __( 'A key is stored. Leave blank to keep it.', 'sportspress-score-sheets' ) : (string) ( $field['description'] ?? '' );
							} elseif ( 'spss_' . $id . '_model' === $option && method_exists( $p, 'get_model' ) ) {
								$value       = (string) $p->get_model();
								$placeholder = (string) ( $field['placeholder'] ?? '' );
								$desc        = (string) ( $field['description'] ?? '' );
							} else {
								$value       = (string) get_option( $option, '' );
								$placeholder = (string) ( $field['placeholder'] ?? '' );
								$desc        = (string) ( $field['description'] ?? '' );
							}
							?>
							<tr>
								<th scope="row"><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $field['label'] ?? $option ); ?></label></th>
								<td>
									<input type="<?php echo esc_attr( $input_type ); ?>" name="<?php echo esc_attr( $option ); ?>" id="<?php echo esc_attr( $option ); ?>" class="regular-text" <?php echo $is_secret ? 'autocomplete="off" ' : ''; ?>placeholder="<?php echo esc_attr( $placeholder ); ?>" value="<?php echo esc_attr( $value ); ?>" />
									<?php if ( '' !== $desc ) : ?>
										<p class="description"><?php echo esc_html( $desc ); ?></p>
									<?php endif; ?>
									<?php
									if ( $is_secret ) {
										$this->render_clear_checkbox( $option );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
						<?php $this->render_budget_rows( $id ); ?>
					</table>
				<?php endforeach; ?>

				<h2><?php esc_html_e( 'Remote intake (email / SMS / webhook)', 'sportspress-score-sheets' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Optional. Let sheets arrive by webhook, emailed photo (Cloudflare Worker), or Twilio SMS/MMS — all land in the same review queue. See assets/remote-intake.md for setup. Every remote sheet still requires human review before it is written.', 'sportspress-score-sheets' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Webhook URL', 'sportspress-score-sheets' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'spss/v1/ingest' ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_webhook_secret_display"><?php esc_html_e( 'Webhook secret', 'sportspress-score-sheets' ); ?></label></th>
						<td>
							<input type="text" id="spss_webhook_secret_display" class="regular-text code" readonly="readonly" value="<?php echo esc_attr( get_option( 'spss_webhook_secret', '' ) ); ?>" onfocus="this.select();" />
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=spss_regen_secret' ), 'spss_regen_secret' ) ); ?>"><?php esc_html_e( 'Regenerate', 'sportspress-score-sheets' ); ?></a>
							<p class="description"><?php esc_html_e( 'Shared with the email Worker / any custom sender. Requests are HMAC-SHA256 signed (timestamp.body); 300s replay window.', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Twilio webhook URL', 'sportspress-score-sheets' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'spss/v1/twilio' ) ); ?></code></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_twilio_account_sid"><?php esc_html_e( 'Twilio Account SID', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="text" name="spss_twilio_account_sid" id="spss_twilio_account_sid" class="regular-text" value="<?php echo esc_attr( get_option( 'spss_twilio_account_sid', '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_twilio_auth_token"><?php esc_html_e( 'Twilio Auth Token', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="password" name="spss_twilio_auth_token" id="spss_twilio_auth_token" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( 'spss_twilio_auth_token' ) ); ?>" value="" /><?php $this->render_clear_checkbox( 'spss_twilio_auth_token' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WhatsApp webhook URL (Meta)', 'sportspress-score-sheets' ); ?></th>
						<td>
							<code><?php echo esc_html( rest_url( 'spss/v1/whatsapp' ) ); ?></code>
							<p class="description"><?php esc_html_e( 'Direct WhatsApp Cloud API (no Twilio). Set this as the Callback URL in the Meta app\'s WhatsApp webhook config, subscribe to the "messages" field, and use the verify token below.', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_whatsapp_verify_token"><?php esc_html_e( 'WhatsApp verify token', 'sportspress-score-sheets' ); ?></label></th>
						<td>
							<input type="text" name="spss_whatsapp_verify_token" id="spss_whatsapp_verify_token" class="regular-text" value="<?php echo esc_attr( get_option( 'spss_whatsapp_verify_token', '' ) ); ?>" />
							<p class="description"><?php esc_html_e( 'Any string you choose; enter the same value in the Meta webhook config. Used only for the one-time verification handshake.', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_whatsapp_app_secret"><?php esc_html_e( 'WhatsApp app secret', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="password" name="spss_whatsapp_app_secret" id="spss_whatsapp_app_secret" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( 'spss_whatsapp_app_secret' ) ); ?>" value="" /><p class="description"><?php esc_html_e( 'Meta app secret — validates the X-Hub-Signature-256 on every inbound webhook.', 'sportspress-score-sheets' ); ?></p><?php $this->render_clear_checkbox( 'spss_whatsapp_app_secret' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_whatsapp_access_token"><?php esc_html_e( 'WhatsApp access token', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="password" name="spss_whatsapp_access_token" id="spss_whatsapp_access_token" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( 'spss_whatsapp_access_token' ) ); ?>" value="" /><p class="description"><?php esc_html_e( 'Cloud API token (a permanent System User token is recommended) — used as the Bearer to download media.', 'sportspress-score-sheets' ); ?></p><?php $this->render_clear_checkbox( 'spss_whatsapp_access_token' ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_whatsapp_graph_version"><?php esc_html_e( 'Graph API version', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="text" name="spss_whatsapp_graph_version" id="spss_whatsapp_graph_version" class="small-text" value="<?php echo esc_attr( get_option( 'spss_whatsapp_graph_version', 'v21.0' ) ); ?>" placeholder="v21.0" /></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Cloudflare Access (optional)', 'sportspress-score-sheets' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'If a recognition provider\'s endpoint sits behind Cloudflare Access, create an Access service token (Zero Trust → Access → Service Auth), allow it in that application\'s policy, and set its host + credentials here. The two CF-Access headers are then sent on recognition requests to that host, alongside the provider\'s own API key. Leave the host blank to disable.', 'sportspress-score-sheets' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spss_cf_access_host"><?php esc_html_e( 'Access-protected host', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="text" name="spss_cf_access_host" id="spss_cf_access_host" class="regular-text" value="<?php echo esc_attr( get_option( 'spss_cf_access_host', '' ) ); ?>" placeholder="litellm.example.com" /><p class="description"><?php esc_html_e( 'Hostname only (no scheme/path). Headers are attached only to requests whose host matches this exactly.', 'sportspress-score-sheets' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_cf_access_client_id"><?php esc_html_e( 'CF-Access-Client-Id', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="text" name="spss_cf_access_client_id" id="spss_cf_access_client_id" class="regular-text" autocomplete="off" value="<?php echo esc_attr( get_option( 'spss_cf_access_client_id', '' ) ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_cf_access_client_secret"><?php esc_html_e( 'CF-Access-Client-Secret', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="password" name="spss_cf_access_client_secret" id="spss_cf_access_client_secret" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( 'spss_cf_access_client_secret' ) ); ?>" value="" /><p class="description"><?php esc_html_e( 'May instead be defined as the SPSS_CF_ACCESS_CLIENT_SECRET constant in wp-config.php (which takes precedence).', 'sportspress-score-sheets' ); ?></p><?php $this->render_clear_checkbox( 'spss_cf_access_client_secret' ); ?></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Retention', 'sportspress-score-sheets' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spss_retention_days"><?php esc_html_e( 'Retain processed sheets (days)', 'sportspress-score-sheets' ); ?></label></th>
						<td><input type="number" min="1" max="365" name="spss_retention_days" id="spss_retention_days" value="<?php echo esc_attr( (int) get_option( 'spss_retention_days', 30 ) ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function render_queue() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$primary_ok = SPSS_Recognition_Manager::has_usable_primary();
		$events     = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 100,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);
		$sheets = SPSS_Database::get_sheets( '', 50 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Score Sheets', 'sportspress-score-sheets' ); ?></h1>

			<?php if ( ! $primary_ok ) : ?>
				<div class="notice notice-warning"><p>
					<?php
					printf(
						/* translators: %s: settings URL */
						wp_kses_post( __( 'The recognition provider is not configured. <a href="%s">Add an API key in Settings</a> before uploading.', 'sportspress-score-sheets' ) ),
						esc_url( admin_url( 'admin.php?page=' . self::SETTINGS_SLUG ) )
					);
					?>
				</p></div>
			<?php endif; ?>

			<?php $this->render_notices(); ?>

			<h2><?php esc_html_e( 'Upload a score sheet', 'sportspress-score-sheets' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="spss_upload_sheet" />
				<?php wp_nonce_field( 'spss_upload_sheet', 'spss_upload_nonce' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="spss_event_id"><?php esc_html_e( 'Game (for roster matching)', 'sportspress-score-sheets' ); ?></label></th>
						<td>
							<select name="event_id" id="spss_event_id">
								<option value="0"><?php esc_html_e( '— Select later during review —', 'sportspress-score-sheets' ); ?></option>
								<?php foreach ( $events as $ev ) : ?>
									<option value="<?php echo esc_attr( $ev->ID ); ?>"><?php echo esc_html( get_the_title( $ev ) . ' — ' . get_the_date( '', $ev ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Selecting the game lets the reader match jersey numbers to your roster (recommended).', 'sportspress-score-sheets' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="spss_sheet_file"><?php esc_html_e( 'Photo of the sheet', 'sportspress-score-sheets' ); ?></label></th>
						<td>
							<input type="file" name="sheet" id="spss_sheet_file" accept="image/*" required />
							<p class="description">
								<?php
								printf(
									/* translators: %s: comma-separated list of accepted file extensions */
									esc_html__( 'Accepted on this server: %s. (HEIC and PDF are listed only when this server can decode them.)', 'sportspress-score-sheets' ),
									esc_html( implode( ', ', self::supported_uploads()['extensions'] ) )
								);
								?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Upload &amp; read', 'sportspress-score-sheets' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent sheets', 'sportspress-score-sheets' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Uploaded', 'sportspress-score-sheets' ); ?></th>
					<th><?php esc_html_e( 'Channel', 'sportspress-score-sheets' ); ?></th>
					<th><?php esc_html_e( 'Status', 'sportspress-score-sheets' ); ?></th>
					<th><?php esc_html_e( 'Event', 'sportspress-score-sheets' ); ?></th>
					<th></th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $sheets ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No sheets yet.', 'sportspress-score-sheets' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sheets as $s ) : ?>
						<tr>
							<td><?php echo esc_html( get_date_from_gmt( $s->created_at, 'Y-m-d H:i' ) ); ?></td>
							<td><?php echo esc_html( $s->channel ); ?></td>
							<td><span class="spss-status spss-status-<?php echo esc_attr( $s->status ); ?>"><?php echo esc_html( $this->status_label( $s->status ) ); ?></span></td>
							<td><?php echo $s->event_id ? esc_html( get_the_title( (int) $s->event_id ) ) : '&mdash;'; ?></td>
							<td>
								<?php if ( SPSS_Database::STATUS_PENDING_REVIEW === $s->status ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . SPSS_Review_Admin::PAGE_SLUG . '&sheet_id=' . (int) $s->id ) ); ?>"><?php esc_html_e( 'Review', 'sportspress-score-sheets' ); ?></a>
								<?php elseif ( SPSS_Database::STATUS_FAILED === $s->status ) : ?>
									<span class="description"><?php echo esc_html( $s->error ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function status_label( $status ) {
		$labels = array(
			SPSS_Database::STATUS_QUEUED         => __( 'Queued', 'sportspress-score-sheets' ),
			SPSS_Database::STATUS_PROCESSING     => __( 'Reading…', 'sportspress-score-sheets' ),
			SPSS_Database::STATUS_PENDING_REVIEW => __( 'Needs review', 'sportspress-score-sheets' ),
			SPSS_Database::STATUS_CONFIRMED      => __( 'Applied', 'sportspress-score-sheets' ),
			SPSS_Database::STATUS_FAILED         => __( 'Failed', 'sportspress-score-sheets' ),
			SPSS_Database::STATUS_DUPLICATE      => __( 'Duplicate', 'sportspress-score-sheets' ),
		);
		return $labels[ $status ] ?? $status;
	}

	private function render_notices() {
		$notice = isset( $_GET['spss_notice'] ) ? sanitize_key( wp_unslash( $_GET['spss_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' === $notice ) {
			return;
		}
		// Applied, but the writer refused one or more reviewer-confirmed player
		// rows — carries a count, so it can't live in the static map below.
		if ( 'applied_partial' === $notice ) {
			$count = isset( $_GET['spss_skipped'] ) ? absint( $_GET['spss_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			printf(
				'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: %d: number of confirmed player rows that were not written */
						_n(
							'Results applied, but %d confirmed player row was not written because that player is not on the team it was attributed to. Fix the roster (or the row) and confirm again.',
							'Results applied, but %d confirmed player rows were not written because those players are not on the teams they were attributed to. Fix the roster (or the rows) and confirm again.',
							$count,
							'sportspress-score-sheets'
						),
						$count
					)
				)
			);
			return;
		}
		$map = array(
			'uploaded'  => array( 'success', __( 'Sheet uploaded — reading in the background. Refresh in a moment.', 'sportspress-score-sheets' ) ),
			'duplicate' => array( 'warning', __( 'That image was already submitted.', 'sportspress-score-sheets' ) ),
			'error'     => array( 'error', __( 'Upload failed. Please try a different photo.', 'sportspress-score-sheets' ) ),
			'applied'   => array( 'success', __( 'Results applied to the event.', 'sportspress-score-sheets' ) ),
		);
		if ( isset( $map[ $notice ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $notice ][0] ), esc_html( $map[ $notice ][1] ) );
		}
	}

	/**
	 * Image types this server can actually decode.
	 *
	 * HEIC (the iPhone camera default) and PDF passed the upload validator but
	 * then died in wp_get_image_editor() unless the installed editor really
	 * supports them — accepting them blindly turned an iPhone photo into an
	 * unexplained "Upload failed". Probe the editor instead of advertising a
	 * hardcoded list, with jpeg/png as a floor so the list can never be empty.
	 *
	 * @return array { extensions:string[], mime_types:string[] }
	 */
	private static function supported_uploads() {
		// Probing instantiates image editors, and both the queue render and the
		// upload handler ask — resolve once per request.
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$candidates = array(
			'image/jpeg'      => array( 'jpg', 'jpeg' ),
			'image/png'       => array( 'png' ),
			'image/webp'      => array( 'webp' ),
			'image/heic'      => array( 'heic' ),
			'application/pdf' => array( 'pdf' ),
		);

		$mime_types = array();
		$extensions = array();
		foreach ( $candidates as $mime => $exts ) {
			if ( ! wp_image_editor_supports( array( 'mime_type' => $mime ) ) ) {
				continue;
			}
			$mime_types[] = $mime;
			$extensions   = array_merge( $extensions, $exts );
		}

		if ( empty( $mime_types ) ) {
			$mime_types = array( 'image/jpeg', 'image/png' );
			$extensions = array( 'jpg', 'jpeg', 'png' );
		}

		$cached = array(
			'extensions' => $extensions,
			'mime_types' => $mime_types,
		);
		return $cached;
	}

	public function handle_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-score-sheets' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'spss_upload_sheet', 'spss_upload_nonce' );

		$redirect = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		if ( empty( $_FILES['sheet'] ) || ! isset( $_FILES['sheet']['tmp_name'] ) ) {
			wp_safe_redirect( add_query_arg( 'spss_notice', 'error', $redirect ) );
			exit;
		}

		$file = array(
			'name'     => isset( $_FILES['sheet']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['sheet']['name'] ) ) : '',
			'type'     => isset( $_FILES['sheet']['type'] ) ? sanitize_text_field( wp_unslash( $_FILES['sheet']['type'] ) ) : '',
			'tmp_name' => isset( $_FILES['sheet']['tmp_name'] ) ? $_FILES['sheet']['tmp_name'] : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'error'    => isset( $_FILES['sheet']['error'] ) ? (int) $_FILES['sheet']['error'] : UPLOAD_ERR_NO_FILE,
			'size'     => isset( $_FILES['sheet']['size'] ) ? (int) $_FILES['sheet']['size'] : 0,
		);

		$supported = self::supported_uploads();
		$valid     = SPAT_Upload_Validator::validate(
			$file,
			array(
				'allowed_extensions' => $supported['extensions'],
				'allowed_mime_types' => $supported['mime_types'],
				'max_bytes'          => 15 * 1024 * 1024,
			)
		);
		if ( is_wp_error( $valid ) ) {
			wp_safe_redirect( add_query_arg( 'spss_notice', 'error', $redirect ) );
			exit;
		}

		$result = SPSS_Ingest_Service::accept_image(
			array(
				'tmp_path' => $file['tmp_name'],
				'ext'      => strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) ),
				'channel'  => 'upload',
				'event_id' => isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0,
			)
		);

		if ( is_wp_error( $result ) ) {
			$notice = ( 'spss_duplicate_sheet' === $result->get_error_code() ) ? 'duplicate' : 'error';
			wp_safe_redirect( add_query_arg( 'spss_notice', $notice, $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'spss_notice', 'uploaded', $redirect ) );
		exit;
	}
}
