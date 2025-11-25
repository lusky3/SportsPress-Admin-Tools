<?php
/**
 * Manual Verification Script for Clone Configuration UI Button
 * 
 * This script verifies the implementation of the Clone Configuration button
 * in the admin interface.
 */

echo "Clone Configuration UI Button - Implementation Verification\n";
echo "===========================================================\n\n";

// Verify the implementation exists
$admin_file = dirname(dirname(__DIR__)) . '/includes/class-admin.php';
$content = file_get_contents($admin_file);

echo "1. Checking if Clone Configuration button exists... ";
if (strpos($content, 'id="spsg-clone-config"') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button ID not found\n";
    exit(1);
}

echo "2. Checking if button has correct label... ";
if (strpos($content, "<?php _e('Clone Configuration', 'sportspress-schedule-generator'); ?>") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button label not found\n";
    exit(1);
}

echo "3. Checking if button is positioned after 'Save As New'... ";
$save_as_new_pos = strpos($content, 'id="spsg-save-as-new"');
$clone_config_pos = strpos($content, 'id="spsg-clone-config"');
if ($save_as_new_pos !== false && $clone_config_pos !== false && $clone_config_pos > $save_as_new_pos) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button not positioned correctly\n";
    exit(1);
}

echo "4. Checking if button uses WordPress button class... ";
if (strpos($content, 'class="button" id="spsg-clone-config"') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button class not correct\n";
    exit(1);
}

echo "5. Checking if button is hidden when no config is selected... ";
if (strpos($content, "style=\"<?php echo empty(\$config->id) ? 'display:none;' : ''; ?>\"") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Conditional display logic not found\n";
    exit(1);
}

echo "6. Checking if button is in spsg-config-actions div... ";
$actions_div_start = strpos($content, '<div class="spsg-config-actions">');
$actions_div_end = strpos($content, '</div>', $actions_div_start);
if ($actions_div_start !== false && $clone_config_pos > $actions_div_start && $clone_config_pos < $actions_div_end) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button not in correct container\n";
    exit(1);
}

echo "7. Checking if button is before 'Delete Configuration'... ";
$delete_config_pos = strpos($content, 'id="spsg-delete-config"');
if ($delete_config_pos !== false && $clone_config_pos < $delete_config_pos) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button not positioned before Delete Configuration\n";
    exit(1);
}

echo "8. Checking if button type is 'button'... ";
if (strpos($content, 'type="button" class="button" id="spsg-clone-config"') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Button type not set correctly\n";
    exit(1);
}

echo "\n";
echo "===========================================================\n";
echo "All UI button checks passed! ✓\n";
echo "===========================================================\n\n";

echo "Implementation Summary:\n";
echo "- Button ID: spsg-clone-config ✓\n";
echo "- Button label: 'Clone Configuration' (translatable) ✓\n";
echo "- Button position: After 'Save As New' ✓\n";
echo "- Button styling: WordPress 'button' class ✓\n";
echo "- Conditional display: Hidden when no config selected ✓\n";
echo "- Container: spsg-config-actions div ✓\n";
echo "- Button order: Before 'Delete Configuration' ✓\n";
echo "- Button type: 'button' (prevents form submission) ✓\n";
echo "\nThe button is keyboard accessible via Tab navigation by default.\n";

exit(0);
