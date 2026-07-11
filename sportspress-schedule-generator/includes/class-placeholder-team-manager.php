<?php
/**
 * Placeholder Team Manager
 *
 * Handles creation, tracking, and replacement of placeholder teams
 * in generated schedules and SportsPress events.
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Placeholder Team Manager class
 */
class SPSG_Placeholder_Team_Manager {


	/**
	 * Meta key used to mark placeholder teams
	 */
	const PLACEHOLDER_META_KEY = '_spsg_placeholder_team';

	/**
	 * Meta key for the schedule config ID that created the placeholder
	 */
	const CONFIG_META_KEY = '_spsg_placeholder_config_id';

	/**
	 * Meta key for the division the placeholder belongs to
	 */
	const DIVISION_META_KEY = '_spsg_placeholder_division';

	/**
	 * Generate placeholder team names for a division
	 *
	 * @param array  $existing_teams  Current teams in the division
	 * @param int    $target_count    Target number of teams
	 * @param string $prefix          Naming prefix (e.g., "Team")
	 * @param string $division_name   Division name for context
	 * @return array Array of placeholder team names
	 */
	public static function generate_placeholder_names( $existing_teams, $target_count, $prefix = 'Team', $division_name = '' ) {
		$placeholders = array();
		$needed = $target_count - count( $existing_teams );

		if ( $needed <= 0 ) {
			return $placeholders;
		}

		$counter = 1;
		for ( $i = 0; $i < $needed; $i++ ) {
			$name = $division_name
				? sprintf( '%s %s %d', $prefix, $division_name, $counter )
				: sprintf( '%s %d', $prefix, $counter );

			// Avoid name collisions with existing teams
			while ( in_array( $name, $existing_teams ) || in_array( $name, $placeholders ) ) {
				$counter++;
				$name = $division_name
					? sprintf( '%s %s %d', $prefix, $division_name, $counter )
					: sprintf( '%s %d', $prefix, $counter );
			}

			$placeholders[] = $name;
			$counter++;
		}

		return $placeholders;
	}

	/**
	 * Inject placeholder teams into configuration divisions
	 *
	 * Modifies the config's divisions array in-place, adding placeholder
	 * team names to reach the target count per division.
	 *
	 * @param SPSG_Schedule_Configuration $config Configuration object
	 * @return array Info about injected placeholders keyed by division index
	 */
	public static function inject_into_config( $config ) {
		$generic = $config->generic_teams;
		$injection_info = array();

		if ( empty( $generic['enabled'] ) ) {
			return $injection_info;
		}

		$target = intval( $generic['per_division'] ?? 8 );
		$prefix = sanitize_text_field( $generic['prefix'] ?? 'Team' );

		foreach ( $config->divisions as $index => &$division ) {
			$teams = $division['teams'] ?? array();
			$division_name = $division['name'] ?? '';

			$placeholders = self::generate_placeholder_names(
				$teams,
				$target,
				$prefix,
				$division_name
			);

			if ( ! empty( $placeholders ) ) {
				$division['teams'] = array_merge( $teams, $placeholders );
				$injection_info[ $index ] = array(
					'division_name' => $division_name,
					'placeholders' => $placeholders,
					'original_count' => count( $teams ),
					'new_count' => count( $division['teams'] ),
				);
			}
		}
		unset( $division );

		return $injection_info;
	}

	/**
	 * Create placeholder team posts in SportsPress
	 *
	 * Called during import when a team name is not found and matches
	 * a known placeholder pattern.
	 *
	 * @param string $team_name    The placeholder team name
	 * @param string $config_id    The schedule configuration ID
	 * @param string $division     The division name
	 * @return int|WP_Error The created team post ID or error
	 */
	public static function create_placeholder_team( $team_name, $config_id = '', $division = '' ) {
		$team_id = wp_insert_post(
			array(
				'post_type'   => 'sp_team',
				'post_title'  => $team_name,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $team_id ) ) {
			return $team_id;
		}

		// Mark as placeholder
		update_post_meta( $team_id, self::PLACEHOLDER_META_KEY, '1' );

		if ( $config_id ) {
			update_post_meta( $team_id, self::CONFIG_META_KEY, $config_id );
		}

		if ( $division ) {
			update_post_meta( $team_id, self::DIVISION_META_KEY, $division );
		}

		return $team_id;
	}

