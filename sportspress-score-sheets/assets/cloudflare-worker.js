/**
 * Cloudflare Email Worker for SportsPress Score Sheets — Inbound Image Intake
 *
 * Receives an email via Cloudflare Email Routing, authorizes the envelope
 * sender against an operator-configured domain allowlist, extracts the FIRST
 * image attachment from the MIME body, and forwards it as an HMAC-signed JSON
 * webhook to the Score Sheets ingest endpoint. The image is then queued as
 * channel="email" and flows through the normal recognition → human review →
 * write pipeline (identical to an admin upload).
 *
 * This is the score-sheet counterpart to the e-Transfer worker: same skeleton,
 * same sender allowlisting, same HMAC signing approach — but it extracts an
 * image attachment instead of Interac notification text, and signs with the
 * header/scheme the Score Sheets plugin expects.
 *
 * ── Required environment variables ─────────────────────────────────────────
 *   WEBHOOK_URL              The plugin ingest endpoint. The operator sets this
 *                            to  https://SITE/wp-json/spss/v1/ingest  (HTTPS
 *                            only; the worker refuses non-HTTPS URLs).
 *   SPSS_WEBHOOK_SECRET      Shared secret. MUST match the secret configured in
 *                            WordPress at  Score Sheets → Settings. Used to
 *                            compute the HMAC-SHA256 request signature.
 *   ALLOWED_SENDER_DOMAINS   Comma-separated envelope-sender domain allowlist.
 *                            Each entry is an exact domain ("mail.example.com")
 *                            or a leading-dot wildcard (".example.com") that
 *                            also matches any subdomain. Email whose envelope
 *                            sender domain is not listed is rejected.
 *
 * ── Optional environment variables ─────────────────────────────────────────
 *   DEBUG                    "true" enables verbose console logging.
 *   DISABLE_SENDER_CHECK     "true" bypasses the domain allowlist (debug only).
 *
 * ── Signing scheme (must match includes/class-rest-api.php ingest_signature) ─
 *   signature = hex( HMAC_SHA256( key = SPSS_WEBHOOK_SECRET,
 *                                 msg = timestamp + "." + body ) )
 *   Headers sent with the POST:
 *     Content-Type:     application/json
 *     X-SPSS-Timestamp: <unix seconds>          (integer, string form)
 *     X-SPSS-Signature: <lowercase hex digest>
 *   The plugin recomputes the HMAC over "<timestamp>.<raw body>" and rejects
 *   requests older than its replay window (300s).
 *
 * ── Deployment ─────────────────────────────────────────────────────────────
 *   Deployed MANUALLY to Cloudflare (Email Routing → Email Worker), the same
 *   way as the e-Transfer worker — this file is not built or bundled by the
 *   plugin. Paste it into a Worker, set the env vars above as Worker
 *   Variables/Secrets, and point an Email Routing address at the Worker.
 *
 * Dependency-free modern Workers JS. HMAC via Web Crypto (crypto.subtle).
 *
 * @author Cody (lusky3)
 */

/**
 * Interpret a Worker environment variable as a boolean. Env vars are always
 * strings, so only the literal string "true" (case-insensitive, trimmed) is
 * treated as enabled. This prevents "false"/"0"/"no" — all truthy strings in
 * JavaScript — from silently enabling a flag.
 */
function isEnvTrue(value) {
  return typeof value === 'string' && value.trim().toLowerCase() === 'true';
}

export default {
  async email(message, env, ctx) {
    try {
      // Authorize ONLY on the envelope sender (message.from). Cloudflare Email
      // Routing sets this from the verified SMTP envelope; it is covered by
      // SPF/DKIM, unlike the free-text body "From:" line. A forwarded photo
      // arrives with the forwarder as the envelope sender, so operators add
      // their forwarder's domain to ALLOWED_SENDER_DOMAINS.
      if (!isFromSafeDomain(message.from, env)) {
        console.log('Rejected email from unsafe envelope domain:', message.from);
        message.setReject('Not from a safe sender domain');
        return;
      }

      if (!env.WEBHOOK_URL || !env.SPSS_WEBHOOK_SECRET) {
        console.error('Missing WEBHOOK_URL or SPSS_WEBHOOK_SECRET environment variables');
        message.setReject('Configuration error');
        return;
      }

      const url = new URL(env.WEBHOOK_URL);
      if (url.protocol !== 'https:') {
        console.error('Webhook URL must use HTTPS');
        message.setReject('Invalid webhook URL protocol');
        return;
      }

      const rawContent = await streamToString(message.raw);

      // Extract the first image attachment from the MIME body.
      const image = extractFirstImage(rawContent);
      if (!image) {
        // Graceful exit: an email with no image attachment is not an error,
        // it simply has nothing to ingest. Do not send a webhook.
        console.log('No image attachment found; nothing to ingest. Message-Id:',
          sanitize(message.headers?.get('message-id') || ''));
        return;
      }

      const messageId = message.headers?.get('message-id') || '';

      if (isEnvTrue(env.DEBUG)) {
        console.log('Score sheet email debug:', {
          from: message.from,
          to: message.to,
          messageId: sanitize(messageId),
          mediaType: image.mediaType,
          ext: image.ext,
          bytes: image.bytes.length
        });
      }

      const payload = {
        image_b64: bytesToBase64(image.bytes),
        media_type: image.mediaType,
        channel: 'email',
        source_ref: messageId,
        ext: image.ext
      };

      await sendWebhook(payload, env, message);
    } catch (error) {
      console.error('Email processing error:', {
        message: String(error.message || error).replaceAll(/[\r\n]/g, ' '),
        name: error.name,
        stack: error.stack?.replaceAll(/[\r\n]/g, ' | ')
      });
      message.setReject('Processing error');
    }
  }
};

