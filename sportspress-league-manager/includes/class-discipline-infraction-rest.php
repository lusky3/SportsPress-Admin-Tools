<?php
/**
 * REST write routes for the infraction list: create and update.
 *
 * There is no delete route. Infractions are never hard-deleted (notices link
 * `infraction_id`), so retiring one is an update with active = false. Edits
 * never rewrite existing notices, which carry their own snapshot.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Infraction_REST {

	const REST_NAMESPACE = 'splm/v1';

	// The editable fields a request may carry.
	const FIELDS = array( 'rule_ref', 'title', 'rule_text', 'outcome', 'default_games', 'needs_review', 'active', 'sort_order' );

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the create and update routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		$gate = array( 'SPLM_Discipline_Notice_REST', 'gate' );

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/infractions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => $gate,
				'args'                => self::field_args( true ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/discipline/infractions/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => $gate,
				'args'                => array_merge(
					array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'minimum'           => 1,
							'validate_callback' => 'rest_validate_request_arg',
							'sanitize_callback' => 'absint',
						),
					),
					self::field_args( false )
				),
			)
		);
	}

	/**
	 * The editable fields' arg declarations. Every arg declares BOTH
	 * callbacks (see SPLM_Discipline_Notice_REST::list_args()). No arg has a
	 * registered default: update must only touch the fields actually sent.
	 *
	 * @param bool $title_required Whether title is required (create).
	 * @return array
	 */
	private static function field_args( bool $title_required ): array {
		return array(
			'rule_ref'      => array(
				'required'          => false,
				'type'              => 'string',
				'maxLength'         => 20,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'title'         => array(
				'required'          => $title_required,
				'type'              => 'string',
				'maxLength'         => 120,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'rule_text'     => array(
				'required'          => false,
				'type'              => 'string',
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'outcome'       => array(
				'required'          => false,
				'type'              => 'string',
				'enum'              => array( 'games', 'indefinite' ),
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'sanitize_key',
			),
		) + self::flag_and_number_args();
	}

	/**
	 * The numeric and boolean field declarations.
	 *
	 * @return array
	 */
	private static function flag_and_number_args(): array {
		$bool = array(
			'required'          => false,
			'type'              => 'boolean',
			'validate_callback' => 'rest_validate_request_arg',
			'sanitize_callback' => 'rest_sanitize_boolean',
		);

		return array(
			'default_games' => array(
				'required'          => false,
				'type'              => 'integer',
				'minimum'           => 0,
				'maximum'           => 20,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'absint',
			),
			'needs_review'  => $bool,
			'active'        => $bool,
			'sort_order'    => array(
				'required'          => false,
				'type'              => 'integer',
				'minimum'           => -32768,
				'maximum'           => 32767,
				'validate_callback' => 'rest_validate_request_arg',
				'sanitize_callback' => 'intval',
			),
		);
	}

	/**
	 * An infraction row shaped for the API.
	 *
	 * @param object $row Infraction row.
	 * @return array
	 */
	public static function to_response( $row ): array {
		return array(
			'id'            => (int) $row->id,
			'rule_ref'      => (string) $row->rule_ref,
			'title'         => (string) $row->title,
			'rule_text'     => (string) $row->rule_text,
			'outcome'       => (string) $row->outcome,
			'default_games' => (int) $row->default_games,
			'needs_review'  => (bool) $row->needs_review,
			'active'        => (bool) ( $row->active ?? 1 ),
			'sort_order'    => (int) ( $row->sort_order ?? 0 ),
		);
	}

	/**
	 * Only the editable fields the request actually carries.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return array
	 */
	private static function sent_fields( $request ): array {
		$out = array();
		foreach ( self::FIELDS as $field ) {
			if ( $request->has_param( $field ) ) {
				$out[ $field ] = $request->get_param( $field );
			}
		}

		return $out;
	}

	/**
	 * 400 for an empty title.
	 *
	 * @return WP_Error
	 */
	private static function title_error(): WP_Error {
		return new WP_Error( 'invalid_title', __( 'An infraction needs a title.', 'sportspress-league-manager' ), array( 'status' => 400 ) );
	}

	/**
	 * 500 for a failed write.
	 *
	 * @return WP_Error
	 */
	private static function write_error(): WP_Error {
		return new WP_Error( 'splm_infraction_write_failed', __( 'Could not save the infraction.', 'sportspress-league-manager' ), array( 'status' => 500 ) );
	}

	/**
	 * POST /discipline/infractions
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function create( $request ) {
		$fields = self::sent_fields( $request );
		if ( '' === SPLM_Discipline_Infraction::sanitize_row( $fields )['title'] ) {
			return self::title_error();
		}

		$id = SPLM_Discipline_Infraction::insert_row( $fields );
		if ( $id <= 0 ) {
			return self::write_error();
		}

		return new WP_REST_Response( array( 'infraction' => self::to_response( SPLM_Discipline_Infraction::find( $id ) ) ), 201 );
	}

	/**
	 * POST /discipline/infractions/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function update( $request ) {
		$id       = absint( $request->get_param( 'id' ) );
		$existing = SPLM_Discipline_Infraction::find( $id );
		if ( ! $existing ) {
			return new WP_Error( 'splm_infraction_not_found', __( 'That infraction does not exist.', 'sportspress-league-manager' ), array( 'status' => 404 ) );
		}

		$fields = self::sent_fields( $request );
		$title  = SPLM_Discipline_Infraction::sanitize_row( array( 'title' => $fields['title'] ?? $existing->title ) )['title'];
		if ( '' === $title ) {
			return self::title_error();
		}
		if ( ! SPLM_Discipline_Infraction::update_row( $id, $fields ) ) {
			return self::write_error();
		}

		return new WP_REST_Response( array( 'infraction' => self::to_response( SPLM_Discipline_Infraction::find( $id ) ) ), 200 );
	}
}
