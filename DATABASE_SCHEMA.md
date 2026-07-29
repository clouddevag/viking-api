# Database Schema

31 migrations, 38 tables. MySQL 8.4 in production, SQLite for the test suite —
every migration and every hand-written query is portable across both.

The naming rules are consistent throughout:

- **Bilingual columns** come in `*_en` / `*_ar` pairs. The `HasLocalizedAttributes`
  trait resolves the pair against the request locale, so application code reads
  `$product->name`, never `$product->name_en`.
- **Money** is `decimal(12, 2)`, never a float. Iraqi dinar has no minor unit in
  practice, but the scale leaves room for a future currency and prevents binary
  rounding drift.
- **Soft deletes** (`deleted_at`) apply to anything a human can "delete" but a
  historical order might still reference: products, categories, coupons, users,
  branches, tables, offers, addresses.
- **Timestamps** are UTC in the database. Branches carry their own `timezone`
  and reports convert at query time.

---

## Entity overview

```
branches ──┬── dining_tables ── table_sessions ──┐
           │                                     │
           ├── users (staff)                     │
           └── orders ───────────────────────────┘
                 │
                 ├── order_items ── order_item_options
                 ├── order_status_events
                 ├── payments ── refunds
                 └── coupon_redemptions ── coupons

categories ── products ─┬── product_images ── media
                        ├── option_groups ── options
                        ├── reviews
                        └── favorites

offers ── offer_product ── products
settings          activity_log          notifications
```

---

## Catalogue

### `categories`

Self-referencing tree (`parent_id`), though the seeded menu is one level deep —
the customer app renders a horizontal rail, and nesting hurts on a phone.

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `parent_id` | bigint null | FK → `categories.id`, `nullOnDelete` |
| `slug` | varchar unique | URL key |
| `name_en` / `name_ar` | varchar | |
| `description_en` / `description_ar` | text null | |
| `image_id` | bigint null | FK → `media.id` |
| `icon` | varchar null | lucide icon name |
| `accent_color` | varchar null | oklch string used for the category chip |
| `sort_order` | int | manual ordering from the admin drag handle |
| `is_active` / `is_featured` | bool | |

Indexes: `(is_active, sort_order)`, `parent_id`.

### `products`

| Column | Type | Notes |
| --- | --- | --- |
| `id` | bigint PK | |
| `category_id` | bigint | FK → `categories.id`, restricted |
| `sku` | varchar unique | printed on kitchen tickets |
| `slug` | varchar unique | |
| `name_en` / `name_ar` | varchar | |
| `short_description_en` / `_ar` | varchar null | card subtitle |
| `description_en` / `_ar` | text null | detail page body |
| `image_id` | bigint null | primary image, FK → `media.id` |
| `base_price` | decimal(12,2) | before options |
| `compare_at_price` | decimal(12,2) null | struck-through "was" price |
| `cost_price` | decimal(12,2) null | admin-only; drives margin reports |
| `calories` | int null | |
| `prep_time_minutes` | int default 10 | feeds the kitchen ageing thresholds |
| `spice_level` | tinyint 0–3 | |
| `allergens` | json | array of slugs |
| `tags` | json | array of slugs (`vegetarian`, `bestseller`, …) |
| `is_active` | bool | hidden from the menu entirely when false |
| `is_available` | bool | visible but "sold out" — the 86 switch |
| `is_featured` / `is_new` | bool | homepage rails |
| `rating_average` | decimal(3,2) | denormalised from approved reviews |
| `rating_count` | int | |
| `order_count` | int | denormalised popularity, used for sorting |

Indexes: `(is_active, is_available)`, `(category_id, sort_order)`, `is_featured`,
`slug`. A `FULLTEXT` index on `(name_en, name_ar, description_en, description_ar)`
is created **only on MySQL** — the migration branches on the driver, and SQLite
falls back to `LIKE` scans in `ProductController::search`.

