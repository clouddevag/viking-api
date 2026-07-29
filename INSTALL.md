# Installation

Two paths. Docker gets the whole stack up in one command; the manual path is
for working on the code day to day.

---

## Option A — Docker (recommended)

### Requirements

Docker Engine 24+ with the Compose plugin. Nothing else — no PHP, no Node, no
MySQL on the host.

**On Windows 11**, Docker Desktop with the **WSL 2 backend** — the default since
Docker Desktop 4.x. Check **Settings → General → "Use WSL 2 based engine"** is
ticked. If Docker Desktop reports WSL 2 is missing, run `wsl --install` in an
administrator PowerShell and reboot; on Windows 11 that enables the *Virtual
Machine Platform* and *Windows Subsystem for Linux* features and installs the
kernel in one step.

Run the commands below from **PowerShell**, not Git Bash — Git Bash rewrites
absolute paths in `docker compose exec` arguments and the errors it produces are
misleading.

> **Clone with LF line endings.** The repository ships a `.gitattributes` that
> forces this, so a fresh clone is already correct. If you cloned *before* that
> file existed, re-normalise once:
>
> ```powershell
> git rm --cached -r .
> git reset --hard
> ```
>
> Otherwise Git's Windows default rewrites the container entrypoints to CRLF and
> the API container dies at boot with
> `exec /usr/local/bin/entrypoint: no such file or directory` — which reads like
> a missing file but is really the `\r` in the shebang.

### Steps

```bash
git clone https://github.com/clouddevag/viking-api.git
cd viking-api
cp .env.example .env
```

Generate an application key and paste it into `.env` as `APP_KEY`:

```bash
docker run --rm php:8.4-cli php -r \
  "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
```

Then set, at minimum:

```dotenv
APP_KEY=base64:…                     # from the command above
DB_PASSWORD=…                        # anything, it is internal to the compose network
DB_ROOT_PASSWORD=…
REVERB_APP_KEY=…                     # public — ships in the browser bundle
REVERB_APP_SECRET=…                  # private
VIKING_SEED_PASSWORD=…               # password for the demo accounts
```

Bring it up:

```bash
docker compose up -d --build
```

First boot builds both images, waits for MySQL to genuinely accept
connections, runs all 31 migrations, seeds the menu and demo data, then caches
config, routes and events. Expect 3–6 minutes for the initial build; restarts
afterwards take seconds.

Watch it:

```bash
docker compose logs -f api
```

### What you get

| URL | |
| --- | --- |
| http://localhost:3000 | Customer app |
| http://localhost:3000/admin | Admin panel |
| http://localhost:3000/kitchen | Kitchen display |
| http://localhost:3000/cashier | Cashier POS |
| http://localhost:8000/api/v1/bootstrap | API health check |
| ws://localhost:8080 | Reverb |

### Verify

```bash
curl -s http://localhost:8000/api/v1/bootstrap | jq .data.currency
curl -s http://localhost:8000/up          # Laravel health endpoint
docker compose ps                          # every service should be healthy
```

### Common operations

```bash
docker compose exec api php artisan migrate:status
docker compose exec api php artisan tinker
docker compose exec mysql mysql -uviking -p viking
docker compose logs -f worker reverb
docker compose down            # stop, keep data
docker compose down -v         # stop and destroy the database
```

`RUN_MIGRATIONS` and `RUN_SEEDERS` are read on every boot but the entrypoint
only seeds when the product catalogue is empty, so restarting will not
duplicate the menu. Set both to `false` once the install is settled.

---

## Option B — Local development

### Requirements

| | Version | |
| --- | --- | --- |
| PHP | 8.3+ | with `pdo_mysql`, `mbstring`, `bcmath`, `gd`, `intl`, `zip`, `redis` |
| Composer | 2.7+ | |
| Node | 20+ | 22 recommended |
| npm | 10+ | |
| MySQL | 8.0+ | or SQLite for a zero-setup start |
| Redis | 7+ | optional locally — file cache and sync queue work fine |

### Backend

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
```

Edit `backend/.env`. For the quickest possible start, SQLite needs no server:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/viking-api/backend/database/database.sqlite
CACHE_STORE=file
QUEUE_CONNECTION=sync
BROADCAST_CONNECTION=log
```

```bash
touch database/database.sqlite
```

