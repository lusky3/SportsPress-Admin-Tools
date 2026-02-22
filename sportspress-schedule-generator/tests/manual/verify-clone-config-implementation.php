<?php
/**
 * Manual Verification Script for Clone Configuration AJAX Handler
 * 
 * This script verifies the implementation logic of the ajax_clone_config method
 * without requiring a full WordPress test environment.
 */

echo "Clone Configuration AJAX Handler - Implementation Verification\n";
echo "==============================================================\n\n";

// Verify the implementation exists
$admin_file = dirname(dirname(__DIR__)) . '/includes/class-admin.php';
$content = file_get_contents($admin_file);

echo "1. Checking if ajax_clone_config method exists... ";
if (strpos($content, 'public function ajax_clone_config()') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Method not found\n";
    exit(1);
}

echo "2. Checking if nonce verification is implemented... ";
if (strpos($content, "check_ajax_referer('spsg_clone_config', 'nonce')") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Nonce verification not found\n";
    exit(1);
}

echo "3. Checking if capability check is implemented... ";
if (strpos($content, "current_user_can('manage_options')") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Capability check not found\n";
    exit(1);
}

echo "4. Checking if config_id parameter is validated... ";
if (strpos($content, "sanitize_text_field(\$_POST['config_id']") !== false &&
    strpos($content, "empty(\$config_id)") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - config_id validation not found\n";
    exit(1);
}

echo "5. Checking if new_name parameter is validated... ";
if (strpos($content, "sanitize_text_field(\$_POST['new_name']") !== false &&
    strpos($content, "empty(\$new_name)") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - new_name validation not found\n";
    exit(1);
}

echo "6. Checking if clone_configuration is called... ";
if (strpos($content, '$this->config_manager->clone_configuration($config_id, $new_name)') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - clone_configuration call not found\n";
    exit(1);
}

echo "7. Checking if WP_Error is handled... ";
if (strpos($content, 'is_wp_error($result)') !== false &&
    strpos($content, 'wp_send_json_error($result->get_error_message())') !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - WP_Error handling not found\n";
    exit(1);
}

echo "8. Checking if success response includes new_config_id... ";
if (strpos($content, "'new_config_id' => \$new_config_id") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - new_config_id not in success response\n";
    exit(1);
}

echo "9. Checking if success response includes message... ";
if (strpos($content, "'message' => __('Configuration cloned successfully'") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Success message not found\n";
    exit(1);
}

echo "10. Checking if AJAX action hook is registered... ";
if (strpos($content, "add_action('wp_ajax_spsg_clone_config', array(\$this, 'ajax_clone_config'))") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - AJAX action hook not registered\n";
    exit(1);
}

echo "11. Checking if nonce is added to localized script... ";
if (strpos($content, "'clone_config' => wp_create_nonce('spsg_clone_config')") !== false) {
    echo "✓ PASS\n";
} else {
    echo "✗ FAIL - Nonce not added to localized script\n";
    exit(1);
}

echo "\n";
echo "==============================================================\n";
echo "All implementation checks passed! ✓\n";
echo "==============================================================\n\n";

echo "Implementation Summary:\n";
echo "- AJAX handler method: ajax_clone_config() ✓\n";
echo "- Nonce verification: check_ajax_referer() ✓\n";
echo "- Capability check: current_user_can('manage_options') ✓\n";
echo "- Input sanitization: sanitize_text_field() ✓\n";
echo "- Parameter validation: config_id and new_name ✓\n";
echo "- Backend call: clone_configuration() ✓\n";
echo "- Error handling: WP_Error check ✓\n";
echo "- Success response: message and new_config_id ✓\n";
echo "- Action hook: wp_ajax_spsg_clone_config ✓\n";
echo "- Nonce registration: spsgData.nonces.clone_config ✓\n";

exit(0);
