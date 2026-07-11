<?php
/**
 * Deterministic consistency validation for an extracted score sheet.
 *
 * Runs a battery of pure, DB-free checks over an SPSS_Extraction_Result and
 * appends a flag for every inconsistency found. The review UI surfaces these
 * flags so a human can confirm or correct the extraction before it is written
 * into SportsPress. No WordPress or database calls are made here: everything
 * operates on the data already carried by the result object.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPSS_Consistency_Checker {

	/**
	 * Sane per-player statistic bounds for a hockey game. Anything outside this
	 * range is almost certainly an OCR misread rather than a real value.
	 */
	const MIN_STAT = 0;
	const MAX_STAT = 50;

	/**
	 * Confidence at or below which a missing/illegible field is worth flagging.
	 * field_confidence values are expected to be in the 0..1 range.
	 */
	const LOW_CONFIDENCE = 0.5;

	/**
	 * Run every consistency check against $result.
	 *
	 * Mutates $result by appending a flag for each inconsistency, and returns
	 * just the flags that were added by this call (existing flags are left in
	 * place on the result but excluded from the return value).
	 *
	 * @param SPSS_Extraction_Result $result Extraction result to validate.
	 * @return array List of flag arrays that were appended.
	 */
	public static function check( SPSS_Extraction_Result $result ): array {
		$before = count( $result->flags );

		self::check_score_mismatch( $result );
		self::check_unmatched_jerseys( $result );
		self::check_duplicate_jerseys( $result );
		self::check_illegible_or_missing( $result );
		self::check_out_of_range( $result );

		// Return only the flags this invocation added.
		return array_slice( $result->flags, $before );
	}

	/**
	 * Check 1: score_mismatch.
	 *
	 * For each side, if the team's final_score is known and every player goal on
	 * that side is known, the sum of player goals must equal the final score.
	 */
	private static function check_score_mismatch( SPSS_Extraction_Result $result ) {
		$players = self::players( $result );
		$teams   = isset( $result->data['teams'] ) && is_array( $result->data['teams'] )
			? $result->data['teams']
			: array();

		foreach ( array( 'home', 'away' ) as $side ) {
			$final = $teams[ $side ]['final_score'] ?? null;

			// Nothing to compare against if the final score is unknown.
			if ( is_null( $final ) ) {
				continue;
			}

			$sum          = 0;
			$all_goals_ok = true;

			foreach ( $players as $player ) {
				if ( ( $player['team'] ?? null ) !== $side ) {
					continue;
				}

				$goals = $player['goals'] ?? null;
				if ( is_null( $goals ) ) {
					// Can't trust the sum if any contributing goal is missing.
					$all_goals_ok = false;
					break;
				}

				$sum += (int) $goals;
			}

			if ( $all_goals_ok && $sum !== (int) $final ) {
				$result->add_flag(
					'score_mismatch',
					sprintf( '%s: players sum %d vs final %d', $side, $sum, (int) $final )
				);
			}
		}
	}

	/**
	 * Check 2: unmatched_jersey.
	 *
	 * Any player row that could not be tied to a SportsPress player record needs
	 * a human to resolve it. Flags rows with no matched_player_id, or whose
	 * matched_by is explicitly 'unmatched'.
	 */
	private static function check_unmatched_jerseys( SPSS_Extraction_Result $result ) {
		foreach ( self::players( $result ) as $index => $player ) {
			$matched_id = $player['matched_player_id'] ?? null;
			$matched_by = $player['matched_by'] ?? '';

			if ( is_null( $matched_id ) || 'unmatched' === $matched_by ) {
				$jersey = $player['jersey_written'] ?? '';
				$team   = $player['team'] ?? '';

				$result->add_flag(
					'unmatched_jersey',
					sprintf( '%s jersey "%s" could not be matched to a player', $team, $jersey ),
					$index
				);
			}
		}
	}

	/**
	 * Check 3: duplicate_jersey.
	 *
	 * The same written jersey number appearing more than once on a single team
	 * is a data-entry conflict. Reports the second and subsequent occurrences.
	 */
	private static function check_duplicate_jerseys( SPSS_Extraction_Result $result ) {
		$seen = array();

		foreach ( self::players( $result ) as $index => $player ) {
			$jersey = $player['jersey_written'] ?? null;
			$team   = $player['team'] ?? '';

			// Blank jerseys are handled by the illegible/missing check.
			if ( is_null( $jersey ) || '' === $jersey ) {
				continue;
			}

			$key = $team . '#' . $jersey;

			if ( isset( $seen[ $key ] ) ) {
				$result->add_flag(
					'duplicate_jersey',
					sprintf( '%s jersey "%s" appears more than once', $team, $jersey ),
					$index
				);
			} else {
				$seen[ $key ] = true;
			}
		}
	}

	/**
	 * Check 4: illegible / missing.
	 *
	 * Kept deliberately conservative to avoid flooding the reviewer: only flags a
	 * missing jersey number, or missing goals whose field_confidence is low.
	 */
	private static function check_illegible_or_missing( SPSS_Extraction_Result $result ) {
		foreach ( self::players( $result ) as $index => $player ) {
			$jersey     = $player['jersey_written'] ?? null;
			$goals      = $player['goals'] ?? null;
			$confidence = isset( $player['field_confidence'] ) && is_array( $player['field_confidence'] )
				? $player['field_confidence']
				: array();

			// Missing jersey number.
			if ( is_null( $jersey ) || '' === $jersey ) {
				$result->add_flag(
					'illegible',
					'jersey number missing or illegible',
					$index
				);
				continue;
			}

			// Missing goals, but only when the reader itself was unsure.
			if ( is_null( $goals ) ) {
				$goals_conf = $confidence['goals'] ?? null;
				if ( ! is_null( $goals_conf ) && (float) $goals_conf <= self::LOW_CONFIDENCE ) {
					$result->add_flag(
						'illegible',
						sprintf( 'goals missing with low confidence (%.2f) for jersey "%s"', (float) $goals_conf, $jersey ),
						$index
					);
				}
			}
		}
	}

	/**
	 * Check 5: out_of_range.
	 *
	 * Flags any goals/assists/pim value outside sane hockey bounds, which almost
	 * always indicates a misread digit.
	 */
	private static function check_out_of_range( SPSS_Extraction_Result $result ) {
		foreach ( self::players( $result ) as $index => $player ) {
			foreach ( array( 'goals', 'assists', 'pim' ) as $field ) {
				$value = $player[ $field ] ?? null;

				if ( is_null( $value ) ) {
					continue;
				}

				$value = (int) $value;
				if ( $value < self::MIN_STAT || $value > self::MAX_STAT ) {
					$result->add_flag(
						'out_of_range',
						sprintf( '%s value %d is outside the expected %d-%d range', $field, $value, self::MIN_STAT, self::MAX_STAT ),
						$index
					);
				}
			}
		}
	}

	/**
	 * Normalized accessor for the players list, always returning an array.
	 *
	 * @param SPSS_Extraction_Result $result Extraction result.
	 * @return array List of player rows.
	 */
	private static function players( SPSS_Extraction_Result $result ): array {
		if ( isset( $result->data['players'] ) && is_array( $result->data['players'] ) ) {
			return $result->data['players'];
		}
		return array();
	}
}
