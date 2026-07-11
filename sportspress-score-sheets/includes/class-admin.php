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
					'default' => 0,
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

		// Hosted LLM providers: a (masked) API key + an editable model id each.
		// The model default is '' so the provider falls back to its own
		// default_model(); the field displays the effective value via get_model().
		foreach ( array( 'claude', 'gemini', 'openai', 'openrouter' ) as $id ) {
			register_setting(
				self::SETTINGS_GROUP,
				"spss_{$id}_api_key",
				array(
					'type' => 'string',
					'sanitize_callback' => array( $this, 'sanitize_' . $id . '_key' ),
					'default' => '',
				)
			);
			register_setting(
				self::SETTINGS_GROUP,
				"spss_{$id}_model",
				array(
					'type' => 'string',
					'sanitize_callback' => 'sanitize_text_field',
					'default' => '',
				)
			);
		}
		// Aggregator gateway base URL (OpenRouter by default; any OpenAI-compatible endpoint).
		register_setting(
			self::SETTINGS_GROUP,
			'spss_openrouter_base_url',
			array(
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
			)
		);

		// Self-hosted sidecar: endpoint + optional model + optional bearer key.
		register_setting(
			self::SETTINGS_GROUP,
			'spss_selfhosted_endpoint',
			array(
				'type' => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default' => '',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_selfhosted_model',
			array(
				'type' => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default' => '',
			)
		);
		register_setting(
			self::SETTINGS_GROUP,
			'spss_selfhosted_key',
			array(
				'type' => 'string',
				'sanitize_callback' => array( $this, 'sanitize_selfhosted_key' ),
				'default' => '',
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
	 * Keep a stored secret unchanged when the field is submitted with its masked
	 * placeholder (so we never overwrite a real key with bullet characters).
	 */
	private function preserve_masked_key( $value, $option ) {
		$value = trim( (string) $value );
		if ( '' !== $value && false !== strpos( $value, '•' ) ) {
			return (string) get_option( $option, '' );
		}
		return sanitize_text_field( $value );
	}

	public function sanitize_claude_key( $value ) {
		return $this->preserve_masked_key( $value, 'spss_claude_api_key' );
	}

	public function sanitize_gemini_key( $value ) {
		return $this->preserve_masked_key( $value, 'spss_gemini_api_key' );
	}

	public function sanitize_openai_key( $value ) {
		return $this->preserve_masked_key( $value, 'spss_openai_api_key' );
	}

	public function sanitize_openrouter_key( $value ) {
		return $this->preserve_masked_key( $value, 'spss_openrouter_api_key' );
	}

	public function sanitize_selfhosted_key( $value ) {
		return $this->preserve_masked_key( $value, 'spss_selfhosted_key' );
	}

	private function masked( $option ) {
		$key = (string) get_option( $option, '' );
		return '' !== $key ? str_repeat( '•', 8 ) . substr( $key, -4 ) : '';
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
		$budget       = get_option( "spss_{$id}_monthly_budget", '' );
		$cost         = get_option( "spss_{$id}_cost_per_sheet", '' );
		?>
		<tr>
			<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_monthly_budget"><?php esc_html_e( 'Monthly budget ($)', 'sportspress-score-sheets' ); ?></label></th>
			<td>
				<input type="number" step="0.01" min="0" name="spss_<?php echo esc_attr( $id ); ?>_monthly_budget" id="spss_<?php echo esc_attr( $id ); ?>_monthly_budget" value="<?php echo esc_attr( '' === $budget || 0 === (int) $budget ? '' : $budget ); ?>" />
				<p class="description"><?php esc_html_e( 'Blank / 0 = unlimited. When the estimated spend would exceed this in a calendar month, recognition fails over to the next provider in the chain.', 'sportspress-score-sheets' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet"><?php esc_html_e( 'Est. cost per sheet ($)', 'sportspress-score-sheets' ); ?></label></th>
			<td>
				<input type="number" step="0.001" min="0" name="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet" id="spss_<?php echo esc_attr( $id ); ?>_cost_per_sheet" value="<?php echo esc_attr( '' === $cost || 0 === (int) $cost ? '' : $cost ); ?>" placeholder="<?php echo esc_attr( number_format( $default_cost, 3 ) ); ?>" />
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
					<?php if ( $p instanceof SPSS_Abstract_LLM_Provider ) : ?>
						<h2><?php echo esc_html( $p->get_label() ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_api_key"><?php esc_html_e( 'API key', 'sportspress-score-sheets' ); ?></label></th>
								<td>
									<input type="password" name="spss_<?php echo esc_attr( $id ); ?>_api_key" id="spss_<?php echo esc_attr( $id ); ?>_api_key" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( "spss_{$id}_api_key" ) ); ?>" value="" />
									<p class="description"><?php echo '' !== $this->masked( "spss_{$id}_api_key" ) ? esc_html__( 'A key is stored. Leave blank to keep it.', 'sportspress-score-sheets' ) : esc_html__( 'Enter a key to enable this provider.', 'sportspress-score-sheets' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="spss_<?php echo esc_attr( $id ); ?>_model"><?php esc_html_e( 'Model', 'sportspress-score-sheets' ); ?></label></th>
								<td><input type="text" name="spss_<?php echo esc_attr( $id ); ?>_model" id="spss_<?php echo esc_attr( $id ); ?>_model" class="regular-text" value="<?php echo esc_attr( $p->get_model() ); ?>" /><?php echo 'openrouter' === $id ? ' <span class="description">' . esc_html__( 'e.g. openai/gpt-4o, anthropic/claude-*, google/gemini-*, qwen/qwen2.5-vl-* — must support vision + structured output.', 'sportspress-score-sheets' ) . '</span>' : ''; ?></td>
							</tr>
							<?php if ( 'openrouter' === $id ) : ?>
								<tr>
									<th scope="row"><label for="spss_openrouter_base_url"><?php esc_html_e( 'Gateway base URL', 'sportspress-score-sheets' ); ?></label></th>
									<td>
										<input type="url" name="spss_openrouter_base_url" id="spss_openrouter_base_url" class="regular-text" placeholder="<?php echo esc_attr( SPSS_OpenRouter_Provider::DEFAULT_BASE_URL ); ?>" value="<?php echo esc_attr( get_option( 'spss_openrouter_base_url', '' ) ); ?>" />
										<p class="description"><?php esc_html_e( 'Defaults to OpenRouter. Point at any other OpenAI-compatible aggregator/gateway (Together, Groq, Fireworks, a LiteLLM/vLLM proxy, …).', 'sportspress-score-sheets' ); ?></p>
									</td>
								</tr>
							<?php endif; ?>
							<?php $this->render_budget_rows( $id ); ?>
						</table>
					<?php elseif ( 'selfhosted' === $id ) : ?>
						<h2><?php echo esc_html( $p->get_label() ); ?></h2>
						<table class="form-table" role="presentation">
							<tr>
								<th scope="row"><label for="spss_selfhosted_endpoint"><?php esc_html_e( 'Sidecar endpoint URL', 'sportspress-score-sheets' ); ?></label></th>
								<td>
									<input type="url" name="spss_selfhosted_endpoint" id="spss_selfhosted_endpoint" class="regular-text" placeholder="http://127.0.0.1:8000" value="<?php echo esc_attr( get_option( 'spss_selfhosted_endpoint', '' ) ); ?>" />
									<p class="description"><?php esc_html_e( 'Base URL of your local recognition sidecar (POSTs to /v1/recognize). Leave blank to disable.', 'sportspress-score-sheets' ); ?></p>
								</td>
							</tr>
							<tr>
								<th scope="row"><label for="spss_selfhosted_model"><?php esc_html_e( 'Model (optional)', 'sportspress-score-sheets' ); ?></label></th>
								<td><input type="text" name="spss_selfhosted_model" id="spss_selfhosted_model" class="regular-text" value="<?php echo esc_attr( get_option( 'spss_selfhosted_model', '' ) ); ?>" /></td>
							</tr>
							<tr>
								<th scope="row"><label for="spss_selfhosted_key"><?php esc_html_e( 'Bearer token (optional)', 'sportspress-score-sheets' ); ?></label></th>
								<td><input type="password" name="spss_selfhosted_key" id="spss_selfhosted_key" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $this->masked( 'spss_selfhosted_key' ) ); ?>" value="" /></td>
							</tr>
							<?php $this->render_budget_rows( $id ); ?>
						</table>
					<?php endif; ?>
				<?php endforeach; ?>

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
						<td><input type="file" name="sheet" id="spss_sheet_file" accept="image/*" required /></td>
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

		$valid = SPAT_Upload_Validator::validate(
			$file,
			array(
				'allowed_extensions' => array( 'jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf' ),
				'allowed_mime_types' => array( 'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf' ),
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
