#!/usr/bin/env python3
"""
e-Transfer Webhook Tester - Supports all 3 webhook methods
Usage: python3 test-webhook.py --url <webhook_url> --secret <webhook_secret> [--method <generic|deliverhook|cloudflare>]
"""

import json
import hmac
import hashlib
import requests
import argparse
import sys
from datetime import datetime

def create_hmac_signature(payload, secret):
    """Create HMAC SHA256 signature for webhook payload"""
    return hmac.new(
        secret.encode('utf-8'),
        payload.encode('utf-8'),
        hashlib.sha256
    ).hexdigest()

def create_test_payload(method):
    """Create test e-Transfer notification payload based on method"""
    if method == 'generic':
        return {
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
            "date": datetime.now().isoformat(),
            "text": """You have received an INTERAC e-Transfer.

Reference Number:

12345678901

Amount:

$150.00 (CAD)

Sent From:

John Smith

Message:

ARL-12345

To deposit this money, please follow the instructions below.""",
            "html": """<p>You have received an INTERAC e-Transfer.</p>
<p><strong>Reference Number:</strong><br>12345678901</p>
<p><strong>Amount:</strong><br>$150.00 (CAD)</p>
<p><strong>Sent From:</strong><br>John Smith</p>
<p><strong>Message:</strong><br>ARL-12345</p>"""
        }
    elif method == 'deliverhook':
        return {
            "subject": "INTERAC e-Transfer: You have received money",
            "date": datetime.now().strftime('%a, %d %b %Y %H:%M:%S %z'),
            "html": None,
            "text": f"""Transfer Details

Message:

ARL-12345

Date:

{datetime.now().strftime('%B %d, %Y')}

Reference Number:

CADzqxQ4

Sent From:

John Smith

Amount:

$150.00 (CAD)""",
            "from": {
                "name": "Interac e-Transfer",
                "address": "notify@payments.interac.ca"
            },
            "attachments": []
        }
    elif method == 'cloudflare':
        return {
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
            "date": datetime.now().isoformat(),
            "text": """You have received an INTERAC e-Transfer.

Reference Number:

12345678901

Amount:

$150.00 (CAD)

Sent From:

John Smith

Message:

ARL-12345

To deposit this money, please follow the instructions below.""",
            "html": """<p>You have received an INTERAC e-Transfer.</p>
<p><strong>Reference Number:</strong><br>12345678901</p>
<p><strong>Amount:</strong><br>$150.00 (CAD)</p>
<p><strong>Sent From:</strong><br>John Smith</p>
<p><strong>Message:</strong><br>ARL-12345</p>"""
        }
    else:
        raise ValueError(f"Unknown method: {method}")

def test_webhook(url, secret, method):
    """Test the webhook endpoint"""
    payload = create_test_payload(method)
    payload_json = json.dumps(payload, separators=(',', ':'))
    
    signature = create_hmac_signature(payload_json, secret)
    
    headers = {
        'Content-Type': 'application/json',
        'X-Signature': signature,
        'User-Agent': 'Test-Webhook-Client/1.0'
    }
    
    print(f"Testing webhook: {url}")
    print(f"Method: {method}")
    print(f"Payload size: {len(payload_json)} bytes")
    print(f"HMAC signature: {signature[:16]}...")
    print()
    
    try:
        response = requests.post(url, json=payload, headers=headers, timeout=30)
        
        print(f"Status Code: {response.status_code}")
        print(f"Response Headers: {dict(response.headers)}")
        print(f"Response Body: {response.text}")
        
        if response.status_code == 200:
            print(f"\n✅ {method} webhook test successful!")
        else:
            print(f"\n❌ {method} webhook test failed with status {response.status_code}")
            
    except requests.exceptions.RequestException as e:
        print(f"❌ Request failed: {e}")
        return False
    
    return response.status_code == 200

def main():
    parser = argparse.ArgumentParser(description='Test e-Transfer webhook endpoint')
    parser.add_argument('--url', required=True, help='Webhook URL')
    parser.add_argument('--secret', required=True, help='Webhook secret for HMAC signature')
    parser.add_argument('--method', choices=['generic', 'deliverhook', 'cloudflare'], default='generic', help='Webhook method (default: generic)')
    parser.add_argument('--verbose', '-v', action='store_true', help='Verbose output')
    
    args = parser.parse_args()
    
    if args.verbose:
        print(f"Test payload ({args.method}):")
        print(json.dumps(create_test_payload(args.method), indent=2))
        print()
    
    success = test_webhook(args.url, args.secret, args.method)
    sys.exit(0 if success else 1)

if __name__ == '__main__':
    main()