#!/bin/bash
# e-Transfer Webhook Tester - Supports all 3 webhook methods
# Usage: ./test-webhook.sh --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>] [--verbose]

set -e

# Default values
METHOD="generic"
VERBOSE=0

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --url)
            WEBHOOK_URL="$2"
            shift 2
            ;;
        --secret)
            WEBHOOK_SECRET="$2"
            shift 2
            ;;
        --method)
            METHOD="$2"
            shift 2
            ;;
        --verbose|-v)
            VERBOSE=1
            shift
            ;;
        --help|-h)
            echo "Usage: $0 --url <webhook_url> --secret <webhook_secret> [options]"
            echo "Options:"
            echo "  --method <generic|deliverhook|cloudflare>  Webhook method (default: generic)"
            echo "  --verbose, -v                             Verbose output"
            echo "  --help, -h                                Show this help"
            exit 0
            ;;
        *)
            echo "Unknown option $1"
            echo "Usage: $0 --url <webhook_url> --secret <webhook_secret> [--method <method>] [--verbose]"
            exit 1
            ;;
    esac
done

# Check required arguments
if [[ -z "$WEBHOOK_URL" || -z "$WEBHOOK_SECRET" ]]; then
    echo "Error: Both --url and --secret are required"
    echo "Usage: $0 --url <webhook_url> --secret <webhook_secret> [--method <method>] [--verbose]"
    exit 1
fi

# Validate method
if [[ "$METHOD" != "generic" && "$METHOD" != "deliverhook" && "$METHOD" != "cloudflare" ]]; then
    echo "Error: Method must be one of: generic, deliverhook, cloudflare"
    exit 1
fi

# Create test payload based on method
case "$METHOD" in
    "generic")
        PAYLOAD='{
          "from": {
            "address": "notify@payments.interac.ca",
            "name": "Interac e-Transfer"
          },
          "reply_to": {
            "address": "customer@example.com",
            "name": "John Smith"
          },
          "to": "payments@example.com",
          "subject": "INTERAC e-Transfer: You have received money",
          "date": "'$(date -Iseconds)'",
          "text": "You have received an INTERAC e-Transfer.\n\nReference Number:\n\n12345678901\n\nAmount:\n\n$150.00 (CAD)\n\nSent From:\n\nJohn Smith\n\nMessage:\n\nARL-12345\n\nTo deposit this money, please follow the instructions below.",
          "html": "<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>"
        }'
        ;;
    "deliverhook")
        PAYLOAD='{
          "subject": "INTERAC e-Transfer: You have received money",
          "date": "'$(date -R)'",
          "html": null,
          "text": "Transfer Details\n\nMessage:\n\nARL-12345\n\nDate:\n\n'$(date "+%B %d, %Y")'\n\nReference Number:\n\nCADzqxQ4\n\nSent From:\n\nJohn Smith\n\nAmount:\n\n$150.00 (CAD)",
          "from": {
            "name": "Interac e-Transfer",
            "address": "notify@payments.interac.ca"
          },
          "attachments": []
        }'
        ;;
    "cloudflare")
        PAYLOAD='{
          "from": {
            "address": "notify@payments.interac.ca",
            "name": "Interac e-Transfer"
          },
          "reply_to": {
            "address": "customer@example.com",
            "name": "John Smith"
          },
          "to": "payments@example.com",
          "subject": "INTERAC e-Transfer: You have received money",
          "date": "'$(date -Iseconds)'",
          "text": "You have received an INTERAC e-Transfer.\n\nReference Number:\n\n12345678901\n\nAmount:\n\n$150.00 (CAD)\n\nSent From:\n\nJohn Smith\n\nMessage:\n\nARL-12345\n\nTo deposit this money, please follow the instructions below.",
          "html": "<p>You have received an INTERAC e-Transfer.</p><p><strong>Reference Number:</strong><br>12345678901</p><p><strong>Amount:</strong><br>$150.00 (CAD)</p><p><strong>Sent From:</strong><br>John Smith</p><p><strong>Message:</strong><br>ARL-12345</p>"
        }'
        ;;
esac

# Create HMAC signature
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$WEBHOOK_SECRET" | cut -d' ' -f2)

if [[ "$VERBOSE" == "1" ]]; then
    echo "Testing webhook: $WEBHOOK_URL"
    echo "Method: $METHOD"
    echo "Payload size: $(echo -n "$PAYLOAD" | wc -c) bytes"
    echo "HMAC signature: ${SIGNATURE:0:16}..."
    echo
    echo "Test payload:"
    echo "$PAYLOAD" | jq .
    echo
fi

echo "Sending $METHOD webhook request..."

# Send webhook request
RESPONSE=$(curl -s -w "\nHTTP_STATUS:%{http_code}\nTIME_TOTAL:%{time_total}" \
    -X POST \
    -H "Content-Type: application/json" \
    -H "X-Signature: $SIGNATURE" \
    -H "User-Agent: Test-Webhook-Client/1.0" \
    -d "$PAYLOAD" \
    "$WEBHOOK_URL")

# Parse response
HTTP_STATUS=$(echo "$RESPONSE" | grep "HTTP_STATUS:" | cut -d: -f2)
TIME_TOTAL=$(echo "$RESPONSE" | grep "TIME_TOTAL:" | cut -d: -f2)
RESPONSE_BODY=$(echo "$RESPONSE" | sed '/HTTP_STATUS:/d' | sed '/TIME_TOTAL:/d')

echo "Status Code: $HTTP_STATUS"
echo "Response Time: ${TIME_TOTAL}s"
echo "Response Body: $RESPONSE_BODY"

if [[ "$HTTP_STATUS" == "200" ]]; then
    echo
    echo "✅ $METHOD webhook test successful!"
    exit 0
else
    echo
    echo "❌ $METHOD webhook test failed with status $HTTP_STATUS"
    exit 1
fi