<?php
/**
 * REST routes for the notice queue.
 *
 * Both queue surfaces — the technical WP-admin tab and the React page — act
 * through these four routes. There is deliberately no admin_post_* handler:
 * release logic that exists twice is how one surface ends up enforcing
 * capabilities and the other does not.
 *
 * @author Cody (lusky3)
 *
 * Four routes, each with a handler and an args block, plus the shared row
 * shape both surfaces render. The method count is the route count.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_REST {

	const REST_NAMESPACE = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the four routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/notices',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_notices' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => self::list_args(),
			)
		);

		foreach ( array( 'release', 'discard', 'serve' ) as $action ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/discipline/notices/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => array( $this, 'can_manage' ),
					'args'                => self::action_args(),
				)
			);
		}
	}

	/**
	 * Module-gated capability check, matching SPLM_Leaders_REST's discipline
	 * routes so the whole feature answers the same way when switched off.
	 *
	 * @return true|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function can_manage() {
		if ( ! SPLM_REST_API::module_enabled( 'league_discipline' ) ) {
			return new WP_Error( 'module_disabled', __( 'Penalty discipline is not enabled.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}
		if ( ! SPLM_Capabilities::can_manage() ) {
			return new WP_Error( 'forbidden', __( 'You cannot manage disciplinary notices.', 'sportspress-league-manager' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Args for the list route.
	 *
	 * Every arg declares BOTH callbacks. WordPress only falls back to
	 * rest_validate_request_arg when no sanitize_callback is declared, so an
	 * enum with a sanitiser and no validator would not be enforced at all.
	 *
	 * @return array
	 */
	private static function list_args(): array {
		return array(
			'season'   => array(
				'required'          => false,
				'type'              => 'integer',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'status'   => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => SPLM_Discipline_Notice_Database::STATUSES,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
			'page'     => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'required'          => false,
				'type'              => 'integer',
				'default'           => 50,
				'minimum'           => 1,
				'maximum'           => 100,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Args shared by the three mutating routes.
	 *
	 * @return array
	 */
	private static function action_args(): array {
		return array(
			'id'   => array(
				'required'          => true,
				'type'              => 'integer',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'note' => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'wp_kses_post',
			),
		);
	}

	/**
	 * GET /discipline/notices
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_notices( $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );

		$result = SPLM_Discipline_Notice_Database::query(
			array(
				'season' => (int) $request->get_param( 'season' ),
				'status' => (string) $request->get_param( 'status' ),
			),
			$page,
			$per_page
		);

		$items = array();
		foreach ( $result['rows'] as $row ) {
			$items[] = self::row_to_response( $row );
		}

		return new WP_REST_Response(
			splm_rest_list_response( $items, (int) $result['total'], $page, $per_page ),
			200
		);
	}

	/**
	 * POST /discipline/notices/{id}/release
	 *
	 * Sends a pending notice, or retries a failed one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function release( $request ) {
		$id = absint( $request->get_param( 'id' ) );

		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return new WP_Error( 'splm_no_lock', __( 'Cannot release safely without the parent plugin’s lock.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}

		// Per-notice lock, and the row is re-read INSIDE it. A double-click must
		// not send twice, and checking the status before taking the lock would
		// leave exactly that race open.
		$result = SPAT_Lock::with(
			'splm_discipline_notice_' . $id,
			60,
			function () use ( $id ) {
				return $this->release_locked( $id );
			}
		);

		if ( false === $result ) {
			return new WP_Error( 'splm_notice_busy', __( 'That notice is already being released.', 'sportspress-league-manager' ), array( 'status' => 409 ) );
		}

		return $result;
	}

	/**
	 * The release body, already holding the lock.
	 *
	 * @param int $id Notice id.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function release_locked( int $id ) {
		$row = SPLM_Discipline_Notice_Database::find( $id );
		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$releasable = array(
			SPLM_Discipline_Notice_Database::STATUS_PENDING,
			SPLM_Discipline_Notice_Database::STATUS_FAILED,
		);
		if ( ! in_array( (string) $row->status, $releasable, true ) ) {
			return new WP_Error(
				'splm_notice_not_releasable',
				__( 'Only a pending or failed notice can be released.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$player_id = (int) $row->player_id;
		$season_id = (int) $row->season_id;

		// Re-resolved rather than trusting the stored address: a convener whose
		// first attempt failed for a missing address fixes the player record and
		// then releases, and that fix has to be picked up.
		$address = SPLM_Discipline_Notice_Recipients::player_email( $player_id );
		$team_id = self::team_for( $player_id, $season_id );

		$sent = SPLM_Discipline_Notice_Mail::send(
			$id,
			self::context_for( $row, $team_id ),
			$address['email'],
			SPLM_Discipline_Notice_Recipients::bcc_for( $season_id, $team_id )
		);

		SPLM_Discipline_Notice_Database::update(
			$id,
			array(
				'released_by'   => get_current_user_id(),
				'recipient_via' => $address['via'],
			)
		);

		if ( ! $sent ) {
			$fresh = SPLM_Discipline_Notice_Database::find( $id );

			return new WP_Error(
				'splm_notice_send_failed',
				$fresh && $fresh->last_error
					? (string) $fresh->last_error
					: __( 'The notice could not be sent.', 'sportspress-league-manager' ),
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

	/**
	 * POST /discipline/notices/{id}/discard
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function discard( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = SPLM_Discipline_Notice_Database::find( $id );

		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$discardable = array(
			SPLM_Discipline_Notice_Database::STATUS_PENDING,
			SPLM_Discipline_Notice_Database::STATUS_FAILED,
		);
		if ( ! in_array( (string) $row->status, $discardable, true ) ) {
			return new WP_Error(
				'splm_notice_not_discardable',
				__( 'Only a pending or failed notice can be discarded.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$fields = array(
			'status'      => SPLM_Discipline_Notice_Database::STATUS_DISCARDED,
			'released_by' => get_current_user_id(),
		);

		// Only written when the request actually carried one. Writing it always
		// blanks whatever note the row already carries, because neither surface
		// sends this parameter today — so every discard would silently erase the
		// reason someone had recorded for the notice.
		$note = (string) $request->get_param( 'note' );
		if ( '' !== $note ) {
			$fields['note'] = $note;
		}

		$ok = SPLM_Discipline_Notice_Database::update( $id, $fields );

		if ( ! $ok ) {
			return new WP_Error( 'splm_notice_write_failed', __( 'Could not discard the notice.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_DISCARDED,
			),
			200
		);
	}

	/**
	 * POST /discipline/notices/{id}/serve
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function serve( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$row = SPLM_Discipline_Notice_Database::find( $id );

		if ( ! $row ) {
			return new WP_Error( 'splm_notice_not_found', __( 'Notice not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		if ( 'suspend' !== (string) $row->consequence ) {
			return new WP_Error(
				'splm_notice_not_a_suspension',
				__( 'Only a suspension can be marked served.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( SPLM_Discipline_Notice_Database::STATUS_SENT !== (string) $row->status ) {
			return new WP_Error(
				'splm_notice_not_sent',
				__( 'A suspension can only be marked served once the player has been told.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		$fields = array(
			'status'    => SPLM_Discipline_Notice_Database::STATUS_SERVED,
			'served_at' => SPLM_Discipline_Notice_Database::now(),
		);

		// See discard(): an absent note parameter must not blank the stored one.
		$note = (string) $request->get_param( 'note' );
		if ( '' !== $note ) {
			$fields['note'] = $note;
		}

		$ok = SPLM_Discipline_Notice_Database::update( $id, $fields );

		if ( ! $ok ) {
			return new WP_Error( 'splm_notice_write_failed', __( 'Could not mark the suspension served.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'id'      => $id,
				'status'  => SPLM_Discipline_Notice_Database::STATUS_SERVED,
			),
			200
		);
	}

	/**
	 * The row shape both surfaces render.
	 *
	 * The React page shows a subset; the technical tab shows all of it. One
	 * shape means the two views cannot disagree about what a row says.
	 *
	 * @param object $row Database row.
	 * @return array
	 */
	public static function row_to_response( $row ): array {
		$player_id = (int) $row->player_id;

		return array(
			'id'            => (int) $row->id,
			'player_id'     => $player_id,
			'player'        => get_the_title( $player_id ),
			'season_id'     => (int) $row->season_id,
			'tier_key'       => (string) $row->tier_key,
			'ack_key'        => (string) $row->ack_key,
			'scope'          => (string) $row->scope,
			'severity'       => (string) $row->severity,
			'consequence'    => (string) $row->consequence,
			'games'          => (int) $row->games,
			'value_at_fire'  => (int) $row->value_at_fire,
			'season_at_fire' => (int) $row->season_at_fire,
			// Snapshots taken when the notice fired, so a mid-season roster
			// move does not rewrite the history of an old notice.
			'team'           => (string) $row->team,
			'division'       => (string) $row->division,
			'status'         => (string) $row->status,
			'recipient'     => (string) $row->recipient,
			'recipient_via' => (string) $row->recipient_via,
			'bcc'           => (string) $row->bcc,
			'sent_at'       => (string) $row->sent_at,
			'served_at'     => (string) $row->served_at,
			'released_by'   => (int) $row->released_by,
			'last_error'    => (string) $row->last_error,
			'note'          => (string) $row->note,
			'created_at'    => (string) $row->created_at,
		);
	}

	/**
	 * Build the mail context for a stored notice row.
	 *
	 * The counterpart of SPLM_Discipline_Notice_Pass::mail_context(), which
	 * builds the same context from a live match. Both hand their fields to
	 * SPLM_Discipline_Notice_Mail::context() so the derived values are computed
	 * in one place — releasing a queued notice and sending one automatically
	 * must produce identical wording.
	 *
	 * @param object $row     Notice row.
	 * @param int    $team_id The player's team for the row's season.
	 * @return array Body context.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function context_for( $row, int $team_id ): array {
		$tiers = SPLM_Penalty_Watch::sanitize_tiers( (array) get_option( 'splm_discipline_tiers', array() ) );

		return SPLM_Discipline_Notice_Mail::context(
			array(
				'player_name'  => get_the_title( (int) $row->player_id ),
				'season_name'  => self::season_name( (int) $row->season_id ),
				'scope'        => (string) $row->scope,
				'season_value' => (int) $row->season_at_fire,
				'consequence'  => (string) $row->consequence,
				'games'        => (int) $row->games,
				'value'        => (int) $row->value_at_fire,
				'team_id'      => $team_id,
			),
			$tiers
		);
	}

	/**
	 * The team a player counts for in a season.
	 *
	 * Goes through the aggregator so the team used for the captain Bcc and the
	 * next-game lookup is the same one the watch list attributes the player to.
	 *
	 * @param int $player_id Player post id.
	 * @param int $season_id Season term id.
	 * @return int Team post id, or 0.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function team_for( int $player_id, int $season_id ): int {
		$players = SPLM_Player_Stats_Aggregator::for_season( $season_id, array( 'include_playoffs' => true ) );

		return (int) ( $players[ $player_id ]['team_id'] ?? 0 );
	}

	/**
	 * A season's display name.
	 *
	 * @param int $season_id Season term id.
	 * @return string
	 */
	private static function season_name( int $season_id ): string {
		$season = get_term( $season_id, 'sp_season' );

		return ( $season && ! is_wp_error( $season ) ) ? (string) $season->name : '';
	}
}
