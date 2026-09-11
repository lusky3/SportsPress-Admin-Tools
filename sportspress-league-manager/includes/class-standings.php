<?php
/**
 * Standings and tiebreak engine, extending core SportsPress's SP_League_Table
 * rather than replacing it (design notes kept locally, not in this repo).
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One cohesive engine, deliberately: every method here is small (see the
 * per-method complexity, all well under this repo's own -C 8 threshold) and
 * independently testable, and the class exists to be the SINGLE integration
 * point with SP_League_Table -- splitting it would scatter that integration
 * across multiple classes for no real gain.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SPLM_Standings {

	const PIM_COLUMN_SLUG = 'pim';

	/**
	 * Option flag recording that ensure_pim_column() already succeeded on this
	 * site, so the check-and-insert does not repeat on every request.
	 */
	const PIM_COLUMN_ENSURED_OPTION = 'splm_standings_pim_column_ensured';

	/**
	 * Sum one team's players' penalty minutes across every sp_event tagged
	 * with any of $season_ids, optionally restricted to a post_date range.
	 *
	 * The sp_players meta shape: array( $team_id => array( $player_id => array(
	 * 'pim' => int, ... ) ) ) -- SportsPress's own box-score storage, read
	 * the same way SPLM_Player_Stats_Aggregator::for_season() does.
	 *
	 * @param int|string  $team_id    Team id.
	 * @param array       $season_ids sp_season term ids to include.
	 * @param string|null $from      Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to        Inclusive upper post_date bound, 'Y-m-d'.
	 * @return int
	 */
	public static function team_pim_total( $team_id, array $season_ids, $from = null, $to = null ) {
		$args = array(
			'post_type'   => 'sp_event',
			'numberposts' => -1,
			'post_status' => 'publish',
			'tax_query'   => array(
				array(
					'taxonomy' => 'sp_season',
					'field'    => 'term_id',
					'terms'    => $season_ids,
				),
			),
		);

		$date_query = self::build_date_query( $from, $to );
		if ( null !== $date_query ) {
			$args['date_query'] = array( $date_query );
		}

		$events = get_posts( $args );
		$total  = 0;

		foreach ( $events as $event ) {
			$total += self::sum_event_team_pim( $event->ID, $team_id );
		}

		return $total;
	}

	/**
	 * Build the date_query clause for team_pim_total(), or null when
	 * neither bound is given.
	 *
	 * @param string|null $from Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to   Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array|null
	 */
	private static function build_date_query( $from, $to ) {
		if ( ! $from && ! $to ) {
			return null;
		}

		$date_query = array( 'inclusive' => true );
		if ( $from ) {
			$date_query['after'] = $from;
		}
		if ( $to ) {
			$date_query['before'] = array(
				'year'   => (int) substr( $to, 0, 4 ),
				'month'  => (int) substr( $to, 5, 2 ),
				'day'    => (int) substr( $to, 8, 2 ),
				'hour'   => 23,
				'minute' => 59,
				'second' => 59,
			);
		}

		return $date_query;
	}

	/**
	 * Sum one team's players' pim for a single event.
	 *
	 * @param int        $event_id Event post id.
	 * @param int|string $team_id  Team id.
	 * @return int
	 */
	private static function sum_event_team_pim( $event_id, $team_id ) {
		$box = get_post_meta( $event_id, 'sp_players', true );
		if ( ! is_array( $box ) || ! isset( $box[ (int) $team_id ] ) || ! is_array( $box[ (int) $team_id ] ) ) {
			return 0;
		}

		$total = 0;
		foreach ( $box[ (int) $team_id ] as $player_stats ) {
			if ( is_array( $player_stats ) ) {
				$total += (int) ( $player_stats['pim'] ?? 0 );
			}
		}

		return $total;
	}

	/**
	 * Run ensure_pim_column() at most once per site, and never before
	 * SportsPress itself is loaded.
	 *
	 * This is what the plugin bootstrap calls. The raw ensure_pim_column()
	 * would otherwise run its get_page_by_path() + wp_insert_post() pair on
	 * every request -- including unauthenticated front-end ones, and ones
	 * where sp_column is not a registered post type yet (SportsPress
	 * deactivated, or simply not loaded), which would create an orphaned post.
	 * Skipping entirely once the option is set also closes the practical half
	 * of the check-and-insert race (only concurrent *first* requests can still
	 * collide, which is acceptable -- a duplicate is recoverable and a real
	 * lock is not worth the machinery here).
	 *
	 * @return int The column's post id, or 0 when the call was skipped or failed.
	 */
	public static function maybe_ensure_pim_column() {
		if ( get_option( self::PIM_COLUMN_ENSURED_OPTION ) ) {
			return 0;
		}

		if ( ! post_type_exists( 'sp_column' ) ) {
			return 0;
		}

		$id = self::ensure_pim_column();
		if ( ! $id ) {
			return 0;
		}

		update_option( self::PIM_COLUMN_ENSURED_OPTION, 1 );

		return $id;
	}

	/**
	 * Create the "PIM" sp_column once, idempotently. Call maybe_ensure_pim_column()
	 * rather than this from request-time code -- this one does its
	 * get_page_by_path() + wp_insert_post() work unconditionally.
	 *
	 * @return int The column's post id (existing or newly created), or 0 if the
	 *             insert failed.
	 */
	public static function ensure_pim_column() {
		$existing = get_page_by_path( self::PIM_COLUMN_SLUG, OBJECT, 'sp_column' );
		if ( $existing ) {
			return $existing->ID;
		}

		$id = wp_insert_post(
			array(
				'post_type'   => 'sp_column',
				'post_title'  => 'PIM',
				'post_name'   => self::PIM_COLUMN_SLUG,
				'post_status' => 'publish',
			)
		);

		if ( is_wp_error( $id ) || ! $id ) {
			// Never fall through to update_post_meta( 0, ... ), which would
			// silently scribble the column's meta onto post id 0.
			return 0;
		}

		update_post_meta( $id, 'sp_equation', '$' . self::PIM_COLUMN_SLUG );
		update_post_meta( $id, 'sp_precision', 0 );
		update_post_meta( $id, 'sp_order', 'ASC' ); // fewer penalty minutes is better
		update_post_meta( $id, 'sp_priority', self::next_priority() );

		return $id;
	}

	/**
	 * The next unused sp_priority value, so the PIM column sorts after every
	 * existing priority column instead of colliding with one.
	 *
	 * @return int
	 */
	private static function next_priority() {
		$columns = get_posts(
			array(
				'post_type'   => 'sp_column',
				'numberposts' => -1,
				'post_status' => 'publish',
			)
		);

		$max = 0;
		foreach ( $columns as $column ) {
			$priority = (int) get_post_meta( $column->ID, 'sp_priority', true );
			$max      = max( $max, $priority );
		}

		return $max + 1;
	}

	/**
	 * Hook for core's `sportspress_equation_solve_for_presets` filter
	 * (sp_solve(), sportspress-pro/includes/sportspress/includes/sp-core-functions.php).
	 * Answers a plain "$pim" equation directly; every other equation is left
	 * for core's own generic engine by returning $solution unchanged.
	 *
	 * $post_id is the TEAM id in this filter's calling context, not a post
	 * type of any kind -- confirmed against SP_League_Table's call site.
	 *
	 * @param mixed  $solution Value from an earlier filter, or null.
	 * @param string $equation The column's raw sp_equation string.
	 * @param int    $post_id  Team id being scored.
	 * @return mixed
	 */
	public static function filter_pim_equation( $solution, $equation, $post_id ) {
		if ( '$' . self::PIM_COLUMN_SLUG !== trim( (string) $equation ) ) {
			return $solution;
		}

		return self::team_pim_total( $post_id, self::current_table_season_ids() );
	}

	/**
	 * The sp_season term ids the table currently being computed is scoped to.
	 *
	 * Core hands filter_pim_equation() only the TEAM id -- nothing identifying
	 * the sp_table being computed -- so the scope is resolved from three
	 * sources, most trustworthy first:
	 *
	 * 1. An explicit override, set by rank_by_points_h2h() around its own
	 *    SP_League_Table call. That path knows its own scope exactly.
	 * 2. The scope observed on the way into the CURRENT SP_League_Table::data()
	 *    call, captured from core's own `sportspress_table_data_event_args`
	 *    filter (see capture_table_season_scope()). Core fires that filter
	 *    inside data(), before the sp_solve() loop that reaches us, on every
	 *    data() call -- including plain front-end renders that have nothing to
	 *    do with rank().
	 * 3. WordPress's global post context, for the single-sp_table template and
	 *    the admin table editor, where the current post IS the table.
	 *
	 * Before this, only (1) existed, so a directly-rendered league table
	 * resolved every PIM cell against an EMPTY season list (zero events, so
	 * "0" for every team), or -- worse -- against whatever season a rank()
	 * call earlier in the same request happened to leave behind.
	 *
	 * @return array
	 */
	private static function current_table_season_ids() {
		if ( null !== self::$current_season_ids ) {
			return self::$current_season_ids;
		}

		if ( null !== self::$rendering_season_ids ) {
			return self::$rendering_season_ids;
		}

		if ( function_exists( 'sp_get_the_term_ids' ) && function_exists( 'get_the_ID' ) ) {
			return (array) sp_get_the_term_ids( get_the_ID(), 'sp_season' );
		}

		return array();
	}

	/** @var array|null Explicit override, set by rank_by_points_h2h() around its own SP_League_Table call. */
	private static $current_season_ids = null;

	/** @var array|null Scope of the SP_League_Table::data() call currently in progress, from capture_table_season_scope(). */
	private static $rendering_season_ids = null;

	/**
	 * Sets the season scope filter_pim_equation() reads, overriding whatever
	 * would otherwise be derived from the table being rendered. Called by
	 * rank_by_points_h2h() around each SP_League_Table query, and directly by
	 * tests. Always pair with clear_current_table_season_ids().
	 *
	 * @param array $season_ids sp_season term ids to scope to.
	 * @return void
	 */
	public static function set_current_table_season_ids( array $season_ids ) {
		self::$current_season_ids = $season_ids;
	}

	/**
	 * Drops the explicit season-scope override, so later unrelated tables in
	 * the same request fall back to their own scope instead of inheriting one.
	 *
	 * @return void
	 */
	public static function clear_current_table_season_ids() {
		self::$current_season_ids = null;
	}

	/**
	 * Hook for core's `sportspress_table_data_event_args` filter, fired from
	 * inside SP_League_Table::data() before the sp_solve() loop that calls
	 * filter_pim_equation(). Records the sp_season terms core put in the
	 * table's own event query, which is exactly the season scope that table's
	 * columns are being computed over.
	 *
	 * The args are passed through untouched; this is a read-only observer.
	 *
	 * @param array $args WP_Query args core assembled for the table's events.
	 * @return array $args, unchanged.
	 */
	public static function capture_table_season_scope( $args ) {
		$season_ids = array();

		foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && 'sp_season' === ( $clause['taxonomy'] ?? '' ) ) {
				$season_ids = array_merge( $season_ids, (array) ( $clause['terms'] ?? array() ) );
			}
		}

		// Assigned even when empty: a table with no season terms must read as
		// "no season scope", not as the previous table's scope.
		self::$rendering_season_ids = $season_ids;

		return $args;
	}

	/**
	 * Wire this class's core integration filters. Call once, e.g. from the
	 * plugin's init() after the autoloader registers.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		add_filter( 'sportspress_equation_solve_for_presets', array( __CLASS__, 'filter_pim_equation' ), 10, 3 );
		add_filter( 'sportspress_table_data_event_args', array( __CLASS__, 'capture_table_season_scope' ) );
	}

	/**
	 * The one hidden sp_table post this class reuses for every ad-hoc
	 * ranking query. Created once; never shown on any front-end table list
	 * (no menu_order/visibility meta is set because nothing renders it
	 * directly -- only SP_League_Table::data() reads it).
	 *
	 * @return int The scratch table's post id, or 0 if it could not be created.
	 */
	public static function scratch_table_id() {
		static $id = null;
		if ( null !== $id ) {
			return $id;
		}

		$existing = get_page_by_path( 'splm-standings-scratch', OBJECT, 'sp_table' );
		if ( $existing ) {
			$id = $existing->ID;
			return $id;
		}

		$inserted = wp_insert_post(
			array(
				'post_type'   => 'sp_table',
				'post_title'  => 'SPLM Standings (internal, do not display)',
				'post_name'   => 'splm-standings-scratch',
				'post_status' => 'private',
			)
		);

		if ( is_wp_error( $inserted ) || ! $inserted ) {
			// Deliberately NOT memoized: a transient insert failure must not
			// poison every later call in this request with a bogus id.
			return 0;
		}

		$id = $inserted;

		return $id;
	}

	/**
	 * Reorder $team_ids best-to-worst using core SP_League_Table's own
	 * points/priority-column sort (the PIM column from Task 2 participates
	 * automatically once it exists), scoped to one season and an optional
	 * date range, with head-to-head tiebreaking applied to whatever that
	 * sort leaves tied.
	 *
	 * Core's OWN head-to-head re-sort (the `$is_main_loop && 'h2h' ==
	 * get_option('sportspress_table_tiebreaker')` block in
	 * SP_League_Table::data()) can never take that branch through this call
	 * pattern: `$is_main_loop` is forced false the instant $team_ids is
	 * passed to data(), which every call this class makes always does (to
	 * scope to one division). apply_head_to_head() below runs the identical
	 * recursion core's own block would run -- `$this->data(false, $teams)`
	 * restricted to just the tied group -- ourselves, so head-to-head
	 * tiebreaking actually happens regardless of that option or the caller.
	 *
	 * @param array       $team_ids Team ids to rank.
	 * @param int|string  $season_id sp_season term id.
	 * @param string|null $from    Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to      Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array{order: array, ties: array} 'order': $team_ids reordered,
	 *         with any group core's sort left tied already resolved by
	 *         head-to-head wherever that was possible. 'ties': the residual
	 *         groups head-to-head ALSO left tied -- smaller than (or equal
	 *         to) core's own tiebreakers, since a group head-to-head fully
	 *         resolves is no longer reported here.
	 */
	public static function rank_by_points_h2h( array $team_ids, $season_id, $from = null, $to = null ) {
		$table_id = self::scratch_table_id();
		if ( ! $table_id ) {
			// No scratch table, no ranking: hand back the input untouched
			// rather than querying against post id 0.
			return array(
				'order' => array_values( $team_ids ),
				'ties'  => array(),
			);
		}

		wp_set_object_terms( $table_id, array( $season_id ), 'sp_season' );
		self::write_date_range_meta( $table_id, $from, $to );

		self::set_current_table_season_ids( array( $season_id ) );

		try {
			$table = new SP_League_Table( $table_id );
			$data  = $table->data( false, $team_ids );
			$order = array_keys( $data );

			$ties  = self::tied_groups_from_tiebreakers( $table );
			$order = self::apply_head_to_head( $table, $order, $ties );
		} finally {
			// Released the moment core is done with it, so a later unrelated
			// table render in this same request cannot inherit this scope --
			// including when data() throws.
			self::clear_current_table_season_ids();
		}

		return array(
			'order' => $order,
			'ties'  => $ties,
		);
	}

	/**
	 * Run core's own head-to-head recursion for every group $order left
	 * tied, since SP_League_Table::data() can never take that branch itself
	 * through this class's call pattern (see rank_by_points_h2h()'s
	 * docblock). For each group, this is exactly core's own h2h line --
	 * `$this->data( false, $teams )` -- restricted to just that group's own
	 * games (core's `! $is_main_loop` event filter already drops any event
	 * involving a team outside the passed-in list, which is precisely what
	 * head-to-head needs). Whatever THAT recursive call itself leaves tied
	 * (read from the table's own, freshly-recomputed $tiebreakers) becomes
	 * the new, smaller residual tie groups PIM/coin-flip still need to
	 * resolve.
	 *
	 * @param SP_League_Table $table Configured, already-queried table instance.
	 * @param array           $order Best-to-worst order from the initial sort.
	 * @param array           $ties  Reference: original tie groups (2+ teams
	 *                               each); replaced with the residual groups
	 *                               head-to-head itself left tied.
	 * @return array $order, with each tied group re-sequenced by head-to-head.
	 */
	private static function apply_head_to_head( $table, array $order, array &$ties ) {
		if ( empty( $ties ) ) {
			return $order;
		}

		$residual_ties = array();

		foreach ( $ties as $group ) {
			$positions = self::tied_group_positions( $group, $order );
			if ( null === $positions ) {
				// Same invariant-violation guard as rank()'s own tied_group_positions()
				// use: skip rather than let a false position corrupt $order.
				$residual_ties[] = $group;
				continue;
			}

			// SP_League_Table::data() resets $this->pos/$this->counter on
			// every call but NEVER $this->tiebreakers -- calculate_pos()
			// only ever appends to it. Left alone, this recursive call's own
			// tiebreakers would merge with (and be unreadable from) whatever
			// the outer call, or an earlier iteration of this loop, already
			// left behind. Clearing it first is what makes the read below
			// reflect ONLY this recursive call.
			$table->tiebreakers = array();

			$h2h_order = array_keys( $table->data( false, $group ) );
			foreach ( $positions as $i => $position ) {
				$order[ $position ] = $h2h_order[ $i ];
			}

			array_push( $residual_ties, ...self::tied_groups_from_tiebreakers( $table ) );
		}

		$ties = $residual_ties;

		return $order;
	}

	/**
	 * Groups of 2+ team ids a table's own $tiebreakers property currently
	 * reports as tied, as a plain array( array( id, id, ... ), ... ) --
	 * shared by rank_by_points_h2h()'s initial read and each head-to-head
	 * recursion's own residual read in apply_head_to_head() above.
	 *
	 * @param SP_League_Table $table
	 * @return array
	 */
	private static function tied_groups_from_tiebreakers( $table ) {
		$groups = array();
		foreach ( (array) ( $table->tiebreakers ?? array() ) as $group ) {
			if ( count( $group ) > 1 ) {
				$groups[] = array_values( $group );
			}
		}
		return $groups;
	}

	/**
	 * Write a date scope onto a table post as the POST META core actually
	 * reads, before SP_League_Table is constructed over it.
	 *
	 * SP_League_Table::data() re-reads sp_date (through SP_Custom_Post::__get(),
	 * i.e. get_post_meta( $this->ID, 'sp_date', true )) on its first lines, and
	 * sp_date_relative / sp_date_from / sp_date_to inside its 'range' branch.
	 * Anything assigned to the object's own $date/$from/$to properties is
	 * therefore overwritten and lost -- which is how a date range passed to
	 * rank_by_points_h2h() used to be silently discarded, quietly ranking the
	 * whole season instead.
	 *
	 * Every key is written on every call, because the scratch table is reused:
	 * an un-reset sp_date would leak the previous call's range into this one.
	 *
	 * @param int         $table_id sp_table post id.
	 * @param string|null $from     Inclusive lower bound, 'Y-m-d', or null.
	 * @param string|null $to       Inclusive upper bound, 'Y-m-d', or null.
	 * @return void
	 */
	private static function write_date_range_meta( $table_id, $from, $to ) {
		update_post_meta( $table_id, 'sp_date', ( $from || $to ) ? 'range' : 0 );
		// Must be falsy, or core takes its relative() path instead of range().
		update_post_meta( $table_id, 'sp_date_relative', '' );
		update_post_meta( $table_id, 'sp_date_from', $from );
		update_post_meta( $table_id, 'sp_date_to', $to );
	}

	/**
	 * Decide (once) which of two tied teams wins a final tiebreak, and
	 * persist the decision so it never changes on a later recompute.
	 *
	 * @param int|string $team_a_id   First candidate team id.
	 * @param int|string $team_b_id   Second candidate team id.
	 * @param string     $context_key Stable identifier for THIS specific tie
	 *                                (e.g. "div1-2027-final-rank-3") -- must
	 *                                be unique per tie being broken, shared
	 *                                across repeated calls for the same one.
	 * @return int|string The winning team id.
	 */
	public static function coin_flip( $team_a_id, $team_b_id, $context_key ) {
		$option_name = 'splm_standings_coin_flip_' . $context_key;
		$stored      = get_option( $option_name, null );

		if ( null !== $stored ) {
			// WordPress round-trips option values through the DB as strings, so
			// $stored may be "40" even though $team_a_id/$team_b_id are ints.
			// Compare loosely (as strings) and always return one of THIS call's
			// own arguments -- never the raw stored scalar -- so the identity
			// (and type) of the winner stays whatever the caller expects, and a
			// stored value that's no longer one of the two current ids self-heals
			// by falling back to $team_b_id instead of returning garbage.
			return ( (string) $team_a_id === (string) $stored ) ? $team_a_id : $team_b_id;
		}

		$winner = ( wp_rand( 0, 1 ) === 0 ) ? $team_a_id : $team_b_id;
		update_option( $option_name, $winner, false );

		return $winner;
	}

	/**
	 * The one entry point later phases (seed resolution) call: a full,
	 * best-to-worst ranking of $team_ids for one season/date-range, with
	 * ties broken by combined PIM and then a persisted coin flip.
	 *
	 * @param array       $team_ids                Team ids to rank.
	 * @param int|string  $season_id                sp_season term id the PRIMARY ranking (points/H2H) is scoped to.
	 * @param array       $combined_pim_season_ids  sp_season term ids to SUM PIM across for tiebreaking (e.g. both
	 *                                               the regular and playoff seasons). Defaults to just $season_id.
	 * @param string|null $from Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to   Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array $team_ids, fully ordered best-to-worst.
	 */
	public static function rank( array $team_ids, $season_id, array $combined_pim_season_ids = array(), $from = null, $to = null ) {
		if ( empty( $combined_pim_season_ids ) ) {
			$combined_pim_season_ids = array( $season_id );
		}

		$ranked = self::rank_by_points_h2h( $team_ids, $season_id, $from, $to );
		$order  = $ranked['order'];

		foreach ( $ranked['ties'] as $tied_group ) {
			$positions = self::tied_group_positions( $tied_group, $order );
			if ( null === $positions ) {
				continue;
			}

			// Replace the tied group's positions in $order with the resolved order.
			$resolved_order = self::resolve_tied_group( $tied_group, $combined_pim_season_ids, $season_id, $from, $to );
			foreach ( $positions as $i => $position ) {
				$order[ $position ] = $resolved_order[ $i ];
			}
		}

		return $order;
	}

	/**
	 * The ascending positions a tied group occupies within $order, or null if
	 * any member of the group is not in $order at all.
	 *
	 * That "not in $order" case is an invariant violation -- core reports its
	 * tiebreaker groups out of the very list it just ordered -- but if it ever
	 * happened, array_search()'s false would sort ahead of every real int and
	 * then overwrite $order[0], silently corrupting the TOP of the standings.
	 * Reporting null so the caller skips the group keeps a bug elsewhere from
	 * turning into bad published data.
	 *
	 * @param array $tied_group Team ids core left tied together.
	 * @param array $order      The current best-to-worst ordering.
	 * @return array|null Sorted positions, or null if the group is malformed.
	 */
	private static function tied_group_positions( array $tied_group, array $order ) {
		$positions = array();

		foreach ( $tied_group as $team_id ) {
			$position = array_search( $team_id, $order, true );
			if ( false === $position ) {
				return null;
			}
			$positions[] = $position;
		}

		sort( $positions );

		return $positions;
	}

	/**
	 * Resolve one group of points/H2H-tied teams into a best-to-worst order:
	 * sort by combined PIM (fewer is better), then independently resolve each
	 * same-PIM-value sub-group of exactly 2 via a persisted coin_flip().
	 *
	 * @param array       $tied_group               Team ids core left in one tie group.
	 * @param array       $combined_pim_season_ids  sp_season term ids to SUM PIM across.
	 * @param int|string  $season_id                Used only to namespace the coin-flip context key.
	 * @param string|null $from                     Inclusive lower post_date bound, 'Y-m-d'.
	 * @param string|null $to                       Inclusive upper post_date bound, 'Y-m-d'.
	 * @return array $tied_group, resolved best-to-worst.
	 */
	private static function resolve_tied_group( array $tied_group, array $combined_pim_season_ids, $season_id, $from, $to ) {
		$by_pim = array();
		foreach ( $tied_group as $team_id ) {
			$by_pim[ $team_id ] = self::team_pim_total( $team_id, $combined_pim_season_ids, $from, $to );
		}
		asort( $by_pim ); // fewer pim first

		$by_value = array();
		foreach ( $by_pim as $team_id => $pim ) {
			$by_value[ $pim ][] = $team_id;
		}

		$resolved_order = array();
		foreach ( $by_value as $bucket ) {
			if ( 2 === count( $bucket ) ) {
				list( $a, $b ) = $bucket;
				// The date range belongs in the key: the same pair can be tied
				// twice in one season through two different computations (full
				// regular-season standings, and a round-robin-only re-seed over
				// a sub-range), and those must get independent flips rather
				// than colliding on one persisted decision.
				$context = 'rank-tie-' . min( $a, $b ) . '-' . max( $a, $b ) . '-season-' . $season_id
					. '-' . ( $from ?? '' ) . '-' . ( $to ?? '' );
				$winner  = self::coin_flip( $a, $b, $context );
				$loser   = ( $winner === $a ) ? $b : $a;
				$bucket  = array( $winner, $loser );
			}
			// TODO: a 3+-way exact-PIM tie is left in PIM/tiebreakers order; no N-way persisted ordering yet.
			$resolved_order = array_merge( $resolved_order, $bucket );
		}

		return $resolved_order;
	}
}
