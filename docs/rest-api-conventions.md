# REST API Conventions

This document codifies the response shape every plugin's REST endpoints share. The
React dashboard (`sportspress-league-manager/src/dashboard`) and any other client
that consumes these endpoints relies on these shapes — keep them consistent.

Namespaces in use:

- `splm/v1` — shared across `sportspress-league-manager`, `sportspress-events-manager`,
  and `sportspress-player-tools`. Path collisions are forbidden; see the existing
  module comments in `class-rest-api.php`.
- `spsg/v1` — `sportspress-schedule-generator`.
- `spet/v1` — `sportspress-etransfer-automation` (webhook only).

## Response shapes

### Single resource

A `GET /thing/{id}` returns the resource directly as a JSON object:

```json
{ "id": 42, "name": "...", "...": "..." }
```

### List endpoint

A `GET /things` (no ID in the path, returns a collection) is **always** wrapped:

```json
{
  "data": [ { "id": 1, ... }, { "id": 2, ... } ],
  "total": 27,
  "page": 1,
  "total_pages": 1
}
```

Rules:

- `data` is the array of items (never `games`, `items`, `results`, or any other
  custom key — older bare-array and `{games, total}` shapes were standardized
  away during the 2026-05 audit).
- `total` is the total number of items across all pages.
- `page` is the 1-indexed current page.
- `total_pages` is `ceil( total / per_page )`. For non-paginated endpoints it
  equals `1` and `total === count(data)`.
- Endpoints that do not paginate still wrap (so the client never has to
  branch on shape).

PHP helper: `splm_rest_list_response( array $items, ?int $total = null, int $page = 1, int $per_page = 0 )`
in `sportspress-league-manager/includes/class-rest-api.php` builds this shape.
Sibling plugins call it via `function_exists('splm_rest_list_response')`; when SPLM is not active they fall
back to the same shape inline.

### Aggregate / report resource

Endpoints that compute a single aggregate object (a health report, a season
summary, a team comparison) return the object directly — they are not lists.
Example: `GET /splm/v1/health` returns
`{ "teams_without_players": [...], "players_without_email": [...], ... }`.

### Write operations

Mutation endpoints (`POST`, `PUT`, `DELETE`) keep their existing shape:

```json
{ "success": true, "...": "..." }
```

Do not retrofit writes to the list shape.

### Errors

Errors are returned as a `WP_Error`, which WordPress serializes to:

```json
{ "code": "invalid_input", "message": "...", "data": { "status": 400 } }
```

Status codes:

| Code | Use                                              |
|------|--------------------------------------------------|
| 400  | Malformed input (missing field, bad type, etc.)  |
| 403  | Capability check denied                          |
| 404  | Resource not found                               |
| 409  | Conflict (lock contention, double-write, etc.)   |
| 413  | Payload too large (chunked import limits)        |
| 503  | Required dependency / module unavailable         |

## Client-side handling

`sportspress-league-manager/src/dashboard/lib/api.js` unwraps `.data` for every
list endpoint. Clients of those functions receive a plain array. Do not add
defensive `Array.isArray( data ) ? data : data?.foo` shims — if you find one,
the server is wrong. Fix the server.
