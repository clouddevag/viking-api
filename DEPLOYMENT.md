# Deployment

The platform is four processes and two datastores:

| Process | What it is | Can it be serverless? |
| --- | --- | --- |
| Laravel API | PHP-FPM behind nginx | yes, with caveats |
| Queue worker | `queue:work`, long-running | **no** |
| Reverb | websocket server, long-running | **no** |
| Next.js frontend | SSR + static | yes |
| MySQL 8 | | managed service |
| Redis 7 | cache, queue, sessions | managed service |

Two of those must run continuously, which rules out deploying the whole stack
to a function platform. The frontend can go anywhere; the backend needs a host
that runs processes.

---

## Option 0 — Railway + Vercel (no server administration)

The simplest supported path, and the one to use unless you specifically want a
machine of your own. Railway runs the API, queue worker, Reverb, MySQL and
Redis; Vercel runs the frontend. Both deploy from this GitHub repository.

**Click-by-click instructions: [DEPLOY_NOW.md](DEPLOY_NOW.md).**

The pieces that make it work are already in the repository:

- `docker/railway/Dockerfile` — a **single-container** image built on
  FrankenPHP. The Compose setup splits php-fpm and nginx across two containers,
  which Railway cannot express: it routes one HTTP port per service. FrankenPHP
  is one process that speaks HTTP directly, and the same image serves the API,
  the worker and Reverb with only the start command changed.
- `docker/railway/entrypoint.sh` — binds `$PORT`, maps Railway's `MYSQL*` and
  `REDIS*` plugin variables onto Laravel's `DB_*` and `REDIS_*`, then migrates
  and seeds when `RUN_MIGRATIONS=true`.
- `railway.json` — build and healthcheck configuration (`/up`).
- `frontend/vercel.json` — framework, build command and region.

---

## Option 1 — Single VPS with Docker Compose

The fastest route to a working production install. One 2 vCPU / 4 GB machine
comfortably runs a busy single-branch restaurant.

### One command

```bash
git clone https://github.com/clouddevag/viking-api.git && cd viking-api
cp .env.example .env && $EDITOR .env      # fill in the values below
docker compose up -d --build
```

### Required values in `.env`

| Variable | How to produce it |
| --- | --- |
| `APP_KEY` | `docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"` |
| `DB_PASSWORD`, `DB_ROOT_PASSWORD` | `openssl rand -base64 24` |
| `REVERB_APP_KEY` | `openssl rand -hex 16` — **public**, ships in the browser bundle |
| `REVERB_APP_SECRET` | `openssl rand -hex 32` — **private** |
| `VIKING_SEED_PASSWORD` | a real password for the seeded accounts |

And the public URLs:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.viking.example
FRONTEND_URL=https://viking.example
NEXT_PUBLIC_API_URL=https://api.viking.example/api/v1
NEXT_PUBLIC_SITE_URL=https://viking.example
NEXT_PUBLIC_MEDIA_HOSTNAME=api.viking.example
REVERB_HOST=api.viking.example
REVERB_SCHEME=https
REVERB_PUBLIC_PORT=443
```

> `NEXT_PUBLIC_*` are **build-time**. Next inlines them into the client bundle,
> so changing one requires `docker compose up -d --build frontend`, not a
> restart. This is the single most common deployment mistake with this stack.

### After first boot

```bash
docker compose exec api php artisan migrate:status   # 31 applied
docker compose ps                                     # all healthy
curl -s https://api.viking.example/api/v1/bootstrap | jq .data.currency
```

Then turn off first-boot behaviour so a restart can never re-seed:

```dotenv
RUN_MIGRATIONS=false
RUN_SEEDERS=false
```

### TLS

Compose publishes plain HTTP on 8000/3000/8080. Terminate TLS in front of it —
Caddy is the least work:

```caddyfile
viking.example {
    reverse_proxy localhost:3000
}

