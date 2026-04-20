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

// Require authentication + manage_sportspress capability.
if ( ! is_user_logged_in() || ! current_user_can( 'manage_sportspress' ) ) {
	wp_redirect( wp_login_url( get_permalink() ) );
	exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
	<title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — League Dashboard</title>
	<?php wp_head(); ?>
</head>
<body class="splm-dashboard-body">
	<div id="splm-dashboard-root"></div>
	<?php wp_footer(); ?>
</body>
</html>
