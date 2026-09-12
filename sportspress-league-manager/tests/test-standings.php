<?php
/**
 * Standalone tests for SPLM_Standings.
 *
 * @author Cody (lusky3)
 */

define( 'ABSPATH', __DIR__ );

/**
 * Mutable harness state. A class rather than $GLOBALS (Codacy PHPMD
 * Superglobals) and instance properties rather than statics with array
 * subscript writes (also flagged).
 */
class SPLM_Standings_Test_State {
	/** post_id => WP_Post-like stdClass, for get_posts(). */
	public $events = array();

	/** post_id => WP_Post-like stdClass, for get_posts() when post_type='sp_column'. */
	public $column_posts = array();

	/** post_id => array( meta_key => value ). */
	public $meta = array();

	/** option_name => value. */
	public $options = array();

	/** post_name => post_id, simulates get_page_by_path() / sp_column posts. */
	public $columns = array();

	/** post_id => array( taxonomy => term_ids ), for wp_set_object_terms() / sp_get_the_term_ids(). */
	public $object_terms = array();

	/** Post types post_type_exists() reports as registered. */
	public $registered_post_types = array();

	/** What get_the_ID() reports as WordPress's current global post. */
	public $current_post_id = 0;

	/** When non-null, wp_insert_post() returns this instead of inserting (0 or a WP_Error). */
	public $insert_post_failure = null;

	/** How many times get_page_by_path() has been consulted, to prove short-circuits really short-circuit. */
	public $page_by_path_calls = 0;
}

function splm_standings_test_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPLM_Standings_Test_State();
	}
	return $state;
}

/**
 * Minimal get_posts() stub: filters by post_type and applies tax_query/date_query.
 * Handles both sp_event (Task 1) and sp_column (Task 2) post types.
 * Real get_posts() takes a tax_query/date_query array; this stub reads the same
 * $args shape but resolves directly against the harness state instead of a database.
 */
/**
 * The sp_season terms an "IN" tax_query clause names, and whether that
 * clause was present at all -- distinct from "no clause" so an EMPTY terms
 * list (a real bug symptom, see get_posts()) isn't confused with "no season
 * filter requested."
 *
 * @param array $args get_posts()-style query args.
 * @return array{0: bool, 1: array} [has_season_clause, season_terms].
 */
function get_posts_season_clause( $args ) {
	foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
		if ( ( $clause['taxonomy'] ?? '' ) === 'sp_season' ) {
			return array( true, (array) $clause['terms'] );
		}
	}
	return array( false, array() );
}

/**
 * A date_query bound, as either a bare string or WP_Date_Query's array
 * shape, normalized to one comparable 'Y-m-d H:i:s' string.
 *
 * @param string|array|null $bound
 * @return string|null
 */
function get_posts_normalize_date_bound( $bound ) {
	if ( ! is_array( $bound ) ) {
		return $bound;
	}
	return sprintf(
		'%04d-%02d-%02d %02d:%02d:%02d',
		$bound['year'] ?? 0,
		$bound['month'] ?? 0,
		$bound['day'] ?? 0,
		$bound['hour'] ?? 0,
		$bound['minute'] ?? 0,
		$bound['second'] ?? 0
	);
}

/**
 * Whether one stubbed event matches the resolved season/date scope.
 *
 * @param object     $event
 * @param array      $season_terms
 * @param string|null $after
 * @param string|null $before
 * @return bool
 */
function get_posts_event_matches( $event, array $season_terms, $after, $before ) {
	if ( $season_terms && ! array_intersect( $season_terms, $event->sp_season_terms ) ) {
		return false;
	}
	if ( $after && $event->post_date < $after ) {
		return false;
	}
	if ( $before && $event->post_date > $before ) {
		return false;
	}
	return true;
}

function get_posts( $args ) {
	$state = splm_standings_test_state();

	list( $has_season_clause, $season_terms ) = get_posts_season_clause( $args );

	// Real WP_Tax_Query returns NO results for an "IN" clause with empty terms
	// (class-wp-tax-query.php: `if ( empty( $terms ) ) return self::$no_results;`),
	// rather than ignoring the clause. Modelling that is what makes an
	// empty-season-scope bug visible here instead of silently matching
	// everything.
	if ( $has_season_clause && empty( $season_terms ) ) {
		return array();
	}

	$after  = get_posts_normalize_date_bound( $args['date_query'][0]['after'] ?? null );
	$before = get_posts_normalize_date_bound( $args['date_query'][0]['before'] ?? null );

	$post_type        = $args['post_type'] ?? 'post';
	$posts_to_search  = 'sp_column' === $post_type ? $state->column_posts : $state->events;

	$matches = array();
	foreach ( $posts_to_search as $event ) {
		if ( get_posts_event_matches( $event, $season_terms, $after, $before ) ) {
			$matches[] = $event;
		}
	}
	return $matches;
}

