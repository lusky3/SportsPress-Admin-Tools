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
	 * @param array $args Merged arguments; '' means the user chose "All".
	 * @return string HTML.
	 */
	private function render_games( array $args ): string {
		$empty = '<p class="arl-schedule-empty">' . esc_html__( 'No games match these filters.', 'sportspress-events-manager' ) . '</p>';

		$calendar_id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		unset( $args['id'] );

		if ( $calendar_id && ( 'sp_calendar' !== get_post_type( $calendar_id ) || 'publish' !== get_post_status( $calendar_id ) ) ) {
			return '<p class="arl-schedule-empty">' . esc_html__( 'Calendar not found.', 'sportspress-events-manager' ) . '</p>';
		}

		$detach = (bool) array_intersect( self::DETACH_KEYS, array_keys( $args ) );

		if ( $detach ) {
			$args = $this->resolve_term_values( $args );
			if ( null === $args ) {
				return $empty; // A named season/division/team does not exist.
			}
			if ( $calendar_id ) {
				$args = $this->detach_calendar_settings( $calendar_id, $args );
			}
		} elseif ( $calendar_id ) {
			$args = array( 'id' => $calendar_id ) + $args;
		}

		$html = do_shortcode( self::build_event_list_shortcode( $args ) );

		// SportsPress prints an empty table body when nothing matches.
		if ( false === strpos( $html, '<tr class="sp-row' ) ) {
			return $empty;
		}
		return $html;
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
			$term = ctype_digit( (string) $args[ $key ] )
				? get_term( (int) $args[ $key ], $taxonomy )
				: get_term_by( 'slug', sanitize_title( $args[ $key ] ), $taxonomy );
			if ( ! $term || is_wp_error( $term ) ) {
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
		$status = get_post_meta( $calendar_id, 'sp_status', true );
		$order  = get_post_meta( $calendar_id, 'sp_order', true );
		$number = get_post_meta( $calendar_id, 'sp_number', true );
		$date   = get_post_meta( $calendar_id, 'sp_date', true );
		$format = get_post_meta( $calendar_id, 'sp_event_format', true );
		$day    = get_post_meta( $calendar_id, 'sp_day', true );
		$cols   = get_post_meta( $calendar_id, 'sp_columns', true );

		$own = array(
			'status' => $status ? $status : '',
			'order'  => $order ? $order : '',
			'number' => $number ? $number : '',
			'date'   => $date ? $date : '',
			'format' => $format ? $format : '',
			'day'    => $day ? $day : '',
		);
		if ( 'range' === $date ) {
			if ( get_post_meta( $calendar_id, 'sp_date_relative', true ) ) {
				$own['date_relative'] = '1';
				$own['date_past']     = (string) get_post_meta( $calendar_id, 'sp_date_past', true );
				$own['date_future']   = (string) get_post_meta( $calendar_id, 'sp_date_future', true );
			} else {
				$own['date_from'] = (string) get_post_meta( $calendar_id, 'sp_date_from', true );
				$own['date_to']   = (string) get_post_meta( $calendar_id, 'sp_date_to', true );
			}
		}
		if ( is_array( $cols ) && $cols ) {
			$own['columns'] = implode( ',', $cols );
		}

		$terms = array(
			'league' => 'sp_league',
			'season' => 'sp_season',
			'venue'  => 'sp_venue',
		);
		foreach ( $terms as $key => $taxonomy ) {
			if ( array_key_exists( $key, $args ) ) {
				continue;
			}
			$ids = array();
			foreach ( (array) get_the_terms( $calendar_id, $taxonomy ) as $term ) {
				if ( $term instanceof WP_Term ) {
					$ids[] = $term->term_id;
				}
			}
			if ( function_exists( 'sp_add_auto_term' ) ) {
				$ids = sp_add_auto_term( $ids, $calendar_id, $taxonomy );
			}
			if ( 1 === count( $ids ) ) {
				$own[ $key ] = (string) reset( $ids );
			}
		}
		if ( ! array_key_exists( 'team', $args ) ) {
			$teams = array_filter( get_post_meta( $calendar_id, 'sp_team', false ) );
			if ( 1 === count( $teams ) ) {
				$own['team'] = (string) reset( $teams );
			}
		}

		// Arguments win; anything they leave unset falls back to the Calendar.
		return $args + array_filter( $own, 'strlen' );
	}
}
