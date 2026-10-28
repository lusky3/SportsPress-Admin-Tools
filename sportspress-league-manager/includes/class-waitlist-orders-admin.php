<?php
/**
 * The "Complete Waitlist Orders" admin page, under the League Manager menu.
 *
 * Orders that put someone on the waitlist used to sit in Processing forever;
 * SPLM_Waitlist_Order_Completion now completes new ones as they arrive. This
 * page does the same, once, for the ones already waiting, without emailing
 * the customers.
 *
 * It is a plain WordPress admin page (nonce-protected form, capability check)
 * rather than part of the React dashboard: it is a one-off maintenance action,
 * not something a convener needs in their daily view.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Orders_Admin {

	const SLUG = 'splm-waitlist-orders';

	/**
	 * Slug of the League Manager top-level menu (registered by SPLM_Admin).
	 */
	const PARENT_SLUG = 'splm-dashboard';

	const ACTION = 'splm_complete_waitlist_orders';

	/**
	 * Seconds one click may spend completing orders, so a long list stops well
	 * inside PHP's time limit. Whatever is left is counted and can be run again.
	 */
	const TIME_BUDGET = 20;

	/**
	 * Hook the page up once, however many times it is asked for.
	 *
	 * @return self
	 */
	public static function boot(): self {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	public function __construct() {
		// After SPLM_Admin registers the League Manager menu (priority 10), so
		// the parent exists and this lands at the end of its sub-menu.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function add_menu() {
		$cap   = SPLM_Capabilities::can_manage() ? 'manage_sportspress' : 'do_not_allow';
		$title = __( 'Complete Waitlist Orders', 'sportspress-league-manager' );
		add_submenu_page( self::PARENT_SLUG, $title, $title, $cap, self::SLUG, array( $this, 'render_page' ) );
	}

	/**
	 * Complete waitlist orders until none are left or the time budget is spent.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int $seconds Time budget in seconds.
	 * @return array { completed: int, remaining: int, other: int }
	 */
	public static function run( int $seconds = self::TIME_BUDGET ): array {
		$deadline  = microtime( true ) + $seconds;
		$completed = 0;

		do {
			$batch      = SPLM_Waitlist_Order_Completion::complete_pending_source_orders();
			$completed += (int) $batch['completed'];
		} while ( $batch['completed'] > 0 && microtime( true ) < $deadline );

		$counts = SPLM_Waitlist_Order_Completion::pending_counts();

		return array(
			'completed' => $completed,
			'remaining' => (int) $counts['completable'],
			'other'     => (int) $counts['other'],
		);
	}

	/**
	 * The sentence shown after a run.
	 *
	 * @param int $completed Orders completed by that run.
	 * @param int $remaining Orders still completable (time ran out).
	 * @return string
	 */
	public static function result_message( int $completed, int $remaining ): string {
		if ( 0 === $completed && 0 === $remaining ) {
			return __( 'No waitlist orders needed completing.', 'sportspress-league-manager' );
		}

		/* translators: %d: number of orders. */
		$message = sprintf( _n( 'Completed %d waitlist order without emailing the customer.', 'Completed %d waitlist orders without emailing the customers.', $completed, 'sportspress-league-manager' ), $completed );

		if ( $remaining > 0 ) {
			/* translators: %d: number of orders. */
			$message .= ' ' . sprintf( _n( '%d is still waiting; run it again to finish.', '%d are still waiting; run it again to finish.', $remaining, 'sportspress-league-manager' ), $remaining );
		}

		return $message;
	}

	/**
	 * The admin-post handler: check, run, then redirect back with the result.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function handle_post() {
		if ( ! SPLM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'sportspress-league-manager' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );

		$result = self::run();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::SLUG,
					'completed' => $result['completed'],
					'ran'       => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function render_page() {
		if ( ! SPLM_Capabilities::can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'sportspress-league-manager' ), '', array( 'response' => 403 ) );
		}

		$counts = SPLM_Waitlist_Order_Completion::pending_counts();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of the last run's result; no state change.
		$ran       = isset( $_GET['ran'] );
		$completed = isset( $_GET['completed'] ) ? absint( wp_unslash( $_GET['completed'] ) ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Complete Waitlist Orders', 'sportspress-league-manager' ); ?></h1>

			<?php if ( $ran ) : ?>
				<?php // A card in the page body, not a .notice: notice-collecting plugins remove .notice elements from the page. ?>
				<div class="card" role="status">
					<p><strong><?php echo esc_html( self::result_message( $completed, (int) $counts['completable'] ) ); ?></strong></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Joining the waitlist is a purchase, and that order used to stay in Processing for good. New ones are now completed automatically once the entry is recorded. This completes the ones already waiting. Customers are not emailed, and nobody is registered as a player.', 'sportspress-league-manager' ); ?></p>

			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of orders. */
							_n( '%d waitlist order is still in Processing and can be completed.', '%d waitlist orders are still in Processing and can be completed.', (int) $counts['completable'], 'sportspress-league-manager' ),
							(int) $counts['completable']
						)
					);
					?>
				</strong>
			</p>

			<?php if ( $counts['other'] > 0 ) : ?>
				<p class="description">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of orders. */
							_n( '%d other order in Processing is left alone: it mixes a waitlist product with something else, or its entry was not recorded.', '%d other orders in Processing are left alone: each mixes a waitlist product with something else, or its entry was not recorded.', (int) $counts['other'], 'sportspress-league-manager' ),
							(int) $counts['other']
						)
					);
					?>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Complete waitlist orders', 'sportspress-league-manager' ), 'primary', 'submit', true, 0 === (int) $counts['completable'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
		</div>
		<?php
	}
}
