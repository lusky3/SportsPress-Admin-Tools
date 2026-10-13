<?php
/**
 * Reminder sections for the weekly penalty digest.
 *
 * Two lists, no new cron: suspensions whose eligible date has passed without
 * being marked served, and indefinite suspensions still awaiting a decision.
 * The notice table is append-only for manual notices, so a row replaced by a
 * child (decide, amend, revoke) is skipped.
 *
 * incident_note is convener-only and is never selected into a reminder row.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Digest_Reminders {

	const CAP = 25;

	/**
	 * Load both reminder lists for a season.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param int    $season_id Season term id.
	 * @param string $today     Site-local 'Y-m-d'; defaults to today.
	 * @return array array( 'overdue' => list, 'indefinite' => list ), each list array( 'rows' => array, 'more' => int ).
	 */
	public static function load( int $season_id, string $today = '' ): array {
		if ( '' === $today ) {
			$today = wp_date( 'Y-m-d' );
		}

		$rows     = self::fetch( $season_id );
		$replaced = SPLM_Discipline_Notice_Database::replaced_ids( array_map( static fn( $r ) => (int) $r->id, $rows ) );
		$live     = array_filter( $rows, static fn( $r ) => ! in_array( (int) $r->id, $replaced, true ) );

		return array(
			'overdue'    => self::cap( array_filter( $live, static fn( $r ) => self::is_overdue( $r, $today ) ), 'eligible' ),
			'indefinite' => self::cap( array_filter( $live, array( __CLASS__, 'is_awaiting_decision' ) ), 'issued' ),
		);
	}

	/**
	 * Whether any list has rows.
	 *
	 * @param array $data Result of load().
	 * @return bool
	 */
	public static function has_any( array $data ): bool {
		return ! empty( $data['overdue']['rows'] ) || ! empty( $data['indefinite']['rows'] );
	}

	/**
	 * Render both sections; empty string when there is nothing to remind about.
	 *
	 * @param array $data Result of load().
	 * @return string
	 */
	public static function render( array $data ): string {
		$out  = self::section(
			__( 'Suspensions past their eligible date, not yet marked served', 'sportspress-league-manager' ),
			$data['overdue'] ?? array(),
			__( 'Eligible', 'sportspress-league-manager' )
		);
		$out .= self::section(
			__( 'Indefinite suspensions awaiting a decision', 'sportspress-league-manager' ),
			$data['indefinite'] ?? array(),
			__( 'Issued', 'sportspress-league-manager' )
		);

		return $out;
	}

	/**
	 * Candidate rows: sent rows with an eligible date, or sent manual indefinite rows.
	 * Narrow on purpose; the PHP predicates below make the final call.
	 *
	 * @param int $season_id Season term id.
	 * @return object[]
	 */
	private static function fetch( int $season_id ): array {
		global $wpdb;

		if ( ! SPLM_Discipline_Notice_Database::table_exists() ) {
			return array();
		}

		$table = SPLM_Discipline_Notice_Database::table_name();
		// incident_note and note are deliberately not selected.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name, not a value.
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB
			$wpdb->prepare(
				"SELECT id, player_id, status, source, scope, outcome, consequence, infraction_title, eligible_on, sent_at, created_at
				 FROM {$table}
				 WHERE season_id = %d AND status = %s AND ( eligible_on IS NOT NULL OR ( source = %s AND outcome = %s ) )
				 ORDER BY id ASC",
				$season_id,
				SPLM_Discipline_Notice_Database::STATUS_SENT,
				SPLM_Discipline_Notice_Database::SOURCE_MANUAL,
				'indefinite'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Sent suspension whose eligible date has passed.
	 *
	 * @param object $row   Notice row.
	 * @param string $today Site-local 'Y-m-d'.
	 * @return bool
	 */
	private static function is_overdue( object $row, string $today ): bool {
		$date = (string) ( $row->eligible_on ?? '' );

		return 'sent' === (string) $row->status
			&& 'suspend' === (string) $row->consequence
			&& '' !== $date
			&& substr( $date, 0, 10 ) < $today;
	}

	/**
	 * Original manual indefinite notice, sent, not yet replaced.
	 *
	 * @param object $row Notice row.
	 * @return bool
	 */
	private static function is_awaiting_decision( object $row ): bool {
		return 'sent' === (string) $row->status
			&& 'manual' === (string) $row->source
			&& 'manual' === (string) $row->scope
			&& 'indefinite' === (string) $row->outcome;
	}

	/**
	 * Cap a list and flatten its rows.
	 *
	 * @param object[] $rows     Filtered rows.
	 * @param string   $date_key 'eligible' (eligible_on) or 'issued' (sent_at/created_at).
	 * @return array array( 'rows' => array, 'more' => int ).
	 */
	private static function cap( array $rows, string $date_key ): array {
		$rows = array_values( $rows );
		$out  = array();

		foreach ( array_slice( $rows, 0, self::CAP ) as $row ) {
			$raw   = 'eligible' === $date_key ? (string) $row->eligible_on : (string) ( $row->sent_at ? $row->sent_at : $row->created_at );
			$out[] = array(
				'player' => (string) get_the_title( (int) $row->player_id ),
				'title'  => (string) $row->infraction_title,
				'date'   => substr( $raw, 0, 10 ),
			);
		}

		return array(
			'rows' => $out,
			'more' => max( 0, count( $rows ) - self::CAP ),
		);
	}

	/**
	 * A DATE string in the site's date format, without timezone shifting.
	 *
	 * @param string $date 'Y-m-d'.
	 * @return string
	 */
	private static function format_date( string $date ): string {
		$stamp = strtotime( $date . ' 00:00:00 UTC' );

		return $stamp ? (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $stamp, new DateTimeZone( 'UTC' ) ) : $date;
	}

	/**
	 * One titled table plus an overflow line.
	 *
	 * @param string $heading Section heading.
	 * @param array  $list    array( 'rows' => array, 'more' => int ).
	 * @param string $date_th Date column heading.
	 * @return string
	 */
	private static function section( string $heading, array $list, string $date_th ): string {
		if ( empty( $list['rows'] ) ) {
			return '';
		}

		$out  = '<h3>' . esc_html( $heading ) . '</h3>';
		$out .= '<table cellpadding="6" border="1" style="border-collapse:collapse"><tr><th>' . esc_html__( 'Player', 'sportspress-league-manager' )
			. '</th><th>' . esc_html__( 'Infraction', 'sportspress-league-manager' ) . '</th><th>' . esc_html( $date_th ) . '</th></tr>';
		foreach ( $list['rows'] as $row ) {
			$out .= '<tr><td>' . esc_html( $row['player'] ) . '</td><td>' . esc_html( $row['title'] ) . '</td><td>' . esc_html( self::format_date( $row['date'] ) ) . '</td></tr>';
		}
		$out .= '</table>';

		if ( ! empty( $list['more'] ) ) {
			$out .= '<p>' . esc_html(
				sprintf(
					/* translators: %d: number of rows not shown. */
					__( '+%d more — see the dashboard Notices page', 'sportspress-league-manager' ),
					(int) $list['more']
				)
			) . '</p>';
		}

		return $out;
	}
}
