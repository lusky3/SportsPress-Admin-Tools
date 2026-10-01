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
		$id   = (int) $row->id;
		$elig = self::release_eligibility( $row );

		// The stored date is refreshed first, so the email and the row agree.
		if ( null !== $elig ) {
			if ( ! SPLM_Discipline_Notice_Database::update( $id, array( 'eligible_on' => $elig['eligible_on'] ) ) ) {
				return new WP_Error( 'splm_notice_write_failed', __( 'Could not save the suspension.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
			}
			$row              = clone $row;
			$row->eligible_on = $elig['eligible_on'];
		}

		$ctx    = SPLM_Discipline_Suspension_Context::for_row( $row, self::release_extra( $row, $elig ) );
		$result = SPLM_Discipline_Suspension::deliver( $id, $ctx, self::release_kind( $row ) );

		return self::release_response( $id, $result );
	}

	/**
	 * Eligibility recomputed at release time (the schedule may have changed
	 * since the draft), for a games outcome only.
	 *
	 * @param object $row Manual notice row.
	 * @return array|null eligible_on, remaining; null for an indefinite outcome.
	 */
	private static function release_eligibility( object $row ): ?array {
		if ( 'games' !== (string) $row->outcome ) {
			return null;
		}

		return self::eligibility_for( $row, self::count_from( $row, true ), (int) $row->games );
	}

	/**
	 * Eligibility date and schedule shortfall for a games count. A 0-game
	 * suspension carries no date.
	 *
	 * @param object $row   Notice row (player and season).
	 * @param string $after Local 'Y-m-d' games count from.
	 * @param int    $games Games.
	 * @return array eligible_on (string|null), remaining (int).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function eligibility_for( object $row, string $after, int $games ): array {
		$elig = SPLM_Discipline_Eligibility::next_eligible( (int) $row->player_id, (int) $row->season_id, $after, $games );

		return array(
			'eligible_on' => ( $games > 0 && ! empty( $elig['date'] ) ) ? substr( (string) $elig['date'], 0, 10 ) : null,
			'remaining'   => (int) ( $elig['remaining'] ?? 0 ),
		);
	}

	/**
	 * Context extras for a release: the team names, the schedule shortfall, and
	 * for an amendment the length it replaced so a retry still reads "changed
	 * from X to Y".
	 *
	 * @param object     $row  Manual notice row.
	 * @param array|null $elig release_eligibility() result.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function release_extra( object $row, ?array $elig ): array {
		$scope = (string) ( $row->scope ?? '' );
		$extra = array( 'team_names' => (string) $row->team );
		$extra = array_merge( $extra, self::prior_games_extra( $scope, self::parent_of( $row ) ) );

		if ( null !== $elig ) {
			$extra['remaining'] = $elig['remaining'];
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
	 * Date a row's games are counted from. Must match how decide, amend,
	 * recalculate and release compute eligible_on. A decision counts from the
	 * day it was made (an indefinite suspension's games start then); issued and
	 * amended rows count from the incident, or, when there is no valid incident,
	 * from the day the root suspension was issued, so amending or recalculating
	 * later never moves the start forward. Pure.
	 *
	 * @param string $scope         Scope of the anchor row (see count_from()).
	 * @param string $incident_date 'Y-m-d' of the incident match, or '' when none.
	 * @param string $decision_date 'Y-m-d' (site-local) the decision row was created, or ''.
	 * @param string $issued_date   'Y-m-d' (site-local) the root suspension was sent (else created), or ''.
	 * @param string $today         'Y-m-d' local today, the fallback for a missing date.
	 * @return string
	 */
	public static function count_from_date( string $scope, string $incident_date, string $decision_date, string $issued_date, string $today ): string {
		$issued = '' !== $incident_date ? $incident_date : $issued_date;
		$date   = 'manual-decided' === $scope ? $decision_date : $issued;

		return '' === $date ? $today : $date;
	}

	/**
	 * The count-from date for a stored row. An amendment counts from wherever
	 * the row it replaced did, so the chain is followed back to its anchor.
	 *
	 * @param object $row         Manual notice row.
	 * @param bool   $issuing_now The row is being released now: an unsent root has no
	 *                            issued date yet, so it counts from today.
	 * @return string 'Y-m-d'.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function count_from( object $row, bool $issuing_now = false ): string {
		$anchor  = self::anchor_row( $row );
		$created = self::local_date( (string) ( $anchor->created_at ?? '' ) );
		$sent    = self::local_date( (string) ( $anchor->sent_at ?? '' ) );

		return self::count_from_date(
			(string) ( $anchor->scope ?? '' ),
			SPLM_Discipline_Suspension_REST::incident_date( (int) ( $anchor->incident_event_id ?? 0 ) ),
			$created,
			'' !== $sent || $issuing_now ? $sent : $created,
			current_time( 'Y-m-d' )
		);
	}

	/**
	 * A stored UTC datetime as the site-local 'Y-m-d', or '' when empty.
	 *
	 * @param string $gmt 'Y-m-d H:i:s' in UTC.
	 * @return string
	 */
	private static function local_date( string $gmt ): string {
		return '' === $gmt ? '' : get_date_from_gmt( $gmt, 'Y-m-d' );
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
