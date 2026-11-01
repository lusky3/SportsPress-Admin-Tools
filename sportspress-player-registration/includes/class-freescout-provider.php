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
	 * Entries are the log rows of the orders billed to any of the customer's
	 * addresses and of their players; attention covers those orders.
	 *
	 * @param int[]    $player_ids Resolved player ids (the union across $emails).
	 * @param string[] $emails     The customer's lower-cased addresses.
	 * @return array{entries: array, attention: array}
	 */
	public static function registration_section( array $player_ids, array $emails ): array {
		$order_ids  = array();
		$candidates = array(); // order id => processed marker.
		foreach ( self::orders_billed_to( $emails ) as $order ) {
			$order_id    = (int) $order->get_id();
			$order_ids[] = $order_id;
			if ( self::is_completed_registration( $order ) ) {
				$candidates[ $order_id ] = (string) $order->get_meta( '_spr_processed' );
			}
		}

		$player_ids = array_values( array_filter( array_map( 'intval', $player_ids ) ) );

		$entries = array();
		foreach ( self::query_logs( $order_ids, $player_ids, self::MAX_ENTRIES ) as $row ) {
			$entries[] = array(
				'order_id' => (int) $row->order_id,
				'at'       => self::iso_utc( $row ),
				'action'   => (string) $row->action,
				'season'   => (string) $row->season,
				'position' => (string) $row->position,
			);
		}

		return array(
			'entries'   => $entries,
			'attention' => self::attention_items( $candidates ),
		);
	}

	/**
	 * The newest MAX_ORDERS orders billed to any of the addresses, each once.
	 *
	 * One query per address (at most five) rather than one with a list, so it
	 * does not depend on how the order store handles an array billing_email.
	 *
	 * @param string[] $emails Addresses.
	 * @return WC_Order[] Newest first.
	 */
	private static function orders_billed_to( array $emails ): array {
		$orders = array();
		foreach ( $emails as $email ) {
			$found = wc_get_orders(
				array(
					'billing_email' => (string) $email,
					'limit'         => self::MAX_ORDERS,
					'orderby'       => 'date',
					'order'         => 'DESC',
				)
			);
			foreach ( (array) $found as $order ) {
				$orders[ (int) $order->get_id() ] = $order;
			}
		}

		$orders = array_values( $orders );
		usort(
			$orders,
			function ( $a, $b ) {
				return self::created_at( $b ) <=> self::created_at( $a );
			}
		);
		return array_slice( $orders, 0, self::MAX_ORDERS );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return int Creation timestamp, 0 when unknown.
	 */
	private static function created_at( $order ): int {
		$created = $order->get_date_created();
		return $created ? (int) $created->getTimestamp() : 0;
	}

	/**
	 * A completed order that holds registration items. process_completed_order()
	 * leaves orders without registration items unmarked on purpose, so those
	 * are never failures.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private static function is_completed_registration( $order ): bool {
		return 'completed' === $order->get_status()
			&& array() !== SPPR_Player_Registration::get_registration_items( $order );
	}

	/**
	 * The completed registration orders that need a human, each with the log
	 * action SPPR_Registration_Outcome::shown() picks: no processed marker
	 * (the newest failure row, so a partly registered order names what
	 * failed), or a marker of '1' whose newest log row is a failure (legacy
	 * orders processed before player-registration 1.2.1 carry '1' even after
	 * season_not_detected or player_creation_failed). A '1' with no log rows
	 * (purged or erased) is trusted.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array<int, string> $candidates Order id => processed marker.
	 * @return array<int, array{order_id:int, last_action:string}>
	 */
	private static function attention_items( array $candidates ): array {
		if ( empty( $candidates ) ) {
			return array();
		}

		// Each order's log actions, newest first (the query's order).
		$actions = array();
		foreach ( self::query_logs( array_keys( $candidates ), array(), 0 ) as $row ) {
			$actions[ (int) $row->order_id ][] = (string) $row->action;
		}

		$items = array();
		foreach ( $candidates as $order_id => $marker ) {
			$action = SPPR_Registration_Outcome::shown( $actions[ $order_id ] ?? array(), (string) $marker );
			if ( '1' === $marker && SPPR_Registration_Outcome::is_success( $action ) ) {
				continue;
			}
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
		$sql   = "SELECT order_id, timestamp, UNIX_TIMESTAMP(timestamp) AS timestamp_unix, action, season, position FROM `{$table}` WHERE " . implode( ' OR ', $clauses ) . ' ORDER BY timestamp DESC, id DESC';
		if ( $limit > 0 ) {
			$sql   .= ' LIMIT %d';
			$args[] = $limit;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is internal; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * A log row's time as ISO-8601 UTC.
	 *
	 * The `timestamp` column is a DATETIME defaulting to CURRENT_TIMESTAMP, so
	 * it holds wall-clock time in the MySQL session time zone, not UTC.
	 * query_logs() selects UNIX_TIMESTAMP(timestamp), which MySQL converts from
	 * that zone (DST included) to epoch seconds. The bare string read as UTC is
	 * only a fallback for a row without it.
	 *
	 * @param object $row Log row from query_logs().
	 * @return string
	 */
	private static function iso_utc( $row ): string {
		$unix = isset( $row->timestamp_unix ) ? (int) $row->timestamp_unix : 0;
		if ( $unix > 0 ) {
			return gmdate( 'Y-m-d\TH:i:s\Z', $unix );
		}
		$mysql_datetime = (string) ( $row->timestamp ?? '' );
		$ts             = '' === $mysql_datetime ? false : strtotime( $mysql_datetime . ' UTC' );
		return false === $ts ? '' : gmdate( 'Y-m-d\TH:i:s\Z', $ts );
	}
}