function get_post_meta( $post_id, $key, $single = false ) {
	$state = splm_standings_test_state();
	// Real WordPress returns '' for a missing single value, which matters here:
	// SP_League_Table::data() leans on the falsiness of a missing sp_date.
	$value = $state->meta[ (int) $post_id ][ $key ] ?? ( $single ? '' : array() );
	return $value;
}

function update_post_meta( $post_id, $key, $value ) {
	$state = splm_standings_test_state();
	$state->meta[ (int) $post_id ][ $key ] = $value;
	return true;
}

/**
 * Stub mirroring the WordPress signature; the unused argument is deliberate.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function get_page_by_path( $path, $output = OBJECT, $post_type = 'page' ) {
	$state = splm_standings_test_state();
	$state->page_by_path_calls++;
	$key = $post_type . ':' . $path;
	if ( ! isset( $state->columns[ $key ] ) ) {
		return null;
	}
	return (object) array( 'ID' => $state->columns[ $key ] );
}

function wp_insert_post( $post_array ) {
	$state = splm_standings_test_state();
	if ( null !== $state->insert_post_failure ) {
		return $state->insert_post_failure;
	}
	$id  = count( $state->columns ) + 1;
	$key = $post_array['post_type'] . ':' . $post_array['post_name'];
	$state->columns[ $key ] = $id;
	return $id;
}

function wp_set_object_terms( $post_id, $terms, $taxonomy ) {
	$state = splm_standings_test_state();
	$state->object_terms[ $post_id ][ $taxonomy ] = $terms;
	return $terms;
}

/**
 * Minimal WP_Error stand-in: only its type matters to is_wp_error().
 */
class WP_Error {}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

function post_type_exists( $post_type ) {
	$state = splm_standings_test_state();
	return in_array( $post_type, $state->registered_post_types, true );
}

function get_the_ID() {
	return splm_standings_test_state()->current_post_id;
}

/**
 * SportsPress's own term-id helper, backed by the same store
 * wp_set_object_terms() writes to.
 */
function sp_get_the_term_ids( $post_id, $taxonomy ) {
	$state = splm_standings_test_state();
	return (array) ( $state->object_terms[ $post_id ][ $taxonomy ] ?? array() );
}

function get_option( $name, $default = false ) {
	$state = splm_standings_test_state();
	return array_key_exists( $name, $state->options ) ? $state->options[ $name ] : $default;
}

function update_option( $name, $value ) {
	$state                    = splm_standings_test_state();
	$state->options[ $name ] = $value;
	return true;
}

function wp_rand( $min, $max ) {
	return random_int( $min, $max );
}

if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}

