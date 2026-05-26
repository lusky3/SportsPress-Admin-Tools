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
	wp_die();
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
	public static function resolve_venue_slots( $venue_id, $date, $day_name, $config ) {
		// Priority 1: Date-specific availability
		if ( ! empty( $config->venue_date_availability[ $venue_id ] ) ) {
			foreach ( $config->venue_date_availability[ $venue_id ] as $range ) {
				if ( $date >= $range['start_date'] && $date <= $range['end_date'] ) {
					return $range['time_slots'];
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
