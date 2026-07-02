/**
 * Cloudflare Worker for e-Transfer Email Processing
 *
 * This worker receives emails via Cloudflare Email Routing,
 * processes Interac e-Transfer notifications, and forwards
 * them as webhooks to SportsPress Admin Tools.
 *
 * @author Cody (lusky3)
 */

/**
 * Interpret a Worker environment variable as a boolean. Env vars are always
 * strings, so only the literal string "true" (case-insensitive, trimmed) is
 * treated as enabled. This prevents "false"/"0"/"no" — all of which are truthy
 * strings in JavaScript — from silently enabling a flag (M2).
 */
function isEnvTrue(value) {
  return typeof value === 'string' && value.trim().toLowerCase() === 'true';
}

export default {
  async email(message, env, ctx) {
    try {
      // Authorize ONLY on the envelope sender (message.from). Cloudflare Email
      // Routing sets this from the verified SMTP envelope and it is covered by
      // SPF/DKIM — unlike the body "From:" line, which is free text an attacker
      // can forge. A forwarded Interac notification arrives with the forwarder
      // as the envelope sender (e.g. *.mxroute.com), so legitimate forwarding
      // still passes; operators add their forwarder to SAFE_DOMAINS rather than
      // relying on the body. Do NOT OR-in the body-parsed address here: doing so
      // lets anyone who can deliver mail to this address forge a
      // "From: notify@payments.interac.ca" body and have it signed + forwarded
      // to WordPress, where it can auto-complete a WooCommerce order.
      if (!isFromSafeDomain(message.from, env)) {
        console.log('Rejected email from unsafe envelope domain:', message.from);
        message.setReject('Not from a safe sender domain');
        return;
      }

      // Build email data after the envelope has been authorized.
      const emailData = await buildEmailData(message, env);
      
      await sendWebhook(emailData, env, message);
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

async function buildEmailData(message, env) {
  const rawContent = await streamToString(message.raw);
  
  // Extract email body from multipart content
  const emailBody = extractEmailBody(rawContent);
  
  // Parse original sender from email body or headers
  const originalFrom = parseOriginalSender(rawContent, message.headers);
  
  const debug = isEnvTrue(env.DEBUG);

  // Authentication headers forwarded to WordPress for DKIM verification.
  //
  // SECURITY (H1): the ARC-* family (ARC-Seal, ARC-Message-Signature,
  // ARC-Authentication-Results) is deliberately STRIPPED and never forwarded —
  // not even as debug data. ARC is designed to carry authentication results
  // verbatim across forwarding hops, so a sender who can deliver mail through an
  // allowlisted forwarder can embed a forged
  // "ARC-Authentication-Results: ...; dkim=pass header.d=interac.ca" that would
  // survive forwarding intact and masquerade as a trusted result. Only the
  // Authentication-Results header is forwarded; the WordPress side trusts a
  // dkim=pass ONLY inside the A-R instance whose leading authserv-id EXACTLY
  // matches the operator-pinned spet_dkim_authserv_id. Operators' forwarders
  // MUST strip any inbound A-R bearing their own authserv-id before stamping
  // their own (RFC 8601 §5), or the pin is spoofable.
  const strippedAuthHeaders = ['arc-seal', 'arc-message-signature', 'arc-authentication-results'];
  const allHeaders = {};
  const authHeaders = ['dkim-signature', 'authentication-results', 'received-spf'];

  for (const [key, value] of message.headers) {
    const lowerKey = key.toLowerCase();
    if (strippedAuthHeaders.includes(lowerKey)) {
      continue; // Never forward attacker-forgeable ARC headers.
    }
    // Always include authentication headers; include everything else only in debug.
    if (authHeaders.includes(lowerKey) || debug) {
      allHeaders[key] = value;
    }
  }
  
  // Always include essential headers
  const essentialHeaders = ['from', 'reply-to', 'to', 'subject', 'date', 'message-id'];
  for (const header of essentialHeaders) {
    const value = message.headers?.get(header);
    if (value && !allHeaders[header]) {
      allHeaders[header] = value;
    }
  }
  
  if (debug) {
    console.log('Email Debug Info:', {
      from: message.from,
      to: message.to,
      originalFrom: originalFrom,
      headerCount: Object.keys(allHeaders).length,
      rawContentLength: rawContent.length,
      emailBodyPreview: emailBody.substring(0, 300).replaceAll(/[\r\n]/g, ' | ')
    });
  }
  
  const emailData = {
    from: {
      address: originalFrom.address || message.from || '',
      name: originalFrom.name || 'Interac e-Transfer'
    },
    reply_to: {
      address: parseEmailAddress(message.headers?.get('reply-to')),
      name: parseEmailName(message.headers?.get('reply-to'))
    },
    to: message.to || '',
    subject: message.headers?.get('subject') || '',
    date: message.headers?.get('date') || new Date().toISOString(),
    text: emailBody
  };
  
  // Always forward the trusted authentication headers so DKIM verification can
  // run regardless of DEBUG mode (M3). In debug mode, add extra diagnostic data
  // additively rather than in place of the auth headers.
  appendAuthHeaders(emailData, allHeaders, authHeaders);
  if (debug) {
    emailData.html = emailBody;
    emailData.debug_headers = allHeaders;
  }

  return emailData;
}

/**
 * Append the original email authentication headers (DKIM-Signature,
 * Authentication-Results, Received-SPF) to the webhook payload under
 * emailData.auth_headers. ARC-* headers are intentionally NOT forwarded (see
 * the strippedAuthHeaders note in buildEmailData). The WordPress side reads
 * these and verifies that the Interac sender domain produced a passing DKIM
 * result inside an A-R instance whose authserv-id matches the operator-pinned
 * value (see SPET_ETransfer_Automation::verify_email_authentication).
 * Enforcement is controlled server-side by the spet_dkim_enforcement option
 * (log vs reject).
 */
function appendAuthHeaders(emailData, allHeaders, authHeaders) {
  const authData = {};
  for (const [key, value] of Object.entries(allHeaders)) {
    if (authHeaders.includes(key.toLowerCase())) {
      authData[key] = value;
    }
  }
  if (Object.keys(authData).length > 0) {
    emailData.auth_headers = authData;
  }
}

async function sendWebhook(emailData, env, message) {
  if (!env.WEBHOOK_URL || !env.WEBHOOK_SECRET) {
    console.error('Missing WEBHOOK_URL or WEBHOOK_SECRET environment variables');
    message.setReject('Configuration error');
    return;
  }

  const url = new URL(env.WEBHOOK_URL);
  if (!url.protocol.startsWith('https')) {
    console.error('Webhook URL must use HTTPS');
    message.setReject('Invalid webhook URL protocol');
    return;
  }

  emailData.timestamp = new Date().toISOString();
  const payload = JSON.stringify(emailData);
  const headers = await buildHeaders(payload, env.WEBHOOK_SECRET, env.CUSTOM_HEADERS, emailData.timestamp);
  
  const response = await fetch(env.WEBHOOK_URL, {
    method: 'POST',
    headers,
    body: payload,
    redirect: 'manual'
  });

  await handleWebhookResponse(response, message, env);
}

async function buildHeaders(payload, secret, customHeaders, timestamp) {
  const headers = {
    'Content-Type': 'application/json',
    'X-Signature': await createHmacSignature(timestamp + '.' + payload, secret),
    'X-Timestamp': timestamp,
    'User-Agent': 'Cloudflare-Worker-Email-Processor/1.0'
  };

  if (customHeaders) {
    try {
      // customHeaders is env.CUSTOM_HEADERS — operator-set Worker config (trusted,
      // deploy-time), merged into an outbound request header object, never a
      // user-facing response. Not attacker-controlled mass assignment.
      Object.assign(headers, JSON.parse(customHeaders)); // nosemgrep
    } catch (e) {
      console.error('Invalid CUSTOM_HEADERS JSON:', e.message, 'Value:', customHeaders);
    }
  }

  return headers;
}

async function handleWebhookResponse(response, message, env) {
  if (response.ok) {
    console.log('Webhook sent successfully');
    if (env.FORWARD_EMAIL) {
      message.forward(env.FORWARD_EMAIL);
    }
  } else {
    try {
      const responseText = await response.text();
      console.error('Webhook failed:', response.status, encodeURIComponent(responseText.replaceAll(/[\r\n]/g, ' ').substring(0, 200)));
    } catch (textError) {
      console.error('Webhook failed:', response.status, 'Unable to read response:', textError.message);
    }
    message.setReject('Webhook processing failed');
  }
}

/**
 * Convert ReadableStream to string
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
    chunks.push(decoder.decode()); // Flush any remaining bytes
  } catch (error) {
    console.error('Stream reading error: Failed to process email stream');
    throw new Error(`Failed to read email stream: ${error.message}`);
  } finally {
    reader.releaseLock();
  }
  
  return chunks.join('');
}

/**
 * Parse email address from header (e.g., "John Smith <john@example.com>" -> "john@example.com")
 */
function parseEmailAddress(header) {
  if (!header) return '';
  const match = header.match(/<([^>]+)>/);
  return match ? match[1] : header.trim();
}

/**
 * Parse email name from header (e.g., "John Smith <john@example.com>" -> "John Smith")
 */
function parseEmailName(header) {
  if (!header) return '';
  const match = header.match(/^([^<]+)</);
  return match ? match[1].trim() : '';
}

/**
 * Check if email is from a safe (authorized) sender domain.
 *
 * The allowlist is intentionally OPERATOR-CONFIGURABLE: there is no implicitly
 * trusted shared-hosting forwarder. The only built-in trusted sender is the
 * direct Interac notification address. To accept FORWARDED Interac mail (the
 * common case), the operator must add their own forwarder's envelope domain to
 * the SAFE_DOMAINS environment variable (comma-separated). A leading-dot entry
 * (e.g. ".example.com") also matches subdomains of that domain.
 *
 * Rationale: a previous build implicitly trusted any *.mxroute.com sender. On a
 * shared host that means ANY customer of that provider could deliver a forged
 * Interac body that the Worker would then sign and forward. Trust must instead
 * be scoped to the operator's specific forwarder.
 */
function isFromSafeDomain(fromAddress, env) {
  // If DISABLE_INTERAC_CHECK is set, skip the default check (debugging flag).
  // Compared explicitly against the literal "true" so that setting it to
  // "false"/"0" does NOT accidentally enable the bypass (M2).
  if (isEnvTrue(env.DISABLE_INTERAC_CHECK)) {
    return true;
  }

  // Built-in: the direct Interac notification address.
  if (fromAddress === 'notify@payments.interac.ca') {
    return true;
  }

  const emailDomain = fromAddress?.split('@')[1];
  if (!emailDomain) {
    return false;
  }

  // Operator-configured allowlist. Each entry is either an exact domain
  // ("mail.example.com") or a leading-dot wildcard (".example.com") that also
  // matches any subdomain. Operators populate this with their own forwarder.
  if (env.SAFE_DOMAINS) {
    const safeDomains = env.SAFE_DOMAINS
      .split(',')
      .map(d => d.trim().toLowerCase())
      .filter(d => d.length > 0);

    const domain = emailDomain.toLowerCase();
    for (const entry of safeDomains) {
      if (entry.startsWith('.')) {
        // ".example.com" matches example.com and any subdomain of it.
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
 * Extract email body from multipart content
 */
function extractEmailBody(rawContent) {
  // Split headers from body on first double newline
  const crlfSplit = rawContent.indexOf('\r\n\r\n');
  const lfSplit = rawContent.indexOf('\n\n');
  const splitPos = crlfSplit !== -1 ? crlfSplit : lfSplit;
  if (splitPos === -1) return rawContent;

  const topHeaders = rawContent.substring(0, splitPos);
  const body = rawContent.substring(splitPos + (crlfSplit !== -1 ? 4 : 2));

  // Check if multipart
  const boundaryMatch = topHeaders.match(/boundary="?([^\s";]+)"?/i);
  if (boundaryMatch) {
    const result = extractFromMultipart(body, boundaryMatch[1]);
    if (result) return result;
  }

  // Not multipart - decode the single-part body
  const ctMatch = topHeaders.match(/Content-Type:\s*text\/(plain|html)/i);
  if (ctMatch) {
    const decoded = decodeBody(body.trim(), topHeaders);
    if (ctMatch[1].toLowerCase() === 'html') {
      return decoded.replaceAll(/<[^>]*>/g, '').replaceAll(/&[^;]+;/g, ' ').trim();
    }
    return decoded;
  }

  // Fallback: return body as-is
  return body.trim() || rawContent;
}

/**
 * Extract text from a multipart MIME part, handling nested multipart
 */
function extractFromMultipart(body, boundary) {
  const parts = body.split('--' + boundary);
  let plainText = null;
  let htmlText = null;

  for (const part of parts) {
    if (!part || part.startsWith('--')) continue;

    // Split part headers from part body
    const crlfSplit = part.indexOf('\r\n\r\n');
    const lfSplit = part.indexOf('\n\n');
    const pSplitPos = crlfSplit !== -1 ? crlfSplit : lfSplit;
    if (pSplitPos === -1) continue;

    const partHeaders = part.substring(0, pSplitPos);
    const partBody = part.substring(pSplitPos + (crlfSplit !== -1 ? 4 : 2)).trim();

    // Handle nested multipart (e.g. multipart/alternative inside multipart/mixed)
    const nestedBoundary = partHeaders.match(/boundary="?([^\s";]+)"?/i);
    if (nestedBoundary) {
      const nested = extractFromMultipart(partBody, nestedBoundary[1]);
      if (nested) return nested;
      continue;
    }

    const ctMatch = partHeaders.match(/Content-Type:\s*text\/(plain|html)/i);
    if (!ctMatch) continue;

    const decoded = decodeBody(partBody, partHeaders);
    if (ctMatch[1].toLowerCase() === 'plain') {
      plainText = decoded;
    } else if (!htmlText) {
      htmlText = decoded;
    }
  }

  if (plainText) return plainText;
  if (htmlText) {
    return htmlText.replaceAll(/<[^>]*>/g, '').replaceAll(/&[^;]+;/g, ' ').trim();
  }
  return null;
}

/**
 * Decode email body based on Content-Transfer-Encoding
 */
function decodeBody(body, headers) {
  const encodingMatch = headers.match(/Content-Transfer-Encoding:\s*(\S+)/i);
  if (!encodingMatch) return body;
  
  const encoding = encodingMatch[1].toLowerCase();
  if (encoding === 'base64') {
    try {
      return atob(body.replace(/\s/g, ''));
    } catch (e) {
      return body;
    }
  }
  if (encoding === 'quoted-printable') {
    return body
      .replace(/=\r?\n/g, '')
      .replace(/=([0-9A-Fa-f]{2})/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
  }
  return body;
}

/**
 * Parse original sender from email headers in raw content
 */
function parseOriginalSender(rawContent, headers) {
  // Look for original From header in raw content (before forwarding)
  const fromMatch = rawContent.match(/^From: (.+)$/m);
  if (fromMatch) {
    const fromHeader = fromMatch[1];
    return {
      address: parseEmailAddress(fromHeader),
      name: parseEmailName(fromHeader)
    };
  }
  
  // Fallback to current headers
  const currentFrom = headers?.get('from') || '';
  return {
    address: parseEmailAddress(currentFrom),
    name: parseEmailName(currentFrom)
  };
}

/**
 * Create HMAC SHA256 signature
 */
async function createHmacSignature(data, secret) {
  const encoder = new TextEncoder();
  const keyData = encoder.encode(secret);
  const messageData = encoder.encode(data);
  
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    keyData,
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  
  const signature = await crypto.subtle.sign('HMAC', cryptoKey, messageData);
  const hashArray = Array.from(new Uint8Array(signature));
  return hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
}