/**
 * POST the signed JSON payload to the plugin ingest endpoint.
 */
async function sendWebhook(payloadObj, env, message) {
  const body = JSON.stringify(payloadObj);
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const signature = await createHmacSignature(timestamp + '.' + body, env.SPSS_WEBHOOK_SECRET);

  const response = await fetch(env.WEBHOOK_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-SPSS-Timestamp': timestamp,
      'X-SPSS-Signature': signature,
      'User-Agent': 'Cloudflare-Worker-ScoreSheet-Intake/1.0'
    },
    body,
    redirect: 'manual'
  });

  if (response.ok) {
    console.log('Score sheet webhook sent successfully');
  } else {
    try {
      const text = await response.text();
      console.error('Webhook failed:', response.status,
        encodeURIComponent(text.replaceAll(/[\r\n]/g, ' ').substring(0, 200)));
    } catch (textError) {
      console.error('Webhook failed:', response.status, 'Unable to read response:', textError.message);
    }
    message.setReject('Webhook processing failed');
  }
}

/**
 * Check if email is from a safe (authorized) sender domain.
 *
 * The allowlist is OPERATOR-CONFIGURABLE via ALLOWED_SENDER_DOMAINS
 * (comma-separated). Each entry is either an exact domain ("mail.example.com")
 * or a leading-dot wildcard (".example.com") that also matches subdomains.
 * There is no implicitly trusted forwarder — trust must be scoped to the
 * operator's own forwarding domain.
 */
function isFromSafeDomain(fromAddress, env) {
  // Debug bypass. Compared against the literal "true" so "false"/"0" cannot
  // accidentally enable it.
  if (isEnvTrue(env.DISABLE_SENDER_CHECK)) {
    return true;
  }

  const emailDomain = fromAddress?.split('@')[1];
  if (!emailDomain) {
    return false;
  }

  if (env.ALLOWED_SENDER_DOMAINS) {
    const safeDomains = env.ALLOWED_SENDER_DOMAINS
      .split(',')
      .map(d => d.trim().toLowerCase())
      .filter(d => d.length > 0);

    const domain = emailDomain.toLowerCase();
    for (const entry of safeDomains) {
      if (entry.startsWith('.')) {
        const base = entry.slice(1);
        if (domain === base || domain.endsWith('.' + base)) {
          return true;
        }
      } else if (domain === entry) {
        return true;
      }
    }
  }

  return false;
}

/**
 * Supported inline/attachment image content types mapped to a normalized
 * extension hint the plugin understands (jpg|png|webp|heic).
 */
const IMAGE_CONTENT_TYPES = {
  'image/jpeg': 'jpg',
  'image/jpg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
  'image/heic': 'heic',
  'image/heif': 'heic'
};

/**
 * Map a filename extension to a normalized extension hint, or null if it is not
 * a recognized image extension. Used to rescue attachments sent as the generic
 * application/octet-stream content type.
 */
function extFromFilename(filename) {
  if (!filename) return null;
  const m = filename.toLowerCase().match(/\.([a-z0-9]+)\s*$/);
  if (!m) return null;
  switch (m[1]) {
    case 'jpg':
    case 'jpeg':
      return 'jpg';
    case 'png':
      return 'png';
    case 'webp':
      return 'webp';
    case 'heic':
    case 'heif':
      return 'heic';
    default:
      return null;
  }
}

/**
 * Extract the FIRST image attachment from raw MIME content.
 *
 * Walks the MIME tree (recursing into nested multipart parts) and returns the
 * first part that is either:
 *   - a recognized image/* content type, or
 *   - application/octet-stream whose filename has an image extension.
 * The part body is base64-decoded to raw bytes.
 *
 * @returns {{bytes: Uint8Array, mediaType: string, ext: string}|null}
 */
function extractFirstImage(rawContent) {
  const splitPos = headerBodySplit(rawContent);
  if (splitPos.index === -1) return null;

  const topHeaders = rawContent.substring(0, splitPos.index);
  const body = rawContent.substring(splitPos.index + splitPos.len);

  const boundaryMatch = topHeaders.match(/boundary="?([^\s";]+)"?/i);
  if (!boundaryMatch) {
    // Single-part message: check whether the whole body is itself an image.
    return imageFromPart(topHeaders, body);
  }

  return searchMultipartForImage(body, boundaryMatch[1]);
}

/**
 * Recursively search a multipart body for the first image part.
 */