`cost_price` never leaves the server for a non-admin caller: `ProductResource`
keys it off `$request->routeIs('api.v1.admin.*')`.

### `option_groups` and `options`

One table models both variants and add-ons, discriminated by `kind`:

| Column | Type | Notes |
| --- | --- | --- |
| `product_id` | bigint null | **null = library group**, reusable across products |
| `kind` | enum(`variant`,`addon`) | variants change the base item; add-ons stack on |
| `selection` | enum(`single`,`multiple`) | radio vs checkbox |
| `is_required` | bool | |
| `min_selections` / `max_selections` | int | enforced server-side in `CartPricingService` |
| `sort_order` | int | |

A group with `product_id = NULL` is attached to products through the
`option_group_product` pivot, which carries its own `sort_order` so the same
"Extras" group can sit in a different position on each product.

`options` rows hold `price_delta` (may be negative), `is_default`,
`is_available`, and `max_quantity` for add-ons you can take twice.

> Why one table: a "Large" size and an "Extra cheese" behave identically at
> checkout — both are a priced selection constrained by a group. Splitting them
> into two tables duplicates the min/max validation and the order snapshot.

### `media`, `product_images`

`media` is the upload ledger: `disk`, `path`, `mime_type`, `size`, `width`,
`height`, `collection`, bilingual `alt_*`, and `uploaded_by`. `product_images`
is the ordered gallery join (`product_id`, `media_id`, `sort_order`).

---

## Ordering

### `orders`

| Column | Type | Notes |
| --- | --- | --- |
| `order_number` | varchar unique | human-facing, e.g. `V-260729-0007` |
| `branch_id` | bigint | FK → `branches.id` |
| `dining_table_id` | bigint null | set for dine-in |
| `table_session_id` | bigint null | groups several orders onto one bill |
| `user_id` | bigint null | null for guests |
| `guest_token` | varchar(32) null | device identity when `user_id` is null |
| `customer_name`, `customer_phone` | varchar null | |
| `type` | enum(`dine_in`,`takeaway`,`delivery`) | |
| `status` | enum | `pending → confirmed → preparing → ready → served → completed`, plus `cancelled` |
| `payment_status` | enum(`unpaid`,`partial`,`paid`,`refunded`) | **derived** from the payments ledger, never assigned by hand |
| `subtotal` | decimal(12,2) | sum of line totals |
| `discount_total` | decimal(12,2) | offers + coupon |
| `manual_discount_total` | decimal(12,2) | cashier override |
| `manual_discount_reason` | varchar null | required when the above is non-zero |
| `tax_total`, `service_charge`, `delivery_fee` | decimal(12,2) | |
| `grand_total` | decimal(12,2) | |
| `refunded_total` | decimal(12,2) | |
| `coupon_id`, `coupon_code` | | code is snapshotted so a deleted coupon still prints |
| `estimated_minutes` | int null | max prep time across the lines |
| `placed_at` … `cancelled_at` | datetime null | one column per state, written by `OrderStatus::timestampColumn()` |
| `accepted_by`, `served_by`, `cashier_id`, `cancelled_by` | bigint null | FK → `users.id` |

Indexes: `order_number`, `(branch_id, status)`, `(branch_id, created_at)`,
`(guest_token, created_at)`, `(user_id, created_at)`, `table_session_id`.

`(branch_id, status)` is the kitchen board's index — that query runs every few
seconds on every screen in the pass, so it must never table-scan.

### `order_items` and `order_item_options`

Both tables **snapshot** their source. `order_items` stores
`product_name_en`, `product_name_ar`, `product_sku`, `image_url` and
`unit_price` alongside a nullable `product_id`; `order_item_options` stores
`group_name_*`, `option_name_*`, `group_kind` and `price_delta`.

> Why: a receipt reprinted in March must show what was actually sold in
> January, at January's price, under January's name. A join to `products`
> would silently rewrite history the moment someone edits the menu — and
> `product_id` is `nullOnDelete`, so a purged product must not erase the sale.

