# Cloudflare Access Service Token support — Implementation Plan

**Goal:** Let recognition providers reach an endpoint that sits behind Cloudflare Access (e.g. a self-hosted LiteLLM gateway at `litellm.lusk.app`) by sending an Access **service token** (`CF-Access-Client-Id` / `CF-Access-Client-Secret`) on the outbound request, in addition to the provider's own API key.

**Why:** Cloudflare Access is evaluated at the edge before the request reaches the origin, so a provider API key alone gets a 302 to the Access login and never hits the gateway. Access **service tokens** are Cloudflare's designated non-interactive-client mechanism (Zero Trust → Access → Service Auth). Today the plugin has no way to attach those headers: every provider funnels through `SPSS_Recognition_HTTP::request_with_retry()`, which posts a fixed header set with no extension point.

**Architecture:** Cloudflare Access is a *transport/gateway* concern, orthogonal to which LLM provider is chosen — it can front the OpenAI-compatible (OpenRouter) provider, the self-hosted sidecar, anything. So it belongs in the shared HTTP trait, keyed by destination host, **not** bolted onto one provider. The trait injects the two headers only when Access credentials are configured **and** the request URL's host matches the configured Access host — never leaking the secret to a different host (e.g. a confirmation provider hitting Anthropic direct). A filter wraps the header set so the behaviour is testable and future gateways can hook it.

**Tech Stack:** WordPress plugin PHP (WPCS 3.x), standalone echo-based PHP test harness (`run-all-tests.sh`). No JS/React changes.

**Spec:** none — this plan is the spec. Design approved in-session 2026-08-29.

## Global Constraints

- Plugin: `sportspress-score-sheets` (prefix `SPSS_`). Class files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and carry `@author Cody (lusky3)`.
- The client **secret** must be storable via a `wp-config` constant (`SPSS_CF_ACCESS_CLIENT_SECRET`) taking precedence over the option — mirrors the existing `key_constant()` pattern in `SPSS_Abstract_LLM_Provider` so a secret need never live in the DB.
- Secret option follows the existing masked-preservation pattern (`preserve_masked_key()`) so a "Save Changes" that re-posts the masked value does not wipe the stored secret.
- PHPCS must report **0 errors**. New test file MUST be registered in `run-all-tests.sh`.
- No behaviour change when Access is not configured — the header set is byte-identical to today.

---

## Design detail

### Config (three settings, provider-agnostic "Cloudflare Access" group in `class-admin.php`)
- `spss_cf_access_host` — the host the token applies to (e.g. `litellm.lusk.app`). Text; empty = feature off.
- `spss_cf_access_client_id` — `CF-Access-Client-Id` value. Text (not sensitive on its own, but treat as config).
- `spss_cf_access_client_secret` — `CF-Access-Client-Secret`. **Secret**: constant `SPSS_CF_ACCESS_CLIENT_SECRET` first, else option; masked in the UI; `preserve_masked_key()` on save.

### Injection (core, in `trait-recognition-http.php`)
A small private helper `cf_access_headers( string $url ): array`:
1. Resolve host = `wp_parse_url( $url, PHP_URL_HOST )`.
2. Read `spss_cf_access_host`; if empty or `!== $host` (case-insensitive), return `[]`.
3. Resolve id (option) + secret (constant-then-option). If either empty, return `[]`.
4. Return `[ 'CF-Access-Client-Id' => id, 'CF-Access-Client-Secret' => secret ]`.

In `request_with_retry()`, before the loop:
`$headers = apply_filters( 'spss_recognition_request_headers', array_merge( $headers, $this->cf_access_headers( $url ) ), $url );`

The `array_merge` puts CF headers alongside the provider's `Authorization`; the filter is the public seam (testable; future non-CF gateway auth can hook it). Host-match guard is the security boundary — asserted by tests.

### Admin surface (`class-admin.php`)
- Register the three settings in the existing settings group (same pattern as `spss_whatsapp_app_secret` et al.); secret uses `preserve_masked_key()`.
- Render a "Cloudflare Access (optional)" section on the settings page with a one-line explainer + the three fields; mask the secret; note the constant override.

### Docs (`readme.txt`)
- One paragraph: provider behind Cloudflare Access → create an Access **service token**, allow it in the app's policy, set host + client id here and the secret here or via `SPSS_CF_ACCESS_CLIENT_SECRET`.

---

## Tasks

1. **Trait** — add `cf_access_headers()` + the `array_merge` + filter line in `request_with_retry()`. (`includes/recognition/trait-recognition-http.php`)
2. **Admin settings** — register the three options (secret via `preserve_masked_key`) and render the "Cloudflare Access (optional)" section. (`includes/class-admin.php`)
3. **Tests** — new `tests/test-cf-access.php`, registered in `run-all-tests.sh`:
   - headers injected when host matches + both creds set;
   - **not** injected for a non-matching host (no secret leakage);
   - not injected when disabled (empty host) or creds missing;
   - constant overrides the option for the secret;
   - the `spss_recognition_request_headers` filter can add/override headers.
4. **Readme** — the paragraph above.
5. **Verify** — `bash run-all-tests.sh` green; `phpcs` 0 errors.

## Non-goals
- mTLS client-certificate Access auth (separate mechanism, rarely needed).
- A general reverse-proxy-auth framework — just service tokens; the filter is the seam for anything else later.
- This supersedes the throwaway mu-plugin used to unblock a same-day OCR test.
