=== SportsPress FreeScout Bridge ===
Contributors: lusky3
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 8.1
Requires Plugins: sportspress-admin-tools
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The signed REST API and event outbox that connect a FreeScout helpdesk to your SportsPress league.

== Description ==

A child module of SportsPress Admin Tools. It lets the FreeScout SPAT bridge show league data (player, registration, e-Transfer and waitlist sections) in a ticket's sidebar, run actions on a customer's behalf, and receive signed events when something happens that an agent would want to see on the ticket.

Requests are authenticated with a shared secret (an HMAC over the timestamp and body), not a WordPress session. The REST routes live under `spat/v1/freescout`; that namespace is kept so an existing FreeScout setup keeps working without reconfiguration.

This code used to ship inside SportsPress Admin Tools. Options, the outbox table, cron hooks, REST routes and filters are unchanged, so nothing needs to be migrated or reconfigured.

Settings are on their own tab under Settings, SportsPress Admin Tools: the shared secret, the events URL, the Send Events switch and the outbox status.

Activating the plugin turns the "FreeScout Bridge" module on.

== Installation ==

1. Install and activate SportsPress Admin Tools (the parent framework).
2. Install and activate this plugin. It does nothing while the installed SportsPress Admin Tools is older than 1.3.0, because that version and earlier still carry their own copy of the bridge; it takes over once the parent is updated.
3. Open the FreeScout Bridge tab under Settings, SportsPress Admin Tools and check the shared secret and events URL.

== Changelog ==

= 1.0.0 =
* New: the FreeScout bridge moved out of SportsPress Admin Tools into its own plugin. The REST routes, options, outbox table, cron hooks and filters are unchanged.
* New: the settings are a FreeScout Bridge tab on the SportsPress Admin Tools settings page instead of a section on the General tab.
* New: stays dormant, with a notice, while SportsPress Admin Tools is older than 1.3.0, so it can be installed before the parent is updated with no gap in service.
