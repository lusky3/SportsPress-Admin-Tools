<?php
/**
 * REST routes for manually issued suspensions: infractions, a player's
 * history, a write-free preview, and issuing one.
 *
 * The preview answers "what would happen if I issued this?" — eligibility
 * date, who would be mailed, what the emails would say, and which warnings
 * apply — without touching the database. Every decision it makes lives in
 * plan_preview(), which calls no WordPress function, so the handlers stay
 * glue.
 *
 * @author Cody (lusky3)
 *
 * The routes with their args blocks, plus the pure planner and the small
 * helpers that keep each method inside the complexity limit.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension_REST {

	const REST_NAMESPACE = 'splm/v1';

	// Private note limit, and the team snapshot column's width (varchar(200)).
	const NOTE_MAX = 2000;
	const TEAM_MAX = 200;

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the routes.
	 *
	 * The permission callback is the notice REST class's static gate rather
	 * than an instance: constructing that class would register its hooks a
	 * second time.
	 *
	 * @return void
	 */
	public function register_routes() {
		$gate = array( 'SPLM_Discipline_Notice_REST', 'gate' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/infractions',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_infractions' ),
				'permission_callback' => $gate,
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/history',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_history' ),
				'permission_callback' => $gate,
				'args'                => self::history_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/suspensions/preview',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => $gate,
				'args'                => self::preview_args(),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/suspensions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $gate,
				'args'                => self::create_args(),
			)
		);

		$this->register_action_routes( $gate );
	}

	/**
	 * The per-notice action routes: decide, amend, revoke, recalculate.
	 *
	 * @param array $gate Permission callback.
	 * @return void
	 */
	private function register_action_routes( array $gate ): void {
		$actions = array(
			'decide'      => array( 'games' => self::int_arg( true, 1, SPLM_Discipline_Infraction::MAX_GAMES ) ),
			'amend'       => array( 'games' => self::int_arg( true, 0, SPLM_Discipline_Infraction::MAX_GAMES ) ),
			'revoke'      => array( 'notify' => self::notify_arg() ),
			'recalculate' => array(),
		);

		foreach ( $actions as $action => $args ) {
			register_rest_route(
				self::REST_NAMESPACE,
				'/discipline/suspensions/(?P<id>\d+)/' . $action,
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, $action ),
					'permission_callback' => $gate,
					'args'                => array_merge( array( 'id' => self::int_arg( true, 1 ) ), $args ),
				)
			);
		}
	}

	/**
	 * The revoke route's notify flag.
	 *
	 * @return array
	 */
	private static function notify_arg(): array {
		return array(
			'required'          => false,
			'type'              => 'boolean',
			'default'           => true,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_boolean',
		);
	}

	/**
	 * Args for the history route. Every arg declares BOTH callbacks (see
	 * SPLM_Discipline_Notice_REST::list_args()).
	 *
	 * @return array
	 */
	private static function history_args(): array {
		return array(
			'player'           => self::int_arg( true, 1 ),
			'include_baseline' => array(
				'required'          => false,
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Args for the preview route.
	 *
	 * @return array
	 */
	private static function preview_args(): array {
		return array(
			'player'         => self::int_arg( true, 1 ),
			'infraction'     => self::int_arg( true, 1 ),
			'season'         => self::int_arg( false, 0 ),
			'incident_event' => self::int_arg( false, 0 ),
			'games'          => self::int_arg( false, 0, SPLM_Discipline_Infraction::MAX_GAMES ),
		);
	}

	/**
	 * Args for the create route: the preview's, plus the private note and the
	 * send/draft switch.
	 *
	 * @return array
	 */
	private static function create_args(): array {
		return array_merge(
			self::preview_args(),
			array(
				'incident_note' => array(
					'required'          => false,
					'type'              => 'string',
					'maxLength'         => self::NOTE_MAX,
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_textarea_field',
				),
				'mode'          => array(
					'required'          => false,
					'type'              => 'string',
					'enum'              => array( 'send', 'draft' ),
					'default'           => 'send',
					'validate_callback' => 'rest_validate_request_arg',
					'sanitize_callback' => 'sanitize_key',
				),
			)
		);
	}

	/**
	 * One integer arg declaration.
	 *
	 * @param bool     $required Whether the arg is required.
	 * @param int      $minimum  Inclusive minimum.
	 * @param int|null $maximum  Inclusive maximum, or null for none.
	 * @return array
	 */
	private static function int_arg( bool $required, int $minimum, ?int $maximum = null ): array {
		$arg = array(
			'required'          => $required,
			'type'              => 'integer',
			'minimum'           => $minimum,
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'absint',
		);

		if ( null !== $maximum ) {
			$arg['maximum'] = $maximum;
		}

		return $arg;
	}

	/**
	 * GET /discipline/infractions
	 *
	 * @return WP_REST_Response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_infractions() {
		$items = array();
		foreach ( SPLM_Discipline_Infraction::all() as $row ) {
			$items[] = array(
				'id'            => (int) $row->id,
				'rule_ref'      => (string) $row->rule_ref,
				'title'         => (string) $row->title,
				'rule_text'     => (string) $row->rule_text,
				'outcome'       => (string) $row->outcome,
				'default_games' => (int) $row->default_games,
				'needs_review'  => (int) $row->needs_review,
			);
		}

		return new WP_REST_Response( splm_rest_list_response( $items ), 200 );
	}

	/**
	 * GET /discipline/history
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_history( $request ) {
		$rows = SPLM_Discipline_Notice_Database::for_player(
			absint( $request->get_param( 'player' ) ),
			(bool) $request->get_param( 'include_baseline' )
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = SPLM_Discipline_Notice_REST::row_to_response( $row, true );
		}

		$body            = splm_rest_list_response( $items );
		$body['summary'] = SPLM_Discipline_Suspension::summary_line( $rows );

		return new WP_REST_Response( $body, 200 );
	}

	/**
	 * Games a suspension carries: the infraction's default, or the convener's
	 * override clamped to 0-MAX_GAMES. An indefinite outcome ignores any
	 * override. Pure.
	 *
	 * @param object|null $infraction Infraction row.
	 * @param mixed       $override   Convener's games value, or null.
	 * @return int
	 */
	public static function resolve_games( ?object $infraction, $override ): int {
		if ( null === $infraction || 'indefinite' === (string) $infraction->outcome ) {
			return 0;
		}

		$games = ( null === $override || '' === $override ) ? (int) $infraction->default_games : (int) $override;

		return min( SPLM_Discipline_Infraction::MAX_GAMES, max( 0, $games ) );
	}

	/**
	 * Decide what a preview should say. Pure: no WordPress calls.
	 *
	 * @param array       $input         Keys: player_id, infraction_id, season_id, incident_event_id, games (nullable).
	 * @param object|null $infraction    Infraction row, null when the id matched nothing.
	 * @param array       $elig          SPLM_Discipline_Eligibility::next_eligible() result.
	 * @param array       $player_email  array( 'email', 'via' ).
	 * @param array[]     $captains      Each: team_id, team, email. Empty when no team resolved.
	 * @param object[]    $existing_rows The player's existing notice rows.
	 * @param string      $today         UTC 'Y-m-d' of the attempt.
	 * @return array ok, error_code, warnings, games, outcome, eligible_on, remaining, duplicate_of.
	 */
	public static function plan_preview( array $input, ?object $infraction, array $elig, array $player_email, array $captains, array $existing_rows, string $today ): array {
		if ( null === $infraction ) {
			return array(
				'ok'         => false,
				'error_code' => 'invalid_infraction',
				'warnings'   => array(),
			);
		}

		$outcome   = 'indefinite' === (string) $infraction->outcome ? 'indefinite' : 'games';
		$games     = self::resolve_games( $infraction, $input['games'] ?? null );
		$duplicate = self::duplicate_of( $input, $existing_rows, $today );
		$warnings  = array_merge(
			self::contact_warnings( $player_email, $captains ),
			self::history_warnings( (int) ( $input['season_id'] ?? 0 ), $existing_rows, $duplicate )
		);

		$projected = 'games' === $outcome && $games > 0 && ! empty( $elig['date'] );

		return array(
			'ok'           => true,
			'error_code'   => '',
			'warnings'     => $warnings,
			'games'        => $games,
			'outcome'      => $outcome,
			'eligible_on'  => $projected ? substr( (string) $elig['date'], 0, 10 ) : null,
			'remaining'    => 'games' === $outcome ? (int) ( $elig['remaining'] ?? 0 ) : 0,
			'duplicate_of' => $duplicate,
		);
	}

	/**
	 * Id of the existing row an attempt would duplicate, or 0.
	 *
	 * @param array    $input         Preview input.
	 * @param object[] $existing_rows Existing rows.
	 * @param string   $today         UTC 'Y-m-d'.
	 * @return int
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function duplicate_of( array $input, array $existing_rows, string $today ): int {
		foreach ( $existing_rows as $row ) {
			if ( SPLM_Discipline_Suspension::is_duplicate(
				array( $row ),
				(int) ( $input['player_id'] ?? 0 ),
				(int) ( $input['infraction_id'] ?? 0 ),
				(int) ( $input['incident_event_id'] ?? 0 ),
				$today
			) ) {
				return (int) ( $row->id ?? 0 );
			}
		}

		return 0;
	}

	/**
	 * Warnings about who can be reached.
	 *
	 * @param array   $player_email array( 'email', 'via' ).
	 * @param array[] $captains     Each: team_id, team, email.
	 * @return string[]
	 */
	private static function contact_warnings( array $player_email, array $captains ): array {
		$warnings = array();

		if ( '' === (string) ( $player_email['email'] ?? '' ) ) {
			$warnings[] = 'no_player_email';
		}
		if ( ! $captains ) {
			$warnings[] = 'no_team_for_season';
		}
		foreach ( $captains as $captain ) {
			if ( '' === (string) ( $captain['email'] ?? '' ) ) {
				$warnings[] = 'captain_no_email:' . (string) ( $captain['team'] ?? '' );
			}
		}

		return $warnings;
	}

	/**
	 * Warnings drawn from the player's existing record.
	 *
	 * @param int      $season_id Season being suspended in.
	 * @param object[] $existing  Existing rows.
	 * @param int      $duplicate Id of a duplicated row, or 0.
	 * @return string[]
	 */
	private static function history_warnings( int $season_id, array $existing, int $duplicate ): array {
		$warnings = array();

		if ( self::has_prior_suspension( $season_id, $existing ) ) {
			$warnings[] = 'prior_suspension_this_season';
		}
		if ( $duplicate > 0 ) {
			$warnings[] = 'duplicate';
		}

		return $warnings;
	}

	/**
	 * Whether the player already has a live manual suspension this season.
	 * Revoked, discarded and baseline rows never counted as one.
	 *
	 * @param int      $season_id Season.
	 * @param object[] $existing  Existing rows.
	 * @return bool
	 */
	private static function has_prior_suspension( int $season_id, array $existing ): bool {
		foreach ( $existing as $row ) {
			if ( 'manual' === (string) ( $row->source ?? '' )
				&& 'suspend' === (string) $row->consequence
				&& (int) $row->season_id === $season_id
				&& in_array( (string) $row->status, array( 'sent', 'served', 'pending' ), true )
			) {
				return true;
			}
		}

		return false;
	}

	/**
	 * POST /discipline/suspensions/preview
	 *
	 * Writes nothing: no insert, no update, no lock.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function preview( $request ) {
		$input      = self::preview_input( $request );
		$infraction = self::active_infraction( $input['infraction_id'] );

		if ( 'sp_player' !== get_post_type( $input['player_id'] ) ) {
			return new WP_Error( 'invalid_player', __( 'That player does not exist.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
		}

		$facts = self::gather_facts( $input, $infraction );
		$plan  = self::plan_preview( $input, $infraction, $facts['elig'], $facts['player_email'], $facts['captains'], $facts['existing'], gmdate( 'Y-m-d' ) );

		if ( ! $plan['ok'] ) {
			return new WP_Error( $plan['error_code'], __( 'That infraction does not exist.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
		}

		return new WP_REST_Response( self::preview_response( $input, $infraction, $plan, $facts ), 200 );
	}

	/**
	 * The request, normalised.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function preview_input( $request ): array {
		$games  = $request->get_param( 'games' );
		$season = absint( $request->get_param( 'season' ) );

		return array(
			'player_id'         => absint( $request->get_param( 'player' ) ),
			'infraction_id'     => absint( $request->get_param( 'infraction' ) ),
			'season_id'         => $season ? $season : SPLM_SportsPress_Data::default_season_id(),
			'incident_event_id' => absint( $request->get_param( 'incident_event' ) ),
			'games'             => ( null === $games || '' === $games ) ? null : absint( $games ),
		);
	}

	/**
	 * Read-only lookups the planner needs.
	 *
	 * @param array       $input      Preview input.
	 * @param object|null $infraction Infraction row.
	 * @return array elig, player_email, captains, bcc, existing.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function gather_facts( array $input, ?object $infraction ): array {
		$player = $input['player_id'];
		$season = $input['season_id'];
		$games  = self::resolve_games( $infraction, $input['games'] );
		$caps   = SPLM_Discipline_Suspension::captain_recipients( $player, $season );
		$first  = $caps ? (int) $caps[0]['team_id'] : 0;

		return array(
			'elig'         => SPLM_Discipline_Eligibility::next_eligible( $player, $season, self::after_date( $input['incident_event_id'] ), $games ),
			'player_email' => SPLM_Discipline_Notice_Recipients::player_email( $player ),
			'captains'     => $caps,
			'bcc'          => $first ? SPLM_Discipline_Notice_Recipients::bcc_for( $season, $first ) : array(),
			'existing'     => SPLM_Discipline_Notice_Database::for_player( $player, false ),
		);
	}

	/**
	 * The local date eligibility counts from: the incident match, else today.
	 *
	 * @param int $event_id Incident event id (0 for none).
	 * @return string 'Y-m-d'.
	 */
	private static function after_date( int $event_id ): string {
		if ( $event_id > 0 && 'sp_event' === get_post_type( $event_id ) ) {
			$event = get_post( $event_id );
			if ( $event && ! empty( $event->post_date ) ) {
				return substr( (string) $event->post_date, 0, 10 );
			}
		}

		return current_time( 'Y-m-d' );
	}

	/**
	 * The preview body, including the emails exactly as they would be sent.
	 *
	 * @param array  $input      Preview input.
	 * @param object $infraction Infraction row.
	 * @param array  $plan       plan_preview() result.
	 * @param array  $facts      gather_facts() result.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function preview_response( array $input, $infraction, array $plan, array $facts ): array {
		// A synthetic, never-saved row: the same builder and context path a
		// real issue uses, so the preview cannot word things differently.
		$row = (object) array_merge(
			SPLM_Discipline_Suspension::build_row( self::row_input( $input ), $infraction, $facts['elig'] ),
			array(
				'id'         => 0,
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$ctx = SPLM_Discipline_Suspension_Context::for_row(
			$row,
			array(
				'remaining'  => $plan['remaining'],
				'team_names' => self::team_names( $facts['captains'] ),
			)
		);

		return array(
			'eligible_on'    => $plan['eligible_on'],
			'remaining'      => $plan['remaining'],
			'outcome'        => $plan['outcome'],
			'games'          => $plan['games'],
			'player_email'   => $facts['player_email'],
			'captains'       => self::captain_summary( $facts ),
			'warnings'       => $plan['warnings'],
			'duplicate_of'   => $plan['duplicate_of'],
			'player_body'    => SPLM_Discipline_Suspension_Body::body( 'player', $ctx ),
			'captain_body'   => SPLM_Discipline_Suspension_Body::body( 'captain', $ctx ),
			'player_subject' => SPLM_Discipline_Suspension_Body::subject( 'issued', (string) $ctx['season_name'] ),
		);
	}

	/**
	 * build_row() input for a preview.
	 *
	 * @param array $input Preview input.
	 * @return array
	 */
	private static function row_input( array $input ): array {
		return array(
			'player_id'         => $input['player_id'],
			'season_id'         => $input['season_id'],
			'incident_event_id' => $input['incident_event_id'],
			'games'             => $input['games'],
		);
	}

	/**
	 * Decoded team titles, comma-joined, for the context.
	 *
	 * @param array[] $captains Each: team_id, team, email.
	 * @return string
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function team_names( array $captains ): string {
		$names = array();
		foreach ( $captains as $captain ) {
			$names[] = SPLM_Discipline_Suspension_Context::decode( (string) $captain['team'] );
		}

		return implode( ', ', $names );
	}

	/**
	 * Captains as the preview reports them, one entry per address, with who
	 * already receives that address's copy.
	 *
	 * @param array $facts gather_facts() result.
	 * @return array[] Each: team, email, covered_by.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function captain_summary( array $facts ): array {
		$plan = SPLM_Discipline_Suspension::plan_captain_mail( $facts['captains'], (string) $facts['player_email']['email'], $facts['bcc'] );
		$out  = array();
		foreach ( $plan as $entry ) {
			$out[] = array(
				'team'       => implode( ', ', $entry['teams'] ),
				'email'      => (string) $entry['email'],
				'covered_by' => (string) $entry['covered_by'],
			);
		}

		return $out;
	}

	/**
	 * An infraction a convener may issue: one that exists and is active. A
	 * retired infraction stays readable elsewhere but is never issuable.
	 *
	 * @param int $id Infraction id.
	 * @return object|null
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function active_infraction( int $id ): ?object {
		$infraction = SPLM_Discipline_Infraction::find( $id );

		return ( $infraction && 0 !== (int) ( $infraction->active ?? 1 ) ) ? $infraction : null;
	}

	/**
	 * First reason a create request cannot proceed, or ''. Pure; the order is
	 * the contract (cheapest and most specific check first).
	 *
	 * @param bool        $event_ok   Incident event is absent or an sp_event.
	 * @param bool        $player_ok  Player post is an sp_player.
	 * @param object|null $infraction Infraction row, null when unknown.
	 * @param int         $season_id  Resolved season (0 when none could be found).
	 * @return string Error code.
	 */
	public static function create_error( bool $event_ok, bool $player_ok, ?object $infraction, int $season_id ): string {
		if ( ! $event_ok ) {
			return 'invalid_incident_event';
		}
		if ( ! $player_ok ) {
			return 'invalid_player';
		}
		if ( null === $infraction ) {
			return 'invalid_infraction';
		}

		return $season_id > 0 ? '' : 'invalid_season';
	}

	/**
	 * A 400 for one of create_error()'s codes.
	 *
	 * @param string $code Error code.
	 * @return WP_Error
	 */
	private static function create_wp_error( string $code ): WP_Error {
		$messages = array(
			'invalid_incident_event' => __( 'The incident must be a match.', 'sportspress-league-manager' ),
			'invalid_player'         => __( 'That player does not exist.', 'sportspress-league-manager' ),
			'invalid_infraction'     => __( 'That infraction does not exist.', 'sportspress-league-manager' ),
			'invalid_season'         => __( 'No season could be determined.', 'sportspress-league-manager' ),
		);

		return new WP_Error( $code, $messages[ $code ] ?? '', array( 'status' => 400 ) );
	}

	/**
	 * POST /discipline/suspensions
	 *
	 * Issues a suspension: validates, then inserts and (for mode=send) mails
	 * under a per-player lock so a double-click cannot create two.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function create( $request ) {
		$input      = self::create_input( $request );
		$infraction = self::active_infraction( $input['infraction_id'] );
		$event_ok   = 0 === $input['incident_event_id'] || 'sp_event' === get_post_type( $input['incident_event_id'] );
		$error      = self::create_error( $event_ok, 'sp_player' === get_post_type( $input['player_id'] ), $infraction, $input['season_id'] );

		if ( '' !== $error ) {
			return self::create_wp_error( $error );
		}
		if ( ! class_exists( 'SPAT_Lock' ) ) {
			return new WP_Error( 'splm_no_lock', __( 'Cannot issue safely without the parent plugin’s lock.', 'sportspress-league-manager' ), array( 'status' => 503 ) );
		}

		$result = SPAT_Lock::with(
			'splm_discipline_suspension_' . $input['player_id'],
			60,
			static function () use ( $input, $infraction ) {
				return self::create_locked( $input, $infraction );
			}
		);

		if ( false === $result ) {
			return new WP_Error( 'splm_notice_busy', __( 'That player is already being processed.', 'sportspress-league-manager' ), array( 'status' => 409 ) );
		}

		return $result;
	}

	/**
	 * The preview's input plus the private note and the send/draft mode.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function create_input( $request ): array {
		return array_merge(
			self::preview_input( $request ),
			array(
				'incident_note' => mb_substr( sanitize_textarea_field( (string) $request->get_param( 'incident_note' ) ), 0, self::NOTE_MAX ),
				'mode'          => 'draft' === (string) $request->get_param( 'mode' ) ? 'draft' : 'send',
			)
		);
	}

	/**
	 * The create body, already holding the player lock. The facts (including
	 * the player's existing rows) are read here, not before the lock.
	 *
	 * @param array  $input      create_input() result.
	 * @param object $infraction Infraction row.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function create_locked( array $input, $infraction ) {
		$facts = self::gather_facts( $input, $infraction );
		$plan  = self::plan_preview( $input, $infraction, $facts['elig'], $facts['player_email'], $facts['captains'], $facts['existing'], gmdate( 'Y-m-d' ) );

		if ( $plan['duplicate_of'] > 0 ) {
			return new WP_Error(
				'splm_suspension_duplicate',
				__( 'That suspension has already been issued.', 'sportspress-league-manager' ),
				array(
					'status'       => 409,
					'duplicate_of' => $plan['duplicate_of'],
				)
			);
		}

		$names = self::team_names( $facts['captains'] );
		$row   = SPLM_Discipline_Suspension::build_row(
			array_merge(
				self::row_input( $input ),
				array(
					'incident_note' => $input['incident_note'],
					'team'          => mb_substr( $names, 0, self::TEAM_MAX ),
					'division'      => '',
				)
			),
			$infraction,
			$facts['elig']
		);
		// The plan already decided whether a date is projected (never for 0 games).
		$row['eligible_on'] = $plan['eligible_on'];

		$id = SPLM_Discipline_Notice_Database::insert( $row );
		if ( $id <= 0 ) {
			return new WP_Error( 'splm_notice_write_failed', __( 'Could not save the suspension.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
		}

		return self::finish_create( $id, $row, $plan['remaining'], $names, $input['mode'] );
	}

	/**
	 * Mail a freshly inserted row (mode=send) and build the 201 response. A
	 * failed send is still a 201: the row exists, and notice.status plus
	 * last_error tell the modal what happened.
	 *
	 * @param int    $id        New row id.
	 * @param array  $row       The inserted row.
	 * @param int    $remaining Games the schedule cannot cover.
	 * @param string $names     Decoded, comma-joined team titles.
	 * @param string $mode      send|draft.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function finish_create( int $id, array $row, int $remaining, string $names, string $mode ) {
		$stored   = (object) array_merge( $row, array( 'id' => $id ) );
		$delivery = array(
			'sent'     => false,
			'captains' => array(),
		);

		if ( 'send' === $mode ) {
			$ctx = SPLM_Discipline_Suspension_Context::for_row(
				$stored,
				array(
					'remaining'  => $remaining,
					'team_names' => $names,
				)
			);
			$delivery = self::deliver_locked( $id, $ctx, 'issued' );
			if ( is_wp_error( $delivery ) ) {
				return $delivery;
			}
		}

		$fresh = SPLM_Discipline_Notice_Database::find( $id );

		return new WP_REST_Response(
			array(
				'notice'   => SPLM_Discipline_Notice_REST::row_to_response( $fresh ? $fresh : $stored, true ),
				'sent'     => (bool) $delivery['sent'],
				'captains' => $delivery['captains'],
			),
			201
		);
	}

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
				self::release_after_date( $scope, self::after_date( (int) ( $row->incident_event_id ?? 0 ) ), current_time( 'Y-m-d' ) ),
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
	 * Date games are counted from when a row is released. Must match how the
	 * decide / amend routes compute the child's eligible_on: a decision counts
	 * from today (an indefinite suspension's games start at the decision);
	 * issued and amended rows count from the incident. Pure.
	 *
	 * @param string $scope         Row scope.
	 * @param string $incident_date 'Y-m-d' of the incident match, or today when none.
	 * @param string $today         'Y-m-d' local today.
	 * @return string
	 */
	public static function release_after_date( string $scope, string $incident_date, string $today ): string {
		return 'manual-decided' === $scope ? $today : $incident_date;
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
	private static function with_notice_lock( int $id, callable $fn ) {
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
	private static function deliver_locked( int $id, array $ctx, string $kind ) {
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
	public function decide( $request ) {
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
	public function amend( $request ) {
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

		$elig  = self::eligibility_for( $parent, self::after_date( (int) ( $parent->incident_event_id ?? 0 ) ), $games );
		$child = SPLM_Discipline_Suspension::build_child_row(
			$parent,
			'amended',
			array(
				'games'       => $games,
				'eligible_on' => $elig['eligible_on'],
			)
		);

		return self::issue_child( $child, 'amended', array_merge( array( 'remaining' => $elig['remaining'] ), self::prior_games_extra( 'manual-amended', $parent ) ) );
	}

	/**
	 * POST /discipline/suspensions/{id}/revoke
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke( $request ) {
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
	public function recalculate( $request ) {
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

		$elig = self::eligibility_for( $parent, self::after_date( (int) ( $parent->incident_event_id ?? 0 ) ), (int) $parent->games );
		SPLM_Discipline_Notice_Database::update( $id, array( 'eligible_on' => $elig['eligible_on'] ) );

		return self::action_response( $id, self::no_delivery() );
	}
}
