<?php
/**
 * Shared WordPress stubs for the Player App standalone test suites.
 *
 * Usage (from a suite): require __DIR__ . '/bootstrap.php';
 *
 * State lives in $GLOBALS['sppa_*']; call sppa_reset() at the top of a test
 * block to start from a clean slate. Fixture helpers (sppa_add_post() and
 * friends) populate it; the stubbed WP functions read from it.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

/* ------------------------------------------------------------------------
 * Assertions
 * --------------------------------------------------------------------- */

$GLOBALS['sppa_passed'] = 0;
$GLOBALS['sppa_failed'] = 0;

function sppa_assert( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['sppa_passed'];
		echo "PASS: {$label}\n";
	} else {
		++$GLOBALS['sppa_failed'];
		echo "FAIL: {$label}\n";
	}
}

/** Print the summary and exit non-zero when anything failed. */
function sppa_done() {
	echo "\nPassed: {$GLOBALS['sppa_passed']}  Failed: {$GLOBALS['sppa_failed']}\n";
	exit( $GLOBALS['sppa_failed'] > 0 ? 1 : 0 );
}

/* ------------------------------------------------------------------------
 * State
 * --------------------------------------------------------------------- */

function sppa_reset() {
	$GLOBALS['sppa_options']    = array( 'timezone_string' => 'America/Toronto' );
	$GLOBALS['sppa_posts']      = array(); // id => object.
	$GLOBALS['sppa_meta']       = array(); // id => key => list of row values.
	$GLOBALS['sppa_terms']      = array(); // id => taxonomy => list of term objects.
	$GLOBALS['sppa_term_meta']  = array(); // term_id => key => value.
	$GLOBALS['sppa_term_store'] = array(); // taxonomy => term_id => term object.
	$GLOBALS['sppa_queries']    = array(); // WP_Query args, in call order.
	$GLOBALS['sppa_filters']    = array(); // tag => list of callbacks.
	$GLOBALS['sppa_actions']    = array(); // tag => list of callbacks.
	$GLOBALS['sppa_user_id']    = 0;
	$GLOBALS['sppa_user_caps']  = array();
	$GLOBALS['sppa_users']      = array(); // id => user object (ID, user_email, display_name).
	$GLOBALS['sppa_user_meta']  = array(); // id => key => value.
	$GLOBALS['sppa_fired']      = array(); // do_action calls: [ tag, args ].
	$GLOBALS['sppa_mail']       = array(); // wp_mail calls.
	$GLOBALS['sppa_mail_ok']    = true;
	$GLOBALS['sppa_lock_calls'] = array(); // SPAT_Lock::with keys, in call order.
	$GLOBALS['sppa_lock_ttls']  = array(); // and their TTLs.
	$GLOBALS['sppa_lock_held']  = false;   // true: the lock is held elsewhere.
	$GLOBALS['sppa_lock_race']  = null;    // callable run after the lock is taken, before the callback.
	$GLOBALS['sppa_transients']        = array();
	$GLOBALS['sppa_transient_reads']   = array();
	$GLOBALS['sppa_transient_writes']  = array();
	$GLOBALS['sppa_meta_cache_primed'] = array();
	$GLOBALS['sppa_posts_primed']      = array(); // _prime_post_caches / update_object_term_cache id lists.
	$GLOBALS['sppa_cron']              = array(); // hook => [ time, recurrence ].
	$GLOBALS['sppa_user_queries']      = array();
	$GLOBALS['sppa_roles']             = array(); // role => SPPA_Test_Role.
	$GLOBALS['wpdb']            = new SPPA_Test_WPDB();
}

/** Add a post fixture. $props may override post_date / post_date_gmt. */
function sppa_add_post( $id, $type, $title = '', $status = 'publish', array $props = array() ) {
	$GLOBALS['sppa_posts'][ $id ] = (object) array_merge(
		array(
			'ID'            => $id,
			'post_type'     => $type,
			'post_title'    => $title,
			'post_status'   => $status,
			'post_date'     => '2026-01-01 00:00:00',
			'post_date_gmt' => '2026-01-01 05:00:00',
		),
		$props
	);
}

