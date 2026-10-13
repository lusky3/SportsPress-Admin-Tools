<?php
/**
 * A player's games, newest first, for the incident-match picker on the
 * suspension form.
 *
 * Covers every team the player has in the season, up to and including today
 * (site-local), and leaves out postponed and cancelled fixtures the same way
 * the eligibility query does. merge_events() is pure and calls no WordPress
 * function.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incident-match picker data.
 */
class SPLM_Discipline_Incident_Games {

	/**
	 * Games for the picker.
	 *
	 * @param int $player_id Player post id.
	 * @param int $season_id Season term id.
	 * @param int $limit     Maximum games returned.
	 * @return array[] Each { id, title, date (Y-m-d), label }.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function for_player( int $player_id, int $season_id, int $limit = 40 ): array {
		$teams = SPLM_Discipline_Eligibility::player_team_ids( $player_id, $season_id );
		if ( ! $teams ) {
			return array();
		}

		$today    = current_time( 'Y-m-d' );
		$per_team = array();
		foreach ( $teams as $team_id ) {
			$per_team[] = self::team_events( (int) $team_id, $season_id, $today, $limit );
		}

		$items = array();
		foreach ( self::merge_events( $per_team, $limit ) as $event ) {
			$items[] = self::to_item( $event );
		}

		return $items;
	}

	/**
	 * De-duplicate by event id, sort by date then id descending, and cap.
	 * Pure.
	 *
	 * @param array[] $per_team_events One list of { id, title, date } per team.
	 * @param int     $limit           Maximum events returned.
	 * @return array[]
	 */
	public static function merge_events( array $per_team_events, int $limit ): array {
		$by_id = array();
		foreach ( $per_team_events as $events ) {
			foreach ( $events as $event ) {
				$by_id[ (int) $event['id'] ] = $event;
			}
		}

		$merged = array_values( $by_id );
		usort(
			$merged,
			static function ( array $a, array $b ): int {
				return array( $b['date'], $b['id'] ) <=> array( $a['date'], $a['id'] );
			}
		);

		return array_slice( $merged, 0, max( 0, $limit ) );
	}

	/**
	 * One team's events up to today, as { id, title, date } with the raw
	 * site-local post_date.
	 *
	 * @param int    $team_id   Team post id.
	 * @param int    $season_id Season term id.
	 * @param string $today     Local 'Y-m-d'.
	 * @param int    $limit     Cap.
	 * @return array[]
	 */
	private static function team_events( int $team_id, int $season_id, string $today, int $limit ): array {
		$ids = get_posts( self::query_args( $team_id, $season_id, $today, $limit ) );

		$events = array();
		foreach ( (array) $ids as $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post ) {
				continue;
			}
			$events[] = array(
				'id'    => (int) $event_id,
				'title' => (string) $post->post_title,
				'date'  => (string) $post->post_date,
			);
		}

		return $events;
	}

	/**
	 * The picker row for one event.
	 *
	 * @param array $event { id, title, date } with the raw post_date.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function to_item( array $event ): array {
		$title = SPLM_Discipline_Suspension_Context::decode( $event['title'] );
		$when  = mysql2date( get_option( 'date_format' ), $event['date'] );

		return array(
			'id'    => (int) $event['id'],
			'title' => $title,
			'date'  => substr( $event['date'], 0, 10 ),
			'label' => SPLM_Discipline_Suspension_Context::format_label( $title, $when ),
		);
	}

	/**
	 * Query args for one team's games up to and including today.
	 *
	 * 'publish' AND 'future' — a fixture dated today can still be stored as
	 * 'future'. Postponed and cancelled fixtures are excluded in the query,
	 * the same way the eligibility query does.
	 *
	 * @param int    $team_id   Team post id.
	 * @param int    $season_id Season term id; teams persist across seasons.
	 * @param string $today     Local 'Y-m-d'.
	 * @param int    $limit     Cap.
	 * @return array get_posts() args.
	 */
	private static function query_args( int $team_id, int $season_id, string $today, int $limit ): array {
		return array(
			'post_type'      => 'sp_event',
			'post_status'    => array( 'publish', 'future' ),
			'posts_per_page' => $limit,
			'orderby'        => array(
				'date' => 'DESC',
				'ID'   => 'DESC',
			),
			'date_query'     => array(
				array(
					'before'    => $today . ' 23:59:59',
					'inclusive' => true,
				),
			),
			'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => 'sp_season',
					'field'            => 'term_id',
					'terms'            => $season_id,
					'include_children' => false,
				),
			),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'relation' => 'AND',
				array(
					'key'   => 'sp_team',
					'value' => $team_id,
				),
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
		);
	}
}
