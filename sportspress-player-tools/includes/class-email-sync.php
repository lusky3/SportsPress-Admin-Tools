<?php
/**
 * Email Sync — bulk-populate spt_email for players missing it.
 *
 * Matching priority:
 * 1. SPR registration log → order billing email (highest confidence)
 * 2. post_author → WP user email + billing_email user meta
 * 3. Unmatched → CSV export for manual entry
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPT_Email_Sync {

	public function __construct() {
		add_action( 'spat_admin_page_content', array( $this, 'render_section' ), 20 );
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
		echo '<p class="description">' . esc_html__( 'Populate missing player emails from WooCommerce registration orders and linked user accounts.', 'sportspress-player-tools' ) . '</p>';

		if ( isset( $_GET['spt_sync_scan'] ) && wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'spt_email_scan' ) ) {
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
			add_query_arg( 'spt_sync_scan', '1' ),
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
			echo '<h3>' . esc_html__( 'Matched Players', 'sportspress-player-tools' ) . ' (' . count( $matched ) . ')</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			echo '<input type="hidden" name="action" value="spt_apply_email_sync">';
			wp_nonce_field( 'spt_apply_email_sync', 'spt_sync_nonce' );

			echo '<table class="widefat striped"><thead><tr>';
			echo '<th><input type="checkbox" id="spt-check-all" checked></th>';
			echo '<th>' . esc_html__( 'Player', 'sportspress-player-tools' ) . '</th>';
			echo '<th>' . esc_html__( 'Email', 'sportspress-player-tools' ) . '</th>';
			echo '<th>' . esc_html__( 'Source', 'sportspress-player-tools' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $matched as $m ) {
				$player_id = $m['player_id'];
				$best      = $m['emails'][0]; // Highest priority match.

				// If multiple emails, show a select.
				if ( count( $m['emails'] ) > 1 ) {
					$email_field = '<select name="email[' . esc_attr( $player_id ) . ']">';
					foreach ( $m['emails'] as $opt ) {
						$email_field .= '<option value="' . esc_attr( $opt['email'] ) . '">'
							. esc_html( $opt['email'] ) . ' (' . esc_html( $opt['source'] ) . ')'
							. '</option>';
					}
					$email_field .= '</select>';
					$source_text  = esc_html__( 'Multiple sources', 'sportspress-player-tools' );
				} else {
					$email_field = '<input type="hidden" name="email[' . esc_attr( $player_id ) . ']" value="' . esc_attr( $best['email'] ) . '">'
						. esc_html( $best['email'] );
					$source_text = esc_html( $best['source'] );
				}

				echo '<tr>';
				echo '<td><input type="checkbox" name="players[]" value="' . esc_attr( $player_id ) . '" checked></td>';
				echo '<td><a href="' . esc_url( get_edit_post_link( $player_id ) ) . '">' . esc_html( get_the_title( $player_id ) ) . '</a></td>';
				echo '<td>' . $email_field . '</td>';
				echo '<td>' . $source_text . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '<p class="submit"><button type="submit" class="button button-primary">'
				. esc_html__( 'Apply Selected', 'sportspress-player-tools' ) . '</button></p>';
			echo '</form>';

			// Check-all JS.
			echo '<script>document.getElementById("spt-check-all").addEventListener("change",function(){';
			echo 'document.querySelectorAll(\'input[name="players[]"]\').forEach(function(c){c.checked=this.checked}.bind(this));';
			echo '});</script>';
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
	 * Find email matches for all players missing spt_email.
	 *
	 * @return array Array of [ player_id, player_name, emails => [ [email, source], ... ] ]
	 */
	private function find_matches() {
		global $wpdb;

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

			// SPR match first (highest confidence).
			if ( isset( $spr_emails[ $pid ] ) ) {
				foreach ( $spr_emails[ $pid ] as $email ) {
					$emails[] = array(
						'email'  => $email,
						'source' => __( 'Registration order', 'sportspress-player-tools' ),
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
	 * Match players to emails via their linked WP user (post_author).
	 *
	 * @param array $players Array of WP_Post objects.
	 * @return array player_id => [ [email, source], ... ]
	 */
	private function match_via_post_author( $players ) {
		$results = array();

		// Collect unique non-zero post_author IDs.
		$author_ids = array();
		foreach ( $players as $player ) {
			if ( ! empty( $player->post_author ) && (int) $player->post_author !== 0 ) {
				$author_ids[] = (int) $player->post_author;
			}
		}
		$author_ids = array_unique( $author_ids );
		if ( empty( $author_ids ) ) {
			return $results;
		}

		// Batch-load all users in one query.
		$users    = get_users( array( 'include' => $author_ids ) );
		$user_map = array();
		foreach ( $users as $user ) {
			$user_map[ $user->ID ] = $user;
		}

		foreach ( $players as $player ) {
			if ( empty( $player->post_author ) || (int) $player->post_author === 0 ) {
				continue;
			}

			$user = $user_map[ (int) $player->post_author ] ?? null;
			if ( ! $user ) {
				continue;
			}

			$entries = array();

			// WP user email.
			if ( is_email( $user->user_email ) ) {
				$entries[] = array(
					'email'  => strtolower( $user->user_email ),
					'source' => __( 'Linked user account', 'sportspress-player-tools' ),
				);
			}

			// WooCommerce billing email (may differ from user email).
			$billing_email = get_user_meta( $user->ID, 'billing_email', true );
			if ( $billing_email && is_email( $billing_email ) && strtolower( $billing_email ) !== strtolower( $user->user_email ) ) {
				$entries[] = array(
					'email'  => strtolower( $billing_email ),
					'source' => __( 'Billing email', 'sportspress-player-tools' ),
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
	 * Handle the "Apply Selected" form submission.
	 */
	public function handle_apply() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'sportspress-player-tools' ) );
		}
		check_admin_referer( 'spt_apply_email_sync', 'spt_sync_nonce' );

		$player_ids = array_map( 'absint', $_POST['players'] ?? array() );
		$emails     = array_map( 'sanitize_email', (array) ( $_POST['email'] ?? array() ) );
		$updated    = 0;
		$skipped    = 0;

		foreach ( $player_ids as $pid ) {
			if ( ! isset( $emails[ $pid ] ) ) {
				++$skipped;
				continue;
			}
			// Fix #15: emails already sanitized by the array_map above.
			$email = $emails[ $pid ];
			if ( $email && is_email( $email ) ) {
				update_post_meta( $pid, 'spt_email', $email );
				++$updated;
			} else {
				++$skipped;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'sportspress-admin-tools',
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
		return get_posts(
			array(
				'post_type'      => 'sp_player',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
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
		$q = new WP_Query( array(
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
		) );
		return (int) $q->found_posts;
	}

	private function count_all_players() {
		return (int) wp_count_posts( 'sp_player' )->publish;
	}
}