/** Append one meta row (repeated keys keep every row). */
function sppa_add_meta( $id, $key, $value ) {
	$GLOBALS['sppa_meta'][ $id ][ $key ][] = $value;
}

/** Replace a key's rows with a single row. */
function sppa_set_meta( $id, $key, $value ) {
	$GLOBALS['sppa_meta'][ $id ][ $key ] = array( $value );
}

/** Register a term so get_term() finds it. */
function sppa_register_term( $term_id, $taxonomy, $name, $parent = 0 ) {
	$term = (object) array(
		'term_id' => (int) $term_id,
		'name'    => $name,
		'slug'    => strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $name ) ),
		'parent'  => (int) $parent,
	);
	$GLOBALS['sppa_term_store'][ $taxonomy ][ (int) $term_id ] = $term;
	return $term;
}

/**
 * Set a post's terms for a taxonomy from [ term_id => name ], registering each
 * term. $parents maps term_id => parent term id (default 0).
 */
function sppa_set_terms( $id, $taxonomy, array $terms, array $parents = array() ) {
	$out = array();
	foreach ( $terms as $term_id => $name ) {
		$out[] = sppa_register_term( $term_id, $taxonomy, $name, $parents[ $term_id ] ?? 0 );
	}
	$GLOBALS['sppa_terms'][ $id ][ $taxonomy ] = $out;
}

/** Local start time for an event fixture (site timezone). */
function sppa_event_at( $id, $local, $status = 'publish', array $team_ids = array() ) {
	$gmt = ( new DateTimeImmutable( $local, new DateTimeZone( 'America/Toronto' ) ) )
		->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	sppa_add_post( $id, 'sp_event', "Event {$id}", $status, array( 'post_date' => $local, 'post_date_gmt' => $gmt ) );
	foreach ( $team_ids as $team_id ) {
		sppa_add_meta( $id, 'sp_team', (string) $team_id );
	}
}

/* ------------------------------------------------------------------------
 * wpdb
 * --------------------------------------------------------------------- */

/**
 * Records every query. prepare() does real %d/%s substitution so tests can
 * assert on the final SQL. Rows for get_results()/get_var() come from the
 * $results_handler callable (receives the SQL, returns rows).
 */
class SPPA_Test_WPDB {
	public $prefix          = 'wp_';
	public $options         = 'wp_options';
	public $queries         = array();
	public $results_handler = null;
	public $query_return    = 1;
	public $posts           = 'wp_posts';
	public $postmeta        = 'wp_postmeta';
	public $term_relationships = 'wp_term_relationships';
	public $term_taxonomy   = 'wp_term_taxonomy';
	public $query_handler   = null; // callable( $sql ): affected rows, for stateful fakes.
	public $inserts         = array(); // [ table, data, formats ].
	public $insert_return   = 1;
	public $insert_id       = 0;
	public $insert_handler  = null; // callable( $table, $data, $default_id ): the new row's id.

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function esc_like( $text ) {
		return addcslashes( $text, '_%\\' );
	}

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			function ( $m ) use ( &$i, $args ) {
				$v = $args[ $i++ ];
				return '%d' === $m[0] ? (string) (int) $v : "'" . addslashes( (string) $v ) . "'";
			},
			$sql
		);
	}

	public function query( $sql ) {
		$this->queries[] = $sql;
		if ( $this->query_handler ) {
			return call_user_func( $this->query_handler, $sql );
		}
		return $this->query_return;
	}

	public function insert( $table, $data, $formats = null ) {
		$this->inserts[] = array( $table, $data, $formats );
		if ( false === $this->insert_return ) {
			return false;
		}
		$this->insert_id = count( $this->inserts );
		if ( $this->insert_handler ) {
			// A stateful fake may keep the row and choose its id, or refuse it (false, like a unique key).
			$id = call_user_func( $this->insert_handler, $table, $data, $this->insert_id );
			if ( false === $id ) {
				return false;
			}
			$this->insert_id = (int) $id;
		}
		return 1;
	}

	public function get_col( $sql ) {
		$this->queries[] = $sql;
		return $this->results_handler ? (array) call_user_func( $this->results_handler, $sql ) : array();
	}

	public function get_row( $sql, $output = OBJECT ) {
		$this->queries[] = $sql;
		$rows            = $this->results_handler ? (array) call_user_func( $this->results_handler, $sql ) : array();
		return $rows ? reset( $rows ) : null;
	}

	public function get_results( $sql, $output = OBJECT ) {
		$this->queries[] = $sql;
		return $this->results_handler ? (array) call_user_func( $this->results_handler, $sql ) : array();
	}

	public function get_var( $sql ) {
		$this->queries[] = $sql;
		return $this->results_handler ? call_user_func( $this->results_handler, $sql ) : null;
	}
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

