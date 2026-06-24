# Cloudflare Worker for e-Transfer Email Processing

This Cloudflare Worker processes Interac e-Transfer notification emails and forwards them as webhooks to your SportsPress Admin Tools plugin.

## Prerequisites

- Cloudflare account with Email Routing enabled
- Domain configured with Cloudflare
- Wrangler CLI installed (`npm install -g wrangler`)

## Setup Instructions

### 1. Enable Email Routing

1. Log into your Cloudflare dashboard
2. Select your domain
3. Go to **Email** → **Email Routing**
4. Click **Enable Email Routing**
5. Follow the setup wizard to configure DNS records

### 2. Deploy the Worker

1. Download the worker files:
   - `cloudflare-worker.js`
   - `wrangler.toml`

2. Install Wrangler CLI:

   ```bash
   npm install -g wrangler
   ```

3. Authenticate with Cloudflare:

   ```bash
   wrangler login
   ```

4. Edit wrangler.toml to set WEBHOOK_URL and CUSTOM_HEADERS, then set secrets:

   ```bash
   wrangler secret put WEBHOOK_SECRET
   # Enter: Your webhook secret from the plugin settings
   
   wrangler secret put FORWARD_EMAIL
   # Enter: admin@yoursite.com (optional - for email forwarding)
   
   # Allowed sender domains. REQUIRED if you FORWARD Interac emails through
   # your own mail provider (the only built-in trusted sender is the direct
   # address notify@payments.interac.ca). Add your forwarder's envelope
   # domain here. Comma-separated; a leading-dot entry (".example.com") also
   # matches subdomains.
   wrangler secret put SAFE_DOMAINS
   # Enter: mail.yourforwarder.com,.yourforwarder.com
   
   # Optional: Disable Interac domain check (debugging only)
   wrangler secret put DISABLE_INTERAC_CHECK
   # Enter: true
   ```

5. Deploy the worker:

   ```bash
   wrangler deploy
   ```

### 3. Configure Email Routing

1. In Cloudflare dashboard, go to **Email** → **Email Routing**
2. Click **Routing Rules**
3. Add a new rule:
   - **Matcher**: Custom address
   - **Address**: `etransfer@yourdomain.com` (or any address you prefer)
   - **Action**: Send to Worker
   - **Worker**: Select your deployed worker

### 4. Test the Setup

1. Send a test email from `notify@payments.interac.ca` to your configured address
2. Check the Worker logs in Cloudflare dashboard
3. Verify webhook delivery in your plugin's activity log

## Environment Variables

| Variable | Required | Description |
|----------|----------|-------------|
| `WEBHOOK_URL` | Yes | Your WordPress site's webhook endpoint |
| `WEBHOOK_SECRET` | Yes | HMAC signing secret from plugin settings |
| `FORWARD_EMAIL` | No | Email address to forward processed emails to |
| `CUSTOM_HEADERS` | No | JSON object with additional HTTP headers |

## Troubleshooting

### Worker Not Receiving Emails

- Check Email Routing DNS records are properly configured
- Verify routing rule is active and pointing to your worker
- Check worker deployment status

### Webhook Delivery Failures

- Verify `WEBHOOK_URL` is correct and accessible
- Check `WEBHOOK_SECRET` matches plugin settings
- Review worker logs for error details

### Custom Headers

- Set CUSTOM_HEADERS as JSON object: `{"Authorization":"Bearer token","X-Custom":"value"}`
- Headers are added to all webhook requests sent to your site
- Invalid JSON format will be logged as errors in Worker console

### Email Processing Issues

- Ensure emails are from `notify@payments.interac.ca` or configured safe domains
- Check email content format matches expected patterns
- Review worker console logs for parsing errors
- Verify `SAFE_DOMAINS` format if using custom domains

### Safe Domains Configuration

- Format: `domain.com,myservice.email,processor.org` (comma-separated)
- Spaces around commas are automatically trimmed
- Only the domain part is checked (e.g., `user@domain.com` matches `domain.com`)
- A leading-dot entry (e.g. `.example.com`) also matches any subdomain of it
- There is NO implicitly trusted forwarding provider. If you forward Interac
  mail through a third party (including shared-hosting forwarders), you MUST
  add that provider's envelope domain to `SAFE_DOMAINS` — scope it to your own
  forwarder, not to a whole shared host
- Set `DISABLE_INTERAC_CHECK=true` to bypass the sender check (debugging only)

## Security Notes

- By default, only the direct address `notify@payments.interac.ca` is trusted
- Use `SAFE_DOMAINS` to authorize the specific domain of your own forwarder.
  Avoid allowlisting a broad shared-hosting domain: any other customer of that
  host could otherwise deliver a forged Interac notification that the Worker
  would sign and forward
- The WordPress side additionally verifies the original Interac DKIM result
  from the forwarded authentication headers. By default this is log-only
  (non-breaking); set the "DKIM Enforcement" option to "Reject" in the plugin
  settings once you have confirmed your forwarder preserves the Interac DKIM
  signature
- Set `DISABLE_INTERAC_CHECK` to bypass domain restrictions (use with caution)
- All webhook requests are signed with HMAC SHA256
- Environment variables are encrypted in Cloudflare
- Consider enabling additional Cloudflare security features

## Support

For issues with this worker:

1. Check Cloudflare Worker logs
2. Review plugin webhook activity log
3. Verify all configuration steps were completed
4. Test with a known working e-Transfer email format
