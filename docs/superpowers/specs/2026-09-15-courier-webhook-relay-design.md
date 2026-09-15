# Courier Webhook Relay (courier-hub) — Design

Date: 2026-09-15
Status: Approved

## Problem

Multiple ecommerce sites share one MySQL server and one codebase lineage. Each
courier (Pathao, Steadfast, Carrybee, Paperfly, RedX) allows only a small number
of webhook URLs per merchant account, and the courier payload does not say which
site an order belongs to. Today every site must register its own webhook URL.

## Goal

A standalone relay application (`courier-hub`) receives all courier webhooks,
identifies which tenant database owns the order, and forwards the untouched
request to that tenant's ecommerce URL on the same path the courier used. The
ecommerce applications require **zero** changes: they keep serving their existing
`/api/webhooks/{courier}` routes and cannot tell the request was relayed.

## Constraints

- No change of any kind to the ecommerce repository (no routes, migrations, columns).
- The relay never writes to a tenant database. Lookups are read-only.
- No Laravel queue workers. Forwarding is synchronous during the request; retries
  run from a cron-driven Artisan command (`schedule:run` already exists).
- Adding a tenant or courier target is an admin data change (a new
  `webhook_transfers` row), never a code change.

## Architecture

```
courier  ──POST /api/webhooks/{courier}──▶  courier-hub
                                              1. log raw delivery
                                              2. extract identity
                                              3. resolve tenant DB(s)  ─read-only─▶ tenant MySQL DBs
                                              4. forward raw body ───────────────▶ https://tenant/api/webhooks/{courier}
                                              5. reply courier's canned ack (never the tenant's response)
                                           cron: schedule:run ─▶ webhooks:retry (failed + unmatched sweep)
```

### 1. Data model (relay's own database `courier_hub`)

**`webhook_transfers`** — admin-managed routing table.

| column | type | notes |
| --- | --- | --- |
| `name` | string | label for admin |
| `target_url` | string | scheme + host (+ optional port) only; a path is rejected by validation |
| `courier` | enum string | `pathao`, `steadfast`, `carrybee`, `paperfly`, `redx` |
| `database_name` | string | tenant MySQL database on the shared credentials |
| `priority` | unsigned int, default 0 | lower first; ordering only, does not stop fan-out |
| `is_active` | bool, default true | |

Unique constraint: (`courier`, `database_name`, `target_url`).

**`webhook_deliveries`** — raw capture, forward log, and retry queue in one table.

| column | type | notes |
| --- | --- | --- |
| `courier` | string | |
| `incoming_path` | string | preserved verbatim, including legacy aliases |
| `headers` | json | inbound headers as received |
| `raw_body` | longtext | forwarded byte-for-byte |
| `payload` | json nullable | decoded body when it was JSON |
| `invoice_no`, `tracking_id`, `phone`, `name` | string nullable | extracted identity |
| `matched_by` | string nullable | `invoice` / `tracking` / `phone` / `name` |
| `database_name` | string nullable | resolved tenant |
| `webhook_transfer_id` | FK nullable | null-on-delete |
| `target_url` | string nullable | resolved absolute forward URL |
| `status` | enum string | `unmatched`, `delivered`, `failed` |
| `attempts` | unsigned int, default 0 | |
| `next_attempt_at` | timestamp nullable | backoff schedule |
| `response_status` | unsigned int nullable | |
| `response_body` | text nullable | truncated |
| `last_error` | text nullable | |
| `delivered_at` | timestamp nullable | |

**`courier_settings`** — one row per courier, holds the secret/token the relay
echoes back in its own ack.

| column | type |
| --- | --- |
| `courier` | string, unique |
| `secret` | string nullable |
| `is_active` | bool, default true |

Seeded with the five couriers.

### 2. Inbound routes

Mirror the ecommerce routes exactly:

```
POST /api/webhooks/{courier}     courier ∈ {pathao,steadfast,carrybee,paperfly,redx}
POST /api/pathao-webhook         legacy alias, courier forced to pathao
POST /api/steadfast-webhook      legacy alias, courier forced to steadfast
```

A single `IncomingWebhookController` handles all three. CSRF does not apply (API
routes). No inbound signature verification — matching the ecommerce behaviour,
which reads courier headers but does not enforce them.

### 3. Identity extraction

`config/couriers.php` holds one field map per courier; dot notation addresses
nested payloads. `CourierIdentityExtractor` reads the map and never throws on a
missing key — an absent field yields `null`.

| courier | invoice | tracking | phone | name |
| --- | --- | --- | --- | --- |
| pathao | `merchant_order_id` | `consignment_id` | `recipient_phone` | `recipient_name` |
| carrybee | `merchant_order_id` | `consignment_id` | `recipient_phone` | `recipient_name` |
| steadfast | `invoice` | `tracking_code`, `consignment_id` | `recipient_phone` | `recipient_name` |
| paperfly | `data.merchant_order_reference` | `data.order_number`, `data.barcode` | `data.recipient_phone` | `data.recipient_name` |
| redx | `invoice_number` | `tracking_number` | `customer_phone` | `customer_name` |

Values copied from the ecommerce `CourierWebhookService`. Only the identity
fields are copied — no status, tracking-promotion, or order-mutation logic. The
tenant ecommerce app still performs every order update itself when it receives
the forwarded request.

### 4. Tenant resolution

`TenantResolver` builds runtime connections: it clones
`database.connections.mysql` and overrides only `database`. Tenant connections
are never pre-declared in `config/database.php`, so a new tenant needs no code
change.

