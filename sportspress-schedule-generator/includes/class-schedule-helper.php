<?php
/**
 * Schedule Helper
 *
 * Shared utilities used by the slot allocator and the constraint manager so
 * feasibility pre-checks and the live allocator agree on how many slots a
 * given (venue, date) tuple actually offers.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedule helper utilities
 */
class SPSG_Schedule_Helper {


	/**
	 * Extract an ID from an object, array, or string entity.
	 *
	 * Mirrors the private extraction logic in SPSG_Slot_Allocator so both
	 * paths resolve venue IDs identically.
	 *
	 * @param mixed $entity Entity (object, array, or string).
	 * @return string Resolved ID (or empty string).
	 */
	public static function extract_id( $entity ) {
		if ( is_string( $entity ) ) {
			return $entity;
		}
		if ( is_object( $entity ) ) {
			return $entity->id ?? $entity->name ?? '';
		}
		if ( is_array( $entity ) ) {
			return $entity['id'] ?? $entity['name'] ?? '';
		}
		return '';
	}

	/**
	 * Build a date => sequential season-week-number map from a schedule.
	 *
	 * Games are grouped by real calendar week (Monday-Sunday, ISO-8601), so a
	 * league playing e.g. Friday and Sunday both land under the same week
	 * number. Weeks are then numbered 1, 2, 3... in true chronological
	 * order -- "Week 1 of the season", not the ISO week-of-year number,
	 * which would reset at each calendar year boundary (this plugin's
	 * seasons routinely cross one) and mean nothing to an admin reading an
	 * export.
	 *
	 * Numbering by the order dates first appear in `$schedule` (rather than
	 * sorting) would seem equivalent, since a generated schedule is *mostly*
	 * date-ordered, but isn't reliably so -- the slot allocator can place a
	 * later matchup before an earlier one depending on how the search
	 * proceeds, and did so on a real 272-game season, ending up with e.g.
	 * "Week 3 — October 25" printed before "Week 4 — October 18". Grouping
	 * dates into real weeks first, then sorting those week keys (an ISO
	 * year + zero-padded ISO week number sorts correctly as a plain string)
	 * before assigning sequential numbers avoids depending on the input
	 * order at all.
	 *
	 * Shared by the CSV and XLSX exporters so a game reports the same week
	 * number in either format -- neither a plain game object nor array ever
	 * carries a week_number of its own (see {@see SPSG_Slot_Allocator::create_game()}),
	 * so without this, an exported "Week" column has nothing to show at all.
	 *
	 * @param array $schedule Array of game objects/arrays, each carrying a `date`.
	 * @return array<string,int> Date (Y-m-d) => week number.
	 */
	public static function build_week_number_map( $schedule ) {
		$dates_by_key = self::group_dates_by_real_week( $schedule );
		ksort( $dates_by_key );

		$week_by_date = array();
		$week_num     = 1;
		foreach ( $dates_by_key as $dates_in_week ) {
			foreach ( $dates_in_week as $date ) {
				$week_by_date[ $date ] = $week_num;
			}
			++$week_num;
		}

		return $week_by_date;
	}

	/**
	 * Group a schedule's distinct dates by real calendar week.
	 *
	 * @param array $schedule Array of game objects/arrays, each carrying a `date`.
	 * @return array<string,array<string,string>> ISO week key => set of dates (as a value=>value map, to dedupe).
	 */
	public static function group_dates_by_real_week( $schedule ) {
		$dates_by_key = array();

		foreach ( (array) $schedule as $game ) {
			$date = self::extract_game_date( $game );
			$key  = '' !== $date ? self::iso_week_key( $date ) : null;

			if ( null === $key ) {
				continue;
			}

			$dates_by_key[ $key ][ $date ] = $date;
		}

		return $dates_by_key;
	}

	/**
	 * Read a game object/array's `date` field.
	 *
	 * @param array|object $game Game object or array.
	 * @return string Date in Y-m-d format, or '' if absent.
	 */
	private static function extract_game_date( $game ) {
		return is_array( $game ) ? ( $game['date'] ?? '' ) : ( $game->date ?? '' );
	}

