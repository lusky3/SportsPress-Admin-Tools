<?php
/**
 * Bench database schema.
 *
 * Four custom tables, created and upgraded with dbDelta() and versioned by the
 * `spbn_db_version` option. Every datetime column holds UTC (gmdate()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPBN_Schema {

	const DB_VERSION        = '1';
	const DB_VERSION_OPTION = 'spbn_db_version';

	/**
	 * Logical name => full table name, in creation order.
	 *
	 * @return array<string,string>
	 */
	public static function tables(): array {
		global $wpdb;
		$out = array();
		foreach ( array( 'availability', 'spare_calls', 'spare_responses', 'changes' ) as $name ) {
			$out[ $name ] = $wpdb->prefix . 'spbn_' . $name;
		}
		return $out;
	}

	/**
	 * dbDelta-formatted CREATE TABLE statements, one per table.
	 *
	 * dbDelta is picky: one field per line, two spaces before the PRIMARY KEY
	 * column list, and KEY definitions named.
	 *
	 * @param string $prefix  Table prefix (e.g. `wp_`).
	 * @param string $charset Charset/collate clause from $wpdb->get_charset_collate().
	 * @return string[]
	 */
	public static function create_sql( string $prefix, string $charset ): array {
		$availability = "CREATE TABLE {$prefix}spbn_availability (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL,
	player_id bigint(20) unsigned NOT NULL,
	team_id bigint(20) unsigned NOT NULL,
	status varchar(8) NOT NULL,
	note varchar(140) NOT NULL DEFAULT '',
	updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY event_player (event_id,player_id),
	KEY player_id (player_id)
) {$charset};";

		$spare_calls = "CREATE TABLE {$prefix}spbn_spare_calls (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL,
	team_id bigint(20) unsigned NOT NULL,
	position varchar(8) NOT NULL,
	needed tinyint(3) unsigned NOT NULL DEFAULT 1,
	note varchar(140) NOT NULL DEFAULT '',
	status varchar(10) NOT NULL DEFAULT 'open',
	created_by bigint(20) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY event_id (event_id),
	KEY status (status)
) {$charset};";

		$spare_responses = "CREATE TABLE {$prefix}spbn_spare_responses (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	call_id bigint(20) unsigned NOT NULL,
	user_id bigint(20) unsigned NOT NULL,
	player_id bigint(20) unsigned NOT NULL,
	status varchar(10) NOT NULL DEFAULT 'accepted',
	created_at datetime NOT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY call_user (call_id,user_id)
) {$charset};";

		$changes = "CREATE TABLE {$prefix}spbn_changes (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	event_id bigint(20) unsigned NOT NULL,
	changed_at datetime NOT NULL,
	before_json longtext NOT NULL,
	after_json longtext NOT NULL,
	PRIMARY KEY  (id),
	KEY event_changed (event_id,changed_at)
) {$charset};";

		return array( $availability, $spare_calls, $spare_responses, $changes );
	}

	/**
	 * Create or upgrade the tables when the stored version is behind. Cheap
	 * no-op once the versions match.
	 */
	public static function maybe_upgrade(): void {
		global $wpdb;

		if ( (string) get_option( self::DB_VERSION_OPTION, '' ) === self::DB_VERSION ) {
			return;
		}

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		foreach ( self::create_sql( $wpdb->prefix, $wpdb->get_charset_collate() ) as $statement ) {
			dbDelta( $statement );
		}

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}
}
