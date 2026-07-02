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

// Primary auth enforcement runs at template_redirect (see
// SPLM_Dashboard_Frontend::enforce_template_auth). This block is a
// belt-and-suspenders guard for any code path that bypasses that hook.
if ( ! is_user_logged_in() ) {
	wp_safe_redirect( wp_login_url( get_permalink() ) );
	exit;
}
if ( ! class_exists( 'SPLM_Capabilities' ) || ! SPLM_Capabilities::can_read() ) {
	wp_safe_redirect( home_url() );
	exit;
}
nocache_headers();
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
