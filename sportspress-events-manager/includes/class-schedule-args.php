<?php
/**
 * Argument handling for the [arl_schedule] shortcode.
 *
 * Precedence, lowest to highest: the Calendar setting, the shortcode
 * attribute, then the visitor's dropdown choice. A visitor value of 'all'
 * means "explicitly all": the key stays present with an empty value so the
 * renderer can tell it apart from "not set".
 *
 * Kept free of WordPress calls so it can be tested directly.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPEM_Schedule_Args {

	/**
	 * Attribute keys the shortcode understands.
	 */
	const KNOWN_KEYS = array(
		'id',
		'status',
		'season',
		'league',
		'team',
		'date',
		'date_from',
		'date_to',
		'date_past',
		'date_future',
		'date_relative',
		'number',
		'order',
		'columns',
	);

	/**
	 * Keys that only make sense for the initial, unfiltered view: the date
	 * window. The number limit is kept on visitor changes (it bounds the
	 * list when the window is gone).
	 */
	const INITIAL_ONLY_KEYS = array(
		'date',
		'date_from',
		'date_to',
		'date_past',
		'date_future',
		'date_relative',
	);

	/**
	 * Keys a visitor may change through the dropdowns.
	 */
	const VISITOR_KEYS = array( 'season', 'league', 'team', 'status' );

	/**
	 * Map a friendly or native status to a SportsPress event status.
	 *
	 * @param string $value Raw status.
	 * @return string|null any|future|publish, or null when unrecognised.
	 */
	public static function normalize_status( $value ) {
		$map = array(
			'all'      => 'any',
			'any'      => 'any',
			'upcoming' => 'future',
			'future'   => 'future',
			'results'  => 'publish',
			'publish'  => 'publish',
		);
		$key = strtolower( trim( (string) $value ) );
		return $map[ $key ] ?? null;
	}

	/**
	 * Lower-case keys, alias division to league, normalise status, drop empties.
	 *
	 * @param array $atts Raw shortcode attributes.
	 * @return array
	 */
	public static function normalize_atts( $atts ) {
		$out = array();
		foreach ( (array) $atts as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( 'division' === $key ) {
				$key = 'league';
			}
			if ( ! in_array( $key, self::KNOWN_KEYS, true ) || ! is_scalar( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( 'status' === $key ) {
				$value = (string) self::normalize_status( $value );
			}
			if ( '' === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Overlay visitor choices on attribute values.
	 *
	 * @param array $attrs   Normalised attributes.
	 * @param array $visitor Visitor choices keyed by dropdown name.
	 * @return array
	 */
	public static function merge( $attrs, $visitor ) {
		foreach ( (array) $visitor as $key => $value ) {
			$attrs[ $key ] = 'all' === $value ? '' : $value;
		}
		return $attrs;
	}

	/**
	 * Remove the date window once the visitor has chosen a season or status.
	 *
	 * @param array $args    Merged arguments.
	 * @param array $visitor Visitor choices.
	 * @return array
	 */
	public static function drop_initial_only( $args, $visitor ) {
		if ( ! array_key_exists( 'season', (array) $visitor ) && ! array_key_exists( 'status', (array) $visitor ) ) {
			return $args;
		}
		return array_diff_key( $args, array_flip( self::INITIAL_ONLY_KEYS ) );
	}

	/**
	 * Build a versioned, order-independent cache key.
	 *
	 * @param int   $version Cache version.
	 * @param array $args    Arguments.
	 * @return string
	 */
	public static function cache_key( $version, $args ) {
		ksort( $args );
		return 'spem_schedule_v' . $version . '_' . md5( serialize( $args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
	}

	/**
	 * Whether a visitor may supply this key.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	public static function is_allowed_visitor_key( $key ) {
		return in_array( $key, self::VISITOR_KEYS, true );
	}
}
