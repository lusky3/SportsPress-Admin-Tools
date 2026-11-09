<?php
/**
 * Admin notices of the FreeScout Bridge bootstrap.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SPFB_Notices {

	/**
	 * Shown while SportsPress Admin Tools older than 1.3.0 still ships the bridge.
	 */
	public static function dormant(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::render( 'notice-info', __( 'FreeScout Bridge is installed and will take over when SportsPress Admin Tools is updated to 1.3.0.', 'sportspress-freescout-bridge' ) );
	}

	public static function parent_missing(): void {
		self::render( 'notice-error', __( 'SportsPress FreeScout Bridge requires SportsPress Admin Tools to be installed and activated.', 'sportspress-freescout-bridge' ) );
	}

	public static function parent_outdated(): void {
		self::render( 'notice-error', __( 'SportsPress FreeScout Bridge requires a newer version of SportsPress Admin Tools. Please update the parent plugin.', 'sportspress-freescout-bridge' ) );
	}

	private static function render( string $class, string $message ): void {
		echo '<div class="notice ' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
	}
}
