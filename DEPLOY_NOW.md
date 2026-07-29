# Deploy now — Railway + Vercel

The fastest path from this repository to a working production URL, with no VPS
and no server administration. Roughly 20 minutes, most of it waiting on builds.

**Railway** runs the Laravel API, the queue worker, the Reverb websocket server,
MySQL and Redis. **Vercel** runs the Next.js frontend. Both deploy from this
GitHub repository and redeploy on every push.

> **Why not Vercel alone:** Vercel runs functions. The queue worker and Reverb
> are long-lived processes, and PHP-FPM is not a Vercel runtime. The frontend on
> its own would render an empty menu and a sign-in that fails.

---

## Before you start

Have these three values ready. Generate them now and keep them somewhere safe —
Railway will ask for them in step 4.

| Variable | Generate with | Notes |
| --- | --- | --- |
| `APP_KEY` | `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` | Losing it invalidates every session |
| `REVERB_APP_KEY` | `openssl rand -hex 16` | **Public** — ships in the browser bundle |
| `REVERB_APP_SECRET` | `openssl rand -hex 32` | **Private** — never prefix with `NEXT_PUBLIC_` |

Also decide a `VIKING_SEED_PASSWORD`. It becomes the password for all eight
demo accounts, so pick a real one — the fallback is published in this repo.

> If it contains a `#`, **wrap it in double quotes** anywhere it goes into a
> `.env` file. Dotenv treats an unquoted `#` as the start of a comment, so
> `VIKING_SEED_PASSWORD=Pa#1` seeds the password `Pa` and every documented
> login then fails. Railway's variable editor is not affected.

---

## Part 1 — Railway (the backend)

### 1. Create the project

1. Go to **<https://railway.com>** and click **Login** → **Login with GitHub**.
2. Click **New Project**.
3. Choose **Deploy from GitHub repo**.
4. If prompted, click **Configure GitHub App** and grant Railway access to
   **`clouddevag/viking-api`**.
5. Select **`clouddevag/viking-api`**.
6. When asked which branch, pick the branch you merged to (`main`), or
   `claude/viking-ordering-platform-uo9v1t` to deploy before merging.

Railway reads `railway.json` and builds `docker/railway/Dockerfile`. The first
build takes 4–8 minutes. It will fail its healthcheck until step 4 — that is
expected, there is no database yet.

Rename the service to **`api`**: click it → **Settings** → **Service Name**.

### 2. Add MySQL

1. In the project canvas, click **+ Create** (or **New**).
2. Choose **Database** → **Add MySQL**.

Leave the name as **`MySQL`** — the variable references below depend on it.

### 3. Add Redis

1. Click **+ Create** → **Database** → **Add Redis**.

Leave the name as **`Redis`**.

### 4. Configure the API service

Click the **`api`** service → **Variables** tab → **RAW Editor**, then paste
this block and fill in the four bracketed values:

```dotenv
APP_NAME=Viking
APP_ENV=production
APP_DEBUG=false
APP_KEY=[paste your generated base64: key]
LOG_CHANNEL=stderr
LOG_LEVEL=info

DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}

REDIS_HOST=${{Redis.REDISHOST}}
REDIS_PORT=${{Redis.REDISPORT}}
REDIS_PASSWORD=${{Redis.REDISPASSWORD}}

CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=redis
BROADCAST_CONNECTION=reverb

REVERB_APP_ID=viking
REVERB_APP_KEY=[your hex16]
REVERB_APP_SECRET=[your hex32]
REVERB_SCHEME=https
REVERB_PORT=443

RUN_MIGRATIONS=true
RUN_SEEDERS=true
VIKING_SEED_PASSWORD=[your chosen password]
```

`${{MySQL.…}}` is Railway's reference syntax — it resolves to the private
network address, so the database is never exposed publicly.

`APP_URL`, `FRONTEND_URL` and `REVERB_HOST` come later, once the domains exist.

### 5. Give the API a public domain

1. Still on **`api`** → **Settings** → **Networking**.
2. Click **Generate Domain**. Accept the suggested port (Railway reads `$PORT`).

