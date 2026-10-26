<?php
/**
 * FreeScout "player" section provider.
 *
 * Supplies sections.player of the FreeScout bridge's customer lookup: for each
 * player id, identity details, season assignments and the next game.
 *
 * @package SportsPress_Player_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_FreeScout_Provider {

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
		$providers['player'] = array( __CLASS__, 'player_section' );
		return $providers;
	}

	/**
	 * Build sections.player.
	 *
	 * @param int[]  $player_ids Resolved player ids.
	 * @param string $email      Customer email; returned lower-cased so the
	 *                           Player card can offer Change email.
	 * @return array
	 */
	public static function player_section( array $player_ids, string $email ): array {
		$current_season_id = (int) get_option( 'sportspress_season' );
		$current_name      = '';
		if ( $current_season_id > 0 ) {
			$current_name = self::term_name( $current_season_id, 'sp_season' );
		}

		$players = array();
		foreach ( $player_ids as $player_id ) {
			$player = self::build_player( (int) $player_id, $current_season_id );
			if ( null !== $player ) {
				$players[] = $player;
			}
		}

		return array(
			'email'          => strtolower( $email ),
			'players'        => $players,
			'current_season' => $current_name,
		);
	}

	/**
	 * @param int $player_id         Player post id.
	 * @param int $current_season_id Current season term id (0 if none).
	 * @return array|null Null when the post is gone or isn't a live player.
	 */
	private static function build_player( int $player_id, int $current_season_id ) {
		$post = get_post( $player_id );
		// The email resolver is deliberately unfiltered (privacy export/erasure
		// must see everything), so it can hand over trashed duplicates and
		// non-player posts carrying sp_user (staff). Only live players count here.
		if ( ! $post || 'sp_player' !== $post->post_type || in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}

		$number = get_post_meta( $player_id, 'sp_number', true );
		$skill  = get_post_meta( $player_id, 'spt_skill_level', true );

		$positions = array();
		$pos_terms = wp_get_post_terms( $player_id, 'sp_position' );
		$pos_terms = is_array( $pos_terms ) ? $pos_terms : array();
		foreach ( $pos_terms as $pos_term ) {
			$positions[] = (string) $pos_term->name;
		}

		$assignments  = array();
		$current_team = 0;
		foreach ( self::roster_entries( $player_id ) as $entry ) {
			$is_current = $current_season_id > 0 && $entry['season_id'] === $current_season_id;
			if ( $is_current && 0 === $current_team ) {
				$current_team = $entry['team_id'];
			}
			$assignments[] = array(
				'season_id' => $entry['season_id'],
				'season'    => self::term_name( $entry['season_id'], 'sp_season' ),
				'division'  => self::term_name( $entry['league_id'], 'sp_league' ),
				'team_id'   => $entry['team_id'],
				'team'      => (string) get_the_title( $entry['team_id'] ),
				'current'   => $is_current,
				'captain'   => self::is_captain( $player_id, $entry['team_id'], $entry['season_id'], $is_current ),
			);
		}

		return array(
			'id'             => $player_id,
			'name'           => (string) $post->post_title,
			'number'         => ( '' === $number || false === $number ) ? null : (string) $number,
			'positions'      => $positions,
			'skill_level'    => ( '' === $skill || false === $skill ) ? null : (string) $skill,
			'wp_user_linked' => (int) get_post_meta( $player_id, 'sp_user', true ) > 0,
			'assignments'    => $assignments,
			'next_game'      => $current_team > 0 ? self::next_game( $current_team ) : null,
		);
	}

	/**
	 * Flatten sp_leagues ([league => [season => team]]) into roster entries.
	 *
	 * League key 0 is SportsPress's "no league" marker and a team id <= 0 means
	 * "not on a team"; both are skipped.
	 *
	 * @param int $player_id Player post id.
	 * @return array<int, array{league_id:int, season_id:int, team_id:int}>
	 */
	private static function roster_entries( int $player_id ): array {
		$leagues = get_post_meta( $player_id, 'sp_leagues', true );
		if ( ! is_array( $leagues ) ) {
			return array();
		}
		$out = array();
		foreach ( $leagues as $league_id => $season_map ) {
			if ( (int) $league_id <= 0 || ! is_array( $season_map ) ) {
				continue;
			}
			foreach ( $season_map as $season_id => $team_id ) {
				if ( (int) $season_id <= 0 || (int) $team_id <= 0 ) {
					continue;
				}
				$out[] = array(
					'league_id' => (int) $league_id,
					'season_id' => (int) $season_id,
					'team_id'   => (int) $team_id,
				);
			}
		}
		return $out;
	}

	private static function term_name( int $term_id, string $taxonomy ): string {
		$term = get_term( $term_id, $taxonomy );
		return ( $term && ! is_wp_error( $term ) ) ? (string) $term->name : '';
	}

	/**
	 * Captain is stored as spt_captain on a team's sp_list post.
	 *
	 * Current season: the team's active list (its `sp_list` meta), exactly as
	 * the roster REST API and admin UI read it. Past seasons: published lists
	 * for that team and season term.
	 */
	private static function is_captain( int $player_id, int $team_id, int $season_id, bool $is_current ): bool {
		if ( $is_current ) {
			$active_list_id = (int) get_post_meta( $team_id, 'sp_list', true );
			return $active_list_id > 0 && (int) get_post_meta( $active_list_id, 'spt_captain', true ) === $player_id;
		}

		$list_ids = get_posts(
			array(
				'post_type'      => 'sp_list',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'meta_query'     => array(
					array(
						'key'   => 'sp_team',
						'value' => $team_id,
					),
				),
				'tax_query'      => array(
					array(
						'taxonomy' => 'sp_season',
						'terms'    => $season_id,
					),
				),
			)
		);
		foreach ( (array) $list_ids as $list_id ) {
			if ( (int) get_post_meta( (int) $list_id, 'spt_captain', true ) === $player_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Earliest upcoming event for the team. Unplayed games are post_status
	 * 'future', so both 'publish' and 'future' are queried.
	 *
	 * @return array|null
	 */
	private static function next_game( int $team_id ) {
		$events = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => 1,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'after'     => gmdate( 'Y-m-d H:i:s' ),
						'inclusive' => true,
						'column'    => 'post_date_gmt',
					),
				),
				'meta_query'     => array(
					array(
						'key'   => 'sp_team',
						'value' => $team_id,
					),
				),
			)
		);
		if ( empty( $events ) ) {
			return null;
		}
		$event = $events[0];

		$opponent = '';
		foreach ( (array) get_post_meta( $event->ID, 'sp_team', false ) as $other_id ) {
			if ( (int) $other_id > 0 && (int) $other_id !== $team_id ) {
				$opponent = (string) get_the_title( (int) $other_id );
				break;
			}
		}

		$venue       = '';
		$venue_terms = wp_get_post_terms( $event->ID, 'sp_venue' );
		if ( is_array( $venue_terms ) && ! empty( $venue_terms ) ) {
			$venue = (string) $venue_terms[0]->name;
		}

		return array(
			'event_id' => (int) $event->ID,
			'start'    => wp_date( 'c', strtotime( $event->post_date_gmt . ' UTC' ) ),
			'opponent' => $opponent,
			'venue'    => $venue,
		);
	}
}
