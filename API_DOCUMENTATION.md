# API Documentation

**Base URL:** `{APP_URL}/api/v1`
117 routes. Versioned in the path — a future `v2` mounts alongside rather than
breaking installed PWAs.

---

## Conventions

### Request headers

| Header | When | Value |
| --- | --- | --- |
| `Accept` | always | `application/json` |
| `Authorization` | staff and signed-in customers | `Bearer {sanctum-token}` |
| `X-Guest-Token` | anonymous customers | 32 lowercase alphanumerics |
| `X-Locale` | optional | `ar` (default) or `en` |
| `X-Table-Session` | dine-in | session token from a QR scan |

### Guest identity

Anonymous ordering needs an identity that survives a page reload but is not an
account. The client generates a 32-character token once, stores it, and sends
it as `X-Guest-Token` on every request.

That token is the *only* thing that grants access to a guest order. A caller
presenting a different token — or none — gets **404, not 403**: confirming an
order exists to a stranger who guessed the number is itself a leak.

`POST /auth/login` and `POST /auth/register` accept an optional `guest_token`
in the body and reassign that device's past orders to the new account.

### Responses

Single resource:

```json
{ "data": { … } }
```

Collection (Laravel pagination, `withQueryString`):

```json
{
  "data": [ … ],
  "links": { "first": "…", "last": "…", "prev": null, "next": "…" },
  "meta": { "current_page": 1, "from": 1, "last_page": 4, "per_page": 20, "to": 20, "total": 78 }
}
```

Errors are a consistent envelope, including for 401/403/404/429, which Laravel
would otherwise render as HTML:

```json
{ "message": "Human-readable sentence.", "error": "machine_readable_slug" }
```

422 adds field errors:

```json
{
  "message": "The given data was invalid.",
  "errors": { "items.0.quantity": ["The quantity must be at least 1."] }
}
```

Known `error` slugs: `unauthenticated`, `forbidden`, `not_found`,
`account_inactive`, `rate_limited`, `table_required`, `table_not_found`,
`invalid_transition`, `coupon_invalid`, `coupon_expired`,
`coupon_usage_limit_reached`, `coupon_minimum_not_met`, `protected_role`,
`already_paid`, `refund_exceeds_paid`.

### Collection parameters

Supported by every index endpoint:

| Parameter | Example | Notes |
| --- | --- | --- |
| `page` | `?page=3` | |
| `per_page` | `?per_page=50` | capped per endpoint (25–100) |
| `search` | `?search=burger` | MySQL full-text where available, `LIKE` otherwise |
| `sort` | `?sort=-created_at` | `-` prefix descends. Only allow-listed columns. |
| filters | `?status=preparing&branch_id=1` | allow-listed per endpoint |

An unrecognised sort or filter key is ignored rather than erroring — a stale
bookmark should still return a menu.

### Rate limits

| Bucket | Limit | Applies to |
| --- | --- | --- |
| `api` | 120/min | everything |
| `auth` | 10/min | login, register, password |
| `order` | 30/min | order placement and mutation |

Exceeding one returns 429 with `Retry-After`.

### Money and locale

All amounts are numbers in minor-unit-free IQD (`grand_total: 24300` means
24,300 IQD). Currency metadata comes from `GET /bootstrap`. Localised strings
are resolved server-side from `X-Locale`; the client never receives both
languages for the same field.

---

## Public

### `GET /bootstrap`

Everything the app needs before its first render: branches, public settings,
currency, order types, feature switches. Cacheable, no auth.

```json
{
  "data": {
    "currency": { "code": "IQD", "symbol": "د.ع", "decimals": 0 },
    "branches": [ { "id": 1, "name": "Downtown", "accepts_delivery": false, … } ],
    "settings": { "tax_rate": 0, "service_charge_rate": 0.1, "ordering_enabled": true },
    "locales": ["ar", "en"]
  }
}
```

