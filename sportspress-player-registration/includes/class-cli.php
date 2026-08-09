<?php
/**
 * WP-CLI Commands
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// The whole file is inert outside WP-CLI: nothing here should load, and nothing
// should be declared, on a normal web request.
if ( ! class_exists( 'WP_CLI' ) ) {
	return;
}

class SPPR_CLI {

	/**
	 * Backfill sp_player post_author from the sp_user meta link.
	 *
	 * Registration now sets both when it links a user to a player, but ~233
	 * players were linked by the older code that only wrote sp_user meta, so their
	 * records are still authored by whichever admin happened to create them.
	 *
	 * SAFE BY DEFAULT: this reports and changes nothing unless --write is passed.
	 * Ownership does NOT grant edit rights on its own — see the
	 * 'spr_owner_can_edit' setting — but it is still a write to every affected
	 * post, so it stays opt-in.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report what would change without writing. This is the default; the flag is
	 * accepted so a cautious invocation reads the way an operator expects.
	 *
	 * [--write]
	 * : Actually update post_author. Required for this command to change anything.
	 *
	 * ## EXAMPLES
	 *
	 *     wp spr backfill-owners
	 *     wp spr backfill-owners --write
	 *
	 * @param array $args       Positional args (unused).
	 * @param array $assoc_args Flags.
	 * @return void
	 */
	public function backfill_owners( $args, $assoc_args ) {
		global $wpdb;

		$write = ! empty( $assoc_args['write'] ) && empty( $assoc_args['dry-run'] );

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_author, p.post_title, m.meta_value AS sp_user
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
			WHERE p.post_type = 'sp_player' AND m.meta_key = 'sp_user'"
		);

		$changed = 0;
		$skipped_invalid = 0;
		$already = 0;
		$failed = 0;

		foreach ( (array) $rows as $row ) {
			$user_id   = (int) $row->sp_user;
			$player_id = (int) $row->ID;

			// A link to a deleted or bogus user would orphan authorship, which is
			// worse than the mismatch we are fixing. Leave those for a human.
			if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
				$skipped_invalid++;
				WP_CLI::warning(
					sprintf( 'Player %d ("%s") links to missing user %d — skipped.', $player_id, $row->post_title, $user_id )
				);
				continue;
			}

			if ( (int) $row->post_author === $user_id ) {
				$already++;
				continue;
			}

			WP_CLI::log(
				sprintf(
					'%s player %d ("%s"): post_author %d -> %d',
					$write ? 'Updating' : 'Would update',
					$player_id,
					$row->post_title,
					(int) $row->post_author,
					$user_id
				)
			);

			if ( ! $write ) {
				$changed++;
				continue;
			}

			$result = wp_update_post(
				array(
					'ID' => $player_id,
					'post_author' => $user_id,
				),
				true
			);

			if ( is_wp_error( $result ) || ! $result ) {
				$failed++;
				WP_CLI::warning(
					sprintf(
						'Player %d failed: %s',
						$player_id,
						is_wp_error( $result ) ? $result->get_error_message() : 'wp_update_post returned 0'
					)
				);
				continue;
			}

			$changed++;
		}

		WP_CLI::log(
			sprintf(
				'Linked players: %d | already correct: %d | unresolvable user link: %d | failed: %d',
				count( (array) $rows ),
				$already,
				$skipped_invalid,
				$failed
			)
		);

		if ( $write ) {
			WP_CLI::success( sprintf( '%d player record(s) re-authored.', $changed ) );
			return;
		}

		WP_CLI::success(
			sprintf( 'Dry run: %d player record(s) would be re-authored. Re-run with --write to apply.', $changed )
		);
	}
}

WP_CLI::add_command( 'spr backfill-owners', array( new SPPR_CLI(), 'backfill_owners' ) );
