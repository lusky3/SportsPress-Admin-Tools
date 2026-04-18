<?php
/**
 * Player Notes Database Operations
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPLM_Player_Notes_Database {

	/**
	 * Get the table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'splm_player_notes';
	}

	/**
	 * Create the notes table via dbDelta.
	 */
	public static function create_table() {
		global $wpdb;
		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			player_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NOT NULL,
			category varchar(50) DEFAULT 'general',
			note text NOT NULL,
			is_deleted tinyint(1) DEFAULT 0,
			created_at datetime DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime DEFAULT NULL,
			PRIMARY KEY (id),
			KEY player_id (player_id),
			KEY author_id (author_id),
			KEY category (category),
			KEY created_at (created_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Get notes for a player.
	 *
	 * @param int $player_id Player post ID.
	 * @return array
	 */
	public static function get_notes( $player_id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name AS author_name
				 FROM {$table} n
				 LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
				 WHERE n.player_id = %d AND n.is_deleted = 0
				 ORDER BY n.created_at DESC",
				$player_id
			)
		);
	}

	/**
	 * Get a single note by ID.
	 *
	 * @param int $note_id Note ID.
	 * @return object|null
	 */
	public static function get_note( $note_id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE id = %d AND is_deleted = 0",
				$note_id
			)
		);
	}

	/**
	 * Insert a new note.
	 *
	 * @param int    $player_id Player post ID.
	 * @param int    $author_id User ID.
	 * @param string $note      Note text.
	 * @param string $category  Category tag.
	 * @return int|false Inserted ID or false.
	 */
	public static function insert( $player_id, $author_id, $note, $category = 'general' ) {
		global $wpdb;

		$result = $wpdb->insert(
			self::table_name(),
			array(
				'player_id' => $player_id,
				'author_id' => $author_id,
				'note'      => $note,
				'category'  => $category,
			),
			array( '%d', '%d', '%s', '%s' )
		);

		return $result ? $wpdb->insert_id : false;
	}

	/**
	 * Update a note's text.
	 *
	 * @param int    $note_id Note ID.
	 * @param string $note    New text.
	 * @return bool
	 */
	public static function update( $note_id, $note ) {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table_name(),
			array(
				'note'       => $note,
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $note_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Soft-delete a note.
	 *
	 * @param int $note_id Note ID.
	 * @return bool
	 */
	public static function soft_delete( $note_id ) {
		global $wpdb;

		return (bool) $wpdb->update(
			self::table_name(),
			array( 'is_deleted' => 1 ),
			array( 'id' => $note_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Get recent notes across all players.
	 *
	 * @param int $limit Number of notes.
	 * @return array
	 */
	public static function get_recent( $limit = 10 ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.*, u.display_name AS author_name, p.post_title AS player_name
				 FROM {$table} n
				 LEFT JOIN {$wpdb->users} u ON u.ID = n.author_id
				 LEFT JOIN {$wpdb->posts} p ON p.ID = n.player_id
				 WHERE n.is_deleted = 0
				 ORDER BY n.created_at DESC
				 LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Count notes for a player.
	 *
	 * @param int $player_id Player post ID.
	 * @return int
	 */
	public static function count_for_player( $player_id ) {
		global $wpdb;
		$table = self::table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE player_id = %d AND is_deleted = 0",
				$player_id
			)
		);
	}

	/**
	 * Drop the table (for uninstall).
	 */
	public static function drop_table() {
		global $wpdb;
		$table = self::table_name();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
