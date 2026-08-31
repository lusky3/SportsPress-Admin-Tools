<?php
/**
 * Standalone tests for SPSS_Ingest_Service::accept_image()'s handling of a
 * hash match against an existing row — specifically the retry-a-failed-row
 * fix: a FAILED row (one that accomplished nothing, e.g. a storage error)
 * must NOT permanently block resubmission of the identical image the way a
 * genuine duplicate (queued/processing/pending_review/confirmed) does.
 *
 * Usage: php test-ingest-retry-failed.php
 *
 * No WordPress, no database — we load the REAL class-ingest-service.php
 * against stubbed SPSS_Database / SPSS_Image_Store test doubles (in-memory
 * table + a controllable store_from_path()), so accept_image()'s actual
 * branching runs. Harness state lives on a plain object behind a
 * function-static accessor (never $GLOBALS, never a static-property
 * subscript) — the pattern settled on in test-cf-access.php /
 * test-provider-diagnostics.php after both tripped Codacy/PHPMD findings.
 */

define( 'ABSPATH', dirname( __FILE__ ) . '/' );

/** In-memory harness state: the fake sheets table, image-store behaviour, and scheduling calls. */
class SPSS_Retry_Test_State {
	public $rows              = array(); // id => stdClass row
	public $next_id           = 1;
	public $store_should_fail = false;
	public $store_error       = 'Unable to create directory /var/www/html/wp-content/uploads/spss-sheets. Is its parent directory writable by the server?';
	public $scheduled         = array(); // sheet ids passed to wp_schedule_single_event
	public $deleted_paths     = array();
}

/** Single harness-state instance (function-static object avoids $GLOBALS). */
function spss_retry_state() {
	static $state = null;
	if ( null === $state ) {
		$state = new SPSS_Retry_Test_State();
	}
	return $state;
}

// ── WP_Error / is_wp_error ───────────────────────────────────────────────────
class WP_Error {
	public $code;
	public $message;
	public $data;
	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data; }
	public function get_error_message() { return $this->message; }
	public function get_error_code() { return $this->code; }
	public function get_error_data() { return $this->data; }
	public function add_data( $data ) { $this->data = $data; }
}
function is_wp_error( $t ) { return $t instanceof WP_Error; }

// ── Misc WP shims used by accept_image()/retry_failed_row() ─────────────────
function __( $text ) { return $text; }
function get_current_user_id() { return 9; }
function wp_schedule_single_event( $timestamp, $hook, $args ) {
	spss_retry_state()->scheduled[] = $args[0];
	return true;
}
function spawn_cron() {}
function wp_rand( $min = 0, $max = PHP_INT_MAX ) { return mt_rand( $min, $max ); }

// ── Test doubles for the two collaborators accept_image() calls ─────────────
class SPSS_Database {
	const STATUS_QUEUED         = 'queued';
	const STATUS_PROCESSING     = 'processing';
	const STATUS_PENDING_REVIEW = 'pending_review';
	const STATUS_CONFIRMED      = 'confirmed';
	const STATUS_FAILED         = 'failed';
	const STATUS_DUPLICATE      = 'duplicate';

	public static function find_by_hash( $hash ) {
		foreach ( spss_retry_state()->rows as $row ) {
			if ( $row->image_hash === $hash ) {
				return $row;
			}
		}
		return null;
	}

	public static function insert_sheet( array $data ) {
		$state = spss_retry_state();
		$id    = $state->next_id++;
		$row   = (object) array_merge(
			array(
				'id'          => $id,
				'uploaded_by' => 0,
				'channel'     => 'upload',
				'image_path'  => '',
				'image_hash'  => '',
				'source_ref'  => null,
				'event_id'    => null,
				'status'      => self::STATUS_QUEUED,
				'error'       => '',
			),
			$data
		);
		$state->rows[ $id ] = $row;
		return $id;
	}

	public static function update_sheet( $id, array $fields ) {
		$state = spss_retry_state();
		if ( ! isset( $state->rows[ $id ] ) ) {
			return false;
		}
		foreach ( $fields as $k => $v ) {
			$state->rows[ $id ]->$k = $v;
		}
		return 1;
	}
}

class SPSS_Image_Store {
	public static function store_from_path( $tmp, $ext = 'jpg' ) {
		$state = spss_retry_state();
		if ( $state->store_should_fail ) {
			return new WP_Error( 'spss_image_unreadable', $state->store_error );
		}
		return 'spss-sheets/sheet-' . substr( md5( $tmp . microtime() ), 0, 12 ) . '.' . $ext;
	}
	public static function delete( $relative ) {
		spss_retry_state()->deleted_paths[] = $relative;
		return true;
	}
}

require_once dirname( __FILE__ ) . '/../includes/class-ingest-service.php';

// ── Assertions ───────────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;
function check( $label, $cond ) {
	global $pass, $fail;
	if ( $cond ) {
		++$pass;
		echo "  ✓ PASS: $label\n";
	} else {
		++$fail;
		echo "  ✗ FAIL: $label\n";
	}
}

// A real temp file so file_get_contents()/hash() in accept_image() have real bytes.
$tmp_a = tempnam( sys_get_temp_dir(), 'spss_retry_a_' );
file_put_contents( $tmp_a, 'sheet-bytes-AAA' );
$hash_a = hash( 'sha256', 'sheet-bytes-AAA' );

echo "=== Baseline: a brand-new hash inserts a fresh queued row ===\n";
$state = spss_retry_state();
$r1    = SPSS_Ingest_Service::accept_image( array( 'tmp_path' => $tmp_a, 'channel' => 'upload' ) );
check( 'accept_image() returns a new int id', is_int( $r1 ) );
check( 'exactly one row exists', 1 === count( $state->rows ) );
check( 'new row is queued', SPSS_Database::STATUS_QUEUED === $state->rows[ $r1 ]->status );
check( 'processing was scheduled for the new id', in_array( $r1, $state->scheduled, true ) );