/**
 * Stub mirroring the WordPress signature; the unused arguments are
 * deliberate -- register_hooks() is exercised by calling
 * filter_pim_equation() directly in tests, not through apply_filters().
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	return true; // register_hooks() is exercised by calling filter_pim_equation() directly in tests, not through apply_filters().
}

require_once __DIR__ . '/../includes/class-standings.php';

$passed = 0;
$failed = 0;

function assert_test( $condition, $message ) {
	global $passed, $failed;
	if ( $condition ) {
		echo "✓ PASS: {$message}\n";
		$passed++;
	} else {
		echo "✗ FAIL: {$message}\n";
		$failed++;
	}
}

echo "=== team_pim_total(): sums a team's players' pim across matching events ===\n\n";

$state = splm_standings_test_state();

$state->events[101] = (object) array( 'ID' => 101, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->events[102] = (object) array( 'ID' => 102, 'post_date' => '2026-10-02', 'sp_season_terms' => array( 5 ) );
$state->events[103] = (object) array( 'ID' => 103, 'post_date' => '2027-03-05', 'sp_season_terms' => array( 9 ) ); // different (playoff) season
$state->events[104] = (object) array( 'ID' => 104, 'post_date' => '2026-10-05 19:00:00', 'sp_season_terms' => array( 5 ) ); // same day as range end, with timestamp

$state->meta[101] = array(
	'sp_players' => array(
		200 => array( 0 => array( 'pim' => 2 ) ), // team 200: player 0 is the reserved row, still counted if present
		201 => array( 5 => array( 'pim' => 4 ), 6 => array( 'pim' => 2 ) ), // team 201
	),
);
$state->meta[102] = array(
	'sp_players' => array(
		200 => array( 7 => array( 'pim' => 10 ) ),
		201 => array( 5 => array( 'pim' => 1 ) ),
	),
);
$state->meta[103] = array(
	'sp_players' => array(
		201 => array( 5 => array( 'pim' => 100 ) ), // wrong season, must not count
	),
);
$state->meta[104] = array(
	'sp_players' => array(
		201 => array( 5 => array( 'pim' => 3 ) ),
	),
);

assert_test(
	10 === SPLM_Standings::team_pim_total( 201, array( 5 ) ),
	'team 201, season 5: sums 4+2 (event 101), 1 (event 102), and 3 (event 104) = 10, ignores the season-9 event'
);

assert_test(
	12 === SPLM_Standings::team_pim_total( 200, array( 5 ) ),
	'team 200, season 5: 2 (event 101) + 10 (event 102) = 12'
);

assert_test(
	0 === SPLM_Standings::team_pim_total( 999, array( 5 ) ),
	'a team with no events returns 0, not null or an error'
);

assert_test(
	4 === SPLM_Standings::team_pim_total( 201, array( 5 ), '2026-09-26', '2026-10-05' ),
	'end-of-day inclusive: a date range with $to = "2026-10-05" includes event 104 at "2026-10-05 19:00:00" (event 102 = 1 pim, event 104 = 3 pim, total = 4)'
);

assert_test(
	1 === SPLM_Standings::team_pim_total( 201, array( 5 ), '2026-09-26', '2026-10-04' ),
	'boundary check: $to = "2026-10-04" correctly excludes event 104 at "2026-10-05 19:00:00", keeping only event 102 (1 pim)'
);

echo "\n=== ensure_pim_column(): idempotent creation with correct metadata ===\n\n";

// Reset state for this test block
$state->columns = array(); // post_name => post_id, simulates get_page_by_path()
$state->column_posts = array(); // post_id => WP_Post-like object, for get_posts() queries
$state->meta = array(); // Clear metadata from Task 1 (events) to isolate Task 2

// Seed existing sp_column posts with known priorities
$state->column_posts[10] = (object) array( 'ID' => 10, 'post_date' => '2026-01-01', 'sp_season_terms' => array() );
$state->meta[10] = array( 'sp_priority' => 3 );
$state->column_posts[11] = (object) array( 'ID' => 11, 'post_date' => '2026-01-02', 'sp_season_terms' => array() );
$state->meta[11] = array( 'sp_priority' => 5 );

assert_test(
	1 === SPLM_Standings::ensure_pim_column(),
	'first call creates the column and returns its new post id (1)'
);
assert_test(
	1 === count( $state->columns ),
	'exactly one sp_column post named pim now exists'
);

// Verify the created column's metadata
$pim_id = 1;
assert_test(
	'$pim' === $state->meta[ $pim_id ]['sp_equation'] ?? null,
	'created column has sp_equation = "$pim"'
);
assert_test(
	0 === $state->meta[ $pim_id ]['sp_precision'] ?? null,
	'created column has sp_precision = 0'
);
assert_test(
	'ASC' === $state->meta[ $pim_id ]['sp_order'] ?? null,
	'created column has sp_order = "ASC"'
);
assert_test(
	6 === $state->meta[ $pim_id ]['sp_priority'] ?? null,
	'created column has sp_priority = 6 (one more than max of existing 3 and 5)'
);

assert_test(
	1 === SPLM_Standings::ensure_pim_column(),
	'second call is a no-op: returns the SAME id, does not create a duplicate'
);
assert_test(
	1 === count( $state->columns ),
	'still exactly one sp_column post after calling ensure_pim_column() twice'
);

echo "\n=== ensure_pim_column(): a failed wp_insert_post() is not treated as a post id ===\n\n";

$state->columns             = array();
$state->insert_post_failure = 0; // wp_insert_post()'s documented failure return

assert_test(
	0 === SPLM_Standings::ensure_pim_column(),
	'a wp_insert_post() that returns 0 makes ensure_pim_column() return 0, not a bogus id'
);
assert_test(
	! isset( $state->meta[0] ),
	'...and no sp_equation/sp_priority meta is scribbled onto post id 0'
);
assert_test(
	array() === $state->columns,
	'...and no column post is recorded'
);

$state->insert_post_failure = null;

echo "\n=== maybe_ensure_pim_column(): gated on SportsPress, and runs once per site ===\n\n";

$state->columns              = array();
$state->options              = array();
$state->registered_post_types = array(); // SportsPress not loaded: sp_column unregistered

assert_test(
	0 === SPLM_Standings::maybe_ensure_pim_column(),
	'skips entirely when sp_column is not a registered post type'
);
assert_test(
	array() === $state->columns,
	'...creating no orphaned column post'
);
assert_test(
	false === get_option( 'splm_standings_pim_column_ensured' ),
	'...and not setting the ensured flag, so a later request retries'
);

$state->registered_post_types = array( 'sp_column' );

$maybe_created = SPLM_Standings::maybe_ensure_pim_column();
assert_test(
	$maybe_created > 0 && 1 === count( $state->columns ),
	'creates the column once sp_column is registered'
);
assert_test(
	1 === (int) get_option( 'splm_standings_pim_column_ensured' ),
	'...and records the ensured flag'
);

$columns_snapshot   = $state->columns;
$lookups_before     = $state->page_by_path_calls;

assert_test(
	0 === SPLM_Standings::maybe_ensure_pim_column(),
	'a later request short-circuits on the ensured option'
);
assert_test(
	$lookups_before === $state->page_by_path_calls,
	'...without even running the get_page_by_path() existence check'
);
assert_test(
	$columns_snapshot === $state->columns,
	'...and without creating a second column'
);

$state->options             = array();
$state->columns             = array();
$state->insert_post_failure = 0;

assert_test(
	0 === SPLM_Standings::maybe_ensure_pim_column() && false === get_option( 'splm_standings_pim_column_ensured' ),
	'a failed creation does NOT set the ensured flag, so the next request tries again'
);

$state->insert_post_failure = null;
$state->options             = array();

echo "\n=== filter_pim_equation(): answers \$pim equations, ignores everything else ===\n\n";

// Re-seed event data for this test block (Task 1 data)
$state->events[201] = (object) array( 'ID' => 201, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[201]   = array( 'sp_players' => array( 301 => array( 9 => array( 'pim' => 6 ) ) ) );

assert_test(
	null === SPLM_Standings::filter_pim_equation( null, '$w+$l', 201 ),
	'a non-pim equation is left alone (returns the passed-through null unchanged)'
);

// Nothing has called rank_by_points_h2h() or set_current_table_season_ids()
// yet, so these two assertions run against the genuinely un-overridden state:
// exactly the situation a plain front-end league-table render is in, where the
// old static-only lookup resolved every team to an empty season list and so
// printed 0 for everyone.

// Fallback 3: WordPress's global post context IS the sp_table (single-table
// template, admin table editor).
$state->current_post_id            = 700;
$state->object_terms[700]['sp_season'] = array( 5 );

assert_test(
	6 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'with no override set at all, the season scope falls back to the current post\'s own sp_season terms (NOT an empty scope resolving to 0)'
);

// Fallback 2: the table is a shortcode on some other post, so the global post
// is useless -- but core's own sportspress_table_data_event_args filter, fired
// inside the data() call we are nested in, carries the real scope.
$state->current_post_id = 701; // a plain page with no sp_season terms

assert_test(
	0 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'a global post with no seasons of its own yields nothing on its own'
);

SPLM_Standings::capture_table_season_scope(
	array(
		'post_type' => 'sp_event',
		'tax_query' => array(
			array(
				'taxonomy' => 'sp_season',
				'field'    => 'term_id',
				'terms'    => array( 5 ),
			),
		),
	)
);

assert_test(
	6 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'...but the scope core itself passed into the in-progress data() call resolves it, with no override and an unhelpful global post'
);

SPLM_Standings::set_current_table_season_ids( array( 5 ) );

assert_test(
	6 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'a $pim equation resolves to that team\'s pim total for whatever season(s) the current table is scoped to'
);

SPLM_Standings::set_current_table_season_ids( array( 9 ) );

assert_test(
	0 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'an explicit override wins over both fallbacks (season 9 has none of team 301\'s pim)'
);

SPLM_Standings::clear_current_table_season_ids();

assert_test(
	6 === SPLM_Standings::filter_pim_equation( null, '$pim', 301 ),
	'clearing the override restores the fallback scope instead of leaving the last rank() call\'s season behind'
);

echo "\n=== rank_by_points_h2h(): orders team ids using SP_League_Table ===\n\n";

/**
 * Stand-in for core's SP_League_Table, modelled on the REAL
 * SP_League_Table::data() rather than on a description of it.
 *
 * The load-bearing detail: data() resolves its date scope by re-reading
 * sp_date / sp_date_relative / sp_date_from / sp_date_to from POST META,
 * discarding whatever the caller assigned to $date/$from/$to on the object.
 * An earlier version of this double read those properties instead, which is
 * exactly why a caller that set the properties looked like it worked.
 */
