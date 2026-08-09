<?php
/**
 * GDPR Privacy Exporters and Erasers
 *
 * Registers WordPress privacy data exporters and erasers for player data
 * stored across the SportsPress Admin Tools suite.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Privacy {

	/**
	 * Number of items to process per page/batch.
	 */
	const BATCH_SIZE = 50;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Register data exporters.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['sportspress-admin-tools'] = array(
			'exporter_friendly_name' => __( 'SportsPress Admin Tools', 'sportspress-admin-tools' ),
			'callback'               => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	/**
	 * Register data erasers.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		$erasers['sportspress-admin-tools'] = array(
			'eraser_friendly_name' => __( 'SportsPress Admin Tools', 'sportspress-admin-tools' ),
			'callback'             => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	/**
	 * Find player post IDs associated with an email address.
	 *
	 * Checks spt_email post meta and sp_user meta linked to a WP user with that email.
	 *
	 * @param string $email_address Email address.
	 * @return int[] Array of player post IDs.
	 */
	private function get_player_ids_for_email( $email_address ) {
		global $wpdb;

		$player_ids = array();

		// Players with spt_email meta matching the email.
		$by_email = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
			 WHERE meta_key = 'spt_email' AND meta_value = %s",
				$email_address
			)
		);

		if ( $by_email ) {
			$player_ids = array_merge( $player_ids, array_map( 'intval', $by_email ) );
		}

		// Players linked via sp_user meta to a WP user with this email.
		$user = get_user_by( 'email', $email_address );
		if ( $user ) {
			$by_user = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = 'sp_user' AND meta_value = %s",
					$user->ID
				)
			);

			if ( $by_user ) {
				$player_ids = array_merge( $player_ids, array_map( 'intval', $by_user ) );
			}
		}

		return array_unique( $player_ids );
	}

	/**
	 * Export personal data callback.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function export_personal_data( $email_address, $page = 1 ) {
		// Building the full $all_items list re-runs every export_* query.
		// Cache the assembled list across pagination calls so the costly
		// queries only run once per request. Mirrors the eraser pattern.
		$transient_key = 'spat_privacy_export_' . md5( $email_address );

		if ( 1 === (int) $page ) {
			$all_items = array_merge(
				$this->export_player_records( $email_address ),
				$this->export_registration_logs( $email_address ),
				$this->export_etransfer_logs( $email_address ),
				$this->export_woocommerce_order_links( $email_address )
			);
			set_transient( $transient_key, $all_items, HOUR_IN_SECONDS );
		} else {
			$all_items = get_transient( $transient_key );
			if ( false === $all_items ) {
				// Transient expired — recompute. Exporters don't mutate data,
				// so re-running the queries is safe (just slower).
				$all_items = array_merge(
					$this->export_player_records( $email_address ),
					$this->export_registration_logs( $email_address ),
					$this->export_etransfer_logs( $email_address ),
					$this->export_woocommerce_order_links( $email_address )
				);
				set_transient( $transient_key, $all_items, HOUR_IN_SECONDS );
			}
		}

		$offset       = ( $page - 1 ) * self::BATCH_SIZE;
		$export_items = array_slice( $all_items, $offset, self::BATCH_SIZE );
		$done         = $offset + self::BATCH_SIZE >= count( $all_items );

		if ( $done ) {
			delete_transient( $transient_key );
		}

		return array(
			'data' => $export_items,
			'done' => $done,
		);
	}

	/**
	 * Export player records.
	 *
	 * @param string $email_address Email address.
	 * @return array Export items.
	 */
	private function export_player_records( $email_address ) {
		$items      = array();
		$player_ids = $this->get_player_ids_for_email( $email_address );

		foreach ( $player_ids as $player_id ) {
			$player = get_post( $player_id );
			if ( ! $player || 'sp_player' !== $player->post_type ) {
				continue;
			}

			$data = array(
				array(
					'name'  => __( 'Player Name', 'sportspress-admin-tools' ),
					'value' => $player->post_title,
				),
			);

			$number = get_post_meta( $player_id, 'sp_number', true );
			if ( '' !== $number ) {
				$data[] = array(
					'name'  => __( 'Number', 'sportspress-admin-tools' ),
					'value' => $number,
				);
			}

			$positions = wp_get_object_terms( $player_id, 'sp_position', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $positions ) && ! empty( $positions ) ) {
				$data[] = array(
					'name'  => __( 'Position', 'sportspress-admin-tools' ),
					'value' => implode( ', ', $positions ),
				);
			}

			$teams = wp_get_object_terms( $player_id, 'sp_team', array( 'fields' => 'names' ) );
			if ( is_wp_error( $teams ) ) {
				$teams = array();
			}
			$team_posts = get_post_meta( $player_id, 'sp_team', false );
			if ( ! empty( $team_posts ) ) {
				$team_names = array();
				foreach ( $team_posts as $team_id ) {
					$team_post = get_post( (int) $team_id );
					if ( $team_post ) {
						$team_names[] = $team_post->post_title;
					}
				}
				if ( ! empty( $team_names ) ) {
					$teams = array_unique( array_merge( $teams, $team_names ) );
				}
			}
			if ( ! empty( $teams ) ) {
				$data[] = array(
					'name'  => __( 'Teams', 'sportspress-admin-tools' ),
					'value' => implode( ', ', $teams ),
				);
			}

			$seasons = wp_get_object_terms( $player_id, 'sp_season', array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $seasons ) && ! empty( $seasons ) ) {
				$data[] = array(
					'name'  => __( 'Seasons', 'sportspress-admin-tools' ),
					'value' => implode( ', ', $seasons ),
				);
			}

			$statistics = get_post_meta( $player_id, 'sp_statistics', true );
			if ( ! empty( $statistics ) ) {
				$data[] = array(
					'name'  => __( 'Statistics', 'sportspress-admin-tools' ),
					'value' => wp_json_encode( $statistics ),
				);
			}

			$email = get_post_meta( $player_id, 'spt_email', true );
			if ( $email ) {
				$data[] = array(
					'name'  => __( 'Email', 'sportspress-admin-tools' ),
					'value' => $email,
				);
			}

			$items[] = array(
				'group_id'          => 'sportspress-players',
				'group_label'       => __( 'SportsPress Players', 'sportspress-admin-tools' ),
				'group_description' => __( 'Player records from SportsPress.', 'sportspress-admin-tools' ),
				'item_id'           => "sp-player-{$player_id}",
				'data'              => $data,
			);
		}

		return $items;
	}

	/**
	 * Export registration logs linked to the email via player_id or order billing email.
	 *
	 * @param string $email_address Email address.
	 * @return array Export items.
	 */
	private function export_registration_logs( $email_address ) {
		global $wpdb;

		$table = $wpdb->prefix . 'spat_registration_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$items      = array();
		$player_ids = $this->get_player_ids_for_email( $email_address );

		if ( empty( $player_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $player_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE player_id IN ({$placeholders})",
				$player_ids
			)
		);

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'sportspress-registration-logs',
				'group_label'       => __( 'SportsPress Registration Logs', 'sportspress-admin-tools' ),
				'group_description' => __( 'Player registration activity logs.', 'sportspress-admin-tools' ),
				'item_id'           => "sp-reg-log-{$row->id}",
				'data'              => array(
					array(
						'name'  => __( 'Date', 'sportspress-admin-tools' ),
						'value' => $row->timestamp,
					),
					array(
						'name'  => __( 'Customer Name', 'sportspress-admin-tools' ),
						'value' => $row->customer_name,
					),
					array(
						'name'  => __( 'Season', 'sportspress-admin-tools' ),
						'value' => $row->season,
					),
					array(
						'name'  => __( 'Position', 'sportspress-admin-tools' ),
						'value' => $row->position,
					),
					array(
						'name'  => __( 'Action', 'sportspress-admin-tools' ),
						'value' => $row->action,
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export e-transfer logs where from_email matches.
	 *
	 * @param string $email_address Email address.
	 * @return array Export items.
	 */
	private function export_etransfer_logs( $email_address ) {
		global $wpdb;

		$table = $wpdb->prefix . 'spat_etransfer_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$items = array();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE from_email = %s",
				$email_address
			)
		);

		foreach ( $rows as $row ) {
			$items[] = array(
				'group_id'          => 'sportspress-etransfer-logs',
				'group_label'       => __( 'SportsPress E-Transfer Logs', 'sportspress-admin-tools' ),
				'group_description' => __( 'E-transfer payment logs.', 'sportspress-admin-tools' ),
				'item_id'           => "sp-et-log-{$row->id}",
				'data'              => array(
					array(
						'name'  => __( 'Date', 'sportspress-admin-tools' ),
						'value' => $row->timestamp,
					),
					array(
						'name'  => __( 'From Name', 'sportspress-admin-tools' ),
						'value' => $row->from_name,
					),
					array(
						'name'  => __( 'From Email', 'sportspress-admin-tools' ),
						'value' => $row->from_email,
					),
					array(
						'name'  => __( 'Amount', 'sportspress-admin-tools' ),
						'value' => $row->amount,
					),
					array(
						'name'  => __( 'Reference', 'sportspress-admin-tools' ),
						'value' => $row->reference_number,
					),
					array(
						'name'  => __( 'Result', 'sportspress-admin-tools' ),
						'value' => $row->result,
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Export WooCommerce order links via sp_user -> user -> orders.
	 *
	 * @param string $email_address Email address.
	 * @return array Export items.
	 */
	private function export_woocommerce_order_links( $email_address ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}

		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array();
		}

		$player_ids = $this->get_player_ids_for_email( $email_address );
		if ( empty( $player_ids ) ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user->ID,
				'limit'       => -1,
				'return'      => 'ids',
			)
		);

		if ( empty( $orders ) ) {
			return array();
		}

		$items = array();
		foreach ( $orders as $order_id ) {
			$items[] = array(
				'group_id'          => 'sportspress-order-links',
				'group_label'       => __( 'SportsPress Order Links', 'sportspress-admin-tools' ),
				'group_description' => __( 'WooCommerce orders linked to SportsPress player records.', 'sportspress-admin-tools' ),
				'item_id'           => "sp-order-link-{$order_id}",
				'data'              => array(
					array(
						'name'  => __( 'Order ID', 'sportspress-admin-tools' ),
						'value' => $order_id,
					),
					array(
						'name'  => __( 'Linked Players', 'sportspress-admin-tools' ),
						'value' => implode( ', ', $player_ids ),
					),
				),
			);
		}

		return $items;
	}

	/**
	 * Erase personal data callback.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          Page number.
	 * @return array
	 */
	public function erase_personal_data( $email_address, $page = 1 ) {
		$items_removed  = 0;
		$items_retained = 0;
		$messages       = array();

		// Per-player work is paginated; bulk DB sweeps run only on the first page.
		// Cache the player-id list across pages so a row added/removed mid-erase
		// doesn't shift batch boundaries.
		$transient_key = 'spat_privacy_erase_' . md5( $email_address );
		if ( 1 === (int) $page ) {
			$player_ids = $this->get_player_ids_for_email( $email_address );
			set_transient( $transient_key, $player_ids, HOUR_IN_SECONDS );
		} else {
			$player_ids = get_transient( $transient_key );
			if ( false === $player_ids ) {
				// Transient expired (slow WP-Cron, paused queue, etc.). We MUST NOT
				// re-query here: earlier pages already anonymized spt_email /
				// sp_user meta for the players they processed, so a fresh query
				// would return an empty/incorrect player set and falsely report
				// success. Tell the eraser to stop cleanly; re-running the eraser
				// will rebuild the list and finish any remaining work (including
				// the deferred log sweeps, which run on the final page).
				return array(
					'items_removed'  => 0,
					'items_retained' => 0,
					'messages'       => array(
						__( 'Erasure session cache expired before pagination finished. Records processed so far have already been anonymized; re-run the eraser to finish any remaining records and log entries.', 'sportspress-admin-tools' ),
					),
					'done'           => true,
				);
			}
		}

		$offset    = ( $page - 1 ) * self::BATCH_SIZE;
		$batch_ids = array_slice( $player_ids, $offset, self::BATCH_SIZE );

		// Delete THIS batch's registration-log rows before the loop below strips
		// the meta that makes them discoverable. The sweep used to be deferred to
		// the final page, which meant an interrupted multi-page run (expired
		// transient, paused queue, fatal) left the rows for pages 1..N-1
		// permanently unreachable: get_player_ids_for_email() resolves player ids
		// from the spt_email / sp_user meta this loop deletes, so a re-run can no
		// longer find them and the "re-run to finish" message was false (M2).
		// Deleting per batch rather than per player keeps it to one DELETE and one
		// message per page while still guaranteeing rows go first; it also covers
		// ids that the loop skips on the post-type check.
		$items_removed += $this->erase_registration_logs( $batch_ids, $messages );

		foreach ( $batch_ids as $player_id ) {
			$player = get_post( $player_id );
			if ( ! $player || 'sp_player' !== $player->post_type ) {
				continue;
			}
			$update_result = wp_update_post(
				array(
					'ID'         => $player_id,
					'post_title' => __( 'Anonymous Player', 'sportspress-admin-tools' ),
					'post_name'  => 'anonymous-player-' . $player_id,
				),
				true
			);
			if ( is_wp_error( $update_result ) || 0 === $update_result ) {
				$items_retained++;
				$messages[]      = sprintf(
					/* translators: %d: player post ID */
					__( 'Could not anonymize player %d (post update was blocked).', 'sportspress-admin-tools' ),
					$player_id
				);
				continue;
			}
			delete_post_meta( $player_id, 'spt_email' );
			delete_post_meta( $player_id, 'sp_user' );
			$items_removed++;
		}

		$done = ( $offset + self::BATCH_SIZE ) >= count( $player_ids );

		// The e-transfer sweep still runs once, on the FINAL page. Unlike the
		// registration logs it is keyed on from_email, not on player meta, so an
		// interrupted run leaves it fully discoverable and a re-run completes it —
		// the recovery message is accurate for this table. Deferring it also keeps
		// each page's items_removed scoped to the work that page performed, so the
		// per-page totals reported to the WP eraser sum to the true number of
		// removed items instead of front-loading onto page 1.
		if ( $done ) {
			$items_removed += $this->erase_etransfer_logs( $email_address, $messages );
		}

		if ( $done ) {
			delete_transient( $transient_key );
		}

		return array(
			'items_removed'  => $items_removed,
			'items_retained' => $items_retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	/**
	 * Delete registration logs for the given player IDs.
	 *
	 * @param int[]    $player_ids Player post IDs.
	 * @param string[] $messages   Messages array (passed by reference).
	 * @return int Number of items removed.
	 */
	private function erase_registration_logs( $player_ids, &$messages ) {
		global $wpdb;

		$table = $wpdb->prefix . 'spat_registration_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		if ( empty( $player_ids ) ) {
			return 0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $player_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE player_id IN ({$placeholders})",
				$player_ids
			)
		);

		if ( $count > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE player_id IN ({$placeholders})",
					$player_ids
				)
			);
			$messages[] = sprintf(
				/* translators: %d: number of registration log entries deleted */
				__( 'Deleted %d registration log entries.', 'sportspress-admin-tools' ),
				$count
			);
		}

		return $count;
	}

	/**
	 * Anonymize e-transfer logs for the given email.
	 *
	 * @param string   $email_address Email address.
	 * @param string[] $messages      Messages array (passed by reference).
	 * @return int Number of items removed.
	 */
	private function erase_etransfer_logs( $email_address, &$messages ) {
		global $wpdb;

		$table = $wpdb->prefix . 'spat_etransfer_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return 0;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE from_email = %s",
				$email_address
			)
		);

		if ( $count > 0 ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET from_name = %s, from_email = %s, webhook_data = NULL, payment_data = NULL WHERE from_email = %s",
					__( 'Redacted', 'sportspress-admin-tools' ),
					__( 'Redacted', 'sportspress-admin-tools' ),
					$email_address
				)
			);
			$messages[] = sprintf(
				/* translators: %d: number of e-transfer log entries anonymized */
				__( 'Anonymized %d e-transfer log entries.', 'sportspress-admin-tools' ),
				$count
			);
		}

		return $count;
	}
}
