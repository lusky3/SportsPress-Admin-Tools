<?php
/**
 * Admin AJAX Handlers
 *
 * Handles all AJAX requests for the Schedule Generator admin interface.
 * Extracted from SPSG_Admin to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * AJAX handler class for Schedule Generator
 */
class SPSG_Admin_Ajax
{

    /**
     * Duplicated string constants
     */
    const MSG_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    const MSG_NO_CHANGES = 'No changes recorded yet';

    /**
     * Configuration manager instance
     */
    private $config_manager;

    /**
     * Constructor
     *
     * @param SPSG_Configuration_Manager $config_manager Configuration manager instance
     */
    public function __construct($config_manager)
    {
        $this->config_manager = $config_manager;
        $this->register_ajax_handlers();
    }

    /**
     * Register all AJAX action hooks
     */
    private function register_ajax_handlers()
    {
        add_action('wp_ajax_spsg_save_config', array($this, 'ajax_save_config'));
        add_action('wp_ajax_spsg_load_config', array($this, 'ajax_load_config'));
        add_action('wp_ajax_spsg_validate_config', array($this, 'ajax_validate_config'));
        add_action('wp_ajax_spsg_import_league', array($this, 'ajax_import_league'));
        add_action('wp_ajax_spsg_save_imported_league', array($this, 'ajax_save_imported_league'));
