<?php
/**
 * e-Transfer Admin Page
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPET_ETransfer_Admin {

	/** @var string Menu title text domain key */
	const MENU_TITLE = 'e-Transfer Webhooks';

	/** @var int|null Cached pending webhook count for this request */
	private $pending_count = null;

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_woocommerce_menu' ), 99 );
		add_action( 'admin_head', array( $this, 'update_menu_count' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_styles' ) );
	}

	public function enqueue_admin_styles( $hook ) {
		// Only enqueue on our admin page.
		if ( strpos( (string) $hook, 'etransfer-webhooks' ) === false ) {
			return;
		}
		wp_enqueue_style(
			'spet-etransfer-admin',
			plugin_dir_url( __DIR__ ) . 'assets/css/etransfer-admin.css',
			array(),
			defined( 'SPET_VERSION' ) ? SPET_VERSION : '1.0.0'
		);
	}

	public function add_woocommerce_menu() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'e-Transfer Webhooks requires WooCommerce to be active.', 'sportspress-etransfer-automation' ) . '</p></div>';
				}
			);
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( 'e-Transfer Webhooks', 'sportspress-etransfer-automation' ),
			$this->get_menu_title(),
			'manage_woocommerce',
			'etransfer-webhooks',
			array( $this, 'admin_page' )
		);
	}

	private function get_pending_count() {
		if ( $this->pending_count === null ) {
			$this->pending_count = SPET_Database::count_pending_webhooks();
		}
		return $this->pending_count;
	}

	private function get_menu_title() {
		$menu_title = __( 'e-Transfer Webhooks', 'sportspress-etransfer-automation' );
		$pending_count = $this->get_pending_count();

		if ( $pending_count > 0 ) {
			$menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $pending_count . '</span></span>';
		}

		return $menu_title;
	}

	public function update_menu_count() {
		global $menu, $submenu;

		if ( ! isset( $submenu['woocommerce'] ) ) {
			return;
		}

		$pending_count = $this->get_pending_count();

		foreach ( $submenu['woocommerce'] as $key => $item ) {
			if ( $item[2] === 'etransfer-webhooks' ) {
				$menu_title = __( 'e-Transfer Webhooks', 'sportspress-etransfer-automation' );
				if ( $pending_count > 0 ) {
					$menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . $pending_count . '</span></span>';
				}
				$submenu['woocommerce'][ $key ][0] = $menu_title;
				break;
			}
		}
	}

	public function admin_page() {
		// Handle manual match submission
		if ( isset( $_POST['manual_match'] ) && isset( $_POST['log_index'] ) && isset( $_POST['order_id'] )
			&& isset( $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'manual_match_etransfer' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-etransfer-automation' ) );
			}
			$log_id = intval( $_POST['log_index'] );
			$order_id = intval( $_POST['order_id'] );
			$force_mismatch = isset( $_POST['force_mismatch'] ) && $_POST['force_mismatch'] === '1';
			$result = $this->process_manual_match( $log_id, $order_id, $force_mismatch );
			if ( $result === true ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Manual match processed successfully!', 'sportspress-etransfer-automation' ) . '</p></div>';
			} elseif ( is_array( $result ) && isset( $result['error'] ) && $result['error'] === 'amount_mismatch' ) {
				// Render a confirm form requiring force=1
				$mismatch_message = sprintf(
					/* translators: 1: e-Transfer amount, 2: order ID, 3: order total */
					__( 'Amount mismatch: e-Transfer was $%1$.2f but order #%2$d total is $%3$.2f. Confirm to proceed.', 'sportspress-etransfer-automation' ),
					(float) $result['log_amount'],
					(int) $order_id,
					(float) $result['order_total']
				);
				echo '<div class="notice notice-warning"><p>' . esc_html( $mismatch_message ) . '</p>';
				echo '<form method="post" style="margin-top:8px;">';
				wp_nonce_field( 'manual_match_etransfer' );
				echo '<input type="hidden" name="log_index" value="' . esc_attr( $log_id ) . '">';
				echo '<input type="hidden" name="order_id" value="' . esc_attr( $order_id ) . '">';
				echo '<input type="hidden" name="force_mismatch" value="1">';
				echo '<input type="submit" name="manual_match" value="' . esc_attr__( 'Confirm match despite mismatch', 'sportspress-etransfer-automation' ) . '" class="button button-primary">';
				echo '</form></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to process manual match.', 'sportspress-etransfer-automation' ) . '</p></div>';
			}
		}

		// Handle hide submission
		if ( isset( $_POST['hide_log'] ) && isset( $_POST['log_id'] )
			&& isset( $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'hide_etransfer_log' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-etransfer-automation' ) );
			}
			$hide_log_id = intval( $_POST['log_id'] );
			if ( SPET_Database::hide_etransfer_log( $hide_log_id ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Log entry hidden from management page!', 'sportspress-etransfer-automation' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to hide log entry.', 'sportspress-etransfer-automation' ) . '</p></div>';
			}
		}

		// Handle purge old logs
		if ( isset( $_POST['purge_old_logs'] )
			&& isset( $_POST['_wpnonce'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'spet_purge_old_logs' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-etransfer-automation' ) );
			}
			$deleted = SPET_Database::cleanup_old_logs( 90 );
			if ( $deleted !== false ) {
				echo '<div class="notice notice-success"><p>' . sprintf( __( 'Purged %d log entries older than 90 days.', 'sportspress-etransfer-automation' ), intval( $deleted ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to purge old logs.', 'sportspress-etransfer-automation' ) . '</p></div>';
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'e-Transfer Webhook Management', 'sportspress-etransfer-automation' ); ?></h1>
			
			<h2><?php esc_html_e( 'Unmatched Webhooks', 'sportspress-etransfer-automation' ); ?></h2>
			<?php
			$unmatched_logs = SPET_Database::get_unmatched_etransfer_logs( 50 );
			$this->display_unmatched_webhooks( $unmatched_logs );
			?>

			<h2><?php esc_html_e( 'All Webhook Activity', 'sportspress-etransfer-automation' ); ?></h2>
			<?php
			$all_logs = SPET_Database::get_etransfer_logs( 50, true );
			$this->display_all_webhooks( $all_logs );
			?>
			
			<h2><?php esc_html_e( 'Log Maintenance', 'sportspress-etransfer-automation' ); ?></h2>
			<p><?php esc_html_e( 'Logs older than 90 days are automatically cleaned up daily. You can also purge them manually.', 'sportspress-etransfer-automation' ); ?></p>
			<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all log entries older than 90 days?', 'sportspress-etransfer-automation' ) ); ?>')">
				<?php wp_nonce_field( 'spet_purge_old_logs' ); ?>
				<input type="submit" name="purge_old_logs" value="<?php esc_attr_e( 'Purge Logs Older Than 90 Days', 'sportspress-etransfer-automation' ); ?>" class="button button-secondary" />
			</form>
		</div>
		<?php
	}

	private function display_unmatched_webhooks( $logs ) {
		if ( $logs === false ) {
			echo '<p>' . esc_html__( 'Error retrieving webhook logs.', 'sportspress-etransfer-automation' ) . '</p>';
			return;
		}

		$unmatched = is_array( $logs ) ? $logs : array();

		if ( empty( $unmatched ) ) {
			echo '<p>' . esc_html__( 'No unmatched webhooks found.', 'sportspress-etransfer-automation' ) . '</p>';
			return;
		}

		// Fetch on-hold orders once for all unmatched rows
		$orders = wc_get_orders(
			array(
				'status' => 'on-hold',
				'limit' => 50,
				'orderby' => 'date',
				'order' => 'DESC',
			)
		);

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Evidence', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Match to Order', 'sportspress-etransfer-automation' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $unmatched as $log ) {
				echo '<tr>';
				echo '<td>' . esc_html( $log->timestamp ) . '</td>';
				echo '<td>' . esc_html( $log->from_name ) . '<br><small>' . esc_html( $log->from_email ) . '</small></td>';
				echo '<td>' . esc_html( '$' . number_format( $log->amount, 2 ) ) . '</td>';
				echo '<td>' . esc_html( $log->reference_number ?: 'N/A' ) . '</td>';
				echo '<td>' . esc_html( $log->result ) . '</td>';
				echo '<td>';
				self::render_evidence_cell( $log );
				echo '</td>';
				echo '<td>';

				echo '<form method="post" style="display:inline;">';
				wp_nonce_field( 'manual_match_etransfer' );
				echo '<input type="hidden" name="log_index" value="' . esc_attr( $log->id ) . '">';
				echo '<select name="order_id" required style="margin-right:5px;">';
				echo '<option value="">' . esc_html__( 'Select Order', 'sportspress-etransfer-automation' ) . '</option>';

			foreach ( $orders as $order ) {
				echo '<option value="' . esc_attr( $order->get_id() ) . '">#' . esc_html( $order->get_id() ) . ' - ' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . ' ($' . esc_html( $order->get_total() ) . ')</option>';
			}

				echo '</select>';
				echo '<input type="submit" name="manual_match" value="' . esc_attr__( 'Match & Complete', 'sportspress-etransfer-automation' ) . '" class="button button-primary">';
				echo '</form>';

				// Add hide button
				echo '<form method="post" style="display:inline;margin-left:10px;" onsubmit="return confirm(\'' . esc_js( __( 'Hide this entry from the management page? It will still be visible in the settings page logs.', 'sportspress-etransfer-automation' ) ) . '\')">';
				wp_nonce_field( 'hide_etransfer_log' );
				echo '<input type="hidden" name="log_id" value="' . esc_attr( $log->id ) . '">';
				echo '<input type="submit" name="hide_log" value="' . esc_attr__( 'Hide', 'sportspress-etransfer-automation' ) . '" class="button button-secondary">';
				echo '</form>';

				echo '</td>';
				echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Render the stored raw email for an unmatched row.
	 *
	 * H3: rows written when the Interac parser failed ('extraction_failed') carry
	 * no sender, no amount and no reference — they used to render as a blank line
	 * with a "Match & Complete" button and nothing to decide on, while the only
	 * copy of the evidence sat unread in webhook_data until the 30-day PII sweep
	 * cleared it. Showing the extracted text makes the row actionable.
	 *
	 * @param object $log Row from SPET_Database::get_unmatched_etransfer_logs().
	 */
	private static function render_evidence_cell( $log ) {
		if ( empty( $log->webhook_data ) ) {
			echo '<span class="description">' . esc_html__( 'Not retained', 'sportspress-etransfer-automation' ) . '</span>';
			return;
		}

		$payload = maybe_unserialize( $log->webhook_data );

		$text = '';
		if ( is_array( $payload ) ) {
			if ( isset( $payload['text'] ) && is_string( $payload['text'] ) ) {
				$text = $payload['text'];
			} else {
				$text = wp_json_encode( $payload );
			}
		} elseif ( is_string( $payload ) ) {
			$text = $payload;
		}

		$text = trim( (string) $text );
		if ( '' === $text ) {
			echo '<span class="description">' . esc_html__( 'Not retained', 'sportspress-etransfer-automation' ) . '</span>';
			return;
		}

		// Cap what we paint into the page; the full value stays in the row.
		if ( strlen( $text ) > 4000 ) {
			$text = substr( $text, 0, 4000 ) . "\n…";
		}

		echo '<details><summary>' . esc_html__( 'View email', 'sportspress-etransfer-automation' ) . '</summary>';
		echo '<pre style="max-height:16em;overflow:auto;white-space:pre-wrap;word-break:break-word;">' . esc_html( $text ) . '</pre>';
		echo '</details>';
	}

	private function display_all_webhooks( $logs ) {
		if ( $logs === false ) {
			echo '<p>' . esc_html__( 'Error retrieving webhook logs.', 'sportspress-etransfer-automation' ) . '</p>';
			return;
		}

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No webhook activity recorded yet.', 'sportspress-etransfer-automation' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Match Criteria', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'sportspress-etransfer-automation' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'sportspress-etransfer-automation' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$status_class = strpos( $log->result, 'successfully' ) !== false ? 'success' : 'error';
			echo '<tr>';
			echo '<td>' . esc_html( $log->timestamp ) . '</td>';
			echo '<td>' . esc_html( $log->from_name ) . '<br><small>' . esc_html( $log->from_email ) . '</small></td>';
			echo '<td>' . esc_html( '$' . number_format( $log->amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( $log->reference_number ?: 'N/A' ) . '</td>';
			echo '<td>' . esc_html( $log->match_criteria ?: 'N/A' ) . '</td>';
			echo '<td>' . ( $log->order_id ? '<a href="' . esc_url( self::get_order_edit_url( intval( $log->order_id ) ) ) . '">#' . esc_html( $log->order_id ) . '</a>' : 'N/A' ) . '</td>';
			echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $log->result ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
	}

	/**
	 * Build an HPOS-aware order edit URL. Under WooCommerce HPOS (custom order
	 * tables) the legacy post.php?post=ID&action=edit URL no longer resolves to
	 * the order screen, so we prefer WC_Order::get_edit_order_url() when the
	 * order can be hydrated. Falls back to the legacy URL only if WooCommerce or
	 * the order is unavailable.
	 */
	public static function get_order_edit_url( $order_id ) {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				return $order->get_edit_order_url();
			}
		}
		return admin_url( 'post.php?post=' . intval( $order_id ) . '&action=edit' );
	}

	private function process_manual_match( $log_id, $order_id, $force_mismatch = false ) {
		global $wpdb;
		$table_name = $wpdb->prefix . 'spat_etransfer_logs';

		$log = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM `$table_name` WHERE id = %d",
				intval( $log_id )
			)
		);

		if ( $log === null ) {
			if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
				error_log( 'SPAT: Database error fetching log - ' . $wpdb->last_error );
			}
			return false;
		}

		// Refuse to re-process an already-matched log entry.
		if ( ! empty( $log->order_id ) || ( isset( $log->result ) && strpos( $log->result, 'Manually matched' ) === 0 ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order || $order->get_status() !== 'on-hold' ) {
			return false;
		}

		// Check for amount mismatch — require explicit force_mismatch confirmation.
		// We compute the mismatch state here so we can gate the WHERE-claim, but
		// defer any order side-effects (notes, transaction id, status flip) until
		// AFTER the claim wins. Otherwise concurrent admin submissions can leak
		// duplicate notes onto the order even when only one wins the claim.
		$order_total = floatval( $order->get_total() );
		$log_amount = floatval( $log->amount );
		$has_mismatch = abs( $order_total - $log_amount ) > 0.01;
		if ( $has_mismatch && ! $force_mismatch ) {
			return array(
				'error' => 'amount_mismatch',
				'log_amount' => $log_amount,
				'order_total' => $order_total,
			);
		}

		// Conditionally claim the log row BEFORE touching the order. The WHERE
		// clause ensures only ONE concurrent admin request wins; the other gets
		// rows_affected = 0 and bails out without ever touching the order.
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE `{$wpdb->prefix}spat_etransfer_logs`
				SET order_id = %d,
					result = %s,
					match_criteria = %s
				WHERE id = %d AND (order_id IS NULL OR order_id = 0)",
				intval( $order_id ),
				'Manually matched and processed successfully',
				'Manual Match',
				intval( $log_id )
			)
		);

		if ( $claimed === false ) {
			if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
				error_log( 'SPAT: Failed to update log entry - ' . $wpdb->last_error );
			}
			return false;
		}

		// Only touch the order when this request actually won the claim.
		if ( (int) $wpdb->rows_affected !== 1 ) {
			return false;
		}

		// M6: the claim above is deliberately taken BEFORE the order side-effects
		// (so two concurrent admins can't both stamp notes onto the order), but it
		// writes "…processed successfully" optimistically. Everything below is
		// therefore verified, and the claim is rolled back if the order does not
		// actually reach 'completed' — otherwise a post-claim failure leaves a
		// permanently unfixable "success" row against an uncompleted order.
		//
		// H5: take the same per-order lock the webhook path uses and re-read the
		// status inside it, so an admin match and an inbound e-Transfer (or two
		// admins matching different log rows to the same order) cannot both
		// complete it.
		$order_lock = class_exists( 'SPET_ETransfer_Automation' )
			? SPET_ETransfer_Automation::acquire_order_lock( $order_id )
			: true;

		$completed = false;
		if ( false !== $order_lock ) {
			try {
				clean_post_cache( $order_id );
				wp_cache_delete( $order_id, 'orders' );
				$order = wc_get_order( $order_id );

				if ( $order && $order->has_status( 'on-hold' ) ) {
					// Mismatch note (only the winner records this).
					if ( $has_mismatch ) {
						$order->add_order_note(
							sprintf(
								/* translators: 1: e-Transfer amount, 2: order total */
								__( 'Amount mismatch: e-Transfer was $%1$.2f but order total is $%2$.2f. Manually confirmed by admin.', 'sportspress-etransfer-automation' ),
								$log_amount,
								$order_total
							)
						);
					}

					// Add transaction ID (reference number)
					if ( ! empty( $log->reference_number ) ) {
						$order->set_transaction_id( $log->reference_number );
					}

					// Add order note
					$note = sprintf(
						/* translators: 1: reference number, 2: amount */
						__( 'e-Transfer payment processed manually from webhook log. Reference: %1$s, Amount: $%2$.2f', 'sportspress-etransfer-automation' ),
						$log->reference_number ?: 'N/A',
						$log->amount ?: 0
					);
					$order->add_order_note( $note );

					// Update order status to completed
					$status_ok = $order->update_status( 'completed', __( 'Payment confirmed via manual webhook match.', 'sportspress-etransfer-automation' ) );
					$saved_id = $order->save();

					$completed = ( false !== $status_ok && ! empty( $saved_id ) && $order->has_status( 'completed' ) );
				}
			} catch ( \Exception $e ) {
				$completed = false;
				error_log( '[SPET] Manual match failed for order ' . intval( $order_id ) . ': ' . $e->getMessage() );
			} finally {
				if ( class_exists( 'SPET_ETransfer_Automation' ) ) {
					SPET_ETransfer_Automation::release_order_lock( $order_id, $order_lock );
				}
			}
		}

		if ( ! $completed ) {
			// Release the claim so the row returns to the review list and the
			// admin can retry, and never leave a "success" result standing behind
			// an order that was not completed.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE `{$wpdb->prefix}spat_etransfer_logs`
					SET order_id = NULL,
						result = %s,
						match_criteria = %s
					WHERE id = %d AND order_id = %d",
					SPET_Database::RESULT_MANUAL_MATCH_FAILED,
					'Manual Match',
					intval( $log_id ),
					intval( $order_id )
				)
			);
			return false;
		}

		return true;
	}
}
