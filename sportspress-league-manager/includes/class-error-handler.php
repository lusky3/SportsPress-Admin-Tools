<?php
/**
 * Error Handler for League Manager
 *
 * Formats WP_Error objects for display and AJAX responses.
 *
 * @author Cody (lusky3)
 */

if ( ! defined( 'ABSPATH' ) ) {
	wp_die();
}

class SPLM_Error_Handler {

	/**
	 * Format a WP_Error for HTML display.
	 *
	 * @param WP_Error $error WordPress error object.
	 * @return string Escaped HTML notice.
	 */
	public static function format_for_display( WP_Error $error ): string {
		$code        = $error->get_error_code();
		$message     = $error->get_error_message();
		$suggestions = self::get_suggestions( $code );

		$html  = '<div class="notice notice-error splm-error">';
		$html .= '<p><strong>' . esc_html( $message ) . '</strong></p>';

		if ( ! empty( $suggestions ) ) {
			$html .= '<ul>';
			foreach ( $suggestions as $suggestion ) {
				$html .= '<li>' . esc_html( $suggestion ) . '</li>';
			}
			$html .= '</ul>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Format a WP_Error for AJAX JSON response.
	 *
	 * @param WP_Error $error WordPress error object.
	 * @return array Structured data for wp_send_json_error.
	 */
	public static function format_for_ajax( WP_Error $error ): array {
		return array(
			'success' => false,
			'data'    => array(
				'message'     => $error->get_error_message(),
				'code'        => $error->get_error_code(),
				'suggestions' => self::get_suggestions( $error->get_error_code() ),
			),
		);
	}

	/**
	 * Log a message when debug logging is enabled.
	 *
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function log( string $message, array $context = array() ): void {
		if ( get_option( 'splm_debug_logging', '0' ) !== '1' ) {
			return;
		}

		if ( get_option( 'spat_debug_verbose_logging', '0' ) === '1' ) {
			error_log( 'SPLM: ' . $message . ( ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '' ) );
		}
	}

	/**
	 * Get user-friendly suggestions for an error code.
	 *
	 * @param string $code Error code.
	 * @return string[]
	 */
	private static function get_suggestions( string $code ): array {
		$map = array(
			'sportspress_inactive' => array(
				__( 'Contact your site administrator to activate SportsPress.', 'sportspress-league-manager' ),
			),
			'no_teams'             => array(
				__( 'Create teams in SportsPress before using League Manager.', 'sportspress-league-manager' ),
			),
			'no_leagues'           => array(
				__( 'Ask an admin to create at least one league in SportsPress.', 'sportspress-league-manager' ),
			),
			'permission_denied'    => array(
				__( 'You need the manage_league capability. Contact your administrator.', 'sportspress-league-manager' ),
			),
			'upload_failed'        => array(
				__( 'Check that the file is a valid CSV and within the size limit.', 'sportspress-league-manager' ),
			),
		);

		return $map[ $code ] ?? array();
	}
}
