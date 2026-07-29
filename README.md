<div dir="auto">

# Viking

A restaurant ordering platform: customer PWA, QR table ordering, kitchen
display, cashier POS and admin panel — one codebase, two languages, realtime
throughout.

**Laravel 13 · PHP 8.4 · Next.js 16 · React 19 · TypeScript · Tailwind v4 · MySQL 8 · Redis · Reverb**

</div>

---

## What it does

**Customers** browse a bilingual menu, configure items through variant and
add-on groups, apply coupons, and track their order status live. The app is
installable, works offline for browsing, and runs right-to-left in Arabic with
Latin tabular numerals so prices stay readable.

**Diners at a table** scan the QR code on the table. That is the entire
identification step — no table number typed, no account created. A second
person scanning the same code joins the same bill rather than opening a new
one.

**The kitchen** sees tickets appear within a second of checkout, in three
lanes, with an age timer that turns amber and then red on thresholds the
server sets. Touch targets are sized for someone wearing gloves in a hurry.
Individual lines can be marked ready independently.

**The till** handles payment, change, discounts with a mandatory reason, and
receipt printing in either language. Refunds require a manager — the cashier
role deliberately lacks the permission.

**Managers** get a dashboard, eight report types with CSV export, and full CRUD
over menu, tables, users, roles, coupons, offers, media and settings, with an
audit log behind all of it.

---

## Quick start

```bash
cp .env.example .env      # set APP_KEY, DB_PASSWORD, REVERB_APP_SECRET, VIKING_SEED_PASSWORD
docker compose up -d --build
```

http://localhost:3000 · admin at `/admin` · kitchen at `/kitchen` · cashier at `/cashier`

Sign in as `admin@viking.example` with whatever you set as
`VIKING_SEED_PASSWORD`. Full instructions, including the non-Docker path, are
in **[INSTALL.md](INSTALL.md)**.

---

## Documentation

| | |
| --- | --- |
| **[DEPLOY_NOW.md](DEPLOY_NOW.md)** | Click-by-click Railway + Vercel deployment |
| **[INSTALL.md](INSTALL.md)** | Docker and local setup, first run, troubleshooting |
| **[DEPLOYMENT.md](DEPLOYMENT.md)** | VPS and split hosting, TLS, S3, production checklist |
| **[API_DOCUMENTATION.md](API_DOCUMENTATION.md)** | All 117 endpoints, auth, realtime channels |
| **[DATABASE_SCHEMA.md](DATABASE_SCHEMA.md)** | 38 tables, relationships, seeders |
| **[PROJECT_STATUS.md](PROJECT_STATUS.md)** | What is done, what is verified, what is blocked |

---

## Architecture

```
frontend/          Next.js 16 App Router — customer PWA + three staff consoles
backend/           Laravel 13 REST API + Reverb websockets
docker/            php-fpm, nginx and next images
docker-compose.yml mysql · redis · api · nginx · worker · reverb · frontend
```

A few decisions worth knowing before reading the code:

**Pricing is server-authoritative.** The client sends product ids, quantities
and option ids — never a price. `POST /cart/price` returns the totals, and it
is the *same* service `POST /orders` calls. There is no second implementation
to drift.

**Business rules live in `app/Services/`, not controllers.** Order creation,
pricing, coupons, payments and the status machine are each a service, shared by
the REST layer, the broadcast layer and the seeders. Controllers validate,
delegate, and serialise.

**`OrderStatus` owns the state machine.** `allowedTransitions()` is the single
definition of what may follow what; the API rejects illegal moves and the UI
disables the corresponding buttons from the same source, so they cannot
disagree.

**Order lines are snapshots.** `order_items` stores the product's name, SKU,
image and price at the moment of sale. Editing the menu never rewrites a
receipt printed last month.

**`payment_status` is derived, never assigned.** It is recomputed from the
payments and refunds ledger after every write. There is no setter.

**Coupon limits are enforced by the database.** A conditional
`UPDATE … WHERE used_count < usage_limit` plus a unique index on
`(coupon_id, order_id)` — the update affecting zero rows *is* the "someone else
took the last one" signal. No application lock, correct under concurrency.

**Guest ownership returns 404, not 403.** Confirming that an order number
exists to a stranger who guessed it is itself a leak.

**Bilingual by column pair.** Every user-visible string is `*_en` / `*_ar`,
resolved against the request locale, so application code reads `$product->name`.
The frontend dictionary derives its key type from the English source, making a
missing Arabic translation a compile error rather than a blank label.

---

## Development

```bash
# Backend
cd backend
php artisan migrate --seed
php artisan serve
php artisan test                 # 83 tests, 200 assertions
./vendor/bin/pint --test

# Frontend
cd frontend
npm run dev
npx tsc --noEmit
npm run build                    # 32 routes
```

`Model::preventLazyLoading` is enabled outside production. An N+1 fails the
test suite rather than quietly becoming a slow endpoint in production.

CI runs the PHP suite on SQLite *and* a full `migrate:fresh --seed` against a
real MySQL 8.4 container, plus the frontend typecheck, lint and build, plus
both Docker image builds.

---

## Status

All twelve phases are implemented and verified against a running server: 117
routes exercised, every seeded role's login, guest ownership isolation, RBAC
boundaries, QR scan idempotency, coupon arithmetic, cash change calculation,
refund permission boundaries, and bilingual receipt rendering.

Two external grants are outstanding — GitHub push permission, and a host for
the API. Both are described at the end of
**[DEPLOYMENT.md](DEPLOYMENT.md#current-deployment-status)**.

---

## Note on origin

This is original work. The existing Viking website was used as a functional and
UX reference only — the experience it describes has been rebuilt with original
code, components, layouts and assets. No source code or protected asset was
copied.