/* ------------------------------------------------------------------------
 * Core classes
 * --------------------------------------------------------------------- */

class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Response {
	private $data;
	private $status;

	public function __construct( $data = null, $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}

/* ------------------------------------------------------------------------
 * Options, hooks, i18n, sanitising
 * --------------------------------------------------------------------- */

function get_option( $key, $default = false ) {
	return $GLOBALS['sppa_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['sppa_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	$had = array_key_exists( $key, $GLOBALS['sppa_options'] );
	unset( $GLOBALS['sppa_options'][ $key ] );
	return $had;
}

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['sppa_filters'][ $tag ][] = $callback;
	return true;
}

function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['sppa_actions'][ $tag ][] = $callback;
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['sppa_filters'][ $tag ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value, ...$args );
	}
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function _n( $single, $plural, $number, $domain = 'default' ) {
	return 1 === (int) $number ? $single : $plural;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function wp_strip_all_tags( $text ) {
	return trim( strip_tags( (string) $text ) );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) );
}

function sanitize_title( $title ) {
	return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( strip_tags( (string) $title ) ) ), '-' );
}

function absint( $value ) {
	return abs( (int) $value );
}

function wp_json_encode( $data, $flags = 0, $depth = 512 ) {
	return json_encode( $data, $flags, $depth );
}

/* ------------------------------------------------------------------------
 * Users
 * --------------------------------------------------------------------- */

function get_current_user_id() {
	return (int) $GLOBALS['sppa_user_id'];
}

function is_user_logged_in() {
	return $GLOBALS['sppa_user_id'] > 0;
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['sppa_user_caps'], true );
}

/* ------------------------------------------------------------------------
 * Posts, meta, terms
 * --------------------------------------------------------------------- */

function get_post( $id ) {
	return $GLOBALS['sppa_posts'][ (int) $id ] ?? null;
}

function get_post_status( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_status : false;
}

function get_post_meta( $id, $key = '', $single = false ) {
	$rows = $GLOBALS['sppa_meta'][ (int) $id ][ $key ] ?? array();
	if ( $single ) {
		return $rows[0] ?? '';
	}
	return $rows;
}

function get_the_post_thumbnail_url( $id, $size = 'post-thumbnail' ) {
	$url = $GLOBALS['sppa_meta'][ (int) $id ]['_test_thumbnail_url'][0] ?? '';
	return '' === $url ? false : $url;
}

function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
	$terms = $GLOBALS['sppa_terms'][ (int) $id ][ $taxonomy ] ?? array();
	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_map(
			function ( $t ) {
				return $t->term_id;
			},
			$terms
		);
	}
	return $terms;
}

