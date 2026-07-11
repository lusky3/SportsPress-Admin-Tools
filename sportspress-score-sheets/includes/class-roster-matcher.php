<?php
/**
 * Deterministic roster matching for an extracted score sheet.
 *
 * Resolves jersey numbers (and, as a fallback, player names) to SportsPress
 * roster player_ids across the players, scoring, and penalties lists carried by
 * an SPSS_Extraction_Result, and derives each player's penalty-minute total from
 * the penalties list. The model's own matching is deliberately NOT trusted: this
 * class recomputes every identity from the supplied rosters so the result is
 * reproducible and provider-agnostic. No WordPress or database calls are made:
 * everything operates on the data already carried by the result object plus the
 * rosters passed in.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Roster_Matcher {

	/**
	 * Resolve jerseys -> roster player_ids across players/scoring/penalties, and
	 * derive per-player pim from penalties. Mutates $result->data in place.
	 *
	 * @param SPSS_Extraction_Result $result  Extraction result to enrich.
	 * @param array                  $rosters ['home'=>[['player_id'=>int,'name'=>string,'number'=>string],...],'away'=>[...]]
	 * @return void
	 */
	public static function match( SPSS_Extraction_Result $result, array $rosters ): void {
		$indexes = array(
			'home' => self::build_indexes( $rosters['home'] ?? array() ),
			'away' => self::build_indexes( $rosters['away'] ?? array() ),
		);

		self::match_players( $result, $indexes );
		self::match_scoring( $result, $indexes );
		self::match_penalties( $result, $indexes );
		self::derive_pim( $result );
	}

	/**
	 * Build a number-index and name-index for one side's roster.
	 *
	 * @param array $roster List of ['player_id'=>int,'name'=>string,'number'=>string].
	 * @return array ['number'=>[normalized_number=>player_id], 'name'=>[normalized_name=>player_id]].
	 */
	private static function build_indexes( $roster ): array {
		$by_number = array();
		$by_name   = array();

		if ( ! is_array( $roster ) ) {
			return array(
				'number' => $by_number,
				'name'   => $by_name,
			);
		}

		// First pass: count how many roster players carry each normalized number
		// so we can tell a unique number from a collision.
		$number_counts = array();
		foreach ( $roster as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['player_id'] ) ) {
				continue;
			}
			$number_key = self::normalize_number( $entry['number'] ?? null );
			if ( '' !== $number_key ) {
				$number_counts[ $number_key ] = ( $number_counts[ $number_key ] ?? 0 ) + 1;
			}
		}

		// Second pass: index only numbers that are unique on this roster. A number
		// shared by two or more roster players is ambiguous, so we deliberately
		// leave it out of by_number — a sheet entry for that number falls through
		// to name matching (or stays unmatched) rather than being mis-attributed
		// to whichever player happened to be listed first.
		foreach ( $roster as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['player_id'] ) ) {
				continue;
			}

			$player_id = (int) $entry['player_id'];

			$number_key = self::normalize_number( $entry['number'] ?? null );
			if ( '' !== $number_key
				&& 1 === ( $number_counts[ $number_key ] ?? 0 )
				&& ! isset( $by_number[ $number_key ] ) ) {
				$by_number[ $number_key ] = $player_id;
			}

			$name_key = self::normalize_name( $entry['name'] ?? null );
			if ( '' !== $name_key && ! isset( $by_name[ $name_key ] ) ) {
				$by_name[ $name_key ] = $player_id;
			}
		}

		return array(
			'number' => $by_number,
			'name'   => $by_name,
		);
	}

	/**
	 * Resolve every players[] row to a roster identity, overwriting whatever the
	 * model provided. Number match wins over name match; otherwise unmatched.
	 *
	 * @param SPSS_Extraction_Result $result  Extraction result.
	 * @param array                  $indexes Per-side number/name indexes.
	 * @return void
	 */
	private static function match_players( SPSS_Extraction_Result $result, array $indexes ) {
		if ( ! isset( $result->data['players'] ) || ! is_array( $result->data['players'] ) ) {
			return;
		}

		foreach ( $result->data['players'] as &$player ) {
			if ( ! is_array( $player ) ) {
				continue;
			}

			$side = $player['team'] ?? null;
			$idx  = $indexes[ $side ] ?? null;

			$matched_id = null;
			$matched_by = 'unmatched';

			if ( is_array( $idx ) ) {
				$number_key = self::normalize_number( $player['jersey_written'] ?? null );
				if ( '' !== $number_key && isset( $idx['number'][ $number_key ] ) ) {
					$matched_id = $idx['number'][ $number_key ];
					$matched_by = 'roster_number';
				} else {
					$name_key = self::normalize_name( $player['player_name'] ?? null );
					if ( '' !== $name_key && isset( $idx['name'][ $name_key ] ) ) {
						$matched_id = $idx['name'][ $name_key ];
						$matched_by = 'roster_name';
					}
				}
			}

			$player['matched_player_id'] = $matched_id;
			$player['matched_by']        = $matched_by;
		}
		unset( $player );
	}

	/**
	 * Resolve scorer/assist jerseys on every scoring[] row via that row's team
	 * number-index. Adds scorer_player_id, assist1_player_id, assist2_player_id.
	 *
	 * @param SPSS_Extraction_Result $result  Extraction result.
	 * @param array                  $indexes Per-side number/name indexes.
	 * @return void
	 */
	private static function match_scoring( SPSS_Extraction_Result $result, array $indexes ) {
		if ( ! isset( $result->data['scoring'] ) || ! is_array( $result->data['scoring'] ) ) {
			return;
		}

		foreach ( $result->data['scoring'] as &$goal ) {
			if ( ! is_array( $goal ) ) {
				continue;
			}

			$side    = $goal['team'] ?? null;
			$numbers = $indexes[ $side ]['number'] ?? array();

			$goal['scorer_player_id']  = self::lookup_number( $numbers, $goal['scorer_jersey'] ?? null );
			$goal['assist1_player_id'] = self::lookup_number( $numbers, $goal['assist1_jersey'] ?? null );
			$goal['assist2_player_id'] = self::lookup_number( $numbers, $goal['assist2_jersey'] ?? null );
		}
		unset( $goal );
	}

	/**
	 * Resolve the jersey on every penalties[] row via that row's team
	 * number-index. Adds player_id (null when blank/unmatched).
	 *
	 * @param SPSS_Extraction_Result $result  Extraction result.
	 * @param array                  $indexes Per-side number/name indexes.
	 * @return void
	 */
	private static function match_penalties( SPSS_Extraction_Result $result, array $indexes ) {
		if ( ! isset( $result->data['penalties'] ) || ! is_array( $result->data['penalties'] ) ) {
			return;
		}

		foreach ( $result->data['penalties'] as &$penalty ) {
			if ( ! is_array( $penalty ) ) {
				continue;
			}

			$side    = $penalty['team'] ?? null;
			$numbers = $indexes[ $side ]['number'] ?? array();

			$penalty['player_id'] = self::lookup_number( $numbers, $penalty['jersey'] ?? null );
		}
		unset( $penalty );
	}

	/**
	 * Derive each matched player's pim as the sum of penalties[] lengths that
	 * belong to them (by resolved player_id, or by matching team + normalized
	 * jersey number). Players with no penalties keep their existing pim.
	 *
	 * @param SPSS_Extraction_Result $result Extraction result.
	 * @return void
	 */
	private static function derive_pim( SPSS_Extraction_Result $result ) {
		if ( ! isset( $result->data['players'] ) || ! is_array( $result->data['players'] ) ) {
			return;
		}

		$penalties = isset( $result->data['penalties'] ) && is_array( $result->data['penalties'] )
			? $result->data['penalties']
			: array();

		if ( empty( $penalties ) ) {
			return;
		}

		foreach ( $result->data['players'] as &$player ) {
			if ( ! is_array( $player ) ) {
				continue;
			}

			// Only players with a resolved identity accrue derived penalty minutes.
			$player_id = $player['matched_player_id'] ?? null;
			if ( is_null( $player_id ) ) {
				continue;
			}
			$player_id = (int) $player_id;

			$player_side   = $player['team'] ?? null;
			$player_number = self::normalize_number( $player['jersey_written'] ?? null );

			$total   = 0;
			$matched = false;

			foreach ( $penalties as $penalty ) {
				if ( ! is_array( $penalty ) ) {
					continue;
				}

				$belongs = false;

				$pen_id = $penalty['player_id'] ?? null;
				if ( ! is_null( $pen_id ) && (int) $pen_id === $player_id ) {
					$belongs = true;
				} elseif (
					null !== $player_side
					&& ( $penalty['team'] ?? null ) === $player_side
					&& '' !== $player_number
					&& self::normalize_number( $penalty['jersey'] ?? null ) === $player_number
				) {
					$belongs = true;
				}

				if ( $belongs ) {
					$matched = true;
					$length  = $penalty['length'] ?? null;
					$total  += is_null( $length ) ? 0 : (int) $length;
				}
			}

			// Only override pim when this player actually has penalties.
			if ( $matched ) {
				$player['pim'] = $total;
			}
		}
		unset( $player );
	}

	/**
	 * Look up a jersey value in a number-index, returning a player_id or null.
	 *
	 * @param array $numbers Normalized-number => player_id map.
	 * @param mixed $jersey  Raw jersey value.
	 * @return int|null
	 */
	private static function lookup_number( array $numbers, $jersey ) {
		$key = self::normalize_number( $jersey );
		if ( '' === $key || ! isset( $numbers[ $key ] ) ) {
			return null;
		}
		return (int) $numbers[ $key ];
	}

	/**
	 * Normalize a jersey/number: cast to string, trim, strip non-digits, then
	 * strip leading zeros so leading-zero variants compare equal (e.g. "#07 " ->
	 * "7", "07" -> "7"). A single bare zero is preserved ("00" -> "0", "0" ->
	 * "0"). Returns '' when nothing usable remains.
	 *
	 * @param mixed $value Raw jersey/number.
	 * @return string
	 */
	private static function normalize_number( $value ): string {
		if ( is_null( $value ) ) {
			return '';
		}
		$digits = preg_replace( '/\D/', '', trim( (string) $value ) );
		if ( '' === $digits ) {
			return '';
		}
		$digits = ltrim( $digits, '0' );
		return '' === $digits ? '0' : $digits;
	}

	/**
	 * Normalize a name: lowercase, remove anything except a-z0-9.
	 * e.g. "Kevin Fox (C)" -> "kevinfoxc". Returns '' when nothing usable remains.
	 *
	 * @param mixed $value Raw name.
	 * @return string
	 */
	private static function normalize_name( $value ): string {
		if ( is_null( $value ) ) {
			return '';
		}
		return preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $value ) );
	}
}
