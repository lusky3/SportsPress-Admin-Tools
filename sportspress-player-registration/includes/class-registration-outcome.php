<?php
/**
 * Which registration outcomes mean "the customer is registered".
 *
 * Since player-registration 1.2.1, process_completed_order() sets the
 * `_spr_processed` marker to '1' only when every registration item linked a
 * live player. Orders processed before that are not migrated: there the marker
 * was set whenever no item hit a multiple-players conflict, so a legacy order
 * whose only item failed with season_not_detected or player_creation_failed
 * (or matched nobody with auto-create off) is still marked '1'. For those the
 * marker alone cannot tell success from failure, so callers that report
 * registration state (the FreeScout attention list and player.rerun) check the
 * outcome with this helper as well.
 *
 * @package SportsPress_Player_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPPR_Registration_Outcome {

	/**
	 * Every failure outcome process_completed_order() logs or reports through
	 * spr_registration_outcome. 'failed' is reported (marker 'failed') but not
	 * logged. 'no_match' is an item that matched no player with auto-create off.
	 */
	const FAILURES = array(
		'name_rejected',
		'season_not_detected',
		'player_creation_failed',
		'multiple_players_found_email_conflict',
		'multiple_players_found_name_match_requires_email',
		'failed',
		'no_match',
	);

	/**
	 * Whether an outcome (a log action, or the outcome a run reported) is a
	 * success. '' is a success: process_completed_order() reports nothing on a
	 * full success.
	 *
	 * @param string $outcome Log action or reported outcome.
	 * @return bool
	 */
	public static function is_success( string $outcome ): bool {
		return ! in_array( $outcome, self::FAILURES, true );
	}
}