	/**
	 * Check if a team is a placeholder
	 *
	 * @param int $team_id Team post ID
	 * @return bool
	 */
	public static function is_placeholder( $team_id ) {
		return get_post_meta( $team_id, self::PLACEHOLDER_META_KEY, true ) === '1';
	}

	/**
	 * Get all placeholder teams, optionally filtered by config ID
	 *
	 * @param string $config_id Optional config ID filter
	 * @return array Array of team objects with id, name, division, config_id
	 */
	public static function get_placeholder_teams( $config_id = '' ) {
		$meta_query = array(
			array(
				'key'   => self::PLACEHOLDER_META_KEY,
				'value' => '1',
			),
		);

		if ( $config_id ) {
			$meta_query[] = array(
				'key'   => self::CONFIG_META_KEY,
				'value' => $config_id,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'meta_query'     => $meta_query,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$teams = array();
		foreach ( $query->posts as $post ) {
			$teams[] = array(
				'id'        => $post->ID,
				'name'      => $post->post_title,
				'division'  => get_post_meta( $post->ID, self::DIVISION_META_KEY, true ),
				'config_id' => get_post_meta( $post->ID, self::CONFIG_META_KEY, true ),
			);
		}

		return $teams;
	}

	/**
	 * Replace a placeholder team with a real team across all SportsPress events
	 *
	 * Updates every sp_event where the placeholder team appears as home or away,
	 * swapping in the replacement team ID. Optionally deletes the placeholder post.
	 *
	 * @param int  $placeholder_id   The placeholder team post ID
	 * @param int  $replacement_id   The real team post ID
	 * @param bool $delete_placeholder Whether to delete the placeholder post after replacement
	 * @return array Results with counts of updated events
	 */
	public static function replace_team( $placeholder_id, $replacement_id, $delete_placeholder = true ) {
		global $wpdb;

		$placeholder_id = absint( $placeholder_id );
		$replacement_id = absint( $replacement_id );

		$results = array(
			'events_updated' => 0,
			'errors'         => array(),
		);

		// Validate both teams exist
		if ( ! get_post( $placeholder_id ) || get_post_type( $placeholder_id ) !== 'sp_team' ) {
			$results['errors'][] = sprintf(
				__( 'Placeholder team ID %d not found.', 'sportspress-schedule-generator' ),
				$placeholder_id
			);
			return $results;
		}

		if ( ! get_post( $replacement_id ) || get_post_type( $replacement_id ) !== 'sp_team' ) {
			$results['errors'][] = sprintf(
				__( 'Replacement team ID %d not found.', 'sportspress-schedule-generator' ),
				$replacement_id
			);
			return $results;
		}

		// Find all events that reference the placeholder team
		// SportsPress stores teams in sp_team taxonomy and in post meta 'sp_team'
		$events = self::find_events_with_team( $placeholder_id );

		foreach ( $events as $event_id ) {
			$update_result = self::update_event_team( $event_id, $placeholder_id, $replacement_id );

			if ( is_wp_error( $update_result ) ) {
				$results['errors'][] = sprintf(
					__( 'Failed to update event %1$d: %2$s', 'sportspress-schedule-generator' ),
					$event_id,
					$update_result->get_error_message()
				);
			} else {
				$results['events_updated']++;
			}
		}

		// Transfer league/season taxonomy terms from placeholder to replacement
		self::transfer_taxonomy_terms( $placeholder_id, $replacement_id );

		// Delete placeholder team if requested and all events updated successfully
		if ( $delete_placeholder && empty( $results['errors'] ) ) {
			wp_delete_post( $placeholder_id, true );
		}

		return $results;
	}

	/**
	 * Find all sp_event posts that reference a given team
	 *
	 * @param int $team_id Team post ID
	 * @return array Array of event post IDs
	 */
	private static function find_events_with_team( $team_id ) {
		global $wpdb;

		// SportsPress stores team associations in the sp_team post meta on events
		// The meta value is a serialized array of team IDs
		// We also need to check the wp_sp_event_team relationship table if it exists

		$event_ids = array();

		// Method 1: Check post meta 'sp_team' on sp_event posts
		// SportsPress stores teams as individual meta rows with key 'sp_team'
		$meta_results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = 'sp_team' AND meta_value = %d
             AND post_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'sp_event')",
				$team_id
			)
		);

