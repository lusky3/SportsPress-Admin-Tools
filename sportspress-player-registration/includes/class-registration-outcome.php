<?php
/**
 * Which registration outcomes mean "the customer is registered".
 *
 * Since player-registration 1.2.1, process_completed_order() sets the
 * `_spr_processed` marker to '1' only when every registration item linked a
 * live player. Orders processed before that are not migrated: there the marker
 * was set whenever no item hit a multiple-players conflict, so a legacy order
 * whose only item failed with season_not_detected or player_creation_failed
 * is still marked '1'. For those the marker alone cannot tell success from
 * failure, so callers that report registration state (the FreeScout attention
 * list and player.rerun) check the newest log row with this helper as well.
 *
 * Not covered: a legacy '1' order whose item matched nobody with auto-create
 * off. Before 1.2.1 that case wrote no log row at all, so nothing here can
 * tell it from a registered order; only "Re-run player registration" (which
 * now logs no_match) brings it to light.
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

	/**
	 * The log action to show for an order: with marker '1', the newest action
	 * (it decides whether a legacy '1' really registered); otherwise the newest
	 * failure, so a partly registered order whose failing item was processed
	 * before a linking one names the failure, falling back to the newest action.
	 * Shared by the FreeScout attention list and the player.rerun preview so the
	 * two agree.
	 *
	 * @param string[] $actions Log actions for the order, newest first.
	 * @param string   $marker  The order's `_spr_processed` value.
	 * @return string '' when there are no actions.
	 */
	public static function shown( array $actions, string $marker ): string {
		$actions = array_values( array_map( 'strval', $actions ) );
		if ( '1' !== $marker ) {
			foreach ( $actions as $action ) {
				if ( ! self::is_success( $action ) ) {
					return $action;
				}
			}
		}
		return $actions[0] ?? '';
	}
}