For each distinct active `database_name` mapped to the courier, the resolver
tests keys in this order and stops at the first hit for that database:

1. `orders.invoice_no = invoice`
2. `orders.tracking_id` / `tracking_id2` / `tracking_id3` = tracking
3. `orders.customer_phone`, digits only, last 10 digits compared, = phone
4. `customers.name = name` — only when invoice, tracking and phone were all
   absent from the payload

Every database that matched is returned along with the key that matched it. A
database that cannot be reached is skipped and logged, never fatal.

### 5. Fan-out and forwarding

All matching databases are forwarded to (approved decision: fan out rather than
first-match). One `webhook_transfers` row may share a `courier` +
`database_name` with another row pointing at a different `target_url`; every such
row receives the forward, and each gets its own `webhook_deliveries` row.

`WebhookForwarder` POSTs to `rtrim(target_url, '/') . $incomingPath` with:

- the raw body byte-for-byte,
- the inbound headers passed through minus `Host` and `Content-Length`,
- a 10 second timeout.

A 2xx marks the row `delivered`; anything else (including transport exceptions)
marks it `failed` and sets `next_attempt_at`.

### 6. Courier ack

`CourierResponder` builds the reply from `config/couriers.php` plus the secret in
`courier_settings`. The relay always returns its own ack, never the tenant's
response, and returns it regardless of forward outcome:

| courier | status | body | header |
| --- | --- | --- | --- |
| pathao | 202 | `OK` | `X-Pathao-Merchant-Webhook-Integration-Secret: <secret>` |
| carrybee | 202 | `{"message":"Event received"}` | `X-CB-Webhook-Integration-Header: <secret>` |
| steadfast | 200 | `{"status":"success","message":"Webhook received successfully."}` | — |
| paperfly | 200 | `OK` | — |
| redx | 200 | `OK` | — |

### 7. Retry command

`php artisan webhooks:retry`, registered in the scheduler as
`->everyMinute()->withoutOverlapping()` and driven by the existing
`schedule:run` cron entry. No new cron entry, no queue worker.

- `failed` rows with `attempts < 20` and `next_attempt_at <= now` are re-forwarded.
  Backoff: 1m, 2m, 5m, 10m, 30m, then 1h for every later attempt.
- `unmatched` rows are re-resolved, because the courier can fire before the order
  row exists in the tenant database. On a match the row is promoted (tenant and
  target filled in) and forwarded. Given up after 30 minutes or 10 attempts.

### 8. Filament admin (v5, panel `admin`)

- **`WebhookTransferResource`** — form and table over the routing table.
  `target_url` validation rejects any path, query, or fragment (scheme + host +
  optional port only). `database_name` is a select populated live by running
  `SHOW DATABASES` on the shared credentials and keeping only databases that
  contain an `orders` table.
- **`WebhookDeliveryResource`** — read-only log. Filters by courier and status,
  raw payload and response viewer, a "Retry now" row action, and a bulk retry
  action.
- **`CourierSettingsResource`** — edit the per-courier ack secret and active flag.

Plain Filament authentication, one admin user. No Shield.

### 9. Multi-site scale (10-30 tenants)

- A sweep is **one statement per tenant database**: every key is tested in a
  single `select max(case when …)` and the key priority is applied in PHP.
- A resolved route is cached (`TenantRouteCache`, 24h) keyed by courier plus the
  parcel's invoice/tracking/phone, so the follow-up events of the same parcel
  skip the sweep. Saving or deleting any `webhook_transfers` row bumps the cache
  version and orphans every entry.
- Tenant connections are purged after each lookup, so a sweep never leaves 30
  MySQL connections open.
- A tenant that cannot be reached is logged and skipped; the remaining tenants
  still resolve.
- `webhooks:prune` (scheduled daily) keeps the delivery log bounded: delivered
  rows for 30 days, unresolved rows for 90.
- The admin has an **Add site** action that maps one site to several couriers in
  a single step, since 30 sites across 5 couriers is 150 routing rows.

### 10. Per-site, per-courier secrets

Each site holds its own merchant account at a courier, so each site+courier pair
can have its own secret:

- `webhook_transfers.ack_secret` — the secret for this site at this courier.
- `courier_settings.secret` — courier-wide fallback, used when the site is not
  known yet (an unmatched webhook, or a courier integration handshake).
- Each transfer also gets an `inbound_token` and therefore its own inbound URL,
  `POST /api/w/{token}/webhooks/{courier}`. A courier pointed at that URL is
  acknowledged with that site's secret without any lookup, and the request is
  forwarded to that site on the canonical path `/api/webhooks/{courier}`. This is
  the URL to register when a site has its own merchant account; the shared URL
  remains for accounts that serve several sites.

### 11. Testing (Pest 5)

Fake tenants are extra sqlite connections carrying minimal `orders` and
`customers` tables; `Http::fake()` intercepts the outbound forward.

- identity extraction for each of the five couriers, including missing keys
- match by invoice, by tracking (each of the three columns), by phone
  (digits-only, last 10), by name (only as last resort)
- multi-database fan-out produces one delivery row per target
- unmatched row is promoted and forwarded by a later `webhooks:retry`
- failed row follows the backoff schedule and is retried
- the correct canned ack (status, body, header) per courier
- a `target_url` containing a path is rejected by validation
- the owning site is found among thirty mapped tenants
- a cached route survives the order disappearing, and is invalidated by an edit
- an unreachable tenant does not stop the other tenants resolving
- a site-scoped inbound URL acknowledges with that site's secret
