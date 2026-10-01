<?php
/**
 * Read-only discipline record on the sp_player edit screen.
 *
 * Shows a player's notices to conveners beside the Player Notes box. The
 * private incident note is deliberately included: this box is gated by the
 * same capability as the dashboard's convener-only views. Nothing here writes.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Player_Metabox {

	const BOX_ID = 'splm_discipline_record';

	public function __construct() {
		add_action( 'add_meta_boxes_sp_player', array( $this, 'add_meta_box' ) );
	}

	/**
	 * Whether the current user may see the box.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return SPLM_Capabilities::can_manage()
			&& in_array( 'league_discipline', (array) get_option( 'spat_enabled_modules', array() ), true );
	}

	/**
	 * Register the box (same context and priority as Player Notes).
	 *
	 * @return void
	 */
	public function add_meta_box(): void {
		if ( ! $this->is_available() ) {
			return;
		}

		add_meta_box(
			self::BOX_ID,
			__( 'Discipline record (conveners only)', 'sportspress-league-manager' ),
			array( $this, 'render_meta_box' ),
			'sp_player',
			'normal',
			'default'
		);
	}

	/**
	 * Render the box.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param WP_Post $post The player.
	 * @return void
	 */
	public function render_meta_box( $post ): void {
		if ( ! $this->is_available() ) {
			return;
		}

		$rows     = SPLM_Discipline_Notice_Database::for_player( (int) $post->ID, false );
		$replaced = SPLM_Discipline_Notice_Database::replaced_ids( array_map( static fn( $row ) => (int) $row->id, $rows ) );

		echo '<style>.splm-disc-replaced{opacity:.55}.splm-disc-note td{padding-top:0}</style>';
		echo '<p>' . esc_html( SPLM_Discipline_Suspension::summary_line( $rows ) ) . '</p>';
		if ( $rows ) {
			echo self::render_rows_html( $rows, $replaced ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped inside.
		}
		self::echo_dashboard_link();
	}

	/**
	 * Link to the dashboard page when one is already provisioned. Read-only:
	 * SPLM_Dashboard_Frontend::ensure_page() would create the page.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @return void
	 */
	private static function echo_dashboard_link(): void {
		$page_id = (int) get_option( SPLM_Dashboard_Frontend::PAGE_OPT, 0 );
		$url     = $page_id > 0 ? get_permalink( $page_id ) : '';
		if ( ! $url ) {
			return;
		}

		echo '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Manage in the dashboard', 'sportspress-league-manager' ) . '</a></p>';
	}

	/**
	 * The notices table. Pure apart from the site's date settings.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object[] $rows         Notice rows, newest first.
	 * @param int[]    $replaced_ids Ids of rows a later notice replaced.
	 * @return string HTML, escaped.
	 */
	public static function render_rows_html( array $rows, array $replaced_ids = array() ): string {
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'No disciplinary record.', 'sportspress-league-manager' ) . '</p>';
		}

		$head = '';
		foreach ( array( __( 'Date', 'sportspress-league-manager' ), __( 'Consequence', 'sportspress-league-manager' ), __( 'Infraction', 'sportspress-league-manager' ), __( 'Status', 'sportspress-league-manager' ), __( 'Eligible', 'sportspress-league-manager' ) ) as $label ) {
			$head .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}

		$body = '';
		foreach ( $rows as $row ) {
			$body .= self::row_html( $row, in_array( (int) $row->id, $replaced_ids, true ) );
		}

		return '<table class="widefat striped"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table>';
	}

	/**
	 * One notice: its cells, plus a Private note line when there is a note.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess)
	 *
	 * @param object $row      Notice row.
	 * @param bool   $replaced Whether a later notice replaced it.
	 * @return string
	 */
	private static function row_html( $row, bool $replaced ): string {
		$item   = SPLM_Discipline_Notice_REST::row_to_response( $row, true );
		$status = self::status_label( $item['status'] );
		if ( $replaced ) {
			/* translators: %s: notice status, e.g. "Sent". */
			$status = sprintf( __( '%s (Replaced)', 'sportspress-league-manager' ), $status );
		}

		$cells = array(
			self::local_date( '' !== $item['sent_at'] ? $item['sent_at'] : $item['created_at'] ),
			SPLM_Discipline_Notice_Labels::consequence_text( $item ),
			SPLM_Discipline_Notice_Labels::infraction_text( $item ),
			$status,
			self::eligible_date( $item['eligible_on'] ),
		);

		$html = '<tr' . ( $replaced ? ' class="splm-disc-replaced"' : '' ) . '>';
		foreach ( $cells as $cell ) {
			$html .= '<td>' . esc_html( $cell ) . '</td>';
		}
		$html .= '</tr>';

		if ( '' !== ( $item['incident_note'] ?? '' ) ) {
			$html .= '<tr class="splm-disc-note' . ( $replaced ? ' splm-disc-replaced' : '' ) . '"><td colspan="5"><strong>' . esc_html__( 'Private note', 'sportspress-league-manager' ) . ':</strong> ' . esc_html( $item['incident_note'] ) . '</td></tr>';
		}

		return $html;
	}

	/**
	 * Plain label for a status word; an unknown word is shown as it is.
	 *
	 * @param string $status Raw status.
	 * @return string
	 */
	private static function status_label( string $status ): string {
		$labels = array(
			'pending'   => __( 'Waiting for release', 'sportspress-league-manager' ),
			'sent'      => __( 'Sent', 'sportspress-league-manager' ),
			'failed'    => __( 'Could not send', 'sportspress-league-manager' ),
			'discarded' => __( 'Discarded', 'sportspress-league-manager' ),
			'served'    => __( 'Served', 'sportspress-league-manager' ),
			'revoked'   => __( 'Withdrawn', 'sportspress-league-manager' ),
			'baseline'  => __( 'On record', 'sportspress-league-manager' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * A stored UTC datetime as a site-local date in the site's date format.
	 *
	 * @param string $utc UTC 'Y-m-d H:i:s'.
	 * @return string
	 */
	private static function local_date( string $utc ): string {
		if ( '' === $utc ) {
			return '—';
		}

		return mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), get_date_from_gmt( $utc ) );
	}

	/**
	 * The eligible-on DATE as a plain date. It carries no time zone, so it is
	 * formatted as stored rather than shifted.
	 *
	 * @param string $date 'Y-m-d' or ''.
	 * @return string
	 */
	private static function eligible_date( string $date ): string {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}/', $date ) ) {
			return '—';
		}

		return mysql2date( (string) get_option( 'date_format', 'Y-m-d' ), substr( $date, 0, 10 ) . ' 00:00:00' );
	}
}