/** Like core: the cached terms, or false when the post has none. */
function get_the_terms( $post, $taxonomy ) {
	$id    = is_object( $post ) ? (int) $post->ID : (int) $post;
	$terms = $GLOBALS['sppa_terms'][ $id ][ $taxonomy ] ?? array();
	return $terms ? $terms : false;
}

function get_term_meta( $term_id, $key = '', $single = false ) {
	return $GLOBALS['sppa_term_meta'][ (int) $term_id ][ $key ] ?? '';
}

function get_term( $term_id, $taxonomy = '' ) {
	return $GLOBALS['sppa_term_store'][ $taxonomy ][ (int) $term_id ] ?? null;
}

function wp_timezone() {
	return new DateTimeZone( get_option( 'timezone_string', 'UTC' ) );
}

function get_post_datetime( $post = null, $field = 'date', $source = 'local' ) {
	$p = is_object( $post ) ? $post : get_post( $post );
	if ( ! $p ) {
		return false;
	}
	if ( 'local' === $source ) {
		return new DateTimeImmutable( $p->post_date, wp_timezone() );
	}
	return new DateTimeImmutable( $p->post_date_gmt, new DateTimeZone( 'UTC' ) );
}

/* ------------------------------------------------------------------------
 * WP_Query / get_posts emulation
 *
 * Understands post_type, post_status, meta_query (= and IN on any row of a
 * repeated key), tax_query (term_id membership), date_query after/before on
 * one column, orderby date, posts_per_page
 * and fields=ids. Every set of args is logged to $GLOBALS['sppa_queries'] so a
 * test can assert on the query a reader built.
 * --------------------------------------------------------------------- */

function sppa_meta_clause_matches( $post_id, array $clause ) {
	$rows    = $GLOBALS['sppa_meta'][ $post_id ][ $clause['key'] ] ?? array();
	$compare = strtoupper( $clause['compare'] ?? '=' );
	if ( 'NOT EXISTS' === $compare ) {
		return array() === $rows;
	}
	if ( 'EXISTS' === $compare ) {
		return array() !== $rows;
	}
	$want = array_map( 'strval', (array) ( $clause['value'] ?? array() ) );
	foreach ( $rows as $row ) {
		if ( in_array( (string) $row, $want, true ) ) {
			return true;
		}
	}
	return false;
}

function sppa_tax_clause_matches( $post_id, array $clause ) {
	$want = array_map( 'intval', (array) $clause['terms'] );
	foreach ( $GLOBALS['sppa_terms'][ $post_id ][ $clause['taxonomy'] ] ?? array() as $term ) {
		if ( in_array( $term->term_id, $want, true ) ) {
			return true;
		}
	}
	return false;
}

function sppa_query_posts( array $args ) {
	$GLOBALS['sppa_queries'][] = $args;

	$types    = (array) ( $args['post_type'] ?? 'post' );
	$statuses = (array) ( $args['post_status'] ?? 'publish' );
	$matched  = array();

	foreach ( $GLOBALS['sppa_posts'] as $post ) {
		if ( ! in_array( $post->post_type, $types, true ) ) {
			continue;
		}
		if ( ! in_array( 'any', $statuses, true ) && ! in_array( $post->post_status, $statuses, true ) ) {
			continue;
		}
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && ! sppa_meta_clause_matches( $post->ID, $clause ) ) {
				continue 2;
			}
		}
		foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && ! sppa_tax_clause_matches( $post->ID, $clause ) ) {
				continue 2;
			}
		}
		foreach ( (array) ( $args['date_query'] ?? array() ) as $clause ) {
			if ( ! is_array( $clause ) ) {
				continue;
			}
			$when = $post->{ $clause['column'] ?? 'post_date' };
			if ( isset( $clause['after'] ) && strcmp( $when, $clause['after'] ) < 0 ) {
				continue 2;
			}
			if ( isset( $clause['before'] ) && strcmp( $when, $clause['before'] ) >= 0 ) {
				continue 2;
			}
		}
		$matched[] = $post;
	}

	if ( 'date' === ( $args['orderby'] ?? '' ) ) {
		$dir = strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ? 1 : -1;
		usort(
			$matched,
			function ( $a, $b ) use ( $dir ) {
				return $dir * strcmp( $a->post_date_gmt, $b->post_date_gmt );
			}
		);
	}

	$limit = (int) ( $args['posts_per_page'] ?? -1 );
	if ( $limit > 0 ) {
		$matched = array_slice( $matched, 0, $limit );
	}

	if ( 'ids' === ( $args['fields'] ?? '' ) ) {
		return array_map(
			function ( $p ) {
				return $p->ID;
			},
			$matched
		);
	}
	return $matched;
}