class SPLM_Standings_Test_Table {
	public $ID;
	public $date = 0;
	public $relative = '';
	public $from = 'now';
	public $to   = 'now';
	public $tiebreakers = array();

	/**
	 * Set by tests before constructing, to control what data() reports as
	 * tied. Default (Task 3's original behavior): the whole input list
	 * tied at position 0.
	 */
	public static $simulated_ties = null;

	/**
	 * Queue of canned responses for the SECOND and later data() calls in a
	 * request -- i.e. the recursive head-to-head calls apply_head_to_head()
	 * makes -- each shift()ed off in turn: array( 'order' => [...], 'tiebreakers' => [...] ).
	 * Empty means "fall back to the same default (reverse input) behavior
	 * as the first call."
	 */
	public static $h2h_responses = array();

	/** Every $team_ids a data() call received, in call order, for asserting on recursion. */
	public static $call_log = array();

	/** What the most recent data() call actually resolved its date scope to. */
	public static $observed = array();

	public function __construct( $id ) {
		$this->ID = $id;
	}

	/**
	 * Stub mirroring the real SP_League_Table::data( $admin, $team_ids )
	 * signature; $admin is unused here exactly as it would be by any caller
	 * on the `! $is_main_loop` path this class always takes.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function data( $admin = false, $team_ids = null ) {
		// --- mirrors real SP_League_Table::data() ---
		$this->date = get_post_meta( $this->ID, 'sp_date', true );
		if ( ! $this->date ) {
			$this->date = 0;
		}

		$this->from = null;
		$this->to   = null;

		if ( 'range' === $this->date ) {
			$this->relative = get_post_meta( $this->ID, 'sp_date_relative', true );
			if ( ! $this->relative ) {
				$this->from = get_post_meta( $this->ID, 'sp_date_from', true );
				$this->to   = get_post_meta( $this->ID, 'sp_date_to', true );
			}
		}

		self::$observed = array(
			'date'     => $this->date,
			'relative' => $this->relative,
			'from'     => $this->from,
			'to'       => $this->to,
		);

		// Core fires this filter from inside data(), before the sp_solve()
		// loop, with the table's own season terms in the event query.
		SPLM_Standings::capture_table_season_scope(
			array(
				'post_type' => 'sp_event',
				'tax_query' => array(
					array(
						'taxonomy' => 'sp_season',
						'field'    => 'term_id',
						'terms'    => sp_get_the_term_ids( $this->ID, 'sp_season' ),
					),
				),
			)
		);
		// --- end of core mirror ---

		self::$call_log[] = $team_ids;

		// Only the SECOND-and-later call in a request (i.e. a recursive
		// head-to-head call from apply_head_to_head()) draws from the
		// queue -- the first (outer) call always takes the default path
		// below, exactly like before this stub could simulate recursion.
		if ( count( self::$call_log ) > 1 && ! empty( self::$h2h_responses ) ) {
			$response = array_shift( self::$h2h_responses );
			$this->merge_tiebreakers( $response['tiebreakers'] );
			return array_fill_keys( $response['order'], array() );
		}

		$reversed = array_reverse( $team_ids );

		$this->merge_tiebreakers(
			( null === self::$simulated_ties ) ? array( 0 => $reversed ) : self::$simulated_ties
		);

		return array_fill_keys( $reversed, array() );
	}

	/**
	 * Mirrors real SP_League_Table::calculate_pos()'s own tiebreakers
	 * write: APPENDS to $this->tiebreakers, never resets it -- real data()
	 * only ever resets $this->pos/$this->counter, not $this->tiebreakers.
	 * Without this, the stub couldn't reproduce the real contamination bug
	 * apply_head_to_head() has to defend against (a recursive call's own
	 * tiebreakers merging with -- and being unreadable from -- an earlier
	 * call's leftovers at the same position key).
	 *
	 * @param array $new_tiebreakers pos => array of team ids.
	 */
	private function merge_tiebreakers( array $new_tiebreakers ) {
		foreach ( $new_tiebreakers as $pos => $group ) {
			if ( ! isset( $this->tiebreakers[ $pos ] ) ) {
				$this->tiebreakers[ $pos ] = array();
			}
			foreach ( $group as $id ) {
				if ( ! in_array( $id, $this->tiebreakers[ $pos ], true ) ) {
					$this->tiebreakers[ $pos ][] = $id;
				}
			}
		}
	}
}