You now have something like `https://api-production-a1b2.up.railway.app`.
**Copy it.**

3. Go back to **Variables** and add:

```dotenv
APP_URL=https://[your api domain]
```

The service redeploys. **This deploy runs the migrations and the seeders** —
watch the **Deploy Logs** for `→ Running migrations…` and `→ Seeding…`.

### 6. Verify the API is alive

Open in a browser:

```
https://[your api domain]/up
https://[your api domain]/api/v1/bootstrap
```

`/up` should render Laravel's health page. `/api/v1/bootstrap` should return
JSON containing `"code":"IQD"` and two branches. If it does, the database is
migrated and seeded.

### 7. Add the queue worker

1. **+ Create** → **GitHub Repo** → **`clouddevag/viking-api`** (the same repo).
2. Rename it to **`worker`** (**Settings** → **Service Name**).
3. **Settings** → **Deploy** → **Custom Start Command**:

   ```
   php artisan queue:work --tries=3 --max-time=3600 --sleep=1
   ```

4. **Settings** → **Networking**: do **not** generate a domain.
5. **Settings** → **Deploy** → **Healthcheck Path**: leave empty. A worker
   serves no HTTP, so a healthcheck would restart it forever.
6. **Variables** → **RAW Editor**: paste the same block as the `api` service,
   but change the last three lines to:

   ```dotenv
   RUN_MIGRATIONS=false
   RUN_SEEDERS=false
   ```

   The worker must never touch the schema.

### 8. Add the Reverb websocket server

1. **+ Create** → **GitHub Repo** → **`clouddevag/viking-api`** again.
2. Rename it to **`reverb`**.
3. **Settings** → **Deploy** → **Custom Start Command**:

   ```
   php artisan reverb:start --host=0.0.0.0 --port=${PORT}
   ```

4. **Settings** → **Deploy** → **Healthcheck Path**: leave empty.
5. **Settings** → **Networking** → **Generate Domain**. **Copy it** — this is
   your `REVERB_HOST`.
6. **Variables**: same block as the worker (`RUN_MIGRATIONS=false`), plus:

   ```dotenv
   REVERB_SERVER_HOST=0.0.0.0
   ```

### 9. Point the API at Reverb

Back on the **`api`** service → **Variables**, add — hostname only, no
`https://` and no trailing slash:

```dotenv
REVERB_HOST=[your reverb domain, e.g. reverb-production-x1y2.up.railway.app]
```

Add the same variable to the **`worker`** service, which is what actually
broadcasts events.

---

## Part 2 — Vercel (the frontend)

### 10. Import the project

1. Go to **<https://vercel.com/new>**.
2. Under **Import Git Repository**, find **`clouddevag/viking-api`** and click
   **Import**. If it is not listed, click **Adjust GitHub App Permissions** and
   grant access to the repository.
3. **Root Directory** — click **Edit** and select **`frontend`**. This is the
   one setting people miss; without it the build fails immediately.
4. Framework Preset should auto-detect as **Next.js**. Leave build and output
   settings alone — `frontend/vercel.json` covers them.

### 11. Set the environment variables

Expand **Environment Variables** and add these six. Use your real Railway
domains:

| Name | Value |
| --- | --- |
| `NEXT_PUBLIC_API_URL` | `https://[your api domain]/api/v1` |
| `NEXT_PUBLIC_SITE_URL` | `https://[your vercel domain]` |
| `NEXT_PUBLIC_REVERB_APP_KEY` | your `REVERB_APP_KEY` (the hex16, **not** the secret) |
| `NEXT_PUBLIC_REVERB_HOST` | `[your reverb domain]` |
| `NEXT_PUBLIC_REVERB_PORT` | `443` |
| `NEXT_PUBLIC_REVERB_SCHEME` | `https` |

For `NEXT_PUBLIC_SITE_URL` you do not know the domain yet — put
`https://viking-api.vercel.app` for now and correct it in step 13.