		if ( $meta_results ) {
			$event_ids = array_merge( $event_ids, $meta_results );
		}

		// Method 2: Check taxonomy relationship (sp_event posts tagged with team)
		$tax_results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
             INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
             INNER JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
             WHERE p.post_type = 'sp_event'
             AND tt.taxonomy = 'sp_team'
             AND t.term_id = %d",
				$team_id
			)
		);

		// Note: SportsPress doesn't use sp_team as a taxonomy on events typically,
		// but check anyway for compatibility

		if ( $tax_results ) {
			$event_ids = array_merge( $event_ids, $tax_results );
		}

		return array_unique( array_map( 'absint', $event_ids ) );
	}

	/**
	 * Update a single event, replacing placeholder team with replacement
	 *
	 * @param int $event_id       Event post ID
	 * @param int $placeholder_id Placeholder team ID
	 * @param int $replacement_id Replacement team ID
	 * @return true|WP_Error
	 */
	private static function update_event_team( $event_id, $placeholder_id, $replacement_id ) {
		// Get current team meta values
		$teams = get_post_meta( $event_id, 'sp_team', false );

		if ( empty( $teams ) ) {
			return new WP_Error( 'no_teams', __( 'Event has no team assignments.', 'sportspress-schedule-generator' ) );
		}

		// Remove old placeholder team meta
		delete_post_meta( $event_id, 'sp_team', $placeholder_id );

		// Add replacement team meta
		add_post_meta( $event_id, 'sp_team', $replacement_id );

		// Update the team results/performance meta if it exists
		// SportsPress stores results keyed by team ID
		$results_meta = get_post_meta( $event_id, 'sp_results', true );
		if ( is_array( $results_meta ) && isset( $results_meta[ $placeholder_id ] ) ) {
			$results_meta[ $replacement_id ] = $results_meta[ $placeholder_id ];
			unset( $results_meta[ $placeholder_id ] );
			update_post_meta( $event_id, 'sp_results', $results_meta );
		}

		// Update player performance meta if it exists
		$players_meta = get_post_meta( $event_id, 'sp_players', true );
		if ( is_array( $players_meta ) && isset( $players_meta[ $placeholder_id ] ) ) {
			$players_meta[ $replacement_id ] = $players_meta[ $placeholder_id ];
			unset( $players_meta[ $placeholder_id ] );
			update_post_meta( $event_id, 'sp_players', $players_meta );
		}

		// Update event title if it contains the placeholder team name
		$placeholder_name = get_the_title( $placeholder_id );
		$replacement_name = get_the_title( $replacement_id );
		$event = get_post( $event_id );

		if ( $event && strpos( $event->post_title, $placeholder_name ) !== false ) {
			$new_title = str_replace( $placeholder_name, $replacement_name, $event->post_title );
			wp_update_post(
				array(
					'ID'         => $event_id,
					'post_title' => $new_title,
				)
			);
		}

		return true;
	}

	/**
	 * Transfer taxonomy terms (leagues, seasons) from placeholder to replacement team
	 *
	 * @param int $placeholder_id Placeholder team ID
	 * @param int $replacement_id Replacement team ID
	 */
	private static function transfer_taxonomy_terms( $placeholder_id, $replacement_id ) {
		$taxonomies = array( 'sp_league', 'sp_season' );

		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $placeholder_id, $taxonomy, array( 'fields' => 'ids' ) );

			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				// Get existing terms on replacement to avoid duplicates
				$existing = wp_get_object_terms( $replacement_id, $taxonomy, array( 'fields' => 'ids' ) );
				$new_terms = array_diff( $terms, is_wp_error( $existing ) ? array() : $existing );

				if ( ! empty( $new_terms ) ) {
					wp_set_object_terms(
						$replacement_id,
						array_merge(
							is_wp_error( $existing ) ? array() : $existing,
							$new_terms
						),
						$taxonomy
					);
				}
			}
		}
	}

	/**
	 * Maximum placeholder teams to delete per request before deferring the
	 * remainder to a scheduled WP-Cron event. Keeps user-facing actions
	 * (config delete) responsive when a config has hundreds of placeholders.
	 */
	const CLEANUP_BATCH_SIZE = 25;

	/**
	 * Delete all placeholder teams associated with a config ID.
	 *
	 * Processes up to {@see self::CLEANUP_BATCH_SIZE} teams synchronously and,
	 * if more remain, schedules a single follow-up event to continue. Inside
	 * the loop cache invalidation is suspended to avoid thrashing object
	 * caches when bulk-deleting many posts.
	 *
	 * @param string $config_id Configuration ID
	 * @return int Number of teams deleted in this invocation.
	 */
	public static function cleanup_for_config( $config_id ) {
		$teams = self::get_placeholder_teams( $config_id );
		if ( empty( $teams ) ) {
			return 0;
		}

		$batch     = array_slice( $teams, 0, self::CLEANUP_BATCH_SIZE );
		$remaining = count( $teams ) - count( $batch );
		$deleted   = 0;
		$failures  = 0;

		// Hard ceiling on per-config delete attempts to prevent unbounded
		// cron recursion when wp_delete_post consistently fails (e.g. a
		// custom post-type filter is vetoing deletion).
		$iteration_key = 'spsg_placeholder_cleanup_iter_' . $config_id;
		$iteration     = (int) get_transient( $iteration_key );
		if ( $iteration >= 200 ) {
			// Give up — leave orphaned placeholders for a human to inspect.
			delete_transient( $iteration_key );
			error_log(
				sprintf(
					'[SPSG] Placeholder cleanup for config %s aborted after %d iterations.',
					$config_id,
					$iteration
				)
			);
			return 0;
		}

		// Suspend cache invalidation during the bulk delete to avoid repeated
		// invalidation cycles. Restored regardless of how the loop exits.
		$prev = function_exists( 'wp_suspend_cache_invalidation' )
			? wp_suspend_cache_invalidation( true )
			: false;

		try {
			foreach ( $batch as $team ) {
				$result = wp_delete_post( $team['id'], true );
				if ( $result === false || $result === null ) {
					$failures++;
					continue;
				}
				$deleted++;
			}
		} finally {
			if ( function_exists( 'wp_suspend_cache_invalidation' ) ) {
				wp_suspend_cache_invalidation( $prev );
			}
		}

		// Bail out of the recursion entirely if the whole batch failed to
		// delete — rescheduling would re-fetch the same posts forever.
		if ( $deleted === 0 && $failures > 0 ) {
			delete_transient( $iteration_key );
			error_log(
				sprintf(
					'[SPSG] Placeholder cleanup for config %s: batch of %d failed entirely; aborting.',
					$config_id,
					$failures
				)
			);
			return 0;
		}

		// Defer the rest to a single-shot scheduled event.
		if ( $remaining > 0 && function_exists( 'wp_schedule_single_event' ) ) {
			set_transient( $iteration_key, $iteration + 1, HOUR_IN_SECONDS );
			wp_schedule_single_event(
				time() + 60,
				'spsg_cleanup_placeholders_continue',
				array( $config_id )
			);
		} else {
			delete_transient( $iteration_key );
		}

		return $deleted;
	}

	/**
	 * Get non-placeholder teams for replacement dropdown
	 *
	 * @return array Array of team objects with id and name
	 */
	public static function get_real_teams() {
		$query = new WP_Query(
			array(
				'post_type'      => 'sp_team',
				'posts_per_page' => -1,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => self::PLACEHOLDER_META_KEY,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'   => self::PLACEHOLDER_META_KEY,
						'value' => '1',
						'compare' => '!=',
					),
				),
				'orderby' => 'title',
				'order'   => 'ASC',
			)
		);

		$teams = array();
		foreach ( $query->posts as $post ) {
			$teams[] = array(
				'id'   => $post->ID,
				'name' => $post->post_title,
			);
		}

		return $teams;
	}
}
