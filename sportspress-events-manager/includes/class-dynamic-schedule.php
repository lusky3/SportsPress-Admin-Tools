<?php
/**
 * Dynamic Schedule
 *
 * Provides the [arl_schedule] shortcode: a SportsPress event list with
 * Season / Division / Team / Show dropdowns that refilter over AJAX.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The [arl_schedule] shortcode, its AJAX handler and its cache.
 *
 * One cohesive feature; the pure helpers are static so they can be tested
 * without WordPress, which is why the method count is high.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 */
class SPEM_Dynamic_Schedule {

	/**
	 * Lifetime, in seconds, of a rendered schedule block in the transient
	 * cache (5 minutes). A literal rather than MINUTE_IN_SECONDS so loading
	 * this file does not depend on WordPress's constants.
	 */
	const SCHEDULE_CACHE_TTL = 300;

	/**
	 * Option holding the cache namespace version; bumped by flush_cache().
	 */
	const CACHE_VERSION_OPTION = 'spem_schedule_cache_version';

	/**
	 * Hooks after which cached schedules and dropdown lists are stale.
	 */
	const FLUSH_HOOKS = array( 'save_post_sp_event', 'save_post_sp_calendar', 'edited_sp_season', 'edited_sp_league' );

	/**
	 * Fields validate_request() checks, in order. The calendar comes from
	 * the verified attribute blob, not from the request.
	 */
	const REQUEST_KEYS = array( 'calendar', 'season', 'league', 'team', 'status' );

	/**
	 * POST fields the AJAX handler reads.
	 */
	const POST_FIELDS = array( 'atts', 'sig', 'season', 'league', 'team', 'status' );

	/**
	 * Row cap for visitor-driven (AJAX) renders that have no number limit;
	 * filterable via spem_schedule_max_rows. The initial view is not capped.
	 */
	const MAX_AJAX_ROWS = 500;

	/**
	 * Query parameters the front end writes for shareable links.
	 */
	const URL_PARAMS = array( 'season', 'division', 'team', 'show' );

	/**
	 * Longest accepted data-atts blob (base64), checked before decoding.
	 */
	const MAX_ATTS_LENGTH = 4096;

	/**
	 * Values of the Show dropdown.
	 */
	const DROPDOWN_STATUSES = array( 'all', 'upcoming', 'results' );

	/**
	 * Shape of a term slug a request may carry (checked before any lookup).
	 */
	const SLUG_PATTERN = '/^[a-z0-9_-]{1,200}$/';

	/**
	 * Shape of a post id a request may carry.
	 */
	const ID_PATTERN = '/^[1-9][0-9]{0,19}$/';

	/**
	 * How many [arl_schedule] blocks this request has rendered; later ones
	 * get suffixed element ids so labels stay unique.
	 *
	 * @var int
	 */
	private static $instances = 0;

	/**
	 * Grouped seasons, memoised for the request.
	 *
	 * @var array|null
	 */
	private $seasons = null;