`order_items.status` tracks per-line kitchen progress (`pending`, `preparing`,
`ready`), which is what lets a cook mark the burger done while the fries are
still in the fryer.

### `order_status_events`

Append-only audit of every transition: `from_status`, `to_status`, `user_id`,
`actor_label` (denormalised name, so a deleted employee still reads sensibly),
`note`, `created_at`. This is the timeline the customer tracking page renders.

### `payments` and `refunds`

`payments`: `method` (`cash`, `card`, `transfer`, `wallet`), `status`,
`amount`, `tendered_amount`, `change_amount`, `reference`, `meta` json,
`processed_by`, `processed_at`.

`refunds`: `amount`, `reason` (required), `status`, `payment_id` (nullable —
a refund can be issued against the order rather than one tender),
`processed_by`.

`orders.payment_status` is recomputed from these two tables after every write.
There is deliberately no setter for it.

### `table_sessions`

Opened by the first QR scan at a table, rejoined by every subsequent scan while
`status = 'open'`. Holds `session_token` (unique), `party_size`, `opened_at`,
`last_activity_at`, `closed_at`, `closed_by`. Several orders point at one
session, which is how a table pays a single bill.

Index: `(dining_table_id, status)` — the uniqueness check on every scan.

### `dining_tables`

`number`, bilingual `name_*`, `zone`, `capacity`, `status`
(`available`, `occupied`, `reserved`, `out_of_service`), and:

| Column | Notes |
| --- | --- |
| `qr_token` | varchar(64) **unique**, minted in the model's `booted()` hook |
| `qr_rotated_at` | datetime null — set when a manager reprints |

Unique index on `(branch_id, number)`.

> `qr_token` is generated by a model event, not a DB default, so a table can
> never exist without one. Test code that fakes *all* events breaks this —
> `Event::fake()` must always be given an explicit list of broadcast classes.

---

## Promotions

### `coupons`

`code` (unique), bilingual names, `type` (`percentage`|`fixed`), `value`,
`minimum_order_amount`, `maximum_discount_amount`, `applies_to`
(`all`|`categories`|`products`), `usage_limit`, `usage_limit_per_user`,
`used_count`, `first_order_only`, `starts_at`, `expires_at`, `is_active`.

Scoped through `coupon_product` and `category_coupon` pivots.

### `coupon_redemptions`

`coupon_id`, `order_id`, `user_id`, `guest_token`, `discount_amount`.

**Unique index on `(coupon_id, order_id)`.** Together with the conditional
claim in `CouponService::redeem()` —

```sql
UPDATE coupons SET used_count = used_count + 1
 WHERE id = ? AND is_active = 1
   AND (usage_limit IS NULL OR used_count < usage_limit)
```

— this is what makes a limited coupon safe under concurrent checkout. The
`UPDATE` returning 0 affected rows *is* the "someone else took the last one"
signal; no `SELECT … FOR UPDATE`, no application-level lock.

### `offers`

`type` (`banner`, `combo`, `discount`), `discount_type`, `discount_value`,
`combo_price`, bilingual `title_*` / `badge_*`, `starts_at`, `ends_at`,
`cta_url`. Combo membership lives in `offer_product` with a `quantity`.

Offers apply **before** coupons — an offer is a property of the menu, a coupon
is a property of the transaction.

---

## Accounts and access

### `users`

Laravel's base table plus `branch_id`, `phone` (unique, nullable),
`phone_verified_at`, `avatar_path`, `locale`, `is_active`, `last_login_at`,
`last_login_ip`, `deleted_at`.

`branch_id` scopes staff: a cook at Downtown cannot advance a Riverside ticket.
Admins and super-admins have `branch_id = null` and see everything.

Customers and staff share the table — the difference is role assignment, and a
`staff()` / `customers()` scope pair keeps the admin list readable.

### spatie/laravel-permission tables

