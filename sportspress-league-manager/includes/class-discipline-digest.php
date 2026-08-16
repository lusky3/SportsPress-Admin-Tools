<?php
/**
 * Weekly penalty digest.
 *
 * Wrapped in SPAT_Lock because WP-Cron can fire the same event twice when two
 * requests race the scheduler, and a duplicated disciplinary email to the whole
 * board is not a harmless duplicate.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Digest {

	const HOOK = 'splm_discipline_digest';
	const LOCK = 'splm_discipline_digest';

	public function __construct() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_filter( 'cron_schedules', array( __CLASS__, 'add_weekly_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval
	}

	/**
	 * Schedule the weekly event if it is not already scheduled.
	 */
	public static function schedule(): void {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$day  = (string) get_option( 'splm_discipline_digest_day', 'monday' );
		$next = strtotime( 'next ' . $day . ' 08:00' );
		if ( ! $next ) {
			$next = time() + DAY_IN_SECONDS;
		}

		wp_schedule_event( $next, 'weekly', self::HOOK );
	}

	/**
	 * Clear the scheduled event.
	 */
	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
		}
	}

	/**
	 * Build and send the digest.
	 *
	 * @return bool Whether mail was sent.
	 */
	public static function run(): bool {
		if ( ! get_option( 'splm_discipline_digest_enabled' ) ) {
			return false;
		}

		return (bool) SPAT_Lock::with(
			self::LOCK,
			120,
			function () {
				$season_id = (int) get_option( 'splm_default_season', 0 );
				if ( ! $season_id ) {
					return false;
				}

				$rows = SPLM_Leaders_REST::build_watch( $season_id );
				// A quiet week sends nothing. A digest that arrives every week
				// saying "nothing to report" trains people to filter it.
				if ( ! $rows ) {
					return false;
				}

				$season = get_term( $season_id, 'sp_season' );
				$name   = ( $season && ! is_wp_error( $season ) ) ? $season->name : '';

				return wp_mail(
					self::recipients(),
					sprintf(
						/* translators: %s: season name. */
						__( 'Penalty watch — %s', 'sportspress-league-manager' ),
						$name
					),
					self::build_body( $rows, $name ),
					array( 'Content-Type: text/html; charset=UTF-8' )
				);
			}
		);
	}

	/**
	 * Digest recipients, falling back to the site admin.
	 *
	 * @return array
	 */
	public static function recipients(): array {
		$raw   = (string) get_option( 'splm_discipline_digest_recipients', '' );
		$parts = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		$valid = array_values( array_filter( $parts, 'is_email' ) );

		return $valid ? $valid : array( get_option( 'admin_email' ) );
	}

	/**
	 * Render the digest body.
	 *
	 * @param array  $rows        Watch rows.
	 * @param string $season_name Season name.
	 * @return string
	 */
	public static function build_body( array $rows, string $season_name ): string {
		$out  = '<p>' . sprintf(
			/* translators: 1: number of players, 2: season name. */
			esc_html__( '%1$d player(s) are over a penalty threshold in %2$s.', 'sportspress-league-manager' ),
			count( $rows ),
			esc_html( $season_name )
		) . '</p>';
		$out .= '<table cellpadding="6" border="1" style="border-collapse:collapse">';
		$out .= '<tr><th>' . esc_html__( 'Player', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Team', 'sportspress-league-manager' ) . '</th><th>'
			. esc_html__( 'Division', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Season PIM', 'sportspress-league-manager' ) . '</th><th>'
			. esc_html__( 'Recent PIM', 'sportspress-league-manager' ) . '</th><th>' . esc_html__( 'Flag', 'sportspress-league-manager' ) . '</th></tr>';

		foreach ( $rows as $row ) {
			$out .= sprintf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
				esc_html( $row['player'] ),
				esc_html( $row['team'] ),
				esc_html( $row['division'] ),
				(int) $row['season_pim'],
				(int) $row['window_pim'],
				esc_html( implode( ', ', array_column( $row['flags'], 'tier_key' ) ) )
			);
		}

		$out .= '</table>';
		$out .= '<p>' . esc_html__( 'Acknowledge these in the League Manager dashboard under Leaders.', 'sportspress-league-manager' ) . '</p>';

		return $out;
	}

	/**
	 * Register a weekly interval; core only ships hourly/twicedaily/daily.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public static function add_weekly_schedule( $schedules ): array {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => __( 'Once Weekly', 'sportspress-league-manager' ),
			);
		}

		return $schedules;
	}
}
