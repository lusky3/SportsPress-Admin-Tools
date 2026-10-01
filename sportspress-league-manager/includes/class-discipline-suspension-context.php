<?php
/**
 * Builds the allow-listed context for manual suspension emails.
 *
 * Maps a notice row onto exactly the keys SPLM_Discipline_Suspension_Body
 * reads. The convener's incident note is deliberately never copied: this
 * builds the context from named fields rather than from the row, so a new
 * column cannot leak into an email by accident.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * TooManyMethods: small pure helpers, one concern each; splitting would
 * scatter one rule.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */
class SPLM_Discipline_Suspension_Context {

	const DEFAULT_RULEBOOK_URL = 'https://www.rookiehockey.ca/wp-content/uploads/2024/10/RuleBook-Rev20241009.pdf';
	const DEFAULT_RULEBOOK_REV = 'Rev 20241009';

	/**
	 * Notice kind for a stored row scope.
	 *
	 * @param string $scope Row scope.
	 * @return string issued|decided|amended|revoked.
	 */
	public static function kind_for_scope( string $scope ): string {
		$map = array(
			'manual-decided' => 'decided',
			'manual-amended' => 'amended',
			'manual-revoked' => 'revoked',
		);

		return $map[ $scope ] ?? 'issued';
	}

	/**
	 * "<title> — <when>", dropping whichever half is empty.
	 *
	 * @param string $title      Event title.
	 * @param string $when_local Already-formatted local date.
	 * @return string
	 */
	public static function format_label( string $title, string $when_local ): string {
		if ( '' === $title || '' === $when_local ) {
			return $title . $when_local;
		}

		return $title . ' — ' . $when_local;
	}

	/**
	 * Eligibility date label. Empty when there is no date or the outcome is
	 * indefinite (no date is ever promised for an indefinite suspension).
	 *
	 * @param string|null $eligible_on Stored 'Y-m-d' or null.
	 * @param string      $outcome     games|indefinite.
	 * @return string
	 */
	public static function eligible_label( ?string $eligible_on, string $outcome ): string {
		if ( empty( $eligible_on ) || 'indefinite' === $outcome ) {
			return '';
		}

		return self::date_label( $eligible_on );
	}

	/**
	 * Localised date for a stored 'Y-m-d'.
	 *
	 * @param string $ymd Date.
	 * @return string
	 */
	private static function date_label( string $ymd ): string {
		// Noon keeps the calendar day stable across any timezone offset.
		return (string) wp_date( get_option( 'date_format' ), strtotime( $ymd . ' 12:00:00' ) );
	}

	/**
	 * Rulebook URL.
	 *
	 * @return string
	 */
	public static function rulebook_url(): string {
		$saved = (string) get_option( 'splm_discipline_rulebook_url', '' );

		return '' === $saved ? self::DEFAULT_RULEBOOK_URL : $saved;
	}

	/**
	 * Rulebook revision label.
	 *
	 * @return string
	 */
	public static function rulebook_rev(): string {
		$saved = (string) get_option( 'splm_discipline_rulebook_rev', '' );

		return '' === $saved ? self::DEFAULT_RULEBOOK_REV : $saved;
	}

	/**
	 * Sanitise the rulebook URL setting. Untyped: options.php hands a callback
	 * null when the field is absent from the POST.
	 *
	 * @param mixed $raw Raw value.
	 * @return string http(s) URL, or '' (meaning "use the default").
	 */
	public static function sanitize_rulebook_url( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		$url = trim( (string) $raw );

		return preg_match( '#^https?://#i', $url ) ? esc_url_raw( $url, array( 'http', 'https' ) ) : '';
	}

	/**
	 * Sanitise the rulebook revision label setting. Untyped, as above.
	 *
	 * @param mixed $raw Raw value.
	 * @return string At most 40 characters, or '' (meaning "use the default").
	 */
	public static function sanitize_rulebook_rev( $raw ): string {
		if ( ! is_scalar( $raw ) ) {
			return '';
		}

		return substr( sanitize_text_field( (string) $raw ), 0, 40 );
	}

	/**
	 * Convenor contact: first valid copy address, else the site admin.
	 *
	 * @return string
	 */
	public static function contact(): string {
		$raw   = (string) get_option( 'splm_discipline_notice_cc', '' );
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );

		foreach ( $parts as $part ) {
			if ( is_email( $part ) ) {
				return $part;
			}
		}

		return (string) get_option( 'admin_email', '' );
	}

	/**
	 * Context for a notice row. Never includes incident_note or note.
	 *
	 * @param object $row   Notice row.
	 * @param array  $extra Caller-supplied keys (prior_games, team_names, remaining), merged last.
	 * @return array
	 */
	public static function for_row( object $row, array $extra = array() ): array {
		$outcome     = (string) $row->outcome;
		$eligible_on = empty( $row->eligible_on ) ? null : (string) $row->eligible_on;

		$ctx = array(
			'kind'             => self::kind_for_scope( (string) $row->scope ),
			'player_name'      => self::decode( (string) get_the_title( (int) $row->player_id ) ),
			'season_name'      => self::season_name( (int) $row->season_id ),
			'rule_ref'         => (string) $row->rule_ref,
			'infraction_title' => (string) $row->infraction_title,
			'rule_text'        => (string) $row->rule_text,
			'outcome'          => $outcome,
			'games'            => (int) $row->games,
			'incident_label'   => self::incident_label( (int) ( $row->incident_event_id ?? 0 ) ),
			'eligible_label'   => self::eligible_label( $eligible_on, $outcome ),
			'projected'        => null !== $eligible_on && 'games' === $outcome,
			'remaining'        => 0,
			'rulebook_url'     => self::rulebook_url(),
			'rulebook_rev'     => self::rulebook_rev(),
			'contact'          => self::contact(),
		);

		// The private note must never ride in through the caller's extras.
		unset( $extra['incident_note'], $extra['note'] );

		return array_merge( $ctx, $extra );
	}

	/**
	 * Plain-text safe string: the mail is text/plain, so entities must go.
	 *
	 * @param string $value Possibly entity-encoded string.
	 * @return string
	 */
	public static function decode( string $value ): string {
		return wp_specialchars_decode( $value, ENT_QUOTES );
	}

	/**
	 * Season term name.
	 *
	 * @param int $season_id Term id.
	 * @return string
	 */
	private static function season_name( int $season_id ): string {
		$term = get_term( $season_id, 'sp_season' );

		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}

		return self::decode( (string) $term->name );
	}

	/**
	 * "Event title — local date" for the incident event, or ''.
	 *
	 * @param int $event_id Event post id (0 for none).
	 * @return string
	 */
	private static function incident_label( int $event_id ): string {
		$event = ( $event_id > 0 && 'sp_event' === get_post_type( $event_id ) ) ? get_post( $event_id ) : null;

		if ( ! $event ) {
			return '';
		}

		$format = trim( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
		$when   = (string) mysql2date( $format, (string) $event->post_date );

		return self::format_label( self::decode( (string) $event->post_title ), $when );
	}
}
