<?php
/**
 * The "Complete Waitlist Orders" panel, in the League Manager tab of the main
 * SPAT page (Settings > SportsPress Admin Tools).
 *
 * Orders that put someone on the waitlist used to sit in Processing forever;
 * SPLM_Waitlist_Order_Completion now completes new ones as they arrive. This
 * page does the same, once, for the ones already waiting, without emailing
 * the customers.
 *
 * Module admin UI lives in SPAT's tabs (see AGENTS.md), so this is a panel in
 * the League Manager tab, not a page of its own and not part of the React
 * dashboard: it is a one-off maintenance action, not something a convener
 * needs in their daily view.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Waitlist_Orders_Admin {

	/**
	 * The SPAT settings page and the tab this panel lives in.
	 */
	const SPAT_PAGE = 'sportspress-admin-tools';

	const SPAT_TAB = 'league-manager';

	/**
	 * Set once boot() has run, i.e. the waitlist module is on and this is an
	 * admin request, so the panel only appears when the feature exists.
	 *
	 * @var bool
	 */
	private static $active = false;

	const ACTION = 'splm_complete_waitlist_orders';

	/**
	 * Seconds one click may spend completing orders, so a long list stops well
	 * inside PHP's time limit. Whatever is left is counted and can be run again.
	 */
	const TIME_BUDGET = 20;

	/**
	 * How long a run's result waits for the page to show it, in seconds.
	 */
	const RESULT_TTL = 120;

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
		self::$active = true;
		return $instance;
	}

	public function __construct() {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_post' ) );
	}

	/**
	 * Where the panel is: the League Manager tab of the SPAT settings page.
	 *
	 * @return string
	 */
	public static function panel_url(): string {
		return add_query_arg(
			array(
				'page' => self::SPAT_PAGE,
				'tab'  => self::SPAT_TAB,
			),
			admin_url( 'options-general.php' )
		);
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

		// Hand the result to the page we are about to redirect to. A short-lived,
		// per-user transient rather than query args: nothing in the URL can be
		// edited to put a made-up result on screen.
		set_transient( self::result_key(), self::run(), self::RESULT_TTL );

		// No exit: admin-post.php runs nothing after this action returns.
		wp_safe_redirect( self::panel_url() );
	}

	/**
	 * Where the last run's result waits for the next page view.
	 *
	 * @return string
	 */
	private static function result_key(): string {
		return 'splm_waitlist_orders_result_' . get_current_user_id();
	}

	/**
	 * The last run's result, shown once: read, then forgotten.
	 *
	 * @return array|null { completed: int, remaining: int, other: int }
	 */
	private static function take_result(): ?array {
		$result = get_transient( self::result_key() );
		if ( false === $result ) {
			return null;
		}
		delete_transient( self::result_key() );
		return is_array( $result ) ? $result : null;
	}

	/**
	 * Print the panel. Called from the League Manager tab; prints nothing when
	 * the waitlist feature is off or the user cannot manage the league.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function render_panel() {
		if ( ! self::$active || ! SPLM_Capabilities::can_manage() ) {
			return;
		}

		$counts = SPLM_Waitlist_Order_Completion::pending_counts();
		$result = self::take_result();
		?>
		<div class="splm-waitlist-orders">
			<h2><?php esc_html_e( 'Complete Waitlist Orders', 'sportspress-league-manager' ); ?></h2>

			<?php if ( $result ) : ?>
				<?php // A card in the page body, not a .notice: notice-collecting plugins remove .notice elements from the page. ?>
				<div class="card" role="status">
					<p><strong><?php echo esc_html( self::result_message( (int) $result['completed'], (int) $result['remaining'] ) ); ?></strong></p>
				</div>
			<?php endif; ?>

			<p><?php esc_html_e( 'Joining the waitlist is a purchase, and that order used to stay in Processing for good. New ones are now completed automatically once the entry is recorded. This completes the ones already waiting. Customers are not emailed, and nobody is registered as a player.', 'sportspress-league-manager' ); ?></p>

			<p><strong><?php echo esc_html( self::pending_sentence( (int) $counts['completable'] ) ); ?></strong></p>
			<?php if ( $counts['other'] > 0 ) : ?>
				<p class="description"><?php echo esc_html( self::other_sentence( (int) $counts['other'] ) ); ?></p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>" />
				<?php wp_nonce_field( self::ACTION ); ?>
				<?php submit_button( __( 'Complete waitlist orders', 'sportspress-league-manager' ), 'secondary', 'submit', true, 0 === (int) $counts['completable'] ? array( 'disabled' => 'disabled' ) : array() ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param int $n Orders that can be completed.
	 * @return string
	 */
	private static function pending_sentence( int $n ): string {
		/* translators: %d: number of orders. */
		return sprintf( _n( '%d waitlist order is still in Processing and can be completed.', '%d waitlist orders are still in Processing and can be completed.', $n, 'sportspress-league-manager' ), $n );
	}

	/**
	 * @param int $n Orders left alone.
	 * @return string
	 */
	private static function other_sentence( int $n ): string {
		/* translators: %d: number of orders. */
		return sprintf( _n( '%d other order in Processing is left alone: it mixes a waitlist product with something else, or its entry was not recorded.', '%d other orders in Processing are left alone: each mixes a waitlist product with something else, or its entry was not recorded.', $n, 'sportspress-league-manager' ), $n );
	}

}
