<?php
/**
 * When a suspended player is next eligible to play.
 *
 * A rulebook suspension (§5.10) covers ALL league play — every team the player
 * is on, any night, regular season into playoffs. So "the Nth game" is counted
 * across the union of those teams' schedules, not one team's.
 *
 * The result is a PROJECTION made at issue time. The stored fact is the number
 * of games owed; this only turns it into a date for the email, which says "if
 * the schedule doesn't change". Nothing here binds a notice to an event id.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Eligibility {

	/**
	 * Pick the Nth event from a set. Pure — no WordPress calls.
	 *
	 * @param array $events Each: array( 'id' => int, 'date' => 'Y-m-d H:i:s', 'team_id' => int ).
	 * @param int   $n      Games owed.
	 * @return array array( 'date' => ?string, 'event_id' => int, 'team_id' => int, 'remaining' => int ).
	 */
	public static function pick_nth( array $events, int $n ): array {
		$none = array(
			'date'      => null,
			'event_id'  => 0,
			'team_id'   => 0,
			'remaining' => max( 0, $n ),
		);

		if ( $n <= 0 ) {
			return $none;
		}

		// An event the player appears in through two teams is one game.
		$unique = array();
		foreach ( $events as $event ) {
			$id = (int) ( $event['id'] ?? 0 );
			if ( $id > 0 && ! isset( $unique[ $id ] ) ) {
				$unique[ $id ] = $event;
			}
		}

		$unique = array_values( $unique );
		usort(
			$unique,
			static function ( $a, $b ) {
				$by_date = strcmp( (string) $a['date'], (string) $b['date'] );
				return 0 !== $by_date ? $by_date : ( (int) $a['id'] <=> (int) $b['id'] );
			}
		);

		if ( count( $unique ) < $n ) {
			$none['remaining'] = $n - count( $unique );
			return $none;
		}

		$hit = $unique[ $n - 1 ];

		return array(
			'date'      => (string) $hit['date'],
			'event_id'  => (int) $hit['id'],
			'team_id'   => (int) ( $hit['team_id'] ?? 0 ),
			'remaining' => 0,
		);
	}

	/**
	 * Every team the player is currently on.
	 *
	 * SportsPress stores one sp_current_team meta row per team.
	 *
	 * @param int $player_id Player post id.
	 * @return int[]
	 */
	public static function player_team_ids( int $player_id ): array {
		$ids = array_map( 'absint', (array) get_post_meta( $player_id, 'sp_current_team', false ) );
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Accept only a Y-m-d shaped boundary date. Pure — fallback is passed in.
	 *
	 * @param string $date     Candidate date.
	 * @param string $fallback Used when $date is not Y-m-d shaped.
	 * @return string
	 */
	public static function normalize_after_date( string $date, string $fallback ): string {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : $fallback;
	}

	/**
	 * Project the Nth game the player will sit, across all their teams.
	 *
	 * 'publish' AND 'future' — a fixture dated ahead is stored as 'future'.
	 * Dates compare against post_date (site-local), so the boundary is a
	 * local Y-m-d, matching SPLM_Discipline_Notice_Mail::next_game_label().
	 *
	 * @param int    $player_id  Player post id.
	 * @param string $after_date Local 'Y-m-d'; only events strictly after it count.
	 * @param int    $games      Games owed.
	 * @return array Same shape as pick_nth().
	 */
	public static function next_eligible( int $player_id, string $after_date, int $games ): array {
		$teams = self::player_team_ids( $player_id );
		if ( ! $teams || $games <= 0 ) {
			return self::pick_nth( array(), $games );
		}

		$after_date = self::normalize_after_date( $after_date, current_time( 'Y-m-d' ) );

		$events = array();
		foreach ( $teams as $team_id ) {
			$ids = get_posts(
				array(
					'post_type'      => 'sp_event',
					'post_status'    => array( 'publish', 'future' ),
					'posts_per_page' => $games + 5,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'date_query'     => array(
						array(
							'after'     => $after_date . ' 23:59:59',
							'inclusive' => false,
						),
					),
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						'relation' => 'AND',
						array(
							'key'   => 'sp_team',
							'value' => $team_id,
						),
						// A postponed or cancelled fixture does not use up a suspension game.
						array(
							'relation' => 'OR',
							array(
								'key'     => 'sp_status',
								'value'   => array( 'postponed', 'cancelled' ),
								'compare' => 'NOT IN',
							),
							array(
								'key'     => 'sp_status',
								'compare' => 'NOT EXISTS',
							),
						),
					),
					'fields'         => 'ids',
				)
			);

			foreach ( (array) $ids as $event_id ) {
				if ( self::is_unplayed( (int) $event_id ) ) {
					$events[] = array(
						'id'      => (int) $event_id,
						'date'    => (string) get_post_field( 'post_date', $event_id ),
						'team_id' => (int) $team_id,
					);
				}
			}
		}

		return self::pick_nth( $events, $games );
	}
}