api.viking.example {
    reverse_proxy localhost:8000

    @ws {
        path /app/*
        header Connection *Upgrade*
        header Upgrade    websocket
    }
    reverse_proxy @ws localhost:8080
}
```

That routes the websocket path to Reverb on the same hostname, which is why
`REVERB_PUBLIC_PORT=443` above — the browser connects to `wss://api.viking.example`
on 443 and Caddy forwards it.

With nginx instead, the websocket location needs
`proxy_set_header Upgrade $http_upgrade;` and
`proxy_set_header Connection "upgrade";` plus a long `proxy_read_timeout`.

### Updating

```bash
git pull
docker compose up -d --build
docker compose exec api php artisan migrate --force
```

### Backups

```bash
docker compose exec mysql mysqldump -uroot -p"$DB_ROOT_PASSWORD" \
  --single-transaction --routines viking | gzip > viking-$(date +%F).sql.gz
docker run --rm -v viking_api-storage:/data -v "$PWD":/backup alpine \
  tar czf /backup/storage-$(date +%F).tar.gz -C /data .
```

Uploaded media lives in the `api-storage` volume. Moving it to S3 (below)
removes it from the backup surface entirely.

---

## Option 2 — Split hosting

Frontend on Vercel, backend on a process host. Better CDN reach for the menu,
more moving parts.

### Frontend on Vercel

```bash
cd frontend
vercel --prod
```

Project settings:

- **Root directory:** `frontend`
- **Framework:** Next.js (detected)
- **Environment variables:** every `NEXT_PUBLIC_*` from the table above

`output: "standalone"` in `next.config.ts` is for the Docker image; Vercel
ignores it and uses its own adapter. No change needed.

> **The frontend alone is not a working deployment.** Until
> `NEXT_PUBLIC_API_URL` points at a live Laravel instance, every page renders
> its shell and then its error state — the menu is empty, sign-in fails, the
> kitchen board stays blank. Deploy the API first.

### Backend on a process host

Anything that runs containers: Railway, Render, Fly.io, DigitalOcean App
Platform, or a plain VPS. You need **three** process definitions from the same
image:

```
web:    php-fpm (behind the platform's router) — or `php artisan serve` for small installs
worker: php artisan queue:work --tries=3 --max-time=3600
reverb: php artisan reverb:start --host=0.0.0.0 --port=8080
```

Plus managed MySQL 8 and managed Redis 7. Point `DB_*` and `REDIS_*` at them.

Run migrations once as a release command:

```bash
php artisan migrate --force
```

### Media on S3

Local disk does not survive a container restart on most platforms. Switch to
object storage:

```dotenv
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…
AWS_BUCKET=…
AWS_URL=https://cdn.viking.example
AWS_ENDPOINT=…            # for R2, Spaces, MinIO
AWS_USE_PATH_STYLE_ENDPOINT=false
```

`league/flysystem-aws-s3-v3` is already installed. Set
`NEXT_PUBLIC_MEDIA_HOSTNAME` to the bucket or CDN hostname so Next's image
optimiser will accept it — an unlisted hostname produces a broken image, not an
error you will notice in a log.

---

## Production checklist

**Before the first real order goes through:**

- [ ] `APP_DEBUG=false` — a stack trace on a 500 leaks configuration
- [ ] `APP_ENV=production`
- [ ] `APP_KEY` set and backed up — losing it invalidates every session
- [ ] Seeded account passwords changed, or the accounts deleted
- [ ] `VIKING_SEED_PASSWORD` was set before seeding
- [ ] `RUN_SEEDERS=false`
- [ ] TLS on both hostnames, HSTS on
- [ ] `FRONTEND_URL` matches the real origin, or CORS blocks the app
- [ ] `SANCTUM_STATEFUL_DOMAINS` matches if you use cookie auth
- [ ] Redis is not reachable from the internet
- [ ] MySQL is not reachable from the internet
- [ ] Database backups scheduled **and a restore tested**
- [ ] `php artisan config:cache route:cache event:cache` (the entrypoint does this)
- [ ] `php artisan optimize` on the release step
- [ ] OPcache on with `validate_timestamps=0` (set in `docker/php/php.ini`)
- [ ] Queue worker supervised and restarting
- [ ] Reverb supervised and restarting
- [ ] Log rotation configured
- [ ] Uptime check on `/up`

**Worth doing in week one:**

- [ ] Error tracking (Sentry) on both applications
- [ ] `php artisan schedule:work` if you add scheduled reports
- [ ] Read replica if reporting starts competing with ordering
- [ ] Rotate every table's QR after handing the codes to staff

---

## Scaling notes

The kitchen board query (`branch_id`, `status`) and the order lookup
(`order_number`) are indexed, and they are the two that run constantly. Beyond
that:

- **Reverb** is single-process by default. Above a few hundred concurrent
  screens, run several instances behind a Redis-backed scaling driver.
- **The queue worker** handles broadcasts and notifications. Add workers before
  adding API containers — a slow broadcast queue shows up as a laggy kitchen
  board, which looks like a websocket problem and is not.
- **PHP-FPM** children default to dynamic. On a small box cap `pm.max_children`
  so a traffic spike cannot swap the machine.
- **Menu reads** dominate. They are cacheable and locale-keyed; putting a CDN
  in front of `/api/v1/products` and `/api/v1/categories` removes most load.

---

## Rollback

```bash
git checkout <previous-tag>
docker compose up -d --build
```

Migrations are additive. If a release added a column, rolling the code back is
safe on its own; `php artisan migrate:rollback` is only needed when a release
changed the meaning of existing data, and it should be paired with a restored
backup rather than run against live rows.

---

## Current deployment status

Nothing is deployed. Two credentials are missing, and both are the account
owner's to grant:

### 1. GitHub push access — **blocking the repository sync**

The session's GitHub App installation for `clouddevag/viking-api` is
**read-only**. Both write paths fail:

```
git push  → 403 (git-receive-pack)
GitHub API create branch → 403 "Resource not accessible by integration"
```

8 commits are built and committed locally on
`claude/viking-ordering-platform-uo9v1t` and cannot leave the container.

**To fix:** grant the Claude GitHub App **Contents: read & write** on this
repository — https://claude.ai/admin-settings/claude-in-slack, or the app's
installation settings on GitHub. Once granted, `git push -u origin
claude/viking-ordering-platform-uo9v1t` publishes all 8 commits.

### 2. A host for the API — **blocking any real deployment**

Vercel is connected, and the frontend can be deployed there today. It cannot
host the Laravel API, MySQL, Redis or Reverb — two of those are long-running
processes and Vercel runs functions.

**To fix:** provision a container host (Railway, Render, Fly.io, a VPS) and a
managed MySQL 8 + Redis 7, then set `NEXT_PUBLIC_API_URL` on the Vercel project
to its public URL and redeploy the frontend.

Everything else is ready: the images build, the compose file validates, CI is
configured, migrations and seeders run clean, and the test suite is green.
