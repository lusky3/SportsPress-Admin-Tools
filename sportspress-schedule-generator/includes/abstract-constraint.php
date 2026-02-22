<?php
/**
 * Abstract Base Constraint Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Abstract base class for all constraints
 */
abstract class SPSG_Abstract_Constraint implements SPSG_Constraint_Interface
{

    /**
     * Constraint name
     */
    protected $name;

    /**
     * Constraint priority
     */
    protected $priority = 10;

    /**
     * Constraint type
     */
    protected $type = 'hard';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->init();
    }

    /**
     * Initialize constraint (override in child classes)
     */
    protected function init()
    {
    // Override in child classes
    }

    /**
     * Get constraint priority
     */
    public function get_priority()
    {
        return $this->priority;
    }

    /**
     * Get constraint type
     */
    public function get_type()
    {
        return $this->type;
    }

    /**
     * Get constraint name
     */
    public function get_name()
    {
        return $this->name ?: get_class($this);
    }

    /**
     * Default violation cost implementation
     */
    public function get_violation_cost($game, $schedule, $config)
    {
        if ($this->type === 'hard') {
            return PHP_FLOAT_MAX; // Hard constraints have infinite cost when violated
        }

        // Override in child classes for soft/optimization constraints
        return 0.0;
    }

    /**
     * Log constraint activity (if debug enabled)
     */
    protected function log($message, $level = 'info')
    {
        if (get_option('spsg_enable_debug_logging', '0') === '1') {
            error_log(sprintf('[SPSG Constraint %s] %s', $this->get_name(), $message));
        }
    }

    /**
     * Helper to safely get team ID from object or array
     */
    protected function get_team_id($team)
    {
        return is_array($team) ? $team['id'] : $team->id;
    }

    /**
     * Helper to safely get venue ID from object or array
     */
    protected function get_venue_id($venue)
    {
        return is_array($venue) ? $venue['id'] : $venue->id;
    }

    /**
     * Helper to safely get venue name from object or array
     */
    protected function get_venue_name($venue)
    {
        return is_array($venue) ? $venue['name'] : $venue->name;
    }
}