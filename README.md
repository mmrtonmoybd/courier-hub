# Courier Hub

A standalone webhook relay for Bangladeshi courier services.

One relay sits in front of 10-30 ecommerce sites that share a MySQL server. Every
courier webhook (Pathao, Steadfast, Carrybee, Paperfly, RedX) is pointed at this
app. The relay works out which site owns the parcel by looking the order up in
each mapped tenant database, then replays the untouched request to that site on
the exact path the courier used.

**The ecommerce applications need no changes at all.** They keep serving their
existing `/api/webhooks/{courier}` routes and cannot tell the request was
relayed.

- Author: Moshiur Rahman <rmedha037@gmail.com>
- Stack: Laravel 13, Filament 5, Livewire 4, PHP 8.4, MySQL, Pest 5
- Filament plugins: Northwestern theme, Auth Designer (login page), Breezy (profile,
  avatars, browser sessions), Overlook (resource cards). The panel reuses the
  ecommerce admin theme: same orange palette, sidebar layout and base styles.

## Why

A courier merchant account allows only a handful of webhook URLs, and the
webhook payload never says which site an order belongs to. Registering one URL
per site does not scale to 30 sites. Registering the relay once does.

## How a webhook travels

```
courier ──POST /api/webhooks/pathao──▶ courier-hub
                                         1. log the raw request
                                         2. extract invoice / tracking / phone / name
                                         3. find the owning tenant database   ──read-only──▶ tenant MySQL databases
                                         4. replay the raw body ────────────────────────────▶ https://site/api/webhooks/pathao
                                         5. answer the courier with its own canned ack
                                      cron: schedule:run ─▶ webhooks:retry (failed + unmatched sweep)
```

1. **Capture.** Every inbound request is written to `webhook_deliveries` before
   anything else, raw body and headers included.
