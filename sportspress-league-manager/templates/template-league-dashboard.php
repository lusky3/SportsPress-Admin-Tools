<?php
/**
 * Template Name: League Dashboard
 *
 * Renders the React-powered League Manager dashboard.
 * No WordPress admin chrome — standalone interface.
 *
 * @package SportsPress_League_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Require authentication + any SportsPress role (minimum: read_sp_event).
if ( ! is_user_logged_in() ) {
	wp_redirect( wp_login_url( get_permalink() ) );
	exit;
}
if ( ! current_user_can( 'manage_sportspress' ) && ! current_user_can( 'edit_others_sp_events' ) && ! current_user_can( 'edit_sp_events' ) ) {
	wp_redirect( home_url() );
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — League Dashboard</title>
	<link rel="manifest" href="<?php echo esc_url( SPLM_PLUGIN_URL . 'assets/manifest.json' ); ?>">
	<meta name="theme-color" content="#2563eb">
	<?php wp_head(); ?>
</head>
<body class="splm-dashboard-body">
	<div id="splm-dashboard-root"></div>
	<?php wp_footer(); ?>
</body>
</html>
