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

/**
 * REST routes for issuing and inspecting manual suspensions.
 *
 * TooManyMethods: the routes with their args blocks, plus the pure planner and
 * the small helpers that keep each method inside the complexity limit.
 * ExcessiveClassComplexity: the sum of many small, individually simple
 * methods; no single method is complex.
 * CouplingBetweenObjects: REST glue coordinating the gate, data, eligibility,
 * mail and context classes.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
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
				'args'                => self::infractions_args(),
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

		$this->register_games_route( $gate );
		$this->register_action_routes( $gate );
	}

	/**
	 * The incident-match picker route.
	 *
	 * @param array $gate Permission callback.
	 * @return void
	 */
	private function register_games_route( array $gate ): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/player-games',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_player_games' ),
				'permission_callback' => $gate,
				'args'                => self::player_games_args(),
			)
		);
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
					'callback'            => array( 'SPLM_Discipline_Suspension_Actions', $action ),
					'permission_callback' => $gate,
					'args'                => array_merge( array( 'id' => self::int_arg( true, 1 ) ), $args ),
				)
			);
		}
	}

	/**
	 * Args for the infractions list: retired rows are opt-in.
	 *
	 * @return array
	 */
	private static function infractions_args(): array {
		return array(
			'include_inactive' => array(
				'required'          => false,
				'type'              => 'boolean',
				'default'           => false,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
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
	 * Args for the incident-match picker route. The season has no registered
	 * default: it is resolved at request time like the other routes.
	 *
	 * @return array
	 */
	private static function player_games_args(): array {
		$limit            = self::int_arg( false, 1, 100 );
		$limit['default'] = 40;

		return array(
			'player' => self::int_arg( true, 1 ),
			'season' => self::int_arg( false, 1 ),
			'limit'  => $limit,
		);
	}

	/**
	 * GET /discipline/infractions
	 *
	 * Active rows only, unless include_inactive is set (the admin editor).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_infractions( $request ) {
		$items = array();
		foreach ( SPLM_Discipline_Infraction::all( ! $request->get_param( 'include_inactive' ) ) as $row ) {
			$items[] = SPLM_Discipline_Infraction_REST::to_response( $row );
		}

		return new WP_REST_Response( splm_rest_list_response( $items ), 200 );
	}

	/**
	 * GET /discipline/player-games
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function get_player_games( $request ) {
		$player_id = absint( $request->get_param( 'player' ) );
		$season_id = absint( $request->get_param( 'season' ) );
		if ( 0 === $season_id ) {
			$season_id = SPLM_SportsPress_Data::default_season_id();
		}

		if ( 'sp_player' !== get_post_type( $player_id ) ) {
			return self::create_wp_error( 'invalid_player' );
		}
		if ( 0 === self::valid_season_id( $season_id ) ) {
			return self::create_wp_error( 'invalid_season' );
		}

		$items = SPLM_Discipline_Incident_Games::for_player( $player_id, $season_id, absint( $request->get_param( 'limit' ) ) );
		$count = count( $items );

		return new WP_REST_Response( splm_rest_list_response( $items, $count, 1, $count ), 200 );
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

		$replaced = SPLM_Discipline_Notice_Database::replaced_ids( array_map( static fn( $r ) => (int) $r->id, $rows ) );
		$items    = array();
		foreach ( $rows as $row ) {
			$items[] = array_merge(
				SPLM_Discipline_Notice_REST::row_to_response( $row, true ),
				array( 'replaced' => in_array( (int) $row->id, $replaced, true ) )
			);
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
		$row = SPLM_Discipline_Suspension::duplicate_row(
			$existing_rows,
			(int) ( $input['player_id'] ?? 0 ),
			(int) ( $input['infraction_id'] ?? 0 ),
			(int) ( $input['incident_event_id'] ?? 0 ),
			$today
		);

		return null === $row ? 0 : (int) ( $row->id ?? 0 );
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
	 * Whether the player already has a manual suspension in force this season.
	 * Revoked, discarded, baseline and replaced rows never count as one.
	 *
	 * @param int      $season_id Season.
	 * @param object[] $existing  Existing rows.
	 * @return bool
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private static function has_prior_suspension( int $season_id, array $existing ): bool {
		foreach ( SPLM_Discipline_Suspension::in_force_rows( $existing ) as $row ) {
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

		if ( 0 === self::valid_season_id( $input['season_id'] ) ) {
			return self::create_wp_error( 'invalid_season' );
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
		$email  = SPLM_Discipline_Notice_Recipients::player_email( $player );

		return array(
			'elig'         => SPLM_Discipline_Eligibility::next_eligible( $player, $season, self::after_date( $input['incident_event_id'] ), $games ),
			'player_email' => $email,
			'captains'     => SPLM_Discipline_Suspension::captain_recipients( $player, $season ),
			'bcc'          => SPLM_Discipline_Suspension::delivery_bcc( $season, (string) $email['email'] ),
			'existing'     => SPLM_Discipline_Notice_Database::for_player( $player, false ),
		);
	}

	/**
	 * The local date eligibility counts from: the incident match, else today.
	 *
	 * @param int $event_id Incident event id (0 for none).
	 * @return string 'Y-m-d'.
	 */
	public static function after_date( int $event_id ): string {
		$date = self::incident_date( $event_id );

		return '' === $date ? current_time( 'Y-m-d' ) : $date;
	}

	/**
	 * The local date of a valid incident match, or '' when there is none (no
	 * id, not an sp_event any more, or trashed).
	 *
	 * @param int $event_id Incident event id (0 for none).
	 * @return string 'Y-m-d' or ''.
	 */
	public static function incident_date( int $event_id ): string {
		if ( $event_id > 0 && 'sp_event' === get_post_type( $event_id ) ) {
			$event = get_post( $event_id );
			if ( $event && ! empty( $event->post_date ) ) {
				return substr( (string) $event->post_date, 0, 10 );
			}
		}

		return '';
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
	 * The season id when it is a real sp_season term, else 0.
	 *
	 * @param int $season_id Season term id (0 when none could be found).
	 * @return int
	 */
	private static function valid_season_id( int $season_id ): int {
		if ( $season_id <= 0 ) {
			return 0;
		}

		$term = get_term( $season_id, 'sp_season' );

		return ( $term && ! is_wp_error( $term ) ) ? $season_id : 0;
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
		$error      = self::create_error( $event_ok, 'sp_player' === get_post_type( $input['player_id'] ), $infraction, self::valid_season_id( $input['season_id'] ) );

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
			$delivery = SPLM_Discipline_Suspension_Actions::deliver_locked( $id, $ctx, 'issued' );
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
}
