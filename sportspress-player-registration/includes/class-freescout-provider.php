<?php
/**
 * FreeScout "registration" section provider.
 *
 * Supplies sections.registration of the FreeScout bridge's customer lookup: the
 * customer's registration log entries and paid registration orders that never
 * produced a registration.
 *
 * @package SportsPress_Player_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_FreeScout_Provider {

	const MAX_ORDERS  = 20;
	const MAX_ENTRIES = 20;

	/**
	 * Register the provider with the parent's FreeScout REST layer.
	 */
	public static function register() {
		add_filter( 'spat_freescout_providers', array( __CLASS__, 'add_provider' ) );
	}

	/**
	 * @param array $providers Provider callables keyed by section name.
	 * @return array
	 */
	public static function add_provider( $providers ) {
		$providers['registration'] = array( __CLASS__, 'registration_section' );
		return $providers;
	}

	/**
	 * Build sections.registration.
	 *
	 * @param int[]  $player_ids Resolved player ids.
	 * @param string $email      Lower-cased customer email.
	 * @return array{entries: array, attention: array}
	 */
	public static function registration_section( array $player_ids, string $email ): array {
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => self::MAX_ORDERS,
				'orderby'       => 'date',
				'order'         => 'DESC',
			)
		);

		$order_ids = array();
		$attention = array();
		foreach ( (array) $orders as $order ) {
			$order_id    = (int) $order->get_id();
			$order_ids[] = $order_id;
			if ( self::needs_attention( $order ) ) {
				$attention[] = $order_id;
			}
		}

		$player_ids = array_values( array_filter( array_map( 'intval', $player_ids ) ) );

		$entries = array();
		foreach ( self::query_logs( $order_ids, $player_ids, self::MAX_ENTRIES ) as $row ) {
			$entries[] = array(
				'order_id' => (int) $row->order_id,
				'at'       => self::iso_utc( (string) $row->timestamp ),
				'action'   => (string) $row->action,
				'season'   => (string) $row->season,
				'position' => (string) $row->position,
			);
		}

		return array(
			'entries'   => $entries,
			'attention' => self::attention_items( $attention ),
		);
	}

	/**
	 * A completed order that holds registration items but never got the
	 * processed marker. process_completed_order() leaves orders without
	 * registration items unmarked on purpose, so those are not failures.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function needs_attention( $order ): bool {
		if ( 'completed' !== $order->get_status() || '1' === (string) $order->get_meta( '_spr_processed' ) ) {
			return false;
		}
		return array() !== SPPR_Player_Registration::get_registration_items( $order );
	}

	/**
	 * Pair each attention order with its newest log action.
	 *
	 * @param int[] $order_ids Completed, unprocessed order ids.
	 * @return array<int, array{order_id:int, last_action:string}>
	 */
	private static function attention_items( array $order_ids ): array {
		if ( empty( $order_ids ) ) {
			return array();
		}

		// Newest first, so the first row seen per order is its last action.
		$last = array();
		foreach ( self::query_logs( $order_ids, array(), 0 ) as $row ) {
			$key = (int) $row->order_id;
			if ( ! isset( $last[ $key ] ) ) {
				$last[ $key ] = (string) $row->action;
			}
		}

		$items = array();
		foreach ( $order_ids as $order_id ) {
			$action = $last[ $order_id ] ?? '';
			$items[] = array(
				'order_id'    => $order_id,
				'last_action' => '' !== $action ? $action : 'not_processed',
			);
		}
		return $items;
	}

	/**
	 * Log rows for the given orders and/or players, newest first.
	 *
	 * @param int[] $order_ids  Order ids (may be empty).
	 * @param int[] $player_ids Player ids (may be empty).
	 * @param int   $limit      Max rows; 0 for no limit.
	 * @return object[]
	 */
	private static function query_logs( array $order_ids, array $player_ids, int $limit ): array {
		global $wpdb;

		$clauses = array();
		$args    = array();
		if ( ! empty( $order_ids ) ) {
			$clauses[] = 'order_id IN (' . implode( ',', array_fill( 0, count( $order_ids ), '%d' ) ) . ')';
			$args      = array_merge( $args, $order_ids );
		}
		if ( ! empty( $player_ids ) ) {
			$clauses[] = 'player_id IN (' . implode( ',', array_fill( 0, count( $player_ids ), '%d' ) ) . ')';
			$args      = array_merge( $args, $player_ids );
		}
		if ( empty( $clauses ) ) {
			return array();
		}

		$table = $wpdb->prefix . 'spat_registration_logs';
		$sql   = "SELECT order_id, timestamp, action, season, position FROM `{$table}` WHERE " . implode( ' OR ', $clauses ) . ' ORDER BY timestamp DESC, id DESC';
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is internal; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The log's `timestamp` column defaults to CURRENT_TIMESTAMP, i.e. the
	 * database server clock, which the registration admin screens display
	 * as-is. It is treated as UTC here.
	 */
	private static function iso_utc( string $mysql_datetime ): string {
		$ts = strtotime( $mysql_datetime . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