class WP_Query {
	public $posts       = array();
	public $post_count  = 0;
	public $found_posts = 0;

	public function __construct( $args = array() ) {
		$this->posts       = sppa_query_posts( (array) $args );
		$this->post_count  = count( $this->posts );
		$this->found_posts = $this->post_count;
	}
}

function get_posts( $args = array() ) {
	return sppa_query_posts( (array) $args );
}

/* ------------------------------------------------------------------------
 * Actions that fire, post meta writes, user meta, users, mail, lock
 * --------------------------------------------------------------------- */

function do_action( $tag, ...$args ) {
	$GLOBALS['sppa_fired'][] = array( $tag, $args );
	foreach ( $GLOBALS['sppa_actions'][ $tag ] ?? array() as $callback ) {
		call_user_func( $callback, ...$args );
	}
}

/** Every do_action() call for a tag, as [ tag, args ] entries. */
function sppa_fired( $tag ) {
	return array_values(
		array_filter(
			$GLOBALS['sppa_fired'],
			function ( $f ) use ( $tag ) {
				return $f[0] === $tag;
			}
		)
	);
}

function wp_slash( $value ) {
	return is_array( $value ) ? array_map( 'wp_slash', $value ) : ( is_string( $value ) ? addslashes( $value ) : $value );
}

function get_post_field( $field, $id ) {
	$post = get_post( $id );
	return $post && isset( $post->$field ) ? $post->$field : '';
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}

function get_post_type( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_type : false;
}

function wp_is_post_autosave( $id ) {
	return ! empty( $GLOBALS['sppa_meta'][ (int) $id ]['_test_autosave'] ) ? (int) $id : false;
}

function wp_is_post_revision( $id ) {
	$post = get_post( $id );
	return $post && 'revision' === $post->post_type ? 1 : false;
}

function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : ( is_string( $value ) ? stripslashes( $value ) : $value );
}

/** Like core, update_post_meta() unslashes what it is given. */
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['sppa_meta'][ (int) $id ][ $key ] = array( wp_unslash( $value ) );
	return true;
}

function update_meta_cache( $type, $ids ) {
	$GLOBALS['sppa_meta_cache_primed'][] = array( $type, array_values( (array) $ids ) );
	return array();
}

/** Core's post/meta/term cache primer; recorded so a suite can assert ids were primed in bulk. */
function _prime_post_caches( $ids, $update_term_cache = true, $update_meta_cache = true ) {
	$GLOBALS['sppa_posts_primed'][] = array_values( array_map( 'intval', (array) $ids ) );
}

function update_object_term_cache( $object_ids, $object_type ) {
	$GLOBALS['sppa_posts_primed'][] = array_values( array_map( 'intval', (array) $object_ids ) );
	return null;
}

/**
 * The one SportsPress-shaped SQL the suites emulate from fixtures: the season's
 * team ids (SELECT DISTINCT pm.meta_value ... sp_team ... sp_season term N),
 * counting publish/future events and drafts flagged _spem_cancelled. Returns
 * null for any other SQL, so a results handler can fall through.
 */
