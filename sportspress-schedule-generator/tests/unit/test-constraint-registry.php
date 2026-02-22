<?php
/**
 * Unit Tests for Constraint Registry
 * 
 * @author Cody (lusky3)
 */

require_once dirname(__DIR__) . '/bootstrap.php';

class Test_Constraint_Registry extends SPSG_Test_Case {
    
    protected function setUp() {
        // Clear registry for clean tests
        $reflection = new ReflectionClass('SPSG_Constraint_Registry');
        $property = $reflection->getProperty('constraint_classes');
        $property->setAccessible(true);
        $property->setValue(array());
        
        $property = $reflection->getProperty('constraint_instances');
        $property->setAccessible(true);
        $property->setValue(array());
    }
    
    protected function runTest() {
        $this->test_register_valid_constraint();
        $this->test_register_invalid_constraint();
        $this->test_get_registered_classes();
        $this->test_constraint_validation();
    }
    
    private function test_register_valid_constraint() {
        // Create a mock constraint class
        eval('
            class Mock_Valid_Constraint implements SPSG_Constraint_Interface {
                public function validate($game, $schedule, $config) { return true; }
                public function get_priority() { return 10; }
                public function get_type() { return "hard"; }
                public function get_violation_cost($game, $schedule, $config) { return 0.0; }
                public function get_name() { return "Mock Constraint"; }
            }
        ');
        
        $result = SPSG_Constraint_Registry::register('Mock_Valid_Constraint');
        $this->assertTrue($result === true, 'Should register valid constraint');
        
        $classes = SPSG_Constraint_Registry::get_registered_classes();
        $this->assertTrue(isset($classes['Mock_Valid_Constraint']), 'Constraint should be in registry');
    }
    
    private function test_register_invalid_constraint() {
        // Test registering non-existent class
        $result = SPSG_Constraint_Registry::register('Non_Existent_Class');
        $this->assertInstanceOf('WP_Error', $result, 'Should return WP_Error for non-existent class');
        
        // Test registering class without interface
        eval('class Mock_Invalid_Constraint {}');
        $result = SPSG_Constraint_Registry::register('Mock_Invalid_Constraint');
        $this->assertInstanceOf('WP_Error', $result, 'Should return WP_Error for class without interface');
    }
    
    private function test_get_registered_classes() {
        $classes = SPSG_Constraint_Registry::get_registered_classes();
        $this->assertTrue(is_array($classes), 'Should return array of classes');
    }
    
    private function test_constraint_validation() {
        $validation_results = SPSG_Constraint_Registry::validate_all();
        $this->assertTrue(is_array($validation_results), 'Should return validation results array');
        
        foreach ($validation_results as $result) {
            $this->assertTrue(isset($result['class']), 'Each result should have class name');
            $this->assertTrue(isset($result['valid']), 'Each result should have valid flag');
        }
    }
}

// Run the test
$test = new Test_Constraint_Registry();
$test->run();