<?php
/**
 * Change tracking: notice when a game is moved, re-ventued, re-teamed,
 * postponed or cancelled, and keep a row for each.
 *
 * A fingerprint of the fields a player cares about is stored on the event
 * (`_spbn_fingerprint`). Whenever the event is saved, or one of the meta keys
 * that feed the fingerprint is written, the fingerprint is recomputed; a row
 * is logged only when a stored, non-empty fingerprint actually differs and the
 * game is still ahead. The first save of an event never logs.
 *
 * fingerprint() and should_log() are pure and unit-tested; on_save() and the
 * repository methods are thin WordPress wrappers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Changes {

	const FINGERPRINT_META = '_spbn_fingerprint';

	/** The fingerprint's fields, kept so a change row can show "before". */
	const SNAPSHOT_META = '_spbn_snapshot';

	/** Option bumped on every logged change; feeds cache keys. */
	const GENERATION_OPTION = 'spbn_generation';

	/** Meta keys whose writes can change a fingerprint. */
	const WATCHED_META = array( '_spem_cancelled', 'sp_status', 'sp_team' );

	/**
	 * Posts being tracked right now (a listener that saves the event again must
	 * not re-enter).
	 *
	 * @var array<int,bool>
	 */
	private static array $running = array();

	/**
	 * Events with a watched meta write waiting for the end of the request.
	 *
	 * @var array<int,bool>
	 */
	private static array $pending = array();

	/**
	 * Wire the hooks. Meta writes are queued rather than handled on the spot:
	 * SportsPress rewrites sp_team one row at a time (delete, then add each), so
	 * checking after every row would log the half-written states in between.
	 */
	public static function register(): void {
		add_action( 'save_post_sp_event', array( __CLASS__, 'on_save' ), 99, 1 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ) );
	}

	/**
	 * The fields a player cares about, normalised: start, venue name, sorted
	 * team ids and the called-off-aware status.
	 *
	 * @param array $raw Raw event from SPBN_Roster_Reader::raw_event().
	 * @return array{start:string,venue:string,teams:int[],status:string}
	 */
	public static function snapshot( array $raw ): array {
		$teams = array_values( array_unique( array_map( 'intval', (array) ( $raw['team_ids'] ?? array() ) ) ) );
		sort( $teams );
		$venue = (array) ( $raw['venue'] ?? array() );

		return array(
			'start'  => (string) ( $raw['start'] ?? '' ),
			'venue'  => (string) ( $venue['name'] ?? '' ),
			'teams'  => $teams,
			'status' => SPBN_Event_Presenter::present( $raw, 0 )['status'],
		);
	}

	/**
	 * md5 of start | venue name | sorted team ids | normalised status. The
	 * status folds `_spem_cancelled` in (it reads as cancelled), so events-
	 * manager's cancel path changes the fingerprint without touching sp_status.
	 */
	public static function fingerprint( array $raw ): string {
		$snap = self::snapshot( $raw );
		return md5( implode( '|', array( $snap['start'], $snap['venue'], implode( ',', $snap['teams'] ), $snap['status'] ) ) );
	}

	/**
	 * Whether a fingerprint difference is worth a change row: there was a stored
	 * fingerprint (the first save is a baseline), it differs, and the game has
	 * not started.
	 */
	public static function should_log( string $stored, string $new, int $start_ts, int $now_ts ): bool {
		return '' !== $stored && $stored !== $new && $start_ts > $now_ts;
	}

	/**
	 * Meta hook: queue the event for a check at shutdown when the key is one
	 * that feeds the fingerprint.
	 *
	 * @param int|int[] $meta_id   Meta id (an array on delete). Unused.
	 * @param int       $object_id Post id.
	 * @param string    $meta_key  Meta key.
	 */
	public static function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( ! in_array( $meta_key, self::WATCHED_META, true ) ) {
			return;
		}
		$post_id = (int) $object_id;
		if ( $post_id > 0 && 'sp_event' === get_post_type( $post_id ) ) {
			self::$pending[ $post_id ] = true;
		}
	}

	/**
	 * Check every queued event once. Idempotent with on_save(): the fingerprint
	 * has already been brought up to date if a save got there first.
	 */
	public static function flush_pending(): void {
		$ids           = array_keys( self::$pending );
		self::$pending = array();
		foreach ( $ids as $post_id ) {
			self::on_save( (int) $post_id );
		}
	}

	/**
	 * Recompute an event's fingerprint and log the change, if there is one.
	 *
	 * Fires `spbn_event_changed( $post_id, $before, $after )` after a row is
	 * logged; both arguments are {start,venue,teams,status}.
	 */
	public static function on_save( int $post_id ): void {
		if ( isset( self::$running[ $post_id ] ) ) {
			return;
		}
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'sp_event' !== get_post_type( $post_id ) ) {
			return;
		}

		self::$running[ $post_id ] = true;
		try {
			self::track( $post_id );
		} finally {
			unset( self::$running[ $post_id ] );
		}
	}

	/**
	 * Event ids, from the given set, that changed within the last N days.
	 *
	 * @param int[] $event_ids Event ids.
	 * @return int[]
	 */
	public static function recent_event_ids( array $event_ids, int $days = 14 ): array {
		global $wpdb;

		$event_ids = array_values( array_unique( array_filter( array_map( 'intval', $event_ids ) ) ) );
		if ( ! $event_ids ) {
			return array();
		}
		$table        = SPBN_Schema::tables()['changes'];
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * 86400 );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT event_id FROM {$table} WHERE event_id IN ({$placeholders}) AND changed_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders
				array_merge( $event_ids, array( $cutoff ) )
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many change rows an event has (the calendar feed's SEQUENCE).
	 */
	public static function count_for( int $event_id ): int {
		global $wpdb;
		$table = SPBN_Schema::tables()['changes'];

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id )
		);
	}

	private static function track( int $post_id ): void {
		$raw = ( new SPBN_Roster_Reader() )->raw_event( $post_id );
		if ( null === $raw ) {
			return; // Trashed, or an unfinished draft: nothing a player sees.
		}

		$after  = self::snapshot( $raw );
		$new    = self::fingerprint( $raw );
		$stored = (string) get_post_meta( $post_id, self::FINGERPRINT_META, true );
		if ( $stored === $new ) {
			return;
		}

		$when     = '' !== $after['start'] ? strtotime( $after['start'] ) : false;
		$start_ts = false === $when ? 0 : (int) $when;

		if ( ! self::should_log( $stored, $new, $start_ts, time() ) ) {
			self::store( $post_id, $new, $after );
			return;
		}

		$before = self::stored_snapshot( $post_id );
		if ( ! self::insert_row( $post_id, $before, $after ) ) {
			return; // Keep the old fingerprint so the next save tries again.
		}

		self::store( $post_id, $new, $after );
		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 0 ) + 1 );

		do_action( 'spbn_event_changed', $post_id, $before, $after );
	}

	private static function store( int $post_id, string $fingerprint, array $snapshot ): void {
		update_post_meta( $post_id, self::FINGERPRINT_META, $fingerprint );
		update_post_meta( $post_id, self::SNAPSHOT_META, wp_slash( $snapshot ) );
	}

	/**
	 * The snapshot saved with the stored fingerprint; blank fields when an event
	 * was fingerprinted before snapshots existed.
	 *
	 * @return array{start:string,venue:string,teams:int[],status:string}
	 */
	private static function stored_snapshot( int $post_id ): array {
		$saved = get_post_meta( $post_id, self::SNAPSHOT_META, true );
		$saved = is_array( $saved ) ? $saved : array();

		return array(
			'start'  => (string) ( $saved['start'] ?? '' ),
			'venue'  => (string) ( $saved['venue'] ?? '' ),
			'teams'  => array_values( array_map( 'intval', (array) ( $saved['teams'] ?? array() ) ) ),
			'status' => (string) ( $saved['status'] ?? '' ),
		);
	}

	private static function insert_row( int $post_id, array $before, array $after ): bool {
		global $wpdb;

		$inserted = $wpdb->insert(
			SPBN_Schema::tables()['changes'],
			array(
				'event_id'    => $post_id,
				'changed_at'  => gmdate( 'Y-m-d H:i:s' ),
				'before_json' => wp_json_encode( $before ),
				'after_json'  => wp_json_encode( $after ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
		if ( false === $inserted ) {
			if ( class_exists( 'SPAT_Logger' ) ) {
				SPAT_Logger::error( 'bench', 'Change log write failed for event ' . $post_id );
			}
			return false;
		}
		return true;
	}
}
