<?php
/**
 * Releasing (sending or retrying) a stored manual suspension notice, and the
 * rules that keep a retry consistent with what was first stored: which kind of
 * notice a row is, which games count from which date, and what an amendment
 * replaced.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension_Release {

	/**
	 * Notice kind a stored manual row is mailed as. Pure.
	 *
	 * @param object $row Notice row.
	 * @return string issued|decided|amended|revoked.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function release_kind( object $row ): string {
		return SPLM_Discipline_Suspension_Context::kind_for_scope( (string) ( $row->scope ?? '' ) );
	}

	/**
	 * Release (send or retry) a manual row. Called by the notice release route
	 * while it holds that notice's lock.
	 *
	 * @param object $row Manual notice row, status already checked pending/failed.
	 * @return WP_REST_Response|WP_Error Same shapes as the automatic release.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function release_row( object $row ) {
		$id     = (int) $row->id;
		$ctx    = SPLM_Discipline_Suspension_Context::for_row( $row, self::release_extra( $row ) );
		$result = SPLM_Discipline_Suspension::deliver( $id, $ctx, self::release_kind( $row ) );

		return self::release_response( $id, $result );
	}

	/**
	 * Context extras for a release: the schedule shortfall is recomputed (it
	 * may have changed since the draft) but only for a games outcome, and an
	 * amendment carries the length it replaced so a retry still reads
	 * "changed from X to Y".
	 *
	 * @param object $row Manual notice row.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function release_extra( object $row ): array {
		$scope = (string) ( $row->scope ?? '' );
		$extra = array( 'team_names' => (string) $row->team );
		$extra = array_merge( $extra, self::prior_games_extra( $scope, self::parent_of( $row ) ) );

		if ( 'games' === (string) $row->outcome ) {
			$elig = SPLM_Discipline_Eligibility::next_eligible(
				(int) $row->player_id,
				(int) $row->season_id,
				self::count_from( $row ),
				(int) $row->games
			);

			$extra['remaining'] = (int) ( $elig['remaining'] ?? 0 );
		}

		return $extra;
	}

	/**
	 * The parent of an amended row, or null.
	 *
	 * @param object $row Manual notice row.
	 * @return object|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function parent_of( object $row ): ?object {
		$parent_id = (int) ( $row->parent_id ?? 0 );
		if ( 'manual-amended' !== (string) ( $row->scope ?? '' ) || $parent_id <= 0 ) {
			return null;
		}

		$parent = SPLM_Discipline_Notice_Database::find( $parent_id );

		return $parent ? $parent : null;
	}

	/**
	 * The prior_games context key for an amended row. Pure.
	 *
	 * @param string      $scope  Row scope.
	 * @param object|null $parent Parent row (amended rows only).
	 * @return array Empty, or array( 'prior_games' => int ).
	 */
	public static function prior_games_extra( string $scope, ?object $parent ): array {
		if ( 'manual-amended' !== $scope || null === $parent ) {
			return array();
		}

		return array( 'prior_games' => (int) $parent->games );
	}

	/**
	 * Date a row's games are counted from. Must match how decide, amend and
	 * recalculate compute eligible_on, so a retried release agrees with it: a
	 * decision counts from the day it was made (an indefinite suspension's games
	 * start then); issued and amended rows count from the incident. Pure.
	 *
	 * @param string $scope         Scope of the anchor row (see count_from()).
	 * @param string $incident_date 'Y-m-d' of the incident match, or '' when none.
	 * @param string $decision_date 'Y-m-d' (site-local) the decision row was created, or ''.
	 * @param string $today         'Y-m-d' local today, the fallback for a missing date.
	 * @return string
	 */
	public static function count_from_date( string $scope, string $incident_date, string $decision_date, string $today ): string {
		$date = 'manual-decided' === $scope ? $decision_date : $incident_date;

		return '' === $date ? $today : $date;
	}

	/**
	 * The count-from date for a stored row. An amendment counts from wherever
	 * the row it replaced did, so the chain is followed back to its anchor.
	 *
	 * @param object $row Manual notice row.
	 * @return string 'Y-m-d'.
	 */
	public static function count_from( object $row ): string {
		$anchor  = self::anchor_row( $row );
		$created = (string) ( $anchor->created_at ?? '' );

		return self::count_from_date(
			(string) ( $anchor->scope ?? '' ),
			SPLM_Discipline_Suspension_REST::after_date( (int) ( $anchor->incident_event_id ?? 0 ) ),
			'' === $created ? '' : get_date_from_gmt( $created, 'Y-m-d' ),
			current_time( 'Y-m-d' )
		);
	}

	/**
	 * The first row of an amendment chain (the row itself when not amended).
	 *
	 * @param object $row Manual notice row.
	 * @return object
	 */
	private static function anchor_row( object $row ): object {
		for ( $hops = 0; $hops < 10; $hops++ ) {
			$parent = self::parent_of( $row );
			if ( null === $parent ) {
				break;
			}
			$row = $parent;
		}

		return $row;
	}

	/**
	 * Map a deliver() result onto the notice release route's responses.
	 *
	 * @param int   $id     Notice id.
	 * @param array $result SPLM_Discipline_Suspension::deliver() result.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function release_response( int $id, array $result ) {
		if ( 'status' === ( $result['skipped'] ?? '' ) ) {
			return new WP_Error( 'splm_notice_not_releasable', __( 'Only a pending or failed notice can be released.', 'sportspress-league-manager' ), array( 'status' => 409 ) );
		}

		if ( ! $result['sent'] ) {
			$fresh = SPLM_Discipline_Notice_Database::find( $id );

			return new WP_Error(
				'splm_notice_send_failed',
				$fresh && $fresh->last_error ? (string) $fresh->last_error : __( 'The notice could not be sent.', 'sportspress-league-manager' ),
				array( 'status' => 500 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_SENT,
			),
			200
		);
	}
}
