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
					echo '<div class="notice notice-error"><p>' . esc_html__( 'e-Transfer Webhooks requires WooCommerce to be active.', 'sportspress-admin-tools' ) . '</p></div>';
				}
			);
			return;
		}

		add_submenu_page(
			'woocommerce',
			__( self::MENU_TITLE, 'sportspress-admin-tools' ),
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
		$menu_title = __( self::MENU_TITLE, 'sportspress-admin-tools' );
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
				$menu_title = __( self::MENU_TITLE, 'sportspress-admin-tools' );
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
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'manual_match_etransfer' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-admin-tools' ) );
			}
			$log_id = intval( $_POST['log_index'] );
			$order_id = intval( $_POST['order_id'] );
			$force_mismatch = isset( $_POST['force_mismatch'] ) && $_POST['force_mismatch'] === '1';
			$result = $this->process_manual_match( $log_id, $order_id, $force_mismatch );
			if ( $result === true ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Manual match processed successfully!', 'sportspress-admin-tools' ) . '</p></div>';
			} elseif ( is_array( $result ) && isset( $result['error'] ) && $result['error'] === 'amount_mismatch' ) {
				// Render a confirm form requiring force=1
				$mismatch_message = sprintf(
					/* translators: 1: e-Transfer amount, 2: order ID, 3: order total */
					__( 'Amount mismatch: e-Transfer was $%1$.2f but order #%2$d total is $%3$.2f. Confirm to proceed.', 'sportspress-admin-tools' ),
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
				echo '<input type="submit" name="manual_match" value="' . esc_attr__( 'Confirm match despite mismatch', 'sportspress-admin-tools' ) . '" class="button button-primary">';
				echo '</form></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to process manual match.', 'sportspress-admin-tools' ) . '</p></div>';
			}
		}

		// Handle hide submission
		if ( isset( $_POST['hide_log'] ) && isset( $_POST['log_id'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'hide_etransfer_log' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-admin-tools' ) );
			}
			$hide_log_id = intval( $_POST['log_id'] );
			if ( SPET_Database::hide_etransfer_log( $hide_log_id ) ) {
				echo '<div class="notice notice-success"><p>' . esc_html__( 'Log entry hidden from management page!', 'sportspress-admin-tools' ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to hide log entry.', 'sportspress-admin-tools' ) . '</p></div>';
			}
		}

		// Handle purge old logs
		if ( isset( $_POST['purge_old_logs'] )
			&& wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'spet_purge_old_logs' ) ) {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				wp_die( __( 'You do not have permission to perform this action.', 'sportspress-admin-tools' ) );
			}
			$deleted = SPET_Database::cleanup_old_logs( 90 );
			if ( $deleted !== false ) {
				echo '<div class="notice notice-success"><p>' . sprintf( __( 'Purged %d log entries older than 90 days.', 'sportspress-admin-tools' ), intval( $deleted ) ) . '</p></div>';
			} else {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Failed to purge old logs.', 'sportspress-admin-tools' ) . '</p></div>';
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'e-Transfer Webhook Management', 'sportspress-admin-tools' ); ?></h1>
			
			<h2><?php esc_html_e( 'Unmatched Webhooks', 'sportspress-admin-tools' ); ?></h2>
			<?php
			$unmatched_logs = SPET_Database::get_unmatched_etransfer_logs( 50 );
			$this->display_unmatched_webhooks( $unmatched_logs );
			?>

			<h2><?php esc_html_e( 'All Webhook Activity', 'sportspress-admin-tools' ); ?></h2>
			<?php
			$all_logs = SPET_Database::get_etransfer_logs( 50, true );
			$this->display_all_webhooks( $all_logs );
			?>
			
			<h2><?php esc_html_e( 'Log Maintenance', 'sportspress-admin-tools' ); ?></h2>
			<p><?php esc_html_e( 'Logs older than 90 days are automatically cleaned up daily. You can also purge them manually.', 'sportspress-admin-tools' ); ?></p>
			<form method="post" style="display:inline;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete all log entries older than 90 days?', 'sportspress-admin-tools' ) ); ?>')">
				<?php wp_nonce_field( 'spet_purge_old_logs' ); ?>
				<input type="submit" name="purge_old_logs" value="<?php esc_attr_e( 'Purge Logs Older Than 90 Days', 'sportspress-admin-tools' ); ?>" class="button button-secondary" />
			</form>
		</div>
		<?php
	}

	private function display_unmatched_webhooks( $logs ) {
		if ( $logs === false ) {
			echo '<p>' . esc_html__( 'Error retrieving webhook logs.', 'sportspress-admin-tools' ) . '</p>';
			return;
		}

		$unmatched = is_array( $logs ) ? $logs : array();

		if ( empty( $unmatched ) ) {
			echo '<p>' . esc_html__( 'No unmatched webhooks found.', 'sportspress-admin-tools' ) . '</p>';
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
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Match to Order', 'sportspress-admin-tools' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $unmatched as $log ) {
				echo '<tr>';
				echo '<td>' . esc_html( $log->timestamp ) . '</td>';
				echo '<td>' . esc_html( $log->from_name ) . '<br><small>' . esc_html( $log->from_email ) . '</small></td>';
				echo '<td>' . esc_html( '$' . number_format( $log->amount, 2 ) ) . '</td>';
				echo '<td>' . esc_html( $log->reference_number ?: 'N/A' ) . '</td>';
				echo '<td>' . esc_html( $log->result ) . '</td>';
				echo '<td>';

				echo '<form method="post" style="display:inline;">';
				wp_nonce_field( 'manual_match_etransfer' );
				echo '<input type="hidden" name="log_index" value="' . esc_attr( $log->id ) . '">';
				echo '<select name="order_id" required style="margin-right:5px;">';
				echo '<option value="">' . esc_html__( 'Select Order', 'sportspress-admin-tools' ) . '</option>';

			foreach ( $orders as $order ) {
				echo '<option value="' . esc_attr( $order->get_id() ) . '">#' . esc_html( $order->get_id() ) . ' - ' . esc_html( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) . ' ($' . esc_html( $order->get_total() ) . ')</option>';
			}

				echo '</select>';
				echo '<input type="submit" name="manual_match" value="' . esc_attr__( 'Match & Complete', 'sportspress-admin-tools' ) . '" class="button button-primary">';
				echo '</form>';

				// Add hide button
				echo '<form method="post" style="display:inline;margin-left:10px;" onsubmit="return confirm(\'' . esc_js( __( 'Hide this entry from the management page? It will still be visible in the settings page logs.', 'sportspress-admin-tools' ) ) . '\')">';
				wp_nonce_field( 'hide_etransfer_log' );
				echo '<input type="hidden" name="log_id" value="' . esc_attr( $log->id ) . '">';
				echo '<input type="submit" name="hide_log" value="' . esc_attr__( 'Hide', 'sportspress-admin-tools' ) . '" class="button button-secondary">';
				echo '</form>';

				echo '</td>';
				echo '</tr>';
		}

		echo '</tbody></table>';
	}

	private function display_all_webhooks( $logs ) {
		if ( $logs === false ) {
			echo '<p>' . esc_html__( 'Error retrieving webhook logs.', 'sportspress-admin-tools' ) . '</p>';
			return;
		}

		if ( empty( $logs ) ) {
			echo '<p>' . esc_html__( 'No webhook activity recorded yet.', 'sportspress-admin-tools' ) . '</p>';
			return;
		}

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Timestamp', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'From', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Amount', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Reference', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Match Criteria', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Order', 'sportspress-admin-tools' ) . '</th>';
		echo '<th>' . esc_html__( 'Result', 'sportspress-admin-tools' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $logs as $log ) {
			$status_class = strpos( $log->result, 'successfully' ) !== false ? 'success' : 'error';
			echo '<tr>';
			echo '<td>' . esc_html( $log->timestamp ) . '</td>';
			echo '<td>' . esc_html( $log->from_name ) . '<br><small>' . esc_html( $log->from_email ) . '</small></td>';
			echo '<td>' . esc_html( '$' . number_format( $log->amount, 2 ) ) . '</td>';
			echo '<td>' . esc_html( $log->reference_number ?: 'N/A' ) . '</td>';
			echo '<td>' . esc_html( $log->match_criteria ?: 'N/A' ) . '</td>';
			echo '<td>' . ( $log->order_id ? '<a href="' . esc_url( admin_url( 'post.php?post=' . intval( $log->order_id ) . '&action=edit' ) ) . '">#' . esc_html( $log->order_id ) . '</a>' : 'N/A' ) . '</td>';
			echo '<td><span class="' . esc_attr( $status_class ) . '">' . esc_html( $log->result ) . '</span></td>';
			echo '</tr>';
		}

		echo '</tbody></table>';
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
		$order_total = floatval( $order->get_total() );
		$log_amount = floatval( $log->amount );
		if ( abs( $order_total - $log_amount ) > 0.01 ) {
			if ( ! $force_mismatch ) {
				return array(
					'error' => 'amount_mismatch',
					'log_amount' => $log_amount,
					'order_total' => $order_total,
				);
			}
			$order->add_order_note(
				sprintf(
					/* translators: 1: e-Transfer amount, 2: order total */
					__( 'Amount mismatch: e-Transfer was $%1$.2f but order total is $%2$.2f. Manually confirmed by admin.', 'sportspress-admin-tools' ),
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
			__( 'e-Transfer payment processed manually from webhook log. Reference: %1$s, Amount: $%2$.2f', 'sportspress-admin-tools' ),
			$log->reference_number ?: 'N/A',
			$log->amount ?: 0
		);
		$order->add_order_note( $note );

		// Conditionally claim the log row before completing the order. The
		// WHERE clause ensures only ONE concurrent admin request wins; the
		// other gets rows_affected = 0 and bails out without flipping the
		// order to completed twice.
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

		// Only flip the order status when this request actually won the claim.
		if ( (int) $wpdb->rows_affected !== 1 ) {
			return false;
		}

		// Update order status to completed
		$order->update_status( 'completed', __( 'Payment confirmed via manual webhook match.', 'sportspress-admin-tools' ) );
		$order->save();

		return true;
	}
}
