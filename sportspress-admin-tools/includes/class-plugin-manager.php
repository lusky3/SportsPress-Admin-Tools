<?php
/**
 * Plugin Manager for Child Plugins
 *
 * @author Cody (lusky3)
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPAT_Plugin_Manager {

	/**
	 * @var array<string, array<string, mixed>>
	 */
	private static array $registered_plugins = array();

	public static function register_plugin( $plugin_id, $plugin_data ): bool {
		$defaults = array(
			'name'          => '',
			'description'   => '',
			'parent_module' => '',
			'version'       => '0.0.0',
			'file'          => '',
		);

		if ( ! is_array( $plugin_data ) ) {
			_doing_it_wrong( __METHOD__, 'register_plugin requires an array of plugin data.', '1.0.0' );
			return false;
		}

		foreach ( array( 'name', 'parent_module', 'file' ) as $required_key ) {
			if ( empty( $plugin_data[ $required_key ] ) ) {
				_doing_it_wrong( __METHOD__, sprintf( 'register_plugin missing required key "%s" for plugin "%s".', $required_key, (string) $plugin_id ), '1.0.0' );
				return false;
			}
		}

		$plugin_data = array_merge( $defaults, $plugin_data );

		if ( isset( self::$registered_plugins[ $plugin_id ] ) ) {
			_doing_it_wrong( __METHOD__, sprintf( 'register_plugin: duplicate plugin_id "%s"', (string) $plugin_id ), '1.0.0' );
			return false;
		}

		self::$registered_plugins[ $plugin_id ] = $plugin_data;

		// Note: child plugins should not depend on a parent-fired activation signal;
		// they perform their own setup on their own activation hook.

		return true;
	}

	public static function is_module_enabled( $module_id ): bool {
		$enabled_modules = (array) get_option( 'spat_enabled_modules', array() );
		return in_array( (string) $module_id, $enabled_modules, true );
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_registered_plugins(): array {
		return self::$registered_plugins;
	}

	public static function is_plugin_active( $plugin_id ): bool {
		return isset( self::$registered_plugins[ $plugin_id ] );
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_plugin_data( $plugin_id ): ?array {
		return isset( self::$registered_plugins[ $plugin_id ] ) ? self::$registered_plugins[ $plugin_id ] : null;
	}
}
