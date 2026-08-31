=== SportsPress Score Sheets ===
Contributors: lusky3
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Ingest photos of hand-filled score sheets, read them with a pluggable recognition backend, review the results, and apply them to SportsPress events.

== Description ==

A child module of SportsPress Admin Tools. Submit a phone photo of a paper hockey score sheet — by **admin upload**, **HMAC webhook**, **emailed photo** (via a Cloudflare Worker), or **Twilio SMS/MMS** (see assets/remote-intake.md); all channels land in the same review queue. the plugin sends it to a recognition provider (Claude vision by default), reads the final score and per-player stats, runs consistency checks (player goals must sum to the team score, jersey numbers must match the roster), and presents the extracted values next to the image for an administrator to review and correct. Nothing is written to SportsPress until a human confirms.

On confirmation, the event's score and outcome (win/loss/tie, plus OT/SO loss) and each player's box-score stats (goals, assists, PIM, …) are written to the SportsPress event. Standings and player totals update automatically.

Recognition is pluggable. Built-in providers: **Claude vision** (Anthropic), **Gemini vision** (Google), **GPT vision** (OpenAI), an **LLM aggregator** (OpenRouter and other OpenAI-compatible gateways such as Together, Groq, Fireworks, or a self-hosted LiteLLM/vLLM proxy — one API key, any routed vision model), and a **self-hosted** option that POSTs to a local recognition sidecar (a GPU vision-language model via Ollama/vLLM, or a CPU PaddleOCR-VL service) so images never leave your infrastructure. Pick a Primary and an optional Secondary (cross-check) provider under Settings; disagreements between the two are flagged for review. Further backends can be registered via the `spss_register_recognition_providers` filter. Each provider is inert until you configure its key/endpoint. Model ids are editable settings — verify the current model in your provider's console. If a provider's endpoint sits behind **Cloudflare Access** (e.g. a self-hosted gateway on a `*.example.com` host), create an Access service token, allow it in that application's policy, and enter the host + credentials under **Score Sheets → Settings → Cloudflare Access** (the client secret may also be set via the `SPSS_CF_ACCESS_CLIENT_SECRET` constant in wp-config.php). The `CF-Access-Client-Id`/`CF-Access-Client-Secret` headers are then sent only on requests to that host, alongside the provider's own API key. Each configured provider has a **Test connection** button on the Settings page — a free, unbilled connectivity + auth check (a models-list request for the hosted vendors), so a bad key or wrong endpoint is caught right there instead of on the first real sheet. The self-hosted provider's test can only confirm the sidecar is reachable, not that its bearer token is valid, since its only contract is a single inference endpoint with no lightweight one to check auth against. When recognition does fail on a real sheet, the specific reason (not just an HTTP status) is shown next to the sheet in both the wp-admin queue and the dashboard's Sheets page.

= Privacy =

When using a hosted recognition provider (e.g. Claude), the uploaded image is transmitted to that provider for transcription. Uploaded images have their metadata (including any GPS/EXIF data) stripped on ingest, are stored in a non-public directory, are served only to authenticated administrators, and are deleted once applied to an event (and by a retention cron thereafter). Configure your provider and retention window under **Score Sheets → Settings**.

== Installation ==

1. Install and activate SportsPress Admin Tools (the parent framework) and SportsPress.
2. Activate this plugin, then enable the "Score Sheets" module in SportsPress Admin Tools settings.
3. Under **Score Sheets → Settings**, choose a recognition provider and add its API key.

== Changelog ==

= 1.0.0 =
* Initial release: manual image upload, pluggable recognition (Claude vision), consistency checks, human review, and SportsPress event/stat write. MMS and inbound-email ingestion are planned to reuse the same pipeline.
