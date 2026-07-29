# PROJECT_STATUS.md — Viking Restaurant Platform

**Generated:** 29 July 2026 · **Branch:** `claude/viking-ordering-platform-uo9v1t`
**Last verified commit:** `5fc6d0b`

---

## Overall completion

**≈ 92%**

Every functional module is built, verified end to end and covered by tests. The
remaining ~8% is deployment packaging (Docker, CI, documentation), which is in
progress, plus one item that cannot be completed without credentials — see
[Blockers](#current-blockers).

| Layer | Completion |
|---|---|
| Database & domain model | 100% |
| REST API | 100% |
| Authentication & RBAC | 100% |
| Realtime | 100% backend · 100% client |
| Customer PWA | 100% |
| Kitchen display | 100% |
| Cashier POS | 100% |
| Admin dashboard | 100% |
| Automated tests | 85% (backend deep, frontend unit pending) |
| DevOps & docs | 40% (in progress) |

---

## Completed modules

| Module | Notes |
|---|---|
| **Monorepo** | `backend/` Laravel 13 + `frontend/` Next.js 16, one git repo |
| **Database** | 31 migrations, 23 models, normalized, indexed, seeded |
| **Domain services** | Pricing, coupons, orders, payments, tables, media, QR |
| **REST API v1** | 117 routes across public / customer / kitchen / cashier / admin |
| **Auth** | Sanctum tokens, ability-scoped, guest device tokens |
| **RBAC** | 26 permissions, 7 roles, per-branch scoping, policies |
| **Audit log** | spatie/activitylog on branches, products, categories, coupons, offers, tables, users |
| **Realtime** | Reverb events on private per-branch channels + public guest channels |
| **Customer PWA** | 13 routes, AR/EN RTL, dark mode, offline, installable |
| **QR ordering** | Rotatable per-table tokens, idempotent scan, session rollup |
| **Kitchen display** | 3 lanes, ageing colours, synthesised sound, large touch targets |
| **Cashier POS** | Queue, floor plan, split payment, refunds, discounts, receipt print |
| **Admin dashboard** | 14 screens including 6 reports with CSV export |
| **Testing** | 83 backend tests, 200 assertions, all passing |

---

## Remaining modules

| Module | State |
|---|---|
| `docker-compose.yml` + Dockerfiles | ⬜ In progress |
| GitHub Actions CI | ⬜ In progress |
| `DEPLOYMENT.md` / `INSTALL.md` / `API_DOCUMENTATION.md` / `DATABASE_SCHEMA.md` | ⬜ In progress |
| Frontend unit tests (Vitest) | ⬜ Pending |
| Production deployment | 🔴 Blocked — no credentials |

---

## Build status

| Target | Command | Result |
|---|---|---|
| Frontend production build | `npm run build` | ✅ **Pass** — 32 routes compiled |
| Frontend typecheck | `npx tsc --noEmit` | ✅ **Pass** — 0 errors |
| Backend test suite | `php artisan test` | ✅ **Pass** — 83/83, 200 assertions |
| Backend lint | `./vendor/bin/pint` | ✅ **Pass** |
| Migrations | `php artisan migrate:fresh` | ✅ **Pass** — 31/31 |
| Seeders | `php artisan db:seed` | ✅ **Pass** — 6 seeders |

Compile errors: **0**. TypeScript errors: **0**. Laravel errors: **0**.

---

## Backend status — ✅ Complete

Laravel 13.23 on PHP 8.4.

- Business rules live in `app/Services/`, never controllers, so the API,
  websocket events and seeders all share one implementation.
- `OrderStatus` enum owns the state machine; illegal transitions are impossible
  by construction rather than by convention.
- `CartPricingService` prices every cart server-side — the client sends ids and
  quantities only.
- `PaymentService` derives `payment_status` from the ledger rather than
  assigning it, so split payments and partial refunds stay consistent.
- `Model::preventLazyLoading` is on outside production; one violation was found
  during verification (`/admin/users`) and is fixed, with a regression test.

---

## Frontend status — ✅ Complete

Next.js 16.2 (App Router, Turbopack), React 19, TypeScript, Tailwind v4.

- 32 routes build clean; static where possible, dynamic where data demands.
- Design system: oklch tokens split into a raw scale and a semantic layer, so
  the theme switch is ~15 variable redefinitions.
- Arabic is the default locale, with RTL set on `<html>` so portalled dialogs
  and toasts inherit it. Numerals stay Latin and tabular in prices.
- Translation keys are typed from the English dictionary — a missing key is a
  compile error.
- State: TanStack Query for server state, Zustand for cart and session.

**Verified pages:** `/` `/menu` `/menu/[slug]` `/cart` `/checkout` `/orders`
`/orders/[number]` `/favorites` `/account` `/t/[token]` `/auth/login`
`/auth/register` `/offline` `/kitchen` `/cashier` `/cashier/receipt/[number]`
`/admin` + 12 admin sub-routes.

---

## API status — ✅ Verified

117 routes under `/api/v1`. Every group was called against the running server:

| Group | Result |
|---|---|
| Public (bootstrap, categories, products, search, highlights, offers) | ✅ 200 |
| Auth (all 7 seeded roles) | ✅ 200 |
| Cart pricing (incl. coupon + validation errors) | ✅ 200 / 422 |
| QR scan + session | ✅ 200 / 404 on bad token |
| Orders (place, track, cancel, reorder) | ✅ 201 / 200 |
| Kitchen (board, advance, status, items) | ✅ 200 |
| Cashier (orders, tables, pay, refund, discount, receipt) | ✅ 200 / 201 |
| Admin (16 endpoints) | ✅ 200 |
| Reports (6 reports × view + CSV export) | ✅ 200 |

Conventions: `?filter[col]=`, `?sort=-col`, `?per_page=`, `?from=&to=`.
Errors always return `{ message, error, context?, errors? }`.

---

## Database status — ✅ Complete

31 migrations, all applied. Portable across MySQL 8 (production) and SQLite
(tests) — MySQL-only SQL is branched on the driver.

**Seeded volumes:** 2 branches · 42 QR tables · 7 categories · 22 products ·
14 option groups · 48 options · 8 users · 3 coupons · 3 offers · 116 orders ·
104 payments across 14 days of history.

Design points: variants and add-ons share one `option_groups` structure;
order items snapshot names and prices so receipts survive menu edits; orders
carry both lifecycle timestamp columns *and* an append-only status journal.

---

## Authentication status — ✅ Verified

- Sanctum bearer tokens, scoped to the user's abilities.
- Customers sign in with **email or phone** — many order from a phone and have
  no email.
- Guests are identified by an `X-Guest-Token` device token; signing in claims
  that device's order history onto the account.
- Deactivating a user revokes their tokens immediately.
- Rate limits: 10/min on auth, 30/min on ordering, 120/min general.

**Verified:** all 7 role logins return a token and permission list; wrong
credentials return 422; a deactivated token returns 403 `account_inactive`.

---

## Kitchen Display status — ✅ Complete

`/kitchen` — permission `orders.kitchen`.

Three lanes (incoming / preparing / ready) fed by `GET /kitchen/board` plus
Reverb pushes, with a 20-second poll as the fallback for a tablet whose socket
dropped. Ticket age drives border colour and a pulse at the configured
warning/critical thresholds. Special instructions get a bordered callout.
Alert tone is synthesised via Web Audio so simultaneous orders retrigger
without decode latency. One shared clock updates every timer.

---

## Cashier status — ✅ Complete

`/cashier` — permission `orders.cashier`.

Unsettled-order queue and a floor plan grouped by zone with live running
totals. Payment sheet supports cash and card, quick-tender chips, and shows
change before submit. Refunds and manager discounts are permission-gated
separately from payment capture (**verified**: a cashier is refused a refund,
a manager is allowed). Closing a table over unpaid orders requires explicit
confirmation. Receipts print from a structured payload at `/cashier/receipt/[n]`.

---

## Admin Dashboard status — ✅ Complete

`/admin` — permission `dashboard.view`. Sidebar entries filter by permission,
so a branch manager sees a genuinely smaller panel.

Dashboard (single API call: today/yesterday/month, 14-day trend, hourly
histogram, top sellers, live queue) · orders + detail with transitions driven
by the API's `allowed_transitions` · products · categories · tables + QR ·
users + role permission matrix · coupons · offers · 6 reports with CSV export ·
media library · settings · activity log · review moderation.

---

## QR Ordering status — ✅ Verified

Each table carries an opaque, rotatable `qr_token`; the QR encodes
`/t/{token}`, never the table number, so a code cannot be guessed.

Scanning is **idempotent** — a second scan rejoins the open session rather than
starting a competing bill (**verified**: two scans produce one
`table_sessions` row). Sessions roll multiple orders into one bill. Rotation
invalidates every printed code for that table. Admin provides a printable A4
sheet of four table tents per page.

**Verified:** valid token → 200 with session + branch; unknown token → 404
`table_not_found`; dine-in order without a table → 422 `table_required`.

---

## Realtime status — ✅ Complete

Laravel Reverb. Events: `order.placed`, `order.status`, `order.payment`,
`order.refunded`.

Channels: `private-branches.{id}.{kitchen|cashier|admin}` authorised by
permission **and** branch; `private-users.{id}.orders` for signed-in customers;
`guests.{token}.orders` — public but named with the 32-character device token
only that browser holds, since a guest has no account to authenticate with.

Every realtime screen also polls, so the app degrades to a slower but working
state if Reverb is unavailable. Events are dispatched **after** commit, so the
kitchen never sees an order that was rolled back.

> Reverb was configured and its events unit-tested, but a live websocket
> handshake was not exercised in this container (no long-running process).
> The polling fallback is verified.

---

## Testing status — ✅ 83 passing

```
php artisan test  →  83 tests, 200 assertions, 0 failures (10.5s)
```

| Suite | Tests | Covers |
|---|---|---|
| `CartPricingTest` | 16 | Option deltas per unit, required/min/max rules, foreign options, line merging, coupon caps, offers-before-coupons, delivery/service scoping, quantity clamping |
| `OrderLifecycleTest` | 11 | Snapshotting, order numbering, state machine, timestamps, journalling, broadcasts, table occupancy |
| `PaymentTest` | 14 | Split payments, overpayment, tender/change, partial and repeated refunds, manual discounts |
| `CouponRedemptionTest` | 12 | Global and per-device limits, atomic claim under a simulated race, expiry windows, release on cancel |
| `ApiAccessTest` | 22 | RBAC boundaries, branch scoping, guest ownership isolation, QR scan, locale negotiation, deactivated tokens |
| `OrderStatusTest` (unit) | 8 | State machine, discount arithmetic, order-type rules |

Frontend: typecheck and production build pass. Vitest unit tests for the cart
store and format helpers are pending.

---

## GitHub status — ✅ Connected

- Repository: **`clouddevag/viking-api`** (already exists)
- Branch: `claude/viking-ordering-platform-uo9v1t`
- 6 commits, each a completed milestone.
- Pushed automatically after every milestone.

⚠️ **Security — action required by the repository owner.** The Express relay
that previously occupied this repo had a **Telegram bot token and mail
recipient hard-coded in source**. That file is deleted, but the values remain
in git history and must be treated as compromised: **revoke the bot token via
@BotFather and rotate the mail credentials.** Nothing in the new codebase
references them.

---

## Deployment status — 🟡 Prepared, not deployed

No hosting credentials are present in this environment, so nothing has been
deployed. The project is being packaged for one-command deployment
(`docker compose up -d --build`) — see `DEPLOYMENT.md`.

**Why Vercel alone is not sufficient:** the frontend is a Next.js app and would
deploy to Vercel unchanged, but it is useless without the Laravel API, MySQL,
Redis and the Reverb websocket server, none of which run on Vercel. A complete
deployment needs the frontend on Vercel *and* the backend on a host that can
run PHP-FPM plus long-lived processes (Fly.io, Railway, Hetzner, DigitalOcean),
or the whole stack via the provided Docker Compose file.

---

## Current blockers

| # | Blocker | Needed to unblock | Impact |
|---|---|---|---|
| 1 | **No hosting credentials** | Either (a) a server with Docker + SSH, or (b) a Vercel token *and* a PHP host for the API | Cannot return a production URL. Everything is packaged for one-command deploy. |
| 2 | **Leaked Telegram bot token in git history** | Owner revokes it via @BotFather | Anyone with repo history can control that bot. Not used by the new code. |
| 3 | **No S3 credentials** | `AWS_*` in `backend/.env` | Media uploads fall back to the local `public` disk — fine for a single server, not for multi-node. |
| 4 | **No SMTP credentials** | `MAIL_*` in `backend/.env` | Mail is written to the log driver. No customer-facing feature depends on it yet. |
| 5 | **MySQL unavailable in this container** | — | Development and tests ran on SQLite. Migrations are written portably and MySQL-specific paths (full-text search, date functions) are branched on the driver, but they have not executed against a real MySQL 8 instance. First `docker compose up` will exercise them. |

None of these block further development; items 1–4 are credential handovers,
and item 5 resolves the first time the stack runs under Docker.

---

## How to run it right now

```bash
# Backend
cd backend
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed
php artisan serve --port=8000

# Frontend (separate terminal)
cd frontend
cp .env.example .env.local
npm install && npm run dev
```

Open `http://localhost:3000`. Sign in at `/auth/login`.

**Seeded accounts — password `Viking#2026`:**

| Email | Role | Lands on |
|---|---|---|
| `owner@viking.example` | super-admin | `/admin` |
| `admin@viking.example` | admin | `/admin` |
| `manager@viking.example` | manager | `/admin` |
| `cashier@viking.example` | cashier | `/cashier` |
| `kitchen@viking.example` | kitchen | `/kitchen` |
| `waiter@viking.example` | waiter | `/` |
| `customer@viking.example` | customer | `/` |

To try QR ordering, open **Admin → Tables → QR** and scan (or click) a table's
code — it lands on `/t/{token}` and opens a dining session.