function sppa_fixture_season_team_ids( $sql ) {
	if ( false === strpos( $sql, 'SELECT DISTINCT pm.meta_value' ) || ! preg_match( "/tt.term_id = (\d+)/", $sql, $m ) ) {
		return null;
	}
	$out = array();
	foreach ( $GLOBALS['sppa_posts'] as $post ) {
		if ( 'sp_event' !== $post->post_type || ! sppa_tax_clause_matches( $post->ID, array( 'taxonomy' => 'sp_season', 'terms' => array( (int) $m[1] ) ) ) ) {
			continue;
		}
		$cancelled = '1' === (string) ( $GLOBALS['sppa_meta'][ $post->ID ]['_spem_cancelled'][0] ?? '' );
		if ( ! in_array( $post->post_status, array( 'publish', 'future' ), true ) && ! ( 'draft' === $post->post_status && $cancelled ) ) {
			continue;
		}
		foreach ( $GLOBALS['sppa_meta'][ $post->ID ]['sp_team'] ?? array() as $team ) {
			$out[ (string) $team ] = (string) $team;
		}
	}
	return array_values( $out );
}

/* WP-Cron: scheduled events by hook. */
function wp_next_scheduled( $hook, $args = array() ) {
	return $GLOBALS['sppa_cron'][ $hook ]['time'] ?? false;
}

function wp_schedule_event( $timestamp, $recurrence, $hook, $args = array() ) {
	$GLOBALS['sppa_cron'][ $hook ] = array(
		'time'       => (int) $timestamp,
		'recurrence' => $recurrence,
	);
	return true;
}

function wp_clear_scheduled_hook( $hook, $args = array() ) {
	$had = isset( $GLOBALS['sppa_cron'][ $hook ] ) ? 1 : 0;
	unset( $GLOBALS['sppa_cron'][ $hook ] );
	return $had;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	$value = $GLOBALS['sppa_user_meta'][ (int) $user_id ][ $key ] ?? '';
	return $single ? $value : ( '' === $value ? array() : array( $value ) );
}

function update_user_meta( $user_id, $key, $value ) {
	$GLOBALS['sppa_user_meta'][ (int) $user_id ][ $key ] = $value;
	return true;
}

function delete_user_meta( $user_id, $key ) {
	unset( $GLOBALS['sppa_user_meta'][ (int) $user_id ][ $key ] );
	return true;
}

/** Register a user fixture. */
function sppa_add_user( $id, $email = '', $name = '' ) {
	$GLOBALS['sppa_users'][ $id ] = (object) array(
		'ID'           => $id,
		'user_email'   => '' === $email ? "user{$id}@example.test" : $email,
		'display_name' => '' === $name ? "User {$id}" : $name,
	);
}

function get_userdata( $id ) {
	return $GLOBALS['sppa_users'][ (int) $id ] ?? false;
}

/**
 * get_users() emulation: include, meta_key/meta_value (exact), meta_query LIKE
 * clauses (substring of the serialised value), number, fields 'ID' or objects.
 */
function get_users( $args = array() ) {
	$GLOBALS['sppa_user_queries'][] = $args;
	$out                            = array();
	foreach ( $GLOBALS['sppa_users'] as $id => $user ) {
		if ( isset( $args['include'] ) && ! in_array( $id, array_map( 'intval', (array) $args['include'] ), true ) ) {
			continue;
		}
		if ( isset( $args['meta_key'] ) ) {
			$have = $GLOBALS['sppa_user_meta'][ $id ][ $args['meta_key'] ] ?? null;
			// Like MySQL's default collation, the comparison ignores case.
			if ( null === $have || 0 !== strcasecmp( (string) $have, (string) $args['meta_value'] ) ) {
				continue;
			}
		}
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
			if ( ! is_array( $clause ) || ! isset( $clause['key'] ) ) {
				continue;
			}
			$have = $GLOBALS['sppa_user_meta'][ $id ][ $clause['key'] ] ?? null;
			if ( null === $have ) {
				continue 2;
			}
			$have = is_array( $have ) ? serialize( $have ) : (string) $have;
			if ( 'LIKE' === ( $clause['compare'] ?? '=' ) ) {
				if ( false === strpos( $have, (string) $clause['value'] ) ) {
					continue 2;
				}
			} elseif ( $have !== (string) $clause['value'] ) {
				continue 2;
			}
		}
		$out[] = $user;
	}
	$number = (int) ( $args['number'] ?? -1 );
	if ( $number > 0 ) {
		$out = array_slice( $out, 0, $number );
	}
	if ( 'ID' === ( $args['fields'] ?? 'all' ) ) {
		return array_map(
			function ( $u ) {
				return $u->ID;
			},
			$out
		);
	}
	return $out;
}