	public function __construct() {
		add_shortcode( 'arl_schedule', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_spem_get_schedule', array( $this, 'ajax_get_schedule' ) );
		add_action( 'wp_ajax_nopriv_spem_get_schedule', array( $this, 'ajax_get_schedule' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
		foreach ( self::FLUSH_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'flush_cache' ) );
		}
	}

	/**
	 * Whether a page's content uses [arl_schedule] (and so needs the assets).
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function content_needs_assets( string $content ): bool {
		return has_shortcode( $content, 'arl_schedule' );
	}

	/**
	 * Enqueue the filtering script and styles only on pages that use the
	 * shortcode. The nonce is the one the AJAX handler checks.
	 */
	public function maybe_enqueue(): void {
		global $post;
		if ( ! is_a( $post, 'WP_Post' ) || ! self::content_needs_assets( (string) $post->post_content ) ) {
			return;
		}

		// plugin_dir_url( __DIR__ ) is the plugin root (see SPEM_Dynamic_Standings).
		$plugin_url = plugin_dir_url( __DIR__ );

		wp_enqueue_script( 'spem-dynamic-schedule', $plugin_url . 'assets/js/dynamic-schedule.js', array( 'jquery' ), SPEM_VERSION, true );
		wp_localize_script(
			'spem-dynamic-schedule',
			'spemSchedule',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'spem_schedule_nonce' ),
			)
		);
		wp_enqueue_style( 'spem-dynamic-schedule', $plugin_url . 'assets/css/dynamic-schedule.css', array(), SPEM_VERSION );
	}

	/**
	 * Render the [arl_schedule] shortcode: filter bar plus the initial list.
	 *
	 * With an id and no attributes the list is the Calendar's own, exactly
	 * (id route). Without an id the default season is applied so the list
	 * matches the Season dropdown. An id that is not a published Calendar
	 * gives only the "Calendar not found." notice (no filter bar).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML.
	 */
	public function render_shortcode( $atts ): string {
		$atts = SPEM_Schedule_Args::normalize_atts( (array) $atts );
		if ( isset( $atts['id'] ) && ! $this->is_published_calendar( absint( $atts['id'] ) ) ) {
			return $this->notice( __( 'Calendar not found.', 'sportspress-events-manager' ) );
		}
		$defaults     = $this->effective_defaults( $atts );
		$view         = $this->initial_view( $atts, $defaults );
		$view['html'] = $this->get_cached_html( $this->initial_args( $atts, $defaults ) );
		return $this->filter_bar_markup( $view );
	}

	/**
	 * AJAX handler: the refiltered list plus the dependent dropdown options.
	 *
	 * Public on purpose (wp_ajax_nopriv): it only reads published schedule
	 * data, like [arl_standings]. The shortcode's attributes come back as a
	 * signed blob; the signature and every visitor value are checked before
	 * anything is read from or written to the cache.
	 */
	public function ajax_get_schedule(): void {
		check_ajax_referer( 'spem_schedule_nonce', '_ajax_nonce' );

		$post    = $this->read_post();
		$atts    = self::verify_atts( self::as_string( $post['atts'] ), self::as_string( $post['sig'] ), wp_salt( 'nonce' ) );
		$request = null === $atts ? false : self::validate_request(
			array( 'calendar' => $atts['id'] ?? '' ) + $post,
			$this->existence_checks(),
			$this->allows_all_seasons( $atts )
		);
		if ( false === $request ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'sportspress-events-manager' ) ) );
			return;
		}

		wp_send_json_success( $this->ajax_payload( $atts, $request ) );
	}

	/**
	 * A request value as a string; '' for anything non-scalar.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function as_string( $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Sign the normalised attributes for the round trip through the page.
	 *
	 * @param array $atts Normalised attributes.
	 * @return array { atts: base64 JSON, sig: HMAC-SHA256 hex }
	 */
	private function sign_atts( array $atts ): array {
		$json = (string) wp_json_encode( $atts );
		return array(
			'atts' => base64_encode( $json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- transport encoding of signed data.
			'sig'  => hash_hmac( 'sha256', $json, wp_salt( 'nonce' ) ),
		);
	}

	/**
	 * Verify a signed attribute blob and return the normalised attributes.
	 *
	 * The signature is checked (constant time) before the JSON is parsed.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param string $blob Base64 JSON from data-atts.
	 * @param string $sig  Hex HMAC from data-sig.
	 * @param string $key  HMAC key.
	 * @return array|null Attributes, or null when missing, oversized or forged.
	 */
	public static function verify_atts( string $blob, string $sig, string $key ): ?array {
		if ( '' === $blob || strlen( $blob ) > self::MAX_ATTS_LENGTH || ! preg_match( '/^[a-f0-9]{64}$/', $sig ) ) {
			return null;
		}
		$json = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- transport encoding of signed data.
		if ( false === $json || ! hash_equals( hash_hmac( 'sha256', $json, $key ), $sig ) ) {
			return null;
		}
		$atts = json_decode( $json, true );
		return is_array( $atts ) ? SPEM_Schedule_Args::normalize_atts( $atts ) : null;
	}

	/**
	 * Build the AJAX response for verified attributes and a validated request.
	 *
	 * Unsent fields take their default (Calendar, then attribute). The
	 * dependent lists come first: a division or team, sent or defaulted,
	 * with no games in the chosen season is reset to 'all' before rendering,
	 * so the list and the dropdowns agree. 'selected' is the effective value
	 * of every dropdown.
	 *
	 * @param array $atts    Verified attributes.
	 * @param array $request Output of validate_request().
	 * @return array { html, leagues, teams, selected }
	 */
	private function ajax_payload( array $atts, array $request ): array {
		$defaults  = $this->effective_defaults( $atts );
		$effective = array_merge( $defaults, array_intersect_key( $request, $defaults ) );
		$leagues   = $this->get_leagues_for_season( $effective['season'] );
		$effective = self::reset_unlisted( $effective, 'league', array_column( $leagues, 'slug' ) );
		$teams     = $this->get_teams_for( $effective['season'], 'all' === $effective['league'] ? '' : $effective['league'] );
		$effective = self::reset_unlisted( $effective, 'team', array_map( 'strval', array_column( $teams, 'id' ) ) );

		return array(
			'html'     => $this->get_cached_html( $this->request_args( $atts, $defaults, $effective ) ),
			'leagues'  => $leagues,
			'teams'    => $teams,
			'selected' => $effective,
		);
	}

	/**
	 * Reset a league or team that is not among the options to 'all'.
	 *
	 * @param array    $request Request or effective selection.
	 * @param string   $key     league|team.
	 * @param string[] $allowed Option values for the chosen season.
	 * @return array
	 */
	public static function reset_unlisted( array $request, string $key, array $allowed ): array {
		$value = $request[ $key ] ?? 'all';
		if ( 'all' !== $value && ! in_array( (string) $value, $allowed, true ) ) {
			$request[ $key ] = 'all';
		}
		return $request;
	}

	/**
	 * Check a request against the allowed shapes and real data.
	 *
	 * Empty fields (and calendar '0') count as not sent. 'all' is kept for
	 * league, team and status, and for season only when $allow_all_season
	 * (a Calendar with no season). Any other value must match its pattern
	 * and pass the existence check for its key; a missing check fails closed.
	 *
	 * @param array $post             Unslashed, sanitised request fields.
	 * @param array $exists           Callables keyed calendar|season|league|team,
	 *                                each taking the value and returning bool.
	 * @param bool  $allow_all_season Whether season may be 'all'.
	 * @return array|false The accepted fields, or false when any is invalid.
	 */
	public static function validate_request( array $post, array $exists, bool $allow_all_season = false ): array|false {
		$all_keys = $allow_all_season ? array( 'season', 'league', 'team' ) : array( 'league', 'team' );
		$out      = array();
		foreach ( self::REQUEST_KEYS as $key ) {
			$value = $post[ $key ] ?? '';
			if ( ! is_scalar( $value ) ) {
				return false;
			}
			$value = trim( (string) $value );
			if ( self::is_unsent( $key, $value ) ) {
				continue;
			}
			if ( ! self::is_valid_value( $key, $value, $exists, $all_keys ) ) {
				return false;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * Whether a request field counts as not sent.
	 *
	 * @param string $key   Field.
	 * @param string $value Trimmed value.
	 * @return bool
	 */
	private static function is_unsent( string $key, string $value ): bool {
		return '' === $value || ( 'calendar' === $key && '0' === $value );
	}

	/**
	 * Whether one request value is acceptable.
	 *
	 * @param string   $key      Field.
	 * @param string   $value    Trimmed value.
	 * @param array    $exists   Existence checks.
	 * @param string[] $all_keys Fields that may be 'all'.
	 * @return bool
	 */
	private static function is_valid_value( string $key, string $value, array $exists, array $all_keys ): bool {
		if ( 'status' === $key ) {
			return in_array( $value, self::DROPDOWN_STATUSES, true );
		}
		if ( 'all' === $value ) {
			return in_array( $key, $all_keys, true );
		}
		$pattern = in_array( $key, array( 'calendar', 'team' ), true ) ? self::ID_PATTERN : self::SLUG_PATTERN;
		if ( ! preg_match( $pattern, $value ) || ! isset( $exists[ $key ] ) ) {
			return false;
		}
		return (bool) call_user_func( $exists[ $key ], $value );
	}

	/**
	 * The real-data checks validate_request() runs.
	 *
	 * @return array
	 */
	private function existence_checks(): array {
		return array(
			'calendar' => fn( $id ) => $this->is_published_calendar( (int) $id ),
			'season'   => fn( $slug ) => $this->is_base_season( (string) $slug ),
			'league'   => fn( $slug ) => $this->slug_exists( (string) $slug, 'sp_league' ),
			'team'     => fn( $id ) => 'sp_team' === get_post_type( (int) $id ) && 'publish' === get_post_status( (int) $id ),
		);
	}

	/**
	 * Whether a slug is a base (non-playoff) season, as listed in the Season
	 * dropdown. Checked without the cached season list so a rejected request
	 * writes no transient.
	 *
	 * @param string $slug Slug.
	 * @return bool
	 */
	private function is_base_season( string $slug ): bool {
		$term = get_term_by( 'slug', $slug, 'sp_season' );
		return $term && ! is_wp_error( $term ) && ! self::is_playoff_name( (string) $term->name );
	}

	/**
	 * Whether a term slug exists in a taxonomy.
	 *
	 * @param string $slug     Slug.
	 * @param string $taxonomy Taxonomy.
	 * @return bool
	 */
	private function slug_exists( string $slug, string $taxonomy ): bool {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		return $term && ! is_wp_error( $term );
	}

	/**
	 * The request fields, unslashed and sanitised. The nonce is checked by
	 * ajax_get_schedule() before this runs.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 *
	 * @return array
	 */
	private function read_post(): array {
		$post = array();
		foreach ( self::POST_FIELDS as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by the caller; sanitised on the next line.
			$raw          = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '';
			$post[ $key ] = is_scalar( $raw ) ? sanitize_text_field( (string) $raw ) : array();
		}
		return $post;
	}

	/**
	 * Turn the effective selection into [event_list] arguments.
	 *
	 * Only values that differ from the defaults count as visitor choices, so
	 * the client may always send all four fields and an unchanged (or Reset)
	 * view is exactly the initial view. Visitor 'all' clears league or team
	 * and means SportsPress 'any' for status. Without a visitor season the
	 * effective season is a base value, so a Calendar with several seasons
	 * stays on the one the dropdown shows. Once Season or Show differs from
	 * its default, the date window is blocked with explicit '' values so the
	 * detached route cannot copy it back; the number limit is kept and, when
	 * there is none, capped (cap_rows()).
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array $atts      Verified attributes (the base arguments).
	 * @param array $defaults  Output of effective_defaults().
	 * @param array $effective Effective dropdown values.
	 * @return array
	 */
	private function request_args( array $atts, array $defaults, array $effective ): array {
		$visitor = array_diff_assoc( $effective, $defaults );
		if ( ! $visitor ) {
			return $this->initial_args( $atts, $defaults );
		}
		if ( isset( $visitor['status'] ) ) {
			$visitor['status'] = (string) SPEM_Schedule_Args::normalize_status( $visitor['status'] );
		}
		$args = SPEM_Schedule_Args::merge( $this->with_season( $atts, $effective['season'] ), $visitor );
		if ( isset( $visitor['season'] ) || isset( $visitor['status'] ) ) {
			$args = SPEM_Schedule_Args::drop_initial_only( $args, $visitor ) + array_fill_keys( SPEM_Schedule_Args::INITIAL_ONLY_KEYS, '' );
		}
		return $this->cap_rows( $args );
	}

	/**
	 * Make the number limit explicit on a visitor-driven render: the
	 * attribute, else the Calendar's own, else the row cap. Without it a
	 * dropped date window on a Calendar with no season would render every
	 * game ever played.
	 *
	 * @param array $args Arguments.
	 * @return array
	 */
	private function cap_rows( array $args ): array {
		$number         = (int) ( $args['number'] ?? $this->calendar_number( absint( $args['id'] ?? 0 ) ) );
		$args['number'] = (string) ( $number > 0 ? $number : self::max_rows() );
		return $args;
	}

	/**
	 * The Calendar's number limit (0 for none, unlimited or no Calendar).
	 *
	 * @param int $calendar_id Calendar id.
	 * @return int
	 */
	private function calendar_number( int $calendar_id ): int {
		return $calendar_id ? max( 0, (int) get_post_meta( $calendar_id, 'sp_number', true ) ) : 0;
	}

	/**
	 * The row cap for visitor-driven renders.
	 *
	 * @return int
	 */
	private static function max_rows(): int {
		return max( 1, (int) apply_filters( 'spem_schedule_max_rows', self::MAX_AJAX_ROWS ) );
	}

	/**
	 * Arguments of the initial view: the attributes as given; without a
	 * Calendar id, the default season too (the list then matches the Season
	 * dropdown). With an id nothing is injected, so no flags means the
	 * Calendar's own settings exactly.
	 *
	 * @param array $atts     Normalised attributes.
	 * @param array $defaults Output of effective_defaults().
	 * @return array
	 */
	private function initial_args( array $atts, array $defaults ): array {
		return empty( $atts['id'] ) ? $this->with_season( $atts, $defaults['season'] ) : $atts;
	}

	/**
	 * Add a season unless the arguments already name one or it is 'all'.
	 *
	 * @param array  $args   Arguments.
	 * @param string $season Base season slug, 'all' or ''.
	 * @return array
	 */
	private function with_season( array $args, string $season ): array {
		if ( empty( $args['season'] ) && ! in_array( $season, array( '', 'all' ), true ) ) {
			$args['season'] = $season;
		}
		return $args;
	}

	/**
	 * Whether the Season dropdown offers "All seasons": a Calendar id is
	 * given and neither an attribute nor the Calendar sets a season.
	 *
	 * @param array $atts Normalised attributes.
	 * @return bool
	 */
	private function allows_all_seasons( array $atts ): bool {
		return ! empty( $atts['id'] ) && empty( $atts['season'] ) && ! $this->calendar_season_ids( absint( $atts['id'] ) );
	}

	/**
	 * Dropdown defaults (Calendar setting, overridden by attributes).
	 *
	 * @param array $atts Normalised attributes.
	 * @return array { season, league, team, status }
	 */
	private function effective_defaults( array $atts ): array {
		$calendar = absint( $atts['id'] ?? 0 );
		return array(
			'season' => $this->default_season( $atts ),
			'league' => $this->default_league( $atts, $calendar ),
			'team'   => $this->default_team( $atts, $calendar ),
			'status' => $this->default_status( $atts, $calendar ),
		);
	}

	/**
	 * The Calendar's season term ids (including an auto term), newest first.
	 *
	 * @param int $calendar_id Calendar id, 0 for none.
	 * @return string[]
	 */
	private function calendar_season_ids( int $calendar_id ): array {
		if ( ! $calendar_id ) {
			return array();
		}
		$ids = array_map( 'intval', $this->single_term_ids( $calendar_id, 'sp_season' ) );
		rsort( $ids );
		return array_map( 'strval', $ids );
	}

	/**
	 * Season dropdown default: 'all' for a Calendar without a season (see
	 * allows_all_seasons()); else attribute, else the Calendar's season,
	 * else the site's current season, else the newest.
	 *
	 * @param array $atts Attributes (id and season are read).
	 * @return string Base season slug, 'all', or '' when there are no seasons.
	 */
	private function default_season( array $atts ): string {
		if ( $this->allows_all_seasons( $atts ) ) {
			return 'all';
		}
		$candidates = array_merge(
			array( (string) ( $atts['season'] ?? '' ) ),
			$this->calendar_season_ids( absint( $atts['id'] ?? 0 ) ),
			array( (string) get_option( 'spem_current_season_id', '' ) )
		);
		return self::pick_season( $this->get_grouped_seasons(), $candidates );
	}

	/**
	 * Division dropdown default: attribute, else the Calendar's single
	 * league, else 'all'.
	 *
	 * @param array $args        Arguments.
	 * @param int   $calendar_id Calendar id.
	 * @return string League slug or 'all'.
	 */
	private function default_league( array $args, int $calendar_id ): string {
		$value = (string) ( $args['league'] ?? '' );
		if ( '' === $value && $calendar_id ) {
			$ids   = $this->single_term_ids( $calendar_id, 'sp_league' );
			$value = 1 === count( $ids ) ? (string) reset( $ids ) : '';
		}
		$term = '' === $value ? null : $this->find_term( $value, 'sp_league' );
		return $term ? (string) $term->slug : 'all';
	}

	/**
	 * Team dropdown default: attribute, else the Calendar's single team,
	 * else 'all'.
	 *
	 * @param array $args        Arguments.
	 * @param int   $calendar_id Calendar id.
	 * @return string Team id or 'all'.
	 */
	private function default_team( array $args, int $calendar_id ): string {
		$team = (string) ( $args['team'] ?? '' );
		if ( '' === $team && $calendar_id ) {
			$team = $this->calendar_single_team( $calendar_id );
		}
		return ctype_digit( $team ) && '0' !== $team ? $team : 'all';
	}

	/**
	 * Show dropdown default: attribute, else the Calendar's status.
	 *
	 * @param array $args        Arguments.
	 * @param int   $calendar_id Calendar id.
	 * @return string all|upcoming|results.
	 */
	private function default_status( array $args, int $calendar_id ): string {
		$status = (string) ( $args['status'] ?? '' );
		if ( '' === $status && $calendar_id ) {
			$status = (string) get_post_meta( $calendar_id, 'sp_status', true );
		}
		return self::to_dropdown_status( $status );
	}

	/**
	 * Map a SportsPress status to a Show dropdown value.
	 *
	 * @param string $status any|future|publish (anything else counts as any).
	 * @return string all|upcoming|results.
	 */
	public static function to_dropdown_status( string $status ): string {
		$map = array(
			'future'  => 'upcoming',
			'publish' => 'results',
		);
		return $map[ $status ] ?? 'all';
	}

	/**
	 * Everything the filter bar needs for the initial render.
	 *
	 * @param array $atts     Normalised attributes.
	 * @param array $defaults Output of effective_defaults().
	 * @return array
	 */
	private function initial_view( array $atts, array $defaults ): array {
		$calendar = absint( $atts['id'] ?? 0 );
		$league   = 'all' === $defaults['league'] ? '' : $defaults['league'];
		++self::$instances;

		return array(
			'calendar'  => $calendar ? (string) $calendar : '',
			'signed'    => $this->sign_atts( $atts ),
			'defaults'  => $defaults,
			'seasons'   => $this->season_options( $defaults['season'] ),
			'leagues'   => $this->with_league( $this->get_leagues_for_season( $defaults['season'] ), $league ),
			'teams'     => $this->with_team( $this->get_teams_for( $defaults['season'], $league ), $defaults['team'] ),
			'reset_url' => remove_query_arg( self::URL_PARAMS ),
			'suffix'    => self::$instances > 1 ? '-' . self::$instances : '',
		);
	}

	/**
	 * Season dropdown options; "All seasons" only when it is the default.
	 *
	 * @param string $default Default season value.
	 * @return array value => label.
	 */
	private function season_options( string $default ): array {
		$options = array_column( $this->get_grouped_seasons(), 'label', 'slug' );
		return 'all' === $default ? array( 'all' => __( 'All seasons', 'sportspress-events-manager' ) ) + $options : $options;
	}

	/**
	 * Make sure the default league is selectable even when it has no games
	 * in the default season, so the dropdown matches the list shown.
	 *
	 * @param array  $leagues Options [ { slug, name } ].
	 * @param string $slug    Default league slug, '' for all.
	 * @return array
	 */
	private function with_league( array $leagues, string $slug ): array {
		if ( '' === $slug || in_array( $slug, array_column( $leagues, 'slug' ), true ) ) {
			return $leagues;
		}
		$term      = $this->find_term( $slug, 'sp_league' );
		$leagues[] = array(
			'slug' => $slug,
			'name' => $term ? $term->name : $slug,
		);
		return $leagues;
	}

	/**
	 * Make sure the default team is selectable (see with_league()).
	 *
	 * @param array  $teams Options [ { id, name } ].
	 * @param string $team  Default team id or 'all'.
	 * @return array
	 */
	private function with_team( array $teams, string $team ): array {
		if ( 'all' === $team || in_array( (int) $team, array_column( $teams, 'id' ), true ) ) {
			return $teams;
		}
		$teams[] = array(
			'id'   => (int) $team,
			'name' => get_the_title( (int) $team ),
		);
		return $teams;
	}

	/**
	 * The wrapper, filter bar and content region.
	 *
	 * @param array $view Output of initial_view() plus 'html'.
	 * @return string
	 */
	private function filter_bar_markup( array $view ): string {
		$suffix   = $view['suffix'];
		$defaults = $view['defaults'];

		$filters = $this->select_markup( 'season', $suffix, __( 'Season', 'sportspress-events-manager' ), $view['seasons'], $defaults['season'] )
			. $this->select_markup( 'league', $suffix, __( 'Division', 'sportspress-events-manager' ), array( 'all' => __( 'All divisions', 'sportspress-events-manager' ) ) + array_column( $view['leagues'], 'name', 'slug' ), $defaults['league'] )
			. $this->select_markup( 'team', $suffix, __( 'Team', 'sportspress-events-manager' ), array( 'all' => __( 'All teams', 'sportspress-events-manager' ) ) + array_column( $view['teams'], 'name', 'id' ), $defaults['team'] )
			. $this->select_markup( 'status', $suffix, __( 'Show', 'sportspress-events-manager' ), $this->status_options(), $defaults['status'] );

		// Trust boundary: $view['html'] is SportsPress's own [event_list]
		// output (it escapes its own markup) or our escaped notice paragraph,
		// built from validated arguments. It must not be re-escaped.
		return '<div class="arl-schedule-wrap" data-calendar="' . esc_attr( $view['calendar'] ) . '" data-defaults=\'' . esc_attr( wp_json_encode( $defaults ) ) . '\''
			. ' data-atts="' . esc_attr( $view['signed']['atts'] ) . '" data-sig="' . esc_attr( $view['signed']['sig'] ) . '">'
			. '<div class="arl-schedule-filters">' . $filters
			. '<a class="arl-schedule-reset" href="' . esc_url( $view['reset_url'] ) . '">' . esc_html( __( 'Reset', 'sportspress-events-manager' ) ) . '</a>'
			. '</div>'
			. '<div class="arl-schedule-content" aria-live="polite">' . $view['html'] . '</div>'
			. '</div>';
	}

	/**
	 * Show dropdown options.
	 *
	 * @return array value => label.
	 */
	private function status_options(): array {
		return array(
			'all'      => __( 'All games', 'sportspress-events-manager' ),
			'upcoming' => __( 'Upcoming', 'sportspress-events-manager' ),
			'results'  => __( 'Results', 'sportspress-events-manager' ),
		);
	}

	/**
	 * One labelled select.
	 *
	 * @param string $key      Filter name (also the POST field).
	 * @param string $suffix   Id suffix for a second block on the page.
	 * @param string $label    Visible label.
	 * @param array  $options  value => label.
	 * @param string $selected Selected value.
	 * @return string
	 */
	private function select_markup( string $key, string $suffix, string $label, array $options, string $selected ): string {
		$id   = 'arl-schedule-' . $key . $suffix;
		$html = '';
		foreach ( $options as $value => $text ) {
			$html .= '<option value="' . esc_attr( $value ) . '"' . ( (string) $value === $selected ? ' selected' : '' ) . '>' . esc_html( $text ) . '</option>';
		}
		return '<div class="arl-schedule-filter">'
			. '<label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>'
			. '<select id="' . esc_attr( $id ) . '" class="arl-schedule-select" data-filter="' . esc_attr( $key ) . '">' . $html . '</select>'
			. '</div>';
	}

	/**
	 * Flush every cached schedule and dropdown list.
	 *
	 * Same approach as SPEM_Dynamic_Standings::flush_standings_cache(): bump
	 * a version atomically in SQL so concurrent flushes both count; old keys
	 * become unreachable and expire on their own TTL.
	 */
	public function flush_cache(): void {
		global $wpdb;

		add_option( self::CACHE_VERSION_OPTION, 1, '', false );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- atomic increment, no caching needed.
		$wpdb->query(
			"UPDATE {$wpdb->options}
			SET option_value = option_value + 1
			WHERE option_name = 'spem_schedule_cache_version'"
		);

		wp_cache_delete( self::CACHE_VERSION_OPTION, 'options' );
	}

	/**
	 * Rendered list HTML for validated arguments, via the transient cache.
	 *
	 * Callers reached from a request MUST pass arguments built from
	 * validate_request() output, so only real values form cache keys.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private function get_cached_html( array $args ): string {
		return (string) $this->remember( $args + array( '_kind' => 'html' ), fn() => $this->render_with_playoffs( $args ) );
	}

	/**
	 * Read a value from the versioned transient cache, building it on a miss.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param array    $parts Key parts (validated values only).
	 * @param callable $build Builds the value.
	 * @return mixed
	 */
	private function remember( array $parts, callable $build ) {
		$key   = SPEM_Schedule_Args::cache_key( (int) get_option( self::CACHE_VERSION_OPTION, 1 ), $parts );
		$value = get_transient( $key );
		if ( false === $value ) {
			$value = $build();
			set_transient( $key, $value, self::SCHEDULE_CACHE_TTL );
		}
		return $value;
	}

	/**
	 * Render, adding a base season's playoff games to the same list and
	 * bounding the event query by the number limit.
	 *
	 * SportsPress's season attribute takes one term id (SP_Calendar::data()
	 * wraps it in a one-element array), and without a Calendar id its query
	 * fetches every match (posts_per_page -1) before the template trims to
	 * number. A short-lived pre_get_posts hook adds the playoff term to the
	 * sp_season clause and applies the limit to the query itself.
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	private function render_with_playoffs( array $args ): string {
		$season = (string) ( $args['season'] ?? '' );
		$ids    = '' === $season ? array() : $this->season_term_ids( $season, true );
		$ids    = count( $ids ) > 1 ? $ids : array();
		$limit  = max( 0, (int) ( $args['number'] ?? 0 ) );
		if ( ! $ids && ! $limit ) {
			return $this->render_games( $args );
		}
		$hook = static function ( $query ) use ( $ids, $limit ) {
			self::adjust_query( $query, $ids, $limit );
		};
		add_action( 'pre_get_posts', $hook );
		try {
			return $this->render_games( $args );
		} finally {
			remove_action( 'pre_get_posts', $hook );
		}
	}

	/**
	 * pre_get_posts body for an sp_event query: limit an unlimited query to
	 * $limit rows and widen its season clause to the group's terms. The
	 * template trims to the same number in the same order, so the output is
	 * unchanged.
	 *
	 * @param object $query WP_Query.
	 * @param int[]  $ids   Season group term ids (empty for none).
	 * @param int    $limit Row limit (0 for none).
	 */
	private static function adjust_query( $query, array $ids, int $limit ): void {
		if ( 'sp_event' !== $query->get( 'post_type' ) ) {
			return;
		}
		if ( $limit && -1 === (int) $query->get( 'posts_per_page' ) ) {
			$query->set( 'posts_per_page', $limit );
		}
		$tax = $query->get( 'tax_query' );
		if ( $ids && is_array( $tax ) ) {
			$query->set( 'tax_query', self::widen_season_clauses( $tax, $ids ) );
		}
	}

	/**
	 * Add the group's term ids to any sp_season clause that names one of them.
	 *
	 * @param array $tax_query Tax query.
	 * @param int[] $ids       Season group term ids.
	 * @return array
	 */
	public static function widen_season_clauses( array $tax_query, array $ids ): array {
		foreach ( $tax_query as $index => $clause ) {
			if ( ! is_array( $clause ) || 'sp_season' !== ( $clause['taxonomy'] ?? '' ) ) {
				continue;
			}
			$terms = array_map( 'intval', (array) ( $clause['terms'] ?? array() ) );
			if ( array_intersect( $terms, $ids ) ) {
				$tax_query[ $index ]['terms'] = array_values( array_unique( array_merge( $terms, $ids ) ) );
			}
		}
		return $tax_query;
	}

	/**
	 * Term ids of a season value. A base season (slug or base term id) gives
	 * its whole group, playoffs included; any other real term gives itself.
	 *
	 * @param string $season     Season slug or term id.
	 * @param bool   $group_only Only return a group (no single-term fallback).
	 * @return int[]
	 */
	private function season_term_ids( string $season, bool $group_only = false ): array {
		foreach ( $this->get_grouped_seasons() as $group ) {
			if ( $group['slug'] === $season || (string) $group['term_ids'][0] === $season ) {
				return $group['term_ids'];
			}
		}
		$term = $group_only ? null : $this->find_term( $season, 'sp_season' );
		return $term ? array( (int) $term->term_id ) : array();
	}

	/**
	 * Seasons for the dropdown, grouped and newest first, limited to those
	 * with at least one event (any status). Cached.
	 *
	 * @return array [ { slug, label, term_ids } ]
	 */
	private function get_grouped_seasons(): array {
		if ( null === $this->seasons ) {
			$this->seasons = (array) $this->remember( array( '_kind' => 'seasons' ), fn() => $this->build_grouped_seasons() );
		}
		return $this->seasons;
	}

	/**
	 * Uncached body of get_grouped_seasons().
	 *
	 * @return array
	 */
	private function build_grouped_seasons(): array {
		$terms = get_terms(
			array(
				'taxonomy'   => 'sp_season',
				'hide_empty' => false,
				'orderby'    => 'term_id',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}
		return self::group_seasons( $terms, $this->season_ids_with_events() );
	}

	/**
	 * Group season terms: "X" and "X Playoffs" become one entry under X.
	 * Same rule as SPEM_Dynamic_Standings (name contains "Playoff", slug
	 * minus a -playoffs suffix is the base). A group is kept when any of its
	 * terms has events.
	 *
	 * @param object[] $terms       Terms (term_id, slug, name), newest first.
	 * @param int[]    $with_events Term ids that have at least one event.
	 * @return array [ { slug, label, term_ids } ]
	 */
	public static function group_seasons( array $terms, array $with_events ): array {
		$playoffs = self::playoff_ids_by_base( $terms );
		$groups   = array();
		foreach ( $terms as $term ) {
			if ( self::is_playoff_name( $term->name ) ) {
				continue;
			}
			$ids = array_merge( array( (int) $term->term_id ), $playoffs[ $term->slug ] ?? array() );
			if ( array_intersect( $ids, $with_events ) ) {
				$groups[] = array(
					'slug'     => $term->slug,
					'label'    => $term->name,
					'term_ids' => $ids,
				);
			}
		}
		return $groups;
	}

	/**
	 * Playoff term ids keyed by their base slug.
	 *
	 * @param object[] $terms Terms.
	 * @return array
	 */
	private static function playoff_ids_by_base( array $terms ): array {
		$map = array();
		foreach ( $terms as $term ) {
			$base = self::base_slug( $term->slug );
			if ( self::is_playoff_name( $term->name ) && $base !== $term->slug ) {
				$map[ $base ][] = (int) $term->term_id;
			}
		}
		return $map;
	}

	/**
	 * Pick the first candidate that names a season group.
	 *
	 * @param array $groups     Output of group_seasons().
	 * @param array $candidates Slugs or term ids, in priority order.
	 * @return string Group slug; the newest group when none match; '' when
	 *                there are no groups.
	 */
	public static function pick_season( array $groups, array $candidates ): string {
		foreach ( $candidates as $candidate ) {
			foreach ( $groups as $group ) {
				if ( self::group_matches( $group, (string) $candidate ) ) {
					return $group['slug'];
				}
			}
		}
		return $groups[0]['slug'] ?? '';
	}

	/**
	 * Whether a slug or term id belongs to a season group.
	 *
	 * @param array  $group Group.
	 * @param string $value Slug or term id.
	 * @return bool
	 */
	private static function group_matches( array $group, string $value ): bool {
		if ( '' === $value ) {
			return false;
		}
		if ( ctype_digit( $value ) ) {
			return in_array( (int) $value, $group['term_ids'], true );
		}
		return $group['slug'] === self::base_slug( $value );
	}

	/**
	 * Whether a season name is a playoff season.
	 *
	 * @param string $name Term name.
	 * @return bool
	 */
	private static function is_playoff_name( string $name ): bool {
		return false !== stripos( $name, 'Playoff' );
	}

	/**
	 * A season slug without its playoff suffix (as in standings).
	 *
	 * @param string $slug Slug.
	 * @return string
	 */
	private static function base_slug( string $slug ): string {
		return (string) preg_replace( '/-?playoffs?$/i', '', $slug );
	}

	/**
	 * Season term ids that have at least one published or scheduled event.
	 * One set-based query.
	 *
	 * @return int[]
	 */
	private function season_ids_with_events(): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one set-based lookup; the caller caches the result.
		$ids = $wpdb->get_col(
			"SELECT DISTINCT tt.term_id
			FROM {$wpdb->term_relationships} tr
			INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'sp_season'
			INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
			WHERE p.post_type = 'sp_event' AND p.post_status IN ( 'publish', 'future' )"
		);
		return array_map( 'absint', (array) $ids );
	}

	/**
	 * Divisions with events in a season (any status), by name. Cached.
	 *
	 * @param string $season Season slug.
	 * @return array [ { slug, name } ]
	 */
	private function get_leagues_for_season( string $season ): array {
		return (array) $this->remember(
			array(
				'_kind'  => 'leagues',
				'season' => $season,
			),
			fn() => $this->query_leagues( $this->season_scope( $season ) )
		);
	}

	/**
	 * Teams with events in a season, optionally within one division, by
	 * name. Cached.
	 *
	 * @param string $season Season slug.
	 * @param string $league League slug, '' for all.
	 * @return array [ { id, name } ]
	 */
	private function get_teams_for( string $season, string $league ): array {
		return (array) $this->remember(
			array(
				'_kind'  => 'teams',
				'season' => $season,
				'league' => $league,
			),
			fn() => $this->query_teams( $this->season_scope( $season ), $this->league_term_id( $league ) )
		);
	}

	/**
	 * Season term ids for the option queries; null means every season.
	 *
	 * @param string $season Season slug or 'all'.
	 * @return int[]|null
	 */
	private function season_scope( string $season ): ?array {
		return 'all' === $season ? null : $this->season_term_ids( $season );
	}

	/**
	 * Term id of a league slug, 0 for '' or unknown.
	 *
	 * @param string $league Slug.
	 * @return int
	 */
	private function league_term_id( string $league ): int {
		$term = '' === $league ? null : $this->find_term( $league, 'sp_league' );
		return $term ? (int) $term->term_id : 0;
	}

	/**
	 * SQL condition: p is a published or scheduled event in one of N seasons
	 * (any season when N is 0). Leaves N %d placeholders for the season ids.
	 *
	 * @param int $count Number of season ids.
	 * @return string
	 */
	private function event_in_seasons_sql( int $count ): string {
		global $wpdb;
		$sql = "p.post_type = 'sp_event' AND p.post_status IN ( 'publish', 'future' )";
		if ( ! $count ) {
			return $sql;
		}
		$in = implode( ',', array_fill( 0, $count, '%d' ) );
		return $sql . " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} sr
				INNER JOIN {$wpdb->term_taxonomy} st ON st.term_taxonomy_id = sr.term_taxonomy_id
				WHERE sr.object_id = p.ID AND st.taxonomy = 'sp_season' AND st.term_id IN ( {$in} ) )";
	}

	/**
	 * One query: divisions attached to events in the given seasons.
	 *
	 * @param int[]|null $season_ids Season term ids; null for every season.
	 * @return array
	 */
	private function query_leagues( ?array $season_ids ): array {
		if ( array() === $season_ids ) {
			return array();
		}
		global $wpdb;
		$sql  = "SELECT DISTINCT t.slug, t.name
			FROM {$wpdb->terms} t
			INNER JOIN {$wpdb->term_taxonomy} lt ON lt.term_id = t.term_id AND lt.taxonomy = 'sp_league'
			INNER JOIN {$wpdb->term_relationships} lr ON lr.term_taxonomy_id = lt.term_taxonomy_id
			INNER JOIN {$wpdb->posts} p ON p.ID = lr.object_id
			WHERE " . $this->event_in_seasons_sql( count( (array) $season_ids ) ) . '
			ORDER BY t.name ASC';
		$rows = $this->get_rows( $sql, (array) $season_ids );
		return array_map(
			fn( $row ) => array(
				'slug' => (string) $row['slug'],
				'name' => (string) $row['name'],
			),
			$rows
		);
	}

	/**
	 * One query: published teams named in the sp_team meta of events in the
	 * given seasons (teams are post meta, not a taxonomy), optionally only
	 * events in one division.
	 *
	 * @param int[]|null $season_ids Season term ids; null for every season.
	 * @param int        $league_id  League term id, 0 for all.
	 * @return array
	 */
	private function query_teams( ?array $season_ids, int $league_id ): array {
		if ( array() === $season_ids ) {
			return array();
		}
		global $wpdb;
		$params = (array) $season_ids;
		$league = '';
		if ( $league_id ) {
			$league   = " AND EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} lr
				INNER JOIN {$wpdb->term_taxonomy} lt ON lt.term_taxonomy_id = lr.term_taxonomy_id
				WHERE lr.object_id = p.ID AND lt.taxonomy = 'sp_league' AND lt.term_id = %d )";
			$params[] = $league_id;
		}
		$sql  = "SELECT DISTINCT team.ID AS id, team.post_title AS name
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'sp_team'
			INNER JOIN {$wpdb->posts} team ON team.ID = CAST( pm.meta_value AS UNSIGNED ) AND team.post_type = 'sp_team' AND team.post_status = 'publish'
			WHERE " . $this->event_in_seasons_sql( count( (array) $season_ids ) ) . $league . '
			ORDER BY team.post_title ASC';
		$rows = $this->get_rows( $sql, $params );
		return array_map(
			fn( $row ) => array(
				'id'   => (int) $row['id'],
				'name' => (string) $row['name'],
			),
			$rows
		);
	}

	/**
	 * Run a read query, preparing it only when it has placeholders.
	 *
	 * @param string $sql    SQL with one %d per parameter.
	 * @param array  $params Parameters.
	 * @return array Rows as associative arrays.
	 */
	private function get_rows( string $sql, array $params ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- placeholders built by the callers from integer ids.
		$query = $params ? $wpdb->prepare( $sql, ...$params ) : $sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared above when it has values; cached by the callers.
		return (array) $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Keys that, when present in the arguments (even as '' meaning "All"),
	 * force the Calendar to be detached. See render_games().
	 */
	const DETACH_KEYS = array( 'league', 'season', 'team' );

	/**
	 * Build the [event_list] shortcode text for the given arguments.
	 *
	 * Only non-empty values are emitted. Values are made safe to sit inside
	 * double quotes: quotes, brackets and control characters are removed, so a
	 * value can never close the attribute or the shortcode.
	 *
	 * @param array $args Attribute map.
	 * @return string
	 */
	public static function build_event_list_shortcode( $args ) {
		$parts = array();
		foreach ( (array) $args as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key   = preg_replace( '/[^a-z_]/', '', strtolower( (string) $key ) );
			$value = preg_replace( '/[\x00-\x1F\x7F"\'\[\]<>]/', '', (string) $value );
			if ( '' === $key || '' === trim( $value ) ) {
				continue;
			}
			$parts[] = $key . '="' . trim( $value ) . '"';
		}
		return '[event_list' . ( $parts ? ' ' . implode( ' ', $parts ) : '' ) . ']';
	}

	/**
	 * Render the event list for a merged, validated argument map.
	 *
	 * Spike result (SportsPress templates/event-list.php and
	 * includes/class-sp-calendar.php::data()): the template only applies a
	 * league/season/team attribute when it is non-empty, and data() ADDS the
	 * Calendar's own league and season terms and ANDs its team meta whenever
	 * the Calendar has an id. SportsPress offers no filter on the query args
	 * or the calendar object, so with an id a Calendar-set league, season or
	 * team can be replaced (league/season) or narrowed (team) but never
	 * cleared, and a visitor's "All divisions" could not widen it.
	 *
	 * Chosen route: when the arguments mention league, season or team at all
	 * (a value or '' for "All"), or clear any other key with '' (the visitor
	 * dropping the Calendar's date window), render WITHOUT id and pass the Calendar's
	 * other settings explicitly (detach_calendar_settings()), skipping any
	 * field the arguments already decide. Otherwise keep the id so the
	 * Calendar is honoured exactly, including settings that cannot be passed
	 * as attributes (several leagues or teams, players, order by match day).
	 *
	 * Known limit of the detached route (SportsPress has no filter to clear
	 * Calendar values, and its attributes take a single id): a Calendar that
	 * fixes SEVERAL leagues, seasons or teams is widened to all of them, and
	 * the Calendar's players, order-by-match-day and title are dropped. The
	 * route is only taken when a visitor or the shortcode sets league, season
	 * or team, so the plain id route is unaffected.
	 *
	 * @param array $args Merged arguments; '' means the user chose "All".
	 * @return string HTML.
	 */
	private function render_games( array $args ): string {
		$calendar_id = isset( $args['id'] ) ? absint( $args['id'] ) : 0;
		unset( $args['id'] );

		if ( $calendar_id && ! $this->is_published_calendar( $calendar_id ) ) {
			return $this->notice( __( 'Calendar not found.', 'sportspress-events-manager' ) );
		}

		$args = $this->prepare_attributes( $calendar_id, $args );
		if ( null === $args ) {
			return $this->notice( __( 'No games match these filters.', 'sportspress-events-manager' ) );
		}

		$html = do_shortcode( self::build_event_list_shortcode( $args ) );

		// SportsPress prints an empty table body when nothing matches.
		return $this->has_rows( $html ) ? $html : $this->notice( __( 'No games match these filters.', 'sportspress-events-manager' ) );
	}

	/**
	 * Choose between the id route and the detached route.
	 *
	 * @param int   $calendar_id Calendar id, 0 for none.
	 * @param array $args        Arguments without id.
	 * @return array|null Attributes for [event_list], or null for no match.
	 */
	private function prepare_attributes( $calendar_id, array $args ) {
		if ( ! array_intersect( self::DETACH_KEYS, array_keys( $args ) ) && ! in_array( '', $args, true ) ) {
			return $calendar_id ? array( 'id' => $calendar_id ) + $args : $args;
		}
		$args = $this->resolve_term_values( $args );
		if ( null !== $args && $calendar_id ) {
			$args = $this->detach_calendar_settings( $calendar_id, $args );
		}
		return $args;
	}

	/**
	 * Whether the id is a published Calendar.
	 *
	 * @param int $calendar_id Post id.
	 * @return bool
	 */
	private function is_published_calendar( $calendar_id ) {
		return 'sp_calendar' === get_post_type( $calendar_id ) && 'publish' === get_post_status( $calendar_id );
	}

	/**
	 * Whether SportsPress output contains at least one event row.
	 *
	 * @param string $html Event list HTML.
	 * @return bool
	 */
	private function has_rows( $html ) {
		return false !== strpos( $html, '<tr class="sp-row' );
	}

	/**
	 * Wrap a message in the empty-state paragraph.
	 *
	 * @param string $message Already-translated text.
	 * @return string
	 */
	private function notice( $message ) {
		return '<p class="arl-schedule-empty">' . esc_html( $message ) . '</p>';
	}

	/**
	 * Turn season/league slugs into term ids and check team ids. Empty
	 * values pass through untouched.
	 *
	 * @param array $args Arguments.
	 * @return array|null Arguments, or null when a named value does not exist.
	 */
	private function resolve_term_values( array $args ) {
		foreach ( array(
			'season' => 'sp_season',
			'league' => 'sp_league',
		) as $key => $taxonomy ) {
			if ( empty( $args[ $key ] ) ) {
				continue;
			}
			$term = $this->find_term( $args[ $key ], $taxonomy );
			if ( ! $term ) {
				return null;
			}
			$args[ $key ] = (string) $term->term_id;
		}
		if ( ! empty( $args['team'] ) && 'sp_team' !== get_post_type( absint( $args['team'] ) ) ) {
			return null;
		}
		return $args;
	}

	/**
	 * Find a term by id or slug.
	 *
	 * @param string $value    Term id or slug.
	 * @param string $taxonomy Taxonomy.
	 * @return object|null
	 */
	private function find_term( $value, $taxonomy ) {
		$term = ctype_digit( (string) $value )
			? get_term( (int) $value, $taxonomy )
			: get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
		return ( $term && ! is_wp_error( $term ) ) ? $term : null;
	}

	/**
	 * Copy a Calendar's settings into explicit attributes, for the keys the
	 * arguments have not already set. A league, season or team the Calendar
	 * fixes is only carried over when it is a single value; SportsPress's
	 * attributes cannot express a list.
	 *
	 * @param int   $calendar_id Calendar post id.
	 * @param array $args        Arguments (without id).
	 * @return array
	 */
	private function detach_calendar_settings( $calendar_id, array $args ) {
		$date = get_post_meta( $calendar_id, 'sp_date', true );
		$cols = get_post_meta( $calendar_id, 'sp_columns', true );
		$own  = array(
			'status'  => get_post_meta( $calendar_id, 'sp_status', true ),
			'order'   => get_post_meta( $calendar_id, 'sp_order', true ),
			'number'  => get_post_meta( $calendar_id, 'sp_number', true ),
			'date'    => $date,
			'format'  => get_post_meta( $calendar_id, 'sp_event_format', true ),
			'day'     => get_post_meta( $calendar_id, 'sp_day', true ),
			'columns' => is_array( $cols ) ? implode( ',', $cols ) : '',
			'title'   => $this->calendar_title( $calendar_id ),
		) + $this->calendar_date_settings( $calendar_id, $date );

		foreach ( array(
			'league' => 'sp_league',
			'season' => 'sp_season',
			'venue'  => 'sp_venue',
		) as $key => $taxonomy ) {
			$ids = $this->single_term_ids( $calendar_id, $taxonomy );
			if ( ! array_key_exists( $key, $args ) && 1 === count( $ids ) ) {
				$own[ $key ] = (string) reset( $ids );
			}
		}
		$team = $this->calendar_single_team( $calendar_id );
		if ( ! array_key_exists( 'team', $args ) && '' !== $team ) {
			$own['team'] = $team;
		}

		// Arguments win; anything they leave unset falls back to the Calendar.
		return $args + array_map( 'strval', array_filter( $own, array( $this, 'is_set_value' ), ARRAY_FILTER_USE_BOTH ) );
	}

	/**
	 * The heading SportsPress would show for the Calendar: its caption, else
	 * its title; '' when SportsPress hides event list titles (an explicit
	 * title attribute is always shown, so it must not be passed then).
	 *
	 * @param int $calendar_id Calendar post id.
	 * @return string
	 */
	private function calendar_title( $calendar_id ): string {
		if ( 'yes' !== get_option( 'sportspress_event_list_show_title', 'yes' ) ) {
			return '';
		}
		$caption = (string) get_post_meta( $calendar_id, 'sp_caption', true );
		return '' !== $caption ? $caption : (string) get_the_title( $calendar_id );
	}

	/**
	 * Whether a Calendar value counts as set. '0' and '' are unset, except
	 * for the relative range's past/future days, where 0 is a real value.
	 *
	 * @param mixed  $value Meta value.
	 * @param string $key   Attribute name.
	 * @return bool
	 */
	private function is_set_value( $value, $key ) {
		if ( in_array( $key, array( 'date_past', 'date_future' ), true ) ) {
			return '' !== (string) $value;
		}
		return (bool) $value;
	}

	/**
	 * The Calendar's date-range attributes (only when its date mode is range).
	 *
	 * @param int    $calendar_id Calendar post id.
	 * @param string $date        The Calendar's date mode.
	 * @return array
	 */
	private function calendar_date_settings( $calendar_id, $date ) {
		if ( 'range' !== $date ) {
			return array();
		}
		if ( get_post_meta( $calendar_id, 'sp_date_relative', true ) ) {
			return array(
				'date_relative' => '1',
				'date_past'     => get_post_meta( $calendar_id, 'sp_date_past', true ),
				'date_future'   => get_post_meta( $calendar_id, 'sp_date_future', true ),
			);
		}
		return array(
			'date_from' => get_post_meta( $calendar_id, 'sp_date_from', true ),
			'date_to'   => get_post_meta( $calendar_id, 'sp_date_to', true ),
		);
	}

	/**
	 * The ids of a Calendar's terms in a taxonomy, including auto terms.
	 *
	 * @param int    $calendar_id Calendar post id.
	 * @param string $taxonomy    Taxonomy.
	 * @return array
	 */
	private function single_term_ids( $calendar_id, $taxonomy ) {
		$ids = array();
		foreach ( (array) get_the_terms( $calendar_id, $taxonomy ) as $term ) {
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$ids[] = $term->term_id;
			}
		}
		return function_exists( 'sp_add_auto_term' ) ? sp_add_auto_term( $ids, $calendar_id, $taxonomy ) : $ids;
	}

	/**
	 * The Calendar's team id when it fixes exactly one, else ''.
	 *
	 * @param int $calendar_id Calendar post id.
	 * @return string
	 */
	private function calendar_single_team( $calendar_id ) {
		$teams = array_filter( (array) get_post_meta( $calendar_id, 'sp_team', false ) );
		return 1 === count( $teams ) ? (string) reset( $teams ) : '';
	}
}
