# Remote Score Sheet Intake

SportsPress Score Sheets accepts photographed score sheets from three remote
channels. All of them converge on a single ingest funnel and then follow the
**same** path as an in-admin upload:

```
Webhook  ─┐
Email    ─┼─▶  POST /spss/v1/ingest ──▶ queue ──▶ recognition ──▶ HUMAN REVIEW ──▶ write to SportsPress
SMS/MMS  ─┘   (Twilio route: /spss/v1/twilio)
```

> **Every remote submission is untrusted until the mandatory human review.**
> Recognition results are never written to SportsPress automatically — an
> operator must review and confirm each sheet, exactly as with a manual upload.
> The channels below only differ in how the image *arrives* at the queue.

The image is queued with a `channel` tag (`upload`, `email`, or `mms`) and an
optional `source_ref` (email Message-ID or Twilio message SID) for traceability.

---

## 1. Webhook — `POST /wp-json/spss/v1/ingest`

Direct, signed HTTP ingest. Use this for custom integrations, scripts, or any
external system that already has the image bytes.

### Request body (JSON)

```json
{
  "image_b64":  "<base64 of the raw image bytes>",
  "media_type": "image/jpeg",
  "channel":    "email",
  "source_ref": "<optional external id>",
  "ext":        "jpg"
}
```

- `image_b64` — base64 of the raw image file (required).
- `media_type` — one of `image/jpeg`, `image/png`, `image/webp`, `image/heic`.
- `ext` — normalized extension hint: `jpg` | `png` | `webp` | `heic`.
- `channel` — free-form channel tag (e.g. `email`, `mms`, or your own).
- `source_ref` — optional external identifier stored with the sheet.

### Authentication (HMAC)

Requests are authenticated with an HMAC-SHA256 signature over the timestamp and
the raw request body. Two headers are required (plus `Content-Type`):

| Header | Value |
| --- | --- |
| `Content-Type` | `application/json` |
| `X-SPSS-Timestamp` | Unix time in **seconds** (integer) |
| `X-SPSS-Signature` | lowercase hex `HMAC_SHA256(secret, "<timestamp>.<body>")` |

The signed string is `timestamp + "." + body` — the exact raw bytes of the JSON
body, concatenated after the same timestamp value sent in `X-SPSS-Timestamp`.

- **Secret**: set in WordPress at **Score Sheets → Settings**. The caller and
  the plugin must share the same secret.
- **Replay window**: requests whose `X-SPSS-Timestamp` is more than **300
  seconds** (5 minutes) from the server clock are rejected. Sign and send
  promptly; do not reuse a signature.

### `curl` example

Signs and posts a base64-encoded image. Requires `bash`, `openssl`, and
`base64`.

```bash
#!/usr/bin/env bash
set -euo pipefail

SITE="https://example.com"
SECRET="the-secret-from-score-sheets-settings"
IMAGE="scoresheet.jpg"

# Build the JSON body with the base64 image inline.
IMG_B64="$(base64 -w0 "$IMAGE")"
BODY="$(printf '{"image_b64":"%s","media_type":"image/jpeg","channel":"webhook","source_ref":"cli-demo","ext":"jpg"}' "$IMG_B64")"

# Sign: HMAC-SHA256 hex over "<timestamp>.<body>".
TS="$(date +%s)"
SIG="$(printf '%s.%s' "$TS" "$BODY" \
  | openssl dgst -sha256 -hmac "$SECRET" -binary \
  | xxd -p -c 256)"

curl -sS -X POST "$SITE/wp-json/spss/v1/ingest" \
  -H "Content-Type: application/json" \
  -H "X-SPSS-Timestamp: $TS" \
  -H "X-SPSS-Signature: $SIG" \
  --data-binary "$BODY"
```

> The signature covers the body **byte-for-byte**. Sign the exact bytes you
> send on the wire (`--data-binary` above), not a re-serialized copy.

---

## 2. Inbound Email — Cloudflare Email Worker

Let people email a photo of a score sheet to a dedicated address. A Cloudflare
Email Worker parses the message, pulls out the first image attachment, and
forwards it to the webhook above as `channel=email`.

### Deploy

1. In Cloudflare, create an **Email Worker** and paste in
   [`assets/cloudflare-worker.js`](./cloudflare-worker.js). It is deployed
   **manually** (not bundled by the plugin), the same way as the e-Transfer
   worker.
2. Set the Worker environment variables:

   | Variable | Purpose |
   | --- | --- |
   | `WEBHOOK_URL` | `https://SITE/wp-json/spss/v1/ingest` (HTTPS only) |
   | `SPSS_WEBHOOK_SECRET` | Must **match** the secret in Score Sheets → Settings |
   | `ALLOWED_SENDER_DOMAINS` | Comma-separated envelope-sender allowlist |

   `ALLOWED_SENDER_DOMAINS` entries are exact domains (`mail.example.com`) or a
   leading-dot wildcard (`.example.com`, which also matches subdomains). Email
   from any other envelope-sender domain is rejected. When mail is forwarded,
   add the **forwarder's** domain here.
3. Under **Email Routing**, point an address (e.g.
   `scoresheets@yourdomain.com`) at the Worker.

### Flow

Photo emailed in → Worker authorizes the envelope sender → extracts the first
image attachment (`image/jpeg`, `image/png`, `image/webp`, `image/heic`, or an
`application/octet-stream` attachment with an image filename) → base64-encodes
the raw bytes → signs and POSTs to the ingest endpoint → queued as
`channel=email` with the email's `Message-ID` as `source_ref`. If an email has
no image attachment, the Worker logs and exits without calling the webhook.

---

## 3. SMS / MMS — Twilio

Let people text a photo of a score sheet to a Twilio number.

### Configure

1. In the Twilio Console, open your number's **Messaging** configuration and set
   the incoming-message webhook to:

   ```
   https://SITE/wp-json/spss/v1/twilio
   ```

2. In WordPress at **Score Sheets → Settings**, enter your Twilio **Account
   SID** and **Auth Token**.

### Validation & flow

- Twilio signs each request with `X-Twilio-Signature`; the plugin validates it
  using your Auth Token. **The webhook URL must match exactly** — Twilio signs
  the full URL (scheme, host, path, and any query string), so a mismatch
  (http vs https, trailing slash, proxy-rewritten host) fails validation.
- On a valid request, the plugin downloads the image from `MediaUrl0` and
  queues it as `channel=mms`, using the Twilio message SID as `source_ref`.

---

## After intake

Regardless of channel, the queued image runs through recognition and then waits
in the review queue. Nothing is written to SportsPress until an operator opens
the sheet, checks the extracted values against the photo, and confirms — the
same human gate that applies to every manual upload.
