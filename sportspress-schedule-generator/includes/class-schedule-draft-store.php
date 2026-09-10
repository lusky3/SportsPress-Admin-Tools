<?php
/**
 * Schedule Draft Store
 *
 * Persists a generated-but-not-yet-imported schedule per configuration, so
 * it survives across page loads -- and days -- until explicitly imported or
 * discarded. Previously the generated schedule and its stats lived only in
 * a one-hour, per-user transient (`spsg_last_schedule_id_{user_id}` pointing
 * at `spsg_schedule_{id}` / `spsg_schedule_stats_{id}`), which vanished long
 * before an operator finished reviewing a real schedule and, because it was
 * keyed by user rather than by configuration, showed the wrong schedule
 * after switching to a different saved configuration.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores one current draft schedule per configuration in wp_options
 * (autoload disabled -- a schedule can be hundreds of games).
 */
class SPSG_Schedule_Draft_Store {

	/**
	 * Persist a newly generated schedule as the current draft for
	 * $config_id, replacing any existing draft for that configuration.
	 *
	 * @param string $config_id Configuration id.
	 * @param array  $schedule  Array of game objects.
	 * @param array  $stats     Statistics array.
	 * @return string The new draft's schedule id.
	 */
	public static function save( $config_id, $schedule, $stats ) {
		self::delete( $config_id );

		$schedule_id = 'draft_' . bin2hex( random_bytes( 8 ) );
		update_option( 'spsg_schedule_' . $schedule_id, $schedule, false );
		update_option( 'spsg_schedule_stats_' . $schedule_id, $stats, false );
		update_option(
			'spsg_draft_pointer_' . $config_id,
			array(
				'schedule_id' => $schedule_id,
				// Matches the plain "Y-m-d H:i:s" string already used for a
				// configuration's own 'modified' timestamp elsewhere in this
				// plugin (see SPSG_Configuration_Manager), so it can be
				// displayed as-is with no extra formatting.
				'generated_at' => current_time( 'mysql' ),
			),
			false
		);

		return $schedule_id;
	}

	/**
	 * Fetch the current draft for $config_id, if any.
	 *
	 * @param string $config_id Configuration id.
	 * @return array{schedule_id:string,schedule:array,stats:array,generated_at:string}|null
	 */
	public static function get( $config_id ) {
		$pointer = get_option( 'spsg_draft_pointer_' . $config_id );
		if ( empty( $pointer['schedule_id'] ) ) {
			return null;
		}

		$schedule = get_option( 'spsg_schedule_' . $pointer['schedule_id'] );
		if ( ! is_array( $schedule ) ) {
			return null;
		}

		return array(
			'schedule_id' => $pointer['schedule_id'],
			'schedule' => $schedule,
			'stats' => get_option( 'spsg_schedule_stats_' . $pointer['schedule_id'] ) ?: array(),
			'generated_at' => (string) ( $pointer['generated_at'] ?? '' ),
		);
	}

	/**
	 * Discard the current draft for $config_id, if any.
	 *
	 * @param string $config_id Configuration id.
	 */
	public static function delete( $config_id ) {
		$pointer = get_option( 'spsg_draft_pointer_' . $config_id );
		if ( ! empty( $pointer['schedule_id'] ) ) {
			delete_option( 'spsg_schedule_' . $pointer['schedule_id'] );
			delete_option( 'spsg_schedule_stats_' . $pointer['schedule_id'] );
		}
		delete_option( 'spsg_draft_pointer_' . $config_id );
	}

	/**
	 * Resolve a schedule id (as held by the page's hidden input and sent
	 * back on export/import requests) to its stored schedule, regardless of
	 * which configuration's draft it belongs to.
	 *
	 * @param string $schedule_id Schedule id.
	 * @return array|null
	 */
	public static function get_schedule_by_id( $schedule_id ) {
		$schedule = get_option( 'spsg_schedule_' . $schedule_id );
		return is_array( $schedule ) ? $schedule : null;
	}
}
