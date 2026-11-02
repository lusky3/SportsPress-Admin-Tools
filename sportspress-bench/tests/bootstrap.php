<?php
/**
 * Shared WordPress stubs for the Bench standalone test suites.
 *
 * Usage (from a suite): require __DIR__ . '/bootstrap.php';
 *
 * State lives in $GLOBALS['spbn_*']; call spbn_reset() at the top of a test
 * block to start from a clean slate. Fixture helpers (spbn_add_post() and
 * friends) populate it; the stubbed WP functions read from it.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

/* ------------------------------------------------------------------------
 * Assertions
 * --------------------------------------------------------------------- */

$GLOBALS['spbn_passed'] = 0;
$GLOBALS['spbn_failed'] = 0;

function spbn_assert( $cond, $label ) {
	if ( $cond ) {
		++$GLOBALS['spbn_passed'];
		echo "PASS: {$label}\n";
	} else {
		++$GLOBALS['spbn_failed'];
		echo "FAIL: {$label}\n";
	}
}

/** Print the summary and exit non-zero when anything failed. */
function spbn_done() {
	echo "\nPassed: {$GLOBALS['spbn_passed']}  Failed: {$GLOBALS['spbn_failed']}\n";
	exit( $GLOBALS['spbn_failed'] > 0 ? 1 : 0 );
}

/* ------------------------------------------------------------------------
 * State
 * --------------------------------------------------------------------- */

function spbn_reset() {
	$GLOBALS['spbn_options']    = array( 'timezone_string' => 'America/Toronto' );
	$GLOBALS['spbn_posts']      = array(); // id => object.
	$GLOBALS['spbn_meta']       = array(); // id => key => list of row values.
	$GLOBALS['spbn_terms']      = array(); // id => taxonomy => list of term objects.
	$GLOBALS['spbn_term_meta']  = array(); // term_id => key => value.
	$GLOBALS['spbn_queries']    = array(); // WP_Query args, in call order.
	$GLOBALS['spbn_filters']    = array(); // tag => list of callbacks.
	$GLOBALS['spbn_actions']    = array(); // tag => list of callbacks.
	$GLOBALS['spbn_user_id']    = 0;
	$GLOBALS['spbn_user_caps']  = array();
	$GLOBALS['wpdb']            = new SPBN_Test_WPDB();
}

