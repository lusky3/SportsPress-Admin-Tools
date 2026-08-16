<?php
/**
 * Aggregates a season's event box scores into per-player weekly buckets.
 *
 * Stats come from each event's sp_players meta, NOT from a player's
 * sp_statistics meta: the dashboard and score-sheet writers populate the event
 * box score, and SportsPress does not reliably recompute the per-player
 * aggregate, so sp_statistics is frequently empty even when stats exist.
 *
 * Buckets are keyed by the Monday of the event's week. Year-week integers were
 * rejected because 202552 + 1 != 202601, and a winter season sits exactly on
 * that boundary.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Player_Stats_Aggregator {

	/**
	 * Upper bound on events scanned in one pass. A season runs ~380 events;
	 * this is a runaway guard, not an expected limit.
	 */
	const MAX_EVENTS = 5000;

	/**
	 * The Monday that began the week containing $datetime.
	 *
	 * @param string $datetime Any strtotime-parsable date.
	 * @return string Y-m-d.
	 */
	public static function week_key( string $datetime ): string {
		$timestamp = strtotime( $datetime );
		if ( ! $timestamp ) {
			return '';
		}

		// 'N' is 1 (Mon) through 7 (Sun), so subtracting N-1 days always lands
		// on that week's Monday regardless of locale start-of-week settings.
		$offset = (int) gmdate( 'N', $timestamp ) - 1;

		return gmdate( 'Y-m-d', $timestamp - ( $offset * DAY_IN_SECONDS ) );
	}

	/**
	 * Sum every bucket.
	 *
	 * @param array $weeks week => array( gp, g, a, pim ).
	 * @return array
	 */
	public static function totals( array $weeks ): array {
		return self::sum( $weeks, '' );
	}

	/**
	 * Sum buckets on or after a cutoff week.
	 *
	 * @param array  $weeks  week => array( gp, g, a, pim ).
	 * @param string $cutoff Y-m-d week key.
	 * @return array
	 */
	public static function window_totals( array $weeks, string $cutoff ): array {
		return self::sum( $weeks, (string) $cutoff );
	}

	/**
	 * The first week key included by a window of $weeks_back calendar weeks.
	 *
	 * The window is inclusive of the current week, so 4 weeks back from any day
	 * in week W covers W-3..W. The result is clamped to the season's first week
	 * so a window early in a season cannot reach into the previous one.
	 *
	 * @param int    $weeks_back   Window length in weeks; coerced to at least 1.
	 * @param string $today        Reference date (Y-m-d or full datetime).
	 * @param string $season_start Week key of the season's first event.
	 * @return string Y-m-d week key.
	 */
	public static function window_cutoff( int $weeks_back, string $today, string $season_start ): string {
		$weeks_back = max( 1, (int) $weeks_back );
		$this_week  = self::week_key( $today );
		if ( '' === $this_week ) {
			return (string) $season_start;
		}

		$cutoff = gmdate(
			'Y-m-d',
			strtotime( $this_week ) - ( ( $weeks_back - 1 ) * WEEK_IN_SECONDS )
		);

		$season_start = (string) $season_start;
		if ( '' !== $season_start && $cutoff < $season_start ) {
			return $season_start;
		}

		return $cutoff;
	}

	/**
	 * The earliest week key present across every player's buckets.
	 *
	 * @param array $players Output of for_season().
	 * @return string Y-m-d, or '' when there is nothing.
	 */
	public static function season_start( array $players ): string {
		$earliest = '';
		foreach ( $players as $player ) {
			foreach ( array_keys( $player['weeks'] ) as $week ) {
				if ( '' === $earliest || $week < $earliest ) {
					$earliest = $week;
				}
			}
		}

		return $earliest;
	}

	/**
	 * Scan a season's events into per-player weekly buckets.
	 *
	 * @param int   $season_id sp_season term id.
	 * @param array $args      include_playoffs (bool, default false).
	 * @return array player_id => array( name, team, team_id, div_id, div_name, weeks, totals ).
	 */
	public static function for_season( int $season_id, array $args = array() ): array {
		$season_id        = (int) $season_id;
		$include_playoffs = ! empty( $args['include_playoffs'] );

		$event_ids = self::event_ids( $season_id, $include_playoffs );
		if ( ! $event_ids ) {
			return array();
		}
		update_meta_cache( 'post', $event_ids );
		// update_meta_cache() primes meta only. get_posts( fields => ids ) returns
		// before WP_Query primes the posts table, so without this every get_post()
		// below is its own query. The two false flags skip term and meta priming:
		// terms are not needed here and meta is primed on the line above.
		_prime_post_caches( $event_ids, false, false );

		$maps      = self::division_maps( $season_id );
		$buckets   = array();
		$team_tally = array();

		foreach ( $event_ids as $event_id ) {
			$box = get_post_meta( $event_id, 'sp_players', true );
			if ( ! is_array( $box ) ) {
				continue;
			}

			$post = get_post( $event_id );
			$week = self::week_key( $post ? $post->post_date : '' );
			if ( '' === $week ) {
				continue;
			}

			foreach ( $box as $team_id => $rows ) {
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $player_id => $stats ) {
					$player_id = (int) $player_id;
					// Key 0 is SportsPress's reserved row, not a player.
					if ( ! $player_id || ! is_array( $stats ) ) {
						continue;
					}

					if ( ! isset( $buckets[ $player_id ][ $week ] ) ) {
						$buckets[ $player_id ][ $week ] = array(
							'gp'  => 0,
							'g'   => 0,
							'a'   => 0,
							'pim' => 0,
						);
					}

					$buckets[ $player_id ][ $week ]['gp']  += 1;
					$buckets[ $player_id ][ $week ]['g']   += (int) ( $stats['g'] ?? 0 );
					$buckets[ $player_id ][ $week ]['a']   += (int) ( $stats['a'] ?? 0 );
					$buckets[ $player_id ][ $week ]['pim'] += (int) ( $stats['pim'] ?? 0 );

					$team_tally[ $player_id ][ (int) $team_id ] = ( $team_tally[ $player_id ][ (int) $team_id ] ?? 0 ) + 1;
				}
			}
		}

		// Resolve every attributed team up front so the player and team posts can
		// be primed in one query. splm_display_title() calls get_the_title(),
		// which queries per post when the post is not in cache, and neither of
		// these id sets has been through a WP_Query — update_meta_cache() would
		// not help, since it primes meta and not the posts table.
		$teams = array();
		foreach ( array_keys( $buckets ) as $player_id ) {
			$teams[ (int) $player_id ] = self::attributed_team( (int) $player_id, $maps, $team_tally );
		}

		$prime = array_filter( array_unique( array_merge( array_keys( $teams ), array_values( $teams ) ) ) );
		if ( $prime ) {
			_prime_post_caches( $prime, false, false );
		}

		$out = array();
		foreach ( $buckets as $player_id => $weeks ) {
			ksort( $weeks );
			$team_id = (int) ( $teams[ (int) $player_id ] ?? 0 );
			$div_id  = (int) ( $maps['team_to_div'][ $team_id ] ?? 0 );

			$out[ $player_id ] = array(
				'name'     => splm_display_title( $player_id ),
				'team_id'  => $team_id,
				'team'     => $team_id ? splm_display_title( $team_id ) : '',
				'div_id'   => $div_id,
				'div_name' => (string) ( $maps['div_names'][ $div_id ] ?? '' ),
				'weeks'    => $weeks,
				'totals'   => self::totals( $weeks ),
			);
		}

		return $out;
	}

	/**
	 * Event ids for a season.
	 *
	 * The sp_season taxonomy is hierarchical and tax_query defaults to
	 * include_children, which silently sweeps playoff games into a "regular
	 * season" query. The flag is therefore set explicitly in both directions
	 * rather than left to the default.
	 *
	 * @param int  $season_id        Term id.
	 * @param bool $include_playoffs Whether to include child (playoff) terms.
	 * @return array
	 */
	private static function event_ids( $season_id, $include_playoffs ) {
		return get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => self::MAX_EVENTS,
				'post_status'    => array( 'publish', 'future' ),
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => (bool) $include_playoffs,
					),
				),
			)
		);
	}

	/**
	 * team_to_div, div_names, and the season's roster mapping.
	 *
	 * Mirrors the derivation already used by the season summary and division
	 * balance screens: regular-season league tables for the season, collapsed by
	 * their sp_league term, with playoff tables skipped.
	 *
	 * @param int $season_id Term id.
	 * @return array
	 */
	private static function division_maps( $season_id ) {
		$team_to_div = array();
		$div_names   = array();
		$roster      = array();

		$table_ids = get_posts(
			array(
				'post_type'      => 'sp_table',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => false,
					),
				),
			)
		);

		foreach ( $table_ids as $table_id ) {
			$league = wp_get_object_terms( $table_id, 'sp_league' );
			if ( is_wp_error( $league ) || empty( $league ) ) {
				continue;
			}
			$league                          = $league[0];
			$div_names[ $league->term_id ]   = $league->name;

			if ( ! class_exists( 'SP_League_Table' ) ) {
				continue;
			}
			$table = new SP_League_Table( $table_id );
			foreach ( (array) $table->data() as $team_id => $unused ) {
				if ( is_numeric( $team_id ) && (int) $team_id ) {
					$team_to_div[ (int) $team_id ] = (int) $league->term_id;
				}
			}
		}

		// Roster mapping: sp_leagues[league][season] => team. This is the
		// season-scoped source; sp_current_team is not season-scoped and would
		// mis-attribute anyone who has since moved.
		$player_ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'posts_per_page' => self::MAX_EVENTS,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'sp_season',
						'terms'            => $season_id,
						'include_children' => true,
					),
				),
			)
		);
		if ( $player_ids ) {
			update_meta_cache( 'post', $player_ids );
		}
		foreach ( $player_ids as $player_id ) {
			$leagues = get_post_meta( $player_id, 'sp_leagues', true );
			if ( ! is_array( $leagues ) ) {
				continue;
			}
			foreach ( $leagues as $season_map ) {
				if ( is_array( $season_map ) && ! empty( $season_map[ $season_id ] ) ) {
					$roster[ (int) $player_id ] = (int) $season_map[ $season_id ];
					break;
				}
			}
		}

		return array(
			'team_to_div' => $team_to_div,
			'div_names'   => $div_names,
			'roster'      => $roster,
		);
	}

	/**
	 * The team a player counts for.
	 *
	 * Prefers the season roster mapping; falls back to the team they most often
	 * appeared for, so a player with no roster row still lands on a board
	 * instead of vanishing.
	 *
	 * @param int   $player_id  Player post id.
	 * @param array $maps       Output of division_maps().
	 * @param array $team_tally player_id => team_id => appearances.
	 * @return int
	 */
	private static function attributed_team( $player_id, array $maps, array $team_tally ) {
		if ( ! empty( $maps['roster'][ $player_id ] ) ) {
			return (int) $maps['roster'][ $player_id ];
		}

		$tally = $team_tally[ $player_id ] ?? array();
		if ( ! $tally ) {
			return 0;
		}
		arsort( $tally );

		return (int) array_key_first( $tally );
	}

	/**
	 * Sum buckets, optionally only those on or after a cutoff.
	 *
	 * @param array  $weeks  week => stats.
	 * @param string $cutoff Y-m-d, or '' for everything.
	 * @return array
	 */
	private static function sum( array $weeks, $cutoff ) {
		$out = array(
			'gp'  => 0,
			'g'   => 0,
			'a'   => 0,
			'pim' => 0,
		);

		foreach ( $weeks as $week => $stats ) {
			if ( '' !== $cutoff && (string) $week < $cutoff ) {
				continue;
			}
			foreach ( $out as $key => $unused ) {
				$out[ $key ] += (int) ( $stats[ $key ] ?? 0 );
			}
		}

		return $out;
	}
}