echo "=== Resubmitting the SAME bytes while the row is still 'queued' -> genuine duplicate ===\n";
$tmp_a2 = tempnam( sys_get_temp_dir(), 'spss_retry_a2_' );
file_put_contents( $tmp_a2, 'sheet-bytes-AAA' );
$before_count = count( $state->rows );
$r2           = SPSS_Ingest_Service::accept_image( array( 'tmp_path' => $tmp_a2, 'channel' => 'upload' ) );
check( 'returns a WP_Error', is_wp_error( $r2 ) );
check( 'error code is spss_duplicate_sheet', is_wp_error( $r2 ) && 'spss_duplicate_sheet' === $r2->get_error_code() );
check( 'a NEW audit-only row was added (duplicate, not an update)', count( $state->rows ) === $before_count + 1 );
$dup_row = end( $state->rows );
check( 'the audit row is marked duplicate', SPSS_Database::STATUS_DUPLICATE === $dup_row->status );
check( 'the original row (id ' . $r1 . ') is untouched (still queued)', SPSS_Database::STATUS_QUEUED === $state->rows[ $r1 ]->status );
unlink( $tmp_a2 );

echo "=== A FAILED row's hash: resubmission retries the SAME row, not a duplicate ===\n";
$tmp_b = tempnam( sys_get_temp_dir(), 'spss_retry_b_' );
file_put_contents( $tmp_b, 'sheet-bytes-BBB' );
$hash_b = hash( 'sha256', 'sheet-bytes-BBB' );

// Seed a failed row exactly like the real storage-permissions bug produced:
// image_path empty, error set, hash recorded so a re-delivery collapses onto it.
$failed_id = SPSS_Database::insert_sheet(
	array(
		'channel'    => 'upload',
		'image_path' => '',
		'image_hash' => $hash_b,
		'status'     => SPSS_Database::STATUS_FAILED,
		'error'      => 'Unable to create directory /var/www/html/wp-content/uploads/spss-sheets. Is its parent directory writable by the server?',
	)
);
$row_count_before_retry = count( $state->rows );

// The underlying cause is now fixed — store_from_path() succeeds this time.
$state->store_should_fail = false;
$tmp_b2                   = tempnam( sys_get_temp_dir(), 'spss_retry_b2_' );
file_put_contents( $tmp_b2, 'sheet-bytes-BBB' );
$r3 = SPSS_Ingest_Service::accept_image( array( 'tmp_path' => $tmp_b2, 'channel' => 'upload', 'event_id' => 42 ) );

check( 'accept_image() returns the EXISTING failed row\'s id (not a WP_Error, not a new id)', $failed_id === $r3 );
check( 'no new row was inserted — still the same row count', count( $state->rows ) === $row_count_before_retry );
check( 'the row is now queued', SPSS_Database::STATUS_QUEUED === $state->rows[ $failed_id ]->status );
check( 'the row now has a stored image_path', '' !== $state->rows[ $failed_id ]->image_path );
check( 'the stored error was cleared', '' === $state->rows[ $failed_id ]->error );
check( 'event_id was updated to the value this attempt supplied', 42 === $state->rows[ $failed_id ]->event_id );
check( 'processing was scheduled for the EXISTING id', in_array( $failed_id, $state->scheduled, true ) );
unlink( $tmp_b2 );

echo "=== A FAILED row's hash: resubmission that STILL fails updates the same row, no pile-up ===\n";
$tmp_c = tempnam( sys_get_temp_dir(), 'spss_retry_c_' );
file_put_contents( $tmp_c, 'sheet-bytes-CCC' );
$hash_c = hash( 'sha256', 'sheet-bytes-CCC' );
$still_failing_id = SPSS_Database::insert_sheet(
	array(
		'channel'    => 'upload',
		'image_path' => '',
		'image_hash' => $hash_c,
		'status'     => SPSS_Database::STATUS_FAILED,
		'error'      => 'some earlier error',
	)
);
$row_count_before = count( $state->rows );

$state->store_should_fail = true;
$state->store_error       = 'A different error this time.';
$tmp_c2                   = tempnam( sys_get_temp_dir(), 'spss_retry_c2_' );
file_put_contents( $tmp_c2, 'sheet-bytes-CCC' );
$r4 = SPSS_Ingest_Service::accept_image( array( 'tmp_path' => $tmp_c2, 'channel' => 'upload' ) );

check( 'returns a WP_Error (still broken)', is_wp_error( $r4 ) );
check( 'error code is NOT spss_duplicate_sheet (this is a retry, not a duplicate)', is_wp_error( $r4 ) && 'spss_duplicate_sheet' !== $r4->get_error_code() );
check( 'no new row was inserted — one row per hash, even across repeat failures', count( $state->rows ) === $row_count_before );
check( 'the existing row\'s error was refreshed to the latest message', 'A different error this time.' === $state->rows[ $still_failing_id ]->error );
check( 'the row is still marked failed', SPSS_Database::STATUS_FAILED === $state->rows[ $still_failing_id ]->status );
check( 'nothing was scheduled for this id (still not queued)', ! in_array( $still_failing_id, $state->scheduled, true ) );
unlink( $tmp_c2 );

unlink( $tmp_a );
unlink( $tmp_b );
unlink( $tmp_c );

echo "\n=== Results ===\nPassed: $pass\nFailed: $fail\n";
exit( $fail > 0 ? 1 : 0 );
