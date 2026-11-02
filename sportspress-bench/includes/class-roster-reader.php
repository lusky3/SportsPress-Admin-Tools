<?php
/**
 * SportsPress reads for Bench: user to players, player to teams, season, events
 * and rosters. Thin on purpose; every presentation decision lives in
 * SPBN_Event_Presenter.
 *
 * SportsPress facts this class encodes (see the spec, section 2):
 * - sp_current_team is a repeated meta key with '0' placeholder rows, and it is
 *   "last team ever", never season-scoped.
 * - Upcoming events are post_status 'future'.
 * - Events-manager cancels a game by drafting it and setting _spem_cancelled.
 * - An event's teams are repeated sp_team rows, first = home.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Roster_Reader {

	/** Upper bound on one season's events for a user's teams. */
	const EVENT_LIMIT = 200;

	/**
	 * Per-request cache of teams_for_user(), by user id.
	 *
	 * @var array<int,int[]>
	 */
	private static array $teams_cache = array();

	/**
	 * Per-request memo of season_id(); null until first resolved.
	 */
	private static ?int $season_memo = null;

	/**
	 * Per-request cache of table_team_ids() for the current season.
	 *
	 * @var array<int,int[]>|null
	 */
	private ?array $table_teams = null;

	/**
	 * Drop the per-request cache (after a write that changes a user's teams).
	 */
	public static function flush_cache(): void {
		self::$teams_cache = array();
		self::$season_memo = null;
	}

	/**
	 * The sp_player posts linked to a user through sp_user (any non-trash status).
	 *
	 * @return int[]
	 */
	public function player_ids_for_user( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}
		$ids = get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by one user.
					array(
						'key'   => 'sp_user',
						'value' => $user_id,
					),
				),
			)
		);
		return array_values( array_map( 'intval', (array) $ids ) );
	}

	/**
	 * A player's published current teams.
	 *
	 * @return int[]
	 */
	public function current_team_ids( int $player_id ): array {
		$ids = array();
		foreach ( (array) get_post_meta( $player_id, 'sp_current_team', false ) as $row ) {
			$id = is_scalar( $row ) ? absint( $row ) : 0;
			if ( $id > 0 && ! in_array( $id, $ids, true ) && 'publish' === get_post_status( $id ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * The current season's sp_season term id, or 0 when there is none.
	 *
	 * SportsPress's "current season" option when it names a real term; otherwise
	 * (unset, or a deleted term) the season of the earliest upcoming event, and
	 * with nothing upcoming the most recent past one. Memoised per request.
	 */
	public function season_id(): int {
		if ( null === self::$season_memo ) {
			$option            = (int) get_option( 'sportspress_season' );
			self::$season_memo = ( $option > 0 && self::season_term_exists( $option ) ) ? $option : $this->event_season_id();
		}
		return self::$season_memo;
	}

	private static function season_term_exists( int $term_id ): bool {
		$term = get_term( $term_id, 'sp_season' );
		return is_object( $term ) && ! is_wp_error( $term );
	}

	/**
	 * Season of the earliest upcoming publish/future event, else of the latest
	 * past one. An event carrying several season terms (a season and its
	 * Playoffs child) gives its top-level term.
	 */
	private function event_season_id(): int {
		$now     = gmdate( 'Y-m-d H:i:s' );
		$windows = array(
			array(
				'order' => 'ASC',
				'range' => array( 'after' => $now ),
			),
			array(
				'order' => 'DESC',
				'range' => array( 'before' => $now ),
			),
		);
		foreach ( $windows as $window ) {
			$ids = get_posts(
				array(
					'post_type'      => 'sp_event',
					'post_status'    => array( 'publish', 'future' ),
					'posts_per_page' => 5,
					'orderby'        => 'date',
					'order'          => $window['order'],
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'date_query'     => array( array_merge( array( 'column' => 'post_date_gmt' ), $window['range'] ) ),
				)
			);
			foreach ( (array) $ids as $event_id ) {
				$terms = self::terms( (int) $event_id, 'sp_season' );
				if ( ! $terms ) {
					continue;
				}
				foreach ( $terms as $term ) {
					if ( 0 === (int) $term->parent ) {
						return (int) $term->term_id;
					}
				}
				return (int) $terms[0]->term_id;
			}
		}
		return 0;
	}

	/**
	 * Event ids for any of the teams in a season, soonest first.
	 *
	 * Publish and future, plus drafts only when events-manager cancelled them
	 * (_spem_cancelled = '1'); any other draft is unfinished work.
	 *
	 * @param int[] $team_ids  Team post ids.
	 * @param int   $season_id sp_season term id.
	 * @return int[]
	 */
	public function season_event_ids( array $team_ids, int $season_id ): array {
		$team_ids = array_values( array_filter( array_map( 'intval', $team_ids ) ) );
		if ( ! $team_ids || $season_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'      => 'sp_event',
				'post_status'    => array( 'publish', 'future', 'draft' ),
				'posts_per_page' => self::EVENT_LIMIT,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- bounded by the user's teams.
					array(
						'key'     => 'sp_team',
						'value'   => $team_ids,
						'compare' => 'IN',
						'type'    => 'NUMERIC',
					),
				),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one season.
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => array( $season_id ),
						'include_children' => false,
					),
				),
			)
		);

		$out = array();
		foreach ( (array) $ids as $id ) {
			$id = (int) $id;
			if ( 'draft' === get_post_status( $id ) && ! self::is_spem_cancelled( $id ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * The user's teams: published current teams of any linked player, minus
	 * teams with no current-season event (stale sp_current_team rows).
	 *
	 * @return int[]
	 */
	public function teams_for_user( int $user_id ): array {
		if ( isset( self::$teams_cache[ $user_id ] ) ) {
			return self::$teams_cache[ $user_id ];
		}

		$candidates = array();
		foreach ( $this->player_ids_for_user( $user_id ) as $player_id ) {
			foreach ( $this->current_team_ids( $player_id ) as $team_id ) {
				if ( ! in_array( $team_id, $candidates, true ) ) {
					$candidates[] = $team_id;
				}
			}
		}

		$season = $this->season_id();
		$teams  = array();
		foreach ( $candidates as $team_id ) {
			if ( $this->season_event_ids( array( $team_id ), $season ) ) {
				$teams[] = $team_id;
			}
		}

		self::$teams_cache[ $user_id ] = $teams;
		return $teams;
	}

	/**
	 * One event in the presenter's raw shape, or null when it is not a listable
	 * event (wrong type, trashed, or an unfinished draft).
	 */
	public function raw_event( int $event_id ): ?array {
		$post = get_post( $event_id );
		if ( ! $post || 'sp_event' !== $post->post_type ) {
			return null;
		}
		$cancelled = self::is_spem_cancelled( $event_id );
		if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) && ! ( 'draft' === $post->post_status && $cancelled ) ) {
			return null;
		}

		$when = get_post_datetime( $event_id, 'date', 'local' );

		$team_ids = array();
		foreach ( (array) get_post_meta( $event_id, 'sp_team', false ) as $row ) {
			$id = is_scalar( $row ) ? absint( $row ) : 0;
			if ( $id > 0 && ! in_array( $id, $team_ids, true ) ) {
				$team_ids[] = $id;
			}
		}

		$names = array();
		$logos = array();
		foreach ( $team_ids as $team_id ) {
			$team              = get_post( $team_id );
			$names[ $team_id ] = $team ? (string) $team->post_title : '';
			$logo              = get_the_post_thumbnail_url( $team_id, 'thumbnail' );
			$logos[ $team_id ] = $logo ? (string) $logo : '';
		}

		$venue = array(
			'name'    => '',
			'address' => '',
		);
		$terms = self::terms( $event_id, 'sp_venue' );
		if ( $terms ) {
			$venue['name']    = (string) $terms[0]->name;
			$venue['address'] = (string) get_term_meta( (int) $terms[0]->term_id, 'sp_address', true );
		}

		$results = get_post_meta( $event_id, 'sp_results', true );
		$key     = function_exists( 'sp_get_main_result_option' ) ? (string) sp_get_main_result_option() : '';

		return array(
			'id'             => $event_id,
			'start'          => $when ? $when->format( 'c' ) : '',
			'status'         => (string) get_post_meta( $event_id, 'sp_status', true ),
			'spem_cancelled' => $cancelled,
			'team_ids'       => $team_ids,
			'venue'          => $venue,
			'results'        => is_array( $results ) ? $results : array(),
			'result_key'     => '' === $key ? 'goals' : $key,
			'team_names'     => $names,
			'team_logos'     => $logos,
		);
	}

	/**
	 * A team's roster for a season: its fullest current-season sp_list, falling
	 * back to the published players whose sp_current_team includes the team when
	 * no list stores any players.
	 *
	 * A team can have several lists in a season, and an auto-select list stores
	 * no player rows, so the list with the most stored player ids is used (ties:
	 * lowest post id). The captain is read from that list either way.
	 *
	 * @return array<int,array{player_id:int,name:string,number:string,positions:string[],is_captain:bool}>
	 */
	public function roster( int $team_id, int $season_id ): array {
		$lists = get_posts(
			array(
				'post_type'      => 'sp_list',
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one team.
					array(
						'key'   => 'sp_team',
						'value' => $team_id,
					),
				),
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one season.
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => array( $season_id ),
						'include_children' => false,
					),
				),
			)
		);

		$lists = array_map( 'intval', (array) $lists );
		sort( $lists );

		$best_list  = 0;
		$player_ids = array();
		foreach ( $lists as $list_id ) {
			$stored = array();
			foreach ( (array) get_post_meta( $list_id, 'sp_player', false ) as $row ) {
				$id = is_scalar( $row ) ? absint( $row ) : 0;
				if ( $id > 0 && ! in_array( $id, $stored, true ) ) {
					$stored[] = $id;
				}
			}
			// The lowest id is the starting point; only a strictly fuller list replaces it.
			if ( 0 === $best_list || count( $stored ) > count( $player_ids ) ) {
				$best_list  = $list_id;
				$player_ids = $stored;
			}
		}
		$captain = $best_list > 0 ? (int) get_post_meta( $best_list, 'spt_captain', true ) : 0;

		if ( ! $player_ids ) {
			$player_ids = array_map(
				'intval',
				(array) get_posts(
					array(
						'post_type'      => 'sp_player',
						'post_status'    => 'publish',
						'posts_per_page' => 200,
						'fields'         => 'ids',
						'no_found_rows'  => true,
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one team.
							array(
								'key'   => 'sp_current_team',
								'value' => $team_id,
							),
						),
					)
				)
			);
		}

		$out = array();
		foreach ( $player_ids as $player_id ) {
			$post = get_post( $player_id );
			if ( ! $post || 'sp_player' !== $post->post_type || 'trash' === $post->post_status ) {
				continue;
			}
			$positions = array();
			foreach ( self::terms( $player_id, 'sp_position' ) as $term ) {
				$positions[] = (string) $term->name;
			}
			$out[] = array(
				'player_id'  => $player_id,
				'name'       => (string) $post->post_title,
				'number'     => (string) get_post_meta( $player_id, 'sp_number', true ),
				'positions'  => $positions,
				'is_captain' => $captain > 0 && $captain === $player_id,
			);
		}
		return $out;
	}

	/**
	 * Team card fields. Division comes from the team's current-season,
	 * non-playoff sp_table (the only season-scoped anchor SportsPress offers).
	 *
	 * @return array{id:int,name:string,logo:string,division:string}
	 */
	public function team_summary( int $team_id ): array {
		$team = get_post( $team_id );
		$logo = get_the_post_thumbnail_url( $team_id, 'medium' );
		return array(
			'id'       => $team_id,
			'name'     => $team ? (string) $team->post_title : '',
			'logo'     => $logo ? (string) $logo : '',
			'division' => $this->division( $team_id ),
		);
	}

	/**
	 * W/L/T for a team across events, from the presenter's results. Unplayed and
	 * called-off games do not count.
	 *
	 * @param int[] $event_ids Event ids.
	 * @return array{w:int,l:int,t:int}
	 */
	public function record( int $team_id, array $event_ids ): array {
		$record = array(
			'w' => 0,
			'l' => 0,
			't' => 0,
		);
		foreach ( $event_ids as $event_id ) {
			$raw = $this->raw_event( (int) $event_id );
			if ( ! $raw ) {
				continue;
			}
			$game = SPBN_Event_Presenter::present( $raw, $team_id );
			if ( SPBN_Event_Presenter::is_called_off( $game['status'] ) || null === $game['result'] ) {
				continue;
			}
			++$record[ 'W' === $game['result']['outcome'] ? 'w' : ( 'L' === $game['result']['outcome'] ? 'l' : 't' ) ];
		}
		return $record;
	}

	/**
	 * A post's terms, or none when the lookup fails (wp_get_post_terms returns
	 * a WP_Error for an unregistered taxonomy).
	 *
	 * @return object[]
	 */
	private static function terms( int $post_id, string $taxonomy ): array {
		$terms = wp_get_post_terms( $post_id, $taxonomy );
		return is_array( $terms ) ? array_values( $terms ) : array();
	}

	private static function is_spem_cancelled( int $event_id ): bool {
		return '1' === (string) get_post_meta( $event_id, '_spem_cancelled', true );
	}

	private function division( int $team_id ): string {
		foreach ( $this->current_season_tables() as $table_id => $team_ids ) {
			if ( ! in_array( $team_id, $team_ids, true ) ) {
				continue;
			}
			$terms = self::terms( $table_id, 'sp_league' );
			return $terms ? (string) $terms[0]->name : '';
		}
		return '';
	}

	/**
	 * Current-season, non-playoff standings tables and the teams each lists.
	 *
	 * @return array<int,int[]> table id => team ids.
	 */
	private function current_season_tables(): array {
		if ( null !== $this->table_teams ) {
			return $this->table_teams;
		}
		$this->table_teams = array();

		$season = $this->season_id();
		if ( $season <= 0 ) {
			return $this->table_teams;
		}

		$table_ids = get_posts(
			array(
				'post_type'      => 'sp_table',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- one season.
					array(
						'taxonomy'         => 'sp_season',
						'field'            => 'term_id',
						'terms'            => array( $season ),
						'include_children' => false,
					),
				),
			)
		);

		foreach ( (array) $table_ids as $table_id ) {
			$table_id = (int) $table_id;
			if ( $this->is_playoff_table( $table_id ) ) {
				continue;
			}
			$teams = array();
			foreach ( (array) get_post_meta( $table_id, 'sp_team', false ) as $row ) {
				$id = is_scalar( $row ) ? absint( $row ) : 0;
				if ( $id > 0 ) {
					$teams[] = $id;
				}
			}
			$this->table_teams[ $table_id ] = $teams;
		}
		return $this->table_teams;
	}

	/**
	 * A table is a playoff table when its season or league term slug says so.
	 */
	private function is_playoff_table( int $table_id ): bool {
		foreach ( array( 'sp_season', 'sp_league' ) as $taxonomy ) {
			foreach ( self::terms( $table_id, $taxonomy ) as $term ) {
				if ( false !== strpos( (string) $term->slug, 'playoff' ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