### Menu

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/categories` | active categories with product counts |
| `GET` | `/products` | filters: `category_id`, `is_featured`, `is_new`, `tags[]`, `min_price`, `max_price`; sorts: `sort_order`, `base_price`, `rating_average`, `order_count`, `created_at` |
| `GET` | `/products/highlights` | featured / new / bestselling rails in one round trip |
| `GET` | `/products/search?q=` | full-text on MySQL, `LIKE` on SQLite |
| `GET` | `/products/{slug}` | full detail: gallery, option groups, options, related |
| `GET` | `/products/{product}/reviews` | approved only |
| `GET` | `/offers`, `/offers/{slug}` | active window only |

`GET /products/{slug}` returns option groups already ordered — variants before
add-ons, then by pivot `sort_order` for shared library groups.

---

## Table QR ordering

### `POST /tables/scan/{token}`

The entry point for a diner who scans the code on their table. No auth.

```jsonc
// body (optional)
{ "party_size": 2 }
```

```json
{
  "data": {
    "session_token": "…",
    "guest_token": "…",
    "table": { "id": 12, "number": "7", "zone": "Terrace", "capacity": 4 },
    "branch": { "id": 1, "name": "Downtown", … }
  }
}
```

**Scanning is idempotent.** If the table already has an open session, the same
`session_token` comes back — a second person joining the table joins the bill
rather than opening a second one. Unknown token → 404 `table_not_found`.

The response mints a `guest_token` if the caller did not present one, so a
diner who has never used the app is ordering after a single scan with no
manual entry of anything.

### `GET /tables/session/{sessionToken}`

Live state of the session: table, party size, every order on it, running total.

---

## Cart and orders

### `POST /cart/price`

The client sends identities and quantities. It never sends prices.

```jsonc
{
  "branch_id": 1,
  "coupon_code": "WELCOME10",        // optional
  "items": [
    {
      "product_id": 42,
      "quantity": 2,
      "special_instructions": "No onion",
      "options": [ { "option_id": 7 }, { "option_id": 11, "quantity": 2 } ]
    }
  ]
}
```

Returns the fully priced cart: per-line `unit_price`, `options_total`,
`line_total`, then `subtotal`, `discount_total`, `tax_total`,
`service_charge`, `delivery_fee`, `grand_total`, plus a `coupon` block
explaining acceptance or rejection.

> This is the **same code path** `POST /orders` uses. There is no second
> pricing implementation to drift out of sync, and a client that tries to
> submit its own totals simply has them ignored.

Validation performed here: product active and available, options belong to the
product's groups, required groups satisfied, `min_selections` / `max_selections`
respected, add-on `max_quantity` respected. Duplicate identical lines are
merged by fingerprint before pricing.

### `POST /orders`

Places the order. Requires `X-Guest-Token` or a bearer token.

```jsonc
{
  "branch_id": 1,
  "type": "dine_in",                 // dine_in | takeaway | delivery
  "table_session_token": "…",        // required when type = dine_in
  "customer_name": "…",
  "customer_phone": "+9647700000000",
  "notes": "…",
  "coupon_code": "WELCOME10",
  "address_id": 3,                   // required when type = delivery
  "items": [ … ]                     // same shape as /cart/price
}
```

201 with the created order. A `dine_in` order without a valid session is
rejected 422 `table_required` — the table must have been scanned.

Coupon redemption is atomic. If a limited coupon is exhausted between pricing
and placement, the order is rejected with `coupon_usage_limit_reached` rather
than silently placed at the undiscounted price.

Broadcasts `OrderPlaced`.

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/orders` | the caller's own orders — by `user_id` or `guest_token` |
| `GET` | `/orders/{orderNumber}` | 404 for anyone who is not the owner |
| `POST` | `/orders/{orderNumber}/cancel` | only while `pending` or `confirmed` |
| `GET` | `/orders/{orderNumber}/reorder` | returns cart lines rebuilt from the order's snapshots, ready to POST to `/cart/price` |

---

## Customer account

`POST /auth/register`, `POST /auth/login`, `POST /auth/logout`,
`POST /auth/logout-all`, `GET /auth/me`, `PATCH /auth/profile`,
`POST /auth/password`.

`login` accepts `login` (email **or** phone) and `password`, plus optional
`guest_token`. Returns `{ token, user }` where `user` includes `roles` and
`permissions` — the frontend renders navigation from that list rather than
hard-coding role names.

| Method | Path | |
| --- | --- | --- |
| `GET`/`POST`/`PUT`/`DELETE` | `/addresses` | delivery address book |
| `GET` | `/favorites` | |
| `POST` | `/favorites/{product}` | toggle |
| `POST` | `/favorites/sync` | merges a guest's localStorage favourites at sign-in |
| `POST` | `/products/{product}/reviews` | requires a completed order containing the product |
| `GET` | `/notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all` | |