function wp_generate_password( $length = 12, $special_chars = true, $extra_special_chars = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) {
		$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
	}
	return $out;
}

function home_url( $path = '' ) {
	return 'https://arl.example.test' . $path;
}

function is_email( $email ) {
	return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
}

function sanitize_email( $email ) {
	return trim( (string) $email );
}

function wp_mail( $to, $subject, $message, $headers = '' ) {
	$GLOBALS['sppa_mail'][] = array(
		'to'      => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);
	return $GLOBALS['sppa_mail_ok'];
}

/* Roles: add_role / get_role / remove_role over $GLOBALS['sppa_roles']. */
class SPPA_Test_Role {
	public $name;
	public $display_name;
	public $capabilities;

	public function __construct( $name, $display_name, array $capabilities ) {
		$this->name         = $name;
		$this->display_name = $display_name;
		$this->capabilities = $capabilities;
	}

	public function add_cap( $cap, $grant = true ) {
		$this->capabilities[ $cap ] = $grant;
	}
}

function get_role( $role ) {
	return $GLOBALS['sppa_roles'][ $role ] ?? null;
}

function add_role( $role, $display_name, $capabilities = array() ) {
	if ( isset( $GLOBALS['sppa_roles'][ $role ] ) ) {
		return null; // Like core: an existing role is not replaced.
	}
	$GLOBALS['sppa_roles'][ $role ] = new SPPA_Test_Role( $role, $display_name, (array) $capabilities );
	return $GLOBALS['sppa_roles'][ $role ];
}

function remove_role( $role ) {
	unset( $GLOBALS['sppa_roles'][ $role ] );
}

/* ------------------------------------------------------------------------
 * URLs, escaping and transients (app shell and REST)
 * --------------------------------------------------------------------- */

function esc_attr( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_url( $url ) {
	return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' );
}

function esc_url_raw( $url ) {
	return (string) $url;
}

function wp_parse_url( $url, $component = -1 ) {
	return parse_url( $url, $component );
}

function get_transient( $key ) {
	$GLOBALS['sppa_transient_reads'][] = $key;
	return $GLOBALS['sppa_transients'][ $key ] ?? false;
}

function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['sppa_transient_writes'][] = array( $key, $ttl );
	$GLOBALS['sppa_transients'][ $key ] = $value;
	return true;
}

/**
 * SPAT_Lock stand-in with the real contract: with() returns the callback's
 * value, or false when the lock is already held. Tests flip
 * $GLOBALS['sppa_lock_held'], or set sppa_lock_race to run code (a competing
 * request) after the lock is taken and before the callback.
 */
if ( ! class_exists( 'SPAT_Lock' ) ) {
	class SPAT_Lock {
		public static function with( $key, $ttl_seconds, callable $callback ) {
			$GLOBALS['sppa_lock_calls'][] = $key;
			$GLOBALS['sppa_lock_ttls'][]  = $ttl_seconds;
			if ( $GLOBALS['sppa_lock_held'] ) {
				return false;
			}
			if ( is_callable( $GLOBALS['sppa_lock_race'] ) ) {
				call_user_func( $GLOBALS['sppa_lock_race'] );
			}
			return $callback();
		}
	}
}

sppa_reset();
