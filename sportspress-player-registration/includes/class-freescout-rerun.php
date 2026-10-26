<?php
/**
 * FreeScout bridge action player.rerun: re-run player registration for a
 * completed order from the helpdesk ticket.
 *
 * A thin wrapper over SPPR_Admin::rerun(), the same path the admin
 * "Re-run player registration" order action takes; this adds the dry-run
 * preview and the agent-facing preconditions.
 *
 * @package SportsPress_Player_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @SuppressWarnings(PHPMD.StaticAccess)
 */
class SPPR_FreeScout_Rerun {

	const CAPABILITY = 'manage_woocommerce';

	/**
	 * Hook the action into the parent's registry.
	 */
	public static function register() {
		add_filter( 'spat_freescout_actions', array( __CLASS__, 'add_actions' ) );
	}

	public static function add_actions( $actions ) {
		$actions['player.rerun'] = array(
			'capability' => self::CAPABILITY,
			'handler'    => array( __CLASS__, 'rerun_action' ),
		);
		return $actions;
	}

	/**
	 * Action player.rerun.
	 *
	 * @param array   $params     { order_id }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user (unused).
	 * @param array   $actor_info FreeScout agent (unused).
	 * @return array|WP_Error
	 */
	public static function rerun_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		unset( $actor, $actor_info );

		$order_id = isset( $params['order_id'] ) && is_numeric( $params['order_id'] ) ? (int) $params['order_id'] : 0;
		$order    = self::rerunnable_order( $order_id );
		if ( is_wp_error( $order ) ) {
			return $order;
		}

		$marker_line = sprintf( 'Clears the registration marker on order #%d and processes it again', $order_id );

		if ( $dry_run ) {
			return self::preview( $order_id, $order, $marker_line );
		}
		return self::run( $order_id, $marker_line );
	}

	/**
	 * The order when it can be re-run: it exists, is completed and has
	 * registration products (checked in that order).
	 *
	 * @param int $order_id Order id from the request (0 when invalid).
	 * @return WC_Order|WP_Error
	 */
	private static function rerunnable_order( int $order_id ) {
		$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
		if ( ! $order ) {
			return self::error( 'not_found', sprintf( 'Order #%d was not found.', $order_id ), 404 );
		}
		if ( 'completed' !== $order->get_status() ) {
			return self::error(
				'not_completed',
				sprintf( 'Order #%1$d is %2$s. Only completed orders are registered.', $order_id, $order->get_status() ),
				409
			);
		}
		if ( array() === SPPR_Player_Registration::get_registration_items( $order ) ) {
			return self::error( 'no_registration_items', sprintf( 'Order #%d has no registration products.', $order_id ), 409 );
		}
		return $order;
	}

	/**
	 * Dry run: what a re-run would do and the order's current state. Writes
	 * nothing.
	 *
	 * @param int      $order_id    Order id.
	 * @param WC_Order $order       The order.
	 * @param string   $marker_line Change line shared with the real run.
	 * @return array|WP_Error
	 */
	private static function preview( int $order_id, $order, string $marker_line ) {
		$locked = SPPR_Admin::rerun_locked( $order_id );
		if ( $locked ) {
			return $locked;
		}
		$marker = (string) $order->get_meta( '_spr_processed' );
		// Same pick as the FreeScout attention list. Legacy orders (processed
		// before player-registration 1.2.1) carry '1' even after
		// season_not_detected or player_creation_failed, so with '1' the newest
		// log row has a say too.
		$last    = self::last_outcome( $order_id, $marker );
		$current = '1' === $marker && SPPR_Registration_Outcome::is_success( $last );
		return array(
			'summary'          => sprintf( 'Re-run registration for order #%d', $order_id ),
			'changes'          => array(
				$marker_line,
				'Last outcome: ' . self::text( $last ),
				$current ? 'Currently: registered' : 'Currently: not registered',
			),
			'requires_confirm' => array(),
			'data'             => array(),
		);
	}

	/**
	 * Real run through SPPR_Admin::rerun(), reported from this run's outcome.
	 *
	 * @param int    $order_id    Order id.
	 * @param string $marker_line Change line shared with the dry run.
	 * @return array|WP_Error
	 */
	private static function run( int $order_id, string $marker_line ) {
		$result = SPPR_Admin::rerun( $order_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Registered needs both a '1' marker and a success outcome. Since
		// player-registration 1.2.1 a run sets '1' only when every item linked,
		// so the two agree; the outcome check stays as a guard (before 1.2.1 the
		// marker was also '1' after season_not_detected or
		// player_creation_failed). '' is a full success.
		$outcome    = $result['outcome'];
		$registered = '1' === $result['marker'] && SPPR_Registration_Outcome::is_success( $outcome );
		if ( '' === $outcome ) {
			// Nothing reported: a full success (whose log row says how), or a
			// run that never got going (another worker held the order).
			$outcome = $registered ? self::last_outcome( $order_id, '1' ) : 'not_processed';
		}

		return array(
			'summary'          => $registered ? 'Registered' : 'Still not registered: ' . self::text( $outcome ),
			'changes'          => array( $marker_line ),
			'requires_confirm' => array(),
			'data'             => array(
				'marker'  => $result['marker'],
				'outcome' => $outcome,
			),
		);
	}

	/**
	 * The registration log action to show for the order, picked by
	 * SPPR_Registration_Outcome::shown() (the newest row with marker '1', else
	 * the newest failure), or 'not_processed' when there is none.
	 *
	 * @param int    $order_id Order id.
	 * @param string $marker   The order's `_spr_processed` value.
	 */
	private static function last_outcome( int $order_id, string $marker ): string {
		global $wpdb;

		$table   = $wpdb->prefix . 'spat_registration_logs';
		$actions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT action FROM `{$table}` WHERE order_id = %d ORDER BY timestamp DESC, id DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is internal.
				$order_id
			)
		);
		$action = SPPR_Registration_Outcome::shown( is_array( $actions ) ? $actions : array(), $marker );
		return '' !== $action ? $action : 'not_processed';
	}

	/**
	 * A log action as words: "name_rejected" → "name rejected".
	 */
	private static function text( string $outcome ): string {
		return str_replace( '_', ' ', $outcome );
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
