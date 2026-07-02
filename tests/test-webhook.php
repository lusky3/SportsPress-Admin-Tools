<?php
/**
 * e-Transfer Webhook Tester - Supports all 3 webhook methods
 * Usage: php test-webhook.php --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>]
 *
 * @author Cody (lusky3)
 */

// Parse command line arguments
$options = getopt('', ['url:', 'secret:', 'method::', 'verbose', 'help']);

if (isset($options['help']) || !isset($options['url']) || !isset($options['secret'])) {
    echo "Usage: php test-webhook.php --url <webhook_url> --secret <webhook_secret> [options]\n";
    echo "Options:\n";
    echo "  --method <generic|deliverhook|cloudflare>  Webhook method (default: generic)\n";
    echo "  --verbose                                  Verbose output\n";
    echo "  --help                                     Show this help\n";
    exit(isset($options['help']) ? 0 : 1);
}

$webhook_url = $options['url'];
$webhook_secret = $options['secret'];
$method = $options['method'] ?? 'generic';
$verbose = isset($options['verbose']);

// Validate method
if (!in_array($method, ['generic', 'deliverhook', 'cloudflare'])) {
    echo "Error: Method must be one of: generic, deliverhook, cloudflare\n";
    exit(1);
}

// Create test payload based on method
switch ($method) {
    case 'generic':
        $sample_payload = array(
            'from' => array(
                'address' => 'notify@payments.interac.ca',
                'name' => 'Interac e-Transfer'
            ),
            'reply_to' => array(
                'address' => 'customer@example.com',
                'name' => 'John Smith'
            ),
            'to' => 'payments@example.com',
            'subject' => 'INTERAC e-Transfer: You have received money',
            'date' => date('c'),
            'text' => "You have received an INTERAC e-Transfer.\n\nReference Number:\n\n12345678901\n\nAmount:\n\n$150.00 (CAD)\n\nSent From:\n\nJohn Smith\n\nMessage:\n\nARL-12345\n\nTo deposit this money, please follow the instructions below.",
            'html' => "<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>"
        );
        break;
    case 'deliverhook':
        $sample_payload = array(
            'subject' => 'INTERAC e-Transfer: You have received money',
            'date' => date('r'),
            'html' => null,
            'text' => "Transfer Details\n\nMessage:\n\nARL-12345\n\nDate:\n\n" . date('F j, Y') . "\n\nReference Number:\n\nCADzqxQ4\n\nSent From:\n\nJohn Smith\n\nAmount:\n\n$150.00 (CAD)",
            'from' => array(
                'name' => 'Interac e-Transfer',
                'address' => 'notify@payments.interac.ca'
            ),
            'attachments' => array()
        );
        break;
    case 'cloudflare':
        $sample_payload = array(
            'from' => array(
                'address' => 'notify@payments.interac.ca',
                'name' => 'Interac e-Transfer'
            ),
            'reply_to' => array(
                'address' => 'customer@example.com',
                'name' => 'John Smith'
            ),
            'to' => 'payments@example.com',
            'subject' => 'INTERAC e-Transfer: You have received money',
            'date' => date('c'),
            'text' => "You have received an INTERAC e-Transfer.\n\nReference Number:\n\n12345678901\n\nAmount:\n\n$150.00 (CAD)\n\nSent From:\n\nJohn Smith\n\nMessage:\n\nARL-12345\n\nTo deposit this money, please follow the instructions below.",
            'html' => "<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>"
        );
        break;
}

// Convert to JSON
$json_payload = json_encode($sample_payload);

// Generate HMAC signature
$signature = hash_hmac('sha256', $json_payload, $webhook_secret);

if ($verbose) {
    echo "Testing webhook: $webhook_url\n";
    echo "Method: $method\n";
    echo "Payload size: " . strlen($json_payload) . " bytes\n";
    echo "HMAC signature: " . substr($signature, 0, 16) . "...\n";
    echo "\nTest payload:\n";
    echo json_encode($sample_payload, JSON_PRETTY_PRINT) . "\n\n";
}

echo "Sending $method webhook request...\n";

// Send test request
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $webhook_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
curl_setopt($ch, CURLOPT_HTTPHEADER, array(
    'Content-Type: application/json',
    'X-Signature: ' . $signature,
    'User-Agent: Test-Webhook-Client/1.0'
));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$start_time = microtime(true);
$response = curl_exec($ch);
$end_time = microtime(true);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

// Display results
echo "Status Code: $http_code\n";
echo "Response Time: " . round(($end_time - $start_time) * 1000, 2) . "ms\n";
echo "Response Body: $response\n";

if ($http_code === 200) {
    echo "\n✅ $method webhook test successful!\n";
    exit(0);
} else {
    echo "\n❌ $method webhook test failed with status $http_code\n";
    exit(1);
}