if ( ! class_exists( 'SP_League_Table' ) ) {
	class_alias( 'SPLM_Standings_Test_Table', 'SP_League_Table' );
}

$state->object_terms = array(); // post_id => array( taxonomy => term_ids )

// No ties in this section -- it's testing the basic ordering/season-wiring
// only. The dedicated head-to-head tests below cover the tie-resolution path.
SPLM_Standings_Test_Table::$simulated_ties = array();

// Runs FIRST, while scratch_table_id()'s static is still unset, so the failure
// path is the one exercised -- and so the next call proves the failure was not
// memoized.
$state->insert_post_failure = new WP_Error();

$failed_rank = SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );
assert_test(
	0 === SPLM_Standings::scratch_table_id(),
	'a WP_Error from wp_insert_post() makes scratch_table_id() return 0 rather than a WP_Error object'
);
assert_test(
	array( 10, 20, 30 ) === $failed_rank['order'] && array() === $failed_rank['ties'],
	'...and rank_by_points_h2h() hands the input back untouched instead of querying post id 0'
);
assert_test(
	! isset( $state->object_terms[0] ) && ! isset( $state->meta[0] ),
	'...writing no terms or meta onto post id 0'
);

$state->insert_post_failure = null;

assert_test(
	SPLM_Standings::scratch_table_id() > 0,
	'the failed id was never memoized: the next call creates the scratch table for real'
);

$ranked = SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );
assert_test(
	array( 30, 20, 10 ) === $ranked['order'],
	'reorders team_ids using SP_League_Table::data() output order'
);
assert_test(
	array() === $ranked['ties'],
	'no ties simulated in this section (see the dedicated head-to-head tests below for tiebreakers exposure)'
);
assert_test(
	array( 5 ) === ( $state->object_terms[ SPLM_Standings::scratch_table_id() ]['sp_season'] ?? null ),
	'assigns the requested season id to the scratch table before querying'
);

