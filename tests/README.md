# e-Transfer Webhook Testing

Test scripts for the e-Transfer webhook endpoint supporting all 3 webhook methods.

## Available Test Scripts

### Bash Script (`test-webhook.sh`)

```bash
./test-webhook.sh --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]
```

### Python Script (`test-webhook.py`)

```bash
python3 test-webhook.py --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]
```

### PHP Script (`test-webhook.php`)

```bash
php test-webhook.php --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]
```

### Node.js Script (`test-webhook.js`)

```bash
node test-webhook.js --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]
```

## Webhook Methods

### Generic (Default)

Standard webhook format with full email structure including `reply_to` header for customer email matching.

### deliverhook.com

Simplified format used by deliverhook.com service with basic email fields.

### Cloudflare Email Routing

Format used by Cloudflare Email Routing with Worker processing.

## Usage Examples

```bash
# Test generic webhook (default)
./test-webhook.sh --url "https://example.com/wp-json/spet/v1/etransfer-webhook" --secret "your-secret-here"

# Test deliverhook format
python3 test-webhook.py --url "https://example.com/wp-json/spet/v1/etransfer-webhook" --secret "your-secret-here" --method deliverhook

# Test Cloudflare format with verbose output
php test-webhook.php --url "https://example.com/wp-json/spet/v1/etransfer-webhook" --secret "your-secret-here" --method cloudflare --verbose
```

## Test Data

All tests use sample e-Transfer data:

- **Order Number**: ARL-12345
- **Reference**: 12345678901 (Generic/Cloudflare) or CADzqxQ4 (deliverhook)
- **Amount**: $150.00 CAD
- **Sender**: John Smith
- **Customer Email**: [customer@example.com]

## Expected Results

- **Success**: HTTP 200 with JSON response
- **No Match**: `{"success":false,"message":"No matching order found"}` (expected for test data)
- **Error**: HTTP 4xx/5xx with error details

## Security

All scripts use HMAC SHA256 signature verification with the `X-Signature` header for webhook security.

## Sample e-Transfer Email

The `sample-etransfer-notification.eml` file contains a real e-Transfer notification email for reference when setting up email forwarding services.