`roles`, `permissions`, `model_has_roles`, `model_has_permissions`,
`role_has_permissions`.

**7 roles, 26 permissions:**

| Role | Permissions |
| --- | --- |
| `super-admin` | all, via `Gate::before` — deliberately holds no rows |
| `admin` | all 26 |
| `manager` | 22 (no `roles.manage`, `users.manage`, `branches.manage`, `settings.manage`) |
| `cashier` | 8 — `orders.cashier`, `payments.capture`, `payments.discount`, … **not** `payments.refund` |
| `kitchen` | 3 — `orders.view`, `orders.kitchen`, `products.view` |
| `waiter` | 4 |
| `customer` | 0 — the customer API is guarded by ownership, not permissions |

Refunds are a manager decision by design: `payments.refund` is absent from
`cashier` so a till operator cannot reverse their own shortfall.

### `personal_access_tokens`

Sanctum. Tokens are revoked on password change, on logout-all, and
automatically when an account is deactivated.

---

## Supporting tables

| Table | Purpose |
| --- | --- |
| `settings` | `group` + `key` + `value` + `type` + `is_public`. Public rows ship in `GET /bootstrap`; private ones (tax rate, receipt config) stay server-side. Unique on `(group, key)`. |
| `activity_log` | spatie/laravel-activitylog. Model changes with causer, subject and a JSON diff. |
| `notifications` | Laravel's standard notifications table, uuid PK. |
| `addresses` | Customer delivery book: `label`, `city`, `area`, `street`, `building`, `latitude`, `longitude`, `is_default`. |
| `reviews` | `rating` 1–5, `comment`, `is_approved`, `approved_by`. Unmoderated reviews are invisible to the menu and do not move `rating_average`. |
| `favorites` | `(user_id, product_id)` unique. Guests keep favourites in localStorage and `POST /favorites/sync` merges them at sign-in. |
| `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs` | Framework infrastructure. Redis is used in production; these exist so a database-backed fallback works. |

---

## Seeders

`php artisan db:seed` runs, in order:

1. **RolePermissionSeeder** — 26 permissions, 7 roles.
2. **SettingsSeeder** — currency, tax, service charge, receipt header/footer
   (both locales), opening hours, feature switches.
3. **BranchSeeder** — Downtown and Riverside, plus their dining tables.
4. **MenuSeeder** — categories, products, option groups, options, images.
5. **MarketingSeeder** — coupons and offers.
6. **StaffSeeder** — one account per role.
7. **DemoOrderSeeder** — orders spread across statuses so the kitchen board,
   cashier queue and dashboard charts have something to show on first boot.

Seeded accounts (password from `VIKING_SEED_PASSWORD`, default `Viking#2026`):

| Email | Role | Branch |
| --- | --- | --- |
| `owner@viking.example` | super-admin | — |
| `admin@viking.example` | admin | — |
| `manager@viking.example` | manager | Downtown |
| `cashier@viking.example` | cashier | Downtown |
| `kitchen@viking.example` | kitchen | Downtown |
| `waiter@viking.example` | waiter | Downtown |
| `riverside@viking.example` | manager | Riverside |
| `customer@viking.example` | customer | — |

**Set `VIKING_SEED_PASSWORD` before seeding anything reachable from the
internet.** The fallback is documented here, which means it is public.

---

## Portability notes

Three places branch on the database driver. They are the only ones, and each
carries a comment in the source:

1. **Full-text index** on `products` — created on MySQL, skipped on SQLite.
2. **Date and hour extraction** in the reports service — `DATE()` / `HOUR()`
   on MySQL, `strftime()` on SQLite.
3. **`GREATEST`** — unavailable in SQLite, so the coupon release path uses
   `CASE WHEN used_count > 0 THEN used_count - 1 ELSE 0 END`.

CI runs the full suite twice: once on SQLite, once with `migrate:fresh --seed`
against a real MySQL 8.4 service container, so a MySQL-only regression cannot
reach `main`.
