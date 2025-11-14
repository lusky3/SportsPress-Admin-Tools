/**
 * Cloudflare Worker for e-Transfer Email Processing
 * 
 * This worker receives emails via Cloudflare Email Routing,
 * processes Interac e-Transfer notifications, and forwards
 * them as webhooks to SportsPress Admin Tools.
 * 
 * @author Cody (lusky3)
 */

export default {
  async email(message, env, ctx) {
    try {
      // Build email data first to check original sender
      const emailData = await buildEmailData(message, env);
      
      // Check if original sender is from safe domain
      if (!isFromSafeDomain(message.from, env) && !isFromSafeDomain(emailData.from.address, env)) {
        console.log('Rejected email from unsafe domain:', message.from, 'Original:', emailData.from.address);
        message.setReject('Not from a safe sender domain');
        return;
      }
      
      await sendWebhook(emailData, env, message);
    } catch (error) {
      console.error('Email processing error:', {
        message: String(error.message || error).replace(/[\r\n]/g, ' '),
        name: error.name,
        stack: error.stack?.replace(/[\r\n]/g, ' | ')
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
  
  // Build headers object - include authentication headers even in non-debug mode
  const allHeaders = {};
  const authHeaders = ['dkim-signature', 'authentication-results', 'arc-seal', 'arc-message-signature', 'arc-authentication-results', 'received-spf'];
  
  for (const [key, value] of message.headers) {
    const lowerKey = key.toLowerCase();
    // Always include authentication headers
    if (authHeaders.includes(lowerKey) || env.DEBUG) {
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
  
  console.log('Email Debug Info:', {
    from: message.from,
    to: message.to,
    originalFrom: originalFrom,
    debugMode: !!env.DEBUG,
    headerCount: Object.keys(allHeaders).length,
    rawContentLength: rawContent.length,
    emailBodyPreview: emailBody.substring(0, 300).replace(/[\r\n]/g, ' | ')
  });
  
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
  
  // Only include additional data in debug mode
  if (env.DEBUG) {
    emailData.html = emailBody;
    emailData.debug_headers = allHeaders;
  } else {
    // Include authentication headers in non-debug mode for security verification
    const authData = {};
    for (const [key, value] of Object.entries(allHeaders)) {
      const lowerKey = key.toLowerCase();
      if (authHeaders.includes(lowerKey)) {
        authData[key] = value;
      }
    }
    if (Object.keys(authData).length > 0) {
      emailData.auth_headers = authData;
    }
  }
  
  return emailData;
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

  const payload = JSON.stringify(emailData);
  const headers = await buildHeaders(payload, env.WEBHOOK_SECRET, env.CUSTOM_HEADERS);
  
  const response = await fetch(env.WEBHOOK_URL, {
    method: 'POST',
    headers,
    body: payload,
    redirect: 'manual'
  });

  await handleWebhookResponse(response, message, env);
}

async function buildHeaders(payload, secret, customHeaders) {
  const headers = {
    'Content-Type': 'application/json',
    'X-Signature': await createHmacSignature(payload, secret),
    'User-Agent': 'Cloudflare-Worker-Email-Processor/1.0'
  };

  if (customHeaders) {
    try {
      Object.assign(headers, JSON.parse(customHeaders));
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
      console.error('Webhook failed:', response.status, encodeURIComponent(responseText.replace(/[\r\n]/g, ' ').substring(0, 200)));
    } catch (textError) {
      console.error('Webhook failed:', response.status, 'Unable to read response');
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
 * Check if email is from a safe sender domain
 */
function isFromSafeDomain(fromAddress, env) {
  // If DISABLE_INTERAC_CHECK is set, skip the default check
  if (env.DISABLE_INTERAC_CHECK) {
    return true;
  }
  
  // Check default Interac domain
  if (fromAddress === 'notify@payments.interac.ca') {
    return true;
  }
  
  // Allow forwarded emails from MXRoute (common email forwarding service)
  if (fromAddress && fromAddress.includes('mxroute.com')) {
    return true;
  }
  
  // Check custom safe domains if configured
  if (env.SAFE_DOMAINS) {
    const safeDomains = env.SAFE_DOMAINS
      .split(',')
      .map(domain => domain.trim())
      .filter(domain => domain.length > 0);
    
    const emailDomain = fromAddress.split('@')[1];
    return safeDomains.includes(emailDomain);
  }
  
  return false;
}

/**
 * Extract email body from multipart content
 */
function extractEmailBody(rawContent) {
  // Look for plain text content first
  const textMatch = rawContent.match(/Content-Type: text\/plain[\s\S]*?\n\n([\s\S]*?)(?=\n--)/i);
  if (textMatch) {
    return textMatch[1].trim();
  }
  
  // Fallback to HTML content and strip tags
  const htmlMatch = rawContent.match(/Content-Type: text\/html[\s\S]*?\n\n([\s\S]*?)(?=\n--)/i);
  if (htmlMatch) {
    return htmlMatch[1].replace(/<[^>]*>/g, '').replace(/&[^;]+;/g, ' ').trim();
  }
  
  // Last resort: return raw content
  return rawContent;
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