<?php
/**
 * Test Captain Functionality
 * 
 * @author Cody (lusky3)
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    require_once('../../../../wp-load.php');
}

// Test captain functionality
function test_captain_functionality() {
    echo "<h2>Testing Captain Functionality</h2>\n";
    
    // Check if SportsPress is active
    if (!class_exists('SportsPress')) {
        echo "<p style='color: red;'>SportsPress plugin is not active!</p>\n";
        return;
    }
    
    // Check if Player Modifications module is enabled
    $enabled_modules = get_option('spat_enabled_modules', array());
    if (!in_array('player_modifications', $enabled_modules)) {
        echo "<p style='color: orange;'>Player Modifications module is not enabled!</p>\n";
        return;
    }
    
    // Check if captain role setting is enabled
    $captain_enabled = get_option('spat_player_modifications_captain_role', '1');
    if ($captain_enabled !== '1') {
        echo "<p style='color: orange;'>Captain role setting is disabled!</p>\n";
        return;
    }
    
    echo "<p style='color: green;'>✓ All prerequisites met</p>\n";
    
    // Find an sp_list to test with
    $lists = get_posts(array(
        'post_type' => 'sp_list',
        'posts_per_page' => 5,
        'post_status' => 'publish'
    ));
    
    if (empty($lists)) {
        echo "<p style='color: orange;'>No sp_list posts found to test with</p>\n";
        return;
    }
    
    echo "<h3>Available Lists:</h3>\n";
    foreach ($lists as $list) {
        echo "<p><strong>{$list->post_title}</strong> (ID: {$list->ID})</p>\n";
        
        // Check for players in this list
        $players = get_post_meta($list->ID, 'sp_player', false);
        if (empty($players)) {
            $players = get_post_meta($list->ID, 'sp_players', true);
            if (is_array($players)) {
                $players = array_keys($players);
            }
        }
        
        if (!empty($players)) {
            echo "<ul>\n";
            foreach ($players as $player_id) {
                if (is_numeric($player_id) && get_post_type($player_id) === 'sp_player') {
                    $player_title = get_the_title($player_id);
                    echo "<li>Player: {$player_title} (ID: {$player_id})</li>\n";
                }
            }
            echo "</ul>\n";
            
            // Check current captain
            $captain_id = get_post_meta($list->ID, 'spat_captain', true);
            if ($captain_id) {
                $captain_name = get_the_title($captain_id);
                echo "<p style='color: blue;'>Current Captain: {$captain_name} (ID: {$captain_id})</p>\n";
            } else {
                echo "<p>No captain selected</p>\n";
            }
        } else {
            echo "<p style='color: orange;'>No players found in this list</p>\n";
        }
        
        echo "<hr>\n";
    }
    
    echo "<h3>Test Complete</h3>\n";
    echo "<p>To test the captain functionality:</p>\n";
    echo "<ol>\n";
    echo "<li>Go to any sp_list edit page in WordPress admin</li>\n";
    echo "<li>Look for the 'Captain Selection' meta box in the sidebar</li>\n";
    echo "<li>Select a captain from the dropdown</li>\n";
    echo "<li>Save the list</li>\n";
    echo "<li>View the list on the frontend - the captain should have a 'C' indicator</li>\n";
    echo "</ol>\n";
}

// Run the test
test_captain_functionality();
?>