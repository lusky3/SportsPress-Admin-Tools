<?php
/**
 * Base Test Case for Lightweight SPSG Tests
 * 
 * Provides basic assertion methods and test runner functionality
 * for tests that don't require the full WP_UnitTestCase.
 */

abstract class SPSG_Test_Case
{

    protected $passed = 0;
    protected $failed = 0;
    protected $messages = array();

    /**
     * Run the test suite
     */
    public function run()
    {
        $class_name = get_class($this);
        echo "Running $class_name...\n";

        try {
            $this->runTest();
        }
        catch (Exception $e) {
            $this->fail("Unhandled Exception: " . $e->getMessage());
        }

        $this->report();
    }

    /**
     * Abstract method to be implemented by child classes
     */
    abstract protected function runTest();

    /**
     * Report test results
     */
    protected function report()
    {
        echo "Results for " . get_class($this) . ":\n";
        echo "  Passed: " . $this->passed . "\n";
        echo "  Failed: " . $this->failed . "\n";

        if ($this->failed > 0) {
            echo "  Failures:\n";
            foreach ($this->messages as $msg) {
                echo "    - $msg\n";
            }
        }
        echo "\n";

        if ($this->failed > 0) {
            echo "FAIL: " . get_class($this) . " had " . $this->failed . " failures.\n";
        }
    }

    protected function assertEquals($expected, $actual, $message = '')
    {
        if ($expected === $actual) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected: " . var_export($expected, true) . ", Actual: " . var_export($actual, true) . ")");
        }
    }

    protected function assertTrue($condition, $message = '')
    {
        if ($condition === true) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected true, got " . var_export($condition, true) . ")");
        }
    }

    protected function assertFalse($condition, $message = '')
    {
        if ($condition === false) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected false, got " . var_export($condition, true) . ")");
        }
    }

    protected function assertInstanceOf($expected_class, $actual, $message = '')
    {
        if ($actual instanceof $expected_class) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected instance of $expected_class, got " . get_class($actual) . ")");
        }
    }

    protected function assertWPError($actual, $message = '')
    {
        if (is_wp_error($actual)) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected WP_Error, got " . var_export($actual, true) . ")");
        }
    }

    protected function assertContains($needle, $haystack, $message = '')
    {
        if (is_array($haystack) && in_array($needle, $haystack)) {
            $this->pass();
        }
        elseif (is_string($haystack) && strpos($haystack, $needle) !== false) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Did not find " . var_export($needle, true) . " in haystack)");
        }
    }

    protected function assertArrayHasKey($key, $array, $message = '')
    {
        if (is_array($array) && array_key_exists($key, $array)) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Array does not have key: $key)");
        }
    }

    protected function assertIsArray($actual, $message = '')
    {
        if (is_array($actual)) {
            $this->pass();
        }
        else {
            $this->fail($message . " (Expected array, got " . get_type($actual) . ")");
        }
    }

    private function pass()
    {
        $this->passed++;
    }

    private function fail($message)
    {
        $this->failed++;
        $this->messages[] = $message;
    }
}