echo "\n=== rank_by_points_h2h(): head-to-head runs itself, since core's own re-sort structurally can't ===\n\n";

// Core's native h2h block is gated on `$is_main_loop`, which is forced false
// the instant $team_ids is passed to data() -- true of every call this class
// makes. Proving a SECOND data() call happens, restricted to just the tied
// group, is proof this class runs the recursion itself rather than relying on
// (and silently getting nothing from) that gated core option.

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 10, 20, 30 ) ); // all three tied on points
SPLM_Standings_Test_Table::$h2h_responses  = array(
	array(
		'order'       => array( 20, 10, 30 ),
		'tiebreakers' => array( 0 => array( 20 ), 1 => array( 10 ), 2 => array( 30 ) ), // fully resolved
	),
);
SPLM_Standings_Test_Table::$call_log = array();

$h2h_ranked = SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );

assert_test(
	2 === count( SPLM_Standings_Test_Table::$call_log ),
	'the tied group triggers a SECOND data() call -- the recursion core itself would run if $is_main_loop allowed it'
);
assert_test(
	array( 10, 20, 30 ) === ( SPLM_Standings_Test_Table::$call_log[1] ?? null ),
	'the recursive call is restricted to exactly the tied group (core\'s own `$this->data(false, $teams)` line)'
);
assert_test(
	array( 20, 10, 30 ) === $h2h_ranked['order'],
	'head-to-head re-orders the tied group -- the plain points/PIM order does NOT survive unchanged'
);
assert_test(
	array() === $h2h_ranked['ties'],
	'head-to-head leaving no one tied means no residual ties reach PIM/coin-flip'
);

echo "\n=== rank_by_points_h2h(): a group head-to-head only partially resolves leaves a smaller residual tie ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 10, 20, 30 ) );
SPLM_Standings_Test_Table::$h2h_responses  = array(
	array(
		'order'       => array( 20, 10, 30 ),
		'tiebreakers' => array( 0 => array( 20 ), 1 => array( 10, 30 ) ), // 10 and 30 still tied
	),
);
SPLM_Standings_Test_Table::$call_log = array();

$h2h_partial = SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );

assert_test(
	array( 20, 10, 30 ) === $h2h_partial['order'],
	'the group is still re-sequenced by head-to-head even when it only partially resolves the tie'
);
assert_test(
	array( array( 10, 30 ) ) === $h2h_partial['ties'],
	'only the still-tied subset survives as a residual tie group for PIM/coin-flip to pick up next'
);

SPLM_Standings_Test_Table::$h2h_responses  = array();
SPLM_Standings_Test_Table::$simulated_ties = array(); // back to "no ties" for the sections below

echo "\n=== rank_by_points_h2h(): the date range reaches core as POST META, not object properties ===\n\n";

// Regression test for the original bug: the range was assigned to
// $table->date/from/to, which real SP_League_Table::data() overwrites from
// sp_date/sp_date_from/sp_date_to meta on its very first lines. The range was
// therefore silently discarded and every "ranking over a sub-range" call
// actually ranked the whole season.

$scratch = SPLM_Standings::scratch_table_id();

SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5, '2026-09-26', '2026-10-05' );

assert_test(
	'range' === ( $state->meta[ $scratch ]['sp_date'] ?? null ),
	'sp_date meta is written as "range" on the scratch table post'
);
assert_test(
	'2026-09-26' === ( $state->meta[ $scratch ]['sp_date_from'] ?? null )
		&& '2026-10-05' === ( $state->meta[ $scratch ]['sp_date_to'] ?? null ),
	'sp_date_from / sp_date_to meta carry the requested bounds'
);
assert_test(
	empty( $state->meta[ $scratch ]['sp_date_relative'] ),
	'sp_date_relative meta is falsy, so core takes its range() path and not relative()'
);
assert_test(
	array(
		'date'     => 'range',
		'relative' => '',
		'from'     => '2026-09-26',
		'to'       => '2026-10-05',
	) === SPLM_Standings_Test_Table::$observed,
	'and the range is what data() ACTUALLY resolves, reading it back out of meta the way core does'
);

SPLM_Standings::rank_by_points_h2h( array( 10, 20, 30 ), 5 );

assert_test(
	0 === ( $state->meta[ $scratch ]['sp_date'] ?? null ),
	'a later call with no range resets sp_date to 0 instead of inheriting the previous call\'s range'
);
assert_test(
	0 === SPLM_Standings_Test_Table::$observed['date']
		&& null === SPLM_Standings_Test_Table::$observed['from'],
	'...and data() sees no range at all on that call'
);

echo "\n=== coin_flip(): decides once, then stays put ===\n\n";

