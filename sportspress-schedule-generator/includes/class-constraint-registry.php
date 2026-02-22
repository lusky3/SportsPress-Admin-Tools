<?php
/**
 * Constraint Registry Class
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    wp_die();
}

/**
 * Registry for constraint plugins with discovery and validation
 */
class SPSG_Constraint_Registry
{

    /**
     * Registered constraint classes
     */
    private static $constraint_classes = array();

    /**
     * Constraint instances cache
     */
    private static $constraint_instances = array();

    /**
     * Register a constraint class
     */
    public static function register($class_name, $args = array())
    {
        if (!class_exists($class_name)) {
            return new WP_Error('class_not_found', sprintf(__('Constraint class %s not found', 'sportspress-schedule-generator'), $class_name));
        }

        // Validate that class implements interface
        $reflection = new ReflectionClass($class_name);
        if (!$reflection->implementsInterface('SPSG_Constraint_Interface')) {
            return new WP_Error('invalid_interface', sprintf(__('Class %s must implement SPSG_Constraint_Interface', 'sportspress-schedule-generator'), $class_name));
        }

        $defaults = array(
            'enabled' => true,
            'priority_override' => null,
            'description' => '',
            'category' => 'general'
        );

        $args = wp_parse_args($args, $defaults);

        self::$constraint_classes[$class_name] = $args;

        return true;
    }

    /**
     * Unregister a constraint class
     */
    public static function unregister($class_name)
    {
        unset(self::$constraint_classes[$class_name]);
        unset(self::$constraint_instances[$class_name]);
    }

    /**
     * Get all registered constraint classes
     */
    public static function get_registered_classes()
    {
        return self::$constraint_classes;
    }

    /**
     * Get constraint instance (with caching)
     */
    public static function get_instance($class_name)
    {
        if (!isset(self::$constraint_instances[$class_name])) {
            if (!isset(self::$constraint_classes[$class_name])) {
                return new WP_Error('not_registered', sprintf(__('Constraint class %s not registered', 'sportspress-schedule-generator'), $class_name));
            }

            if (!self::$constraint_classes[$class_name]['enabled']) {
                return new WP_Error('disabled', sprintf(__('Constraint class %s is disabled', 'sportspress-schedule-generator'), $class_name));
            }

            try {
                $instance = new $class_name();

                // Apply priority override if set
                if (self::$constraint_classes[$class_name]['priority_override'] !== null) {
                    $reflection = new ReflectionClass($instance);
                    if ($reflection->hasProperty('priority')) {
                        $property = $reflection->getProperty('priority');
                        $property->setAccessible(true);
                        $property->setValue($instance, self::$constraint_classes[$class_name]['priority_override']);
                    }
                }

                self::$constraint_instances[$class_name] = $instance;
            }
            catch (Exception $e) {
                return new WP_Error('instantiation_failed', sprintf(__('Failed to create instance of %s: %s', 'sportspress-schedule-generator'), $class_name, $e->getMessage()));
            }
        }

        return self::$constraint_instances[$class_name];
    }

    /**
     * Get all enabled constraint instances
     */
    public static function get_enabled_instances()
    {
        $instances = array();

        foreach (self::$constraint_classes as $class_name => $args) {
            if ($args['enabled']) {
                $instance = self::get_instance($class_name);
                if (!is_wp_error($instance)) {
                    $instances[] = $instance;
                }
            }
        }

        return $instances;
    }

    /**
     * Auto-discover constraint classes in directory
     */
    public static function discover_constraints($directory)
    {
        if (!is_dir($directory)) {
            return new WP_Error('directory_not_found', sprintf(__('Constraint directory %s not found', 'sportspress-schedule-generator'), $directory));
        }

        $discovered = array();
        $files = glob($directory . '/class-*-constraint.php');

        foreach ($files as $file) {
            $class_name = self::extract_class_name_from_file($file);
            if ($class_name && !isset(self::$constraint_classes[$class_name])) {
                require_once $file;

                if (class_exists($class_name)) {
                    $result = self::register($class_name, array(
                        'discovered' => true,
                        'file' => $file
                    ));

                    if (!is_wp_error($result)) {
                        $discovered[] = $class_name;
                    }
                }
            }
        }

        return $discovered;
    }

    /**
     * Extract class name from file path
     */
    private static function extract_class_name_from_file($file)
    {
        $filename = basename($file, '.php');

        // Convert class-name-constraint to SPSG_Name_Constraint
        $parts = explode('-', $filename);
        if (count($parts) >= 3 && $parts[0] === 'class' && end($parts) === 'constraint') {
            array_shift($parts); // Remove 'class'
            
            // Map common constraint names to their class names
            // If the filename follows standard wordpress naming (class-something-constraint.php)
            // we convert it to SPSG_Something_Constraint
            
            $class_parts = array_map('ucfirst', $parts);
            return 'SPSG_' . implode('_', $class_parts);
        }

        return null;
    }

    /**
     * Validate all registered constraints
     */
    public static function validate_all()
    {
        $validation_results = array();

        foreach (self::$constraint_classes as $class_name => $args) {
            $result = array(
                'class' => $class_name,
                'enabled' => $args['enabled'],
                'valid' => false,
                'errors' => array()
            );

            try {
                $instance = self::get_instance($class_name);
                if (is_wp_error($instance)) {
                    $result['errors'][] = $instance->get_error_message();
                }
                else {
                    // Test basic interface methods
                    $test_methods = array('get_name', 'get_type', 'get_priority');
                    foreach ($test_methods as $method) {
                        if (!method_exists($instance, $method)) {
                            $result['errors'][] = sprintf(__('Missing required method: %s', 'sportspress-schedule-generator'), $method);
                        }
                    }

                    if (empty($result['errors'])) {
                        $result['valid'] = true;
                    }
                }
            }
            catch (Exception $e) {
                $result['errors'][] = $e->getMessage();
            }

            $validation_results[] = $result;
        }

        return $validation_results;
    }

    /**
     * Get constraint statistics
     */
    public static function get_stats()
    {
        $total = count(self::$constraint_classes);
        $enabled = count(array_filter(self::$constraint_classes, function ($args) {
            return $args['enabled'];
        }));

        $categories = array();
        foreach (self::$constraint_classes as $args) {
            $category = $args['category'];
            $categories[$category] = isset($categories[$category]) ? $categories[$category] + 1 : 1;
        }

        return array(
            'total' => $total,
            'enabled' => $enabled,
            'disabled' => $total - $enabled,
            'categories' => $categories
        );
    }

    /**
     * Clear all cached instances
     */
    public static function clear_cache()
    {
        self::$constraint_instances = array();
    }
}