---

## Kitchen — `permission:orders.kitchen`

### `GET /kitchen/board`

Every open ticket for the cook's branch, grouped into the three lanes the
display renders.

```json
{
  "data": {
    "incoming":  [ … ],
    "preparing": [ … ],
    "ready":     [ … ]
  },
  "meta": {
    "thresholds": { "warning_seconds": 480, "urgent_seconds": 900 },
    "counts": { "incoming": 3, "preparing": 5, "ready": 2 }
  }
}
```

Ageing thresholds come from the server so every screen in the pass agrees on
when a ticket turns amber and when it turns red.

| Method | Path | Notes |
| --- | --- | --- |
| `POST` | `/kitchen/orders/{order}/advance` | moves to the next status in the machine — the button a cook actually presses |
| `POST` | `/kitchen/orders/{order}/status` | explicit target, for corrections |
| `PATCH` | `/kitchen/orders/{order}/items/{item}` | per-line `status`, so one dish can be done before another |

A cook may only touch their own branch's orders — 403 otherwise. Illegal
transitions return 422 `invalid_transition`; the allowed set is owned by
`OrderStatus::allowedTransitions()`, which the UI also reads, so a disabled
button and a rejected request always agree.

Broadcasts `OrderStatusChanged` on the branch channel.

---

## Cashier — `permission:orders.cashier`

| Method | Path | Notes |
| --- | --- | --- |
| `GET` | `/cashier/orders` | live queue, filterable by `status`, `payment_status`, `type` |
| `GET` | `/cashier/orders/{orderNumber}` | lookup by the number printed on the ticket |
| `POST` | `/cashier/orders/{order}/pay` | `{ method, amount, tendered_amount?, reference? }` |
| `POST` | `/cashier/orders/{order}/discount` | `{ amount, reason }` — reason is mandatory |
| `POST` | `/cashier/orders/{order}/refund` | `{ amount, reason }` — **requires `payments.refund`** |
| `GET` | `/cashier/receipts/{orderNumber}` | print-ready payload in the requested locale |
| `GET` | `/cashier/tables` | floor plan with per-table running totals |
| `POST` | `/cashier/tables/{table}/close` | settles the session and frees the table |

`pay` with `method: "cash"` and a `tendered_amount` returns `change_amount`.
Overpaying past `grand_total` is rejected; the ledger and
`orders.payment_status` are recomputed after every write.

`refund` is deliberately outside the `cashier` role. A till operator cannot
reverse their own shortfall — 403 for `cashier`, 200 for `manager`.

---

## Admin — `/admin/*`

Every route additionally requires a specific permission.

| Area | Routes |
| --- | --- |
| Dashboard | `GET /admin/dashboard` — revenue, order counts, top products, hourly load |
| Reports | `GET /admin/reports/{report}`, `GET /admin/reports/{report}/export` (CSV). Reports: `sales`, `products`, `categories`, `staff`, `payments`, `coupons`, `tables`, `hours` |
| Orders | `GET /admin/orders`, `GET /admin/orders/{order}`, `POST …/status`, `POST …/cancel` |
| Products | full CRUD, `POST /admin/products/reorder`, `POST …/availability` (the 86 switch), `POST /admin/products/{id}/restore` |
| Categories | full CRUD + `POST /admin/categories/reorder` |
| Option groups | full CRUD |
| Tables | full CRUD, `POST /admin/tables/bulk` (create a whole zone), `GET /admin/tables/{table}/qr` (SVG), `POST …/rotate-qr`, `GET /admin/tables/qr-sheet` (printable sheet) |
| Coupons | full CRUD + `GET /admin/coupons/{coupon}/redemptions` |
| Offers | full CRUD |
| Users | full CRUD |
| Roles | `GET /admin/roles`, `POST /admin/roles`, `PATCH /admin/roles/{role}` |
| Media | `GET`, `POST` (upload), `PATCH`, `DELETE`, `POST /admin/media/bulk-delete` |
| Reviews | `GET /admin/reviews`, `POST …/approve`, `POST …/reject`, `DELETE` |
| Branches | full CRUD |
| Settings | `GET /admin/settings`, `PUT /admin/settings` |
| Activity | `GET /admin/activity` — the audit log, filterable by causer, subject and event |

