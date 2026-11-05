<?php
/**
 * FreeScout bridge integration: the "etransfers" customer section, the
 * etransfers.match action and the etransfers.search / etransfers.order queries.
 *
 * Registered only when the e-Transfer module is enabled.
 *
 * @package SportsPress_ETransfer_Automation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPET_FreeScout_Integration {

	const TRANSFER_LIMIT   = 20;
	const ORDER_LIMIT      = 20;
	const MATCH_LIMIT      = 5;
	const SEARCH_LIMIT     = 20;
	const WINDOW_BEFORE    = 2 * DAY_IN_SECONDS;
	const WINDOW_AFTER     = 14 * DAY_IN_SECONDS;
	const CANDIDATE_CAP    = 50;

	/**
	 * Hook the provider, the action and the queries into the parent's registries.
	 */
	public static function register() {
		add_filter( 'spat_freescout_providers', array( __CLASS__, 'add_provider' ) );
		add_filter( 'spat_freescout_actions', array( __CLASS__, 'add_actions' ) );
		add_filter( 'spat_freescout_queries', array( __CLASS__, 'add_queries' ) );
	}

	public static function add_provider( $providers ) {
		$providers['etransfers'] = array( __CLASS__, 'etransfers_section' );
		return $providers;
	}

	public static function add_actions( $actions ) {
		$actions['etransfers.match'] = array(
			'capability' => 'manage_woocommerce',
			'handler'    => array( __CLASS__, 'match_action' ),
		);
		return $actions;
	}

	public static function add_queries( $queries ) {
		$queries['etransfers.search'] = array( __CLASS__, 'search_query' );
		$queries['etransfers.order']  = array( __CLASS__, 'order_query' );
		return $queries;
	}

	/**
	 * Build sections.etransfers.
	 *
	 * Transfers sent from any of the customer's addresses, and on-hold orders
	 * billed to any of them, each listed once.
	 *
	 * @param int[]    $player_ids Resolved player ids (unused).
	 * @param string[] $emails     The customer's lower-cased addresses.
	 * @return array
	 */
	public static function etransfers_section( array $player_ids, array $emails ): array {
		unset( $player_ids );
		$emails = array_values( array_unique( array_map( 'strtolower', array_map( 'strval', $emails ) ) ) );

		$transfers = array();
		foreach ( SPET_Database::get_customer_etransfer_logs( $emails, self::TRANSFER_LIMIT ) as $row ) {
			$order_id    = (int) $row->order_id;
			$transfers[] = array(
				'log_id'     => (int) $row->id,
				'at'         => self::iso( $row->timestamp ),
				'amount'     => number_format( (float) $row->amount, 2, '.', '' ),
				'from_name'  => (string) $row->from_name,
				'from_email' => (string) $row->from_email,
				'reference'  => (string) $row->reference_number,
				'status'     => $order_id > 0 ? 'matched' : 'unmatched',
				'order_id'   => $order_id > 0 ? $order_id : null,
			);
		}

		$on_hold = array();
		foreach ( self::on_hold_orders( $emails ) as $order ) {
			$timestamp = self::created_at( $order );
			$total     = (float) $order->get_total();

			$on_hold[] = array(
				'order_id'         => (int) $order->get_id(),
				'at'               => $timestamp ? gmdate( 'Y-m-d\TH:i:s\Z', $timestamp ) : null,
				'total'            => number_format( $total, 2, '.', '' ),
				'possible_matches' => $timestamp ? self::possible_matches( $total, $timestamp ) : array(),
			);
		}

		return array(
			'transfers'      => $transfers,
			'on_hold_orders' => $on_hold,
		);
	}

	/**
	 * The newest ORDER_LIMIT on-hold orders billed to any of the addresses,
	 * each once. One query per address (at most five) rather than one with a
	 * list, so it does not depend on how the order store handles an array
	 * billing_email.
	 *
	 * @param string[] $emails Addresses.
	 * @return WC_Order[] Newest first.
	 */
	private static function on_hold_orders( array $emails ): array {
		$orders = array();
		foreach ( $emails as $email ) {
			$found = wc_get_orders(
				array(
					'billing_email' => $email,
					'status'        => 'on-hold',
					'limit'         => self::ORDER_LIMIT,
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
		return array_slice( $orders, 0, self::ORDER_LIMIT );
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
	 * Unmatched transfers for the order's amount, received from 2 days before
	 * to 14 days after the order, nearest the order date first.
	 *
	 * Row timestamps are database-server time, read as UTC like the rest of the
	 * payload. The query orders by distance from the order date in SQL, so the
	 * candidate cap drops the farthest rows, not the oldest.
	 */
	private static function possible_matches( float $total, int $order_ts ): array {
		$rows = SPET_Database::get_unmatched_logs_in_window(
			$total,
			gmdate( 'Y-m-d H:i:s', $order_ts - self::WINDOW_BEFORE ),
			gmdate( 'Y-m-d H:i:s', $order_ts + self::WINDOW_AFTER ),
			self::CANDIDATE_CAP,
			gmdate( 'Y-m-d H:i:s', $order_ts )
		);

		$distance = array();
		foreach ( $rows as $i => $row ) {
			$distance[ $i ] = abs( (int) strtotime( $row->timestamp . ' UTC' ) - $order_ts );
		}
		asort( $distance );

		$matches = array();
		foreach ( array_slice( array_keys( $distance ), 0, self::MATCH_LIMIT ) as $i ) {
			$row       = $rows[ $i ];
			$matches[] = array(
				'log_id'    => (int) $row->id,
				'at'        => self::iso( $row->timestamp ),
				'amount'    => number_format( (float) $row->amount, 2, '.', '' ),
				'from_name' => (string) $row->from_name,
				'reference' => (string) $row->reference_number,
			);
		}
		return $matches;
	}

	/**
	 * Action etransfers.match.
	 *
	 * @param array   $params     { log_id, order_id, force_mismatch? }.
	 * @param bool    $dry_run    Preview only.
	 * @param WP_User $actor      Acting WordPress user.
	 * @param array   $actor_info { email, name } of the FreeScout agent.
	 * @return array|WP_Error
	 */
	public static function match_action( array $params, bool $dry_run, $actor, array $actor_info ) {
		$log_id   = isset( $params['log_id'] ) ? (int) $params['log_id'] : 0;
		$order_id = isset( $params['order_id'] ) ? (int) $params['order_id'] : 0;
		$force    = ! empty( $params['force_mismatch'] );
		if ( $log_id <= 0 || $order_id <= 0 ) {
			return new WP_Error( 'bad_params', 'A log_id and an order_id are required.', array( 'status' => 400 ) );
		}

		$result = SPET_ETransfer_Admin::manual_match( $log_id, $order_id, $force, $dry_run );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$log       = $result['log'];
		$reference = ! empty( $log->reference_number ) ? $log->reference_number : 'N/A';
		$summary   = sprintf(
			'Match e-Transfer %1$s ($%2$s from %3$s) to order #%4$d',
			$reference,
			$result['log_amount'],
			$log->from_name,
			$order_id
		);
		$changes   = array(
			sprintf( 'Order #%d: on hold → completed', $order_id ),
			sprintf( 'e-Transfer %s recorded as manually matched', $reference ),
		);

		$requires_confirm = array();
		if ( $dry_run && $result['mismatch'] && ! $force ) {
			$requires_confirm[] = array(
				'code'    => 'amount_mismatch',
				'message' => sprintf( 'Amounts differ: e-Transfer $%s, order $%s', $result['log_amount'], $result['order_total'] ),
			);
		}

		if ( ! $dry_run ) {
			self::add_attribution_note( $order_id, $actor, $actor_info );
		}

		return array(
			'summary'          => $summary,
			'changes'          => $changes,
			'requires_confirm' => $requires_confirm,
			'data'             => array(),
		);
	}

	/**
	 * Record who in FreeScout asked for the match. A failure here must not
	 * fail the action: the order is already completed.
	 */
	private static function add_attribution_note( int $order_id, $actor, array $actor_info ) {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$agent = sanitize_text_field( (string) ( $actor_info['name'] ?? '' ) );
			if ( '' === $agent ) {
				$agent = sanitize_text_field( (string) ( $actor_info['email'] ?? '' ) );
			}
			$order->add_order_note(
				sprintf( 'Matched manually from FreeScout by %1$s (%2$s)', $agent, $actor->display_name ),
				0,
				true
			);
		} catch ( Throwable $e ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'freescout', 'Could not add the FreeScout match note to order ' . $order_id . ': ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Query etransfers.search.
	 *
	 * @param array $params { q }.
	 * @return array
	 */
	public static function search_query( array $params ): array {
		$term = isset( $params['q'] ) && is_scalar( $params['q'] ) ? trim( (string) $params['q'] ) : '';

		$out = array();
		foreach ( SPET_Database::search_unmatched_etransfer_logs( $term, self::SEARCH_LIMIT ) as $row ) {
			$out[] = array(
				'log_id'     => (int) $row->id,
				'at'         => self::iso( $row->timestamp ),
				'amount'     => number_format( (float) $row->amount, 2, '.', '' ),
				'from_name'  => (string) $row->from_name,
				'from_email' => (string) $row->from_email,
				'reference'  => (string) $row->reference_number,
			);
		}
		return $out;
	}

	/**
	 * Query etransfers.order.
	 *
	 * @param array $params { order_id }.
	 * @return array|WP_Error
	 */
	public static function order_query( array $params ) {
		$order_id = isset( $params['order_id'] ) ? (int) $params['order_id'] : 0;
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;
		// Refunds are WC orders too but have no billing details.
		if ( ! $order || ! method_exists( $order, 'get_billing_email' ) ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}

		return array(
			'order_id'      => $order_id,
			'status'        => (string) $order->get_status(),
			'total'         => number_format( (float) $order->get_total(), 2, '.', '' ),
			'billing_name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'billing_email' => (string) $order->get_billing_email(),
			'on_hold'       => 'on-hold' === $order->get_status(),
		);
	}

	/**
	 * MySQL datetime (database-server time, read as UTC) to ISO 8601.
	 */
	private static function iso( $mysql ): string {
		$ts = strtotime( (string) $mysql . ' UTC' );
		return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : '';
	}
}
