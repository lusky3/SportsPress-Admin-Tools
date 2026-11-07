<?php
/**
 * The FreeScout Bridge settings tab on the SportsPress Admin Tools settings page.
 *
 * The option names and field names are the ones the bridge used when it lived in
 * SportsPress Admin Tools, so saved values carry over untouched.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPFB_Admin {

	const OPTION_GROUP = 'spfb_settings';
	const PAGE         = 'spfb_settings';
	const TAB_ID       = 'freescout-bridge';

	public function __construct() {
		add_action( 'spat_admin_init_settings', array( $this, 'register_settings' ) );
		add_action( 'spat_admin_page_tabs', array( $this, 'add_admin_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'render_tab_content' ) );
	}

	public function add_admin_tab() {
		echo '<a href="#' . esc_attr( self::TAB_ID ) . '" class="nav-tab">' . esc_html__( 'FreeScout Bridge', 'sportspress-freescout-bridge' ) . '</a>';
	}

	public function render_tab_content() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div id="<?php echo esc_attr( self::TAB_ID ); ?>" class="tab-content" style="display:none;">
			<form action="options.php" method="post">
				<input type="hidden" name="current_tab" value="<?php echo esc_attr( self::TAB_ID ); ?>">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::PAGE );
				submit_button( __( 'Save Settings', 'sportspress-freescout-bridge' ) );
				?>
			</form>
		</div>
		<?php
	}

	public function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			SPFB_REST::SECRET_OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_secret' ) )
		);
		register_setting(
			self::OPTION_GROUP,
			SPFB_REST::EVENTS_URL_OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize_events_url' ) )
		);
		register_setting(
			self::OPTION_GROUP,
			SPFB_REST::EVENTS_ENABLED_OPTION,
			array(
				'sanitize_callback' => static function ( $value ): string {
					return '1' === $value ? '1' : '0';
				},
			)
		);

		add_settings_section(
			'spfb_freescout_section',
			__( 'FreeScout', 'sportspress-freescout-bridge' ),
			static function () {
				echo '<p>' . esc_html__( 'Lets the FreeScout SPAT bridge show league data in the ticket sidebar.', 'sportspress-freescout-bridge' ) . '</p>';
			},
			self::PAGE
		);

		add_settings_field( SPFB_REST::SECRET_OPTION, __( 'Shared Secret', 'sportspress-freescout-bridge' ), array( $this, 'secret_callback' ), self::PAGE, 'spfb_freescout_section' );
		add_settings_field( SPFB_REST::EVENTS_URL_OPTION, __( 'Events URL', 'sportspress-freescout-bridge' ), array( $this, 'events_url_callback' ), self::PAGE, 'spfb_freescout_section' );
		add_settings_field( SPFB_REST::EVENTS_ENABLED_OPTION, __( 'Send Events', 'sportspress-freescout-bridge' ), array( $this, 'events_enabled_callback' ), self::PAGE, 'spfb_freescout_section' );
		add_settings_field( 'spat_freescout_events_status', __( 'Events', 'sportspress-freescout-bridge' ), array( $this, 'events_status_callback' ), self::PAGE, 'spfb_freescout_section' );
	}

	/**
	 * sanitize_callback for the FreeScout shared secret. The field never
	 * echoes the stored value, so a blank submission means "unchanged". A
	 * secret shorter than the REST layer's minimum is refused with a notice
	 * (the routes would answer 503 "not configured" for it) and the stored
	 * secret is kept.
	 *
	 * Not sanitize_text_field(): it strips %xx sequences and tag-like text,
	 * silently changing the secret so every signature fails. Not wp_unslash()
	 * either: options.php has already unslashed the value, and a second pass
	 * would drop backslashes that belong to the secret.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_secret( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		$saved = (string) get_option( SPFB_REST::SECRET_OPTION, '' );
		if ( '' === $value ) {
			return $saved;
		}
		if ( strlen( $value ) < SPFB_REST::MIN_SECRET_LENGTH ) {
			add_settings_error(
				SPFB_REST::SECRET_OPTION,
				'spat_freescout_secret_too_short',
				sprintf(
					/* translators: %d: minimum secret length. */
					__( 'The FreeScout shared secret must be at least %d characters. The previous secret was kept.', 'sportspress-freescout-bridge' ),
					SPFB_REST::MIN_SECRET_LENGTH
				)
			);
			return $saved;
		}
		return $value;
	}

	/**
	 * sanitize_callback for the FreeScout events URL. Blank clears it. Anything
	 * that is not an http(s) URL is refused with a notice and the stored value
	 * is kept.
	 *
	 * @param mixed $value Raw submitted value.
	 * @return string
	 */
	public static function sanitize_events_url( $value ): string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value ) {
			return '';
		}
		// Check the RAW value: esc_url_raw() prepends http:// to a scheme-less
		// string, which would silently store a plaintext URL.
		$url = preg_match( '#^https?://#i', $value ) ? esc_url_raw( $value, array( 'http', 'https' ) ) : '';
		if ( '' === $url ) {
			add_settings_error(
				SPFB_REST::EVENTS_URL_OPTION,
				'spat_freescout_events_url_invalid',
				__( 'The FreeScout events URL must start with https:// or http://. The previous URL was kept.', 'sportspress-freescout-bridge' )
			);
			return (string) get_option( SPFB_REST::EVENTS_URL_OPTION, '' );
		}
		return $url;
	}

	public function events_url_callback() {
		printf(
			'<input type="url" name="%1$s" value="%2$s" class="regular-text" placeholder="https://helpdesk.example.com/spatbridge/events">',
			esc_attr( SPFB_REST::EVENTS_URL_OPTION ),
			esc_attr( (string) get_option( SPFB_REST::EVENTS_URL_OPTION, '' ) )
		);
		echo '<p class="description">' . esc_html__( 'The bridge\'s events endpoint in FreeScout.', 'sportspress-freescout-bridge' ) . '</p>';
	}

	public function events_enabled_callback() {
		printf(
			'<label><input type="checkbox" name="%1$s" value="1"%2$s> %3$s</label>',
			esc_attr( SPFB_REST::EVENTS_ENABLED_OPTION ),
			checked( '1', (string) get_option( SPFB_REST::EVENTS_ENABLED_OPTION, '0' ), false ),
			esc_html__( 'Send e-Transfer, registration and waitlist events to FreeScout. Needs the URL and shared secret above.', 'sportspress-freescout-bridge' )
		);
	}

	public function events_status_callback() {
		$status = SPFB_Events::status();
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: pending event count, 2: failed event count. */
				__( 'Pending: %1$d. Failed: %2$d.', 'sportspress-freescout-bridge' ),
				$status['pending'],
				$status['failed']
			)
		) . '</p>';
		if ( '' !== $status['last_error'] ) {
			echo '<p class="description">' . esc_html(
				sprintf(
					/* translators: %s: the most recent delivery error. */
					__( 'Last error: %s', 'sportspress-freescout-bridge' ),
					$status['last_error']
				)
			) . '</p>';
		}
	}

	public function secret_callback() {
		$is_set = '' !== (string) get_option( SPFB_REST::SECRET_OPTION, '' );
		printf(
			'<input type="password" name="%1$s" value="" class="regular-text" autocomplete="new-password" placeholder="%2$s">',
			esc_attr( SPFB_REST::SECRET_OPTION ),
			esc_attr( $is_set ? __( 'Secret is set; leave blank to keep it', 'sportspress-freescout-bridge' ) : '' )
		);
		echo '<p class="description">' . esc_html__( 'At least 32 characters. Enter the same value as the bridge\'s shared secret in FreeScout. Until one is set the FreeScout routes answer 503.', 'sportspress-freescout-bridge' ) . '</p>';
	}
}
