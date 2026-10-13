<?php
/**
 * Dynamic Schedule
 *
 * Provides the [arl_schedule] shortcode: a SportsPress event list with
 * Season / Division / Team / Show dropdowns that refilter over AJAX.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Dynamic_Schedule {

	/**
	 * Lifetime, in seconds, of a rendered schedule block in the transient
	 * cache (5 minutes). A literal rather than MINUTE_IN_SECONDS so loading
	 * this file does not depend on WordPress's constants.
	 */
	const SCHEDULE_CACHE_TTL = 300;

	/**
	 * Keys that, when present in the arguments (even as '' meaning "All"),
	 * force the Calendar to be detached. See render_games().
	 */
	const DETACH_KEYS = array( 'league', 'season', 'team' );

	/**
	 * Build the [event_list] shortcode text for the given arguments.
	 *
	 * Only non-empty values are emitted. Values are made safe to sit inside
	 * double quotes: quotes, brackets and control characters are removed, so a
	 * value can never close the attribute or the shortcode.
	 *
	 * @param array $args Attribute map.
	 * @return string
	 */
	public static function build_event_list_shortcode( $args ) {
		$parts = array();
		foreach ( (array) $args as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key   = preg_replace( '/[^a-z_]/', '', strtolower( (string) $key ) );
			$value = preg_replace( '/[\x00-\x1F\x7F"\'\[\]<>]/', '', (string) $value );
			if ( '' === $key || '' === trim( $value ) ) {
				continue;
			}
			$parts[] = $key . '="' . trim( $value ) . '"';
		}
		return '[event_list' . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']';
	}

	/**
	 * Render the event list for a merged, validated argument map.
	 *
	 * Spike result (SportsPress templates/event-list.php and
	 * includes/class-sp-calendar.php::data()): the template only applies a
	 * league/season/team attribute when it is non-empty, and data() ADDS the
	 * Calendar's own league and season terms and ANDs its team meta whenever
	 * the Calendar has an id. SportsPress offers no filter on the query args
	 * or the calendar object, so with an id a Calendar-set league, season or
	 * team can be replaced (league/season) or narrowed (team) but never
	 * cleared, and a visitor's "All divisions" could not widen it.
	 *
	 * Chosen route: when the arguments mention league, season or team at all
	 * (a value or '' for "All"), render WITHOUT id and pass the Calendar's
	 * other settings explicitly (detach_calendar_settings()), skipping any
	 * field the arguments already decide. Otherwise keep the id so the
	 * Calendar is honoured exactly, including settings that cannot be passed
	 * as attributes (several leagues or teams, players, order by match day).
	 *
	 * Known limit of the detached route (SportsPress has no filter to clear
	 * Calendar values, and its attributes take a single id): a Calendar that
	 * fixes SEVERAL leagues, seasons or teams is widened to all of them, and
	 * the Calendar's players, order-by-match-day and title are dropped. The
	 * route is only taken when a visitor or the shortcode sets league, season
	 * or team, so the plain id route is unaffected.
	 *
	 * @param array $args Merged arguments; '' means the user chose "All".
	 * @return string HTML.
	 */
	private function render_games( array $args ): string {
		$calendar_id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		unset( $args['id'] );

		if ( $calendar_id && ! $this->is_published_calendar( $calendar_id ) ) {
			return $this->notice( __( 'Calendar not found.', 'sportspress-events-manager' ) );
		}

		$args = $this->prepare_attributes( $calendar_id, $args );
		if ( null === $args ) {
			return $this->notice( __( 'No games match these filters.', 'sportspress-events-manager' ) );
		}

		$html = do_shortcode( self::build_event_list_shortcode( $args ) );

		// SportsPress prints an empty table body when nothing matches.
		return $this->has_rows( $html ) ? $html : $this->notice( __( 'No games match these filters.', 'sportspress-events-manager' ) );
	}

	/**
	 * Choose between the id route and the detached route.
	 *
	 * @param int   $calendar_id Calendar id, 0 for none.
	 * @param array $args        Arguments without id.
	 * @return array|null Attributes for [event_list], or null for no match.
	 */
	private function prepare_attributes( $calendar_id, array $args ) {
		if ( ! array_intersect( self::DETACH_KEYS, array_keys( $args ) ) ) {
			return $calendar_id ? array( 'id' => $calendar_id ) + $args : $args;
		}
		$args = $this->resolve_term_values( $args );
		if ( null !== $args && $calendar_id ) {
			$args = $this->detach_calendar_settings( $calendar_id, $args );
		}
		return $args;
	}

	/**
	 * Whether the id is a published Calendar.
	 *
	 * @param int $calendar_id Post id.
	 * @return bool
	 */
	private function is_published_calendar( $calendar_id ) {
		return 'sp_calendar' === get_post_type( $calendar_id ) && 'publish' === get_post_status( $calendar_id );
	}

	/**
	 * Whether SportsPress output contains at least one event row.
	 *
	 * @param string $html Event list HTML.
	 * @return bool
	 */
	private function has_rows( $html ) {
		return false !== strpos( $html, '<tr class="sp-row' );
	}

	/**
	 * Wrap a message in the empty-state paragraph.
	 *
	 * @param string $message Already-translated text.
	 * @return string
	 */
	private function notice( $message ) {
		return '<p class="arl-schedule-empty">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Turn season/league slugs into term ids and check team ids. Empty
	 * values pass through untouched.
	 *
	 * @param array $args Arguments.
	 * @return array|null Arguments, or null when a named value does not exist.
	 */
	private function resolve_term_values( array $args ) {
		foreach ( array(
			'season' => 'sp_season',
			'league' => 'sp_league',
		) as $key => $taxonomy ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$term = $this->find_term( $args[ $key ], $taxonomy );
			if ( ! $term ) {
				return null;
			}
			$args[ $key ] = (string) $term->term_id;
		}
		if ( ! empty( $args['team'] ) && 'sp_team' !== get_post_type( absint( $args['team'] ) ) ) {
			return null;
		}
		return $args;
	}

	/**
	 * Find a term by id or slug.
	 *
	 * @param string $value    Term id or slug.
	 * @param string $taxonomy Taxonomy.
	 * @return object|null
	 */
	private function find_term( $value, $taxonomy ) {
		$term = ctype_digit( (string) $value )
			? get_term( (int) $value, $taxonomy )
			: get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		return ( $term && ! is_wp_error( $term ) ) ? $term : null;
	}

	/**
	 * Copy a Calendar's settings into explicit attributes, for the keys the
	 * arguments have not already set. A league, season or team the Calendar
	 * fixes is only carried over when it is a single value; SportsPress's
	 * attributes cannot express a list.
	 *
	 * @param int   $calendar_id Calendar post id.
	 * @param array $args        Arguments (without id).
	 * @return array
	 */
	private function detach_calendar_settings( $calendar_id, array $args ) {
		$date = get_post_meta( $calendar_id, 'sp_date', true );
		$cols = get_post_meta( $calendar_id, 'sp_columns', true );
		$own  = array(
			'status'  => get_post_meta( $calendar_id, 'sp_status', true ),
			'order'   => get_post_meta( $calendar_id, 'sp_order', true ),
			'number'  => get_post_meta( $calendar_id, 'sp_number', true ),
			'date'    => $date,
			'format'  => get_post_meta( $calendar_id, 'sp_event_format', true ),
			'day'     => get_post_meta( $calendar_id, 'sp_day', true ),
			'columns' => is_array( $cols ) ? implode( ',', $cols ) : '',
		) + $this->calendar_date_settings( $calendar_id, $date );

		foreach ( array(
			'league' => 'sp_league',
			'season' => 'sp_season',
			'venue'  => 'sp_venue',
		) as $key => $taxonomy ) {
			$ids = $this->single_term_ids( $calendar_id, $taxonomy );
			if ( ! array_key_exists( $key, $args ) && 1 === count( $ids ) ) {
				$own[ $key ] = (string) reset( $ids );
			}
		}
		$team = $this->calendar_single_team( $calendar_id );
		if ( ! array_key_exists( 'team', $args ) && '' !== $team ) {
			$own['team'] = $team;
		}

		// Arguments win; anything they leave unset falls back to the Calendar.
		return $args + array_map( 'strval', array_filter( $own, array( $this, 'is_set_value' ), ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * Whether a Calendar value counts as set. '0' and '' are unset, except
	 * for the relative range's past/future days, where 0 is a real value.
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Attribute name.
	 * @return bool
	 */
	private function is_set_value( $value, $key ) {
		if ( in_array( $key, array( 'date_past', 'date_future' ), true ) ) {
			return '' !== (string) $value;
		}
		return (bool) $value;
	}

	/**
	 * The Calendar's date-range attributes (only when its date mode is range).
	 *
	 * @param int    $calendar_id Calendar post id.
	 * @param string $date        The Calendar's date mode.
	 * @return array
	 */
	private function calendar_date_settings( $calendar_id, $date ) {
		if ( 'range' !== $date ) {
			return array();
		}
		if ( get_post_meta( $calendar_id, 'sp_date_relative', true ) ) {
			return array(
				'date_relative' => '1',
				'date_past'     => get_post_meta( $calendar_id, 'sp_date_past', true ),
				'date_future'   => get_post_meta( $calendar_id, 'sp_date_future', true ),
			);
		}
		return array(
			'date_from' => get_post_meta( $calendar_id, 'sp_date_from', true ),
			'date_to'   => get_post_meta( $calendar_id, 'sp_date_to', true ),
		);
	}

	/**
	 * The ids of a Calendar's terms in a taxonomy, including auto terms.
	 *
	 * @param int    $calendar_id Calendar post id.
	 * @param string $taxonomy    Taxonomy.
	 * @return array
	 */
	private function single_term_ids( $calendar_id, $taxonomy ) {
		$ids = array();
		foreach ( (array) get_the_terms( $calendar_id, $taxonomy ) as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$ids[] = $term->term_id;
			}
		}
		return function_exists( 'sp_add_auto_term' ) ? sp_add_auto_term( $ids, $calendar_id, $taxonomy ) : $ids;
	}

	/**
	 * The Calendar's team id when it fixes exactly one, else ''.
	 *
	 * @param int $calendar_id Calendar post id.
	 * @return string
	 */
	private function calendar_single_team( $calendar_id ) {
		$teams = array_filter( (array) get_post_meta( $calendar_id, 'sp_team', false ) );
		return 1 === count( $teams ) ? (string) reset( $teams ) : '';
	}
}
