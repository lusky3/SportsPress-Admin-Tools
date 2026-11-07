# SportsPress FreeScout Bridge

A child plugin of SportsPress Admin Tools. It is the WordPress side of the FreeScout SPAT bridge:

- a signed REST API (`spat/v1/freescout/ping`, `customer`, `action`, `query`) that shows league data
  in a ticket's sidebar and runs actions for a customer;
- an event outbox that sends signed events (unmatched or matched e-Transfers, registration outcomes,
  waitlist changes) to FreeScout through WP-Cron, so a slow helpdesk never delays a checkout or webhook.

The other child plugins add their own sections and actions through the `spat_freescout_providers`,
`spat_freescout_actions` and `spat_freescout_queries` filters.

## History

This code lived in SportsPress Admin Tools up to 1.2.x. Nothing a FreeScout install or a child plugin
depends on changed in the move: the `spat/v1` REST namespace, the options (`spat_freescout_secret`,
`spat_freescout_events_url`, `spat_freescout_events_enabled`), the `spat_freescout_outbox` table, the
cron hooks, the filters and the signature header (`X-SPAT-Signature`) are all the same. Only the PHP class
names changed (`SPFB_*`).

## Requirements

- WordPress 5.0+, PHP 8.1+, and SportsPress Admin Tools 1.3.0 or later (contract version 1.2.0).

## Turning it on

Activating the plugin enables the **FreeScout Bridge** module. Then open the **FreeScout Bridge** tab
under **Settings, SportsPress Admin Tools**:

| Setting | Meaning |
|---------|---------|
| Shared Secret | At least 32 characters; the same value as the bridge's shared secret in FreeScout. Blank keeps the saved one. Until one is set the routes answer 503. |
| Events URL | The bridge's events endpoint in FreeScout (http or https). |
| Send Events | Switch for outgoing events. Needs the URL and the secret. |

## Installing next to an older SportsPress Admin Tools

While SportsPress Admin Tools is older than 1.3.0 it still carries its own copy of the bridge. This
plugin detects that and stays dormant (registers no routes, cron or settings) and shows a notice. Install
and activate this plugin first, then update SportsPress Admin Tools; the bridge changes hands with no gap.

## Uninstalling

Uninstall this plugin before SportsPress Admin Tools if you want its data (secret, settings, outbox table) removed: the "remove data on uninstall" choice is stored by the parent and is deleted with it.

## Development

```bash
php sportspress-freescout-bridge/tests/test-plugin.php   # one suite
bash run-all-tests.sh                                     # everything
```