> These are **build-time** values. Next inlines them into the client bundle, so
> changing one later needs a **redeploy**, not a restart.

### 12. Deploy

Click **Deploy**. Two to four minutes. Copy the production domain it gives you,
e.g. `https://viking-api.vercel.app`.

### 13. Close the loop

Two things still point at placeholders.

**On Vercel** — **Settings** → **Environment Variables** → edit
`NEXT_PUBLIC_SITE_URL` to your real domain. Then **Deployments** → the newest
one → **⋯** → **Redeploy**.

**On Railway**, `api` service → **Variables**, add:

```dotenv
FRONTEND_URL=https://[your vercel domain]
FRONTEND_ORIGIN_PATTERN=^https://.*\.vercel\.app$
```

`FRONTEND_URL` is the CORS allow-list — until it matches, every request from
the browser is blocked. The pattern additionally allows Vercel preview
deployments.

Add `FRONTEND_URL` to the **`worker`** service too: it generates QR code URLs
and receipt links, which must point at the customer app.

Also turn off first-boot behaviour on `api` so a restart can never re-seed:

```dotenv
RUN_MIGRATIONS=false
RUN_SEEDERS=false
```

Leave `RUN_MIGRATIONS=true` only when you are shipping a release that adds a
migration.

---

## Part 3 — Verify

Work through these in order. Each one exercises a different layer.

| # | Do this | Expect |
| --- | --- | --- |
| 1 | Open `https://[api]/api/v1/bootstrap` | JSON with `"code":"IQD"` and two branches |
| 2 | Open the Vercel domain | The menu renders with product photography — proves CORS and `NEXT_PUBLIC_API_URL` are right |
| 3 | Add an item, open the cart | A total appears — proves `POST /cart/price` works |
| 4 | Sign in at `/auth/login` as `admin@viking.example` with your `VIKING_SEED_PASSWORD` | Lands on `/admin` with a populated dashboard |
| 5 | **Admin → Tables**, open any table's **QR** | An SVG renders; the encoded URL points at your Vercel domain |
| 6 | Scan or open that `/t/{token}` URL on a phone | A dining session opens and the table number shows in the header |
| 7 | Place a dine-in order from that session | Order confirmation with a `V-…` number |
| 8 | In another tab, sign in as `kitchen@viking.example` and open `/kitchen` | The ticket appears **within a second, without refreshing** — this is the realtime check |
| 9 | Advance it to preparing → ready | The customer's tracking page follows along |
| 10 | Sign in as `cashier@viking.example`, open `/cashier`, settle the order | Change is calculated; the receipt renders in both languages |

**If step 8 needs a manual refresh**, the websocket is not connecting but
everything else is fine — the kitchen board polls as a fallback. Check that
`NEXT_PUBLIC_REVERB_HOST` has no `https://` prefix, that
`NEXT_PUBLIC_REVERB_PORT` is `443`, and that you redeployed Vercel after
setting them.

---

## After it works

- **Change the seeded passwords.** Admin → Users. The default is published in
  this repository.
- **Rotate every table's QR** once you have printed and distributed the codes.
- **Media uploads**: Railway containers have ephemeral disk, so uploaded images
  vanish on redeploy. Point `FILESYSTEM_DISK=s3` at Cloudflare R2 or S3 and set
  `NEXT_PUBLIC_MEDIA_HOSTNAME` on Vercel to the bucket hostname. See
  `DEPLOYMENT.md`.
- **Backups**: Railway MySQL → **Settings** → **Backups**.

---

## Costs

Railway has no free tier for long-running services. Expect roughly **$10–20 per
month** for the five services at low traffic (three app containers, MySQL,
Redis) — usage-based, so it tracks actual consumption. Vercel's Hobby plan is
free and sufficient for the frontend.

If you would rather not run three separate Railway services, you can drop the
`worker` and set `QUEUE_CONNECTION=sync` on `api`. Broadcasts then happen inline
during the request, which makes checkout slightly slower but is perfectly
workable for a single restaurant. Reverb still needs its own service.