	/**
	 * Build a key identifying the real (Mon-Sun) calendar week a date falls
	 * in, stable across a season that crosses a year boundary.
	 *
	 * Combines the ISO week-numbering year (`o`) with the ISO week number
	 * (`W`) rather than the plain calendar year (`Y`): a date in the last
	 * days of December can belong to ISO week 1 of the *following* year (and
	 * the reverse in early January), so `Y-W` alone can collide two
	 * unrelated weeks onto the same key right at the boundary this plugin's
	 * seasons commonly cross.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string|null Stable per-week key, or null if $date doesn't parse.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function iso_week_key( $date ) {
		$dt = DateTime::createFromFormat( 'Y-m-d', $date );
		return $dt ? $dt->format( 'o-W' ) : null;
	}

	/**
	 * Resolve the calendar dates a real (Mon-Sun) week falls on for each of
	 * the season's configured playing days, restricted to dates within
	 * [season_start, season_end].
	 *
	 * A week clipped by the season boundary (e.g. the season starts on a
	 * Sunday, so that first real week has no Friday in-season) returns fewer
	 * entries than `count($config->playing_days)` -- callers use that to
	 * treat a boundary week as inherently partial.
	 *
	 * @param string $week_key ISO week key, as produced by {@see iso_week_key()} ("o-W").
	 * @param object $config   Schedule configuration.
	 * @return array<int,array{date:string,day_name:string}> One entry per in-season playing day that week.
	 */
	public static function get_week_playing_dates( $week_key, $config ) {
		$parts = explode( '-', $week_key );
		if ( 2 !== count( $parts ) ) {
			return array();
		}

		// Fixed at midnight: `new DateTime()` defaults to the current wall-clock
		// time, which would otherwise push a day whose date matches
		// season_end (also midnight) past it in the `>` comparison below
		// whenever the real-world time of day isn't exactly 00:00:00.
		$monday = new DateTime( 'midnight' );
		$monday->setISODate( (int) $parts[0], (int) $parts[1] );

		$playing_days = $config->playing_days ?? array();
		$season_start = $config->season_start ?? null;
		$season_end   = $config->season_end ?? null;

		$dates = array();
		for ( $offset = 0; $offset < 7; $offset++ ) {
			$day = ( clone $monday )->add( new DateInterval( "P{$offset}D" ) );
			$day_name = strtolower( $day->format( 'l' ) );

			$day_str = $day->format( 'Y-m-d' );
			if ( ! in_array( $day_name, $playing_days, true ) || ! self::is_date_in_season( $day_str, $season_start, $season_end ) ) {
				continue;
			}

			$dates[] = array(
				'date' => $day_str,
				'day_name' => $day_name,
			);
		}

		return $dates;
	}

