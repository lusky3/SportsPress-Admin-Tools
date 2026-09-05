<?php
/**
 * Email Sync — bulk-populate spt_email for players missing it.
 *
 * Matching priority:
 * 1. SPR registration log → order billing email (highest confidence)
 * 2. post_author → WP user email + billing_email user meta, but ONLY when the
 *    player's own sp_user meta confirms that author really is this player's
 *    account, and never for accounts that authored players in bulk
 * 3. Unmatched → CSV export for manual entry
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Email_Sync {

	const TAB = 'player-tools';

	/**
	 * Above this many authored sp_player posts, a post_author is treated as a
	 * bulk importer (staff doing data entry) rather than as a player.
	 *
	 * Rationale: post_author records who CREATED the record, not who the record
	 * is about. On rookiehockey.ca five staff accounts author ~1,800 of the 2,121
	 * players; a real player-owned account authors their own record and maybe a
	 * family member's, so a handful is the natural ceiling. Anything above that
	 * is data entry, and its address is a staff address that must never be
	 * stamped onto a player.
	 */
	const BULK_IMPORT_AUTHOR_THRESHOLD = 5;

	/**
	 * Confidence levels attached to each candidate email.
	 *
	 * Only CONFIDENCE_HIGH rows may be pre-checked in the preview.
	 */
	const CONFIDENCE_HIGH = 'high';
	const CONFIDENCE_LOW  = 'low';

	public function __construct() {
		// Render INSIDE the Player Tools panel (opened by SPT_Admin) via its inner
		// hook — not directly on spat_admin_page_content, which fired after
		// SPT_Admin had already closed the panel <div>, leaving this section
		// outside every tab panel (so it showed on every tab).
		add_action( 'spt_player_tools_content', array( $this, 'render_section' ) );
		add_action( 'admin_post_spt_apply_email_sync', array( $this, 'handle_apply' ) );
		add_action( 'admin_post_spt_export_unmatched_csv', array( $this, 'handle_csv_export' ) );
	}

	/*
	 * Rendering
	*/

	/**
	 * Render the sync section inside the Player Tools tab.
	 *
	 * Fix #8: gate on manage_options before nonce checks — the scan/preview is
	 * admin-only.
	 */
	public function render_section() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show success notice if we just applied.
		if ( isset( $_GET['spt_synced'] ) ) {
			$count   = absint( $_GET['spt_synced'] );
			$skipped = isset( $_GET['spt_skipped'] ) ? absint( $_GET['spt_skipped'] ) : 0;
			echo '<div class="notice notice-success"><p>';
			echo esc_html(
				sprintf(
					/* translators: %d: number of players */
					__( 'Updated %d player email(s).', 'sportspress-player-tools' ),
					$count
				)
			);
			if ( $skipped ) {
				echo ' ';
				echo esc_html(
					sprintf(
						/* translators: %d: number of skipped rows */
						__( 'Skipped %d row(s) due to invalid email.', 'sportspress-player-tools' ),
						$skipped
					)
				);
			}
			echo '</p></div>';
		}

		echo '<hr><h2>' . esc_html__( 'Sync Player Emails', 'sportspress-player-tools' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Populate missing player emails from WooCommerce registration orders and verified linked user accounts. Records created in bulk by staff accounts are not matched to the staff address.', 'sportspress-player-tools' ) . '</p>';

		if ( isset( $_GET['spt_sync_scan'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'spt_email_scan' ) ) {
			$this->render_preview();
		} else {
			$this->render_scan_button();
		}
	}

	/**
	 * Show the scan button with a count of players missing emails.
	 */
	private function render_scan_button() {
		$missing = $this->count_players_missing_email();
		$total   = $this->count_all_players();

		printf(
			'<p>' . esc_html__( '%1$d of %2$d players are missing an email address.', 'sportspress-player-tools' ) . '</p>',
			$missing,
			$total
		);

		if ( $missing === 0 ) {
			echo '<p><strong>' . esc_html__( 'All players have emails. Nothing to sync.', 'sportspress-player-tools' ) . '</strong></p>';
			return;
		}

		$scan_url = wp_nonce_url(
			add_query_arg(
				array(
					'spt_sync_scan' => '1',
					'tab'           => self::TAB, // keep the Player Tools tab active on reload
				)
			),
			'spt_email_scan'
		);
		printf(
			'<a href="%s" class="button button-primary">%s</a>',
			esc_url( $scan_url ),
			esc_html__( 'Scan & Preview Matches', 'sportspress-player-tools' )
		);
	}

	/**
	 * Render the preview table with matched and unmatched players.
	 */
	private function render_preview() {
		$matches = $this->find_matches();

		$matched   = array_filter(
			$matches,
			function ( $m ) {
				return ! empty( $m['emails'] );
			}
		);
		$unmatched = array_filter(
			$matches,
			function ( $m ) {
				return empty( $m['emails'] );
			}
		);

		// --- Matched players table ---
		if ( ! empty( $matched ) ) {
			// PT-SAFETY (audit 2026-08): rows used to render `checked` regardless of
			// how the email was found, under a check-all that was also `checked`, so
			// one click on "Apply Selected" would have written every guess. Only
			// genuinely high-confidence rows are pre-checked now, and the check-all
			// deliberately cannot reach the weak ones.
			$has_high = false;
			foreach ( $matched as $m ) {
				if ( $this->is_high_confidence( $m['emails'][0] ) ) {
					$has_high = true;
					break;
				}
			}

			echo '<h3>' . esc_html__( 'Matched Players', 'sportspress-player-tools' ) . ' (' . count( $matched ) . ')</h3>';
			echo '<p class="description">' . esc_html__( 'Only high-confidence matches are pre-selected. Unchecked rows are guesses — read the Source column and tick them yourself only if you know the address is right.', 'sportspress-player-tools' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="spt_apply_email_sync">';
			wp_nonce_field( 'spt_apply_email_sync', 'spt_sync_nonce' );

			echo '<table class="widefat striped"><thead><tr>';
			if ( $has_high ) {
				echo '<th><input type="checkbox" id="spt-check-all" checked title="'
					. esc_attr__( 'Select all high-confidence rows', 'sportspress-player-tools' ) . '"></th>';
			} else {
				echo '<th></th>';
			}
			echo '<th>' . esc_html__( 'Player', 'sportspress-player-tools' ) . '</th>';
			echo '<th>' . esc_html__( 'Email', 'sportspress-player-tools' ) . '</th>';
			echo '<th>' . esc_html__( 'Source', 'sportspress-player-tools' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $matched as $m ) {
				$player_id = $m['player_id'];
				$best      = $m['emails'][0]; // Highest priority match.
				$high      = $this->is_high_confidence( $best );

				// If multiple emails, show a select.
				if ( count( $m['emails'] ) > 1 ) {
					$email_field = '<select name="email[' . esc_attr( $player_id ) . ']">';
					foreach ( $m['emails'] as $opt ) {
						$email_field .= '<option value="' . esc_attr( $opt['email'] ) . '">'
							. esc_html( $opt['email'] ) . ' (' . esc_html( $opt['source'] ) . ')'
							. '</option>';
					}
					$email_field .= '</select>';
					// Name the winning source rather than a bland "Multiple sources",
					// so a weak default is still visible at a glance.
					$source_text = esc_html( $best['source'] ) . ' ' . esc_html(
						sprintf(
							/* translators: %d: number of alternative email options */
							__( '(+%d other option(s))', 'sportspress-player-tools' ),
							count( $m['emails'] ) - 1
						)
					);
				} else {
					$email_field = '<input type="hidden" name="email[' . esc_attr( $player_id ) . ']" value="' . esc_attr( $best['email'] ) . '">'
						. esc_html( $best['email'] );
					$source_text = esc_html( $best['source'] );
				}

				echo '<tr>';
				echo '<td><input type="checkbox" class="' . ( $high ? 'spt-high-confidence' : 'spt-low-confidence' ) . '"'
					. ' name="players[]" value="' . esc_attr( $player_id ) . '"' . ( $high ? ' checked' : '' ) . '></td>';
				echo '<td><a href="' . esc_url( get_edit_post_link( $player_id ) ) . '">' . esc_html( get_the_title( $player_id ) ) . '</a></td>';
				echo '<td>' . $email_field . '</td>';
				echo '<td>' . $source_text . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '<p class="submit"><button type="submit" class="button button-primary">'
				. esc_html__( 'Apply Selected', 'sportspress-player-tools' ) . '</button></p>';
			echo '</form>';

			// Check-all JS. Capture the toggle's state first; using `.bind(this)`
			// on the forEach callback rebinds `this` to each checkbox, so every box
			// would just be set to its own current state (a no-op).
			// The selector is scoped to .spt-high-confidence on purpose: check-all
			// must not be able to silently re-arm the guesses.
			if ( $has_high ) {
				echo '<script>document.getElementById("spt-check-all").addEventListener("change",function(){';
				echo 'var on=this.checked;document.querySelectorAll(\'input.spt-high-confidence[name="players[]"]\').forEach(function(c){c.checked=on;});';
				echo '});</script>';
			}
		}

		// --- Unmatched players ---
		if ( ! empty( $unmatched ) ) {
			echo '<h3>' . esc_html__( 'Unmatched Players', 'sportspress-player-tools' ) . ' (' . count( $unmatched ) . ')</h3>';
			echo '<p class="description">' . esc_html__( 'These players could not be matched to any WooCommerce data. Export as CSV to fill in manually.', 'sportspress-player-tools' ) . '</p>';

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Player', 'sportspress-player-tools' ) . '</th>';
			echo '<th>' . esc_html__( 'Teams', 'sportspress-player-tools' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $unmatched as $m ) {
				$teams     = wp_get_object_terms(
					$m['player_id'],
					'sp_team',
					array( 'fields' => 'names' )
				);
				$teams_str = is_array( $teams ) ? implode( ', ', $teams ) : '';
				echo '<tr>';
				echo '<td>' . esc_html( get_the_title( $m['player_id'] ) ) . '</td>';
				echo '<td>' . esc_html( $teams_str ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';

			// CSV export button.
			$csv_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=spt_export_unmatched_csv' ),
				'spt_export_unmatched'
			);
			echo '<p><a href="' . esc_url( $csv_url ) . '" class="button">'
				. esc_html__( 'Export Unmatched as CSV', 'sportspress-player-tools' ) . '</a></p>';
		}

		if ( empty( $matched ) && empty( $unmatched ) ) {
			echo '<p><strong>' . esc_html__( 'All players already have emails.', 'sportspress-player-tools' ) . '</strong></p>';
		}
	}

	/*
	 * Matching logic
	*/

	/**
	 * Is this candidate email safe to pre-select?
	 *
	 * @param array $entry One candidate from an emails list.
	 * @return bool
	 */
	private function is_high_confidence( $entry ) {
		return isset( $entry['confidence'] ) && self::CONFIDENCE_HIGH === $entry['confidence'];
	}

	/**
	 * Find email matches for all players missing spt_email.
	 *
	 * @return array Array of [ player_id, emails => [ [email, source, confidence], ... ] ]
	 */
	private function find_matches() {
		$players = $this->get_players_missing_email();
		if ( empty( $players ) ) {
			return array();
		}

		$player_ids = wp_list_pluck( $players, 'ID' );

		// Strategy 1: SPR registration logs → order billing email.
		$spr_emails = $this->match_via_spr_orders( $player_ids );

		// Strategy 2: post_author → user email + billing_email meta.
		$author_emails = $this->match_via_post_author( $players );

		// Merge results.
		$results = array();
		foreach ( $players as $player ) {
			$pid    = $player->ID;
			$emails = array();

			// SPR match first (highest confidence): the registration log ties this
			// exact player row to the order that paid for it.
			if ( isset( $spr_emails[ $pid ] ) ) {
				foreach ( $spr_emails[ $pid ] as $email ) {
					$emails[] = array(
						'email'      => $email,
						'source'     => __( 'Registration order', 'sportspress-player-tools' ),
						'confidence' => self::CONFIDENCE_HIGH,
					);
				}
			}

			// Author match.
			if ( isset( $author_emails[ $pid ] ) ) {
				foreach ( $author_emails[ $pid ] as $entry ) {
					// Avoid duplicates.
					$existing = array_column( $emails, 'email' );
					if ( ! in_array( $entry['email'], $existing, true ) ) {
						$emails[] = $entry;
					}
				}
			}

			$results[] = array(
				'player_id' => $pid,
				'emails'    => $emails,
			);
		}

		return $results;
	}

	/**
	 * Match players to billing emails via SPR registration logs.
	 *
	 * @param array $player_ids Array of player post IDs.
	 * @return array player_id => [email, ...]
	 */
	private function match_via_spr_orders( $player_ids ) {
		global $wpdb;

		$table = $wpdb->prefix . 'spat_registration_logs';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );

		// Join registration logs to order meta to get billing email.
		// WooCommerce may use HPOS (wc_orders table) or legacy postmeta.
		// Check the active storage method, not just table existence.
		$results = array();
		$use_hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$hpos_table = $wpdb->prefix . 'wc_orders';
		if ( $use_hpos && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_table ) ) === $hpos_table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rl.player_id, o.billing_email
					FROM {$table} AS rl
					INNER JOIN {$hpos_table} AS o ON o.id = rl.order_id
					WHERE rl.player_id IN ({$placeholders})
					AND o.billing_email IS NOT NULL AND o.billing_email != ''
					GROUP BY rl.player_id, o.billing_email",
					...$player_ids
				)
			);
		} else {
			// Fallback to postmeta.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT rl.player_id, pm.meta_value AS billing_email
					FROM {$table} AS rl
					INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = rl.order_id AND pm.meta_key = '_billing_email'
					WHERE rl.player_id IN ({$placeholders})
					AND pm.meta_value IS NOT NULL AND pm.meta_value != ''
					GROUP BY rl.player_id, pm.meta_value",
					...$player_ids
				)
			);
		}

		foreach ( $rows as $row ) {
			$email = strtolower( trim( $row->billing_email ) );
			if ( is_email( $email ) ) {
				$results[ (int) $row->player_id ][] = $email;
			}
		}

		return $results;
	}

	/**
	 * Count how many sp_player posts each user authored.
	 *
	 * Deliberately ONE grouped query over the whole post type rather than a
	 * lookup per player: the preview can cover thousands of players, and the
	 * bulk-importer signal is "how much of the roster did this account create",
	 * which is a property of the account, not of the current batch.
	 *
	 * @return array user_id => number of sp_player posts authored.
	 */
	private function get_author_player_counts() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_author, COUNT(*) AS total
				FROM {$wpdb->posts}
				WHERE post_type = %s AND post_status != 'trash'
				GROUP BY post_author",
				'sp_player'
			)
		);

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->post_author ] = (int) $row->total;
		}

		return $counts;
	}

	/**
	 * Match players to emails via their WP user.
	 *
	 * PT-SAFETY (audit 2026-08): this used to read post_author as "the player's
	 * own account" and label whatever it found "Linked user account". post_author
	 * is actually whoever CREATED the record. On rookiehockey.ca that is five
	 * staff accounts covering ~1,800 of 2,121 players, so the old behaviour would
	 * have stamped staff addresses onto players — routing player notifications to
	 * the office and then letting email-based matching confidently pick the wrong
	 * human. Two guards now apply:
	 *
	 *   1. Bulk importers are dropped outright. An account that authored more
	 *      than self::BULK_IMPORT_AUTHOR_THRESHOLD players is doing data entry,
	 *      not playing, and its address is never offered at any confidence. If
	 *      such a person really is a player, an admin types their address in by
	 *      hand — far cheaper than un-picking 1,800 wrong addresses.
	 *   2. What survives is high-confidence ONLY when the player's own sp_user
	 *      meta names that same user, i.e. the registration flow actually linked
	 *      this player to this account. Everything else is a weak hint: it is
	 *      labelled as the record creator and left unchecked in the preview.
	 *
	 * @param array $players Array of WP_Post objects.
	 * @return array player_id => [ [email, source, confidence], ... ]
	 */
	private function match_via_post_author( $players ) {
		$results = array();

		$author_counts = $this->get_author_player_counts();

		// Collect unique post_author IDs that are still worth resolving. Authors
		// over the threshold are skipped here so we never even load their user
		// record, let alone offer their address.
		$author_ids = array();
		foreach ( $players as $player ) {
			$author_id = (int) ( $player->post_author ?? 0 );
			if ( $author_id === 0 ) {
				continue;
			}
			if ( ( $author_counts[ $author_id ] ?? 0 ) > self::BULK_IMPORT_AUTHOR_THRESHOLD ) {
				continue;
			}
			$author_ids[] = $author_id;
		}
		$author_ids = array_unique( $author_ids );
		if ( empty( $author_ids ) ) {
			return $results;
		}

		// Batch-load all remaining users in one query.
		$users    = get_users( array( 'include' => $author_ids ) );
		$user_map = array();
		foreach ( $users as $user ) {
			$user_map[ (int) $user->ID ] = $user;
		}

		foreach ( $players as $player ) {
			$author_id = (int) ( $player->post_author ?? 0 );
			if ( $author_id === 0 ) {
				continue;
			}

			$user = $user_map[ $author_id ] ?? null;
			if ( ! $user ) {
				continue;
			}

			// The only positive evidence that this author IS this player: the
			// player's own sp_user link points back at them. get_post_meta() reads
			// the meta cache primed by get_players_missing_email(), so this costs
			// no extra query.
			$linked_user = (int) get_post_meta( $player->ID, 'sp_user', true );
			$verified    = ( $linked_user > 0 && $linked_user === $author_id );

			if ( $verified ) {
				$confidence  = self::CONFIDENCE_HIGH;
				$user_source = __( 'Linked user account (verified)', 'sportspress-player-tools' );
			} else {
				$confidence  = self::CONFIDENCE_LOW;
				$user_source = __( 'Record creator — may not be the player', 'sportspress-player-tools' );
			}

			$entries = array();

			// WP user email.
			if ( is_email( $user->user_email ) ) {
				$entries[] = array(
					'email'      => strtolower( $user->user_email ),
					'source'     => $user_source,
					'confidence' => $confidence,
				);
			}

			// WooCommerce billing email (may differ from user email). It inherits
			// the same confidence: it is only as trustworthy as the link to the
			// user it hangs off.
			$billing_email = get_user_meta( $user->ID, 'billing_email', true );
			if ( $billing_email && is_email( $billing_email ) && strtolower( $billing_email ) !== strtolower( $user->user_email ) ) {
				$entries[] = array(
					'email'      => strtolower( $billing_email ),
					'source'     => $verified
						? __( 'Billing email (verified account)', 'sportspress-player-tools' )
						: __( 'Record creator billing email — may not be the player', 'sportspress-player-tools' ),
					'confidence' => $confidence,
				);
			}

			if ( ! empty( $entries ) ) {
				$results[ $player->ID ] = $entries;
			}
		}

		return $results;
	}

	/*
	 * Actions
	*/

	/**
	 * Reduce a scan result to player_id => [offered addresses].
	 *
	 * Addresses are lower-cased and trimmed so the comparison in
	 * write_permitted() cannot be defeated by presentation differences.
	 *
	 * @param array $matches Result of find_matches().
	 * @return array player_id => array of normalised email strings.
	 */
	private static function offered_map( array $matches ): array {
		$map = array();

		foreach ( $matches as $row ) {
			// (int) rather than absint(): this helper and write_permitted() are
			// deliberately free of WordPress so they can be tested directly, and
			// the <= 0 guard below already rejects what absint() would clamp.
			$pid = (int) ( $row['player_id'] ?? 0 );
			if ( $pid <= 0 ) {
				continue;
			}

			foreach ( (array) ( $row['emails'] ?? array() ) as $entry ) {
				$email = strtolower( trim( (string) ( $entry['email'] ?? '' ) ) );
				if ( '' !== $email ) {
					$map[ $pid ][] = $email;
				}
			}
		}

		return $map;
	}

	/**
	 * Whether a POSTed (player, email) pair may be written.
	 *
	 * The rendered form is not evidence. Everything that protected this data
	 * used to live in the markup — "only HIGH is pre-checked" was a `checked`
	 * attribute and nothing else — so a stale form, a back-button resubmit or a
	 * hand-edited POST could stamp any address onto any listed player. The
	 * PT-SAFETY note above records a check-all bug of this same family already
	 * shipping once.
	 *
	 * The authority is therefore the scan, re-run at apply time: a pair is
	 * written only if the CURRENT scan still offers that exact address for that
	 * exact player. That also makes the operation self-limiting, because a
	 * player who gained an address between preview and apply is no longer
	 * offered and is skipped rather than overwritten.
	 *
	 * @param array  $offered_map Result of offered_map().
	 * @param int    $player_id   Player post id from the request.
	 * @param string $email       Address from the request.
	 * @return bool
	 */
	private static function write_permitted( array $offered_map, int $player_id, string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( '' === $email || empty( $offered_map[ $player_id ] ) ) {
			return false;
		}

		return in_array( $email, $offered_map[ $player_id ], true );
	}

	/**
	 * The address that may be written for one POSTed player, or null.
	 *
	 * Every reason to refuse a row lives here, so the apply loop reads as
	 * "decide, then write" rather than four interleaved guards.
	 *
	 * @param array $offered Result of offered_map(), the current scan's offer.
	 * @param int   $pid     Player post id from the request.
	 * @param array $emails  Sanitised addresses from the request, keyed by id.
	 * @return string|null Address to write, or null to skip.
	 */
	private function writable_email( array $offered, int $pid, array $emails ): ?string {
		if ( ! isset( $emails[ $pid ] ) ) {
			return null;
		}

		// LOW (player-tools): the POSTed IDs were trusted as-is, so a crafted
		// (or simply stale) submission could stamp spt_email onto ANY post — a
		// page, an order, another plugin's CPT. This tool only ever operates on
		// players, so require the post type before writing.
		if ( 'sp_player' !== get_post_type( $pid ) ) {
			return null;
		}

		// Fix #15: emails already sanitized by the array_map in handle_apply().
		$email = $emails[ $pid ];
		if ( ! $email || ! is_email( $email ) ) {
			return null;
		}

		// The submission is not evidence — see write_permitted().
		if ( ! self::write_permitted( $offered, $pid, $email ) ) {
			return null;
		}

		return $email;
	}

	/**
	 * Handle the "Apply Selected" form submission.
	 */
	public function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-player-tools' ) );
		}
		check_admin_referer( 'spt_apply_email_sync', 'spt_sync_nonce' );

		// LOW (player-tools): $_POST was read without wp_unslash(), so WordPress's
		// added slashes survived into sanitize_email() — an address containing a
		// quote arrived escaped and failed is_email(). Unslash before sanitizing.
		$raw_players = isset( $_POST['players'] ) ? (array) wp_unslash( $_POST['players'] ) : array();
		$raw_emails  = isset( $_POST['email'] ) ? (array) wp_unslash( $_POST['email'] ) : array();

		$player_ids = array_map( 'absint', $raw_players );
		$emails     = array_map( 'sanitize_email', $raw_emails );
		$updated    = 0;
		$skipped    = 0;

		// Re-derive what may be written instead of trusting the submission. See
		// write_permitted(). The scan is the same one that rendered the preview,
		// so this adds no new matching logic — only the refusal to write a pair
		// the current scan does not offer.
		$offered = self::offered_map( $this->find_matches() );

		foreach ( $player_ids as $pid ) {
			$email = $this->writable_email( $offered, $pid, $emails );

			if ( null === $email ) {
				++$skipped;
				continue;
			}

			update_post_meta( $pid, 'spt_email', $email );
			++$updated;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'sportspress-admin-tools',
					'tab'         => self::TAB,
					'spt_synced'  => $updated,
					'spt_skipped' => $skipped,
				),
				admin_url( 'options-general.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize a value for safe CSV output by prefixing formula-triggering characters with a single quote.
	 *
	 * @param mixed $value Cell value.
	 * @return mixed
	 */
	private static function sanitize_csv_value( $value ) {
		if ( is_string( $value ) && isset( $value[0] ) && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			$value = "'" . $value;
		}
		return $value;
	}

	/**
	 * Handle CSV export of unmatched players.
	 */
	public function handle_csv_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-player-tools' ) );
		}
		check_admin_referer( 'spt_export_unmatched' );

		$players = $this->get_players_missing_email();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=unmatched-players-' . wp_date( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Player ID', 'Player Name', 'Teams', 'Email (fill in)' ) );

		foreach ( $players as $player ) {
			$teams = wp_get_object_terms(
				$player->ID,
				'sp_team',
				array( 'fields' => 'names' )
			);
			fputcsv(
				$out,
				array_map(
					array( __CLASS__, 'sanitize_csv_value' ),
					array(
						$player->ID,
						$player->post_title,
						is_array( $teams ) ? implode( ', ', $teams ) : '',
						'',
					)
				)
			);
		}

		fclose( $out );
		exit;
	}

	/*
	 * Queries
	*/

	/**
	 * Get all sp_player posts that are missing spt_email.
	 *
	 * @return array Array of WP_Post objects.
	 */
	private function get_players_missing_email() {
		// PT-7: cap at 5000 rows to bound memory/timeout on large installs,
		// mirroring the LIMIT-5000 pattern used in league-manager. Sites with more
		// than 5000 unsynced players sync in batches across repeated scans.
		return get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => 'publish',
				'posts_per_page' => 5000,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'spt_email',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'spt_email',
						'value'   => '',
						'compare' => '=',
					),
				),
			)
		);
	}

	private function count_players_missing_email() {
		// Perf: avoid loading every WP_Post just to count them. Use found_posts
		// with fields=ids and posts_per_page=1.
		$q = new WP_Query(
			array(
				'post_type'              => 'sp_player',
				'post_status'            => 'publish',
				'meta_query'             => array(
					'relation' => 'OR',
					array(
						'key'     => 'spt_email',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => 'spt_email',
						'value'   => '',
						'compare' => '=',
					),
				),
				'fields'                 => 'ids',
				'posts_per_page'         => 1,
				'no_found_rows'          => false,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => false,
			)
		);
		return (int) $q->found_posts;
	}

	private function count_all_players() {
		return (int) wp_count_posts( 'sp_player' )->publish;
	}
}
