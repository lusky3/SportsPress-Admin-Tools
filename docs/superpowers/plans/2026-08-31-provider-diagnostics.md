# Recognition provider diagnostics — Implementation Plan

**Goal:** When a recognition provider fails (bad key, wrong endpoint, unreachable network — the exact class of problem hit configuring LiteLLM on staging), an operator should see *why* without SSHing in and reading raw HTTP bodies by hand. Two parts: (1) capture and surface the real diagnostic detail on a failed sheet, in both UI surfaces; (2) a "Test connection" action per provider that validates config at settings time, before the first real sheet ever runs.

**Why now:** Diagnosing the staging LiteLLM setup required a manual probe script reading the response body directly — the plugin's own stored error was (and would have been) just "Recognition API returned HTTP 401.", discarding the vendor's actual message ("Invalid proxy server token passed... not found in db"). That gap is real and reproducible for every HTTP-based provider, not specific to LiteLLM.

**Architecture:**
- **Detail capture** lives in the shared HTTP trait (`SPSS_Recognition_HTTP`) — one change point benefits every provider that funnels through `request_with_retry()` (Claude, Gemini, OpenAI, OpenRouter, self-hosted). A new `extract_error_detail()` helper parses the common `{"error":{"message":...}}` / `{"error":"..."}` JSON shapes (OpenAI-compatible, Anthropic, Gemini all use one of these) and falls back to a bounded, HTML-stripped plain-text snippet. Appended to the existing generic "HTTP %d" message rather than replacing it.
- **Test connection** is a new interface method (`test_connection(): true|WP_Error`) — a single, unretried GET against a lightweight endpoint (a models-list endpoint where the vendor has one), reusing the same detail-extraction helper so a failed test reads exactly like a failed sheet would. This is a **breaking interface change**: every provider (all 5 are first-party/in-repo; no third-party implementers exist yet) must implement it. Documented in the changelog.
- **Self-hosted is different in kind**: the sidecar contract is a single `POST /v1/recognize` — no models-list or health endpoint is guaranteed. Its test is reachability-only (any HTTP response, even 404, proves the network path works), explicitly labeled as such in the UI rather than implying it validates the bearer token too.
- **UI**: wp-admin settings gets a per-provider "Test connection" button (plain `admin-post.php` GET + nonce + PRG-with-transient-result — mirrors the pattern already used in `class-league-table-generator.php`'s `RESULT_TRANSIENT_PREFIX`, not AJAX, matching this plugin's existing style). The wp-admin **queue** already renders `$s->error` inline (`class-admin.php:655`) — no change needed there beyond the message itself getting richer. The **React dashboard** queue currently shows nothing for a failed row but a Reprocess button; add the same error text there for parity.

**Tech Stack:** WordPress plugin PHP (WPCS 3.x), `@wordpress/element` React dashboard, standalone echo-based PHP test harness (`run-all-tests.sh`).

**Spec:** none — this plan is the spec. Confirmed with the user in-session 2026-08-31 (error surfacing) and (test-connection, bundled into the same PR).

## Global Constraints

- Plugin: `sportspress-score-sheets` (prefix `SPSS_`) for the PHP side; `sportspress-league-manager` (prefix `SPLM_`) for the React dashboard piece. Class files start with `if ( ! defined( 'ABSPATH' ) ) { exit; }` and carry `@author Cody (lusky3)`.
- All new UI is on the existing admin-only surfaces (`manage_options` for settings, `manage_sportspress`/dashboard REST for the queue) — same trust boundary as today; relaying a vendor's own error text back to that same audience is not a new exposure.
- Detail extraction must be bounded (truncate ~300 chars) and HTML-stripped before it ever reaches a UI — defends against a vendor error page dumping something huge or markup-bearing into the stored `error` column.
- PHPCS must report **0 errors**. New/changed tests MUST be registered in `run-all-tests.sh`.
- Rebuild the JS bundle with Node 24 (`mise exec node@24 -- npm run build`) and commit it; CI fails on drift.
- Follow the working agreement from this session: branch + PR, never push/merge without being asked; verify Codacy new-issues stays at 0 (the CF-Access PR's lesson — hold any new standalone-test shim state off `$GLOBALS`/static-property-subscripts).

## Tasks

1. **`trait-recognition-http.php`**: add `extract_error_detail( string $body, int $limit = 300 ): string` and a `probe_get( $url, array $headers, $timeout = 15 )` single-attempt GET helper (no retry — a "Test connection" click should return fast, not sit through 3× exponential backoff on a definite auth failure). Wire `extract_error_detail()` into the existing non-retryable-status branch of `request_with_retry()`, appended to the current generic message.
2. **`interface-recognition-provider.php`**: add `test_connection()` to the contract, documented as a cheap/free probe, not a billed `recognize()` call.
3. **`class-abstract-llm-provider.php`**: add `protected function probe_url(): string` (default `''`) and a shared `test_connection()` built on `probe_get()` + `extract_error_detail()`, mirroring the design already used for `recognize()`'s shared plumbing.
4. **Concrete hosted providers** — override `probe_url()`:
   - Claude: `https://api.anthropic.com/v1/models`
   - Gemini: `untrailingslashit( self::API_BASE )` (i.e. `.../v1beta/models`)
   - OpenAI: `https://api.openai.com/v1/models`
   - OpenRouter: `$this->base_url() . '/models'` (works against LiteLLM — verified manually on staging this session)
5. **`class-selfhosted-provider.php`**: its own `test_connection()` override — reachability-only GET to the endpoint root, explicit "this only confirms the sidecar is reachable, not that the bearer token is valid" in the returned message when it succeeds without a key, or the UI description text.
6. **`class-admin.php`**: `admin_post_spss_test_provider` handler (nonce + `manage_options`) calling the provider's `test_connection()`, storing a bounded pass/fail result in a short-lived transient keyed by provider id, PRG redirect back to settings. Render a "Test connection" button + the consumed transient's notice inline within each provider's settings block.
7. **React dashboard** (`sportspress-league-manager/src/dashboard/pages/ScoreSheets.jsx`): render the sheet's `error` text for a `failed` row (the dashboard REST already returns it — `class-dashboard-rest.php:402`). Rebuild the JS bundle.
8. **Tests**: extend/add to the existing `tests/test-recognition-providers.php` (or a new `tests/test-provider-diagnostics.php`) covering: JSON `{"error":{"message":...}}` extraction, plain-text fallback with HTML stripped and truncated, `probe_get()` success/failure classification, and each provider's `probe_url()` value. Register any new file in `run-all-tests.sh`.
9. **Readme note** on the new "Test connection" button and the self-hosted reachability-only caveat.
10. **Verify**: `bash run-all-tests.sh` green, `phpcs` 0 errors, `npm run build` (Node 24) drift-clean, Codacy new-issues 0 on the PR.

## Non-goals
- No general-purpose diagnostics dashboard, no polling/auto-retest, no notification/alerting on failure (this is a pull-based check, not push).
- No change to the failover/confirmation chain logic — diagnostics are additive, not a new recognition path.
- Not fixing the pre-existing `/reports/season-summary` playoff-counting bug or other unrelated known issues noted elsewhere.
