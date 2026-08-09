<?php
/**
 * Uninstall cleanup, gated by the parent's data-removal opt-in
 * (spat_remove_data_on_uninstall), consistent with the rest of the suite.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

if ( '1' !== (string) get_option( 'spat_remove_data_on_uninstall', '0' ) ) {
	return;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/class-database.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-image-store.php';

// Delete stored images, then the storage directory. glob('*') skips dotfiles, so
// the protect_dir() .htaccess survived and rmdir() could never succeed — leaving
// the directory (and its deny rules) behind forever. Sweep the dotfiles too.
$dir = SPSS_Image_Store::dir();
if ( is_dir( $dir ) ) {
	$entries = array_merge(
		(array) glob( trailingslashit( $dir ) . '*' ),
		(array) glob( trailingslashit( $dir ) . '.[!.]*' )
	);
	foreach ( $entries as $file ) {
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
	// Best-effort remove the now-empty directory.
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

SPSS_Database::drop_tables();

delete_option( 'spss_db_version' );
delete_option( 'spss_primary_chain' );
delete_option( 'spss_confirmation_providers' );
delete_option( 'spss_spend_ledger' );
// Keep this provider-id list in sync with the built-ins registered in
// SPSS_Recognition_Manager. (Uninstall runs without the plugin loaded, so it
// can't enumerate the manager; filter-registered third-party providers must
// clean up their own spss_<id>_* options on their own uninstall.)
foreach ( array( 'claude', 'gemini', 'openai', 'openrouter', 'selfhosted' ) as $pid ) {
	delete_option( "spss_{$pid}_monthly_budget" );
	delete_option( "spss_{$pid}_cost_per_sheet" );
}
delete_option( 'spss_claude_api_key' );
delete_option( 'spss_claude_model' );
delete_option( 'spss_gemini_api_key' );
delete_option( 'spss_gemini_model' );
delete_option( 'spss_openai_api_key' );
delete_option( 'spss_openai_model' );
delete_option( 'spss_openrouter_api_key' );
delete_option( 'spss_openrouter_model' );
delete_option( 'spss_openrouter_base_url' );
delete_option( 'spss_selfhosted_endpoint' );
delete_option( 'spss_selfhosted_model' );
delete_option( 'spss_selfhosted_key' );
delete_option( 'spss_retention_days' );
delete_option( 'spss_webhook_secret' );
delete_option( 'spss_twilio_account_sid' );
delete_option( 'spss_twilio_auth_token' );
delete_option( 'spss_whatsapp_app_secret' );
delete_option( 'spss_whatsapp_access_token' );
delete_option( 'spss_whatsapp_verify_token' );
delete_option( 'spss_whatsapp_graph_version' );

wp_clear_scheduled_hook( 'spss_cleanup_old_sheets' );