$winner_first_call = SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-3' );
assert_test(
	10 === $winner_first_call || 20 === $winner_first_call,
	'the winner is one of the two teams involved'
);
assert_test(
	$winner_first_call === SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-3' ),
	'a second call with the same context_key returns the SAME winner, not a fresh flip'
);
assert_test(
	$winner_first_call === SPLM_Standings::coin_flip( 20, 10, 'div1-2027-final-rank-3' ),
	'the winner is stable even if the two team ids are passed in reverse order'
);

$other_context_winner = SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-5' );
assert_test(
	$other_context_winner === SPLM_Standings::coin_flip( 10, 20, 'div1-2027-final-rank-5' ),
	'a different context_key is ALSO stable across repeated calls, independent of the first'
);

echo "\n=== coin_flip(): resolves correctly when the stored value comes back as a string ===\n\n";

// Real WordPress round-trips option values through the DB as strings, so
// get_option() can hand back "20" even though update_option() was originally
// called with the int 20. The test harness's plain-array option store
// preserves PHP types, so we seed the string directly here to reproduce what
// a real second-request recompute would see.
$state->options['splm_standings_coin_flip_string-regression-b'] = '20'; // matches $team_b_id
$resolved_b = SPLM_Standings::coin_flip( 10, 20, 'string-regression-b' );
assert_test(
	20 === $resolved_b && is_int( $resolved_b ),
	'a string-typed stored winner ("20") still resolves to the matching argument as an int, not the raw string'
);

$state->options['splm_standings_coin_flip_string-regression-a'] = '10'; // matches $team_a_id
$resolved_a = SPLM_Standings::coin_flip( 10, 20, 'string-regression-a' );
assert_test(
	10 === $resolved_a && is_int( $resolved_a ),
	'also resolves correctly (as team_a_id) when the stored string matches the FIRST argument'
);

echo "\n=== rank(): points/H2H order stands when nothing is tied ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array(); // nothing tied

$state->events[301] = (object) array( 'ID' => 301, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[301]   = array( 'sp_players' => array( 10 => array( 1 => array( 'pim' => 0 ) ) ) );

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank( array( 10, 20, 30 ), 5 ),
	'with no ties, rank() returns rank_by_points_h2h()\'s order unchanged -- PIM/coin-flip never consulted'
);

echo "\n=== rank(): a tied pair is re-ordered by combined PIM ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 20, 30 ) ); // 20 and 30 tied, 10 clear

$state->events[401] = (object) array( 'ID' => 401, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[401]   = array(
	'sp_players' => array(
		20 => array( 1 => array( 'pim' => 8 ) ),
		30 => array( 1 => array( 'pim' => 2 ) ), // fewer pim, should rank ABOVE 20 despite the reversed order() from data()
	),
);

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank( array( 10, 20, 30 ), 5, array( 5 ) ),
	'team 30 (2 pim) ranks above team 20 (8 pim) once combined PIM breaks their tie'
);

echo "\n=== rank(): a pair still tied after PIM goes to a persisted coin flip ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 40, 50 ) );

$state->events[501] = (object) array( 'ID' => 501, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[501]   = array(
	'sp_players' => array(
		40 => array( 1 => array( 'pim' => 4 ) ),
		50 => array( 1 => array( 'pim' => 4 ) ), // identical pim -- still tied
	),
);

$result       = SPLM_Standings::rank( array( 40, 50 ), 5, array( 5 ) );
// The trailing "--" is the (empty) from/to pair: the context key is scoped to
// the date range as well as the season, so a sub-range tie cannot collide with
// this full-season one.
$coin_winner  = get_option( 'splm_standings_coin_flip_rank-tie-40-50-season-5--' );
assert_test(
	null !== $coin_winner,
	'a genuinely equal PIM tie falls through to coin_flip(), which persists a winner'
);
assert_test(
	$result[0] === $coin_winner,
	"the coin flip's winner is placed first"
);

echo "\n=== rank(): two independent PIM-tied pairs within one points-tied group are each resolved separately ===\n\n";

// 4 teams all points/H2H-tied together, but two DISTINCT pim values -- 60/70
// share the lower (better) pim, 80/90 share a separate, higher pim. Both
// pairs must each get their own coin_flip(), and the lower-pim pair must
// rank entirely ahead of the higher-pim pair.
SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 60, 70, 80, 90 ) );

