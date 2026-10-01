<?php
/**
 * The convener-editable list of infractions a suspension can cite.
 *
 * Seeded from the ARL Rule Book (Rev 20241009) §5.10 suspension chart. The
 * chart is a set of MINIMUM guidelines the convener may adjust, so the seed is
 * a starting point stored in a table, not a constant: a rulebook revision is a
 * settings edit, not a deploy. Notices snapshot the text they cite, so editing
 * this list never rewrites what a player was told.
 *
 * Removal-from-league outcomes (second major, second fighting) are
 * deliberately NOT seeded — that is a registration/refund matter.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Discipline_Infraction {

	const OUTCOME_GAMES      = 'games';
	const OUTCOME_INDEFINITE = 'indefinite';
	const MAX_GAMES          = 20;
	const SEEDED_OPTION      = 'splm_discipline_infractions_seeded';

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'splm_discipline_infraction';
	}

	/**
	 * The rule book §5.10 chart as seed rows.
	 *
	 * The rule_ref field holds the rulebook section the wording comes from; entries whose
	 * section number was not stated in the chart itself carry '' and are
	 * confirmed against the PDF by the convener on first edit.
	 *
	 * @return array[]
	 */
	public static function seed_rows(): array {
		$rows = array(
			array( '6.13', 'Game Ejection', 'Game Ejection — suspended for the balance of the game.', self::OUTCOME_GAMES, 0, 0 ),
			array( '6.11', 'Game Misconduct (1st period)', 'Game Misconduct in the first period — balance of the game, and reviewed by the League Convenor.', self::OUTCOME_GAMES, 0, 1 ),
			array( '6.11', 'Game Misconduct (2nd or 3rd period)', 'Game Misconduct in the second or third period — minimum 1 game suspension.', self::OUTCOME_GAMES, 1, 0 ),
			array( '', 'Major Penalty (first offence)', 'Major Penalty (first offence) — minimum 2 game suspension, reviewed by the League Convenor.', self::OUTCOME_GAMES, 2, 1 ),
			array( '6.14', 'Gross Misconduct', 'Gross Misconduct — suspended indefinitely upon review by the League Convenor.', self::OUTCOME_INDEFINITE, 0, 1 ),
			array( '', 'Match Penalty', 'Match Penalty — suspended indefinitely upon review by the League Convenor.', self::OUTCOME_INDEFINITE, 0, 1 ),
			array( '6.5', 'Fighting (first offence)', 'Fighting (first offence) — minimum 3 game suspension, reviewed by the League Convenor.', self::OUTCOME_GAMES, 3, 1 ),
		);

		$out = array();
		foreach ( $rows as $i => $r ) {
			$out[] = array(
				'rule_ref'      => $r[0],
				'title'         => $r[1],
				'rule_text'     => $r[2],
				'outcome'       => $r[3],
				'default_games' => $r[4],
				'needs_review'  => $r[5],
				'active'        => 1,
				'sort_order'    => ( $i + 1 ) * 10,
			);
		}
		return $out;
	}

	/**
	 * Sanitise one infraction row.
	 *
	 * Untyped ($raw) on purpose: options.php and form posts can hand a callback
	 * null when a field is absent, and a hard array type hint would fatal.
	 *
	 * @param mixed $raw Raw row.
	 * @return array Clean row; title is '' when nothing usable was given.
	 */
	public static function sanitize_row( $raw ): array {
		$raw     = is_array( $raw ) ? $raw : array();
		$outcome = ( $raw['outcome'] ?? '' ) === self::OUTCOME_INDEFINITE ? self::OUTCOME_INDEFINITE : self::OUTCOME_GAMES;
		$games   = min( self::MAX_GAMES, absint( $raw['default_games'] ?? 0 ) );

		return array(
			'rule_ref'      => substr( sanitize_text_field( (string) ( $raw['rule_ref'] ?? '' ) ), 0, 20 ),
			'title'         => substr( sanitize_text_field( (string) ( $raw['title'] ?? '' ) ), 0, 120 ),
			'rule_text'     => sanitize_textarea_field( (string) ( $raw['rule_text'] ?? '' ) ),
			'outcome'       => $outcome,
			'default_games' => self::OUTCOME_INDEFINITE === $outcome ? 0 : $games,
			'needs_review'  => empty( $raw['needs_review'] ) ? 0 : 1,
			'active'        => array_key_exists( 'active', $raw ) && empty( $raw['active'] ) ? 0 : 1,
			'sort_order'    => (int) ( $raw['sort_order'] ?? 0 ),
		);
	}

	/**
	 * Create the table (dbDelta), verifying it exists rather than trusting
	 * dbDelta's return value.
	 *
	 * @return bool
	 */
	public static function create_table(): bool {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			rule_ref varchar(20) NOT NULL DEFAULT '',
			title varchar(120) NOT NULL,
			rule_text text NULL,
			outcome varchar(20) NOT NULL DEFAULT 'games',
			default_games smallint(5) unsigned NOT NULL DEFAULT 0,
			needs_review tinyint(1) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort_order smallint(6) NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY active_sort (active, sort_order)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; // phpcs:ignore WordPress.DB
	}

	/**
	 * Seed the chart once. Idempotent: gated on an option, so a convener who
	 * deletes or edits a seeded row is never overwritten on a later upgrade.
	 *
	 * @return int Rows inserted (0 when already seeded).
	 */
	public static function seed_if_empty(): int {
		global $wpdb;

		if ( get_option( self::SEEDED_OPTION ) ) {
			return 0;
		}

		$count = 0;
		foreach ( self::seed_rows() as $row ) {
			if ( false !== $wpdb->insert( self::table_name(), $row ) ) { // phpcs:ignore WordPress.DB
				++$count;
			}
		}

		if ( $count > 0 ) {
			update_option( self::SEEDED_OPTION, 1 );
		}
		return $count;
	}

	/**
	 * All infractions, ordered.
	 *
	 * @param bool $active_only Only rows convener has not retired.
	 * @return object[]
	 */
	public static function all( bool $active_only = true ): array {
		global $wpdb;
		$table = self::table_name();
		$where = $active_only ? 'WHERE active = 1' : '';
		return (array) $wpdb->get_results( "SELECT * FROM {$table} {$where} ORDER BY sort_order ASC, id ASC" ); // phpcs:ignore WordPress.DB
	}

	/**
	 * One infraction by id.
	 *
	 * @param int $id Row id.
	 * @return object|null
	 */
	public static function find( int $id ) {
		global $wpdb;
		$table = self::table_name();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB
	}
}