For MySQL instead:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=viking
DB_USERNAME=viking
DB_PASSWORD=secret
```

```sql
CREATE DATABASE viking CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'viking'@'localhost' IDENTIFIED BY 'secret';
GRANT ALL PRIVILEGES ON viking.* TO 'viking'@'localhost';
```

Migrate, seed, link storage:

```bash
php artisan migrate --seed
php artisan storage:link
php artisan serve                     # http://localhost:8000
```

Set `VIKING_SEED_PASSWORD` in `.env` before seeding if this instance will be
reachable by anyone else.

### Frontend

```bash
cd frontend
npm install
cp .env.example .env.local
```

`frontend/.env.local`:

```dotenv
NEXT_PUBLIC_API_URL=http://localhost:8000/api/v1
NEXT_PUBLIC_SITE_URL=http://localhost:3000
NEXT_PUBLIC_REVERB_APP_KEY=local-key
NEXT_PUBLIC_REVERB_HOST=localhost
NEXT_PUBLIC_REVERB_PORT=8080
NEXT_PUBLIC_REVERB_SCHEME=http
```

```bash
npm run dev                           # http://localhost:3000
```

### Realtime and queues (optional locally)

Two more terminals, only needed when you are working on live updates:

```bash
cd backend && php artisan reverb:start      # websockets
cd backend && php artisan queue:work        # background jobs
```

Without them, set `BROADCAST_CONNECTION=log` and `QUEUE_CONNECTION=sync` in
`backend/.env`. Every realtime screen polls as a fallback, so the kitchen board
still updates — just on an interval rather than instantly.

---

## First run

Sign in at http://localhost:3000/auth/login with any seeded account. The
password is whatever you set as `VIKING_SEED_PASSWORD`, or `Viking#2026` if you
left it blank.

| Email | Sees |
| --- | --- |
| `owner@viking.example` | everything |
| `admin@viking.example` | everything |
| `manager@viking.example` | Downtown admin, reports, refunds |
| `cashier@viking.example` | till, payments, discounts — no refunds |
| `kitchen@viking.example` | kitchen display only |
| `waiter@viking.example` | floor and orders |
| `customer@viking.example` | customer account |

**Change these before exposing the instance to anyone.** They exist so every
screen can be demonstrated on first boot, and the default password is published
in this file.

### Try the QR flow

Admin → Tables → any table → **QR**. Scan it with a phone on the same network,
or open the encoded URL directly. Ordering should work end to end with no
manual table entry:

1. Scan → session opens, table identified.
2. Add items, configure options, checkout.
3. The order appears on the kitchen board within a second.
4. Advance it through preparing → ready; the customer's tracking page follows.
5. Settle it in the cashier screen and print the receipt.

---

## Running the tests

```bash
cd backend
php artisan test                      # 83 tests, 200 assertions
./vendor/bin/pint --test               # code style
```

The suite runs against an in-memory SQLite database and needs no services.

```bash
cd frontend
npx tsc --noEmit                      # types
npm run lint
npm run build                         # production build, 32 routes
```

---

## Troubleshooting

**`SQLSTATE[HY000] [2002] Connection refused`** — MySQL is not up yet. Under
Docker the API waits for a real PDO connection, so this only appears locally.

**`Please provide a valid cache path`** —
`mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache`

**`The stream or file … could not be opened`** — permissions:
`chmod -R ug+rw storage bootstrap/cache`

**Images 404 after upload** — `php artisan storage:link`.

**`Attempted to lazy load […]`** — intentional. `preventLazyLoading` is on
outside production; add the relation to the controller's `with()`.

**Frontend cannot reach the API** — check `NEXT_PUBLIC_API_URL` includes
`/api/v1`, and that `FRONTEND_URL` in the backend `.env` matches the origin
you are browsing from, or CORS will reject the request.

**Websockets never connect** — `NEXT_PUBLIC_REVERB_*` must match the backend's
`REVERB_*`. `NEXT_PUBLIC_REVERB_APP_KEY` pairs with `REVERB_APP_KEY`, and the
secret must never appear in a `NEXT_PUBLIC_` variable. Remember Next inlines
these at **build** time — changing them requires a rebuild, not a restart.

**Composer refuses to run plugins as root** —
`COMPOSER_ALLOW_SUPERUSER=1 composer install`
