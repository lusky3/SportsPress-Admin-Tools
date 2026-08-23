<?php
/**
 * REST controller for the season audit and its one-click repairs.
 *
 * Kept out of class-rest-api.php, which is already past 5,000 lines. Both
 * routes write to or describe league data, so both sit behind the manage
 * capability rather than the general read tier.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Audit_REST {

	const NAMESPACE_V1 = 'splm/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the audit routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/audit',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_audit' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'season' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/audit/fix',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'post_fix' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'season' => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'validate_callback' => 'rest_validate_request_arg',
					),
					'check'  => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	/**
	 * Repairing league records is a league-management action.
	 *
	 * @return bool|WP_Error
	 */
	public function can_manage() {
		if ( ! SPLM_Capabilities::can_manage() ) {
			return new WP_Error(
				'forbidden',
				__( 'You cannot audit or repair league records.', 'sportspress-league-manager' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * The season this feature is allowed to audit and repair.
	 *
	 * The dashboard's season picker lists every season ever played and
	 * remembers the user's last choice, so an unconstrained season parameter
	 * would let a convener who happens to be looking at an old season press
	 * "fix all" and re-point every current calendar at it. The repairs only
	 * make sense for the season in progress, so that is the only one accepted.
	 *
	 * @return int Term id, or 0 when no current season is configured.
	 */
	private function current_season_id(): int {
		$id = (int) get_option( 'spem_current_season_id', 0 );

		return $id ? $id : (int) get_option( 'sportspress_season', 0 );
	}

	/**
	 * Reject any season other than the one in progress.
	 *
	 * @param int $season_id Requested season term id.
	 * @return WP_Term|WP_Error
	 */
	private function resolve_season( int $season_id ) {
		$season = get_term( $season_id, 'sp_season' );
		if ( ! $season || is_wp_error( $season ) ) {
			return new WP_Error( 'invalid_season', __( 'Season not found.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$current = $this->current_season_id();
		if ( ! $current ) {
			return new WP_Error(
				'no_current_season',
				__( 'No current season is set, so there is nothing to audit against.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		if ( $season_id !== $current ) {
			return new WP_Error(
				'not_current_season',
				__( 'Only the current season can be audited or repaired. Switch the season selector to the current season and try again.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		return $season;
	}

	/**
	 * GET /audit — what is currently mis-configured for a season.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_audit( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$season    = $this->resolve_season( $season_id );
		if ( is_wp_error( $season ) ) {
			return $season;
		}

		$report = SPLM_Season_Audit::run( $season_id );
		$checks = array();

		foreach ( SPLM_Season_Audit::CHECKS as $key ) {
			$description = SPLM_Season_Audit::describe( $key );
			$checks[]    = array_merge(
				array( 'key' => $key ),
				$description,
				array(
					'count'  => $report[ $key ]['count'] ?? 0,
					// Only the listing is truncated; a repair still covers every
					// match, which is why count is reported in full.
					'items'  => array_slice( $report[ $key ]['items'] ?? array(), 0, SPLM_Season_Audit::MAX_ITEMS ),
					'capped' => ! empty( $report[ $key ]['capped'] ),
				)
			);
		}

		return new WP_REST_Response(
			array(
				'season' => array(
					'id'   => (int) $season->term_id,
					'name' => $season->name,
				),
				'checks' => $checks,
			),
			200
		);
	}

	/**
	 * POST /audit/fix — repair every record one check reports.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function post_fix( $request ) {
		$season_id = absint( $request->get_param( 'season' ) );
		$check     = sanitize_key( (string) $request->get_param( 'check' ) );

		$season = $this->resolve_season( $season_id );
		if ( is_wp_error( $season ) ) {
			return $season;
		}

		if ( ! in_array( $check, SPLM_Season_Audit::CHECKS, true ) ) {
			return new WP_Error( 'invalid_check', __( 'Unknown check.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
		}

		$result = SPLM_Season_Audit::fix( $check, $season_id );

		// A held lock means another repair is running, which is not the same
		// as "nothing needed fixing" and must not be reported as success.
		if ( ! empty( $result['locked'] ) ) {
			return new WP_Error(
				'audit_locked',
				__( 'Another repair is already running. Try again in a moment.', 'sportspress-league-manager' ),
				array( 'status' => 409 )
			);
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'check'   => $check,
				'fixed'   => (int) $result['fixed'],
				'skipped' => (int) $result['skipped'],
				'items'   => $result['items'],
			),
			200
		);
	}
}
