/**
 * e-Transfer Webhook Tester - Supports all 3 webhook methods
 * Usage: node test-webhook.js --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]
 * 
 * @author Cody (lusky3)
 */

const crypto = require('crypto');
const https = require('https');
const http = require('http');

// Parse command line arguments
const args = process.argv.slice(2);
const options = {};

for (let i = 0; i < args.length; i += 2) {
    const key = args[i].replace(/^--/, '');
    const value = args[i + 1];
    options[key] = value;
}

if (options.help || !options.url || !options.secret) {
    console.log('Usage: node test-webhook.js --url <webhook_url> --secret <webhook_secret> [options]');
    console.log('Options:');
    console.log('  --method <generic|deliverhook|cloudflare>  Webhook method (default: generic)');
    console.log('  --verbose                                  Verbose output');
    console.log('  --help                                     Show this help');
    process.exit(options.help ? 0 : 1);
}

const webhookUrl = options.url;
const webhookSecret = options.secret;
const method = options.method || 'generic';
const verbose = options.verbose !== undefined;

// Validate method
if (!['generic', 'deliverhook', 'cloudflare'].includes(method)) {
    console.error('Error: Method must be one of: generic, deliverhook, cloudflare');
    process.exit(1);
}

// Create test payload based on method
let samplePayload;

switch (method) {
    case 'generic':
        samplePayload = {
            from: {
                address: 'notify@payments.interac.ca',
                name: 'Interac e-Transfer'
            },
            reply_to: {
                address: 'customer@example.com',
                name: 'John Smith'
            },
            to: 'payments@example.com',
            subject: 'INTERAC e-Transfer: You have received money',
            date: new Date().toISOString(),
            text: `You have received an INTERAC e-Transfer.

Reference Number:

12345678901

Amount:

$150.00 (CAD)

Sent From:

John Smith

Message:

ARL-12345

To deposit this money, please follow the instructions below.`,
            html: `<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>`
        };
        break;
    case 'deliverhook':
        samplePayload = {
            subject: 'INTERAC e-Transfer: You have received money',
            date: new Date().toUTCString(),
            html: null,
            text: `Transfer Details

Message:

ARL-12345

Date:

${new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}

Reference Number:

CADzqxQ4

Sent From:

John Smith

Amount:

$150.00 (CAD)`,
            from: {
                name: 'Interac e-Transfer',
                address: 'notify@payments.interac.ca'
            },
            attachments: []
        };
        break;
    case 'cloudflare':
        samplePayload = {
            from: {
                address: 'notify@payments.interac.ca',
                name: 'Interac e-Transfer'
            },
            reply_to: {
                address: 'customer@example.com',
                name: 'John Smith'
            },
            to: 'payments@example.com',
            subject: 'INTERAC e-Transfer: You have received money',
            date: new Date().toISOString(),
            text: `You have received an INTERAC e-Transfer.

Reference Number:

12345678901

Amount:

$150.00 (CAD)

Sent From:

John Smith

Message:

ARL-12345

To deposit this money, please follow the instructions below.`,
            html: `<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>`
        };
        break;
}

// Convert to JSON
const jsonPayload = JSON.stringify(samplePayload);

// Generate HMAC signature
const signature = crypto
    .createHmac('sha256', webhookSecret)
    .update(jsonPayload)
    .digest('hex');

if (verbose) {
    console.log(`Testing webhook: ${webhookUrl}`);
    console.log(`Method: ${method}`);
    console.log(`Payload size: ${Buffer.byteLength(jsonPayload)} bytes`);
    console.log(`HMAC signature: ${signature.substring(0, 16)}...`);
    console.log('\nTest payload:');
    console.log(JSON.stringify(samplePayload, null, 2));
    console.log();
}

console.log(`Sending ${method} webhook request...`);

// Parse URL
const url = new URL(webhookUrl);

// Request options
const requestOptions = {
    hostname: url.hostname,
    port: url.port || (url.protocol === 'https:' ? 443 : 80),
    path: url.pathname + url.search,
    method: 'POST',
    headers: {
        'Content-Type': 'application/json',
        'Content-Length': Buffer.byteLength(jsonPayload),
        'X-Signature': signature,
        'User-Agent': 'Test-Webhook-Client/1.0'
    }
};

const startTime = Date.now();

// Send request
const req = (url.protocol === 'https:' ? https : http).request(requestOptions, (res) => {
    let data = '';
    
    res.on('data', (chunk) => {
        data += chunk;
    });
    
    res.on('end', () => {
        const endTime = Date.now();
        
        console.log(`Status Code: ${res.statusCode}`);
        console.log(`Response Time: ${endTime - startTime}ms`);
        console.log(`Response Body: ${data}`);
        
        if (res.statusCode === 200) {
            console.log(`\n✅ ${method} webhook test successful!`);
            process.exit(0);
        } else {
            console.log(`\n❌ ${method} webhook test failed with status ${res.statusCode}`);
            process.exit(1);
        }
    });
});

req.on('error', (error) => {
    console.error(`❌ Request failed: ${error.message}`);
    process.exit(1);
});

req.write(jsonPayload);
req.end();