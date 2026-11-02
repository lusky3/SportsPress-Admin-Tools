<?php
/**
 * Change tracking: notice when a game is moved, re-ventued, re-teamed,
 * postponed or cancelled, and keep a row for each.
 *
 * A fingerprint of the fields a player cares about is stored on the event
 * (`_sppa_fingerprint`). Whenever the event is saved, or one of the meta keys
 * that feed the fingerprint is written, the fingerprint is recomputed; a row
 * is logged only when a stored, non-empty fingerprint actually differs and the
 * game is still ahead. The first save of an event never logs.
 *
 * Games that existed before the module was enabled are baselined (fingerprint
 * stored, nothing logged) by baseline_upcoming(), run on activation, on enable
 * and by a daily cron until none is left, and lazily when a game is read.
 *
 * fingerprint() and should_log() are pure and unit-tested; on_save() and the
 * repository methods are thin WordPress wrappers.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPA_Changes {

	const FINGERPRINT_META = '_sppa_fingerprint';

	/** The fingerprint's fields, kept so a change row can show "before". */
	const SNAPSHOT_META = '_sppa_snapshot';

	/** Option bumped on every logged change; feeds cache keys. */
	const GENERATION_OPTION = 'sppa_generation';

	/** Meta keys whose writes can change a fingerprint. */
	const WATCHED_META = array( '_spem_cancelled', 'sp_status', 'sp_team' );

	/** Daily cron that baselines upcoming games until none is left without a fingerprint. */
	const BASELINE_HOOK = 'sppa_baseline';

	/** Events baselined per batch. */
	const BASELINE_LIMIT = 200;

	/** Batches one cron run may take (10 x 200 covers a season). */
	const BASELINE_BATCHES = 10;

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
	 * Wire the hooks. Saves and meta writes are queued, not handled on the spot,
	 * and the event is checked once at shutdown. SportsPress writes sp_team and
	 * sp_status on the generic save_post, which core fires after
	 * save_post_sp_event, and rewrites sp_team one row at a time (delete, then
	 * add each); checking earlier would baseline or log half-written states.
	 */
	public static function register(): void {
		add_action( 'save_post_sp_event', array( __CLASS__, 'on_post_saved' ), 99, 1 );
		add_action( 'added_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'updated_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'deleted_post_meta', array( __CLASS__, 'on_meta' ), 10, 3 );
		add_action( 'shutdown', array( __CLASS__, 'flush_pending' ) );
		add_action( self::BASELINE_HOOK, array( __CLASS__, 'run_baseline' ) );
	}

	/**
	 * Store a baseline (fingerprint and snapshot, no change row, no
	 * sppa_event_changed) for upcoming games that have none: publish/future
	 * events, and drafts events-manager cancelled. The Player App ships dark, so every
	 * game scheduled before the module was enabled starts without one, and its
	 * first reschedule would otherwise go unlogged. Soonest games first.
	 *
	 * @param int $limit Most events to baseline in this batch.
	 * @return int Events baselined.
	 */
	public static function baseline_upcoming( int $limit = self::BASELINE_LIMIT ): int {
		$limit = max( 1, $limit );
		// raw_event() reads the start from post_date (site time), so "upcoming" does too.
		$now  = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'Y-m-d H:i:s' );
		$base = array(
			'post_type'      => 'sp_event',
			'orderby'        => 'date',
			'order'          => 'ASC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'date_query'     => array(
				array(
					'column' => 'post_date',
					'after'  => $now,
				),
			),
		);
		$no_fingerprint = array(
			'key'     => self::FINGERPRINT_META,
			'compare' => 'NOT EXISTS',
		);

		$ids = (array) get_posts(
			$base + array(
				'post_status'    => array( 'publish', 'future' ),
				'posts_per_page' => $limit,
				'meta_query'     => array( $no_fingerprint ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a bounded batch.
			)
		);
		if ( count( $ids ) < $limit ) {
			$ids = array_merge(
				$ids,
				(array) get_posts(
					$base + array(
						'post_status'    => 'draft',
						'posts_per_page' => $limit - count( $ids ),
						'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- a bounded batch.
							array(
								'key'   => '_spem_cancelled',
								'value' => '1',
							),
							$no_fingerprint,
						),
					)
				)
			);
		}
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		_prime_post_caches( $ids, true, true );

		$reader = new SPPA_Roster_Reader();
		$done   = 0;
		foreach ( $ids as $post_id ) {
			$raw = $reader->raw_event( $post_id );
			if ( null !== $raw && self::store_baseline( $post_id, $raw ) ) {
				++$done;
			}
		}
		return $done;
	}

	/**
	 * Baseline one batch now and, when more may be left, schedule the daily
	 * cron to finish. Runs on activation and when the module is enabled.
	 */
	public static function start_baseline(): void {
		if ( self::baseline_upcoming() >= self::BASELINE_LIMIT && ! wp_next_scheduled( self::BASELINE_HOOK ) ) {
			wp_schedule_event( time() + 60, 'daily', self::BASELINE_HOOK );
		}
	}

	/**
	 * The sppa_baseline cron: baseline in batches, and unschedule once a batch
	 * comes back short (nothing is left).
	 */
	public static function run_baseline(): void {
		for ( $i = 0; $i < self::BASELINE_BATCHES; $i++ ) {
			if ( self::baseline_upcoming() < self::BASELINE_LIMIT ) {
				wp_clear_scheduled_hook( self::BASELINE_HOOK );
				return;
			}
		}
	}

	/**
	 * Store a baseline for a game that is being read and has none yet (cheap:
	 * one meta read from the primed cache, a write only when missing). Never
	 * overwrites a stored fingerprint, which would hide a real change.
	 *
	 * @param array $raw Raw event from SPPA_Roster_Reader::raw_event().
	 */
	public static function ensure_baseline( array $raw ): void {
		$post_id = (int) ( $raw['id'] ?? 0 );
		if ( $post_id <= 0 || isset( self::$running[ $post_id ] ) ) {
			return;
		}
		$start = '' !== (string) ( $raw['start'] ?? '' ) ? strtotime( (string) $raw['start'] ) : false;
		if ( false === $start || $start <= time() ) {
			return;
		}
		self::store_baseline( $post_id, $raw );
	}

	/**
	 * The fields a player cares about, normalised: start, venue name, sorted
	 * team ids and the called-off-aware status.
	 *
	 * @param array $raw Raw event from SPPA_Roster_Reader::raw_event().
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
			'status' => SPPA_Event_Presenter::present( $raw, 0 )['status'],
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
	 * save_post_sp_event hook: queue the event for the check at shutdown.
	 */
	public static function on_post_saved( int $post_id ): void {
		if ( $post_id > 0 && 'sp_event' === get_post_type( $post_id ) ) {
			self::$pending[ $post_id ] = true;
		}
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
	 * Check every queued event once, after everything the request wrote to it:
	 * one fingerprint compare, so a save that moves the game and changes its
	 * teams is one change row and one sppa_event_changed. Shutdown also fires
	 * in WP-CLI and cron, which covers importers.
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
	 * Fires `sppa_event_changed( $post_id, $before, $after )` after a row is
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
		$table        = SPPA_Schema::tables()['changes'];
		$placeholders = implode( ',', array_fill( 0, count( $event_ids ), '%d' ) );
		$cutoff       = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * 86400 );

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT event_id FROM {$table} WHERE event_id IN ({$placeholders}) AND changed_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from SPPA_Schema, not input; every value stays bound. The IN() list is literal %d placeholders.
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
		$table = SPPA_Schema::tables()['changes'];

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names come from SPPA_Schema, not input; every value stays bound.
		);
	}

	private static function track( int $post_id ): void {
		$raw = ( new SPPA_Roster_Reader() )->raw_event( $post_id );
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

		do_action( 'sppa_event_changed', $post_id, $before, $after );
	}

	/**
	 * Fingerprint and snapshot for an event with no stored fingerprint. Never
	 * logs, bumps the generation or fires sppa_event_changed.
	 *
	 * @return bool Whether a baseline was written.
	 */
	private static function store_baseline( int $post_id, array $raw ): bool {
		if ( '' !== (string) get_post_meta( $post_id, self::FINGERPRINT_META, true ) ) {
			return false;
		}
		self::store( $post_id, self::fingerprint( $raw ), self::snapshot( $raw ) );
		return true;
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
			SPPA_Schema::tables()['changes'],
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
				SPAT_Logger::error( 'player_app', 'Change log write failed for event ' . $post_id );
			}
			return false;
		}
		return true;
	}
}