$state->events[601] = (object) array( 'ID' => 601, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[601]   = array(
	'sp_players' => array(
		60 => array( 1 => array( 'pim' => 2 ) ),
		70 => array( 1 => array( 'pim' => 2 ) ), // tied with 60, lower pim than the 80/90 pair
		80 => array( 1 => array( 'pim' => 6 ) ),
		90 => array( 1 => array( 'pim' => 6 ) ), // tied with 80, higher pim than the 60/70 pair
	),
);

$multi_result  = SPLM_Standings::rank( array( 60, 70, 80, 90 ), 5, array( 5 ) );
$low_pair_win  = get_option( 'splm_standings_coin_flip_rank-tie-60-70-season-5--' );
$high_pair_win = get_option( 'splm_standings_coin_flip_rank-tie-80-90-season-5--' );

assert_test(
	null !== $low_pair_win && null !== $high_pair_win,
	'both independently-pim-tied pairs each get their OWN persisted coin flip'
);
assert_test(
	in_array( 60, array_slice( $multi_result, 0, 2 ), true ) && in_array( 70, array_slice( $multi_result, 0, 2 ), true ),
	'the lower-pim pair (60, 70) occupies the first two positions'
);
assert_test(
	in_array( 80, array_slice( $multi_result, 2, 2 ), true ) && in_array( 90, array_slice( $multi_result, 2, 2 ), true ),
	'the higher-pim pair (80, 90) occupies the last two positions, after the whole lower-pim pair'
);
assert_test(
	$multi_result[0] === $low_pair_win,
	"the lower-pim pair's coin-flip winner ranks first"
);
assert_test(
	$multi_result[2] === $high_pair_win,
	"the higher-pim pair's coin-flip winner ranks third (first of its own pair)"
);

echo "\n=== rank(): a tie over a date sub-range gets its own coin flip ===\n\n";

// The same pair can be tied twice in one season -- once in the full
// regular-season standings, once in a round-robin-only re-seed over a
// sub-range -- and those two flips must be independent.
SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 40, 50 ) );

$ranged_key = 'splm_standings_coin_flip_rank-tie-40-50-season-5-2026-09-01-2026-09-30'; // gitleaks:allow -- a wp_options option name, not a secret

// Seed the ranged flip with the OPPOSITE of the full-season winner, so a
// collision between the two keys would be unmistakable.
$opposite_winner            = ( 40 === $coin_winner ) ? 50 : 40;
$state->options[ $ranged_key ] = $opposite_winner;

$ranged_result = SPLM_Standings::rank( array( 40, 50 ), 5, array( 5 ), '2026-09-01', '2026-09-30' );

assert_test(
	$ranged_result[0] === $opposite_winner,
	'a tie over a date sub-range resolves from its OWN persisted flip, not the full-season one'
);
assert_test(
	$coin_winner === get_option( 'splm_standings_coin_flip_rank-tie-40-50-season-5--' ),
	'...and the full-season flip for the same pair in the same season is left untouched'
);

echo "\n=== rank(): \$combined_pim_season_ids defaults to the ranking season ===\n\n";

SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 110, 120 ) );

$state->events[701] = (object) array( 'ID' => 701, 'post_date' => '2026-09-25', 'sp_season_terms' => array( 5 ) );
$state->meta[701]   = array(
	'sp_players' => array(
		110 => array( 1 => array( 'pim' => 1 ) ), // fewer pim in season 5 -> should win the tiebreak
		120 => array( 1 => array( 'pim' => 9 ) ),
	),
);
// Decoy in a DIFFERENT season: counted only if the default scope were wrong
// (it would push 110 to 51 pim and flip the expected order).
$state->events[702] = (object) array( 'ID' => 702, 'post_date' => '2027-03-05', 'sp_season_terms' => array( 9 ) );
$state->meta[702]   = array( 'sp_players' => array( 110 => array( 1 => array( 'pim' => 50 ) ) ) );

// Note: no $combined_pim_season_ids argument at all.
$defaulted = SPLM_Standings::rank( array( 110, 120 ), 5 );

assert_test(
	array( 110, 120 ) === $defaulted,
	'with $combined_pim_season_ids omitted, the PIM tiebreak still runs, scoped to $season_id alone (and reverses data()\'s order)'
);
assert_test(
	false === get_option( 'splm_standings_coin_flip_rank-tie-110-120-season-5--' ),
	'...resolved by PIM outright, so no coin flip was needed -- proving the default scope found real events rather than an empty 0-vs-0 scope'
);

echo "\n=== rank(): a tie group naming a team outside the ranking is skipped, not applied ===\n\n";

// Invariant violation: core reports tiebreaker groups out of the list it just
// ordered, so 999 cannot legitimately appear here. Before the guard,
// array_search()'s false sorted ahead of every real position and overwrote
// $order[0] -- corrupting the TOP of the standings.
SPLM_Standings_Test_Table::$simulated_ties = array( 0 => array( 20, 999 ) );

assert_test(
	array( 30, 20, 10 ) === SPLM_Standings::rank( array( 10, 20, 30 ), 5, array( 5 ) ),
	'the malformed group is skipped and the points/H2H order stands, with nothing written to position 0'
);

SPLM_Standings_Test_Table::$simulated_ties = null; // reset so later tests don't inherit this configuration

echo "\n=== Results ===\n\nPassed: {$passed}\nFailed: {$failed}\n";
exit( $failed > 0 ? 1 : 0 );
