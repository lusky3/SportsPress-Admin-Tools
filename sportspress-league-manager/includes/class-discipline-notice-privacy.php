<?php
/**
 * GDPR export and erase for disciplinary notices.
 *
 * Registered by league-manager rather than added to the parent plugin's
 * class-privacy.php: that file lives in sportspress-admin-tools and knows
 * nothing about any league-manager table, and making the parent read a child's
 * schema inverts the dependency direction this codebase maintains. WordPress
 * supports any number of exporters keyed by name, so this is the same coverage
 * with the ownership the right way round.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Privacy {

	const BATCH_SIZE = 50;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_erasers' ) );
	}

	/**
	 * Register the exporter.
	 *
	 * @param array $exporters Existing exporters.
	 * @return array
	 */
	public function register_exporters( $exporters ) {
		$exporters['sportspress-league-manager-discipline'] = array(
			'exporter_friendly_name' => __( 'SportsPress Disciplinary Notices', 'sportspress-league-manager' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @param array $erasers Existing erasers.
	 * @return array
	 */
	public function register_erasers( $erasers ) {
		$erasers['sportspress-league-manager-discipline'] = array(
			'eraser_friendly_name' => __( 'SportsPress Disciplinary Notices', 'sportspress-league-manager' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * Export notice rows for a person.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          1-indexed page.
	 * @return array array( 'data' => array, 'done' => bool ).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function export( $email_address, $page = 1 ) {
		$rows   = $this->rows_for( $email_address );
		$offset = ( max( 1, (int) $page ) - 1 ) * self::BATCH_SIZE;
		$slice  = array_slice( $rows, $offset, self::BATCH_SIZE );

		$items = array();
		foreach ( $slice as $row ) {
			$items[] = array(
				'group_id'          => 'splm-discipline-notices',
				'group_label'       => __( 'Disciplinary Notices', 'sportspress-league-manager' ),
				'group_description' => __( 'Penalty-threshold warnings and suspensions issued to this player.', 'sportspress-league-manager' ),
				'item_id'           => 'splm-notice-' . (int) $row->id,
				'data'              => array(
					array(
						'name'  => __( 'Recorded', 'sportspress-league-manager' ),
						'value' => $row->created_at,
					),
					array(
						'name'  => __( 'Threshold', 'sportspress-league-manager' ),
						'value' => $row->tier_key,
					),
					array(
						'name'  => __( 'Consequence', 'sportspress-league-manager' ),
						'value' => 'suspend' === $row->consequence
							? sprintf(
								/* translators: %d: number of games. */
								_n( 'Suspension — %d game', 'Suspension — %d games', (int) $row->games, 'sportspress-league-manager' ),
								(int) $row->games
							)
							: __( 'Warning', 'sportspress-league-manager' ),
					),
					array(
						'name'  => __( 'Penalty minutes at the time', 'sportspress-league-manager' ),
						'value' => (int) $row->value_at_fire,
					),
					array(
						'name'  => __( 'Status', 'sportspress-league-manager' ),
						'value' => $row->status,
					),
					array(
						'name'  => __( 'Sent to', 'sportspress-league-manager' ),
						'value' => $row->recipient,
					),
					array(
						'name'  => __( 'Sent at', 'sportspress-league-manager' ),
						'value' => $row->sent_at,
					),
				),
			);
		}

		return array(
			'data' => $items,
			'done' => ( $offset + self::BATCH_SIZE ) >= count( $rows ),
		);
	}

	/**
	 * Anonymise notice rows for a person.
	 *
	 * Rows are anonymised rather than deleted: the league has a legitimate
	 * interest in the fact that a suspension was issued, and the identifying
	 * data is the address and the copied recipients, not the row's existence.
	 * player_id is zeroed so the row can no longer be tied back.
	 *
	 * @param string $email_address Email address.
	 * @param int    $page          1-indexed page.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function erase( $email_address, $page = 1 ) {
		global $wpdb;

		$messages = array();

		if ( ! SPLM_Discipline_Notice_Database::table_exists() ) {
			return $this->erase_result( 0, 0, $messages, true );
		}

		// The email -> player lookup resolves through spt_email / sp_user post
		// meta, which an earlier page's erasure may already have removed. Cache
		// the id list on page 1 and stop cleanly if it is gone, rather than
		// re-querying and falsely reporting success — the failure mode the
		// parent plugin's eraser documents at length.
		$transient_key = 'splm_notice_erase_' . md5( $email_address );

		if ( 1 === (int) $page ) {
			$player_ids = SPLM_Discipline_Notice_Recipients::players_for_email( $email_address );
			set_transient( $transient_key, $player_ids, HOUR_IN_SECONDS );
		} else {
			$player_ids = get_transient( $transient_key );
			if ( false === $player_ids ) {
				return $this->erase_result(
					0,
					0,
					array( __( 'Erasure session cache expired before pagination finished. Notices processed so far have already been anonymized; re-run the eraser to finish any remaining rows.', 'sportspress-league-manager' ) ),
					true
				);
			}
		}

		if ( ! $player_ids ) {
			delete_transient( $transient_key );
			return $this->erase_result( 0, 0, $messages, true );
		}

		$offset    = ( max( 1, (int) $page ) - 1 ) * self::BATCH_SIZE;
		$batch_ids = array_slice( $player_ids, $offset, self::BATCH_SIZE );
		$removed   = 0;

		$table    = SPLM_Discipline_Notice_Database::table_name();
		$redacted = __( 'Redacted', 'sportspress-league-manager' );

		foreach ( $batch_ids as $player_id ) {
			$count = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE player_id = %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value.
					(int) $player_id
				)
			);

			if ( ! $count ) {
				continue;
			}

			$wpdb->query( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value.
					"UPDATE {$table} SET recipient = %s, bcc = NULL, note = NULL, player_id = 0 WHERE player_id = %d",
					$redacted,
					(int) $player_id
				)
			);

			$removed += $count;
		}

		if ( $removed ) {
			$messages[] = sprintf(
				/* translators: %d: number of notices anonymized. */
				_n( 'Anonymized %d disciplinary notice.', 'Anonymized %d disciplinary notices.', $removed, 'sportspress-league-manager' ),
				$removed
			);
		}

		$done = ( $offset + self::BATCH_SIZE ) >= count( $player_ids );
		if ( $done ) {
			delete_transient( $transient_key );
		}

		return $this->erase_result( $removed, 0, $messages, $done );
	}

	/**
	 * Notice rows belonging to a person.
	 *
	 * @param string $email_address Email address.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function rows_for( string $email_address ): array {
		global $wpdb;

		if ( ! SPLM_Discipline_Notice_Database::table_exists() ) {
			return array();
		}

		$player_ids = SPLM_Discipline_Notice_Recipients::players_for_email( $email_address );
		if ( ! $player_ids ) {
			return array();
		}

		$table        = SPLM_Discipline_Notice_Database::table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $player_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name and a list of literal placeholders; values are bound below.
				"SELECT * FROM {$table} WHERE player_id IN ({$placeholders}) ORDER BY id ASC",
				$player_ids
			)
		);
	}

	/**
	 * The eraser return shape.
	 *
	 * @param int   $removed  Items removed.
	 * @param int   $retained Items retained.
	 * @param array $messages Messages.
	 * @param bool  $done     Whether the eraser is finished.
	 * @return array
	 */
	private function erase_result( int $removed, int $retained, array $messages, bool $done ): array {
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}
}