function searchMultipartForImage(body, boundary) {
  const parts = body.split('--' + boundary);

  for (const part of parts) {
    if (!part || part.startsWith('--')) continue;

    const split = headerBodySplit(part);
    if (split.index === -1) continue;

    const partHeaders = part.substring(0, split.index);
    const partBody = part.substring(split.index + split.len);

    // Recurse into nested multipart parts.
    const nestedBoundary = partHeaders.match(/boundary="?([^\s";]+)"?/i);
    if (nestedBoundary) {
      const nested = searchMultipartForImage(partBody, nestedBoundary[1]);
      if (nested) return nested;
      continue;
    }

    const image = imageFromPart(partHeaders, partBody);
    if (image) return image;
  }

  return null;
}

/**
 * If the given MIME part is an image attachment, decode and return it.
 * Otherwise return null.
 *
 * @returns {{bytes: Uint8Array, mediaType: string, ext: string}|null}
 */
function imageFromPart(partHeaders, partBody) {
  const ctMatch = partHeaders.match(/Content-Type:\s*([^;\r\n]+)/i);
  if (!ctMatch) return null;
  const contentType = ctMatch[1].trim().toLowerCase();

  const filename = parseAttachmentFilename(partHeaders);

  let mediaType = null;
  let ext = null;

  if (IMAGE_CONTENT_TYPES[contentType]) {
    mediaType = contentType === 'image/jpg' ? 'image/jpeg'
      : contentType === 'image/heif' ? 'image/heic'
      : contentType;
    ext = IMAGE_CONTENT_TYPES[contentType];
  } else if (contentType === 'application/octet-stream') {
    // Accept octet-stream only when the filename looks like an image.
    ext = extFromFilename(filename);
    if (!ext) return null;
    mediaType = ext === 'jpg' ? 'image/jpeg'
      : ext === 'png' ? 'image/png'
      : ext === 'webp' ? 'image/webp'
      : 'image/heic';
  } else {
    return null;
  }

  // Image attachments are base64-encoded in practice; only base64 yields valid
  // raw bytes. Anything else is treated as not-an-image.
  const encoding = (partHeaders.match(/Content-Transfer-Encoding:\s*(\S+)/i) || [])[1];
  if (!encoding || encoding.toLowerCase() !== 'base64') {
    return null;
  }

  const bytes = base64ToBytes(partBody.replace(/\s/g, ''));
  if (!bytes || bytes.length === 0) return null;

  return { bytes, mediaType, ext };
}

/**
 * Parse the attachment filename from Content-Disposition or the Content-Type
 * "name=" parameter across the part's header block.
 */
function parseAttachmentFilename(partHeaders) {
  const disp = partHeaders.match(/filename="?([^"\r\n;]+)"?/i);
  if (disp) return disp[1].trim();
  const nameParam = partHeaders.match(/name="?([^"\r\n;]+)"?/i);
  if (nameParam) return nameParam[1].trim();
  return '';
}

/**
 * Find the header/body split (first blank line). Returns the index and the
 * length of the separator (\r\n\r\n = 4, \n\n = 2), or index -1 if none.
 */
function headerBodySplit(text) {
  const crlf = text.indexOf('\r\n\r\n');
  const lf = text.indexOf('\n\n');
  if (crlf !== -1 && (lf === -1 || crlf < lf)) {
    return { index: crlf, len: 4 };
  }
  if (lf !== -1) {
    return { index: lf, len: 2 };
  }
  return { index: -1, len: 0 };
}

/**
 * Convert ReadableStream to string.
 */
async function streamToString(stream) {
  const decoder = new TextDecoder();
  const reader = stream.getReader();
  const chunks = [];

  try {
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      chunks.push(decoder.decode(value, { stream: true }));
    }
    chunks.push(decoder.decode()); // Flush any remaining bytes.
  } catch (error) {
    console.error('Stream reading error: Failed to process email stream');
    throw new Error(`Failed to read email stream: ${error.message}`);
  } finally {
    reader.releaseLock();
  }

  return chunks.join('');
}

/**
 * Decode a base64 string to raw bytes (Uint8Array). Returns null on failure.
 */
function base64ToBytes(b64) {
  try {
    const binary = atob(b64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    return bytes;
  } catch (e) {
    return null;
  }
}

/**
 * Encode raw bytes (Uint8Array) to a base64 string.
 */
function bytesToBase64(bytes) {
  let binary = '';
  const chunkSize = 0x8000; // Avoid arg-count limits on String.fromCharCode.
  for (let i = 0; i < bytes.length; i += chunkSize) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
  }
  return btoa(binary);
}

/**
 * Strip CR/LF from a value before logging to prevent log injection.
 */
function sanitize(value) {
  return String(value).replaceAll(/[\r\n]/g, ' ');
}

/**
 * Create an HMAC-SHA256 signature, returned as a lowercase hex string.
 * Signs `data` (which callers build as "<timestamp>.<body>") with `secret`.
 */
async function createHmacSignature(data, secret) {
  const encoder = new TextEncoder();
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );

  const signature = await crypto.subtle.sign('HMAC', cryptoKey, encoder.encode(data));
  const hashArray = Array.from(new Uint8Array(signature));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}