2. **Identify.** `config/couriers.php` holds one field map per courier, so the
   invoice number, tracking id, phone and customer name are read from wherever
   that courier puts them (dot notation covers Paperfly's nested `data`). A
   missing key is never an error.
3. **Resolve.** Each active tenant database mapped to that courier is asked, in
   one statement, whether it knows the order:

   | order | key | matched against |
   | --- | --- | --- |
   | 1 | invoice | `orders.invoice_no` |
   | 2 | tracking | `orders.tracking_id`, `tracking_id2`, `tracking_id3` |
   | 3 | phone | `orders.customer_phone`, digits only, last 10 compared |
   | 4 | name | `customers.name`, only when the payload carried none of the above |

   Lookups are read-only. The relay never writes to a tenant database.
4. **Forward.** Every matching site is forwarded to — the raw body byte for
   byte, the courier's own headers passed through, 10 second timeout. Two sites
   claiming the same parcel both receive it; each one only updates its own order.
5. **Acknowledge.** The courier always gets the response its own protocol
   expects, never the tenant's response, and gets it whether or not the forward
   succeeded.

| courier | ack |
| --- | --- |
| Pathao | 202 `OK` + `X-Pathao-Merchant-Webhook-Integration-Secret` |
| Carrybee | 202 `{"message":"Event received"}` + `X-CB-Webhook-Integration-Header` |
| Steadfast | 200 `{"status":"success","message":"Webhook received successfully."}` |
| Paperfly | 200 `OK` |
| RedX | 200 `OK` |

## Inbound URLs

| URL | use |
| --- | --- |
| `POST /api/webhooks/{courier}` | shared. The owning site is discovered from the payload. |
| `POST /api/pathao-webhook` | legacy alias, same handler |
| `POST /api/steadfast-webhook` | legacy alias, same handler |
| `POST /api/w/{token}/webhooks/{courier}` | site-scoped. Use when a site has its own merchant account, and therefore its own secret, at that courier. Shown on the transfer's edit page. |

## Secrets

Each site can hold its own secret per courier (`Own secret` on the transfer).
The courier-wide secret under **Courier Secrets** is the fallback, used when the
site is not known yet — an unmatched webhook, or a courier integration
handshake. A site-scoped inbound URL always answers with that site's secret.

## No queue

Forwarding happens inline during the request. A forward that fails is stored and
swept by `php artisan webhooks:retry`, which is registered on the scheduler and
driven by the `schedule:run` cron entry you already have.

- **Failed** rows: up to 20 attempts, backing off 1m, 2m, 5m, 10m, 30m, then
  hourly.
- **Unmatched** rows: re-resolved for 30 minutes / 10 attempts, because a courier
  can fire before the order row exists in the tenant database. On a match the row
  is promoted and forwarded.

## Install

```bash
composer install
cp .env.example .env            # then set the DB credentials, see below
php artisan key:generate
php artisan migrate --seed      # creates the tables, seeds the five couriers
php artisan make:filament-user  # create your admin login
npm install && npm run build
```

`.env` points at the relay's **own** database, using the same MySQL host, user
and password as the ecommerce sites, because the relay opens runtime connections
to their databases:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=courier_hub
DB_USERNAME=root
DB_PASSWORD=secret
```

### Seeders

| seeder | what it does | safe in production |
| --- | --- | --- |
| `CourierSettingSeeder` | one row per supported courier, pre-filled with its default secret. Runs as part of `db:seed`. | yes |
| `WebhookTransferSeeder` | maps every tenant database found on the shared credentials to every courier, using known site addresses where available. Re-runnable. | yes, then edit the rows in the admin |
| `DemoDataSeeder` | the two above plus two weeks of demo traffic (delivered, failed and unmatched) across every route, for trying out the dashboard, filters and retries. | **no** |

```bash
php artisan db:seed                                    # couriers only
php artisan db:seed --class=WebhookTransferSeeder      # map the databases found on this server
php artisan db:seed --class=DemoDataSeeder             # demo traffic, local only
```

Add the scheduler to cron once, if it is not already there:

```cron
* * * * * cd /path/to/courier-hub && php artisan schedule:run >> /dev/null 2>&1
```

## Admin

The Filament panel is served from `/`.

### Dashboard

- **Stats** — webhooks in the last 24 hours (with an hourly sparkline), success
  rate, forwards awaiting retry, unmatched webhooks, active routes, distinct
  sites, couriers wired, average attempts per webhook.
- **Forwarding volume** — delivered / failed / unmatched per day, over 7, 14 or
  30 days.
- **Webhooks by courier** — share of traffic per courier over the last week.
- **Site performance** — delivered and failed counts per routing row for the last
  week, plus when each site last received a webhook. The fastest way to spot the
  one site out of thirty that stopped accepting forwards.
- **Needs attention** — every failed or unmatched webhook, newest first, with a
  one-click retry and a link to the full log entry.

- **Webhook Transfer** — the routing table. A row is one site + one courier +
  one tenant database. You enter only the site address, e.g.
  `https://elegantemart.com`; the relay appends the courier's own webhook path
  (`/api/webhooks/pathao`), shows the resulting URL in the form, and rejects an
  address that already carries a path. The tenant
  database list is read live from the server and filtered to databases that
  contain an `orders` table. **Add site** maps one site to several couriers at
  once.
- **Delivery Log** — every webhook received, what it matched on, where it went,
  the response, and any error. Filter by courier, status or tenant. `Retry now`
  re-forwards a single row; unmatched rows are re-resolved first.
- **Courier Secrets** — the courier-wide fallback secret and the shared inbound
  URL per courier.

Adding a site or a courier target is a row in the admin, never a code change.

## Rollout

1. Add the site in **Webhook Transfer** (or **Add site** for several couriers).
2. Point one courier, for one site, at the relay. Keep the ecommerce app's own
   webhook registered if the courier allows several URLs — both receive the
   event, and the relay's forward is idempotent from the tenant's point of view.
3. Watch the **Delivery Log** for `delivered` rows on the expected tenant.
4. Repeat per courier, then per site, and remove the direct registrations once
   the log is clean.

## Operations

```bash
php artisan webhooks:retry            # manual sweep (also runs every minute)
php artisan webhooks:prune            # trim the log (also runs daily at 03:10)
php artisan test --compact            # 98 tests
vendor/bin/pint                       # code style
```

The delivery log keeps delivered rows for 30 days and unresolved rows for 90;
both are configurable in `config/couriers.php`.

## Adding a courier

1. Add an entry to `config/couriers.php`: the field map and the ack it expects.
2. Add the case to `App\Enums\Courier`.
3. Add its route alias in `routes/api.php` if it uses a non-standard path.
4. Seed its `courier_settings` row (`php artisan db:seed`).

No other code changes are needed — identity extraction, resolution, forwarding
and retries are all driven by that config entry.

## Front-end build

The panel theme lives in `resources/css/filament/admin/` and is compiled by Vite
(`npm run build`, or `npm run dev` while working on it). It imports the Filament
and Northwestern theme stylesheets, so `@tailwindcss/forms` and
`@tailwindcss/typography` are required dev dependencies.

## Design document

`docs/superpowers/specs/2026-09-15-courier-webhook-relay-design.md`
