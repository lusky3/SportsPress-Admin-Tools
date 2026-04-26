<?php
/**
 * Name Matching Helper Class
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPET_Name_Matcher {

	private static $equivalent_names_cache = null;

	/**
	 * Check if two names are equivalent (exact match or similar names)
	 */
	public static function names_match( $name1, $name2 ) {
		$name1 = trim( $name1 );
		$name2 = trim( $name2 );

		// Exact match (case-insensitive)
		if ( strcasecmp( $name1, $name2 ) === 0 ) {
			return true;
		}

		// Check if names are equivalent based on settings
		return self::are_names_equivalent( $name1, $name2 );
	}

	/**
	 * Check if two names are equivalent based on the equivalent names list
	 */
	private static function are_names_equivalent( $name1, $name2 ) {
		$equivalent_groups = self::get_equivalent_names();

		// Normalize names for comparison
		$name1_parts = array_values( self::normalize_name( $name1 ) );
		$name2_parts = array_values( self::normalize_name( $name2 ) );

		// Both names must have at least one part
		if ( empty( $name1_parts ) || empty( $name2_parts ) ) {
			return false;
		}

		// Warn when part counts differ by more than 1
		if ( abs( count( $name1_parts ) - count( $name2_parts ) ) > 1 ) {
			error_log( 'SPET Name Matcher: Part count differs by more than 1 - "' . $name1 . '" vs "' . $name2 . '"' );
		}

		// Compare first parts (first names)
		$first1 = $name1_parts[0];
		$first2 = $name2_parts[0];
		if ( ! self::parts_are_equivalent( $first1, $first2, $equivalent_groups ) ) {
			return false;
		}

		// Compare last parts (last names) - must match exactly or be equivalent
		$last1 = end( $name1_parts );
		$last2 = end( $name2_parts );
		if ( ! self::parts_are_equivalent( $last1, $last2, $equivalent_groups ) ) {
			return false;
		}

		// When middle names exist in both names but differ, return false
		if ( count( $name1_parts ) > 2 && count( $name2_parts ) > 2 ) {
			$middle1 = array_slice( $name1_parts, 1, -1 );
			$middle2 = array_slice( $name2_parts, 1, -1 );
			if ( count( $middle1 ) === count( $middle2 ) ) {
				for ( $i = 0; $i < count( $middle1 ); $i++ ) {
					if ( ! self::parts_are_equivalent( $middle1[ $i ], $middle2[ $i ], $equivalent_groups ) ) {
						return false;
					}
				}
			} else {
				return false;
			}
		}

		return true;
	}

	/**
	 * Normalize a name into parts (first, middle, last)
	 */
	private static function normalize_name( $name ) {
		$name = strtolower( trim( $name ) );
		$parts = preg_split( '/\s+/', $name );
		return array_filter( $parts );
	}

	/**
	 * Check if two name parts are equivalent
	 */
	private static function parts_are_equivalent( $part1, $part2, $equivalent_groups ) {
		$part1 = strtolower( $part1 );
		$part2 = strtolower( $part2 );

		// Exact match
		if ( $part1 === $part2 ) {
			return true;
		}

		// Check if both parts are in the same equivalence group
		foreach ( $equivalent_groups as $group ) {
			$in_group_1 = in_array( $part1, $group );
			$in_group_2 = in_array( $part2, $group );

			if ( $in_group_1 && $in_group_2 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get equivalent names from settings and parse into groups
	 */
	private static function get_equivalent_names() {
		if ( self::$equivalent_names_cache !== null ) {
			return self::$equivalent_names_cache;
		}

		$equivalent_names_text = get_option( 'spet_equivalent_names', '' );
		$lines = explode( "\n", $equivalent_names_text );
		$groups = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );

			// Skip empty lines and comments
			if ( empty( $line ) || strpos( $line, '#' ) === 0 ) {
				continue;
			}

			// Split by pipe character
			$names = explode( '|', $line );
			$names = array_map( 'trim', $names );
			$names = array_filter( $names );

			// Validate and sanitize each name
			$valid_names = array();
			foreach ( $names as $name ) {
				// Only allow letters, spaces, hyphens, and apostrophes
				if ( preg_match( '/^[a-zA-Z\s\-\']+$/', $name ) && strlen( $name ) <= 50 ) {
					$valid_names[] = strtolower( $name );
				}
			}

			// Only add groups with at least 2 valid names
			if ( count( $valid_names ) > 1 ) {
				$groups[] = array_unique( $valid_names );
			}
		}

		self::$equivalent_names_cache = $groups;
		return $groups;
	}

	/**
	 * Clear the cache (useful after settings update)
	 */
	public static function clear_cache() {
		self::$equivalent_names_cache = null;
	}
}
