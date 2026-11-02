<?php
/**
 * Availability: In / Out / Maybe per player per game.
 *
 * The rules (can_edit, validate_status, summarize) are pure and unit-tested;
 * the repository methods are thin $wpdb wrappers over spbn_availability.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Availability {

	const STATUSES = array( 'in', 'out', 'maybe' );

	/** Longest note, in characters. */
	const NOTE_MAX = 140;

	/** Players may still correct their answer this long after the game starts. */
	const EDIT_GRACE_SECONDS = 3 * 3600;

	/**
	 * Whether a status change is still allowed. Organizers always may; players
	 * until the game has been on for three hours.
	 */
	public static function can_edit( int $event_start_ts, int $now_ts, bool $is_organizer ): bool {
		return $is_organizer || $now_ts <= $event_start_ts + self::EDIT_GRACE_SECONDS;
	}

	/**
	 * The normalised status (trimmed, lower case), or null when it is not one of
	 * STATUSES.
	 */
	public static function validate_status( string $status ): ?string {
		$status = strtolower( trim( $status ) );
		return in_array( $status, self::STATUSES, true ) ? $status : null;
	}

	/**
	 * Counts for one side of a game.
	 *
	 * Rows for players off the roster are ignored; a roster member with no row
	 * (or an unknown status) is `none`. Accepted spares each add one to `in`,
	 * and one to `goalies_in` when they were called as a goalie.
	 *
	 * @param array $rows    Availability rows {player_id,status}.
	 * @param array $roster  Roster entries {player_id,positions[]}.
	 * @param array $spares  Accepted spares {position:'skater'|'goalie'}.
	 * @return array{in:int,out:int,maybe:int,none:int,goalies_in:int,spares:int}
	 */
	public static function summarize( array $rows, array $roster, array $spares = array() ): array {
		$goalie = array();
		foreach ( $roster as $member ) {
			$goalie[ (int) $member['player_id'] ] = self::is_goalie( (array) ( $member['positions'] ?? array() ) );
		}

		$answers = array();
		foreach ( $rows as $row ) {
			$player_id = (int) $row['player_id'];
			$status    = self::validate_status( (string) ( $row['status'] ?? '' ) );
			if ( isset( $goalie[ $player_id ] ) && null !== $status ) {
				$answers[ $player_id ] = $status;
			}
		}

		$out = array(
			'in'         => 0,
			'out'        => 0,
			'maybe'      => 0,
			'none'       => count( $goalie ) - count( $answers ),
			'goalies_in' => 0,
			'spares'     => count( $spares ),
		);
		foreach ( $answers as $player_id => $status ) {
			++$out[ $status ];
			if ( 'in' === $status && $goalie[ $player_id ] ) {
				++$out['goalies_in'];
			}
		}
		foreach ( $spares as $spare ) {
			++$out['in'];
			if ( 'goalie' === ( $spare['position'] ?? '' ) ) {
				++$out['goalies_in'];
			}
		}
		return $out;
	}

	/**
	 * Every availability row for a game.
	 *
	 * @return array<int,array{player_id:int,team_id:int,status:string,note:string,updated_at:string}>
	 */
	public static function get_for_event( int $event_id ): array {
		global $wpdb;
		$table = SPBN_Schema::tables()['availability'];

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT player_id, team_id, status, note, updated_at FROM {$table} WHERE event_id = %d ORDER BY updated_at ASC",
				$event_id
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[] = array(
				'player_id'  => (int) $row['player_id'],
				'team_id'    => (int) $row['team_id'],
				'status'     => (string) $row['status'],
				'note'       => (string) $row['note'],
				'updated_at' => (string) $row['updated_at'],
			);
		}
		return $out;
	}

	/**
	 * Insert or replace one player's answer for a game.
	 *
	 * Callers are expected to validate the status first (validate_status) and map
	 * a bad one to a 400; reaching here with one is a bug, so it throws rather
	 * than writing nothing quietly.
	 *
	 * @return array{player_id:int,team_id:int,status:string,note:string,updated_at:string}
	 * @throws InvalidArgumentException On an unknown status.
	 * @throws RuntimeException         When the write fails.
	 */
	public static function set( int $event_id, int $player_id, int $team_id, string $status, string $note, int $by ): array {
		global $wpdb;

		$clean_status = self::validate_status( $status );
		if ( null === $clean_status ) {
			throw new InvalidArgumentException( 'Unknown availability status.' );
		}

		$clean_note = mb_substr( trim( sanitize_text_field( $note ) ), 0, self::NOTE_MAX );
		$now        = gmdate( 'Y-m-d H:i:s' );
		$table      = SPBN_Schema::tables()['availability'];

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (event_id, player_id, team_id, status, note, updated_by, updated_at)
				VALUES (%d,%d,%d,%s,%s,%d,%s)
				ON DUPLICATE KEY UPDATE team_id = VALUES(team_id), status = VALUES(status), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)",
				$event_id,
				$player_id,
				$team_id,
				$clean_status,
				$clean_note,
				$by,
				$now
			)
		);
		if ( false === $result ) {
			throw new RuntimeException( 'Availability write failed.' );
		}

		return array(
			'player_id'  => $player_id,
			'team_id'    => $team_id,
			'status'     => $clean_status,
			'note'       => $clean_note,
			'updated_at' => $now,
		);
	}

	/**
	 * One player's answers across a set of games.
	 *
	 * @param int[] $event_ids Event ids.
	 * @return array<int,string> event id => status, only for games with an answer.
	 */
	public static function for_player_events( int $player_id, array $event_ids ): array {
		global $wpdb;

		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return array();
		}
		$table        = SPBN_Schema::tables()['availability'];
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT event_id, status FROM {$table} WHERE player_id = %d AND event_id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				array_merge( array( $player_id ), $event_ids )
			),
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['event_id'] ] = (string) $row['status'];
		}
		return $out;
	}

	/**
	 * Whether any of a player's position names marks a goalie. Matches by name
	 * so Bench does not depend on player-tools being active.
	 */
	private static function is_goalie( array $positions ): bool {
		foreach ( $positions as $name ) {
			if ( is_string( $name ) && 1 === preg_match( '/goal/i', $name ) ) {
				return true;
			}
		}
		return false;
	}
}
