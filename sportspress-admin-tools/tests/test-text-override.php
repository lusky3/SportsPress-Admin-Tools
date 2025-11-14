<?php
/**
 * Test file for SportsPress text overrides
 * This file can be temporarily included to test the text override functionality
 * 
 * @author Cody (lusky3)
 */

// This is a temporary test file - remove after testing

// Include WordPress
require_once('../../../wp-config.php');

// Include our text helper
require_once('includes/class-text-helper.php');

echo "<h1>SportsPress Text Override Test</h1>";

// Test basic functionality
echo "<h2>Basic Text Override Test</h2>";
echo "<p>Player (default): " . SPAT_Text_Helper::get_text('Player') . "</p>";
echo "<p>Team (default): " . SPAT_Text_Helper::get_text('Team') . "</p>";
echo "<p>League (default): " . SPAT_Text_Helper::get_text('League') . "</p>";
echo "<p>Venue (default): " . SPAT_Text_Helper::get_text('Venue') . "</p>";
echo "<p>Season (default): " . SPAT_Text_Helper::get_text('Season') . "</p>";
echo "<p>Event (default): " . SPAT_Text_Helper::get_text('Event') . "</p>";

// Test if SportsPress is available
if (function_exists('SP') && SP()) {
    echo "<h2>SportsPress Available</h2>";
    echo "<p>SportsPress is loaded and available.</p>";
    
    if (!empty(SP()->text)) {
        echo "<p>Text overrides found: " . count(SP()->text) . " entries</p>";
        echo "<pre>" . print_r(SP()->text, true) . "</pre>";
    } else {
        echo "<p>No text overrides configured.</p>";
    }
} else {
    echo "<h2>SportsPress Not Available</h2>";
    echo "<p>SportsPress is not loaded or not available.</p>";
}

echo "<p><em>Remember to delete this test file after testing!</em></p>";