Admin-only fields (`cost_price`, margins, internal notes) are emitted by the
shared resources only when `$request->routeIs('api.v1.admin.*')`, so the public
menu and the admin menu can use one serialiser without leaking.

Nobody may grant a role above their own: a manager creating an `admin` is 403,
and only a `super-admin` may grant `super-admin`.

`GET /admin/tables/{table}/qr` returns SVG and requires the bearer token, so it
cannot be used as an `<img src>` — fetch it through the API client and inline
the markup.

---

## Realtime

Laravel Reverb, Pusher protocol. The frontend uses `laravel-echo` + `pusher-js`.

| Channel | Type | Events |
| --- | --- | --- |
| `branch.{branchId}.orders` | private, staff | `OrderPlaced`, `OrderStatusChanged`, `OrderPaid` |
| `branch.{branchId}.kitchen` | private, `orders.kitchen` | `OrderPlaced`, `OrderStatusChanged`, `OrderItemStatusChanged` |
| `orders.{orderNumber}` | private, signed-in owner | `OrderStatusChanged` |
| `guest.{guestToken}` | public | `OrderStatusChanged` |
| `table.{sessionToken}` | public | `TableSessionUpdated` |

Authorisation runs through `routes/channels.php` and re-checks branch scope and
permissions — a token that can read the kitchen board cannot subscribe to
another branch's channel.

Guests broadcast on a **public** channel named with their guest token. The
token is unguessable (32 chars) and the payload carries only status and
timestamps, never customer details — private channels would require an
authenticated user, which a guest by definition does not have.

Client setup:

```ts
const echo = new Echo({
  broadcaster: "reverb",
  key: process.env.NEXT_PUBLIC_REVERB_APP_KEY,
  wsHost: process.env.NEXT_PUBLIC_REVERB_HOST,
  wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT),
  forceTLS: process.env.NEXT_PUBLIC_REVERB_SCHEME === "https",
  enabledTransports: ["ws", "wss"],
  authEndpoint: `${API_ORIGIN}/broadcasting/auth`,
  auth: { headers: { Authorization: `Bearer ${token}` } },
});
```

Every realtime screen also polls on a slow interval as a fallback. A dropped
websocket must degrade to a stale board, never a wrong one.

---

## Security

- **Sanctum** bearer tokens; revoked on password change, logout-all and
  deactivation. The `active` middleware rejects a disabled account on the next
  request rather than waiting for expiry.
- **Ownership before authorisation** on guest resources — 404, not 403.
- **Branch scoping** on every staff mutation.
- **Rate limiting** per bucket (above).
- **Validation** on every write, via form requests. No `$request->all()` mass
  assignment anywhere.
- **Eloquent throughout**, so no string-interpolated SQL. The three
  driver-branched raw fragments are constants, not user input.
- **`Model::preventLazyLoading`** outside production: an N+1 is a test failure,
  not a slow endpoint discovered in production.
- **Audit log** on every model change, with causer and diff.
- **Security headers** set by nginx and by Next: `X-Content-Type-Options`,
  `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`.

---

## Quick reference

```bash
API=http://localhost:8000/api/v1
GUEST=$(head -c 24 /dev/urandom | base32 | tr 'A-Z' 'a-z' | tr -cd 'a-z0-9' | head -c 32)

# Menu
curl -s "$API/bootstrap" | jq .
curl -s "$API/products?per_page=5" -H 'X-Locale: en' | jq '.data[].name'

# Scan a table (QR token from the admin panel or the tables seeder)
curl -s -X POST "$API/tables/scan/$QR_TOKEN" -d 'party_size=2' | jq .

# Price a cart, then place it
curl -s -X POST "$API/cart/price" -H 'Content-Type: application/json' \
  -d '{"branch_id":1,"items":[{"product_id":1,"quantity":2}]}' | jq .data.grand_total

curl -s -X POST "$API/orders" -H 'Content-Type: application/json' \
  -H "X-Guest-Token: $GUEST" \
  -d '{"branch_id":1,"type":"takeaway","items":[{"product_id":1,"quantity":2}]}' \
  | jq .data.order_number

# Staff
TOKEN=$(curl -s -X POST "$API/auth/login" -H 'Content-Type: application/json' \
  -d '{"login":"kitchen@viking.example","password":"Viking#2026"}' | jq -r .data.token)

curl -s "$API/kitchen/board" -H "Authorization: Bearer $TOKEN" | jq '.meta.counts'
```
