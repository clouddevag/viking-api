# CONTINUE.md — Viking Restaurant Platform

Working state of the build. Update this file at the end of every work session.

**Last updated:** commit `06b4df0` — all 12 phases complete; blocked on GitHub push access.
**Branch:** `claude/viking-ordering-platform-uo9v1t`

---

## 1. Status

| Phase | Area | State |
|---|---|---|
| 1 | Architecture & monorepo | ✅ Done |
| 2 | Database schema, models, seeders | ✅ Done |
| 3 | REST API v1 (117 routes) | ✅ Done, verified end-to-end |
| 4 | Auth (Sanctum), RBAC, audit log | ✅ Done |
| 5 | Realtime (Reverb) — backend | ✅ Events + channels done |
| 6 | Frontend foundation | ✅ Done |
| 7 | Customer PWA | ✅ Done |
| 8 | Admin panel | ✅ Done — 14 screens |
| 9 | Kitchen display | ✅ Done |
| 10 | Cashier POS | ✅ Done |
| 11 | Testing | ✅ Done — 83 backend + 48 frontend |
| 12 | Docker / CI / deployment docs | ✅ Done |

**All twelve phases are complete.** What remains is not code — see
[§7 Remaining work](#7-remaining-work).

---

## 2. Architecture

```
viking-api/
├── backend/          Laravel 13 · PHP 8.4 · Sanctum · Reverb · MySQL · Redis
├── frontend/         Next.js 16 · React 19 · TS · Tailwind v4 · TanStack Query
├── CONTINUE.md       this file
└── .gitignore
```

**Key decision:** all business rules live in `backend/app/Services/`, never in
controllers — the same code backs REST, websocket events and the seeders. The
frontend never computes a price; it calls `POST /cart/price`, which is the same
code path checkout uses.

### Backend layout
```
app/
├── Data/             DTOs: CartLineInput, PricedCart, PricedLine, PricedOption
├── Enums/            OrderStatus (owns the state machine), OrderType, Payment*, …
├── Events/           OrderPlaced, OrderStatusChanged, OrderPaymentRecorded, OrderRefunded
├── Broadcasting/     OrderChannels — one place that decides channel fan-out
├── Exceptions/       DomainException + Cart/Coupon/Payment/Table/Transition
├── Http/
│   ├── Controllers/Api/V1/{Public,Customer,Kitchen,Cashier,Admin}/
│   ├── Middleware/   SetLocale, SecurityHeaders, EnsureUserIsActive, OptionalSanctumAuth
│   ├── Requests/     PriceCartRequest, PlaceOrderRequest
│   └── Resources/    12 API resources (admin-only fields gated on route name)
├── Models/           23 models + Concerns/HasLocalizedAttributes
├── Policies/         Order, Product, User
├── Services/
│   ├── Orders/       CartPricingService, OrderCreationService, OrderStatusService, OrderNumberGenerator
│   ├── Coupons/      CouponService
│   ├── Payments/     PaymentService
│   ├── Tables/       TableSessionService, QrCodeService
│   └── Media/        MediaService
└── Support/          Permissions (permission catalogue + role definitions)
```

### Frontend layout
```
src/
├── app/
│   ├── layout.tsx            fonts, providers, SW registration
│   ├── globals.css           design tokens (oklch, semantic layer)
│   ├── (customer)/           page · menu · menu/[slug] · cart · checkout
│   │                         orders · orders/[number] · favorites · account
│   ├── auth/login · auth/register
│   ├── t/[token]/            QR landing (no bottom nav on purpose)
│   └── offline/
├── components/
│   ├── ui/                   button, primitives (Card/Field/Input/Badge/…), sheet
│   ├── customer/             product-card, shell
│   ├── providers.tsx
│   └── service-worker.tsx
├── hooks/                    queries.ts (all TanStack hooks + key registry), use-realtime.ts
├── lib/
│   ├── api/                  client.ts (axios + credentials), endpoints.ts
│   ├── i18n/                 dictionaries.ts (typed keys), provider.tsx
│   ├── echo.ts               Reverb client
│   ├── format.ts             money/date — Latin numerals even in Arabic
│   └── utils.ts              cn, debounce, …
├── stores/                   cart.ts, auth.ts
└── types/api.ts              hand-written API response types
```

---

## 3. Database schema (31 migrations, all applied)

`settings` · `branches` · `users`(extended) · `media` · `categories` · `products` ·
`product_images` · `option_groups` · `options` · `option_group_product` ·
`dining_tables` · `table_sessions` · `coupons` (+`category_coupon`,`coupon_product`) ·
`offers` (+`offer_product`) · `orders` · `order_items` · `order_item_options` ·
`order_status_events` · `payments` · `refunds` · `coupon_redemptions` ·
`favorites` · `addresses` · `reviews` · `notifications` · spatie permission +
activity_log tables.

**Design notes worth keeping in mind when extending:**
- Variants and add-ons share one structure (`option_groups.kind` = variant|addon).
  A group with `product_id = NULL` is a reusable library group attached through
  `option_group_product`.
- Order items snapshot product name/price. The `product_id` FK is for reporting
  only and may be null after a delete.
- `orders` has discrete lifecycle timestamp columns *and* an append-only
  `order_status_events` journal — the columns let the kitchen sort/age without a
  join; the journal is the audit trail.
- Money is `decimal(12,2)` everywhere; IQD renders with 0 decimals.

---

## 4. API — `/api/v1` (117 routes)

Public: `bootstrap` · `categories` · `products` · `products/search` ·
`products/highlights` · `products/{slug}` · `offers` · `products/{p}/reviews` ·
`tables/scan/{token}` · `tables/session/{token}` · `cart/price`

Auth: `auth/register|login|me|profile|password|logout|logout-all`

Ordering (guest **or** token): `orders` POST/GET · `orders/{n}` ·
`orders/{n}/cancel` · `orders/{n}/reorder`

Account: `favorites` · `addresses` · `products/{p}/reviews` POST · `notifications`

Kitchen (`orders.kitchen`): `kitchen/board` · `.../advance` · `.../status` · `.../items/{id}`

Cashier (`orders.cashier`): `cashier/orders` · `.../pay` · `.../refund` ·
`.../discount` · `cashier/tables` · `.../close` · `cashier/receipts/{n}`

Admin (`api.v1.admin.*`): dashboard · orders · products · categories ·
option-groups · tables (+bulk, qr, qr-sheet, rotate-qr) · users · roles ·
coupons (+redemptions) · offers · media · settings · branches ·
reports/{sales|products|categories|payments|staff|hours} (+`/export` CSV) ·
activity · reviews

**Conventions:** `?filter[col]=`, `?sort=-col`, `?per_page=`, `?from=&to=`.
Errors always `{ message, error, context?, errors? }`.
Guests identified by `X-Guest-Token` header (32 lowercase alphanumerics).

---

## 5. Environment variables

### backend/.env
`APP_*` · `FRONTEND_URL` · `DB_*` (mysql) · `REDIS_*` · `CACHE_STORE` ·
`QUEUE_CONNECTION` · `BROADCAST_CONNECTION=reverb` ·
`REVERB_APP_ID|KEY|SECRET|HOST|PORT|SCHEME` · `AWS_*` (S3-compatible) ·
`MAIL_*` · `VIKING_CURRENCY|TAX_PERCENT|SERVICE_CHARGE_PERCENT|ALLOW_GUEST_ORDERS` ·
`RATE_LIMIT_API|AUTH|ORDER` · `VIKING_SEED_PASSWORD`

### frontend/.env.local
`NEXT_PUBLIC_API_URL` · `NEXT_PUBLIC_SITE_URL` · `NEXT_PUBLIC_REVERB_APP_KEY` ·
`NEXT_PUBLIC_REVERB_HOST|PORT|SCHEME` · `NEXT_PUBLIC_MEDIA_HOSTNAME`

---

## 6. Local development

MySQL is **not** installed in the dev container, so `backend/.env` currently
points at SQLite with `CACHE_STORE=file` and `BROADCAST_CONNECTION=log`.
`.env.example` is the real (MySQL + Redis + Reverb) configuration.

```bash
# backend
cd backend && php artisan migrate:fresh --seed && php artisan serve --port=8000

# frontend
cd frontend && npm run dev        # Turbopack is the default in Next 16
```

### Seeded accounts — password `Viking#2026`
| Email | Role |
|---|---|
| owner@viking.example | super-admin |
| admin@viking.example | admin |
| manager@viking.example | manager (Downtown) |
| cashier@viking.example | cashier (Downtown) |
| kitchen@viking.example | kitchen (Downtown) |
| waiter@viking.example | waiter (Downtown) |
| customer@viking.example | customer |

Seed data: 2 branches, 42 QR tables, 7 categories, 22 products, 14 option
groups, 48 options, 3 coupons, 3 offers, ~105 orders across 14 days.

---

## 7. Remaining work

No code is outstanding. Two external grants are, and both belong to the account
owner.

### 🔴 Blocker 1 — GitHub push access (highest priority)

The GitHub App installation on `clouddevag/viking-api` is **read-only**.

```
git push origin claude/…   → 403 on git-receive-pack
POST /repos/…/git/refs     → 403 "Resource not accessible by integration"
git ls-remote origin       → works (reads are fine)
```

**10 commits are built locally and cannot leave the container.** Grant
**Contents: write** at <https://claude.ai/admin-settings/claude-in-slack> or in
the GitHub App's installation settings, then:

```bash
git push -u origin claude/viking-ordering-platform-uo9v1t
```

This also blocks the Vercel deploy, which works from a GitHub repository.

### 🔴 Blocker 2 — a host for the API

Vercel is connected but can only host the frontend. The Laravel API, MySQL,
Redis and Reverb need a container host; two of those are long-running
processes. Provision one (Railway, Render, Fly.io, a VPS) plus managed MySQL 8
and Redis 7, then set `NEXT_PUBLIC_API_URL` on the Vercel project and redeploy.

Deploying the frontend on its own would produce a URL where the menu is empty
and sign-in fails — see `DEPLOYMENT.md` for the full reasoning.

### Optional, once deployed

- S3 credentials (`AWS_*`) so uploaded media survives a container restart.
- SMTP credentials (`MAIL_*`) — nothing customer-facing depends on mail yet.
- A live websocket handshake against Reverb. Events are unit-tested and the
  polling fallback is verified, but no long-running process ran in this
  container.

---

## 8. Gotchas already hit (don't rediscover these)

- **spatie/activitylog v5** moved the trait to
  `Spatie\Activitylog\Models\Concerns\LogsActivity` and `LogOptions` to
  `Spatie\Activitylog\Support\LogOptions`; `dontSubmitEmptyLogs()` is now
  `dontLogEmptyChanges()`.
- **Eloquent defaults**: DB column defaults leave the in-memory model null right
  after `create()`. Models that are serialised immediately (Order, OrderItem,
  Payment, TableSession, DiningTable, OrderItemOption) declare
  `protected $attributes`.
- **`Model::preventLazyLoading`** is on outside production — every relation used
  by a resource must be eager-loaded.
- **Next 16**: `params`/`searchParams` are promises (`use()` in client
  components); Turbopack is default and a webpack config would fail the build;
  `useSearchParams` needs a `<Suspense>` boundary or prerender fails;
  `images.domains` is deprecated → `remotePatterns`; `data-scroll-behavior` is
  needed to keep the scroll override.
- **SQLite in dev**: avoid MySQL-only SQL. `GREATEST` → `CASE WHEN`; date
  grouping is branched on the driver in Dashboard/Report controllers.
- **`php artisan install:broadcasting`** needs a TTY — configure Reverb by hand.
- **`Event::fake()` with no arguments** disables model `booted` hooks too, so
  `DiningTable` stopped minting `qr_token` and every insert hit a NOT NULL
  violation. Always fake an explicit list of broadcast classes.
- **`getAllPermissions()`** reads the direct `permissions` relation *and* each
  role's, so a resource calling it needs `roles.permissions` **and**
  `permissions` eager-loaded, or `preventLazyLoading` turns the endpoint into a
  500.
- **React 19 lint rules are strict and correct.** Copying external state into
  React state from an effect, writing a ref during render and calling
  `Date.now()` during render are all errors. Use `useSyncExternalStore` for
  browser-only values, assign refs inside effects, and put clock reads in a
  lazy `useState` initialiser.
- **Docker `COPY` cannot reach outside the build context** — the PHP services
  build from the repo root (`context: .`) so they can copy both `backend/` and
  `docker/php/`.
- **YAML plain scalars break on a colon**, so
  `${APP_KEY:?... run: php artisan ...}` failed `docker compose config` until
  the whole value was quoted.

---

## 9. Security note (action required by the owner)

The Express relay that previously lived in `viking-backend/` had a **Telegram
bot token and a mail recipient hard-coded in source**. It has been deleted, but
the values remain in this repository's git history and must be treated as
compromised: **revoke that bot token via @BotFather and rotate the mail
credentials.** Nothing in the new codebase uses them.