	/**
	 * Whether $date (Y-m-d) falls within [$season_start, $season_end],
	 * treating either bound as open-ended when absent.
	 *
	 * @param string        $date         Date in YYYY-MM-DD format.
	 * @param DateTime|null $season_start Season start, or null for no lower bound.
	 * @param DateTime|null $season_end   Season end, or null for no upper bound.
	 * @return bool
	 */
	private static function is_date_in_season( $date, $season_start, $season_end ) {
		if ( $season_start instanceof DateTime && $date < $season_start->format( 'Y-m-d' ) ) {
			return false;
		}
		if ( $season_end instanceof DateTime && $date > $season_end->format( 'Y-m-d' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether a (date, day) has no active blackout or date-specific override
	 * for ANY venue -- i.e. the normal, unmodified schedule applies. A venue
	 * whose date-specific window merely repeats the normal hours still
	 * counts as "modified": the operator explicitly carved out that date, and
	 * a week-completeness check should not assume it behaves like any other.
	 *
	 * @param string $date     Date in YYYY-MM-DD format.
	 * @param object $config   Schedule configuration.
	 * @return bool
	 */
	public static function is_date_unmodified( $date, $config ) {
		if ( in_array( $date, (array) ( $config->blackout_dates ?? array() ), true ) ) {
			return false;
		}

		foreach ( (array) ( $config->venues ?? array() ) as $venue ) {
			$venue_id = self::extract_id( $venue );

			if ( self::is_venue_blacked_out( $venue_id, $date, $config ) ) {
				return false;
			}

			if ( self::has_date_specific_override( $venue_id, $date, $config ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether $venue_id carries a `venue_date_availability` range covering $date.
	 *
	 * @param int|string $venue_id Venue identifier.
	 * @param string     $date     Date in YYYY-MM-DD format.
	 * @param object     $config   Schedule configuration.
	 * @return bool
	 */
	private static function has_date_specific_override( $venue_id, $date, $config ) {
		if ( empty( $config->venue_date_availability[ $venue_id ] ) ) {
			return false;
		}

		foreach ( $config->venue_date_availability[ $venue_id ] as $range ) {
			if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Enumerate every real (Mon-Sun) calendar week the season's date range
	 * touches, regardless of whether any games were actually scheduled in
	 * it -- so a week where a whole division went silent still gets
	 * evaluated for completeness rather than being invisible to the check
	 * because no games happen to reference it.
	 *
	 * @param object $config Schedule configuration.
	 * @return array<int,string> ISO week keys ("o-W"), season order.
	 */
	public static function get_season_week_keys( $config ) {
		if ( ! ( $config->season_start instanceof DateTime ) || ! ( $config->season_end instanceof DateTime ) ) {
			return array();
		}

		$keys = array();
		$current = clone $config->season_start;
		while ( $current <= $config->season_end ) {
			$key = self::iso_week_key( $current->format( 'Y-m-d' ) );
			if ( null !== $key ) {
				$keys[ $key ] = $key;
			}
			$current->add( new DateInterval( 'P1D' ) );
		}

		return array_values( $keys );
	}

	/**
	 * Whether a real calendar week is "complete": every one of the season's
	 * configured playing days falls in-season that week, and none of them
	 * carries a blackout or date-specific override on any venue. Only a
	 * complete week is a fair basis for expecting every team to play exactly
	 * once -- see {@see SPSG_Statistics_Calculator::detect_incomplete_weeks()}.
	 *
	 * @param string $week_key ISO week key ("o-W").
	 * @param object $config   Schedule configuration.
	 * @return bool
	 */
	public static function is_week_complete( $week_key, $config ) {
		$playing_days = $config->playing_days ?? array();
		if ( empty( $playing_days ) ) {
			return false;
		}

		$week_dates = self::get_week_playing_dates( $week_key, $config );
		if ( count( $week_dates ) !== count( $playing_days ) ) {
			return false;
		}

		foreach ( $week_dates as $entry ) {
			if ( ! self::is_date_unmodified( $entry['date'], $config ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Resolve the time slots available for a (venue, date, day_name) tuple,
	 * respecting the priority cascade:
	 *   1. Date-specific availability windows (venue_date_availability)
	 *   2. Venue-specific weekday timeslots (venue_timeslots)
	 *   3. Global weekday timeslots (time_slots)
	 *
	 * Returns null when no rule matches so callers can distinguish "no slots"
	 * from "explicitly empty list".
	 *
	 * @param int|string $venue_id Venue identifier.
	 * @param string     $date     Date in YYYY-MM-DD format.
	 * @param string     $day_name Lowercase day name (monday..sunday).
	 * @param object     $config   Schedule configuration.
	 * @return array|null Time slots for this venue+date+day combo, or null if none.
	 */
	/**
	 * Per-request memoization for resolve_venue_slots().
	 *
	 * SG-6: resolve_venue_slots() is a pure function of
	 * (venue_id, date, day_name, $config) — it only reads config arrays and has
	 * no side effects. It is called O(n log n) times inside the division-grouping
	 * usort comparator and per-game cost calcs, recomputing the same cascade
	 * repeatedly. Memoizing returns the byte-identical array for identical inputs.
	 * Keyed on the config object identity so a different config object never
	 * reads another's slots, and so the cache is naturally scoped per pass.
	 *
	 * @var array<string,array|null>
	 */
	private static $venue_slots_cache = array();

	/**
	 * Reset the resolve_venue_slots() memo. Call between generation passes that
	 * mutate the same config object in place (none do today, but this keeps the
	 * cache safe if that ever changes).
	 */
	public static function reset_venue_slots_cache() {
		self::$venue_slots_cache = array();
	}

	public static function resolve_venue_slots( $venue_id, $date, $day_name, $config ) {
		// SG-6: memoize on (config identity, venue, date, day). Output is the
		// exact array the cascade below would return, so schedule output is
		// byte-identical with or without the cache.
		$config_key = is_object( $config ) ? spl_object_id( $config ) : 'arr';
		$cache_key  = $config_key . '|' . $venue_id . '|' . $date . '|' . $day_name;
		if ( array_key_exists( $cache_key, self::$venue_slots_cache ) ) {
			return self::$venue_slots_cache[ $cache_key ];
		}

		$resolved = self::resolve_venue_slots_uncached( $venue_id, $date, $day_name, $config );
		self::$venue_slots_cache[ $cache_key ] = $resolved;
		return $resolved;
	}

	/**
	 * Uncached cascade resolution. Extracted so {@see resolve_venue_slots()} can
	 * memoize without changing the resolution logic.
	 *
	 * @param int|string $venue_id Venue identifier.
	 * @param string     $date     Date in YYYY-MM-DD format.
	 * @param string     $day_name Lowercase day name.
	 * @param object     $config   Schedule configuration.
	 * @return array|null Resolved slots or null.
	 */
	private static function resolve_venue_slots_uncached( $venue_id, $date, $day_name, $config ) {
		// Priority 1: Date-specific availability
		if ( ! empty( $config->venue_date_availability[ $venue_id ] ) ) {
			foreach ( $config->venue_date_availability[ $venue_id ] as $range ) {
				if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
					// Guard against malformed range rows missing time_slots; when
					// empty, fall through to the next cascade level rather than
					// returning a partial/undefined value.
					if ( isset( $range['time_slots'] ) && ! empty( $range['time_slots'] ) ) {
						return $range['time_slots'];
					}
				}
			}
		}

		// Priority 2: Venue-specific timeslots for this day
		if ( ! empty( $config->venue_timeslots[ $venue_id ][ $day_name ] ) ) {
			return $config->venue_timeslots[ $venue_id ][ $day_name ];
		}

		// Priority 3: Global time slots for this day
		if ( ! empty( $config->time_slots[ $day_name ] ) ) {
			return $config->time_slots[ $day_name ];
		}

		return null;
	}

	/**
	 * Resolve the target share of games for each playing day.
	 *
	 * `distribution_rules.day_ratios` is what the sanitizer derives from the
	 * admin form's day_weights input. `day_balance` is the documented property
	 * (docs/CONFIGURATION-PROPERTIES.md), what every preset ships and what the
	 * REST generate path writes from the global day-weights option. An explicit
	 * `day_ratios` wins when both are present; with neither, every playing day
	 * gets an equal share.
	 *
	 * Shares are normalised so weights (3:1) and ratios (0.75 / 0.25) mean the
	 * same thing. A playing day the rule leaves out gets a 0 share: that is
	 * what the REST path produces for a zero-weight day, and keeping the
	 * even-split default for it would make the shares sum to more than 1.
	 *
	 * Shared by the distribution constraint (per-team day balance) and the slot
	 * allocator (per-date load targets) so the two cannot disagree about what
	 * the operator asked for.
	 *
	 * @param object $config Schedule configuration.
	 * @return array<string,float> day name => share in [0, 1], one entry per playing day.
	 */
	public static function resolve_day_ratios( $config ) {
		$playing_days = (array) ( $config->playing_days ?? array() );

		$source = self::day_share_source( (array) ( $config->distribution_rules ?? array() ) );
		$shares = self::sanitize_day_shares( $source, $playing_days );
		$total  = array_sum( $shares );

		return $total > 0
			? self::normalize_day_shares( $playing_days, $shares, $total )
			: self::even_split_ratios( $playing_days );
	}

	/**
	 * Scale validated day shares to sum to 1, filling in a 0 share for any
	 * playing day the configured rule left out.
	 *
	 * @param array               $playing_days Playing day names.
	 * @param array<string,float> $shares       Validated day => share (see {@see sanitize_day_shares()}).
	 * @param float               $total        Sum of $shares, already known to be > 0.
	 * @return array<string,float> day name => normalised share.
	 */
	private static function normalize_day_shares( $playing_days, $shares, $total ) {
		$ratios = array();

		foreach ( $playing_days as $day ) {
			$ratios[ $day ] = isset( $shares[ $day ] ) ? $shares[ $day ] / $total : 0.0;
		}

		return $ratios;
	}

	/**
	 * Equal share for every playing day (the fallback resolve_day_ratios()
	 * returns when no day rule is configured, or is left in place for any day
	 * a configured rule doesn't override).
	 *
	 * @param array $playing_days Playing day names.
	 * @return array<string,float> day name => equal share.
	 */
	private static function even_split_ratios( $playing_days ) {
		$default_ratio = count( $playing_days ) > 0 ? 1.0 / count( $playing_days ) : 0.0;
		$ratios        = array();

		foreach ( $playing_days as $day ) {
			$ratios[ $day ] = $default_ratio;
		}

		return $ratios;
	}

	/**
	 * Pick which distribution-rules key holds the configured day shares.
	 * `day_ratios` (the admin form's derived value) wins when present;
	 * `day_balance` (the documented property) otherwise.
	 *
	 * @param array $rules Configuration's distribution_rules.
	 * @return array Raw day => share source, or empty when neither is set.
	 */
	private static function day_share_source( $rules ) {
		if ( ! empty( $rules['day_ratios'] ) && is_array( $rules['day_ratios'] ) ) {
			return $rules['day_ratios'];
		}
		if ( ! empty( $rules['day_balance'] ) && is_array( $rules['day_balance'] ) ) {
			return $rules['day_balance'];
		}
		return array();
	}

	/**
	 * Keep only entries that name an actual playing day and carry a
	 * non-negative numeric share.
	 *
	 * @param array $source       Raw day => share source.
	 * @param array $playing_days Playing day names.
	 * @return array<string,float> Validated day => share.
	 */
	private static function sanitize_day_shares( $source, $playing_days ) {
		$shares = array();

		foreach ( $source as $day => $share ) {
			if ( ! in_array( $day, $playing_days, true ) || ! is_numeric( $share ) ) {
				continue;
			}
			if ( (float) $share >= 0 ) {
				$shares[ $day ] = (float) $share;
			}
		}

		return $shares;
	}

	/**
	 * Check whether a venue is blacked out on the given date.
	 *
	 * @param int|string $venue_id Venue identifier.
	 * @param string     $date     Date in YYYY-MM-DD format.
	 * @param object     $config   Schedule configuration.
	 * @return bool True when the venue is blacked out for that date.
	 */
	public static function is_venue_blacked_out( $venue_id, $date, $config ) {
		return ! empty( $config->venue_blackout_dates[ $venue_id ] )
			&& in_array( $date, $config->venue_blackout_dates[ $venue_id ], true );
	}

	/**
	 * Count the total available slots across the season honouring the same
	 * cascade the slot allocator uses at run time.
	 *
	 * For each date in [season_start, season_end] that is a playing day and
	 * is not globally blacked out, iterates every venue and sums the slots
	 * resolved by resolve_venue_slots(), skipping venue/date blackouts.
	 *
	 * @param object $config Schedule configuration.
	 * @return int Total slot count.
	 */
	public static function count_available_slots( $config ) {
		$slots = 0;

		$tz = ! empty( $config->timezone ) ? new DateTimeZone( $config->timezone ) : wp_timezone();

		$season_start = $config->season_start instanceof DateTime
			? clone $config->season_start
			: new DateTime( $config->season_start, $tz );

		$season_end = $config->season_end instanceof DateTime
			? clone $config->season_end
			: new DateTime( $config->season_end, $tz );

		$blackout_dates = $config->blackout_dates ?? array();
		$playing_days   = $config->playing_days ?? array();
		$venues         = $config->venues ?? array();

		$current_date = clone $season_start;

		while ( $current_date <= $season_end ) {
			$date_str = $current_date->format( 'Y-m-d' );
			$day_name = strtolower( $current_date->format( 'l' ) );

			if ( ! in_array( $day_name, $playing_days, true ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			if ( in_array( $date_str, $blackout_dates, true ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			foreach ( $venues as $venue ) {
				$venue_id = self::extract_id( $venue );

				if ( self::is_venue_blacked_out( $venue_id, $date_str, $config ) ) {
					continue;
				}

				$venue_slots = self::resolve_venue_slots( $venue_id, $date_str, $day_name, $config );

				if ( ! empty( $venue_slots ) ) {
					$slots += count( $venue_slots );
				}
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		return $slots;
	}

	/**
	 * Count playing days in a date range that have at least one resolvable
	 * slot across all venues. Respects global and venue blackouts.
	 *
	 * @param DateTime $start          Range start.
	 * @param DateTime $end            Range end (inclusive).
	 * @param object   $config         Schedule configuration.
	 * @param array    $blackout_dates Optional list of YYYY-MM-DD blackouts (defaults to $config->blackout_dates).
	 * @return int Number of usable playing days.
	 */
	public static function count_usable_playing_days( $start, $end, $config, $blackout_dates = null ) {
		if ( null === $blackout_dates ) {
			$blackout_dates = $config->blackout_dates ?? array();
		}

		$playing_days = $config->playing_days ?? array();
		$venues       = $config->venues ?? array();

		$count        = 0;
		$current_date = clone $start;

		while ( $current_date <= $end ) {
			$date_str = $current_date->format( 'Y-m-d' );
			$day_name = strtolower( $current_date->format( 'l' ) );

			if ( ! in_array( $day_name, $playing_days, true ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			if ( in_array( $date_str, $blackout_dates, true ) ) {
				$current_date->add( new DateInterval( 'P1D' ) );
				continue;
			}

			$has_slots = false;
			foreach ( $venues as $venue ) {
				$venue_id = self::extract_id( $venue );

				if ( self::is_venue_blacked_out( $venue_id, $date_str, $config ) ) {
					continue;
				}

				$venue_slots = self::resolve_venue_slots( $venue_id, $date_str, $day_name, $config );
				if ( ! empty( $venue_slots ) ) {
					$has_slots = true;
					break;
				}
			}

			if ( $has_slots ) {
				$count++;
			}

			$current_date->add( new DateInterval( 'P1D' ) );
		}

		return $count;
	}
}
