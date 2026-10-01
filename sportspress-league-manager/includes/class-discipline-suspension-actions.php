<?php
/**
 * The per-notice actions on a manual suspension: decide, amend, revoke and
 * recalculate.
 *
 * Every action takes the notice's lock, re-reads the parent inside it, runs a
 * pure precondition planner, and only then writes. The routes themselves are
 * registered by SPLM_Discipline_Suspension_REST; its callbacks point here.
 *
 * @author Cody (lusky3)
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension_Actions {

	/**
	 * First reason an action cannot touch a parent row, or ''. Pure.
	 *
	 * @param object|null $parent Parent row, null when the id matched nothing.
	 * @return string Error code.
	 */
	public static function parent_error( ?object $parent ): string {
		if ( null === $parent ) {
			return 'splm_notice_not_found';
		}

		return 'manual' === (string) ( $parent->source ?? 'auto' ) ? '' : 'splm_suspension_not_manual';
	}

	/**
	 * Whether a decision may be made on this parent. Pure.
	 *
	 * @param object|null $parent    Parent row.
	 * @param bool        $has_child A non-discarded child already exists.
	 * @return string Error code, or ''.
	 */
	public static function decide_error( ?object $parent, bool $has_child ): string {
		$error = self::parent_error( $parent );
		if ( '' !== $error ) {
			return $error;
		}
		if ( 'indefinite' !== (string) $parent->outcome || ! in_array( (string) $parent->status, array( 'sent', 'served' ), true ) ) {
			return 'splm_suspension_not_decidable';
		}

		return $has_child ? 'splm_suspension_already_decided' : '';
	}

	/**
	 * Whether this parent may be amended to $games. Pure.
	 *
	 * @param object|null $parent    Parent row.
	 * @param int         $games     Requested length.
	 * @param bool        $has_child A non-discarded child already exists.
	 * @return string Error code, or ''.
	 */
	public static function amend_error( ?object $parent, int $games, bool $has_child ): string {
		$error = self::parent_error( $parent );
		if ( '' !== $error ) {
			return $error;
		}
		if ( 'games' !== (string) $parent->outcome || 'sent' !== (string) $parent->status ) {
			return 'splm_suspension_not_amendable';
		}
		if ( (int) $parent->games === $games ) {
			return 'splm_suspension_unchanged';
		}

		return $has_child ? 'splm_suspension_already_amended' : '';
	}

	/**
	 * Whether this parent may be revoked (pending and failed drafts are
	 * discarded instead, a sent notice is revoked). Pure.
	 *
	 * @param object|null $parent Parent row.
	 * @return string Error code, or ''.
	 */
	public static function revoke_error( ?object $parent ): string {
		$error = self::parent_error( $parent );
		if ( '' !== $error ) {
			return $error;
		}

		return in_array( (string) $parent->status, array( 'pending', 'failed', 'sent' ), true ) ? '' : 'splm_suspension_not_revocable';
	}

	/**
	 * Whether this parent's eligibility date may be recomputed. Pure.
	 *
	 * @param object|null $parent Parent row.
	 * @return string Error code, or ''.
	 */
	public static function recalculate_error( ?object $parent ): string {
		$error = self::parent_error( $parent );
		if ( '' !== $error ) {
			return $error;
		}
		if ( 'games' !== (string) $parent->outcome || ! in_array( (string) $parent->status, array( 'pending', 'sent' ), true ) ) {
			return 'splm_suspension_not_recalculable';
		}

		return '';
	}

	/**
	 * Whether a non-discarded child of a parent exists. A discarded child never
	 * took effect. Pure.
	 *
	 * @param object[] $rows      The player's rows.
	 * @param int      $parent_id Parent id.
	 * @return bool
	 */
	public static function live_child_exists( array $rows, int $parent_id ): bool {
		foreach ( $rows as $row ) {
			if ( (int) ( $row->parent_id ?? 0 ) === $parent_id && 'discarded' !== (string) ( $row->status ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * An action route's precondition failure as a WP_Error.
	 *
	 * @param string $code One of the *_error() codes.
	 * @return WP_Error
	 */
	public static function action_error( string $code ): WP_Error {
		$messages = array(
			'splm_notice_not_found'           => __( 'Notice not found.', 'sportspress-league-manager' ),
			'splm_suspension_not_manual'      => __( 'Only a manually issued suspension can be changed here.', 'sportspress-league-manager' ),
			'splm_suspension_not_decidable'   => __( 'Only a sent or served indefinite suspension can be decided.', 'sportspress-league-manager' ),
			'splm_suspension_already_decided' => __( 'That suspension has already been decided.', 'sportspress-league-manager' ),
			'splm_suspension_not_amendable'   => __( 'Only a sent suspension with a set length can be amended.', 'sportspress-league-manager' ),
			'splm_suspension_unchanged'       => __( 'That is already the suspension’s length.', 'sportspress-league-manager' ),
			'splm_suspension_already_amended' => __( 'That suspension has already been amended.', 'sportspress-league-manager' ),
			'splm_suspension_not_revocable'   => __( 'Only a draft or sent suspension can be revoked.', 'sportspress-league-manager' ),
			'splm_suspension_not_recalculable' => __( 'Only a pending or sent suspension with a set length can be recalculated.', 'sportspress-league-manager' ),
		);
		$statuses = array(
			'splm_notice_not_found'     => 404,
			'splm_suspension_unchanged' => 400,
		);

		return new WP_Error( $code, $messages[ $code ] ?? '', array( 'status' => $statuses[ $code ] ?? 409 ) );
	}

	/**
	 * Run a callback holding the per-notice lock. Missing lock class -> 503,
	 * busy -> 409; otherwise whatever the callback returned.
	 *
	 * @param int      $id Notice id.
	 * @param callable $fn Callback.
	 * @return mixed
	 */
	public static function with_notice_lock( int $id, callable $fn ) {
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return new WP_Error( 'splm_no_lock', __( 'Cannot change this safely without the parent plugin’s lock.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}

		$result = SPAT_Lock::with( 'splm_discipline_notice_' . $id, 60, $fn );
		if ( false === $result ) {
			return new WP_Error( 'splm_notice_busy', __( 'That notice is already being processed.', 'sportspress-league-manager' ), array( 'status' => 409 ) );
		}

		return $result;
	}

	/**
	 * Mail a stored notice under its own lock (deliver()'s documented
	 * contract), so a Release click cannot double-send it.
	 *
	 * @param int    $id   Notice id.
	 * @param array  $ctx  Body context.
	 * @param string $kind issued|decided|amended|revoked.
	 * @return array|WP_Error deliver() result, or a 409/503.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public static function deliver_locked( int $id, array $ctx, string $kind ) {
		return self::with_notice_lock(
			$id,
			static function () use ( $id, $ctx, $kind ) {
				return SPLM_Discipline_Suspension::deliver( $id, $ctx, $kind );
			}
		);
	}

	/**
	 * The parent row, re-read (callers hold its lock).
	 *
	 * @param int $id Notice id.
	 * @return object|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function find_parent( int $id ): ?object {
		$row = SPLM_Discipline_Notice_Database::find( $id );

		return $row ? $row : null;
	}

	/**
	 * Whether the parent already has a non-discarded child.
	 *
	 * @param object|null $parent Parent row.
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function has_live_child( ?object $parent ): bool {
		return null !== $parent && self::live_child_exists( SPLM_Discipline_Notice_Database::for_player( (int) $parent->player_id, false ), (int) $parent->id );
	}

	/**
	 * Eligibility date and schedule shortfall for a games count. A 0-game
	 * suspension carries no date.
	 *
	 * @param object $parent Parent row (player and season).
	 * @param string $after  Local 'Y-m-d' games count from.
	 * @param int    $games  Games.
	 * @return array eligible_on (string|null), remaining (int).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function eligibility_for( object $parent, string $after, int $games ): array {
		$elig = SPLM_Discipline_Eligibility::next_eligible( (int) $parent->player_id, (int) $parent->season_id, $after, $games );

		return array(
			'eligible_on' => ( $games > 0 && ! empty( $elig['date'] ) ) ? substr( (string) $elig['date'], 0, 10 ) : null,
			'remaining'   => (int) ( $elig['remaining'] ?? 0 ),
		);
	}

	/**
	 * A delivery result for an action that mailed nothing.
	 *
	 * @return array
	 */
	private static function no_delivery(): array {
		return array(
			'sent'     => false,
			'captains' => array(),
		);
	}

	/**
	 * The 200 every action returns.
	 *
	 * @param int   $notice_id Row shown to the modal, re-read.
	 * @param array $delivery  deliver() result, or no_delivery().
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function action_response( int $notice_id, array $delivery ) {
		$fresh = SPLM_Discipline_Notice_Database::find( $notice_id );
		if ( ! $fresh ) {
			return self::action_error( 'splm_notice_not_found' );
		}

		return new WP_REST_Response(
			array(
				'notice'   => SPLM_Discipline_Notice_REST::row_to_response( $fresh, true ),
				'sent'     => (bool) $delivery['sent'],
				'captains' => $delivery['captains'],
			),
			200
		);
	}

	/**
	 * A 500 for a failed insert.
	 *
	 * @return WP_Error
	 */
	private static function write_failed(): WP_Error {
		return new WP_Error( 'splm_notice_write_failed', __( 'Could not save the suspension.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
	}

	/**
	 * Mail an inserted child under its own lock.
	 *
	 * @param int    $id    Child id.
	 * @param array  $child Child row as inserted.
	 * @param string $kind  decided|amended|revoked.
	 * @param array  $extra Context extras (remaining, prior_games).
	 * @return array|WP_Error deliver() result, or a 409/503.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function deliver_child( int $id, array $child, string $kind, array $extra ) {
		$ctx = SPLM_Discipline_Suspension_Context::for_row(
			(object) array_merge( $child, array( 'id' => $id ) ),
			array_merge( array( 'team_names' => (string) $child['team'] ), $extra )
		);

		return self::deliver_locked( $id, $ctx, $kind );
	}

	/**
	 * Insert a decided or amended child, mail it, and answer with it.
	 *
	 * @param array  $child Child row from build_child_row().
	 * @param string $kind  decided|amended.
	 * @param array  $extra Context extras.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function issue_child( array $child, string $kind, array $extra ) {
		$id = SPLM_Discipline_Notice_Database::insert( $child );
		if ( $id <= 0 ) {
			return self::write_failed();
		}

		$delivery = self::deliver_child( $id, $child, $kind, $extra );

		return is_wp_error( $delivery ) ? $delivery : self::action_response( $id, $delivery );
	}

	/**
	 * POST /discipline/suspensions/{id}/decide
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function decide( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$games = absint( $request->get_param( 'games' ) );

		return self::with_notice_lock(
			$id,
			static function () use ( $id, $games ) {
				return self::decide_locked( $id, $games );
			}
		);
	}

	/**
	 * The decide body, holding the parent's lock. An indefinite suspension
	 * becomes a games suspension counted from today.
	 *
	 * @param int $id    Parent id.
	 * @param int $games Decided length.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function decide_locked( int $id, int $games ) {
		$parent = self::find_parent( $id );
		$error  = self::decide_error( $parent, self::has_live_child( $parent ) );
		if ( '' !== $error ) {
			return self::action_error( $error );
		}

		$elig  = self::eligibility_for( $parent, current_time( 'Y-m-d' ), $games );
		$child = SPLM_Discipline_Suspension::build_child_row(
			$parent,
			'decided',
			array(
				'games'       => $games,
				'eligible_on' => $elig['eligible_on'],
			)
		);

		return self::issue_child( $child, 'decided', array( 'remaining' => $elig['remaining'] ) );
	}

	/**
	 * POST /discipline/suspensions/{id}/amend
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function amend( $request ) {
		$id    = absint( $request->get_param( 'id' ) );
		$games = absint( $request->get_param( 'games' ) );

		return self::with_notice_lock(
			$id,
			static function () use ( $id, $games ) {
				return self::amend_locked( $id, $games );
			}
		);
	}

	/**
	 * The amend body, holding the parent's lock. Games count from the incident
	 * date, as they did for the suspension being replaced.
	 *
	 * @param int $id    Parent id.
	 * @param int $games New length (0 allowed).
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function amend_locked( int $id, int $games ) {
		$parent = self::find_parent( $id );
		$error  = self::amend_error( $parent, $games, self::has_live_child( $parent ) );
		if ( '' !== $error ) {
			return self::action_error( $error );
		}

		$elig  = self::eligibility_for( $parent, SPLM_Discipline_Suspension_REST::after_date( (int) ( $parent->incident_event_id ?? 0 ) ), $games );
		$child = SPLM_Discipline_Suspension::build_child_row(
			$parent,
			'amended',
			array(
				'games'       => $games,
				'eligible_on' => $elig['eligible_on'],
			)
		);

		return self::issue_child( $child, 'amended', array_merge( array( 'remaining' => $elig['remaining'] ), SPLM_Discipline_Suspension_REST::prior_games_extra( 'manual-amended', $parent ) ) );
	}

	/**
	 * POST /discipline/suspensions/{id}/revoke
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revoke( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$notify = null === $request->get_param( 'notify' ) ? true : (bool) $request->get_param( 'notify' );

		return self::with_notice_lock(
			$id,
			static function () use ( $id, $notify ) {
				return self::revoke_locked( $id, $notify );
			}
		);
	}

	/**
	 * The revoke body, holding the parent's lock. A draft is discarded (nothing
	 * was ever sent); a sent notice is revoked and, unless told otherwise, the
	 * player is told.
	 *
	 * @param int  $id     Parent id.
	 * @param bool $notify Mail the correction.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function revoke_locked( int $id, bool $notify ) {
		$parent = self::find_parent( $id );
		$error  = self::revoke_error( $parent );
		if ( '' !== $error ) {
			return self::action_error( $error );
		}

		if ( 'sent' !== (string) $parent->status ) {
			SPLM_Discipline_Notice_Database::update( $id, array( 'status' => 'discarded' ) );

			return self::action_response( $id, self::no_delivery() );
		}

		return self::revoke_sent( $parent, $notify );
	}

	/**
	 * Revoke a sent notice: child first (a failed insert leaves the parent
	 * untouched), then the parent, then the mail.
	 *
	 * @param object $parent Sent parent row.
	 * @param bool   $notify Mail the correction.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function revoke_sent( object $parent, bool $notify ) {
		$parent_id = (int) $parent->id;
		$child     = SPLM_Discipline_Suspension::build_child_row( $parent, 'revoked', array( 'notify' => $notify ) );
		$child_id  = SPLM_Discipline_Notice_Database::insert( $child );
		if ( $child_id <= 0 ) {
			return self::write_failed();
		}

		SPLM_Discipline_Notice_Database::update( $parent_id, array( 'status' => 'revoked' ) );

		$delivery = $notify ? self::deliver_child( $child_id, $child, 'revoked', array() ) : self::no_delivery();

		return is_wp_error( $delivery ) ? $delivery : self::action_response( $parent_id, $delivery );
	}

	/**
	 * POST /discipline/suspensions/{id}/recalculate
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function recalculate( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		return self::with_notice_lock(
			$id,
			static function () use ( $id ) {
				return self::recalculate_locked( $id );
			}
		);
	}

	/**
	 * The recalculate body, holding the lock: only eligible_on moves, and
	 * nothing is mailed.
	 *
	 * @param int $id Notice id.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function recalculate_locked( int $id ) {
		$parent = self::find_parent( $id );
		$error  = self::recalculate_error( $parent );
		if ( '' !== $error ) {
			return self::action_error( $error );
		}

		$elig = self::eligibility_for( $parent, SPLM_Discipline_Suspension_REST::after_date( (int) ( $parent->incident_event_id ?? 0 ) ), (int) $parent->games );
		SPLM_Discipline_Notice_Database::update( $id, array( 'eligible_on' => $elig['eligible_on'] ) );

		return self::action_response( $id, self::no_delivery() );
	}
}
