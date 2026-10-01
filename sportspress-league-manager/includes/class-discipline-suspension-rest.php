<?php
/**
 * REST routes for manually issued suspensions: infractions, a player's
 * history and a write-free preview.
 *
 * The preview answers "what would happen if I issued this?" — eligibility
 * date, who would be mailed, what the emails would say, and which warnings
 * apply — without touching the database. Every decision it makes lives in
 * plan_preview(), which calls no WordPress function, so the handlers stay
 * glue.
 *
 * @author Cody (lusky3)
 *
 * Three routes with their args blocks, plus the pure planner and the small
 * helpers that keep each method inside the complexity limit.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Suspension_REST {

	const REST_NAMESPACE = 'splm/v1';

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
		$infraction = SPLM_Discipline_Infraction::find( $input['infraction_id'] );

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
}
