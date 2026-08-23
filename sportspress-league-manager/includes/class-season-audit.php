<?php
/**
 * Detects and repairs current-season records left mis-configured.
 *
 * Conveners build a new season by duplicating last season's records. The copy
 * keeps the original's configuration, so a list can carry the right season, the
 * right division and the right players while still filtering on last season's
 * date window — every stat then reads zero with nothing reporting an error.
 * Calendars have the mirror problem: they survive the rollover but stay tagged
 * to the season they were built for, so a team's schedule looks empty.
 *
 * Each check knows how to find the affected records and how to repair them, and
 * a repair always covers every record the check found.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Season_Audit {

	/**
	 * Check keys, in the order they are presented.
	 */
	const CHECKS = array( 'stale_date_range', 'calendar_season' );

	/**
	 * Upper bound on matches reported or repaired in one pass.
	 */
	const MAX_ITEMS = 200;

	/**
	 * Upper bound on records examined per post type.
	 *
	 * The cap is applied to the records EXAMINED, not to the matches, because
	 * capping the candidate query would hide older affected records behind
	 * newer healthy ones and let the audit report a clean bill of health.
	 */
	const MAX_CANDIDATES = 2000;

	/**
	 * Whether a record's date filter cannot overlap the season.
	 *
	 * Only an absolute `range` filters by the stored dates. Mode `0` means
	 * "all dates", and a `range` in relative mode uses sp_date_past/future and
	 * ignores from/to entirely — in both cases the stored dates are inert
	 * leftovers, so treating them as a filter would convert a working record.
	 *
	 * An unknown season start returns false: without it there is nothing to
	 * compare against, and guessing would rewrite records that are fine.
	 *
	 * @param string $mode         The record's sp_date mode.
	 * @param string $relative     The record's sp_date_relative flag.
	 * @param string $date_to      The record's sp_date_to value (Y-m-d).
	 * @param string $season_start Date of the season's first event (Y-m-d).
	 * @return bool
	 */
	public static function is_stale_range( string $mode, string $relative, string $date_to, string $season_start ): bool {
		if ( 'range' !== $mode || '' === $date_to || '' === $season_start ) {
			return false;
		}

		if ( '' !== $relative && '0' !== $relative ) {
			return false;
		}

		return $date_to < $season_start;
	}

	/**
	 * Whether a record is missing the season it is supposed to show.
	 *
	 * @param array $tagged_ids Term ids currently on the record.
	 * @param int   $season_id  Season term id.
	 * @return bool
	 */
	public static function needs_season_tag( array $tagged_ids, int $season_id ): bool {
		return ! in_array( $season_id, array_map( 'intval', $tagged_ids ), true );
	}

	/**
	 * The season term plus any child (playoff) terms.
	 *
	 * A calendar attached only to the parent misses playoff games, which are
	 * tagged to the child term. The season rollover writes both, so a repair
	 * has to write both or it produces a calendar worse than a rolled-over one.
	 *
	 * @param int $season_id Season term id.
	 * @return array Int term ids, parent first.
	 */
	public static function season_terms( int $season_id ): array {
		$ids      = array( $season_id );
		$children = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
				'parent'     => $season_id,
				'fields'     => 'ids',
			)
		);

		if ( ! is_wp_error( $children ) ) {
			foreach ( $children as $child ) {
				$ids[] = (int) $child;
			}
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/**
	 * Human-facing description of a check.
	 *
	 * @param string $key Check key.
	 * @return array Empty when the key is unknown.
	 */
	public static function describe( string $key ): array {
		$all = array(
			'stale_date_range' => array(
				'label'      => __( 'Records still filtered to a past season', 'sportspress-league-manager' ),
				'severity'   => 'error',
				'problem'    => __( 'These were copied from an earlier season and kept its date filter, so they show no statistics for this season even though the games, teams and players are all correct.', 'sportspress-league-manager' ),
				'fix_label'  => __( 'Clear the date filter', 'sportspress-league-manager' ),
				'applies_to' => __( 'player lists, calendars and league tables', 'sportspress-league-manager' ),
			),
			'calendar_season'  => array(
				'label'      => __( 'Team calendars not showing this season', 'sportspress-league-manager' ),
				'severity'   => 'warning',
				'problem'    => __( 'These calendars belong to teams playing this season but are still attached to an earlier season, so their schedules appear empty.', 'sportspress-league-manager' ),
				'fix_label'  => __( 'Attach to this season', 'sportspress-league-manager' ),
				'applies_to' => __( 'team calendars', 'sportspress-league-manager' ),
			),
		);

		return $all[ $key ] ?? array();
	}

	/**
	 * Date of the season's first event.
	 *
	 * @param int $season_id Season term id.
	 * @return string Y-m-d, or '' when the season has no events.
	 */
	public static function season_start( int $season_id ): string {
		$events = self::season_events( $season_id );
		if ( ! $events ) {
			return '';
		}

		$earliest = '';
		foreach ( $events as $event_id ) {
			$post = get_post( $event_id );
			if ( ! $post ) {
				continue;
			}
			$date = substr( $post->post_date, 0, 10 );
			if ( '' === $earliest || $date < $earliest ) {
				$earliest = $date;
			}
		}

		return $earliest;
	}

	/**
	 * Run every check for a season.
	 *
	 * @param int $season_id Season term id.
	 * @return array check_key => array( items, count, capped ).
	 */
	public static function run( int $season_id ): array {
		$out = array();
		foreach ( self::CHECKS as $key ) {
			$out[ $key ] = self::find( $key, $season_id );
		}

		return $out;
	}

	/**
	 * Repair every record a check currently reports.
	 *
	 * Detection is re-run here rather than trusting ids from the caller, so a
	 * stale dashboard cannot drive a write against records that have since been
	 * corrected by hand.
	 *
	 * @param string $check_key Check key.
	 * @param int    $season_id Season term id.
	 * @return array fixed, skipped, items, and locked when the lock was held.
	 */
	public static function fix( string $check_key, int $season_id ): array {
		$empty = array(
			'fixed'   => 0,
			'skipped' => 0,
			'items'   => array(),
			'locked'  => false,
		);

		if ( ! in_array( $check_key, self::CHECKS, true ) ) {
			return $empty;
		}

		$apply = function () use ( $check_key, $season_id ) {
			$fixed   = 0;
			$skipped = 0;
			$done    = array();
			$result  = self::find( $check_key, $season_id );

			foreach ( $result['items'] as $item ) {
				$ok = 'stale_date_range' === $check_key
					? self::clear_date_filter( (int) $item['id'] )
					: self::attach_season( (int) $item['id'], $season_id );

				if ( $ok ) {
					++$fixed;
					$done[] = array(
						'id'    => (int) $item['id'],
						'title' => $item['title'],
					);
				} else {
					++$skipped;
				}
			}

			return array(
				'fixed'   => $fixed,
				'skipped' => $skipped,
				'items'   => $done,
				'locked'  => false,
			);
		};

		// Two conveners clicking at once would otherwise interleave detection
		// and repair. The key is per season and per check so unrelated repairs
		// do not block each other.
		if ( class_exists( 'SPAT_Lock' ) ) {
			$result = SPAT_Lock::with( "splm_season_audit_{$check_key}_{$season_id}", 120, $apply );

			// with() returns false when the lock could not be acquired, which
			// must not be reported as "nothing needed fixing".
			if ( ! is_array( $result ) ) {
				$empty['locked'] = true;

				return $empty;
			}

			return $result;
		}

		return $apply();
	}

	/**
	 * Records currently failing a check.
	 *
	 * @param string $key       Check key.
	 * @param int    $season_id Season term id.
	 * @return array items, count, capped.
	 */
	private static function find( string $key, int $season_id ): array {
		$found = 'stale_date_range' === $key
			? self::find_stale_ranges( $season_id )
			: self::find_untagged_calendars( $season_id );

		// Every match is returned: a repair must cover all of them. Truncation
		// for display happens in the REST layer, which is the only place that
		// needs to keep a response small.
		return array(
			'items'  => $found,
			'count'  => count( $found ),
			'capped' => count( $found ) > self::MAX_ITEMS,
		);
	}

	/**
	 * Season-tagged records whose date filter predates the season.
	 *
	 * @param int $season_id Season term id.
	 * @return array
	 */
	private static function find_stale_ranges( int $season_id ): array {
		$season_start = self::season_start( $season_id );
		if ( '' === $season_start ) {
			return array();
		}

		$found = array();
		foreach ( array( 'sp_list', 'sp_calendar', 'sp_table' ) as $type ) {
			$ids = get_posts(
				array(
					'post_type'      => $type,
					'posts_per_page' => self::MAX_CANDIDATES,
					'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
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
			if ( ! $ids ) {
				continue;
			}
			update_meta_cache( 'post', $ids );
			_prime_post_caches( $ids, false, false );

			foreach ( $ids as $post_id ) {
				$mode     = (string) get_post_meta( $post_id, 'sp_date', true );
				$relative = (string) get_post_meta( $post_id, 'sp_date_relative', true );
				$to       = (string) get_post_meta( $post_id, 'sp_date_to', true );
				if ( ! self::is_stale_range( $mode, $relative, $to, $season_start ) ) {
					continue;
				}

				$from    = (string) get_post_meta( $post_id, 'sp_date_from', true );
				$found[] = array(
					'id'     => (int) $post_id,
					'title'  => splm_display_title( $post_id ),
					/* translators: 1: start date, 2: end date. */
					'detail' => sprintf( __( 'filtered to %1$s – %2$s', 'sportspress-league-manager' ), $from, $to ),
				);
			}
		}

		return $found;
	}

	/**
	 * Calendars of teams playing this season that are not attached to it.
	 *
	 * A team with no calendar at all is deliberately not reported: creating one
	 * is the season rollover's job, and inventing records is not a repair.
	 *
	 * @param int $season_id Season term id.
	 * @return array
	 */
	private static function find_untagged_calendars( int $season_id ): array {
		$playing = self::season_team_ids( $season_id );
		if ( ! $playing ) {
			return array();
		}

		$calendars = get_posts(
			array(
				'post_type'      => 'sp_calendar',
				'posts_per_page' => self::MAX_CANDIDATES,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'fields'         => 'ids',
			)
		);
		if ( ! $calendars ) {
			return array();
		}
		update_meta_cache( 'post', $calendars );
		_prime_post_caches( $calendars, false, false );

		$found = array();
		foreach ( $calendars as $calendar_id ) {
			$team = (int) get_post_meta( $calendar_id, 'sp_team', true );
			if ( ! $team || ! isset( $playing[ $team ] ) ) {
				continue;
			}

			$terms = wp_get_object_terms( $calendar_id, 'sp_season', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) ) {
				continue;
			}
			if ( ! self::needs_season_tag( (array) $terms, $season_id ) ) {
				continue;
			}

			$names   = wp_get_object_terms( $calendar_id, 'sp_season', array( 'fields' => 'names' ) );
			$names   = is_wp_error( $names ) ? array() : (array) $names;
			$found[] = array(
				'id'     => (int) $calendar_id,
				'title'  => splm_display_title( $calendar_id ),
				'detail' => $names
					/* translators: %s: comma-separated season names. */
					? sprintf( __( 'attached to %s', 'sportspress-league-manager' ), implode( ', ', $names ) )
					: __( 'not attached to any season', 'sportspress-league-manager' ),
			);
		}

		return $found;
	}

	/**
	 * Stop a record filtering by date, so it follows its season instead.
	 *
	 * Only the mode is changed. The stored from/to dates are left in place so
	 * the original window is still visible to anyone who wants it back.
	 *
	 * @param int $post_id Record id.
	 * @return bool
	 */
	private static function clear_date_filter( int $post_id ): bool {
		update_post_meta( $post_id, 'sp_date', '0' );

		return '0' === (string) get_post_meta( $post_id, 'sp_date', true );
	}

	/**
	 * Point a calendar at this season and its playoffs.
	 *
	 * The seasons are replaced rather than appended: a calendar shows the
	 * seasons it carries, so appending would make it show every season it has
	 * ever been attached to. Both the parent and its playoff child are written,
	 * matching the season rollover — writing only the parent would leave the
	 * calendar missing every playoff game.
	 *
	 * @param int $post_id   Calendar id.
	 * @param int $season_id Season term id.
	 * @return bool
	 */
	private static function attach_season( int $post_id, int $season_id ): bool {
		$result = wp_set_object_terms( $post_id, self::season_terms( $season_id ), 'sp_season' );

		return ! is_wp_error( $result );
	}

	/**
	 * Event ids in a season, playoff children included.
	 *
	 * @param int $season_id Season term id.
	 * @return array
	 */
	private static function season_events( int $season_id ): array {
		static $cache = array();

		if ( isset( $cache[ $season_id ] ) ) {
			return $cache[ $season_id ];
		}

		$cache[ $season_id ] = get_posts(
			array(
				'post_type'      => 'sp_event',
				'posts_per_page' => 5000,
				'post_status'    => array( 'publish', 'future' ),
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

		if ( $cache[ $season_id ] ) {
			_prime_post_caches( $cache[ $season_id ], false, false );
			update_meta_cache( 'post', $cache[ $season_id ] );
		}

		return $cache[ $season_id ];
	}

	/**
	 * Teams appearing in a season's events, as a lookup set.
	 *
	 * @param int $season_id Season term id.
	 * @return array team_id => true.
	 */
	private static function season_team_ids( int $season_id ): array {
		$teams = array();
		foreach ( self::season_events( $season_id ) as $event_id ) {
			foreach ( (array) get_post_meta( $event_id, 'sp_team', false ) as $team ) {
				if ( (int) $team ) {
					$teams[ (int) $team ] = true;
				}
			}
		}

		return $teams;
	}
}
