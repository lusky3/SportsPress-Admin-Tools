<?php
/**
 * Admin Interface Coordinator
 *
 * Slim coordinator that delegates rendering to SPSG_Admin_Renderer
 * and AJAX handling to SPSG_Admin_Ajax. Handles menu registration,
 * script enqueueing, SPAT integration, and settings callbacks.
 *
 * Refactored from a 60-method monolith to reduce class size (S138).
 *
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Admin interface coordinator for Schedule Generator
 */
class SPSG_Admin
{

    /**
     * String constants shared across admin classes
     */
    const MSG_INSUFFICIENT_PERMISSIONS = 'Insufficient permissions';
    const MSG_NO_CHANGES = 'No changes recorded yet';
    const LABEL_SCHEDULE_GENERATOR = 'Schedule Generator';
    const LABEL_GENERATE_SCHEDULE = 'Generate Schedule';
    const LABEL_GAMES_PER_TEAM = 'Games Per Team';
    const LABEL_SAVE_CONFIGURATION = 'Save Configuration';
    const LABEL_IMPORT_LEAGUE = 'Import League Structure';
    const LABEL_IMPORT_SCHEDULE = 'Import Schedule';

    /**
     * Configuration manager instance
     *
     * @var SPSG_Configuration_Manager
     */
    private $config_manager;

    /**
     * Renderer instance
     *
     * @var SPSG_Admin_Renderer
     */
    private $renderer;