/** Add a post fixture. $props may override post_date / post_date_gmt. */
function spbn_add_post( $id, $type, $title = '', $status = 'publish', array $props = array() ) {
	$GLOBALS['spbn_posts'][ $id ] = (object) array_merge(
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
function spbn_add_meta( $id, $key, $value ) {
	$GLOBALS['spbn_meta'][ $id ][ $key ][] = $value;
}

/** Replace a key's rows with a single row. */
function spbn_set_meta( $id, $key, $value ) {
	$GLOBALS['spbn_meta'][ $id ][ $key ] = array( $value );
}

/** Set a post's terms for a taxonomy from [ term_id => name ]. */
function spbn_set_terms( $id, $taxonomy, array $terms ) {
	$out = array();
	foreach ( $terms as $term_id => $name ) {
		$out[] = (object) array(
			'term_id' => (int) $term_id,
			'name'    => $name,
			'slug'    => strtolower( preg_replace( '/[^A-Za-z0-9]+/', '-', $name ) ),
		);
	}
	$GLOBALS['spbn_terms'][ $id ][ $taxonomy ] = $out;
}

/** Local start time for an event fixture (site timezone). */
function spbn_event_at( $id, $local, $status = 'publish', array $team_ids = array() ) {
	$gmt = ( new DateTimeImmutable( $local, new DateTimeZone( 'America/Toronto' ) ) )
		->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	spbn_add_post( $id, 'sp_event', "Event {$id}", $status, array( 'post_date' => $local, 'post_date_gmt' => $gmt ) );
	foreach ( $team_ids as $team_id ) {
		spbn_add_meta( $id, 'sp_team', (string) $team_id );
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
class SPBN_Test_WPDB {
	public $prefix          = 'wp_';
	public $options         = 'wp_options';
	public $queries         = array();
	public $results_handler = null;
	public $query_return    = 1;

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
		return $this->query_return;
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
	return $GLOBALS['spbn_options'][ $key ] ?? $default;
}

function update_option( $key, $value ) {
	$GLOBALS['spbn_options'][ $key ] = $value;
	return true;
}

function add_filter( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['spbn_filters'][ $tag ][] = $callback;
	return true;
}

function add_action( $tag, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['spbn_actions'][ $tag ][] = $callback;
	return true;
}

function apply_filters( $tag, $value, ...$args ) {
	foreach ( $GLOBALS['spbn_filters'][ $tag ] ?? array() as $callback ) {
		$value = call_user_func( $callback, $value, ...$args );
	}
	return $value;
}

function __( $text, $domain = 'default' ) {
	return $text;
}

function esc_html( $text ) {
	return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
}

function esc_html__( $text, $domain = 'default' ) {
	return esc_html( $text );
}

function sanitize_text_field( $text ) {
	return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $text ) ) );
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
	return (int) $GLOBALS['spbn_user_id'];
}

function is_user_logged_in() {
	return $GLOBALS['spbn_user_id'] > 0;
}

function current_user_can( $cap ) {
	return in_array( $cap, $GLOBALS['spbn_user_caps'], true );
}

/* ------------------------------------------------------------------------
 * Posts, meta, terms
 * --------------------------------------------------------------------- */

function get_post( $id ) {
	return $GLOBALS['spbn_posts'][ (int) $id ] ?? null;
}

function get_post_status( $id ) {
	$post = get_post( $id );
	return $post ? $post->post_status : false;
}

function get_post_meta( $id, $key = '', $single = false ) {
	$rows = $GLOBALS['spbn_meta'][ (int) $id ][ $key ] ?? array();
	if ( $single ) {
		return $rows[0] ?? '';
	}
	return $rows;
}

function get_the_post_thumbnail_url( $id, $size = 'post-thumbnail' ) {
	$url = $GLOBALS['spbn_meta'][ (int) $id ]['_test_thumbnail_url'][0] ?? '';
	return '' === $url ? false : $url;
}

function wp_get_post_terms( $id, $taxonomy, $args = array() ) {
	$terms = $GLOBALS['spbn_terms'][ (int) $id ][ $taxonomy ] ?? array();
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

function get_term_meta( $term_id, $key = '', $single = false ) {
	return $GLOBALS['spbn_term_meta'][ (int) $term_id ][ $key ] ?? '';
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
 * repeated key), tax_query (term_id membership), orderby date, posts_per_page
 * and fields=ids. Every set of args is logged to $GLOBALS['spbn_queries'] so a
 * test can assert on the query a reader built.
 * --------------------------------------------------------------------- */

function spbn_meta_clause_matches( $post_id, array $clause ) {
	$rows = $GLOBALS['spbn_meta'][ $post_id ][ $clause['key'] ] ?? array();
	$want = array_map( 'strval', (array) ( $clause['value'] ?? array() ) );
	foreach ( $rows as $row ) {
		if ( in_array( (string) $row, $want, true ) ) {
			return true;
		}
	}
	return false;
}

function spbn_tax_clause_matches( $post_id, array $clause ) {
	$want = array_map( 'intval', (array) $clause['terms'] );
	foreach ( $GLOBALS['spbn_terms'][ $post_id ][ $clause['taxonomy'] ] ?? array() as $term ) {
		if ( in_array( $term->term_id, $want, true ) ) {
			return true;
		}
	}
	return false;
}

function spbn_query_posts( array $args ) {
	$GLOBALS['spbn_queries'][] = $args;

	$types    = (array) ( $args['post_type'] ?? 'post' );
	$statuses = (array) ( $args['post_status'] ?? 'publish' );
	$matched  = array();

	foreach ( $GLOBALS['spbn_posts'] as $post ) {
		if ( ! in_array( $post->post_type, $types, true ) ) {
			continue;
		}
		if ( ! in_array( 'any', $statuses, true ) && ! in_array( $post->post_status, $statuses, true ) ) {
			continue;
		}
		foreach ( (array) ( $args['meta_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && ! spbn_meta_clause_matches( $post->ID, $clause ) ) {
				continue 2;
			}
		}
		foreach ( (array) ( $args['tax_query'] ?? array() ) as $clause ) {
			if ( is_array( $clause ) && ! spbn_tax_clause_matches( $post->ID, $clause ) ) {
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
		$this->posts       = spbn_query_posts( (array) $args );
		$this->post_count  = count( $this->posts );
		$this->found_posts = $this->post_count;
	}
}

function get_posts( $args = array() ) {
	return spbn_query_posts( (array) $args );
}

spbn_reset();
