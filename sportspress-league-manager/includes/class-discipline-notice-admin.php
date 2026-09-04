<?php
/**
 * The technical notice queue, on the SPAT settings page.
 *
 * A second League Manager tab rather than a section inside the existing one:
 * that panel is a single <form action="options.php">, so an actionable queue
 * nested in it would be invalid HTML and would post Release and Discard to
 * options.php.
 *
 * Actions call the REST routes rather than an admin_post_* handler, so release
 * logic lives in exactly one place and both surfaces are gated identically.
 *
 * @author Cody (lusky3)
 *
 * One method per rendered region of a single admin table. Collapsing them
 * would rebuild a 70-line render method, which is what they came out of.
 *
 * @SuppressWarnings(PHPMD.TooManyMethods)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Notice_Admin {

	const PER_PAGE = 100;

	public function __construct() {
		add_action( 'spat_admin_page_tabs', array( $this, 'add_tab' ) );
		add_action( 'spat_admin_page_content', array( $this, 'add_content' ) );
	}

	/**
	 * The nav tab.
	 *
	 * @return void
	 */
	public function add_tab() {
		echo '<a href="#discipline-queue" class="nav-tab">' . esc_html__( 'Discipline Queue', 'sportspress-league-manager' ) . '</a>';
	}

	/**
	 * The tab panel.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	public function add_content() {
		echo '<div id="discipline-queue" class="tab-content" style="display: none;">';

		// A disabled module makes this view READ-ONLY rather than blank. The
		// REST routes and the React page are both module-gated and 503 when it
		// is off — but this table reads the database directly, and an
		// administrator looking at a feature someone just switched off is
		// usually trying to find out what it already sent. Withholding the
		// audit trail is the one thing this surface must not do.
		$readonly  = ! SPLM_REST_API::module_enabled( 'league_discipline' );
		$season_id = (int) get_option( 'splm_default_season', 0 );

		if ( $readonly ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Penalty Discipline module is disabled. No notices are being evaluated and no action can be taken here; the rows below are history.', 'sportspress-league-manager' ) . '</p></div>';
		}

		$this->render_diagnostics( $season_id );

		if ( ! $season_id ) {
			echo '<p>' . esc_html__( 'No default season is set, so the evaluation pass does nothing. Set a Season Override on the League Manager tab.', 'sportspress-league-manager' ) . '</p></div>';
			return;
		}

		$status = $this->requested_status();

		$this->render_filter( $status );
		$this->render_table( $season_id, $status, $readonly );

		if ( ! $readonly ) {
			$this->render_script();
		}

		echo '</div>';
	}

	/**
	 * The status filter from the query string.
	 *
	 * @return string A valid status, or '' for all.
	 *
	 * @SuppressWarnings(PHPMD.Superglobals)
	 */
	private function requested_status(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter on a settings screen; changes no state.
		$raw = isset( $_GET['splm_notice_status'] ) ? sanitize_key( wp_unslash( $_GET['splm_notice_status'] ) ) : '';

		return in_array( $raw, SPLM_Discipline_Notice_Database::STATUSES, true ) ? $raw : '';
	}

	/**
	 * Status filter links.
	 *
	 * Links rather than a form, so this cannot post into the settings form
	 * that shares the page.
	 *
	 * @param string $current Selected status.
	 * @return void
	 */
	private function render_filter( string $current ): void {
		$base = admin_url( 'options-general.php?page=sportspress-admin-tools&tab=discipline-queue' );

		$links = array( '' => __( 'All', 'sportspress-league-manager' ) );
		foreach ( SPLM_Discipline_Notice_Database::STATUSES as $status ) {
			$links[ $status ] = $status;
		}

		$out = array();
		foreach ( $links as $value => $label ) {
			$url   = $value ? add_query_arg( 'splm_notice_status', $value, $base ) : $base;
			$out[] = sprintf(
				'<a href="%s"%s>%s</a>',
				esc_url( $url . '#discipline-queue' ),
				$value === $current ? ' style="font-weight:700;text-decoration:none"' : '',
				esc_html( $label )
			);
		}

		echo '<p>' . esc_html__( 'Filter:', 'sportspress-league-manager' ) . ' ' . wp_kses_post( implode( ' | ', $out ) ) . '</p>';
	}

	/**
	 * Cron, mode and row-count diagnostics.
	 *
	 * The next-run line is how a cron that silently stopped firing gets
	 * noticed — without it, "no notices" and "no evaluation" look identical.
	 *
	 * @param int $season_id Season term id.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_diagnostics( int $season_id ): void {
		$next  = wp_next_scheduled( SPLM_Discipline_Notice_Pass::HOOK );
		$counts = $season_id ? SPLM_Discipline_Notice_Database::counts_by_status( $season_id ) : array();

		echo '<h3>' . esc_html__( 'Evaluation', 'sportspress-league-manager' ) . '</h3>';
		echo '<table class="widefat" style="max-width:44em"><tbody>';

		printf(
			'<tr><td>%s</td><td>%s</td></tr>',
			esc_html__( 'Next scheduled run', 'sportspress-league-manager' ),
			esc_html( $next ? wp_date( 'Y-m-d H:i:s', $next ) : __( 'Not scheduled', 'sportspress-league-manager' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Warning notices', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice::mode_for( 'warn' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Suspension notices', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice::mode_for( 'suspend' ) )
		);
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Notice table', 'sportspress-league-manager' ),
			esc_html( SPLM_Discipline_Notice_Database::table_exists() ? SPLM_Discipline_Notice_Database::table_name() : __( 'missing', 'sportspress-league-manager' ) )
		);

		$summary = array();
		foreach ( SPLM_Discipline_Notice_Database::STATUSES as $status ) {
			$summary[] = $status . ': ' . (int) ( $counts[ $status ] ?? 0 );
		}
		printf(
			'<tr><td>%s</td><td><code>%s</code></td></tr>',
			esc_html__( 'Rows by status', 'sportspress-league-manager' ),
			esc_html( implode( '  ', $summary ) )
		);

		echo '</tbody></table>';
	}

	/**
	 * The full row table.
	 *
	 * @param int    $season_id Season term id.
	 * @param string $status    Status filter, or '' for all.
	 * @param bool   $readonly  Whether to suppress action controls.
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 */
	private function render_table( int $season_id, string $status, bool $readonly ): void {
		$result = SPLM_Discipline_Notice_Database::query(
			array(
				'season' => $season_id,
				'status' => $status,
			),
			1,
			self::PER_PAGE
		);

		echo '<h3>' . esc_html__( 'Notices', 'sportspress-league-manager' ) . '</h3>';

		if ( ! $result['rows'] ) {
			echo '<p>' . esc_html__( 'No notices match this filter.', 'sportspress-league-manager' ) . '</p>';
			return;
		}

		$headings = array(
			__( 'ID', 'sportspress-league-manager' ),
			__( 'Player', 'sportspress-league-manager' ),
			__( 'Team / division', 'sportspress-league-manager' ),
			__( 'Season', 'sportspress-league-manager' ),
			__( 'Tier / ack key', 'sportspress-league-manager' ),
			__( 'Consequence', 'sportspress-league-manager' ),
			__( 'PIM (fire / season)', 'sportspress-league-manager' ),
			__( 'Status', 'sportspress-league-manager' ),
			__( 'Recipient (via)', 'sportspress-league-manager' ),
			__( 'Bcc', 'sportspress-league-manager' ),
			__( 'Sent', 'sportspress-league-manager' ),
			__( 'Released by', 'sportspress-league-manager' ),
			__( 'Last error', 'sportspress-league-manager' ),
			__( 'Created', 'sportspress-league-manager' ),
			__( 'Actions', 'sportspress-league-manager' ),
		);

		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headings as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';

		$actionable = 0;
		foreach ( $result['rows'] as $raw ) {
			$row = SPLM_Discipline_Notice_REST::row_to_response( $raw );
			if ( in_array( $row['status'], array( 'pending', 'failed' ), true ) ) {
				++$actionable;
			}
			$this->render_row( $row, $readonly );
		}

		echo '</tbody></table>';

		// Bulk controls issue the per-notice REST calls in sequence, so the
		// server contract stays single-item and idempotent.
		if ( ! $readonly && $actionable ) {
			printf(
				'<p><button type="button" class="button button-primary splm-notice-bulk" data-action="release">%1$s</button> '
					. '<button type="button" class="button splm-notice-bulk" data-action="discard">%2$s</button> '
					. '<span class="description">%3$s</span></p>',
				esc_html__( 'Release all shown', 'sportspress-league-manager' ),
				esc_html__( 'Discard all shown', 'sportspress-league-manager' ),
				esc_html(
					sprintf(
						/* translators: %d: number of actionable notices. */
						_n( '%d notice can be acted on.', '%d notices can be acted on.', $actionable, 'sportspress-league-manager' ),
						$actionable
					)
				)
			);
		}

		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: rows shown, 2: total rows. */
					__( 'Showing %1$d of %2$d rows, newest first.', 'sportspress-league-manager' ),
					count( $result['rows'] ),
					(int) $result['total']
				)
			)
		);
	}

	/**
	 * One table row.
	 *
	 * Cells are assembled into an array and imploded rather than passed to a
	 * twenty-placeholder printf, which is unreadable and trivially easy to
	 * mis-order. Each cell escapes its own content.
	 *
	 * @param array $row      Response-shaped row.
	 * @param bool  $readonly Whether to suppress action controls.
	 * @return void
	 *
	 * Fifteen cells each with an em-dash fallback is inherently a wide NPath.
	 * The alternative — a helper per cell — would scatter one table's markup
	 * across fifteen methods and trip TooManyMethods instead, which is the
	 * documented tension in
	 * docs/superpowers/plans/2026-09-02-registration-waitlist-followups.md.
	 *
	 * @SuppressWarnings(PHPMD.NPathComplexity)
	 */
	private function render_row( array $row, bool $readonly ): void {
		$em = '—';

		$cells = array(
			(string) (int) $row['id'],
			esc_html( $row['player'] ) . ' <code>#' . (int) $row['player_id'] . '</code>',
			esc_html( $row['team'] ? $row['team'] : $em )
				. '<br/><span class="description">' . esc_html( $row['division'] ? $row['division'] : $em ) . '</span>',
			'<code>' . (int) $row['season_id'] . '</code>',
			'<code>' . esc_html( $row['tier_key'] ) . '</code><br/><code>' . esc_html( $row['ack_key'] ) . '</code>',
			esc_html( $row['consequence'] ) . ( $row['games'] ? esc_html( ' (' . (int) $row['games'] . ')' ) : '' ),
			(int) $row['value_at_fire'] . ' / ' . (int) $row['season_at_fire'],
			'<code>' . esc_html( $row['status'] ) . '</code>',
			esc_html( $row['recipient'] ? $row['recipient'] : $em )
				. '<br/><code>' . esc_html( $row['recipient_via'] ) . '</code>',
			'<code>' . esc_html( $row['bcc'] ? $row['bcc'] : $em ) . '</code>',
			esc_html( $row['sent_at'] ? $row['sent_at'] : $em ),
			esc_html( $this->released_by_label( $row ) ),
			esc_html( $row['last_error'] ? $row['last_error'] : $em ),
			esc_html( $row['created_at'] ),
			$readonly ? '' : $this->row_buttons( $row ),
		);

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every cell escapes its own content above.
		echo '<tr><td>' . implode( '</td><td>', $cells ) . '</td></tr>';
	}

	/**
	 * Who released a notice.
	 *
	 * A zero released_by means "automatic" only on a row that actually sent;
	 * on a baseline row it means nothing happened.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	private function released_by_label( array $row ): string {
		if ( $row['released_by'] ) {
			return (string) (int) $row['released_by'];
		}

		return 'sent' === $row['status']
			? __( 'automatic', 'sportspress-league-manager' )
			: '—';
	}

	/**
	 * The action buttons for one row.
	 *
	 * @param array $row Response-shaped row.
	 * @return string
	 */
	private function row_buttons( array $row ): string {
		$buttons = '';

		if ( in_array( $row['status'], array( 'pending', 'failed' ), true ) ) {
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="release" data-id="%d">%s</button> ',
				(int) $row['id'],
				esc_html__( 'Release', 'sportspress-league-manager' )
			);
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="discard" data-id="%d">%s</button>',
				(int) $row['id'],
				esc_html__( 'Discard', 'sportspress-league-manager' )
			);
		}

		if ( 'sent' === $row['status'] && 'suspend' === $row['consequence'] ) {
			$buttons .= sprintf(
				'<button type="button" class="button splm-notice-action" data-action="serve" data-id="%d">%s</button>',
				(int) $row['id'],
				esc_html__( 'Mark served', 'sportspress-league-manager' )
			);
		}

		return $buttons;
	}

	/**
	 * The action layer.
	 *
	 * Calls the same REST routes the React page uses. Kept inline because it is
	 * a dozen lines and this tab is the only consumer; shipping a file for it
	 * would need an enqueue path on a settings page that has none.
	 *
	 * @return void
	 */
	private function render_script(): void {
		$nonce = wp_create_nonce( 'wp_rest' );
		$base  = rest_url( 'splm/v1/discipline/notices/' );
		?>
		<script>
		( function () {
			var base = <?php echo wp_json_encode( $base ); ?>;
			var nonce = <?php echo wp_json_encode( $nonce ); ?>;

			function act( id, action ) {
				return fetch( base + id + '/' + action, {
					method: 'POST',
					headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin'
				} ).then( function ( response ) {
					return response.json().then( function ( body ) {
						return { ok: response.ok, body: body };
					} );
				} );
			}

			document.querySelectorAll( '.splm-notice-action' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					button.disabled = true;
					act( button.getAttribute( 'data-id' ), button.getAttribute( 'data-action' ) )
						.then( function ( result ) {
							if ( result.ok ) {
								window.location.reload();
								return;
							}
							button.disabled = false;
							window.alert( ( result.body && result.body.message ) || 'The action failed.' );
						} )
						.catch( function () {
							button.disabled = false;
							window.alert( 'The action could not be sent.' );
						} );
				} );
			} );

			// Bulk issues the same per-notice calls in sequence, so the server
			// contract stays single-item and each one keeps its own lock.
			// Sequential rather than parallel: releasing sends mail, and a
			// burst of concurrent sends is how a host's mail limit gets hit.
			document.querySelectorAll( '.splm-notice-bulk' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var action = button.getAttribute( 'data-action' );
					var ids = Array.prototype.map.call(
						document.querySelectorAll( '.splm-notice-action[data-action="' + action + '"]' ),
						function ( el ) { return el.getAttribute( 'data-id' ); }
					);
					if ( ! ids.length || ! window.confirm( action + ' ' + ids.length + ' notice(s)?' ) ) {
						return;
					}
					button.disabled = true;
					var failures = 0;
					ids.reduce( function ( chain, id ) {
						return chain.then( function () {
							return act( id, action ).then( function ( result ) {
								if ( ! result.ok ) { failures++; }
							} ).catch( function () { failures++; } );
						} );
					}, Promise.resolve() ).then( function () {
						if ( failures ) {
							window.alert( failures + ' of ' + ids.length + ' could not be processed. Reloading to show current state.' );
						}
						window.location.reload();
					} );
				} );
			} );
		}() );
		</script>
		<?php
	}
}
