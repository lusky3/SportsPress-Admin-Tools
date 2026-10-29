<?php
/**
 * Read-only audit of past name-only registration matches.
 *
 * Before SPPR_Identity_Check existed, a name match was accepted with nothing to
 * back it up and then overwrote the record's email and user link. This scans
 * every logged name-only match and re-judges it with today's policy, flagging
 * the ones where the order's customer contradicts the record's owner, stored
 * email, or the other orders linked to it.
 *
 * It judges against the records as they are NOW. A wrong match that also
 * overwrote the record's email and user link may therefore look clean itself;
 * the real owner's orders on the same record are what get flagged instead, and
 * each flagged row names the orders it disagrees with so both sides can be
 * found. Nothing is written: no posts, meta, orders or log rows.
 *
 * @package SportsPress_Player_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Name_Match_Audit {

	/** Logged actions that came from a name-only match. */
	const ACTIONS = array(
		'player_found_by_name',
		'player_found_by_normalized_name',
		'player_found_by_name_unverified',
		'player_found_by_normalized_name_unverified',
	);

	/** @var SPPR_Identity_Check */
	private $check;

	/**
	 * @param SPPR_Identity_Check|null $check Policy to judge with (stored email always compared).
	 */
	public function __construct( $check = null ) {
		$this->check = $check ? $check : new SPPR_Identity_Check( true );
	}

	/**
	 * Judge every logged name-only match.
	 *
	 * @param int[] $player_ids Only matches against these players; empty for all.
	 * @return array{scanned:int,skipped:int,findings:array<int,array<string,mixed>>}
	 *         skipped counts rows whose order or player no longer exists.
	 */
	public function run( $player_ids = array() ) {
		$report = array(
			'scanned'  => 0,
			'skipped'  => 0,
			'findings' => array(),
		);

		foreach ( $this->log_rows( $player_ids ) as $row ) {
			++$report['scanned'];
			$finding = $this->audit_row( $row );
			if ( null === $finding ) {
				++$report['skipped'];
			} elseif ( array() !== $finding ) {
				$report['findings'][] = $finding;
			}
		}

		return $report;
	}

	/**
	 * @param int[] $player_ids Only these players; empty for all.
	 * @return object[] Log rows, oldest first.
	 */
	private function log_rows( $player_ids ) {
		global $wpdb;
		$table = $wpdb->prefix . 'spat_registration_logs';
		$args  = self::ACTIONS;
		$where = 'player_id > 0 AND action IN (' . implode( ',', array_fill( 0, count( $args ), '%s' ) ) . ')';
		if ( ! empty( $player_ids ) ) {
			$where .= ' AND player_id IN (' . implode( ',', array_fill( 0, count( $player_ids ), '%d' ) ) . ')';
			$args   = array_merge( $args, array_map( 'intval', $player_ids ) );
		}
		$sql = "SELECT id, timestamp, order_id, customer_name, player_id, action FROM `{$table}` WHERE {$where} ORDER BY id ASC";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is internal; every value is a placeholder.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
		return (array) $rows;
	}

	/**
	 * @param object $row Log row.
	 * @return array<string,mixed>|null A finding, array() when the match holds
	 *         up, null when the order or player is gone.
	 */
	private function audit_row( $row ) {
		$order_id  = (int) $row->order_id;
		$player_id = (int) $row->player_id;
		$order     = wc_get_order( $order_id );
		$player    = get_post( $player_id );
		if ( ! $order || ! $player ) {
			return null;
		}

		$customer_id = (int) $order->get_user_id();
		$email       = (string) $order->get_billing_email();
		$result      = $this->check->assess( $player_id, $customer_id, $email, $order_id );
		if ( SPPR_Identity_Check::CONFLICT !== $result['verdict'] ) {
			return array();
		}

		return array(
			'log_id'        => (int) $row->id,
			'logged'        => (string) $row->timestamp,
			'order_id'      => $order_id,
			'customer_id'   => $customer_id,
			'billing_email' => $email,
			'player_id'     => $player_id,
			'player'        => (string) $player->post_title,
			'owner_id'      => $this->check->owner_user_id( $player_id ),
			'stored_email'  => (string) get_post_meta( $player_id, 'spt_email', true ),
			'action'        => (string) $row->action,
			'reasons'       => implode( ',', $result['reasons'] ),
			'other_orders'  => implode( ',', $result['orders'] ),
		);
	}
}
